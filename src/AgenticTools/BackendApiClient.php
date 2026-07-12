<?php

declare(strict_types=1);

/**
 * BackendApiClient — lightweight HTTP client for the Trusteed backend API.
 *
 * Features:
 *  - 10-second socket timeout
 *  - 1 automatic retry ONLY on transient failures (5xx / curl transport error).
 *    4xx responses (400/401/402/403/404/409/422/429, …) are NEVER retried — a
 *    blind retry on a 402/409 against a payment endpoint risks a double charge
 *    (spec-046 H7). The retry decision inspects the HTTP status, not merely the
 *    exception type.
 *  - TLS verification pinned ON (CURLOPT_SSL_VERIFYPEER / VERIFYHOST=2) to match
 *    the other module HTTP clients (AdminTrusteedController, SnapshotClient).
 *  - Optional Idempotency-Key header forwarding.
 *  - SSRF prevention: only HTTPS to externally-routable hosts.
 *  - Structured errors: every failure throws a McpToolException carrying a
 *    canonical FR-014 code + the originating HTTP status.
 */

namespace Trusteed\AgenticTools;

final class BackendApiClient implements BackendApiClientInterface
{
    /**
     * @param string      $apiUrl         Trusteed backend base URL.
     * @param string      $bootstrapToken Bearer token for agent-authenticated routes.
     * @param string|null $s2sSecret      Per-store S2S secret for the
     *                                     /api/v1/embed/agentic-tools/* routes
     *                                     (LANE 046-F1). Defaults to the bootstrap
     *                                     token (same value persisted at onboarding).
     */
    public function __construct(
        private readonly string $apiUrl,
        private readonly string $bootstrapToken,
        private readonly ?string $s2sSecret = null,
    ) {}

    /**
     * POST JSON payload to $endpoint and return decoded response.
     *
     * @param  string      $endpoint       Absolute path, e.g. "/api/v1/internal/agent-identity/verify"
     * @param  array<string,mixed> $payload JSON-serialisable body
     * @param  string|null $idempotencyKey Optional idempotency key (RFC 8792)
     * @return array<string,mixed>
     *
     * @throws McpToolException On non-2xx response or network failure after retry
     */
    public function call(string $endpoint, array $payload, ?string $idempotencyKey = null): array
    {
        $url = rtrim($this->apiUrl, '/') . '/' . ltrim($endpoint, '/');

        $this->assertHttps($url);

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->bootstrapToken,
            'Accept: application/json',
        ];

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        try {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new McpToolException(
                McpToolException::CODE_VALIDATION_FAILED,
                'Failed to encode request payload: ' . $e->getMessage(),
                null,
                $e,
            );
        }

        return $this->executeWithRetry($url, $headers, $body);
    }

    /**
     * POST JSON using per-store S2S auth (LANE 046-F1). Sends the
     * `X-Trusteed-S2S-Secret` header (NOT the Bearer) and injects `merchant_id`
     * so the merchant-facing `/api/v1/embed/agentic-tools/*` routes authenticate
     * + scope ownership by store.
     *
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>
     *
     * @throws McpToolException
     */
    public function callWithS2S(
        string $endpoint,
        array $payload,
        string $merchantId,
        ?string $idempotencyKey = null
    ): array {
        $secret = $this->s2sSecret ?? $this->bootstrapToken;
        if ($secret === '') {
            throw new McpToolException(
                McpToolException::CODE_VALIDATION_FAILED,
                'BackendApiClient: missing S2S secret'
            );
        }
        if ($merchantId === '') {
            throw new McpToolException(
                McpToolException::CODE_VALIDATION_FAILED,
                'BackendApiClient: missing merchant id for S2S call'
            );
        }

        $url = rtrim($this->apiUrl, '/') . '/' . ltrim($endpoint, '/');
        $this->assertHttps($url);

        $headers = [
            'Content-Type: application/json',
            'X-Trusteed-S2S-Secret: ' . $secret,
            'Accept: application/json',
        ];

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        // Authoritative store context — overwrite any caller value.
        $payload['merchant_id'] = $merchantId;

        try {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new McpToolException(
                McpToolException::CODE_VALIDATION_FAILED,
                'Failed to encode request payload: ' . $e->getMessage(),
                null,
                $e,
            );
        }

        return $this->executeWithRetry($url, $headers, $body);
    }

    /**
     * @param  list<string>  $headers
     * @return array<string,mixed>
     *
     * @throws McpToolException
     */
    private function executeWithRetry(string $url, array $headers, string $body): array
    {
        $lastError = null;

        for ($attempt = 0; $attempt <= 1; $attempt++) {
            try {
                return $this->execute($url, $headers, $body);
            } catch (McpToolException $e) {
                $lastError = $e;

                // H7 fix: ONLY retry on transient transport failures. A retry is
                // safe exclusively for curl errors (httpStatus === null) and 5xx
                // responses. Any 4xx (incl. 402 Payment Required / 409 Conflict /
                // 429 Too Many Requests) is a deterministic, non-idempotent
                // outcome — retrying could double-charge. Stop immediately.
                if (!$this->isRetryable($e)) {
                    throw $e;
                }
            }
        }

        throw $lastError;
    }

    /**
     * A failure is retryable only when it is a transport-level error (no HTTP
     * status was obtained) or a 5xx server error.
     */
    private function isRetryable(McpToolException $e): bool
    {
        return self::isRetryableStatus($e->getHttpStatus());
    }

    /**
     * PURE retry classifier (H7). Exposed for unit testing the double-charge
     * guard without driving real cURL.
     *
     *   - null   (transport/curl error) → retryable.
     *   - 5xx                            → retryable.
     *   - anything else (incl. 4xx like 402/409/429) → NOT retryable.
     */
    public static function isRetryableStatus(?int $status): bool
    {
        if ($status === null) {
            return true;
        }

        return $status >= 500 && $status <= 599;
    }

    /**
     * @param  list<string>  $headers
     * @return array<string,mixed>
     *
     * @throws McpToolException
     */
    private function execute(string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new McpToolException(
                McpToolException::CODE_UNREACHABLE,
                'Failed to initialise cURL handle'
            );
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $body,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_TIMEOUT         => 10,
            CURLOPT_CONNECTTIMEOUT  => 5,
            CURLOPT_FOLLOWLOCATION  => false,
            // TLS pinned ON — parity with AdminTrusteedController + SnapshotClient.
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            // Transport failure — no HTTP status. Retryable.
            throw new McpToolException(
                McpToolException::CODE_UNREACHABLE,
                'cURL error calling backend API: ' . $curlError,
                null
            );
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            // Carry the HTTP status so executeWithRetry() can decide retryability
            // and the calling tool can surface a canonical FR-014 code.
            throw McpToolException::fromHttpStatus(
                $httpCode,
                sprintf('Backend API returned HTTP %d for %s', $httpCode, $url)
            );
        }

        try {
            /** @var array<string,mixed>|null $decoded */
            $decoded = json_decode((string) $response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new McpToolException(
                McpToolException::CODE_UNREACHABLE,
                'Backend API returned malformed JSON: ' . $e->getMessage(),
                $httpCode,
                $e,
            );
        }

        if (!is_array($decoded)) {
            throw new McpToolException(
                McpToolException::CODE_UNREACHABLE,
                'Backend API returned non-JSON response',
                $httpCode
            );
        }

        return $decoded;
    }

    private function assertHttps(string $url): void
    {
        if (!str_starts_with($url, 'https://')) {
            throw new McpToolException(
                McpToolException::CODE_VALIDATION_FAILED,
                'BackendApiClient requires HTTPS URL; got: ' . $url
            );
        }

        $this->assertNotPrivateHost($url);
    }

    /**
     * Defends against SSRF / DNS-rebinding by rejecting hosts that resolve to a
     * private, loopback, link-local or reserved IP range — in addition to the
     * HTTPS-only scheme guard. A bare IP-literal host is validated directly; a
     * DNS name is resolved and every returned A/AAAA record is checked. This
     * mirrors the sibling WordPress client (spec-046 F8 parity).
     *
     * NOTE: a literal IP host is validated without DNS (deterministic). A DNS
     * name is resolved and EVERY returned A/AAAA record must be public.
     * Full DNS-rebinding mitigation still requires an egress-layer control
     * (same caveat documented in the Odoo/WP guards).
     *
     * @throws McpToolException When the host resolves to a non-public IP.
     */
    private function assertNotPrivateHost(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new McpToolException(
                McpToolException::CODE_VALIDATION_FAILED,
                'BackendApiClient: unparseable host in URL'
            );
        }

        // Strip IPv6 brackets if present.
        $host = trim($host, '[]');

        // Reject the localhost label outright (does not always resolve via DNS).
        if (strcasecmp($host, 'localhost') === 0) {
            throw new McpToolException(
                McpToolException::CODE_VALIDATION_FAILED,
                'BackendApiClient: host resolves to a non-public IP (SSRF blocked): ' . $host
            );
        }

        $candidates = [];

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $candidates[] = $host;
        } else {
            // Resolve both A and AAAA records; reject if ANY resolves privately.
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
            if (!self::isPublicIp($ip)) {
                throw new McpToolException(
                    McpToolException::CODE_VALIDATION_FAILED,
                    'BackendApiClient: host resolves to a non-public IP (SSRF blocked): ' . $host
                );
            }
        }
    }

    /**
     * PURE SSRF IP classifier. Returns true only for an externally-routable IP
     * (rejects RFC1918 private, loopback 127.x, link-local 169.254.x / IMDS,
     * CGNAT and other reserved ranges). Exposed for deterministic unit testing
     * without driving real DNS resolution.
     */
    public static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
