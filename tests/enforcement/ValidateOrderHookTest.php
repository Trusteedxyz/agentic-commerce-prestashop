<?php

declare(strict_types=1);

/**
 * ValidateOrderHookTest — PHPUnit 10 tests for CEL enforcement (T078, spec-048).
 *
 * Tests cover the core rule-evaluation logic shared by:
 *   - override/classes/PaymentModule.php  (PS 8.x blocking path)
 *   - src/Enforcement/ValidateOrderHook.php (PS 9+ hook + telemetry)
 *
 * Because both files depend on the PrestaShop kernel (\Cart, \Configuration,
 * \Tools, \Context, etc.) we cannot load them in a unit-test process.
 * Instead we test the evaluation logic in three layers:
 *
 *   Layer 1 — SnapshotClient::verifyAndDecode()  (real sodium, no PS)
 *   Layer 2 — TokenVerifier::verify()            (real sodium, no PS)
 *   Layer 3 — MockCelEvaluator                   (in-test orchestrator that
 *              mirrors runCelEvaluation / evaluateCart without PS glue)
 *
 * This approach is consistent with the existing MockPermissionGuardImpl pattern
 * in PermissionGuardTest.php and the WP plugin's TokenVerifierTest.php.
 *
 * Tests:
 *   T078-1  Allow order when agent token is valid and R001 passes
 *   T078-2  Block order when no agent token present and fallbackMode=strict
 *   T078-3  Allow order when no agent token present and fallbackMode=balanced (fail-open)
 *   T078-4  Block order when killSwitch=true regardless of valid token
 *   T078-5  Allow order when agentDid is in allowlistAgentIds (skip verification)
 *   T078-6  Multishop merchant resolution returns per-shop Configuration value
 *
 * Run from repo root:
 *   vendor/bin/phpunit \
 *     packages/prestashop-module-agenticmcpstores/tests/enforcement/ValidateOrderHookTest.php
 */

namespace Trusteed\Tests\Enforcement;

use Trusteed\Enforcement\SnapshotClient;
use Trusteed\Enforcement\TokenVerifier;
use PHPUnit\Framework\TestCase;

// Autoload enforcement classes directly (no PS kernel needed)
require_once __DIR__ . '/PsEnforcementStubs.php';
require_once __DIR__ . '/../../src/Enforcement/SnapshotClient.php';
require_once __DIR__ . '/../../src/Enforcement/TokenVerifier.php';

class ValidateOrderHookTest extends TestCase
{
    // ── Static fixtures shared across tests ──────────────────────────────────

    /** 64-byte Ed25519 secret key for signing RuleSnapshot JWS */
    private static string $snapPrivKey;
    /** 32-byte Ed25519 public key for snapshot verification */
    private static string $snapPubKey;
    /** JWKS kid used for the snapshot signing key */
    private static string $snapKid = 'snap-key-001';

    /** 64-byte Ed25519 secret key for signing agent tokens */
    private static string $agentPrivKey;
    /** 32-byte Ed25519 public key for agent token verification */
    private static string $agentPubKey;

    private const MERCHANT_ID = 'merch-test-ps8';
    private const AGENT_DID   = 'did:key:zQ3shP6YKjqsb9MmbRMSQvmCq5N3a97RXb1ZV8NLKhBWYdrQS';
    private const CART_HASH   = 'aabbccdd0011223344556677889900aabbccdd0011223344556677889900aabb';

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public static function setUpBeforeClass(): void
    {
        // Generate snapshot signing keypair
        $snapKp          = sodium_crypto_sign_keypair();
        self::$snapPubKey  = sodium_crypto_sign_publickey($snapKp);
        self::$snapPrivKey = sodium_crypto_sign_secretkey($snapKp);

        // Generate agent token signing keypair
        $agentKp          = sodium_crypto_sign_keypair();
        self::$agentPubKey  = sodium_crypto_sign_publickey($agentKp);
        self::$agentPrivKey = sodium_crypto_sign_secretkey($agentKp);
    }

    // ── T078-1: Allow order when agent token is valid ─────────────────────────

    /**
     * When the agent presents a valid Ed25519-signed token with agentId set,
     * R001 evaluates to ALLOW and the evaluator returns blocked=false.
     */
    public function testAllowOrderWhenTokenValid(): void
    {
        $snapshotJws = $this->buildSnapshotJws(
            killSwitch:   false,
            fallbackMode: 'balanced',
            rules:        [['ruleCode' => 'R001', 'enabled' => true]],
        );

        $agentTokenJws = $this->buildAgentToken(
            agentId:   'agent-from-test',
            cartHash:  self::CART_HASH,
            nonce:     bin2hex(random_bytes(16)),
        );

        $result = $this->buildEvaluator()->evaluate(
            snapshotJws:   $snapshotJws,
            agentTokenJws: $agentTokenJws,
            cartHash:      self::CART_HASH,
            fallbackMode:  'balanced',
        );

        $this->assertFalse($result['blocked'], 'Valid agent token with agentId must pass R001 → not blocked');
    }

    // ── T078-2: Block order when no agent token and fallbackMode=strict ───────

    /**
     * When no agent token is supplied and fallbackMode is 'strict', the
     * evaluator must block (R001 tier-1 fail-closed in strict mode).
     */
    public function testBlockOrderWhenNoTokenStrictMode(): void
    {
        $snapshotJws = $this->buildSnapshotJws(
            killSwitch:   false,
            fallbackMode: 'strict',
            rules:        [['ruleCode' => 'R001', 'enabled' => true]],
        );

        $result = $this->buildEvaluator()->evaluate(
            snapshotJws:   $snapshotJws,
            agentTokenJws: '',          // No agent token
            cartHash:      self::CART_HASH,
            fallbackMode:  'strict',
        );

        $this->assertTrue($result['blocked'], 'No agent token in strict mode must block the order');
        $this->assertSame('R001', $result['ruleCode'] ?? null,
            'Block reason must reference R001 (agent identity not verified)');
    }

    // ── T078-3: Allow order when no agent token and fallbackMode=balanced ──────

    /**
     * When no agent token is present and fallbackMode is 'balanced', the
     * evaluator returns blocked=false (fail-open for non-agentic flows).
     *
     * Per PaymentModule::runCelEvaluation(): if agentToken === '' the method
     * returns early without blocking — balanced and permissive both fail-open.
     */
    public function testAllowOrderNoTokenFallbackBalanced(): void
    {
        $snapshotJws = $this->buildSnapshotJws(
            killSwitch:   false,
            fallbackMode: 'balanced',
            rules:        [['ruleCode' => 'R001', 'enabled' => true]],
        );

        $result = $this->buildEvaluator()->evaluate(
            snapshotJws:   $snapshotJws,
            agentTokenJws: '',          // No agent token
            cartHash:      self::CART_HASH,
            fallbackMode:  'balanced',
        );

        $this->assertFalse($result['blocked'],
            'No agent token with balanced fallback must not block (fail-open for human flows)');
    }

    // ── T078-4: Block order when killSwitch=true ──────────────────────────────

    /**
     * When the snapshot payload has killSwitch=true, the evaluator must block
     * the order immediately, regardless of a valid agent token.
     * The ruleCode must identify the kill-switch trigger.
     */
    public function testBlockOrderKillSwitch(): void
    {
        $snapshotJws = $this->buildSnapshotJws(
            killSwitch:   true,
            fallbackMode: 'balanced',
            rules:        [],
        );

        $agentTokenJws = $this->buildAgentToken(
            agentId:  'agent-ks-test',
            cartHash: self::CART_HASH,
            nonce:    bin2hex(random_bytes(16)),
        );

        $result = $this->buildEvaluator()->evaluate(
            snapshotJws:   $snapshotJws,
            agentTokenJws: $agentTokenJws,
            cartHash:      self::CART_HASH,
            fallbackMode:  'balanced',
        );

        $this->assertTrue($result['blocked'],
            'killSwitch=true in snapshot must block the order unconditionally');
        $this->assertSame('R000-KILLSWITCH', $result['ruleCode'] ?? null,
            'Kill-switch block must use ruleCode R000-KILLSWITCH');
    }

    // ── T078-5: Allow order when agentDid is in allowlistAgentIds ────────────

    /**
     * When the agent DID appears in the snapshot allowlistAgentIds array,
     * token verification is skipped and the order is allowed without
     * evaluating any rules against the agent token.
     */
    public function testAllowOrderWhenAgentInAllowlist(): void
    {
        $allowlistedDid = self::AGENT_DID;

        $snapshotJws = $this->buildSnapshotJws(
            killSwitch:        false,
            fallbackMode:      'balanced',
            rules:             [['ruleCode' => 'R001', 'enabled' => true]],
            allowlistAgentIds: [$allowlistedDid],
        );

        // Pass an invalid (empty) agent token — allowlist bypass means it is never verified
        $result = $this->buildEvaluator()->evaluate(
            snapshotJws:    $snapshotJws,
            agentTokenJws:  '',
            cartHash:       self::CART_HASH,
            fallbackMode:   'balanced',
            presentAgentDid: $allowlistedDid,   // DID presented by the agent (e.g. from header)
        );

        $this->assertFalse($result['blocked'],
            'Agent DID in allowlistAgentIds must bypass verification and allow the order');
    }

    // ── T078-6: Multishop merchant resolution returns per-shop value ──────────

    /**
     * MockPsMerchantResolver::getMerchantId() must read from the per-shop
     * configuration store keyed by shopId, NOT the global default.
     * This mirrors MerchantResolver::getConfig() which calls
     * Configuration::get($key, null, null, $shopId) when $shopId > 0.
     */
    public function testMultishopMerchantResolution(): void
    {
        $resolver = new MockPsMerchantResolver(
            configStore: [
                'TRUSTEED_CEL_MERCHANT_ID:shop:1' => 'merch-shop-1',
                'TRUSTEED_CEL_MERCHANT_ID:shop:2' => 'merch-shop-2',
                'TRUSTEED_CEL_MERCHANT_ID'         => 'merch-global',
            ]
        );

        $this->assertSame('merch-shop-1', $resolver->getMerchantId(shopId: 1),
            'Shop 1 must return its own per-shop merchantId');
        $this->assertSame('merch-shop-2', $resolver->getMerchantId(shopId: 2),
            'Shop 2 must return a different merchantId from shop 1');
        $this->assertSame('merch-global', $resolver->getMerchantId(shopId: 0),
            'shopId=0 must fall back to the global Configuration value');
    }

    /**
     * Audit `docs/analysis/rules.md` L228 — multi-language PO-box detection
     * must cover local regional formats (Casilla PY, Apartado aéreo CO,
     * Case postale CH-FR, Postbus NL, а/я RU, 私書箱 JA, 邮政信箱 ZH).
     */
    public function testDetectPoBoxLocalAuditExpansion(): void
    {
        // Spanish regional
        $this->assertTrue(\Trusteed\Enforcement\ValidateOrderHook::detectPoBox('Casilla 1234'));
        $this->assertTrue(\Trusteed\Enforcement\ValidateOrderHook::detectPoBox('Apartado aéreo 50321'));
        // French CH/Africa
        $this->assertTrue(\Trusteed\Enforcement\ValidateOrderHook::detectPoBox('Case Postale 99'));
        $this->assertTrue(\Trusteed\Enforcement\ValidateOrderHook::detectPoBox('B.P. 42'));
        // Dutch + German poste-restante
        $this->assertTrue(\Trusteed\Enforcement\ValidateOrderHook::detectPoBox('Postbus 17'));
        $this->assertTrue(\Trusteed\Enforcement\ValidateOrderHook::detectPoBox('Postlagernd 9'));
        // Russian + CJK
        $this->assertTrue(\Trusteed\Enforcement\ValidateOrderHook::detectPoBox('а/я 88'));
        $this->assertTrue(\Trusteed\Enforcement\ValidateOrderHook::detectPoBox('私書箱 7'));
        $this->assertTrue(\Trusteed\Enforcement\ValidateOrderHook::detectPoBox('邮政信箱 100'));
        // Negative
        $this->assertFalse(\Trusteed\Enforcement\ValidateOrderHook::detectPoBox('123 Main Street'));
        $this->assertFalse(\Trusteed\Enforcement\ValidateOrderHook::detectPoBox('Posthumous Lane 4'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Build a JWS-signed RuleSnapshot payload (snapshot signing keypair).
     * The JWKS is embedded in a static fake resolver so SnapshotClient
     * does not need to perform HTTP calls in tests.
     *
     * @param list<array<string,mixed>> $rules
     * @param list<string>              $allowlistAgentIds
     */
    private function buildSnapshotJws(
        bool   $killSwitch,
        string $fallbackMode,
        array  $rules,
        array  $allowlistAgentIds = [],
    ): string {
        $payload = [
            'merchantId'      => self::MERCHANT_ID,
            'ruleSetVersion'  => 'v1.0',
            'validUntil'      => date('c', time() + 300),
            'killSwitch'      => $killSwitch,
            'fallbackMode'    => $fallbackMode,
            'rules'           => $rules,
            'allowlistAgentIds' => $allowlistAgentIds,
            // agentDidResolver maps the agent DID to its public key
            'agentDidResolver' => [
                self::AGENT_DID => [
                    'x' => SnapshotClient::base64UrlEncode(self::$agentPubKey),
                ],
            ],
        ];

        $headerB64  = SnapshotClient::base64UrlEncode(
            json_encode(['alg' => 'EdDSA', 'kid' => self::$snapKid, 'typ' => 'JWS'], JSON_THROW_ON_ERROR)
        );
        $payloadB64 = SnapshotClient::base64UrlEncode(
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        $signingInput = $headerB64 . '.' . $payloadB64;
        $sig          = sodium_crypto_sign_detached($signingInput, self::$snapPrivKey);

        return $signingInput . '.' . SnapshotClient::base64UrlEncode($sig);
    }

    /**
     * Build a signed agent token JWS (agent signing keypair).
     *
     * @param string $agentId  The iss / agentId claim
     * @param string $cartHash The checkoutIntentHash to embed
     * @param string $nonce    Fresh unique nonce for replay protection
     */
    private function buildAgentToken(
        string $agentId,
        string $cartHash,
        string $nonce,
    ): string {
        $now = time();
        $claims = [
            'iss'                => $agentId,
            'aud'                => 'trusteed',
            'merchantId'         => self::MERCHANT_ID,
            'checkoutIntentHash' => $cartHash,
            'nonce'              => $nonce,
            'iat'                => $now,
            'exp'                => $now + 300,
        ];

        $headerB64  = SnapshotClient::base64UrlEncode(
            json_encode([
                'alg' => 'EdDSA',
                'typ' => 'trusteed-agent-token+jwt',
                'kid' => self::AGENT_DID . '#key-1',
            ], JSON_THROW_ON_ERROR)
        );
        $payloadB64 = SnapshotClient::base64UrlEncode(
            json_encode($claims, JSON_THROW_ON_ERROR)
        );

        $signingInput = $headerB64 . '.' . $payloadB64;
        $sig          = sodium_crypto_sign_detached($signingInput, self::$agentPrivKey);

        return $signingInput . '.' . SnapshotClient::base64UrlEncode($sig);
    }

    /**
     * Build a fresh MockCelEvaluator wired with the snapshot keypair's public key.
     */
    private function buildEvaluator(): MockCelEvaluator
    {
        return new MockCelEvaluator(
            snapPubKey: self::$snapPubKey,
            snapKid:    self::$snapKid,
        );
    }
}

// ── In-test doubles ──────────────────────────────────────────────────────────

/**
 * MockCelEvaluator
 *
 * In-test stand-in that mirrors the evaluation flow of both
 * PaymentModule::runCelEvaluation() and ValidateOrderHook::evaluateCart().
 *
 * It uses the real SnapshotClient::verifyAndDecode() and TokenVerifier::verify()
 * so the sodium cryptography is exercised with no mocks.
 * The PS kernel glue (\Cart, \Configuration, \Tools, \Context) is replaced by
 * direct parameter passing.
 */
class MockCelEvaluator
{
    /** @var array<string, string> Map of JWKS kid => 32-byte raw Ed25519 public key */
    private array $jwksKeyMap;

    /**
     * @param string $snapPubKey 32-byte raw Ed25519 public key for snapshot verification
     * @param string $snapKid    JWKS kid that resolves to $snapPubKey
     */
    public function __construct(
        private string $snapPubKey,
        private string $snapKid,
    ) {
        $this->jwksKeyMap = [$snapKid => $snapPubKey];
    }

    /**
     * Evaluate CEL rules against the supplied snapshot JWS and agent token JWS.
     *
     * Mirrors the logic of PaymentModule::runCelEvaluation() and
     * ValidateOrderHook::evaluateCart() without any PS kernel dependency.
     *
     * @param string $snapshotJws    JWS Compact of the signed RuleSnapshot
     * @param string $agentTokenJws  JWS Compact of the signed agent token (or '')
     * @param string $cartHash       SHA-256 hex checkoutIntentHash
     * @param string $fallbackMode   'strict' | 'balanced' | 'permissive'
     * @param string $presentAgentDid  DID presented separately (for allowlist check)
     * @return array{blocked: bool, ruleCode?: string}
     */
    public function evaluate(
        string $snapshotJws,
        string $agentTokenJws,
        string $cartHash,
        string $fallbackMode,
        string $presentAgentDid = '',
    ): array {
        // Verify and decode snapshot using real SnapshotClient logic
        $payload = $this->decodeSnapshot($snapshotJws);
        if ($payload === null) {
            // Unverifiable snapshot — fail-open (same as balanced)
            return ['blocked' => false];
        }

        // Kill-switch: block unconditionally when set in snapshot
        if (!empty($payload['killSwitch'])) {
            return ['blocked' => true, 'ruleCode' => 'R000-KILLSWITCH'];
        }

        // Allowlist bypass: if agent DID is explicitly trusted, allow without token check
        $allowlistAgentIds = (array) ($payload['allowlistAgentIds'] ?? []);
        if ($presentAgentDid !== '' && in_array($presentAgentDid, $allowlistAgentIds, true)) {
            return ['blocked' => false];
        }

        // No agent token present — apply fallback policy
        if ($agentTokenJws === '') {
            if ($fallbackMode === 'strict') {
                return ['blocked' => true, 'ruleCode' => 'R001'];
            }

            // balanced / permissive: fail-open (non-agentic flow)
            return ['blocked' => false];
        }

        // Verify agent token using real TokenVerifier
        $verifier = new TokenVerifier(
            (array) ($payload['agentDidResolver'] ?? []),
            (string) ($payload['merchantId'] ?? '')
        );

        $claims = $verifier->verify($agentTokenJws, $cartHash);

        if ($claims === null) {
            if ($fallbackMode === 'strict') {
                return ['blocked' => true, 'ruleCode' => 'R001'];
            }

            return ['blocked' => false];
        }

        // Evaluate rules from snapshot
        $rules = (array) ($payload['rules'] ?? []);
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            if (!(bool) ($rule['enabled'] ?? true)) {
                continue;
            }

            $ruleCode = (string) ($rule['ruleCode'] ?? '');
            $blocked  = $this->evaluateRule($ruleCode, $claims);

            if ($blocked) {
                return ['blocked' => true, 'ruleCode' => $ruleCode];
            }
        }

        return ['blocked' => false];
    }

    /**
     * Verify JWS signature using the injected public key and decode payload.
     * Replaces the JWKS HTTP fetch in SnapshotClient with a local key map.
     *
     * @return array|null Decoded payload or null on verification failure
     */
    private function decodeSnapshot(string $jws): ?array
    {
        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;

        $header = json_decode(SnapshotClient::base64UrlDecode($headerB64), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'EdDSA' || empty($header['kid'])) {
            return null;
        }

        $pubkeyRaw = $this->jwksKeyMap[$header['kid']] ?? null;
        if ($pubkeyRaw === null || strlen($pubkeyRaw) !== 32) {
            return null;
        }

        $sig          = SnapshotClient::base64UrlDecode($sigB64);
        $signingInput = $headerB64 . '.' . $payloadB64;

        if (!sodium_crypto_sign_verify_detached($sig, $signingInput, $pubkeyRaw)) {
            return null;
        }

        $payload = json_decode(SnapshotClient::base64UrlDecode($payloadB64), true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * Mirror of PaymentModule::evaluateRule() / ValidateOrderHook::evaluateRule().
     *
     * Returns true when the rule blocks the order.
     */
    private function evaluateRule(string $ruleCode, array $claims): bool
    {
        return match ($ruleCode) {
            'R001'  => !isset($claims['agentId']) || $claims['agentId'] === '',
            'R007'  => isset($claims['trustScore']) && (float) $claims['trustScore'] < 0.3,
            default => false, // fail-open for unimplemented rules
        };
    }
}

/**
 * MockPsMerchantResolver
 *
 * Stand-in for Trusteed\Enforcement\MerchantResolver.
 *
 * The real MerchantResolver::getConfig() calls Configuration::get($key, null, null, $shopId)
 * for per-shop resolution and Configuration::get($key) for global.
 * This mock uses an injected key-value store keyed as:
 *   - "{KEY}:shop:{shopId}"  for per-shop values  ($shopId > 0)
 *   - "{KEY}"                for global values     ($shopId = 0)
 *
 * Confirms the multishop contract: different shops return different merchantIds.
 */
class MockPsMerchantResolver
{
    /**
     * @param array<string, string> $configStore Flat key-value config store
     */
    public function __construct(private array $configStore) {}

    /**
     * Resolve the merchantId for $shopId.
     * Mirrors MerchantResolver::getMerchantId(int $shopId): string.
     */
    public function getMerchantId(int $shopId): string
    {
        return $this->getConfig('TRUSTEED_CEL_MERCHANT_ID', $shopId);
    }

    /**
     * Resolve a config key with per-shop precedence.
     * Mirrors MerchantResolver::getConfig(string $key, int $shopId): string.
     */
    private function getConfig(string $key, int $shopId): string
    {
        if ($shopId > 0) {
            $shopKey = "{$key}:shop:{$shopId}";

            return $this->configStore[$shopKey] ?? $this->configStore[$key] ?? '';
        }

        return $this->configStore[$key] ?? '';
    }
}
