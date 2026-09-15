<?php

/**
 * xiProx Cloud — WHMCS hooks.
 *
 *  1. AfterCronJob: a safety-net poll that reconciles each linked service's
 *     status against the panel (in case a webhook was missed), and warns the
 *     admin when a reseller wallet is below its minimum — BEFORE customers get
 *     suspended for arrears.
 *
 * The signed webhook (webhook.php) is the primary, near-instant path; this poll
 * just guarantees eventual consistency.
 */

use Illuminate\Database\Capsule\Manager as Capsule;
use XiProx\ApiClient;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/ApiClient.php';

/** Build an ApiClient for a WHMCS server row (decrypts the stored API key). */
function xiproxcloud_hook_client(object $server): ?ApiClient
{
    $host = $server->hostname ?: $server->ipaddress;
    if ((string) $host === '') {
        return null;
    }
    if (!empty($server->secure) && !preg_match('#^https?://#i', (string) $host)) {
        $host = 'https://' . $host;
    }
    try {
        $dec = localAPI('DecryptPassword', ['password2' => $server->password]);
        $apiKey = (string) ($dec['password'] ?? '');
    } catch (\Throwable $e) {
        return null;
    }
    if ($apiKey === '') {
        return null;
    }
    return new ApiClient((string) $host, $apiKey);
}

add_hook('AfterCronJob', 1, function () {
    // ── 1. Per-server wallet warning ──
    $servers = Capsule::table('tblservers')->where('type', 'xiproxcloud')->where('disabled', 0)->get();
    $clients = [];
    foreach ($servers as $server) {
        $client = xiproxcloud_hook_client($server);
        if (!$client) {
            continue;
        }
        $clients[(int) $server->id] = $client;
        try {
            $wallet = $client->get('/wallet');
            $status = (string) ($wallet['status'] ?? '');
            $bal = (int) ($wallet['walletBalancePaisa'] ?? 0);
            $min = (int) ($wallet['minBalancePaisa'] ?? 0);
            if ($status === 'grace' || $status === 'suspended' || ($min > 0 && $bal < $min)) {
                logActivity(sprintf(
                    'xiProx WARNING: reseller wallet low on server "%s" (status: %s, balance: ₹%s / min ₹%s). Top up to avoid customer suspensions.',
                    $server->name,
                    $status !== '' ? $status : 'unknown',
                    number_format($bal / 100, 2),
                    number_format($min / 100, 2)
                ));
            }
        } catch (\Throwable $e) {
            // Ignore — connectivity issues shouldn't spam the log every cron.
        }
    }

    // ── 2. Reconcile service status ⇄ panel (bounded per run) ──
    $rows = Capsule::table('tblhosting')
        ->join('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
        ->where('tblproducts.servertype', 'xiproxcloud')
        ->whereIn('tblhosting.domainstatus', ['Active', 'Suspended'])
        ->where('tblhosting.server', '>', 0)
        ->select('tblhosting.id', 'tblhosting.server', 'tblhosting.domainstatus')
        ->limit(200)
        ->get();

    foreach ($rows as $row) {
        $client = $clients[(int) $row->server] ?? null;
        if (!$client) {
            continue;
        }
        $vmId = (string) Capsule::table('tblcustomfieldsvalues')
            ->join('tblcustomfields', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
            ->where('tblcustomfields.fieldname', 'xiProx VM ID')
            ->where('tblcustomfieldsvalues.relid', $row->id)
            ->value('tblcustomfieldsvalues.value');
        if ($vmId === '') {
            continue;
        }

        try {
            $vm = $client->get('/vms/' . rawurlencode($vmId));
            $panelStatus = (string) ($vm['status'] ?? '');
            if ($panelStatus === 'suspended' && $row->domainstatus === 'Active') {
                Capsule::table('tblhosting')->where('id', $row->id)->update(['domainstatus' => 'Suspended']);
                logActivity("xiProx poll: suspended service #{$row->id} (VM {$vmId}) to match panel.");
            } elseif (in_array($panelStatus, ['active', 'stopped'], true) && $row->domainstatus === 'Suspended') {
                Capsule::table('tblhosting')->where('id', $row->id)->update(['domainstatus' => 'Active']);
                logActivity("xiProx poll: unsuspended service #{$row->id} (VM {$vmId}) to match panel.");
            }
        } catch (\Throwable $e) {
            // Skip this one; the next cron retries.
        }
    }
});
