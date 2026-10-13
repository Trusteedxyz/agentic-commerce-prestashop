<?php

declare(strict_types=1);

namespace Trusteed\Service;

use Trusteed\AgenticTools\BackendApiClient;

/**
 * AutoRegister: self-serve store registration against the Trusteed backend.
 *
 * Follows this module's conventions:
 *   - PSR-4 autoloaded (`Trusteed\Service`), no manual require_once.
 *   - API-base validation reuses the module's own SSRF classifier
 *     (BackendApiClient::isPublicIp) rather than a separate helper.
 *
 * POSTs the store fingerprint to `{apiBase}/v1/embed/ps/auto-register` and returns
 * the freshly minted `{merchant_id, bootstrap_secret, install_key, is_new}`, so the
 * merchant can activate the module without pasting credentials by hand. The manual
 * paste flow (module config page) remains fully supported; this is additive.
 */
final class AutoRegister implements ReadKeyBackend
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
     * Deregisters the store from Trusteed.
     *
     * PrestaShop has no equivalent of Shopify's `app/uninstalled`: without
     * this call, `uninstall()` would only delete the local `Configuration::`
     * values and tell nobody, so the merchant would stay listed in the public
     * registry and directory with its MCP endpoint advertised, and its score
     * would keep being recalculated on a frozen catalog.
     *
     * **NEVER throws.** Unlike `register()`, the caller is `uninstall()`, and
     * an exception there would leave the merchant with a half-uninstalled
     * module. A stale registry entry is the lesser problem, and it has a
     * second safety net: the heartbeat sweep, which does not depend on this
     * notice.
     *
     * @param string $storeUrl   Public store URL
     * @param string $installKey Durable credential issued at registration
     *
     * @return bool `true` only if the backend confirmed the deregistration.
     */
    public function deregister(string $storeUrl, string $installKey): bool
    {
        if ($installKey === '') {
            return false;
        }

        $payload = json_encode([
            'store_url'   => $storeUrl,
            'install_key' => $installKey,
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return false;
        }

        $ch = curl_init($this->apiBase . '/v1/embed/ps/deprovision');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: Trusteed-PS/1.0',
            ],
            CURLOPT_POSTFIELDS     => $payload,
            // Shorter timeouts than at registration: this runs while the
            // merchant waits for the uninstall to finish.
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return false;
        }

        return $status === 200;
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
            // Declares that this module can publish
            // `.well-known/amcp-verify.txt` and redeem the challenge. Without
            // this flag the backend keeps returning the plain 409, which is
            // correct for an older module: a 202 would fall into its
            // "API rejected request (HTTP 202)" branch, which is worse.
            'supports_domain_verification' => true,
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

        // The backend offers a domain ownership proof instead of leaving us
        // stuck. A typed exception is thrown so the caller can publish the
        // token and retry; the message stays readable in case nobody catches it.
        if ($status === 202 && ($decoded['error'] ?? '') === 'DOMAIN_VERIFICATION_REQUIRED') {
            $challenge = is_array($decoded['challenge'] ?? null) ? $decoded['challenge'] : [];

            throw new DomainVerificationRequired(
                (string) ($challenge['token'] ?? ''),
                (string) ($decoded['message'] ?? 'Domain verification required.')
            );
        }

        if ($status === 409) {
            throw new \RuntimeException(
                'auto-register: this store URL is already registered with a different ' .
                'install key. If this is your store, update the module so it can prove ' .
                'domain ownership, or contact Trusteed support.'
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
     * Redeems a domain challenge that has already been published.
     *
     * Call it AFTER the token has been written to
     * `.well-known/amcp-verify.txt`: the backend fetches it from outside, so
     * calling earlier finds no file and the redemption fails.
     *
     * Returns the same credentials as `register()`, with a NEW `install_key`:
     * if it were not rotated, whoever held the previous one could lock the
     * owner out again on the next call.
     *
     * @return array{merchant_id:string, bootstrap_secret:string, install_key:string, is_new:bool}
     * @throws \RuntimeException on network error or API rejection
     */
    public function verifyChallenge(string $storeUrl, string $challengeToken): array
    {
        $payload = json_encode([
            'store_url'       => $storeUrl,
            'challenge_token' => $challengeToken,
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new \RuntimeException('verify-challenge: failed to encode payload');
        }

        $ch = curl_init($this->apiBase . '/v1/embed/ps/verify-challenge');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: Trusteed-PS/1.0',
            ],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 20,
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
                'verify-challenge: network error (curl errno=' . $errno . ')'
            );
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('verify-challenge: invalid JSON response');
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            $decoded = $decoded['data'];
        }

        if ($status !== 200) {
            // The reason describes the state of the CHALLENGE (unreadable file,
            // expired token, mismatching content...), which is exactly what the
            // merchant needs in order to fix it.
            $reason = $decoded['reason'] ?? $decoded['error'] ?? 'unknown';
            throw new \RuntimeException(
                "verify-challenge: domain verification failed ({$reason})."
            );
        }

        if (empty($decoded['merchantId']) || empty($decoded['bootstrapSecret'])) {
            throw new \RuntimeException('verify-challenge: response missing required fields');
        }

        return [
            'merchant_id'      => (string) $decoded['merchantId'],
            'bootstrap_secret' => (string) $decoded['bootstrapSecret'],
            'install_key'      => (string) ($decoded['installKey'] ?? ''),
            'is_new'           => false,
        ];
    }

    /**
     * Hands Trusteed the READ key the merchant has just authorized. Same
     * credential as registration and deregistration (`install_key`) and same
     * client with its SSRF guard: no weaker channel is opened.
     *
     * The backend tests the key against the store before saving it (up to
     * three GETs of 10 s each), hence the long timeout.
     *
     * @throws ReadKeyBackendException with a short code; never with the key
     */
    public function attachReadKey(string $storeUrl, string $installKey, string $apiKey): void
    {
        [$status, $decoded] = $this->postJson('/v1/embed/ps/read-key', [
            'store_url'   => $storeUrl,
            'install_key' => $installKey,
            'api_key'     => $apiKey,
        ], 45);

        if ($status === 200 && (($decoded['attached'] ?? false) === true)) {
            return;
        }

        if ($status === 0) {
            throw new ReadKeyBackendException('network_error');
        }

        $reason = $decoded['reason'] ?? $decoded['error'] ?? ('http_' . $status);
        throw new ReadKeyBackendException(is_string($reason) ? $reason : ('http_' . $status));
    }

    /**
     * Asks Trusteed to forget the read key. Never throws: it is also called
     * from `uninstall()`.
     */
    public function revokeReadKey(string $storeUrl, string $installKey): bool
    {
        if ($installKey === '') {
            return false;
        }

        [$status] = $this->postJson('/v1/embed/ps/read-key/revoke', [
            'store_url'   => $storeUrl,
            'install_key' => $installKey,
        ], 10);

        return $status === 200;
    }

    /**
     * JSON POST without retries. Returns `[0, []]` on a network error: the
     * caller decides what that means. The body is never included in any error.
     *
     * @param array<string, mixed> $body
     *
     * @return array{0:int, 1:array<string, mixed>}
     */
    private function postJson(string $path, array $body, int $timeout): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return [0, []];
        }

        $ch = curl_init($this->apiBase . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: Trusteed-PS/1.0',
            ],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // No redirects: the key must not travel to another host.
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return [0, []];
        }

        $decoded = json_decode((string) $response, true);

        return [$status, is_array($decoded) ? $decoded : []];
    }

    /**
     * Rejects non-HTTPS URLs and hosts that resolve to a private / loopback /
     * link-local / reserved IP (SSRF guard). Reuses BackendApiClient::isPublicIp
     * (the module's canonical IP classifier) for parity with the other HTTP
     * clients, instead of duplicating the validation.
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
