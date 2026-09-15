<?php

/**
 * xiProx → WHMCS inbound webhook receiver.
 *
 * Public URL: https://<your-whmcs>/modules/servers/xiproxcloud/webhook.php
 * Paste that into the panel's WHMCS integration settings. xiProx posts
 * vm.suspended / vm.resumed here when a reseller's wallet goes into arrears
 * (and clears). The body is HMAC-signed with the reseller's webhook salt — the
 * same value pasted as the server "Access Hash" in WHMCS.
 *
 * Security: verifies the signature against every configured xiProx server's
 * access hash (constant-time), enforces a ±5 min timestamp window to block
 * replays, and only ever flips a service that is linked to the named VM id.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

require __DIR__ . '/../../../init.php';

header('Content-Type: application/json');

function xiprox_webhook_respond(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['ok' => $code < 400, 'message' => $message]);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    xiprox_webhook_respond(400, 'Empty body.');
}

$signature = $_SERVER['HTTP_X_XIPROX_SIGNATURE'] ?? '';
if ($signature === '') {
    xiprox_webhook_respond(401, 'Missing signature.');
}

// ── Verify signature against any configured xiProx server's access hash ──
$verified = false;
$servers = Capsule::table('tblservers')->where('type', 'xiproxcloud')->where('disabled', 0)->get();
foreach ($servers as $server) {
    $salt = (string) ($server->accesshash ?? '');
    if ($salt === '') {
        continue;
    }
    $expected = 'sha256=' . hash_hmac('sha256', $raw, $salt);
    if (hash_equals($expected, $signature)) {
        $verified = true;
        break;
    }
}
if (!$verified) {
    xiprox_webhook_respond(401, 'Invalid signature.');
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    xiprox_webhook_respond(400, 'Invalid JSON.');
}

// Anti-replay: reject stale or future-dated events (±5 min).
$ts = (int) ($payload['timestamp'] ?? 0);
if ($ts <= 0 || abs(time() - $ts) > 300) {
    xiprox_webhook_respond(400, 'Stale or missing timestamp.');
}

$event = (string) ($payload['event'] ?? ($_SERVER['HTTP_X_XIPROX_EVENT'] ?? ''));
$data = $payload['data'] ?? [];
$vmId = (string) ($data['vmId'] ?? '');
if ($vmId === '') {
    xiprox_webhook_respond(400, 'Missing vmId.');
}

// ── Find the WHMCS service linked to this panel VM id ──
$serviceId = (int) Capsule::table('tblcustomfieldsvalues')
    ->join('tblcustomfields', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
    ->where('tblcustomfields.fieldname', 'xiProx VM ID')
    ->where('tblcustomfieldsvalues.value', $vmId)
    ->value('tblcustomfieldsvalues.relid');

if (!$serviceId) {
    // Unknown VM (maybe not sold through this WHMCS) — accept so xiProx doesn't retry forever.
    xiprox_webhook_respond(200, 'No matching service.');
}

$service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
if (!$service) {
    xiprox_webhook_respond(200, 'No matching service.');
}

$reason = (string) ($data['reason'] ?? '');

if ($event === 'vm.suspended') {
    if ($service->domainstatus !== 'Terminated' && $service->domainstatus !== 'Cancelled') {
        Capsule::table('tblhosting')->where('id', $serviceId)->update([
            'domainstatus' => 'Suspended',
        ]);
        logActivity("xiProx: suspended service #{$serviceId} (VM {$vmId}) — reason: {$reason}");
    }
    xiprox_webhook_respond(200, 'Suspended.');
}

if ($event === 'vm.resumed') {
    if ($service->domainstatus === 'Suspended') {
        Capsule::table('tblhosting')->where('id', $serviceId)->update([
            'domainstatus' => 'Active',
        ]);
        logActivity("xiProx: unsuspended service #{$serviceId} (VM {$vmId}) — reason: {$reason}");
    }
    xiprox_webhook_respond(200, 'Resumed.');
}

xiprox_webhook_respond(200, 'Ignored event: ' . $event);
