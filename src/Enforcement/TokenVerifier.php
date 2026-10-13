<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

use Trusteed\Storage\FileCache;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Trusteed CEL Agent Token Verifier for PrestaShop.
 *
 * Verifies the _trusteed_agent_token JWS Compact offline against the rule
 * snapshot's agentDidResolver. Validates checkoutIntentHash binding and
 * performs best-effort nonce deduplication via APCu (or static array fallback).
 *
 * Requires: sodium PHP extension.
 *
 * @package Trusteed\Enforcement
 */
class TokenVerifier
{
    /**
     * Maximum token age (seconds from `iat` until now).
     *
     * The SAME number in every verifier: the 300 s the contract declares
     * (`AgentTokenPayloadSchema`: `exp - iat <= 300`). PrestaShop used to have no
     * age cap (only the lifetime cap `exp - iat`, which does not cover a token
     * with a renewed `exp` and an old `iat`). Kept identical across the
     * platform verifiers.
     */
    private const MAX_AGE_SECONDS = 300;

    /** @var array<mixed> List `[{did, publicKeyJwk}]` from the server or legacy map `{did: jwk}`. */
    private array $agentDidResolver;

    private string $merchantId;

    /** Static in-process nonce cache when APCu unavailable */
    private static array $nonceCache = [];

    /**
     * @param array<mixed> $agentDidResolver The snapshot `agentDidResolver` (list or map; {@see resolveAgentDidPublicKey()}).
     * @param string                           $merchantId       Merchant ID to validate aud binding.
     */
    public function __construct(array $agentDidResolver, string $merchantId)
    {
        $this->agentDidResolver = $agentDidResolver;
        $this->merchantId       = $merchantId;
    }

    /**
     * Verify the _trusteed_agent_token JWS Compact.
     *
     * Validates:
     *  - EdDSA signature against snapshot agentDidResolver
     *  - Required header: alg=EdDSA, typ=trusteed-agent-token+jwt
     *  - Claims: aud="trusteed", merchantId binding, exp (30s grace), iat max age 300s (MAX_AGE_SECONDS)
     *  - checkoutIntentHash binding
     *  - Nonce uniqueness (best-effort via APCu, 5min TTL)
     *
     * @param  string $jws                JWS Compact Serialization.
     * @param  string $checkoutIntentHash SHA-256 hex of canonical cart JSON.
     * @return array{agentId: string, trustScore: float|null, platform: string|null, kid: string, iat: int, scope: string, returnPolicyAccepted: string, jti: string, exp: int, jtiError: string}|null
     *         Verified claims on success, null if invalid. Includes jti/exp for
     *         backend nonce-consume replay protection. The `jti`
     *         key holds the token's single-use identifier: the canonical `nonce`
     *         claim, or the legacy `jti` claim as an alias when `nonce` is absent.
     *         When both are missing or malformed (16 to 64 base64url chars),
     *         returns null and the caller (ValidateOrderHook) treats the token
     *         as invalid.
     */
    public const REPLAY_ID_RE = '/^[A-Za-z0-9_-]{16,64}$/';

    /**
     * Single-use identifier of a (signature-verified) payload. The canonical
     * contract is `nonce` (AgentTokenPayloadSchema; the server keys its
     * anti-replay store on it). `jti` is only a legacy alias, read when `nonce`
     * is absent or empty; with both present `nonce` wins; a malformed `nonce`
     * is not rescued by a valid `jti`.
     *
     * @param  array<string,mixed> $payload
     * @return string '' when missing or malformed
     */
    public static function replayIdentifier(array $payload): string
    {
        $raw = $payload['nonce'] ?? null;
        if ($raw === null || $raw === '') {
            $raw = $payload['jti'] ?? null;
        }
        if (!is_string($raw) || preg_match(self::REPLAY_ID_RE, $raw) !== 1) {
            return '';
        }
        return $raw;
    }

    public function verify(string $jws, string $checkoutIntentHash): ?array
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return null;
        }

        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;

        $headerJson = SnapshotClient::base64UrlDecode($headerB64);
        $header     = json_decode($headerJson, true);
        if (!is_array($header)) {
            return null;
        }

        if (($header['alg'] ?? '') !== 'EdDSA') {
            return null;
        }
        if (($header['typ'] ?? '') !== 'trusteed-agent-token+jwt') {
            return null;
        }
        // RFC 7515 4.1.11: `crit` lists extensions the receiver MUST understand.
        // This verifier understands none and the agent token signer never emits
        // `crit`: if present (even `[]`, which the RFC declares invalid), reject.
        // Same rule as the snapshot verifiers and the WooCommerce token verifier.
        if (array_key_exists('crit', $header)) {
            return null;
        }

        // Extract DID from kid, strip #fragment if present
        $kid      = (string) ($header['kid'] ?? '');
        $agentDid = strstr($kid, '#', true);
        if ($agentDid === false) {
            $agentDid = $kid;
        }
        if ($agentDid === '') {
            return null;
        }

        // Resolve public key from snapshot agentDidResolver (the server's real
        // list or the legacy map).
        $jwk = self::resolveAgentDidPublicKey($this->agentDidResolver, $agentDid);
        if ($jwk === null) {
            return null;
        }
        // A key that declares another type or curve is not Ed25519: not used.
        if ((isset($jwk['kty']) && $jwk['kty'] !== 'OKP') || (isset($jwk['crv']) && $jwk['crv'] !== 'Ed25519')) {
            return null;
        }

        $xCoord    = $jwk['x'];
        $pubkeyRaw = SnapshotClient::base64UrlDecode($xCoord);
        if (strlen($pubkeyRaw) !== 32) {
            return null;
        }

        // Verify Ed25519 signature.
        //
        // sodium_crypto_sign_verify_detached() requires $sig to be exactly SODIUM_CRYPTO_SIGN_BYTES (64) bytes and
        // THROWS SodiumException for any other length instead of returning
        // false. $sig decodes from an unauthenticated request parameter, so a
        // malformed length must be rejected as an ordinary invalid token
        // (null), the same way every other malformed-input branch in this
        // method already behaves, never let it escape as an exception, which
        // callers upstream treat as an unexpected infrastructure failure
        // rather than "this token is bad".
        $signingInput = $headerB64 . '.' . $payloadB64;
        $sig          = SnapshotClient::base64UrlDecode($sigB64);

        if (strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }

        try {
            if (!sodium_crypto_sign_verify_detached($sig, $signingInput, $pubkeyRaw)) {
                return null;
            }
        } catch (\SodiumException $e) {
            // Defense in depth: the length check above should make this
            // unreachable, but sodium's contract is "throws on malformed
            // input" broadly, not just on length. Never a system failure.
            return null;
        }

        // Decode and validate payload
        $payloadJson = SnapshotClient::base64UrlDecode($payloadB64);
        $payload     = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }

        // Validate iss matches kid-derived DID to prevent key-confusion
        // attacks where an attacker uses their own registered key (kid=attacker#k1)
        // but sets iss=victim-agent. The signature would verify (attacker key is valid)
        // but the returned agentId would be the victim's DID, bypassing the allowlist.
        $iss = (string) ($payload['iss'] ?? '');
        if ($iss === '' || $iss !== $agentDid) {
            return null; // iss/kid mismatch: potential key confusion attack
        }

        if (($payload['aud'] ?? '') !== 'trusteed') {
            return null;
        }
        if (($payload['merchantId'] ?? '') !== $this->merchantId) {
            return null;
        }

        // `exp`, `iat` and `nonce` are MANDATORY. The canonical schema requires
        // them (`AgentTokenPayloadSchema`:
        // positive integer `iat`/`exp` and a 16..64 character `nonce`), and the
        // WooCommerce verifier already did. Here each protection below (expiry,
        // 330s lifetime cap, anti-replay) hung off an `isset`, so a token that
        // simply OMITTED the claim skipped the check: without `exp` it was valid
        // forever, and without `nonce` nothing was deduplicated. Fail closed.
        if (!isset($payload['exp']) || !is_numeric($payload['exp'])) {
            return null;
        }
        if (!isset($payload['iat']) || !is_numeric($payload['iat'])) {
            return null;
        }
        // The single-use identifier is `nonce` (canonical) or, as a legacy alias,
        // `jti`; both 16..64 base64url characters. It comes from the payload
        // that was ALREADY verified (the signature was checked above).
        $nonceClaim = self::replayIdentifier($payload);
        if ($nonceClaim === '') {
            return null;
        }

        // Validate expiry: allow 30s clock skew grace
        $now = time();
        if (((int) $payload['exp'] + 30) < $now) {
            return null;
        }

        // Future `iat`: combined with the 330s cap it would give a sliding window
        // (iat=+1h means the token lives 1h+330s of wall clock even if
        // exp-iat <= 330s). Same guard as the WooCommerce verifier.
        if (((int) $payload['iat'] - 30) > $now) {
            return null;
        }

        // Validate token lifetime: max 5min (300s) + 30s grace = 330s
        if (((int) $payload['exp'] - (int) $payload['iat']) > 330) {
            return null;
        }

        // Age since `iat`: the same cap as WooCommerce, Magento and Odoo. Without
        // it, an `exp` inside the lifetime window did not bound how long ago the
        // token was issued.
        if ((time() - (int) $payload['iat']) > self::MAX_AGE_SECONDS) {
            return null;
        }

        // Validate cart hash binding
        if (($payload['checkoutIntentHash'] ?? '') !== $checkoutIntentHash) {
            return null;
        }

        // Nonce deduplication (offline, best-effort). The claim was already
        // validated as mandatory above, so only uniqueness is left to check.
        if (!$this->isNonceNew($nonceClaim, $agentDid)) {
            return null;
        }

        // iss has been verified above to equal $agentDid, use $iss directly.
        return [
            'agentId'              => $iss,
            'trustScore'           => isset($payload['agentTrustScore']) ? (float) $payload['agentTrustScore'] : null,
            'platform'             => isset($payload['platform']) ? (string) $payload['platform'] : null,
            'kid'                  => $kid,
            'iat'                  => isset($payload['iat']) ? (int) $payload['iat'] : 0,
            'scope'                => isset($payload['scope']) ? (string) $payload['scope'] : '',
            'returnPolicyAccepted' => isset($payload['return_policy']) ? (string) $payload['return_policy'] : '',
            'jti'                  => $nonceClaim,
            'exp'                  => isset($payload['exp']) ? (int) $payload['exp'] : 0,
        ];
    }

    /**
     * Port of `resolveAgentDidPublicKey` (platform-flow.ts; pinned by the
     * `snapshotResolverShape` section of the fixture).
     *
     * The server emits a LIST `[{did, publicKeyJwk:{kty,crv,x,kid?}}]`
     * (`RuleSnapshotPayloadSchema`); this module used to read only the legacy
     * MAP `{did: jwk}`, so no legitimate token verified. It accepts both:
     *
     * - List: the LAST well-formed entry with an exact `did` wins.
     * - Map: only the exact key.
     * - Malformed entries (not an object, non-string `did`, non-object
     *   `publicKeyJwk`, missing/empty/non-string `x`) are ignored without
     *   throwing; any other `$resolver` (null, string, number) resolves nothing.
     *
     * It only resolves: {@see verify()} checks `kty`/`crv` and the signature.
     *
     * @param mixed $resolver The snapshot `agentDidResolver` as is.
     * @return array{x:string,kty?:string,crv?:string,kid?:string}|null
     */
    public static function resolveAgentDidPublicKey($resolver, string $did): ?array
    {
        if ($did === '' || !is_array($resolver)) {
            return null;
        }
        if ($resolver !== [] && array_is_list($resolver)) {
            $found = null;
            foreach ($resolver as $entry) {
                if (!self::isJsonObject($entry) || !isset($entry['did']) || $entry['did'] !== $did) {
                    continue;
                }
                $found = self::toPublicKey($entry['publicKeyJwk'] ?? null) ?? $found;
            }
            return $found;
        }
        return array_key_exists($did, $resolver) ? self::toPublicKey($resolver[$did]) : null;
    }

    /** A decoded JSON object: an associative array (or empty), never a list. */
    private static function isJsonObject($value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /** @return array{x:string,kty?:string,crv?:string,kid?:string}|null */
    private static function toPublicKey($jwk): ?array
    {
        if (!self::isJsonObject($jwk) || !isset($jwk['x']) || !is_string($jwk['x']) || $jwk['x'] === '') {
            return null;
        }
        $key = ['x' => $jwk['x']];
        foreach (['kty', 'crv', 'kid'] as $field) {
            if (isset($jwk[$field]) && is_string($jwk[$field])) {
                $key[$field] = $jwk[$field];
            }
        }
        return $key;
    }

    /**
     * Check nonce uniqueness via APCu (best-effort offline deduplication).
     *
     * The nonce key is derived from SHA-256(agentDid + nonce) so it is not
     * reversible. TTL matches the maximum allowed token expiry (5min).
     */
    private function isNonceNew(string $nonce, string $agentDid): bool
    {
        $key = 'trusteed_cel_nonce_' . hash('sha256', $agentDid . $nonce);

        // Persistent file-cache for cross-request nonce dedup so plain
        // PrestaShop installs without APCu also defend against replays.
        $fc = self::fileCache();
        if ($fc !== null) {
            return $fc->addIfAbsent($key, 1, 300); // 5min TTL
        }

        if (function_exists('apcu_fetch')) {
            $success = false;
            apcu_fetch($key, $success);

            if ($success) {
                return false; // Replay detected
            }

            apcu_store($key, 1, 300); // 5min TTL

            return true;
        }

        // Static array fallback (single-process, no cross-request dedup)
        if (isset(self::$nonceCache[$key])) {
            return false;
        }

        self::$nonceCache[$key] = true;

        return true;
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
        $instance = new FileCache(rtrim((string) constant('_PS_CACHE_DIR_'), '/') . '/trusteed_nonces');
        return $instance;
    }

    /**
     * Compute the checkoutIntentHash from a canonical cart JSON string.
     *
     * @param  string $canonicalJson RFC 8785 canonical JSON of the cart.
     * @return string Hex-encoded SHA-256 digest.
     */
    public static function computeIntentHash(string $canonicalJson): string
    {
        return hash('sha256', $canonicalJson);
    }
}
