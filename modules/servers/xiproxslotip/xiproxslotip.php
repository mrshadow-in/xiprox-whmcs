<?php

/**
 * xiProx Slot IP — WHMCS provisioning module for reseller Slot IPs (Squid proxy
 * containers). Same panel + reseller key as the VM module; each Slot IP is
 * CHARGED TO THE RESELLER WALLET (not the customer's).
 *
 * Server config mapping is identical to xiProx Cloud (see that module / the
 * INSTALLATION guide). Add a SEPARATE WHMCS server of type "xiProx Slot IP"
 * with the same hostname / username / password / access hash.
 *
 * Shares lib/ApiClient.php + lib/Helper.php with the xiProx Cloud module (via
 * require_once) — install both module folders. Public, plain PHP 8.1 / WHMCS 8.8.
 */

use XiProx\Helper;
use XiProx\ApiException;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/../xiproxcloud/lib/ApiClient.php';
require_once __DIR__ . '/../xiproxcloud/lib/Helper.php';

/** Declared ConfigOptions order (for Helper::setting index resolution). */
function xiproxslotip_settingOrder(): array
{
    return ['Plan', 'Default IP Pool', 'Default Username'];
}

function xiproxslotip_MetaData(): array
{
    return [
        'DisplayName' => 'xiProx Slot IP',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '443',
        'DefaultSSLPort' => '443',
        'ServiceSingleSignOnLabel' => 'Login to Panel',
        'ListAccountsUniqueIdentifierField' => 'domain',
    ];
}

/** Plan dropdown from GET /slot-plans (falls back to a text field). */
function xiproxslotip_ConfigOptions(): array
{
    $planField = [
        'FriendlyName' => 'Plan',
        'Type' => 'text',
        'Size' => '40',
        'Description' => 'Slot IP plan id — paste from GET /api/v1/reseller/slot-plans.',
    ];
    $hint = '';

    try {
        $client = Helper::clientFromAnyServer('xiproxslotip');
        if (!$client) {
            $hint = 'No xiProx server found — add one under Setup › Products/Services › Servers (Type: xiProx Slot IP), then reopen this product.';
        } else {
            $plans = $client->get('/slot-plans');
            $opts = [];
            foreach ($plans as $p) {
                if (!is_array($p) || empty($p['id'])) {
                    continue;
                }
                $label = ($p['name'] ?? $p['sku'] ?? $p['id'])
                    . ' — ' . Helper::rupees((int) ($p['priceMonthly'] ?? 0)) . '/mo';
                $opts[$p['id']] = $label;
            }
            if ($opts) {
                $planField = [
                    'FriendlyName' => 'Plan',
                    'Type' => 'dropdown',
                    'Options' => $opts,
                    'Description' => 'Slot IP plan — fetched live from your xiProx panel.',
                ];
            } else {
                $hint = 'Connected, but the panel returned no active slot plans.';
            }
        }
    } catch (\Throwable $e) {
        $hint = 'Couldn\'t reach the panel: ' . $e->getMessage();
        Helper::log('ConfigOptions', 'GET /slot-plans', $e->getMessage());
    }

    if ($hint !== '' && ($planField['Type'] ?? '') === 'text') {
        $planField['Description'] .= ' — ' . $hint;
    }

    return [
        'Plan' => $planField,
        'Default IP Pool' => [
            'FriendlyName' => 'Default IP Pool',
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'Optional IP pool id used when the order has no "IP Pool" field. Blank = the panel picks the cheapest pool.',
        ],
        'Default Username' => [
            'FriendlyName' => 'Default Username',
            'Type' => 'text',
            'Size' => '25',
            'Description' => 'Proxy username used when the order has no "Username" field. Blank = derived from the service.',
        ],
    ];
}

function xiproxslotip_TestConnection(array $params): array
{
    try {
        $wallet = Helper::client($params)->get('/wallet');
        return [
            'success' => true,
            'error' => '',
            'status' => 'Connected. Reseller status: ' . ($wallet['status'] ?? 'unknown')
                . ', wallet ' . Helper::rupees((int) ($wallet['walletBalancePaisa'] ?? 0)) . '.',
        ];
    } catch (\Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/** Provision a Slot IP (idempotent). Charged to the RESELLER wallet by the panel. */
function xiproxslotip_CreateAccount(array $params): string
{
    try {
        if (Helper::getSlotId($params) !== '') {
            return 'success';
        }
        $client = Helper::client($params);
        $order = xiproxslotip_settingOrder();

        $planId = (string) Helper::setting($params, 'Plan', '', $order);
        if ($planId === '') {
            return 'No slot plan configured on this product.';
        }
        $ipPoolId = Helper::orderValue($params, 'IP Pool', (string) Helper::setting($params, 'Default IP Pool', '', $order));
        $username = Helper::orderValue($params, 'Username', (string) Helper::setting($params, 'Default Username', '', $order));
        if ($username === '') {
            $username = 'u' . (int) ($params['serviceid'] ?? 0);
        }
        $password = Helper::orderValue($params, 'Password', (string) ($params['password'] ?? ''));

        $client_ = $params['clientsdetails'] ?? [];
        $customerName = trim(((string) ($client_['firstname'] ?? '')) . ' ' . ((string) ($client_['lastname'] ?? '')));

        $body = [
            'slotPlanId' => $planId,
            'hostname' => Helper::hostname($params),
            'proxyUsername' => $username,
            'proxyPassword' => $password,
        ];
        if ($ipPoolId !== '') {
            $body['ipPoolId'] = $ipPoolId;
        }
        if (!empty($client_['email'])) {
            $body['customer'] = [
                'email' => (string) $client_['email'],
                'name' => $customerName !== '' ? $customerName : (string) $client_['email'],
            ];
        }

        $res = $client->post('/slots', $body);
        Helper::log('CreateAccount', $body, $res);

        $slotId = (string) ($res['slotId'] ?? '');
        if ($slotId === '') {
            return 'Deploy did not return a slot id.';
        }
        Helper::setSlotId($params, $slotId);
        if (!empty($res['customer']['id'])) {
            Helper::setCustomerId($params, (string) $res['customer']['id']);
        }
        return 'success';
    } catch (\Throwable $e) {
        Helper::log('CreateAccount:error', $params['serviceid'] ?? '', $e->getMessage());
        return $e->getMessage();
    }
}

function xiproxslotip_SuspendAccount(array $params): string
{
    return xiproxslotip_action($params, 'stop', 'SuspendAccount');
}

function xiproxslotip_UnsuspendAccount(array $params): string
{
    return xiproxslotip_action($params, 'start', 'UnsuspendAccount');
}

function xiproxslotip_TerminateAccount(array $params): string
{
    try {
        $slotId = Helper::getSlotId($params);
        if ($slotId === '') {
            return 'success';
        }
        $res = Helper::client($params)->delete('/slots/' . rawurlencode($slotId));
        Helper::log('TerminateAccount', $slotId, $res);
        Helper::setSlotId($params, '');
        return 'success';
    } catch (ApiException $e) {
        if ($e->getApiCode() === 'not_found') {
            Helper::setSlotId($params, '');
            return 'success';
        }
        return $e->getMessage();
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
}

// ── Client area ──────────────────────────────────────────────────────────

function xiproxslotip_ClientArea(array $params): array
{
    $vars = ['slotId' => '', 'slot' => null, 'panelUrl' => '', 'error' => ''];
    try {
        $slotId = Helper::getSlotId($params);
        $vars['slotId'] = $slotId;
        if ($slotId !== '') {
            $client = Helper::client($params);
            $vars['slot'] = $client->get('/slots/' . rawurlencode($slotId));

            $customerId = Helper::getCustomerId($params);
            if ($customerId !== '') {
                try {
                    $link = $client->post('/customers/' . rawurlencode($customerId) . '/login-link', []);
                    $vars['panelUrl'] = (string) ($link['url'] ?? '');
                } catch (\Throwable $e) {
                    // No link — button hides.
                }
            }
        }
    } catch (\Throwable $e) {
        $vars['error'] = $e->getMessage();
    }

    return ['templatefile' => 'overview', 'vars' => $vars];
}

function xiproxslotip_ClientAreaCustomButtonArray(): array
{
    return [
        'Start' => 'Start',
        'Stop' => 'Stop',
        'Reset User' => 'ResetUser',
        'Reset Password' => 'ResetPassword',
        'Rotate IP' => 'RotateIp',
    ];
}

function xiproxslotip_Start(array $params): string
{
    return xiproxslotip_action($params, 'start', 'Start');
}

function xiproxslotip_Stop(array $params): string
{
    return xiproxslotip_action($params, 'stop', 'Stop');
}

/** Reset proxy user to the configured username + the WHMCS service password. */
function xiproxslotip_ResetUser(array $params): string
{
    try {
        $slotId = Helper::getSlotId($params);
        if ($slotId === '') {
            return 'This service has no provisioned slot yet.';
        }
        $order = xiproxslotip_settingOrder();
        $username = Helper::orderValue($params, 'Username', (string) Helper::setting($params, 'Default Username', '', $order));
        if ($username === '') {
            $username = 'u' . (int) ($params['serviceid'] ?? 0);
        }
        $res = Helper::client($params)->post('/slots/' . rawurlencode($slotId) . '/action', [
            'action' => 'reset-user',
            'username' => $username,
            'password' => (string) ($params['password'] ?? ''),
        ]);
        Helper::log('ResetUser', $slotId, $res);
        return 'success';
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
}

/** Reset proxy password to the WHMCS service password. */
function xiproxslotip_ResetPassword(array $params): string
{
    try {
        $slotId = Helper::getSlotId($params);
        if ($slotId === '') {
            return 'This service has no provisioned slot yet.';
        }
        $res = Helper::client($params)->post('/slots/' . rawurlencode($slotId) . '/action', [
            'action' => 'reset-password',
            'password' => (string) ($params['password'] ?? ''),
        ]);
        Helper::log('ResetPassword', $slotId, $res);
        return 'success';
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
}

function xiproxslotip_RotateIp(array $params): string
{
    try {
        $slotId = Helper::getSlotId($params);
        if ($slotId === '') {
            return 'This service has no provisioned slot yet.';
        }
        $order = xiproxslotip_settingOrder();
        $ipPoolId = Helper::orderValue($params, 'IP Pool', (string) Helper::setting($params, 'Default IP Pool', '', $order));
        if ($ipPoolId === '') {
            return 'No IP pool configured to rotate into.';
        }
        $res = Helper::client($params)->post('/slots/' . rawurlencode($slotId) . '/action', [
            'action' => 'rotate-ip',
            'ipPoolId' => $ipPoolId,
        ]);
        Helper::log('RotateIp', ['slotId' => $slotId, 'pool' => $ipPoolId], $res);
        return 'success';
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
}

function xiproxslotip_ServiceSingleSignOn(array $params): array
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

function xiproxslotip_action(array $params, string $action, string $logLabel): string
{
    try {
        $slotId = Helper::getSlotId($params);
        if ($slotId === '') {
            return 'This service has no provisioned slot yet.';
        }
        $res = Helper::client($params)->post('/slots/' . rawurlencode($slotId) . '/action', ['action' => $action]);
        Helper::log($logLabel, ['slotId' => $slotId, 'action' => $action], $res);
        return 'success';
    } catch (ApiException $e) {
        if ($e->getApiCode() === 'conflict') {
            return 'success';
        }
        return $e->getMessage();
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
}
