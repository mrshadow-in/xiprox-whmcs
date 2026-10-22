<?php

/**
 * xiProx Web Hosting — WHMCS provisioning module for WEB RESELLERS.
 *
 * Sells shared hosting accounts carved out of your xiProx web-reseller hosting
 * account (a hosting plan with reseller features). Each WHMCS service becomes a
 * customer account on one of your packages; you bill your clients in WHMCS,
 * xiProx bills you for your reseller plan.
 *
 * Server setup (Setup › Products/Services › Servers › Add New Server):
 *   Module:    xiProx Web Hosting
 *   Hostname:  your xiProx panel host (e.g. panel.example.com)
 *   Password:  the managed key from Reseller → API, or a Settings API key with
 *              hosting:read + hosting:write (your hosting plan must include the
 *              "API / WHMCS" privilege)
 *
 * Shares lib/ApiClient.php + lib/Helper.php with the xiProx Cloud module (via
 * require_once) — install both module folders. Public, plain PHP 8.1 / WHMCS 8.8.
 */

use XiProx\Helper;
use XiProx\ApiClient;
use XiProx\ApiException;
use Illuminate\Database\Capsule\Manager as Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/../xiproxcloud/lib/ApiClient.php';
require_once __DIR__ . '/../xiproxcloud/lib/Helper.php';

const XIPROXHOSTING_PREFIX = '/api/v1/hosting';

/** Module-log entry under this module's name. */
function xiproxhosting_log(string $action, $request, $response): void
{
    Helper::log($action, $request, $response, 'xiproxhosting');
}

/** The panel's hosting password rule (10–128 chars, no ":" or line breaks). '' = OK. */
function xiproxhosting_passwordError(string $pw): string
{
    $len = strlen($pw);
    if ($len < 10 || $len > 128 || preg_match('/[:\r\n\0]/', $pw)) {
        return 'The hosting password must be 10–128 characters with no ":" or line breaks — set a longer one on the service (or raise the generated password length in WHMCS) and retry.';
    }
    return '';
}

/** The customer on reseller account $resellerId hosting $domain, or '' (adopts an account a lost response left behind). */
function xiproxhosting_findByDomain(ApiClient $client, string $resellerId, string $domain): string
{
    $res = $client->get('/resellers/' . rawurlencode($resellerId));
    foreach (($res['customers'] ?? []) as $c) {
        if (is_array($c) && strcasecmp((string) ($c['primaryDomain'] ?? ''), $domain) === 0) {
            return (string) ($c['id'] ?? '');
        }
    }
    return '';
}

function xiproxhosting_MetaData(): array
{
    return [
        'DisplayName' => 'xiProx Web Hosting',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '443',
        'DefaultSSLPort' => '443',
        'ServiceSingleSignOnLabel' => 'Login to Hosting Panel',
        'ListAccountsUniqueIdentifierField' => 'domain',
    ];
}

function xiproxhosting_client(array $params): ApiClient
{
    return ApiClient::fromParams($params, XIPROXHOSTING_PREFIX);
}

/** "resellerAccountId|packageId" from the product's Package option. */
function xiproxhosting_package(array $params): array
{
    $parts = explode('|', (string) ($params['configoption1'] ?? ''), 2);
    return [trim($parts[0] ?? ''), trim($parts[1] ?? '')];
}

/** Package dropdown: every package of every web-reseller account the key's user owns. */
function xiproxhosting_ConfigOptions(): array
{
    $field = [
        'FriendlyName' => 'Package',
        'Type' => 'text',
        'Size' => '60',
        'Description' => 'resellerAccountId|packageId — both shown in your xiProx Reseller Centre.',
    ];
    try {
        $client = Helper::clientFromAnyServer('xiproxhosting', XIPROXHOSTING_PREFIX);
        if (!$client) {
            $field['Description'] .= ' — add a server of type "xiProx Web Hosting" first, then reopen this product.';
        } else {
            $opts = [];
            foreach ($client->get('/accounts') as $acc) {
                if (!is_array($acc) || empty($acc['plan']['isReseller']) || empty($acc['id'])) {
                    continue;
                }
                $res = $client->get('/resellers/' . rawurlencode((string) $acc['id']));
                foreach (($res['packages'] ?? []) as $p) {
                    if (is_array($p) && !empty($p['id'])) {
                        $opts[$acc['id'] . '|' . $p['id']] = ($acc['primaryDomain'] ?? $acc['id']) . ' › ' . ($p['name'] ?? $p['id'])
                            . ' (' . (int) ($p['diskMb'] ?? 0) . ' MB)';
                    }
                }
            }
            if ($opts) {
                $field = ['FriendlyName' => 'Package', 'Type' => 'dropdown', 'Options' => $opts, 'Description' => 'Fetched live from your xiProx Reseller Centre.'];
            } else {
                $field['Description'] .= ' — connected, but no web-reseller packages were found.';
            }
        }
    } catch (\Throwable $e) {
        $field['Description'] .= ' — couldn\'t reach the panel: ' . $e->getMessage();
        xiproxhosting_log('ConfigOptions', 'hosting packages', $e->getMessage());
    }
    return ['Package' => $field];
}

function xiproxhosting_TestConnection(array $params): array
{
    try {
        $client = xiproxhosting_client($params);
        $ids = [];
        foreach ($client->get('/accounts') as $acc) {
            if (is_array($acc) && !empty($acc['plan']['isReseller']) && !empty($acc['id'])) {
                $ids[] = (string) $acc['id'];
            }
        }
        if (!$ids) {
            return ['success' => false, 'error' => 'Connected, but this API key\'s account has no web-reseller hosting plan.'];
        }
        // Reseller endpoints also need the plan's "API / WHMCS" privilege and an active account.
        try {
            $client->get('/resellers/' . rawurlencode($ids[0]));
        } catch (ApiException $e) {
            return ['success' => false, 'error' => 'Connected, but the reseller API is refused: ' . $e->getMessage()];
        }
        return ['success' => true, 'error' => '', 'status' => 'Connected — ' . count($ids) . ' web-reseller account(s).'];
    } catch (\Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/** Run a customer op; a customer that no longer exists counts as done for terminate. */
function xiproxhosting_op(array $params, string $op, array $extra = []): string
{
    try {
        [$resellerId] = xiproxhosting_package($params);
        $id = Helper::getHostingId($params);
        if ($id === '') {
            return $op === 'terminate' ? 'success' : 'This service has no hosting account yet.';
        }
        $res = xiproxhosting_client($params)->post('/resellers/' . rawurlencode($resellerId) . '/customers/' . rawurlencode($id), ['op' => $op] + $extra);
        xiproxhosting_log('hosting:' . $op, $id, $res);
        if ($op === 'terminate') {
            Helper::setHostingId($params, '');
        }
        return 'success';
    } catch (ApiException $e) {
        if ($op === 'terminate' && $e->getApiCode() === 'not_found') {
            Helper::setHostingId($params, '');
            return 'success';
        }
        if (($op === 'suspend' || $op === 'unsuspend') && $e->getApiCode() === 'conflict') {
            return 'success'; // already in that state
        }
        return $e->getMessage();
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
}

/** Create the customer account (idempotent). */
function xiproxhosting_CreateAccount(array $params): string
{
    try {
        if (Helper::getHostingId($params) !== '') {
            return 'success';
        }
        [$resellerId, $packageId] = xiproxhosting_package($params);
        if ($resellerId === '' || $packageId === '') {
            return 'No package configured on this product.';
        }
        $domain = strtolower(trim((string) ($params['domain'] ?? '')));
        if ($domain === '') {
            return 'The service has no domain.';
        }
        $pwError = xiproxhosting_passwordError((string) ($params['password'] ?? ''));
        if ($pwError !== '') {
            return $pwError;
        }
        $body = [
            'domain' => $domain,
            'password' => (string) ($params['password'] ?? ''),
            'packageId' => $packageId,
            'email' => (string) ($params['clientsdetails']['email'] ?? ''),
        ];
        // WHMCS's generated username is used when the panel accepts it (3–16 lowercase letters/digits).
        $username = strtolower((string) ($params['username'] ?? ''));
        if (preg_match('/^[a-z][a-z0-9]{2,15}$/', $username)) {
            $body['username'] = $username;
        }
        $client = xiproxhosting_client($params);
        try {
            $res = $client->post('/resellers/' . rawurlencode($resellerId) . '/customers', $body);
        } catch (ApiException $e) {
            // "Already hosted here": a retry after a lost response — adopt that account if it's ours.
            $existing = $e->getApiCode() === 'conflict' ? xiproxhosting_findByDomain($client, $resellerId, $domain) : '';
            if ($existing === '') {
                throw $e;
            }
            $res = ['id' => $existing];
        }
        xiproxhosting_log('CreateAccount', ['domain' => $domain, 'packageId' => $packageId], $res);
        if (empty($res['id'])) {
            return 'The panel did not return an account id.';
        }
        Helper::setHostingId($params, (string) $res['id']);
        if (!empty($res['username']) && $res['username'] !== ($params['username'] ?? '')) {
            Capsule::table('tblhosting')->where('id', (int) $params['serviceid'])->update(['username' => (string) $res['username']]);
        }
        return 'success';
    } catch (\Throwable $e) {
        xiproxhosting_log('CreateAccount:error', $params['serviceid'] ?? '', $e->getMessage());
        return $e->getMessage();
    }
}

function xiproxhosting_SuspendAccount(array $params): string
{
    return xiproxhosting_op($params, 'suspend');
}

function xiproxhosting_UnsuspendAccount(array $params): string
{
    return xiproxhosting_op($params, 'unsuspend');
}

function xiproxhosting_TerminateAccount(array $params): string
{
    return xiproxhosting_op($params, 'terminate');
}

function xiproxhosting_ChangePassword(array $params): string
{
    $pwError = xiproxhosting_passwordError((string) ($params['password'] ?? ''));
    if ($pwError !== '') {
        return $pwError;
    }
    return xiproxhosting_op($params, 'password', ['password' => (string) ($params['password'] ?? '')]);
}

/** Upgrade/downgrade: move the account to the product's (new) package. */
function xiproxhosting_ChangePackage(array $params): string
{
    [, $packageId] = xiproxhosting_package($params);
    return xiproxhosting_op($params, 'package', ['packageId' => $packageId]);
}

/** Client-area / admin "Login to Hosting Panel": one-time sign-in link. */
function xiproxhosting_ServiceSingleSignOn(array $params): array
{
    try {
        [$resellerId] = xiproxhosting_package($params);
        $id = Helper::getHostingId($params);
        if ($id === '') {
            return ['success' => false, 'errorMsg' => 'This service has no hosting account yet.'];
        }
        $res = xiproxhosting_client($params)->post('/resellers/' . rawurlencode($resellerId) . '/customers/' . rawurlencode($id), ['op' => 'login']);
        $url = (string) ($res['url'] ?? '');
        if ($url === '') {
            return ['success' => false, 'errorMsg' => 'Could not create a hosting panel login link.'];
        }
        return ['success' => true, 'redirectTo' => $url];
    } catch (\Throwable $e) {
        return ['success' => false, 'errorMsg' => $e->getMessage()];
    }
}

/** Cron: disk + bandwidth usage (and limits) per service, matched by the stored hosting id. */
function xiproxhosting_UsageUpdate(array $params): string
{
    try {
        $client = xiproxhosting_client($params);
        $serverId = (int) ($params['serverid'] ?? 0);
        // hosting account id → WHMCS service id
        $serviceOf = Capsule::table('tblcustomfieldsvalues as v')
            ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
            ->where('f.type', 'product')->where('f.fieldname', Helper::FIELD_HOSTING_ID)->where('v.value', '!=', '')
            ->pluck('v.relid', 'v.value')->all();
        foreach ($client->get('/accounts') as $acc) {
            if (!is_array($acc) || empty($acc['plan']['isReseller'])) {
                continue;
            }
            $res = $client->get('/resellers/' . rawurlencode((string) $acc['id']));
            $limits = [];
            foreach (($res['packages'] ?? []) as $p) {
                $limits[$p['id'] ?? ''] = $p;
            }
            foreach (($res['customers'] ?? []) as $c) {
                $serviceId = (int) ($serviceOf[(string) ($c['id'] ?? '')] ?? 0);
                if (!$serviceId) {
                    continue; // not sold through this WHMCS
                }
                $p = $limits[$c['resellerPackageId'] ?? ''] ?? [];
                Capsule::table('tblhosting')
                    ->where('id', $serviceId)->where('server', $serverId)
                    ->update([
                        'diskusage' => (int) ($c['usage']['diskMb'] ?? 0),
                        'disklimit' => (int) ($p['diskMb'] ?? 0),
                        'bwusage' => (int) ($c['bandwidthMb'] ?? 0),
                        'bwlimit' => (int) ($p['bandwidthGb'] ?? 0) * 1024,
                        'lastupdate' => date('Y-m-d H:i:s'),
                    ]);
            }
        }
        return 'success';
    } catch (\Throwable $e) {
        xiproxhosting_log('UsageUpdate', $params['serverid'] ?? '', $e->getMessage());
        return $e->getMessage();
    }
}

/** Client-area overview: status, usage and a one-click login (WHMCS single sign-on). */
function xiproxhosting_ClientArea(array $params): array
{
    $vars = [
        'error' => '', 'hasAccount' => false, 'domain' => '', 'username' => '', 'status' => '', 'statusClass' => 'default',
        'suspendReason' => '', 'pending' => false, 'packageName' => '', 'diskMb' => 0, 'diskLimitMb' => 0,
        'bandwidthMb' => 0, 'bandwidthLimitGb' => 0, 'serviceId' => (int) ($params['serviceid'] ?? 0),
    ];
    try {
        [$resellerId] = xiproxhosting_package($params);
        $id = Helper::getHostingId($params);
        if ($id === '') {
            $vars['error'] = 'This service is not provisioned yet.';
        } else {
            $c = xiproxhosting_client($params)->get('/resellers/' . rawurlencode($resellerId) . '/customers/' . rawurlencode($id));
            $status = (string) ($c['status'] ?? '');
            $vars = array_merge($vars, [
                'hasAccount' => true,
                'domain' => (string) ($c['primaryDomain'] ?? ''),
                'username' => (string) ($c['username'] ?? ''),
                'status' => $status,
                'statusClass' => ['active' => 'success', 'suspended' => 'danger', 'pending' => 'info'][$status] ?? 'default',
                'suspendReason' => (string) ($c['suspendReason'] ?? ''),
                'pending' => !empty($c['pending']),
                'packageName' => (string) ($c['resellerPackage']['name'] ?? ''),
                'diskMb' => (int) ($c['usage']['diskMb'] ?? 0),
                'diskLimitMb' => (int) ($c['resellerPackage']['diskMb'] ?? 0),
                'bandwidthMb' => (int) ($c['bandwidthMb'] ?? 0),
                'bandwidthLimitGb' => (int) ($c['resellerPackage']['bandwidthGb'] ?? 0),
            ]);
        }
    } catch (\Throwable $e) {
        $vars['error'] = $e->getMessage();
        xiproxhosting_log('ClientArea', $params['serviceid'] ?? '', $e->getMessage());
    }
    return ['tabOverviewReplacementTemplate' => 'templates/overview', 'templateVariables' => $vars];
}
