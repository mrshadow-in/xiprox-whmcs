<?php

/**
 * xiProx Cloud — WHMCS provisioning module for reseller VMs.
 *
 * Talks ONLY to the reseller panel's dedicated API (/api/v1/reseller/*) using
 * the reseller's "WHMCS" API key. No reseller account ⇒ no WHMCS support.
 *
 * Server config mapping (Setup › Products/Services › Servers):
 *   Hostname     = your xiProx panel URL (e.g. panel.example.com)
 *   Username     = your reseller account email (label only)
 *   Password     = your reseller "WHMCS" API key (the bearer token)
 *   Access Hash  = your WHMCS webhook salt (verifies inbound webhooks)
 *
 * Public, plain-PHP module (no ionCube). Target: WHMCS 8.8 on PHP 8.1.
 */

use XiProx\Helper;
use XiProx\ApiClient;
use XiProx\ApiException;
use Illuminate\Database\Capsule\Manager as Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/ApiClient.php';
require_once __DIR__ . '/lib/Helper.php';

/** Module metadata. */
function xiproxcloud_MetaData(): array
{
    return [
        'DisplayName' => 'xiProx Cloud',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '443',
        'DefaultSSLPort' => '443',
        'ServiceSingleSignOnLabel' => 'Login to Panel',
        'ListAccountsUniqueIdentifierField' => 'domain',
    ];
}

/**
 * Product config options. The Plan dropdown is populated LIVE from the panel
 * (GET /plans) using any configured xiProx server; if none is resolvable at
 * product-edit time it falls back to a free-text plan id.
 */
function xiproxcloud_ConfigOptions(): array
{
    $planField = [
        'FriendlyName' => 'Plan',
        'Type' => 'text',
        'Size' => '40',
        'Description' => 'Reseller plan id — paste from GET /api/v1/reseller/plans.',
    ];
    $hint = '';

    try {
        $client = Helper::clientFromAnyServer('xiproxcloud');
        if (!$client) {
            $hint = 'No xiProx server found — add one under Setup › Products/Services › Servers (Type: xiProx Cloud), then reopen this product.';
        } else {
            $plans = $client->get('/plans');
            $opts = [];
            foreach ($plans as $p) {
                if (!is_array($p) || empty($p['id'])) {
                    continue;
                }
                $label = ($p['name'] ?? $p['sku'] ?? $p['id'])
                    . ' — ' . (int) ($p['vcpu'] ?? 0) . ' vCPU / '
                    . round(((int) ($p['ramMb'] ?? 0)) / 1024, 1) . ' GB / '
                    . (int) ($p['diskGb'] ?? 0) . ' GB'
                    . ' — ' . Helper::rupees((int) ($p['priceMonthly'] ?? 0)) . '/mo';
                $opts[$p['id']] = $label;
            }
            if ($opts) {
                $planField = [
                    'FriendlyName' => 'Plan',
                    'Type' => 'dropdown',
                    'Options' => $opts,
                    'Description' => 'Reseller plan — fetched live from your xiProx panel.',
                ];
            } else {
                $hint = 'Connected, but the panel returned no active plans.';
            }
        }
    } catch (\Throwable $e) {
        // Surface WHY, so the admin isn't left guessing (also logged).
        $hint = 'Couldn\'t reach the panel: ' . $e->getMessage();
        Helper::log('ConfigOptions', 'GET /plans', $e->getMessage());
    }

    if ($hint !== '' && ($planField['Type'] ?? '') === 'text') {
        $planField['Description'] .= ' — ' . $hint;
    }

    return [
        'Plan' => $planField,
        'Default OS' => [
            'FriendlyName' => 'Default OS',
            'Type' => 'text',
            'Size' => '25',
            'Default' => 'ubuntu-2404',
            'Description' => 'OS template id used when the order has no "OS Template" field (e.g. ubuntu-2404, debian-12, windows-2022).',
        ],
        'Default IP Pool' => [
            'FriendlyName' => 'Default IP Pool',
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'Optional IP pool id used when the order has no "IP Pool" field. Blank = the panel picks the cheapest pool.',
        ],
    ];
}

/** Server connection test — calls GET /wallet and reports the reseller status. */
function xiproxcloud_TestConnection(array $params): array
{
    try {
        $wallet = Helper::client($params)->get('/wallet');
        $status = $wallet['status'] ?? 'unknown';
        Helper::log('TestConnection', '[hostname] ' . ($params['serverhostname'] ?? ''), $wallet);
        return [
            'success' => true,
            'error' => '',
            'status' => 'Connected. Reseller status: ' . $status
                . ', wallet ' . Helper::rupees((int) ($wallet['walletBalancePaisa'] ?? 0)) . '.',
        ];
    } catch (\Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Provision a VM. Idempotent: if this service already has a panel VM id we do
 * nothing (survives WHMCS retries). Deploys from the reseller wallet and, when
 * the reseller has a white-label panel, creates + assigns the WHMCS customer so
 * they can log in via SSO.
 */
function xiproxcloud_CreateAccount(array $params): string
{
    try {
        if (Helper::getRemoteId($params) !== '') {
            return 'success'; // already provisioned
        }
        $client = Helper::client($params);

        $planId = (string) Helper::setting($params, 'Plan');
        if ($planId === '') {
            return 'No reseller plan configured on this product.';
        }
        $osTemplate = Helper::orderValue($params, 'OS Template', (string) Helper::setting($params, 'Default OS', 'ubuntu-2404'));
        $ipPoolId = Helper::orderValue($params, 'IP Pool', (string) Helper::setting($params, 'Default IP Pool', ''));
        $sshKey = Helper::orderValue($params, 'SSH Key', '');

        $client_ = $params['clientsdetails'] ?? [];
        $customerName = trim(((string) ($client_['firstname'] ?? '')) . ' ' . ((string) ($client_['lastname'] ?? '')));

        $body = [
            'resellerPlanId' => $planId,
            'osTemplate' => $osTemplate,
            'hostname' => Helper::hostname($params),
            'rootPassword' => (string) ($params['password'] ?? ''),
        ];
        if ($ipPoolId !== '') {
            $body['ipPoolId'] = $ipPoolId;
        }
        if ($sshKey !== '') {
            $body['sshKey'] = $sshKey;
        }
        if (!empty($client_['email'])) {
            $body['customer'] = [
                'email' => (string) $client_['email'],
                'name' => $customerName !== '' ? $customerName : (string) $client_['email'],
            ];
        }

        $res = $client->post('/vms', $body);
        Helper::log('CreateAccount', $body, $res);

        $vmId = (string) ($res['vmId'] ?? '');
        if ($vmId === '') {
            return 'Deploy did not return a VM id.';
        }
        Helper::setRemoteId($params, $vmId);
        Helper::setIpPool($params, $ipPoolId); // remember the deploy pool (drives rotate-on-upgrade)
        if (!empty($res['customer']['id'])) {
            Helper::setCustomerId($params, (string) $res['customer']['id']);
        }

        // Let the panel keep the VM alive on the reseller wallet; WHMCS drives
        // the end-customer billing separately.
        try {
            $client->patch('/vms/' . rawurlencode($vmId), ['autoRenew' => true]);
        } catch (\Throwable $e) {
            // Non-fatal.
        }

        return 'success';
    } catch (\Throwable $e) {
        Helper::log('CreateAccount:error', $params['serviceid'] ?? '', $e->getMessage());
        return $e->getMessage();
    }
}

function xiproxcloud_SuspendAccount(array $params): string
{
    return xiproxcloud_vmAction($params, 'stop', 'SuspendAccount');
}

function xiproxcloud_UnsuspendAccount(array $params): string
{
    return xiproxcloud_vmAction($params, 'start', 'UnsuspendAccount');
}

function xiproxcloud_TerminateAccount(array $params): string
{
    try {
        $vmId = Helper::getRemoteId($params);
        if ($vmId === '') {
            return 'success'; // nothing to terminate
        }
        $res = Helper::client($params)->delete('/vms/' . rawurlencode($vmId));
        Helper::log('TerminateAccount', $vmId, $res);
        Helper::setRemoteId($params, '');
        return 'success';
    } catch (ApiException $e) {
        if ($e->getApiCode() === 'not_found') {
            Helper::setRemoteId($params, '');
            return 'success';
        }
        return $e->getMessage();
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
}

/**
 * Upgrade/Downgrade path. Handles BOTH:
 *   - a plan change  → POST /vms/{id}/plan
 *   - an IP-pool change → POST /vms/{id}/ips/rotate  (this is how IP rotation is
 *     done — through WHMCS's upgrade/config flow, NOT an instant client button,
 *     so it goes through ordering/billing). Rotation fires only when the
 *     selected "IP Pool" differs from the one currently on record.
 */
function xiproxcloud_ChangePackage(array $params): string
{
    try {
        $vmId = Helper::getRemoteId($params);
        if ($vmId === '') {
            return 'This service has no provisioned VM yet.';
        }
        $client = Helper::client($params);

        // 1) Plan change. A config-only upgrade re-sends the current plan, which
        //    the panel rejects as "already on that plan" — that's fine, ignore it.
        $planId = (string) Helper::setting($params, 'Plan');
        if ($planId !== '') {
            try {
                $client->post('/vms/' . rawurlencode($vmId) . '/plan', ['newResellerPlanId' => $planId]);
            } catch (ApiException $e) {
                if (!in_array($e->getApiCode(), ['validation_error', 'conflict'], true)) {
                    throw $e;
                }
            }
        }

        // 2) IP-pool change → rotate to the newly selected pool (reseller wallet).
        $newPool = Helper::orderValue($params, 'IP Pool', '');
        if ($newPool !== '' && $newPool !== Helper::getIpPool($params)) {
            $client->post('/vms/' . rawurlencode($vmId) . '/ips/rotate', ['ipPoolId' => $newPool]);
            Helper::setIpPool($params, $newPool);
            Helper::log('ChangePackage', 'rotate-ip', ['vmId' => $vmId, 'pool' => $newPool]);
        }

        return 'success';
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
}

// ── Client area ──────────────────────────────────────────────────────────

function xiproxcloud_ClientArea(array $params): array
{
    $vars = [
        'vmId' => '', 'vm' => null, 'panelUrl' => '', 'error' => '',
        // Credentials the customer manages with. Root password tracks the WHMCS
        // service password (deploy + reinstall set it), so it's always in sync.
        'username' => 'root',
        'password' => (string) ($params['password'] ?? ''),
    ];
    try {
        $vmId = Helper::getRemoteId($params);
        $vars['vmId'] = $vmId;
        if ($vmId !== '') {
            $client = Helper::client($params);
            $vars['vm'] = $client->get('/vms/' . rawurlencode($vmId));

            // Mint a fresh one-time SSO link for the "Open Panel" button.
            $customerId = Helper::getCustomerId($params);
            if ($customerId !== '') {
                try {
                    $link = $client->post('/customers/' . rawurlencode($customerId) . '/login-link', []);
                    $vars['panelUrl'] = (string) ($link['url'] ?? '');
                } catch (\Throwable $e) {
                    // No panel link (domain not active yet) — button just hides.
                }
            }
        }
    } catch (\Throwable $e) {
        $vars['error'] = $e->getMessage();
    }

    return [
        'templatefile' => 'overview',
        'vars' => $vars,
    ];
}

/** Client-area action buttons. (IP rotation is done via Upgrade/Config, not here.) */
function xiproxcloud_ClientAreaCustomButtonArray(): array
{
    return [
        'Start' => 'Start',
        'Stop' => 'Stop',
        'Restart' => 'Restart',
        'Reinstall' => 'Reinstall',
    ];
}

/** Admin-area action buttons (Products/Services → the service → Module Commands). */
function xiproxcloud_AdminCustomButtonArray(): array
{
    return [
        'Sync to White-label Panel' => 'SyncUser',
    ];
}

/**
 * Sync User — link this WHMCS client to the reseller's white-label panel and
 * assign this VM to them. For VMs deployed BEFORE the reseller added a
 * white-label panel (so no customer was created at deploy time). Idempotent.
 */
function xiproxcloud_SyncUser(array $params): string
{
    try {
        $vmId = Helper::getRemoteId($params);
        if ($vmId === '') {
            return 'This service has no provisioned VM yet.';
        }
        $client = Helper::client($params);

        // Already assigned on the panel? Just record it.
        $vm = $client->get('/vms/' . rawurlencode($vmId));
        $existing = (string) ($vm['assignedCustomerId'] ?? '');
        if ($existing !== '') {
            Helper::setCustomerId($params, $existing);
            return 'success';
        }

        $customerId = Helper::ensureWhitelabelCustomer($params, $client);
        if ($customerId === '') {
            return 'Could not create the customer — is your white-label panel active and does the client have an email?';
        }
        $client->post('/vms/' . rawurlencode($vmId) . '/assign', ['customerId' => $customerId]);
        Helper::setCustomerId($params, $customerId);
        Helper::log('SyncUser', $vmId, ['customerId' => $customerId]);
        return 'success';
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
}

function xiproxcloud_Start(array $params): string
{
    return xiproxcloud_vmAction($params, 'start', 'Start');
}

function xiproxcloud_Stop(array $params): string
{
    return xiproxcloud_vmAction($params, 'stop', 'Stop');
}

function xiproxcloud_Restart(array $params): string
{
    return xiproxcloud_vmAction($params, 'restart', 'Restart');
}

/** Reinstall keeps the current OS and resets root to the WHMCS service password. */
function xiproxcloud_Reinstall(array $params): string
{
    try {
        $vmId = Helper::getRemoteId($params);
        if ($vmId === '') {
            return 'This service has no provisioned VM yet.';
        }
        $body = ['action' => 'reinstall', 'rootPassword' => (string) ($params['password'] ?? '')];
        $os = Helper::orderValue($params, 'OS Template', '');
        if ($os !== '') {
            $body['osTemplate'] = $os;
        }
        $res = Helper::client($params)->post('/vms/' . rawurlencode($vmId) . '/actions', $body);
        Helper::log('Reinstall', $vmId, $res);
        return 'success';
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
}

/**
 * Single sign-on entry point (WHMCS "Login to Panel"). Mints a one-time SSO
 * link for the assigned customer and hands it back to WHMCS to redirect to.
 */
function xiproxcloud_ServiceSingleSignOn(array $params): array
{
    try {
        $customerId = Helper::getCustomerId($params);
        if ($customerId === '') {
            return ['success' => false, 'errorMsg' => 'No white-label customer is linked to this service.'];
        }
        $link = Helper::client($params)->post('/customers/' . rawurlencode($customerId) . '/login-link', []);
        $url = (string) ($link['url'] ?? '');
        if ($url === '') {
            return ['success' => false, 'errorMsg' => 'Could not create a panel login link.'];
        }
        return ['success' => true, 'redirectTo' => $url];
    } catch (\Throwable $e) {
        return ['success' => false, 'errorMsg' => $e->getMessage()];
    }
}

// ── Shared ─────────────────────────────────────────────────────────────────

/** Run a power action against the linked VM. Returns 'success' or an error. */
function xiproxcloud_vmAction(array $params, string $action, string $logLabel): string
{
    try {
        $vmId = Helper::getRemoteId($params);
        if ($vmId === '') {
            return 'This service has no provisioned VM yet.';
        }
        $res = Helper::client($params)->post('/vms/' . rawurlencode($vmId) . '/actions', ['action' => $action]);
        Helper::log($logLabel, ['vmId' => $vmId, 'action' => $action], $res);
        return 'success';
    } catch (ApiException $e) {
        // A benign state conflict (e.g. already stopped) shouldn't fail the op.
        if ($e->getApiCode() === 'conflict') {
            return 'success';
        }
        return $e->getMessage();
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
}
