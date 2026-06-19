<?php
/**
 * TokenBroker — PS module token helper.
 *
 * Relays a server-to-server request to apps/api `POST /v1/embed/ps/issue-token`
 * with the per-merchant `X-Embed-Ps-Secret` header and receives back an OPAQUE
 * Redis-backed token (UUID, TTL <= 900s). Pure PHP — no Composer dependency.
 *
 * Design decision (spec-042 §"Implementation Deviation Note"):
 *   The canonical PS token is the OPAQUE token from /v1/embed/ps/issue-token,
 *   NOT the Ed25519 JWT from the legacy /api/v1/auth/embed-bootstrap flow. The
 *   embed data routes (`requireAnyEmbedAuth({sources:[...,'ps-embed']})`) validate
 *   the token via `EmbedTokenService.validate(jti)` against Redis — they do NOT
 *   verify an Ed25519 JWT. Issuing a JWT here guaranteed a 401 on every data call.
 *
 * Threat model:
 *   - Secret never leaves the server — sourced from Configuration::get(...) and
 *     sent only in the X-Embed-Ps-Secret request header (never to the DOM).
 *   - api_base is SSRF-guarded (HTTPS + trusteed.xyz host allow-list).
 *   - CURLOPT_SSL_VERIFYPEER + VERIFYHOST enforced on the relay.
 *   - Opaque token returned in JSON response body; SPA stores in sessionStorage
 *     (tab-scoped) and refreshes on 401 (token TTL <= 900s).
 *
 * @namespace AgenticMcpStores\Mvp
 */

namespace AgenticMcpStores\Mvp;

class TokenBroker
{
    private string $merchantId;
    private string $secret;
    private string $apiBase;
    private string $shopDomain;
    private int    $idShop;
    /** @var array<int> */
    private array $allowedShops;
    private int   $employeeId;
    private bool  $allShops;

    /**
     * @param array<int> $allowedShops
     * @param bool       $allShops     True when employee is PS super-admin (all shops access).
     */
    public function __construct(
        string $merchantId,
        string $secret,
        string $apiBase,
        string $shopDomain,
        int    $idShop,
        array  $allowedShops,
        int    $employeeId,
        bool   $allShops = false
    ) {
        self::validateApiBasePublic($apiBase);
        $this->merchantId   = $merchantId;
        $this->secret       = $secret;
        $this->apiBase      = rtrim($apiBase, '/');
        $this->shopDomain   = $shopDomain;
        $this->idShop       = $idShop;
        $this->allowedShops = $allowedShops;
        $this->employeeId   = $employeeId;
        $this->allShops     = $allShops;
    }

    /**
     * Validate that api_base is HTTPS and does not target a private/internal host.
     *
     * Public so that the wizard controller and module config page can call it
     * before persisting user-supplied values, without constructing a full broker.
     *
     * Rejects:
     *   - Non-HTTPS schemes (http, ftp, file, etc.)
     *   - localhost / 127.x.x.x (loopback)
     *   - RFC-1918 private ranges: 10.x, 172.16-31.x, 192.168.x
     *   - Link-local / APIPA: 169.254.x (AWS/GCP metadata)
     *   - All-zeros: 0.0.0.0
     *   - IPv6 loopback: ::1
     *   - IPv6 private ULA: fc00::/7 (fc00:: – fdff::)
     *   - IPv6 link-local: fe80::/10
     *   - IPv4-mapped IPv6: ::ffff:x.x.x.x (strips wrapper and re-checks IPv4)
     *
     * NOTE: DNS rebinding attacks (hostname that resolves to a private IP at
     * request time) cannot be prevented here without performing a DNS lookup.
     * Defense in depth relies on CURLOPT_SSL_VERIFYPEER + external firewall rules.
     *
     * @throws \InvalidArgumentException
     */
    public static function validateApiBasePublic(string $url): void
    {
        $parsed = parse_url($url);
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        $host   = strtolower((string) ($parsed['host'] ?? ''));

        // Strip IPv6 literal brackets so loopback/IPv4-mapped addresses cannot
        // bypass assertHostNotInternal (e.g. https://[::1]/ or [::ffff:127.0.0.1]).
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if ($scheme !== 'https') {
            throw new \InvalidArgumentException(
                'api_base must be HTTPS and target an external host'
            );
        }

        if ($host === '') {
            throw new \InvalidArgumentException(
                'api_base must be HTTPS and target an external host'
            );
        }

        self::assertHostNotInternal($host);
    }

    /**
     * @throws \InvalidArgumentException when $host is a private/reserved address.
     */
    private static function assertHostNotInternal(string $host): void
    {
        // Exact hostname/IPv6 literals
        if (in_array($host, ['localhost', '::1', '0.0.0.0'], true)) {
            throw new \InvalidArgumentException(
                'api_base must be HTTPS and target an external host'
            );
        }

        // IPv4-mapped IPv6: ::ffff:1.2.3.4  →  unwrap and re-check IPv4
        if (str_starts_with($host, '::ffff:')) {
            $ipv4Part = substr($host, 7);
            $ipLong   = ip2long($ipv4Part);
            if ($ipLong !== false) {
                self::assertIpv4NotInternal($ipLong);
            }
            return; // non-numeric suffix treated as external hostname
        }

        // IPv6 private ranges (fc00::/7 = ULA, fe80::/10 = link-local)
        if (str_contains($host, ':')) {
            // Expand short-form to detect prefixes
            $firstSegment = (int) hexdec(explode(':', $host)[0]);
            // fc00::/7  →  first byte 0xFC or 0xFD
            if (($firstSegment & 0xFE00) === 0xFC00) {
                throw new \InvalidArgumentException(
                    'api_base must be HTTPS and target an external host'
                );
            }
            // fe80::/10  →  first segment 0xFE80..0xFEBF
            if (($firstSegment & 0xFFC0) === 0xFE80) {
                throw new \InvalidArgumentException(
                    'api_base must be HTTPS and target an external host'
                );
            }
            return; // other IPv6 or hostname — allow
        }

        // IPv4 literal
        $ipLong = ip2long($host);
        if ($ipLong !== false) {
            self::assertIpv4NotInternal($ipLong);
        }
        // S042-004: Hostname — restrict to known Trusteed domains to mitigate DNS
        // rebinding (a hostname that resolves to a private IP at request time).
        if (!preg_match('/(?:^|\.)trusteed\.xyz$/', $host)) {
            throw new \InvalidArgumentException(
                'api_base hostname must be a trusteed.xyz domain'
            );
        }
    }

    /** @throws \InvalidArgumentException */
    private static function assertIpv4NotInternal(int $ipLong): void
    {
        $ranges = [
            [0x7F000000, 0xFF000000], // 127.0.0.0/8   loopback
            [0x0A000000, 0xFF000000], // 10.0.0.0/8    RFC-1918
            [0xAC100000, 0xFFF00000], // 172.16.0.0/12 RFC-1918
            [0xC0A80000, 0xFFFF0000], // 192.168.0.0/16 RFC-1918
            [0xA9FE0000, 0xFFFF0000], // 169.254.0.0/16 link-local / metadata
            [0x00000000, 0xFFFFFFFF], // 0.0.0.0/32    all-zeros
        ];
        foreach ($ranges as [$network, $mask]) {
            if (($ipLong & $mask) === $network) {
                throw new \InvalidArgumentException(
                    'api_base must be HTTPS and target an external host'
                );
            }
        }
    }

    /**
     * Relay a server-to-server request to /v1/embed/ps/issue-token and return
     * the OPAQUE token issued by apps/api.
     *
     * The result keeps the key name `access_token` for backwards compatibility
     * with the SPA bootstrap glue (trust.tpl / wizard.tpl read `data.access_token`),
     * but the value is now an opaque Redis-backed token — NOT an Ed25519 JWT.
     * `expires_in` is derived from the `expires_at` returned by the API.
     *
     * @return array{access_token:string,expires_in:int,merchant_id:string,jti:string}
     * @throws \RuntimeException on network failure or API rejection
     */
    public function exchange(): array
    {
        $endpoint = $this->apiBase . '/v1/embed/ps/issue-token';

        $payload = json_encode($this->buildRequestBody(), JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new \RuntimeException('Failed to encode issue-token request body');
        }

        [$respBody, $status] = $this->execWithRetry($endpoint, $payload);

        if ($respBody === false) {
            throw new \RuntimeException('issue-token relay failed: network error');
        }

        $decoded = json_decode((string) $respBody, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('issue-token response not JSON');
        }

        // Unwrap an optional { data: {...} } envelope.
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            $decoded = $decoded['data'];
        }

        if ($status !== 200) {
            $reason = $decoded['error'] ?? 'unknown';
            throw new \RuntimeException("issue-token rejected (status={$status}): {$reason}");
        }

        if (!isset($decoded['token'])) {
            throw new \RuntimeException('issue-token response missing token');
        }

        $expiresIn = self::resolveExpiresIn($decoded['expires_at'] ?? null);

        return [
            'access_token' => (string) $decoded['token'],
            'expires_in'   => $expiresIn,
            'merchant_id'  => $this->merchantId,
            'jti'          => (string) ($decoded['jti'] ?? ''),
        ];
    }

    // ─── Private helpers ───────────────────────────────────────────────────────

    /**
     * Build the issue-token request body, applying the multishop scope contract.
     *
     *   - Super-admin (allShops === true): emit an explicit `all_shops: true`
     *     claim and DO NOT send a truncated `allowed_shops` list. A super-admin
     *     with >64 shops would otherwise be silently scoped to the first 64
     *     (the array_slice cap) — a partial-authorization bug. The backend
     *     authorizes every store when `all_shops` is true, with no 64 cap.
     *   - Scoped employee (allShops === false): emit the explicit shop id list,
     *     bounded to 64 entries by the backend Zod schema. The 64 cap is a
     *     deliberate ceiling for non-super-admins (whose accessible shop set is
     *     expected to be small); a scoped employee with >64 shops is an unusual
     *     configuration and is intentionally truncated rather than escalated.
     *
     * @return array<string,mixed>
     */
    private function buildRequestBody(): array
    {
        $baseBody = [
            'merchant_id'            => $this->merchantId,
            'ps_employee_id'         => (string) $this->employeeId,
            'capability_attestation' => 'admin_trusteed',
        ];

        if ($this->allShops) {
            $scopeBody = ['all_shops' => true];
        } elseif (count($this->allowedShops) > 0) {
            $scopeBody = ['allowed_shops' => array_values(
                array_slice(array_map('intval', $this->allowedShops), 0, 64)
            )];
        } else {
            $scopeBody = [];
        }

        return array_merge($baseBody, $scopeBody);
    }

    /**
     * Execute the relay POST with one retry on a network-level timeout.
     *
     * The issue-token endpoint is idempotent at the merchant/employee level
     * (each call mints a fresh opaque token), so a retry after a transient
     * timeout is safe — no anti-replay concern (unlike the old jti-bound JWT).
     *
     * @return array{0:string|false,1:int}  [responseBody, httpStatus]
     */
    private function execWithRetry(string $url, string $payload): array
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Embed-Ps-Secret: ' . $this->secret,
            'User-Agent: Trusteed-PS/1.0',
        ];

        $send = static function () use ($url, $payload, $headers): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body   = curl_exec($ch);
            $errno  = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return [$body, $status, $errno];
        };

        [$body, $status, $errno] = $send();

        if ($body === false && $errno === CURLE_OPERATION_TIMEDOUT) {
            // Single retry after a 200ms back-off on timeout.
            usleep(200_000);
            [$body, $status] = $send();
        }

        return [$body, $status];
    }

    /**
     * Convert the API `expires_at` (ISO-8601 string or unix seconds) into a
     * relative TTL in seconds. Falls back to 300s on any parse failure.
     */
    private static function resolveExpiresIn(mixed $expiresAt): int
    {
        if (is_int($expiresAt)) {
            return max(0, $expiresAt - time());
        }
        if (is_string($expiresAt) && $expiresAt !== '') {
            $ts = strtotime($expiresAt);
            if ($ts !== false) {
                return max(0, $ts - time());
            }
        }
        return 300;
    }
}
