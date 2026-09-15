<?php

namespace XiProx;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Thin cURL client for the xiProx reseller API (/api/v1/reseller/*).
 *
 * Auth is a single bearer token — the reseller's "WHMCS" API key, stored as the
 * server password in WHMCS. The base URL is the panel URL (server hostname).
 * The webhook salt (access hash) is NOT used here; it only verifies inbound
 * webhooks in webhook.php.
 *
 * Every call returns the decoded `data` envelope on success, or throws
 * ApiException with a human-friendly message on failure (never leaks the key).
 */
class ApiClient
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;

    public function __construct(string $hostname, string $apiKey, int $timeout = 30)
    {
        $this->baseUrl = self::normalizeBase($hostname);
        $this->apiKey = trim($apiKey);
        $this->timeout = $timeout;
    }

    /** Build a client straight from a WHMCS $params array. */
    public static function fromParams(array $params): self
    {
        $host = $params['serverhostname'] ?? '';
        if ($host === '' && !empty($params['serverip'])) {
            $host = $params['serverip'];
        }
        // WHMCS "Secure" toggle → force https when set.
        if (!empty($params['serversecure']) && !preg_match('#^https?://#i', $host)) {
            $host = 'https://' . $host;
        }
        return new self((string) $host, (string) ($params['serverpassword'] ?? ''));
    }

    private static function normalizeBase(string $host): string
    {
        $host = trim($host);
        if (!preg_match('#^https?://#i', $host)) {
            $host = 'https://' . $host;
        }
        return rtrim($host, '/');
    }

    public function get(string $path, array $query = []): array
    {
        $qs = $query ? ('?' . http_build_query($query)) : '';
        return $this->request('GET', $path . $qs, null);
    }

    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $path, $body);
    }

    public function put(string $path, array $body = []): array
    {
        return $this->request('PUT', $path, $body);
    }

    public function patch(string $path, array $body = []): array
    {
        return $this->request('PATCH', $path, $body);
    }

    public function delete(string $path, array $body = []): array
    {
        return $this->request('DELETE', $path, $body ?: null);
    }

    /**
     * Perform a request. On a 2xx returns the `data` payload (array). On any
     * other status, throws ApiException carrying the API's error.message.
     */
    private function request(string $method, string $path, ?array $body): array
    {
        $url = $this->baseUrl . '/api/v1/reseller' . $path;

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new ApiException('Could not reach the xiProx panel: ' . $curlErr, 0);
        }

        $decoded = json_decode((string) $raw, true);

        if ($status >= 200 && $status < 300) {
            if (is_array($decoded) && array_key_exists('data', $decoded)) {
                return is_array($decoded['data']) ? $decoded['data'] : ['value' => $decoded['data']];
            }
            return is_array($decoded) ? $decoded : [];
        }

        // Error envelope: { error: { code, message, details? } }
        $message = 'Request failed (HTTP ' . $status . ').';
        $code = 'error';
        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
            $message = (string) ($decoded['error']['message'] ?? $message);
            $code = (string) ($decoded['error']['code'] ?? $code);
        }
        throw new ApiException($message, $status, $code);
    }
}

class ApiException extends \Exception
{
    private string $apiCode;

    public function __construct(string $message, int $status = 0, string $apiCode = 'error')
    {
        parent::__construct($message, $status);
        $this->apiCode = $apiCode;
    }

    public function getApiCode(): string
    {
        return $this->apiCode;
    }
}
