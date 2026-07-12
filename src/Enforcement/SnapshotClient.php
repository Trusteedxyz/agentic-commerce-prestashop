<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

use Trusteed\Storage\FileCache;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Trusteed CEL Snapshot Client for PrestaShop.
 * Pulls + verifies JWS-signed rule snapshot. APCu cache 5min (static fallback).
 * Uses curl (portable; file_get_contents may be off on shared hosts).
 *
 * @package Trusteed\Enforcement
 */
/**
 * Outcome of consumeNonce() — single-use replay protection (spec-048 P2.8).
 *
 *   ACCEPTED       — HTTP 200, nonce stored, token may be used.
 *   REPLAY         — HTTP 409, nonce already seen — caller must mark token INVALID.
 *   INDETERMINATE  — network / 5xx / 4xx / bad response — caller maps per failure_mode.
 */
class NonceOutcome
{
    public const ACCEPTED      = 'ACCEPTED';
    public const REPLAY        = 'REPLAY';
    public const INDETERMINATE = 'INDETERMINATE';
}

class SnapshotClient
{
    private string $apiBase;
    private string $merchantId;
    private string $installationId;
    private string $hmacSecret;

    /** Static in-process fallback when APCu unavailable */
    private static array $memCache = [];

    /**
     * Sprint C T13: long-TTL local snapshot cache (24h) used to evaluate rules
     * offline when the Layer-2 rules-evaluate endpoint is unavailable.
     * Distinct key namespace from the short-TTL pull cache (5min) so it
     * survives normal cache rotation.
     */
    public const LOCAL_SNAPSHOT_TTL_SECONDS = 86400;
    private const LOCAL_SNAPSHOT_KEY_PREFIX = 'trusteed_snapshot_';

    public function __construct(
        string $apiBase,
        string $merchantId,
        string $installationId,
        string $hmacSecret
    ) {
        $this->apiBase        = rtrim($apiBase, '/');
        $this->merchantId     = $merchantId;
        $this->installationId = $installationId;
        $this->hmacSecret     = $hmacSecret;
    }

    /** Factory: build from PS Configuration. Returns null when required values are missing. */
    public static function fromConfiguration(): ?self
    {
        $merchantId     = (string) \Configuration::get('TRUSTEED_CEL_MERCHANT_ID');
        $installationId = (string) \Configuration::get('TRUSTEED_CEL_INSTALLATION_ID');
        $hmacSecret     = (string) \Configuration::get('TRUSTEED_CEL_HMAC_SECRET');
        $apiBase        = (string) \Configuration::get('TRUSTEED_API_BASE');

        if ($apiBase === '') {
            $apiBase = 'https://api.trusteed.xyz';
        }

        if ($merchantId === '' || $installationId === '' || $hmacSecret === '') {
            return null;
        }

        return new self($apiBase, $merchantId, $installationId, $hmacSecret);
    }

    /**
     * Pull snapshot JWS with conditional GET. Returns cached on 304 / failure.
     * Verifies Ed25519 signature before updating cache.
     *
     * @return string|null JWS Compact string on success, null on failure.
     */
    public function pull(): ?string
    {
        $cacheKey = 'trusteed_cel_snap_' . substr($this->merchantId, 0, 8);
        $etagKey  = $cacheKey . '_etag';

        $cached = $this->cacheGet($cacheKey);
        $etag   = (string) ($this->cacheGet($etagKey) ?? '');

        $url     = $this->apiBase . '/v1/rules/snapshot/' . rawurlencode($this->merchantId);
        $headers = [
            'X-Trusteed-Installation-Id' => $this->installationId,
            'X-Trusteed-Signature'       => $this->hmacSign(''),
            'Accept'                     => 'application/jose',
        ];

        if ($etag !== '') {
            $headers['If-None-Match'] = $etag;
        }

        $result = $this->httpGet($url, $headers, 8);

        if ($result['error'] !== '') {
            // Network failure — return cached if available
            return $cached !== null ? (string) $cached : null;
        }

        if ($result['code'] === 304) {
            return $cached !== null ? (string) $cached : null;
        }

        if ($result['code'] !== 200) {
            return $cached !== null ? (string) $cached : null;
        }

        $jws = trim((string) $result['body']);
        if ($jws === '') {
            return $cached !== null ? (string) $cached : null;
        }

        $payload = $this->verifyAndDecode($jws);
        if ($payload === null) {
            return $cached !== null ? (string) $cached : null;
        }

        $ttl = $this->computeTtl($payload);

        // stale-fail-closed (spec-048 P2 / PL-F7): a snapshot whose validUntil
        // is already in the past must NOT be cached or returned as a fresh/
        // valid snapshot. computeTtl() returns <= 0 for an expired payload.
        // Treat it as "no snapshot available" so the evaluator's fallback
        // policy applies. We never cache the expired JWS as fresh (which the
        // old max(60, …) clamp did).
        if ($ttl <= 0) {
            return $cached !== null ? (string) $cached : null;
        }

        $this->cacheSet($cacheKey, $jws, $ttl);
        $this->cacheSet($etagKey, (string) ($payload['ruleSetVersion'] ?? ''), $ttl);

        // Sprint C T13: persist a long-TTL (24h) copy of the verified snapshot
        // so the hook can evaluate rules offline if Layer-2 evaluate fails.
        $this->writeLocalSnapshotCache($jws);

        return $jws;
    }

    /**
     * Sprint C T13: read the 24h-TTL local snapshot cache for this merchant.
     *
     * Returns null when no cache exists or when stale (>24h). Otherwise returns
     * the verified JWS + cache age (seconds) so callers can log structured
     * `[trusteed.snapshot.cache_fallback]` evidence.
     *
     * @return array{jws:string, ageSeconds:int}|null
     */
    public function getLocalSnapshotCache(): ?array
    {
        $cacheKey = self::LOCAL_SNAPSHOT_KEY_PREFIX . $this->merchantId;
        $raw      = $this->cacheGet($cacheKey);

        if (!is_array($raw) || !isset($raw['jws'], $raw['capturedAt'])) {
            return null;
        }

        $jws         = (string) $raw['jws'];
        $capturedAt  = (int) $raw['capturedAt'];
        $ageSeconds  = max(0, time() - $capturedAt);

        if ($jws === '' || $capturedAt <= 0) {
            return null;
        }

        if ($ageSeconds > self::LOCAL_SNAPSHOT_TTL_SECONDS) {
            // Stale by file-age (>24h) — caller falls back to FallbackMode policy.
            return null;
        }

        // stale-fail-closed (spec-048 P2): even within the 24h file-age window,
        // a snapshot whose OWN validUntil has already passed must not be reused
        // as a valid offline snapshot. Otherwise an expired kill-switch / tier-1
        // ruleset would keep being honoured for up to 24h. When validUntil is in
        // the past we treat it as a cache miss so the caller's FallbackMode
        // policy applies (strict → fail-closed for ENFORCE / tier-1 / kill-switch).
        $validUntil = $this->extractSnapshotValidUntil($jws);
        if ($validUntil !== null && $validUntil <= time()) {
            return null;
        }

        return ['jws' => $jws, 'ageSeconds' => $ageSeconds];
    }

    /**
     * Extract the snapshot's internal `validUntil` (unix seconds) from a JWS
     * without re-verifying the signature or hitting JWKS.
     *
     * The JWS stored in the local cache was already signature-verified at write
     * time (pull() only persists verified snapshots), so for the offline
     * staleness check we only need to read the payload's validUntil claim. We
     * deliberately avoid a network JWKS fetch here because this runs on the
     * Layer-2-unavailable path.
     *
     * @return int|null Unix timestamp, or null when absent / unparseable.
     */
    private function extractSnapshotValidUntil(string $jws): ?int
    {
        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            return null;
        }

        $payloadJson = self::base64UrlDecode($parts[1]);
        $payload     = json_decode($payloadJson, true);
        if (!is_array($payload) || empty($payload['validUntil'])) {
            return null;
        }

        $ts = strtotime((string) $payload['validUntil']);

        return $ts === false ? null : $ts;
    }

    /**
     * Write the verified snapshot to the 24h-TTL local cache.
     * Idempotent; overwrites any prior entry for the same merchant.
     */
    private function writeLocalSnapshotCache(string $jws): void
    {
        $cacheKey = self::LOCAL_SNAPSHOT_KEY_PREFIX . $this->merchantId;
        $this->cacheSet(
            $cacheKey,
            ['jws' => $jws, 'capturedAt' => time()],
            self::LOCAL_SNAPSHOT_TTL_SECONDS
        );
    }

    /** Test-only: seed the long-TTL cache directly. */
    public function seedLocalSnapshotCache(string $jws, int $capturedAt): void
    {
        $cacheKey = self::LOCAL_SNAPSHOT_KEY_PREFIX . $this->merchantId;
        // Compute remaining TTL so seeding with an old capturedAt is honoured.
        $remaining = max(1, self::LOCAL_SNAPSHOT_TTL_SECONDS - (time() - $capturedAt));
        $this->cacheSet(
            $cacheKey,
            ['jws' => $jws, 'capturedAt' => $capturedAt],
            $remaining
        );
    }

    /**
     * Verify JWS Ed25519 signature (sodium) and decode payload.
     * JWKS keys cached 1h via APCu / static fallback.
     *
     * @return array|null Decoded payload on success, null if invalid.
     */
    public function verifyAndDecode(string $jws): ?array
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return null;
        }

        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;

        $headerJson = self::base64UrlDecode($headerB64);
        $header     = json_decode($headerJson, true);
        if (!is_array($header) || empty($header['kid'])) {
            return null;
        }
        if (($header['alg'] ?? '') !== 'EdDSA') {
            return null;
        }

        $jwks      = $this->fetchJwks();
        $pubkeyRaw = $jwks[$header['kid']] ?? null;
        if ($pubkeyRaw === null) {
            return null;
        }

        $signingInput = $headerB64 . '.' . $payloadB64;
        $sig          = self::base64UrlDecode($sigB64);

        if (!sodium_crypto_sign_verify_detached($sig, $signingInput, $pubkeyRaw)) {
            return null;
        }

        $payloadJson = self::base64UrlDecode($payloadB64);
        $payload     = json_decode($payloadJson, true);

        return is_array($payload) ? $payload : null;
    }

    /** @return array<string, string> Map of kid => 32-byte raw Ed25519 public key. */
    private function fetchJwks(): array
    {
        $cacheKey = 'trusteed_cel_jwks_' . md5($this->apiBase);
        $cached   = $this->cacheGet($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $url    = $this->apiBase . '/.well-known/jwks.json';
        $result = $this->httpGet($url, ['Accept' => 'application/json'], 5);

        if ($result['error'] !== '' || $result['code'] !== 200) {
            return [];
        }

        $data = json_decode((string) $result['body'], true);
        $keys = [];

        foreach ($data['keys'] ?? [] as $key) {
            if (!is_array($key)) {
                continue;
            }
            if (($key['kty'] ?? '') !== 'OKP' || ($key['crv'] ?? '') !== 'Ed25519') {
                continue;
            }
            if (empty($key['kid']) || empty($key['x'])) {
                continue;
            }

            $rawKey = self::base64UrlDecode($key['x']);
            if (strlen($rawKey) === 32) {
                $keys[(string) $key['kid']] = $rawKey;
            }
        }

        $this->cacheSet($cacheKey, $keys, 3600);

        return $keys;
    }

    /**
     * Compute X-Trusteed-Signature: t={timestamp},s={hex_mac}  (Stripe-style)
     *
     * IMPORTANT: The field name is "s=" not "v1=". The server parser
     * (SIG_RE = /^s=([0-9a-f]+)$/) will reject "v1=" tokens.
     * See: apps/api/src/services/enforcement/installation-hmac.service.ts
     */
    private function hmacSign(string $body): string
    {
        $ts     = time();
        $toSign = $ts . '.' . $body;
        $mac    = hash_hmac('sha256', $toSign, $this->hmacSecret);

        return "t={$ts},s={$mac}";
    }

    /**
     * Compute cache TTL from payload validUntil.
     *
     * For a still-valid snapshot the TTL is clamped to a maximum of 300
     * seconds so the cache lifetime never exceeds the snapshot's own
     * validUntil window.
     *
     * IMPORTANT (stale-fail-closed, spec-048 P2 / PL-F7): if validUntil is
     * already in the past ($until - now <= 0) this returns a non-positive
     * value. The 300s ceiling is ONLY applied when the remaining lifetime is
     * positive — an expired snapshot must never be promoted back to a 60s
     * "fresh" TTL (the old max(60, …) clamp did exactly that, keeping an
     * expired/near-expired kill-switch or tier-1 ruleset cached for up to
     * 60s past when it should have refreshed). Callers MUST treat a <= 0
     * result as "snapshot stale → no fresh snapshot available".
     */
    private function computeTtl(array $payload): int
    {
        if (empty($payload['validUntil'])) {
            return 300;
        }

        $until = strtotime((string) $payload['validUntil']);
        if ($until === false) {
            return 300;
        }

        $ttl = $until - time();

        // Expired snapshot: surface the non-positive remaining lifetime so
        // the caller routes it into the stale-fail-closed fallback. Do NOT
        // clamp to 60s.
        if ($ttl <= 0) {
            return $ttl;
        }

        return min(300, $ttl);
    }

    /** @return array{code: int, body: string, error: string} */
    private function httpGet(string $url, array $headers, int $timeout = 8): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => array_map(
                static fn($k, $v) => "{$k}: {$v}",
                array_keys($headers),
                array_values($headers)
            ),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = (string) curl_error($ch);
        curl_close($ch);

        return ['code' => $code, 'body' => is_string($body) ? $body : '', 'error' => $err];
    }

    private function cacheGet(string $key): mixed
    {
        // B5: prefer persistent file cache; APCu used only when available;
        // static fallback preserved for unit tests that cannot write to disk.
        $fc = self::fileCache();
        if ($fc !== null) {
            $val = $fc->get($key);
            if ($val !== null) {
                return $val;
            }
        }

        if (function_exists('apcu_fetch')) {
            $success = false;
            $val     = apcu_fetch($key, $success);

            return $success ? $val : null;
        }

        return self::$memCache[$key] ?? null;
    }

    private function cacheSet(string $key, mixed $value, int $ttl): void
    {
        $fc = self::fileCache();
        if ($fc !== null) {
            $fc->set($key, $value, $ttl);
            return;
        }

        if (function_exists('apcu_store')) {
            apcu_store($key, $value, $ttl);

            return;
        }

        self::$memCache[$key] = $value;
    }

    private static function fileCache(): ?FileCache
    {
        static $instance = null;
        if ($instance !== null) {
            return $instance;
        }
        if (!defined('_PS_CACHE_DIR_')) {
            return null;
        }
        $instance = new FileCache(rtrim((string) constant('_PS_CACHE_DIR_'), '/') . '/trusteed_snapshot');
        return $instance;
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Perform a synchronous HTTP POST via cURL.
     *
     * @return array{code:int,body:string,error:string}
     */
    protected function httpPost(string $url, array $headers, string $body, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['code' => 0, 'body' => '', 'error' => 'curl_init failed'];
        }

        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = "{$k}: {$v}";
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $responseBody = curl_exec($ch);
        $code         = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error        = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            return ['code' => 0, 'body' => '', 'error' => $error ?: 'curl_exec failed'];
        }

        return ['code' => $code, 'body' => (string) $responseBody, 'error' => ''];
    }

    /**
     * Call Capa-2 proxy: POST /v1/rules/evaluate for R003-R010 historical rules.
     *
     * Returns null on network failure (caller applies fallbackMode).
     *
     * @param string|null $agentId Null for an organic (non-agentic) checkout —
     *                              App Store remediation 2026-07-11. The shared
     *                              evaluator's ruleCode-identity `appliesTo`
     *                              partition safely excludes AGENT-only rules
     *                              in that case while still evaluating
     *                              universal merchant policy rules.
     * @param int        $cartTotalCents
     * @param string     $currency
     * @param int        $itemCount
     * @param float|null $agentTrustScore 0-100
     * @param array      $cartContext     Enriched cart data extracted locally (best-effort).
     * @return array{decision:string,ruleCode:string,reason:string,evaluationId:string,ucp:array<string,mixed>}|null
     */
    public function callRulesEvaluate(
        ?string $agentId,
        int $cartTotalCents,
        string $currency,
        int $itemCount,
        ?float $agentTrustScore,
        array $cartContext = []
    ): ?array {
        $orderContext = [
            'cartTotalCents'  => $cartTotalCents,
            'currency'        => $currency,
            'itemCount'       => $itemCount,
            'agentTrustScore' => $agentTrustScore,
        ];

        if (!empty($cartContext['billingCountry'])) {
            $orderContext['billingCountry'] = (string) $cartContext['billingCountry'];
        }
        if (!empty($cartContext['shippingCountry'])) {
            $orderContext['shippingCountry'] = (string) $cartContext['shippingCountry'];
        }
        if (!empty($cartContext['paymentMethod'])) {
            $orderContext['paymentMethod'] = (string) $cartContext['paymentMethod'];
        }
        if (!empty($cartContext['lineItems']) && is_array($cartContext['lineItems'])) {
            $orderContext['lineItems'] = $cartContext['lineItems'];
        }
        if (!empty($cartContext['discountCodes']) && is_array($cartContext['discountCodes'])) {
            $orderContext['discountCodes'] = $cartContext['discountCodes'];
        }
        if (!empty($cartContext['cartAttributes']) && is_array($cartContext['cartAttributes'])) {
            $orderContext['cartAttributes'] = $cartContext['cartAttributes'];
        }

        $payload = json_encode([
            'merchantId'    => $this->merchantId,
            'agentId'       => $agentId,
            'orderContext'  => $orderContext,
            'platform'       => 'PRESTASHOP',
            'installationId' => $this->installationId,
            'timestamp'      => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $sig     = $this->hmacSign($payload);
        $url     = rtrim($this->apiBase, '/') . '/v1/rules/evaluate';
        $headers = [
            'Content-Type'               => 'application/json',
            'X-Trusteed-Installation-Id' => $this->installationId,
            'X-Trusteed-Signature'       => $sig,
        ];

        $result = $this->httpPost($url, $headers, $payload, 5);
        if ($result['error'] !== '' || $result['code'] !== 200) {
            return null;
        }

        $body = json_decode($result['body'], true);
        if (!is_array($body) || !isset($body['decision'])) {
            return null;
        }

        // H2: preserve the `ucp` envelope so R043HitlGate can distinguish a
        // HITL outcome (decision=BLOCK + ucp.state=requires_escalation +
        // ucp.reason_code=trusteed:R043…) from a plain rule block.
        return [
            'decision'     => (string) $body['decision'],
            'ruleCode'     => (string) ($body['ruleCode'] ?? ''),
            'reason'       => (string) ($body['reason'] ?? ''),
            'evaluationId' => (string) ($body['evaluationId'] ?? ''),
            'ucp'          => is_array($body['ucp'] ?? null) ? $body['ucp'] : [],
        ];
    }

    /**
     * Call POST /v1/agent-events/nonce-consume to record a single-use
     * agent-token jti (spec-048 P2.8 replay protection).
     *
     * Mirrors WC class-enforcement-api-client.php::consume_nonce(). Reuses the
     * Stripe-style HMAC signing helper already used by callRulesEvaluate().
     *
     * @param string $agentDid Verified agent DID from token iss/kid claim.
     * @param string $jti      Single-use nonce — base64url 16–128 chars.
     * @param int    $exp      Token exp (unix seconds). Used as TTL upper bound.
     * @return array{outcome:string,reason:string,httpStatus:int|null}
     */
    public function consumeNonce(string $agentDid, string $jti, int $exp): array
    {
        $payload = json_encode([
            'merchantId'     => $this->merchantId,
            'installationId' => $this->installationId,
            'agentId'        => $agentDid,
            'nonce'          => $jti,
            'expiresAt'      => gmdate(
                'Y-m-d\TH:i:s\Z',
                $exp > 0 ? $exp : (time() + 300)
            ),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $sig     = $this->hmacSign($payload);
        $url     = $this->apiBase . '/v1/agent-events/nonce-consume';
        $headers = [
            'Content-Type'               => 'application/json',
            'X-Trusteed-Installation-Id' => $this->installationId,
            'X-Trusteed-Signature'       => $sig,
        ];

        $result = $this->httpPost($url, $headers, $payload, 5);

        if ($result['error'] !== '') {
            return ['outcome' => NonceOutcome::INDETERMINATE, 'reason' => 'network_error', 'httpStatus' => null];
        }

        $status = (int) $result['code'];
        if ($status === 200) {
            return ['outcome' => NonceOutcome::ACCEPTED, 'reason' => 'ok', 'httpStatus' => $status];
        }
        if ($status === 409) {
            return ['outcome' => NonceOutcome::REPLAY, 'reason' => 'replay_detected', 'httpStatus' => $status];
        }
        if ($status >= 500) {
            return ['outcome' => NonceOutcome::INDETERMINATE, 'reason' => 'http_5xx', 'httpStatus' => $status];
        }
        return ['outcome' => NonceOutcome::INDETERMINATE, 'reason' => 'http_4xx', 'httpStatus' => $status];
    }
}
