<?php

declare(strict_types=1);

namespace Trusteed\Service;

use Trusteed\AgenticTools\BackendApiClient;

/**
 * AutoRegister — self-serve store registration against the Trusteed backend.
 *
 * Ported from the deprecated `ps-module-trusteed` sibling (classes/AutoRegister.php)
 * and adapted to this module's conventions:
 *   - PSR-4 autoloaded (`Trusteed\Service`) — no manual require_once.
 *   - API-base validation reuses the module's own SSRF classifier
 *     (BackendApiClient::isPublicIp) instead of the old TokenBroker helper.
 *
 * POSTs the store fingerprint to `{apiBase}/v1/embed/ps/auto-register` and returns
 * the freshly minted `{merchant_id, bootstrap_secret, install_key, is_new}`, so the
 * merchant can activate the module without pasting credentials by hand. The manual
 * paste flow (module config page) remains fully supported — this is additive.
 */
final class AutoRegister
{
    private string $apiBase;

    /**
     * @throws \RuntimeException when $apiBase is not an HTTPS URL to a public host.
     */
    public function __construct(string $apiBase)
    {
        $this->validateApiBase($apiBase);
        $this->apiBase = rtrim($apiBase, '/');
    }

    /**
     * Registers (or re-registers) the PrestaShop store with Trusteed.
     *
     * @param string $storeUrl      Public store URL (https://...)
     * @param string $storeName     Store name (PS_SHOP_NAME)
     * @param string $adminEmail    Email of the employee installing the module
     * @param string $psVersion     PrestaShop version (_PS_VERSION_)
     * @param string $moduleVersion Module version
     * @param string $installKey    Empty on first install; required to re-register
     *
     * @return array{merchant_id:string, bootstrap_secret:string, install_key:string, is_new:bool}
     * @throws \RuntimeException on network error or API rejection
     */
    public function register(
        string $storeUrl,
        string $storeName,
        string $adminEmail,
        string $psVersion,
        string $moduleVersion,
        string $installKey = ''
    ): array {
        $payload = json_encode([
            'store_url'      => $storeUrl,
            'store_name'     => $storeName,
            'admin_email'    => $adminEmail,
            'ps_version'     => $psVersion,
            'module_version' => $moduleVersion,
            'install_key'    => $installKey,
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new \RuntimeException('auto-register: failed to encode payload');
        }

        $endpoint = $this->apiBase . '/v1/embed/ps/auto-register';

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: Trusteed-PS/1.0',
            ],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException(
                'auto-register: network error (curl errno=' . $errno . ')'
            );
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('auto-register: invalid JSON response');
        }

        // Unwrap { data: {...} } envelope
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            $decoded = $decoded['data'];
        }

        if ($status === 409) {
            throw new \RuntimeException(
                'auto-register: store already registered. ' .
                'Provide the install key to re-register.'
            );
        }

        if ($status !== 200 && $status !== 201) {
            $reason = $decoded['error'] ?? $decoded['code'] ?? 'unknown';
            throw new \RuntimeException(
                "auto-register: API rejected request (HTTP {$status}): {$reason}"
            );
        }

        if (empty($decoded['merchantId']) || empty($decoded['bootstrapSecret'])) {
            throw new \RuntimeException('auto-register: response missing required fields');
        }

        return [
            'merchant_id'      => (string) $decoded['merchantId'],
            'bootstrap_secret' => (string) $decoded['bootstrapSecret'],
            'install_key'      => (string) ($decoded['installKey'] ?? ''),
            'is_new'           => (bool) ($decoded['isNew'] ?? true),
        ];
    }

    /**
     * Rejects non-HTTPS URLs and hosts that resolve to a private / loopback /
     * link-local / reserved IP (SSRF guard). Reuses BackendApiClient::isPublicIp
     * — the module's canonical IP classifier — for parity with the other HTTP
     * clients, instead of duplicating the old TokenBroker validation.
     *
     * @throws \RuntimeException
     */
    private function validateApiBase(string $url): void
    {
        if (!str_starts_with($url, 'https://')) {
            throw new \RuntimeException('auto-register: API base must use HTTPS; got: ' . $url);
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new \RuntimeException('auto-register: unparseable host in API base');
        }

        // Strip IPv6 brackets.
        $host = trim($host, '[]');

        if (strcasecmp($host, 'localhost') === 0) {
            throw new \RuntimeException('auto-register: API base host is not public (SSRF blocked): ' . $host);
        }

        $candidates = [];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $candidates[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ip'])) {
                        $candidates[] = (string) $record['ip'];
                    }
                    if (isset($record['ipv6'])) {
                        $candidates[] = (string) $record['ipv6'];
                    }
                }
            }
        }

        foreach ($candidates as $ip) {
            if (!BackendApiClient::isPublicIp($ip)) {
                throw new \RuntimeException(
                    'auto-register: API base host is not public (SSRF blocked): ' . $host
                );
            }
        }
    }
}
