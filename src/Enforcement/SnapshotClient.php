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
 * Outcome of consumeNonce(): single-use replay protection.
 *
 *   ACCEPTED       : HTTP 200, nonce stored, token may be used.
 *   REPLAY         : HTTP 409, nonce already seen, caller must mark token INVALID.
 *   INDETERMINATE  : network / 5xx / 4xx / bad response, caller maps per failure_mode.
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
     * Long-TTL local snapshot cache (24h) used to evaluate rules
     * offline when the Layer-2 rules-evaluate endpoint is unavailable.
     * Distinct key namespace from the short-TTL pull cache (5min) so it
     * survives normal cache rotation.
     */
    public const LOCAL_SNAPSHOT_TTL_SECONDS = 86400;

    /** Read cap for a response (a legitimate compact JWS is at most about 4 MiB). */
    public const MAX_RESPONSE_BYTES = 4194304;
    private const LOCAL_SNAPSHOT_KEY_PREFIX = 'trusteed_snapshot_';

    /**
     * Own connect timeout, shorter than the total, so an unresponsive layer 2
     * does not hold every checkout for 5 s.
     */
    public const CONNECT_TIMEOUT_SECONDS = 2;

    /**
     * After a `callRulesEvaluate` failure, layer 2 is considered down for this
     * window (a simple circuit breaker): no retry on every checkout and no
     * repeated `fallback_open` warning in `ps_log`.
     */
    public const LAYER2_FAILURE_MEMORY_SECONDS = 60;

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
     * Reason reported by {@see pullResult()}. ValidateOrderHook maps it to the
     * cause used by FailurePolicy::decideOnDegradedEnforcement():
     * `tampered` becomes invalid_signature, `unavailable` becomes missing_snapshot.
     */
    public const PULL_OK          = 'ok';
    public const PULL_TAMPERED    = 'tampered';
    public const PULL_UNAVAILABLE = 'unavailable';

    /**
     * Pull snapshot JWS with conditional GET. Verified JWS or null.
     * Wrapper around {@see pullResult()} (which says WHY there is no snapshot).
     *
     * @return string|null JWS Compact string on success, null on failure.
     */
    public function pull(): ?string
    {
        return $this->pullResult()['jws'];
    }

    /**
     * Downloads and verifies the snapshot, saying why there is none:
     *
     * - `ok`: verified JWS (freshly served or from our 5 min cache).
     * - `tampered`: the JWS the server JUST served does not verify even after
     *   fetching the JWKS once more (key rotated while the 1 h cache is still
     *   alive). It is never cached, and the cache is not served instead: it is
     *   a tampering signal, not an outage.
     * - `unavailable`: no usable snapshot (network, HTTP other than 200, empty
     *   body, expired snapshot, unreachable JWKS so we cannot judge the
     *   signature) and the cache, if any, does not verify. A copy of OUR cache
     *   that no longer verifies is rotation or a down JWKS, not tampering.
     *
     * @return array{status:string,jws:?string,payload:?array}
     */
    public function pullResult(): array
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

        // Network error, 304, HTTP other than 200 or empty body: use the cache if it verifies.
        if ($result['error'] !== '' || $result['code'] !== 200) {
            return $this->cachedPullResult($cached);
        }

        // Over the cap it is neither verified nor read further: unavailable.
        if (strlen((string) $result['body']) > self::MAX_RESPONSE_BYTES) {
            return $this->cachedPullResult($cached);
        }

        $jws = trim((string) $result['body']);
        if ($jws === '') {
            return $this->cachedPullResult($cached);
        }

        [$status, $payload] = $this->verifyResult($jws);
        if ($status === self::PULL_TAMPERED) {
            return ['status' => self::PULL_TAMPERED, 'jws' => null, 'payload' => null];
        }
        if ($status !== self::PULL_OK || $payload === null) {
            return $this->cachedPullResult($cached);
        }

        $ttl = $this->computeTtl($payload);

        // Stale fail-closed: a snapshot whose validUntil is already in the past
        // must NOT be cached or returned as a fresh/valid snapshot. computeTtl()
        // returns <= 0 for an expired payload. Treat it as "no snapshot
        // available" so the evaluator's fallback policy applies. The expired JWS
        // is never cached as fresh.
        if ($ttl <= 0) {
            return $this->cachedPullResult($cached);
        }

        $this->cacheSet($cacheKey, $jws, $ttl);
        $this->cacheSet($etagKey, (string) ($payload['ruleSetVersion'] ?? ''), $ttl);

        // Persist a long-TTL (24h) copy of the verified snapshot
        // so the hook can evaluate rules offline if Layer-2 evaluate fails.
        $this->writeLocalSnapshotCache($jws);

        return ['status' => self::PULL_OK, 'jws' => $jws, 'payload' => $payload];
    }

    /**
     * The copy from our 5 min cache, re-verified. If it does not verify it is
     * `unavailable`, never `tampered` (we wrote it ourselves after verifying).
     *
     * @param mixed $cached
     * @return array{status:string,jws:?string,payload:?array}
     */
    private function cachedPullResult($cached): array
    {
        if (!is_string($cached) || $cached === '') {
            return ['status' => self::PULL_UNAVAILABLE, 'jws' => null, 'payload' => null];
        }
        [$status, $payload] = $this->verifyResult($cached);
        // The 5 min cache lives as long as the store TTL; even so, a copy whose
        // own `validUntil` has passed is not a usable snapshot.
        return $status === self::PULL_OK && $payload !== null && SnapshotPayloadDecoder::isUsable($payload, time())
            ? ['status' => self::PULL_OK, 'jws' => $cached, 'payload' => $payload]
            : ['status' => self::PULL_UNAVAILABLE, 'jws' => null, 'payload' => null];
    }

    /**
     * Read the 24h-TTL local snapshot cache for this merchant.
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
            // Stale by file-age (>24h): caller falls back to FallbackMode policy.
            return null;
        }

        // Stale fail-closed: even within the 24h file-age window,
        // a snapshot whose OWN validUntil has already passed must not be reused
        // as a valid offline snapshot. Otherwise an expired kill-switch / tier-1
        // ruleset would keep being honoured for up to 24h. When validUntil is in
        // the past we treat it as a cache miss so the caller's FallbackMode
        // policy applies (strict means fail-closed for ENFORCE / tier-1 / kill-switch).
        $validUntil = $this->extractSnapshotValidUntil($jws);
        // A missing or unreadable `validUntil` is a malformed payload, like an expired one.
        if ($validUntil === null || $validUntil <= time()) {
            return null;
        }

        return ['jws' => $jws, 'ageSeconds' => $ageSeconds];
    }

    /** `validUntil` of a JWS already verified when cached (no network: works with layer 2 down). */
    private function extractSnapshotValidUntil(string $jws): ?int
    {
        $payload = SnapshotPayloadDecoder::payloadOfCachedVerifiedJws($jws);

        return is_array($payload) ? SnapshotPayloadDecoder::validUntil($payload) : null;
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
        [$status, $payload] = $this->verifyResult($jws);

        return $status === self::PULL_OK ? $payload : null;
    }

    /**
     * Verifies the JWS, saying why it does not verify:
     *
     * - Invalid shape (not compact, unreadable header, `alg` other than EdDSA,
     *   no `kid`, payload that is not a JSON object): `tampered`.
     * - Signature: against the cached JWKS (1 h); if the `kid` is missing or
     *   does not match, the JWKS is DOWNLOADED once more and the check is
     *   retried. If there is still no key after that retry (unreachable JWKS):
     *   `unavailable`, since the signature cannot be judged. With keys that do
     *   not verify: `tampered`.
     * - Without sodium nothing can be verified: `unavailable`.
     * - Valid signature with `zip` in the header: it is inflated with a cap
     *   ({@see SnapshotPayloadDecoder}); if it cannot be read (zip other than
     *   DEF, bomb, broken stream): `unavailable`, not `tampered`.
     *
     * @return array{0:string,1:?array} [PULL_*, payload or null]
     */
    public function verifyResult(string $jws): array
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return [self::PULL_UNAVAILABLE, null];
        }

        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            return [self::PULL_TAMPERED, null];
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;

        $headerJson = self::base64UrlDecode($headerB64);
        $header     = json_decode($headerJson, true);
        if (!is_array($header) || empty($header['kid']) || !is_string($header['kid'])) {
            return [self::PULL_TAMPERED, null];
        }
        if (($header['alg'] ?? '') !== 'EdDSA') {
            return [self::PULL_TAMPERED, null];
        }
        // RFC 7515 4.1.11: `crit` requires understanding extensions that we do
        // not understand and the signer does not emit.
        if (array_key_exists('crit', $header)) {
            return [self::PULL_TAMPERED, null];
        }

        $kid          = $header['kid'];
        $signingInput = $headerB64 . '.' . $payloadB64;
        $sig          = self::base64UrlDecode($sigB64);

        $verdicts   = $this->jwksRetryVerdicts();
        $negative   = $verdicts->read($kid);
        $cachedKeys = JwksCacheCodec::decode($this->cacheGet('trusteed_cel_jwks_' . md5($this->apiBase)));
        // JWKS unreachable less than a minute ago and no cached copy: do not
        // download again on every checkout.
        if ($cachedKeys === null && $negative === self::PULL_UNAVAILABLE) {
            return [self::PULL_UNAVAILABLE, null];
        }
        $keys = $cachedKeys ?? $this->fetchJwks();

        if (!self::ed25519Verifies($keys[$kid] ?? null, $sig, $signingInput)) {
            // Already retried less than a minute ago with this `kid` and it did
            // not verify: classify with what is known, without another download.
            if ($negative !== null) {
                return [$negative, null];
            }
            // Single retry: key rotation while the JWKS cache is still alive.
            $fresh = $this->fetchJwks(true);
            if ($fresh === []) {
                $verdicts->remember($kid, self::PULL_UNAVAILABLE);
                return [self::PULL_UNAVAILABLE, null];
            }
            if (!self::ed25519Verifies($fresh[$kid] ?? null, $sig, $signingInput)) {
                $verdicts->remember($kid, self::PULL_TAMPERED);
                return [self::PULL_TAMPERED, null];
            }
        }

        // Only after the signature verifies: inflate (if `zip`) with a cap and read.
        [$decoded, $payload] = SnapshotPayloadDecoder::decode($header, self::base64UrlDecode($payloadB64));
        if ($decoded === SnapshotPayloadDecoder::OK) {
            return [self::PULL_OK, $payload];
        }

        // With `zip` the platform signature verified but we cannot read it
        // (unknown zip, bomb, broken stream): not usable, not tampering.
        return SnapshotPayloadDecoder::declaresZip($header)
            ? [self::PULL_UNAVAILABLE, null]
            : [self::PULL_TAMPERED, null];
    }

    /** Ed25519 check that never throws: a signature of odd length simply does not verify. */
    private static function ed25519Verifies(?string $pubkeyRaw, string $sig, string $signingInput): bool
    {
        if ($pubkeyRaw === null || strlen($pubkeyRaw) !== 32 || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached($sig, $signingInput, $pubkeyRaw);
        } catch (\SodiumException $e) {
            return false;
        }
    }

    /** Clock for the JWKS negative cache (protected: tests pin it). */
    protected function now(): int
    {
        return time();
    }

    /** @param array<string,mixed> $entry Protected: test seam for the write failure. */
    protected function persistJwksRetryVerdict(array $entry): void
    {
        $this->cacheSet('trusteed_cel_jwks_retry_' . md5($this->apiBase), $entry, JwksRetryVerdict::TTL_SECONDS);
    }

    private function jwksRetryVerdicts(): JwksRetryVerdict
    {
        return new JwksRetryVerdict(
            fn (): mixed => $this->cacheGet('trusteed_cel_jwks_retry_' . md5($this->apiBase)),
            fn (array $entry) => $this->persistJwksRetryVerdict($entry),
            fn (): int => $this->now()
        );
    }

    /**
     * @param bool $forceRefresh Ignores the 1 h cache (retry after rotation).
     * @return array<string, string> Map of kid => 32-byte raw Ed25519 public key.
     */
    private function fetchJwks(bool $forceRefresh = false): array
    {
        $cacheKey = 'trusteed_cel_jwks_' . md5($this->apiBase);
        $cached   = $forceRefresh ? null : JwksCacheCodec::decode($this->cacheGet($cacheKey));
        if ($cached !== null) {
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

        // Raw bytes as base64 (json_encode rejects them) and never an empty list.
        if (JwksCacheCodec::isCacheable($keys)) {
            $this->cacheSet($cacheKey, JwksCacheCodec::encode($keys), 3600);
        }

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
     * IMPORTANT (stale fail-closed): if validUntil is
     * already in the past ($until - now <= 0) this returns a non-positive
     * value. The 300s ceiling is ONLY applied when the remaining lifetime is
     * positive: an expired snapshot must never be promoted back to a 60s
     * "fresh" TTL (an old max(60, ...) clamp did exactly that, keeping an
     * expired/near-expired kill-switch or tier-1 ruleset cached for up to
     * 60s past when it should have refreshed). Callers MUST treat a <= 0
     * result as "snapshot stale, so no fresh snapshot available".
     */
    private function computeTtl(array $payload): int
    {
        // A snapshot signed by our server always carries `validUntil`: missing
        // or unreadable means malformed, which is "unavailable" just like an
        // expired one (non-positive TTL). The signature verified, so it is not
        // tampering.
        $until = SnapshotPayloadDecoder::validUntil($payload);
        if ($until === null) {
            return 0;
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

    /**
     * Protected (like httpPost) so tests replace ONLY the network edge and
     * exercise the real pull/verification.
     *
     * @return array{code: int, body: string, error: string}
     */
    protected function httpGet(string $url, array $headers, int $timeout = 8): array
    {
        return BoundedHttpGet::fetch($url, $headers, $timeout, self::MAX_RESPONSE_BYTES);
    }

    private function cacheGet(string $key): mixed
    {
        // Prefer persistent file cache; APCu used only when available;
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
            CURLOPT_CONNECTTIMEOUT => min(self::CONNECT_TIMEOUT_SECONDS, max(1, $timeout)),
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

    private function layer2DownKey(): string
    {
        return 'trusteed_cel_l2_down_' . substr(md5($this->merchantId . '|' . $this->apiBase), 0, 16);
    }

    /**
     * Is it this request's turn to write the `fallback_open` warning? Only once
     * per failure window, so a layer 2 outage does not flood `ps_log`.
     */
    public function claimFallbackLogSlot(): bool
    {
        $key = 'trusteed_cel_l2_fallback_logged_' . substr(md5($this->merchantId . '|' . $this->apiBase), 0, 16);
        if ($this->cacheGet($key) !== null) {
            return false;
        }
        $this->cacheSet($key, 1, self::LAYER2_FAILURE_MEMORY_SECONDS);

        return true;
    }

    /**
     * Call the layer 2 proxy: POST /v1/rules/evaluate for R003-R010 historical rules.
     *
     * Returns null on network failure (caller applies fallbackMode).
     *
     * @param string|null $agentId Null for an organic (non-agentic) checkout.
     *                              The shared
     *                              evaluator's ruleCode-identity `appliesTo`
     *                              partition safely excludes AGENT-only rules
     *                              in that case while still evaluating
     *                              universal merchant policy rules.
     * @param int        $cartTotalCents
     * @param string     $currency
     * @param int        $itemCount
     * @param float|null $agentTrustScore 0-100
     * @param array      $cartContext     Enriched cart data extracted locally (best-effort).
     * @param bool       $isAgentic       Checkout with an agentic signal (token
     *                                    presented, verified or not). Only then is
     *                                    `endpointClass: agentic_endpoint` added;
     *                                    the organic body is unchanged byte for byte.
     * @return array{decision:string,ruleCode:string,reason:string,evaluationId:string,ucp:array<string,mixed>}|null
     */
    public function callRulesEvaluate(
        ?string $agentId,
        int $cartTotalCents,
        string $currency,
        int $itemCount,
        ?float $agentTrustScore,
        array $cartContext = [],
        bool $isAgentic = false
    ): ?array {
        $orderContext = [
            'cartTotalCents'  => $cartTotalCents,
            'currency'        => $currency,
            'itemCount'       => $itemCount,
        ];

        // The server schema (`agentTrustScore: z.number().int().min(0).max(100).optional()`)
        // rejects `null` and decimals with HTTP 400, and layer 2 then silently
        // becomes "unavailable". Without a score the key is omitted; with one, an integer.
        if ($agentTrustScore !== null) {
            $orderContext['agentTrustScore'] = max(0, min(100, (int) round($agentTrustScore)));
        }

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

        $body = [
            'merchantId'    => $this->merchantId,
            'agentId'       => $agentId,
            'orderContext'  => $orderContext,
            'platform'       => 'PRESTASHOP',
            'installationId' => $this->installationId,
            'timestamp'      => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        // Agentic checkouts only: the organic payload does not change. This way
        // the server treats an agent without a verified identity (invalid token)
        // as an anonymous agent (R001/R031) and not as a human.
        $payload = json_encode(
            $isAgentic ? array_merge($body, ['endpointClass' => 'agentic_endpoint']) : $body,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );

        $sig     = $this->hmacSign($payload);
        $url     = rtrim($this->apiBase, '/') . '/v1/rules/evaluate';
        $headers = [
            'Content-Type'               => 'application/json',
            'X-Trusteed-Installation-Id' => $this->installationId,
            'X-Trusteed-Signature'       => $sig,
        ];

        // Circuit breaker: layer 2 was down less than LAYER2_FAILURE_MEMORY_SECONDS ago.
        if ($this->cacheGet($this->layer2DownKey()) !== null) {
            return null;
        }

        $result = $this->httpPost($url, $headers, $payload, 5);
        if ($result['error'] !== '' || $result['code'] !== 200) {
            $this->cacheSet($this->layer2DownKey(), 1, self::LAYER2_FAILURE_MEMORY_SECONDS);
            return null;
        }

        $body = json_decode($result['body'], true);
        if (!is_array($body) || !isset($body['decision'])) {
            $this->cacheSet($this->layer2DownKey(), 1, self::LAYER2_FAILURE_MEMORY_SECONDS);
            return null;
        }

        // Preserve the `ucp` envelope so R043HitlGate can distinguish a
        // HITL outcome (decision=BLOCK + ucp.state=requires_escalation +
        // ucp.reason_code=trusteed:R043...) from a plain rule block.
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
     * agent-token jti (replay protection).
     *
     * Mirrors the WooCommerce plugin's consume_nonce(). Reuses the
     * Stripe-style HMAC signing helper already used by callRulesEvaluate().
     *
     * @param string $agentDid Verified agent DID from token iss/kid claim.
     * @param string $jti      Single-use nonce, base64url 16 to 128 chars.
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
