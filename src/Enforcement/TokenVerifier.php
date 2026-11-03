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
     * Antigüedad máxima del token (segundos desde `iat` hasta ahora).
     *
     * Decisión 48 (2026-10-02): el MISMO número en todos los verificadores, los
     * 300 s que declara el contrato (`AgentTokenPayloadSchema`: `exp - iat <= 300`).
     * PrestaShop no tenía tope de antigüedad (sólo el de vida `exp - iat`, que
     * un token con `exp` renovado cuya `iat` es vieja no cubre). Atado entre
     * lenguajes por
     * packages/shared/src/enforcement/__tests__/agent-token-max-age-parity.test.ts.
     */
    private const MAX_AGE_SECONDS = 300;

    /** @var array<mixed> Lista `[{did, publicKeyJwk}]` del servidor o mapa antiguo `{did: jwk}`. */
    private array $agentDidResolver;

    private string $merchantId;

    /** Static in-process nonce cache when APCu unavailable */
    private static array $nonceCache = [];

    /**
     * @param array<mixed> $agentDidResolver `agentDidResolver` del snapshot (lista o mapa; {@see resolveAgentDidPublicKey()}).
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
     *         spec-048 P2.8 backend nonce-consume replay protection. The `jti`
     *         key holds the token's single-use identifier: the canonical `nonce`
     *         claim, or the legacy `jti` claim as an alias when `nonce` is absent
     *         (B5). When both are missing or malformed (16–64 base64url chars),
     *         returns null and the caller (ValidateOrderHook) treats the token
     *         as invalid.
     */
    public const REPLAY_ID_RE = '/^[A-Za-z0-9_-]{16,64}$/';

    /**
     * Single-use identifier of a (signature-verified) payload (B5). The canonical
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
        // RFC 7515 §4.1.11: `crit` lista extensiones que el receptor DEBE
        // entender. Este verificador no entiende ninguna ni el firmante de
        // tokens de agente emite `crit`: presente (incluso `[]`, que el RFC
        // declara inválida) ⇒ rechazo. Mismo criterio que los verificadores
        // de snapshot y que el de tokens de WooCommerce.
        if (array_key_exists('crit', $header)) {
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

        // Resolve public key from snapshot agentDidResolver (lista real del
        // servidor o mapa antiguo; plan 04 Task 22).
        $jwk = self::resolveAgentDidPublicKey($this->agentDidResolver, $agentDid);
        if ($jwk === null) {
            return null;
        }
        // Una clave que declara otro tipo o curva no es Ed25519: no se usa.
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
        // GHSA-2j2x-5q52-g48m issue 1: sodium_crypto_sign_verify_detached()
        // requires $sig to be exactly SODIUM_CRYPTO_SIGN_BYTES (64) bytes and
        // THROWS SodiumException for any other length instead of returning
        // false. $sig decodes from an unauthenticated request parameter, so a
        // malformed length must be rejected as an ordinary invalid token
        // (null), the same way every other malformed-input branch in this
        // method already behaves — never let it escape as an exception, which
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
            // Defense in depth — the length check above should make this
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

        // H4 (verificación 2026-07-28) — `exp`, `iat` y `nonce` son
        // OBLIGATORIOS. El schema canónico los exige
        // (`AgentTokenPayloadSchema` en packages/shared/src/enforcement/types.ts:
        // `iat`/`exp` enteros positivos y `nonce` de 16..64 caracteres), y el
        // verificador de WooCommerce ya los exigía. Aquí cada protección de
        // abajo (caducidad, tope de vida de 330s, anti-replay) colgaba de un
        // `isset`, así que un token que simplemente OMITÍA el claim se saltaba
        // la comprobación: sin `exp` era válido para siempre, y sin `nonce` no
        // se deduplicaba nada. Rechazo fail-closed.
        if (!isset($payload['exp']) || !is_numeric($payload['exp'])) {
            return null;
        }
        if (!isset($payload['iat']) || !is_numeric($payload['iat'])) {
            return null;
        }
        // B5 — el identificador de un solo uso es `nonce` (canónico) o, como
        // alias heredado, `jti`; ambos de 16..64 caracteres base64url. Sale del
        // payload YA verificado (la firma se comprobó arriba).
        $nonceClaim = self::replayIdentifier($payload);
        if ($nonceClaim === '') {
            return null;
        }

        // Validate expiry — allow 30s clock skew grace
        $now = time();
        if (((int) $payload['exp'] + 30) < $now) {
            return null;
        }

        // `iat` futuro: combinado con el tope de 330s daría una ventana
        // deslizante (iat=+1h ⇒ el token vive 1h+330s de reloj de pared aunque
        // exp-iat ≤ 330s). Mismo guard que el verificador de WooCommerce.
        if (((int) $payload['iat'] - 30) > $now) {
            return null;
        }

        // Validate token lifetime — max 5min (300s) + 30s grace = 330s
        if (((int) $payload['exp'] - (int) $payload['iat']) > 330) {
            return null;
        }

        // Antigüedad desde `iat` (decisión 48): el mismo tope que WooCommerce,
        // Magento y Odoo. Sin él, un `exp` dentro de la ventana de vida no
        // acotaba cuánto hace que se emitió el token.
        if ((time() - (int) $payload['iat']) > self::MAX_AGE_SECONDS) {
            return null;
        }

        // Validate cart hash binding
        if (($payload['checkoutIntentHash'] ?? '') !== $checkoutIntentHash) {
            return null;
        }

        // Nonce deduplication (offline, best-effort). El claim ya se validó
        // como obligatorio arriba, así que aquí sólo queda comprobar unicidad.
        if (!$this->isNonceNew($nonceClaim, $agentDid)) {
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
            'jti'                  => $nonceClaim,
            'exp'                  => isset($payload['exp']) ? (int) $payload['exp'] : 0,
        ];
    }

    /**
     * Port de `resolveAgentDidPublicKey` (platform-flow.ts, Task 20; fijado por
     * la sección `snapshotResolverShape` del fixture).
     *
     * El servidor emite una LISTA `[{did, publicKeyJwk:{kty,crv,x,kid?}}]`
     * (`RuleSnapshotPayloadSchema`); este módulo leía sólo el MAPA antiguo
     * `{did: jwk}`, así que ningún token legítimo verificaba. Acepta las dos:
     *
     * - Lista: vale la ÚLTIMA entrada bien formada con `did` exacto.
     * - Mapa: sólo la clave exacta.
     * - Entradas malformadas (no objeto, `did` no cadena, `publicKeyJwk` no
     *   objeto, `x` ausente/vacío/no cadena) se ignoran sin lanzar; cualquier
     *   otro `$resolver` (null, cadena, número) no resuelve nada.
     *
     * Sólo resuelve: `kty`/`crv` y la firma los comprueba {@see verify()}.
     *
     * @param mixed $resolver `agentDidResolver` del snapshot tal cual.
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

    /** Un objeto JSON decodificado: array asociativo (o vacío), nunca una lista. */
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
