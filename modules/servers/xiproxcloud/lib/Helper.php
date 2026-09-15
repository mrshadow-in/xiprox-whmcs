<?php

namespace XiProx;

use Illuminate\Database\Capsule\Manager as Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/ApiClient.php';

/**
 * Glue between WHMCS $params and the xiProx API: builds the client, reads
 * module settings / configurable options / custom fields, and persists the
 * panel VM id + customer id per service (in auto-created admin-only custom
 * fields, so WHMCS ↔ panel stays linked across renewals and retries).
 */
class Helper
{
    public const FIELD_VM_ID = 'xiProx VM ID';
    public const FIELD_SLOT_ID = 'xiProx Slot ID';
    public const FIELD_CUSTOMER_ID = 'xiProx Customer ID';

    public static function client(array $params): ApiClient
    {
        return ApiClient::fromParams($params);
    }

    /**
     * Build a client from ANY configured xiProx server (for product-edit screens
     * like ConfigOptions, which get no service context). Picks the first enabled
     * server whose type is this module and decrypts its stored API key. Returns
     * null when none is usable, so callers can fall back to a text field.
     */
    public static function clientFromAnyServer(?string $moduleType = 'xiproxcloud'): ?ApiClient
    {
        $server = Capsule::table('tblservers')
            ->where('type', $moduleType)->where('disabled', 0)
            ->orderBy('id')->first();
        if (!$server) {
            return null;
        }
        $host = $server->hostname ?: $server->ipaddress;
        if ((string) $host === '') {
            return null;
        }
        if (!empty($server->secure) && !preg_match('#^https?://#i', (string) $host)) {
            $host = 'https://' . $host;
        }
        $apiKey = '';
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

    // ── Module settings (the module's own ConfigOptions) ──────────────────

    /**
     * A module setting by its option name. $order is the declared option order
     * from the module's ConfigOptions (defaults to the xiproxcloud VM module's).
     * The slot module passes its own order.
     */
    public static function setting(array $params, string $name, $default = '', ?array $order = null)
    {
        $order = $order ?? ['Plan', 'Default OS', 'Default IP Pool'];
        $i = array_search($name, $order, true);
        $index = $i === false ? 1 : ($i + 1);
        return $params['configoption' . $index] ?? $default;
    }

    // ── Client-supplied order fields (configurable options / custom fields) ─

    /** A product Configurable Option value by name, or '' if absent. */
    public static function configOption(array $params, string $name): string
    {
        $opts = $params['configoptions'] ?? [];
        return isset($opts[$name]) ? (string) $opts[$name] : '';
    }

    /** A product Custom Field value by name, or '' if absent. */
    public static function customField(array $params, string $name): string
    {
        $fields = $params['customfields'] ?? [];
        return isset($fields[$name]) ? (string) $fields[$name] : '';
    }

    /** Prefer a configurable option, then a custom field, then a fallback. */
    public static function orderValue(array $params, string $name, string $fallback = ''): string
    {
        $v = self::configOption($params, $name);
        if ($v !== '') {
            return $v;
        }
        $v = self::customField($params, $name);
        return $v !== '' ? $v : $fallback;
    }

    // ── Hostname derived from the WHMCS service ───────────────────────────

    public static function hostname(array $params): string
    {
        $raw = $params['domain'] ?? '';
        if ($raw === '') {
            $raw = 'vm-' . ($params['serviceid'] ?? '0');
        }
        // Panel accepts letters, numbers and hyphens only.
        $host = preg_replace('/[^a-zA-Z0-9-]/', '-', explode('.', (string) $raw)[0]);
        $host = trim((string) $host, '-');
        return $host !== '' ? $host : ('vm-' . ($params['serviceid'] ?? '0'));
    }

    // ── Per-service persisted panel ids (auto custom fields) ──────────────

    public static function getRemoteId(array $params): string
    {
        return self::readField($params, self::FIELD_VM_ID);
    }

    public static function setRemoteId(array $params, string $value): void
    {
        self::writeField($params, self::FIELD_VM_ID, $value);
    }

    public static function getSlotId(array $params): string
    {
        return self::readField($params, self::FIELD_SLOT_ID);
    }

    public static function setSlotId(array $params, string $value): void
    {
        self::writeField($params, self::FIELD_SLOT_ID, $value);
    }

    public static function getCustomerId(array $params): string
    {
        return self::readField($params, self::FIELD_CUSTOMER_ID);
    }

    public static function setCustomerId(array $params, string $value): void
    {
        self::writeField($params, self::FIELD_CUSTOMER_ID, $value);
    }

    private static function fieldId(int $productId, string $name): int
    {
        $row = Capsule::table('tblcustomfields')
            ->where('type', 'product')->where('relid', $productId)->where('fieldname', $name)
            ->first();
        if ($row) {
            return (int) $row->id;
        }
        return (int) Capsule::table('tblcustomfields')->insertGetId([
            'type' => 'product', 'relid' => $productId, 'fieldname' => $name,
            'fieldtype' => 'text', 'description' => 'Managed automatically by the xiProx module.',
            'adminonly' => 'on', 'required' => '', 'showorder' => '', 'showinvoice' => '',
            'sortorder' => 0, 'fieldoptions' => '', 'regexpr' => '',
        ]);
    }

    private static function readField(array $params, string $name): string
    {
        $serviceId = (int) ($params['serviceid'] ?? 0);
        $productId = (int) ($params['pid'] ?? 0);
        if (!$serviceId || !$productId) {
            return '';
        }
        $fid = self::fieldId($productId, $name);
        $val = Capsule::table('tblcustomfieldsvalues')->where('fieldid', $fid)->where('relid', $serviceId)->value('value');
        return (string) ($val ?? '');
    }

    private static function writeField(array $params, string $name, string $value): void
    {
        $serviceId = (int) ($params['serviceid'] ?? 0);
        $productId = (int) ($params['pid'] ?? 0);
        if (!$serviceId || !$productId) {
            return;
        }
        $fid = self::fieldId($productId, $name);
        $exists = Capsule::table('tblcustomfieldsvalues')->where('fieldid', $fid)->where('relid', $serviceId)->exists();
        if ($exists) {
            Capsule::table('tblcustomfieldsvalues')->where('fieldid', $fid)->where('relid', $serviceId)->update(['value' => $value]);
        } else {
            Capsule::table('tblcustomfieldsvalues')->insert(['fieldid' => $fid, 'relid' => $serviceId, 'value' => $value]);
        }
    }

    // ── Misc ──────────────────────────────────────────────────────────────

    /** paisa → a display string like "₹1,234.00". */
    public static function rupees(int $paisa): string
    {
        return '₹' . number_format($paisa / 100, 2);
    }

    /** Log a module action to the WHMCS module log (key is never logged). */
    public static function log(string $action, $request, $response): void
    {
        if (function_exists('logModuleCall')) {
            logModuleCall('xiproxcloud', $action, $request, $response);
        }
    }
}
