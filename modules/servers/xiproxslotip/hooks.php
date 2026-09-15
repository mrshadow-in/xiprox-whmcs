<?php

/**
 * xiProx Slot IP — reconcile poll (safety net for missed status changes).
 * Mirrors the VM module's poll but for Slot IP services (GET /slots/{id}).
 * The reseller-wallet low-balance warning is emitted by the VM module's hooks;
 * if you run ONLY the Slot IP module, this also surfaces that warning.
 */

use Illuminate\Database\Capsule\Manager as Capsule;
use XiProx\ApiClient;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/../xiproxcloud/lib/ApiClient.php';

function xiproxslotip_hook_client(object $server): ?ApiClient
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
    return $apiKey === '' ? null : new ApiClient((string) $host, $apiKey);
}

add_hook('AfterCronJob', 1, function () {
    $servers = Capsule::table('tblservers')->where('type', 'xiproxslotip')->where('disabled', 0)->get();
    $clients = [];
    $warnedNoVmModule = false;
    foreach ($servers as $server) {
        $client = xiproxslotip_hook_client($server);
        if (!$client) {
            continue;
        }
        $clients[(int) $server->id] = $client;

        // Only warn here if there is no VM (xiproxcloud) server that already does.
        if (!$warnedNoVmModule) {
            $hasVmServer = Capsule::table('tblservers')->where('type', 'xiproxcloud')->where('disabled', 0)->exists();
            $warnedNoVmModule = true;
            if (!$hasVmServer) {
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
                    // ignore
                }
            }
        }
    }

    $rows = Capsule::table('tblhosting')
        ->join('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
        ->where('tblproducts.servertype', 'xiproxslotip')
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
        $slotId = (string) Capsule::table('tblcustomfieldsvalues')
            ->join('tblcustomfields', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
            ->where('tblcustomfields.fieldname', 'xiProx Slot ID')
            ->where('tblcustomfieldsvalues.relid', $row->id)
            ->value('tblcustomfieldsvalues.value');
        if ($slotId === '') {
            continue;
        }
        try {
            $slot = $client->get('/slots/' . rawurlencode($slotId));
            $panelStatus = (string) ($slot['status'] ?? '');
            if ($panelStatus === 'suspended' && $row->domainstatus === 'Active') {
                Capsule::table('tblhosting')->where('id', $row->id)->update(['domainstatus' => 'Suspended']);
                logActivity("xiProx poll: suspended slot service #{$row->id} (slot {$slotId}) to match panel.");
            } elseif (in_array($panelStatus, ['active', 'stopped'], true) && $row->domainstatus === 'Suspended') {
                Capsule::table('tblhosting')->where('id', $row->id)->update(['domainstatus' => 'Active']);
                logActivity("xiProx poll: unsuspended slot service #{$row->id} (slot {$slotId}) to match panel.");
            }
        } catch (\Throwable $e) {
            // next cron retries
        }
    }
});
