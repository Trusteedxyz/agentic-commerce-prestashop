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
    /** @var array<string, array{x: string}> DID => JWK with base64url x coordinate */
    private array $agentDidResolver;

    private string $merchantId;

    /** Static in-process nonce cache when APCu unavailable */
    private static array $nonceCache = [];

    /**
     * @param array<string, array{x: string}> $agentDidResolver DID => JWK map from snapshot payload.
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
     *  - Claims: aud="trusteed", merchantId binding, exp (30s grace), iat window (max 5min)
     *  - checkoutIntentHash binding
     *  - Nonce uniqueness (best-effort via APCu, 5min TTL)
     *
     * @param  string $jws                JWS Compact Serialization.
     * @param  string $checkoutIntentHash SHA-256 hex of canonical cart JSON.
     * @return array{agentId: string, trustScore: float|null, platform: string|null, kid: string, iat: int, scope: string, returnPolicyAccepted: string, jti: string, exp: int, jtiError: string}|null
     *         Verified claims on success, null if invalid. Includes jti/exp for
     *         spec-048 P2.8 backend nonce-consume replay protection. When jti
     *         is missing or malformed, returns null and the caller (ValidateOrderHook)
     *         must surface the failure as INVALID via cart attributes.
     */
    public const JTI_RE = '/^[A-Za-z0-9_-]{16,128}$/';

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

        // Extract DID from kid — strip #fragment if present
        $kid      = (string) ($header['kid'] ?? '');
        $agentDid = strstr($kid, '#', true);
        if ($agentDid === false) {
            $agentDid = $kid;
        }
        if ($agentDid === '') {
            return null;
        }

        // Resolve public key from snapshot agentDidResolver
        $jwk = $this->agentDidResolver[$agentDid] ?? null;
        if (!is_array($jwk)) {
            return null;
        }

        $xCoord    = (string) ($jwk['x'] ?? '');
        $pubkeyRaw = SnapshotClient::base64UrlDecode($xCoord);
        if (strlen($pubkeyRaw) !== 32) {
            return null;
        }

        // Verify Ed25519 signature
        $signingInput = $headerB64 . '.' . $payloadB64;
        $sig          = SnapshotClient::base64UrlDecode($sigB64);

        if (!sodium_crypto_sign_verify_detached($sig, $signingInput, $pubkeyRaw)) {
            return null;
        }

        // Decode and validate payload
        $payloadJson = SnapshotClient::base64UrlDecode($payloadB64);
        $payload     = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }

        // T108 HIGH-3: Validate iss matches kid-derived DID to prevent key-confusion
        // attacks where an attacker uses their own registered key (kid=attacker#k1)
        // but sets iss=victim-agent. The signature would verify (attacker key is valid)
        // but the returned agentId would be the victim's DID, bypassing the allowlist.
        $iss = (string) ($payload['iss'] ?? '');
        if ($iss === '' || $iss !== $agentDid) {
            return null; // iss/kid mismatch — potential key confusion attack
        }

        if (($payload['aud'] ?? '') !== 'trusteed') {
            return null;
        }
        if (($payload['merchantId'] ?? '') !== $this->merchantId) {
            return null;
        }

        // Validate expiry — allow 30s clock skew grace
        $now = time();
        if (isset($payload['exp']) && ((int) $payload['exp'] + 30) < $now) {
            return null;
        }

        // Validate token lifetime — max 5min (300s) + 30s grace = 330s
        if (
            isset($payload['exp'], $payload['iat'])
            && ((int) $payload['exp'] - (int) $payload['iat']) > 330
        ) {
            return null;
        }

        // Validate cart hash binding
        if (($payload['checkoutIntentHash'] ?? '') !== $checkoutIntentHash) {
            return null;
        }

        // Best-effort nonce deduplication
        $nonce = (string) ($payload['nonce'] ?? '');
        if ($nonce !== '' && !$this->isNonceNew($nonce, $agentDid)) {
            return null;
        }

        // Spec-048 P2.8 — extract `jti` for backend single-use replay protection.
        // Missing or malformed jti is treated as INVALID to prevent unbounded
        // replay via tokens minted without single-use identifiers. Mirrors WC
        // class-token-verifier.php missing_jti / bad_jti gating.
        $jtiRaw = isset($payload['jti']) ? (string) $payload['jti'] : '';
        if ($jtiRaw === '') {
            return null;
        }
        if (preg_match(self::JTI_RE, $jtiRaw) !== 1) {
            return null;
        }

        // iss has been verified above to equal $agentDid — use $iss directly.
        return [
            'agentId'              => $iss,
            'trustScore'           => isset($payload['agentTrustScore']) ? (float) $payload['agentTrustScore'] : null,
            'platform'             => isset($payload['platform']) ? (string) $payload['platform'] : null,
            'kid'                  => $kid,
            'iat'                  => isset($payload['iat']) ? (int) $payload['iat'] : 0,
            'scope'                => isset($payload['scope']) ? (string) $payload['scope'] : '',
            'returnPolicyAccepted' => isset($payload['return_policy']) ? (string) $payload['return_policy'] : '',
            'jti'                  => $jtiRaw,
            'exp'                  => isset($payload['exp']) ? (int) $payload['exp'] : 0,
        ];
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

        // B5: persistent file-cache for cross-request nonce dedup so plain
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
