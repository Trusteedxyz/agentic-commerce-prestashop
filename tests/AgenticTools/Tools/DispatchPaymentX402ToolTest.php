<?php

declare(strict_types=1);

/**
 * DispatchPaymentX402ToolTest — tests the REAL DispatchPaymentX402Tool
 * (spec-046 H1 + H7 + H8 + FR-014).
 *
 * Covers the only module-fulfillable payment rail end-to-end against the REAL
 * tool: canonical spec-035 endpoint, idempotency-key forwarding, the H8
 * cross-shop merchant guard, and structured FR-014 error codes.
 */

namespace Trusteed\Tests\AgenticTools\Tools;

use Trusteed\AgenticTools\McpToolException;
use Trusteed\AgenticTools\ToolToggleSettings;
use Trusteed\AgenticTools\Tools\DispatchPaymentX402Tool;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fakes.php';

final class DispatchPaymentX402ToolTest extends TestCase
{
    private const MERCHANT = 'merchant-uuid-aaa';
    private const PAYLOAD  = ['signature' => '0xabc', 'chain' => 'base'];

    protected function setUp(): void
    {
        // H8: the active shop's authoritative merchantId.
        \TestPsState::reset();
        \TestPsState::$merchantId = self::MERCHANT;
        \TestPsState::$shopId = 1;
        // C3 defense-in-depth: this rail is module-fulfillable; the merchant has
        // toggled it ON. Seed the effective-enabled map so the call-time guard
        // does not fail-close before the seams under test (the unit harness has
        // no PrestaShop Configuration backing store for FR-018b toggles).
        ToolToggleSettings::overrideEnabledForTesting(['dispatch-payment-x402' => true]);
    }

    protected function tearDown(): void
    {
        ToolToggleSettings::overrideEnabledForTesting(null);
    }

    private function tool(FakeBackendApiClient $api, bool $grant = true): DispatchPaymentX402Tool
    {
        return new DispatchPaymentX402Tool($api, new FakePermissionGuard($grant), 7);
    }

    public function testRealExecuteCarriesCanonicalMcpToolName(): void
    {
        $ref   = new \ReflectionMethod(DispatchPaymentX402Tool::class, 'execute');
        $attrs = $ref->getAttributes('PhpMcp\\Server\\Attributes\\McpTool');
        $this->assertSame('trusteed_dispatch_payment_x402', $attrs[0]->newInstance()->name);
    }

    public function testHappyPathCallsCanonicalEndpointAndForwardsIdempotencyKey(): void
    {
        $api = new FakeBackendApiClient(['paymentId' => 'p1', 'status' => 'pending']);
        $result = $this->tool($api)->execute('cart-1', 'idem-key-1', self::MERCHANT, self::PAYLOAD);

        $call = $api->lastCall();
        // LANE 046-F1 Option A — merchant-facing per-store S2S route.
        $this->assertSame('/api/v1/embed/agentic-tools/dispatch-payment-x402', $call['endpoint']);
        $this->assertTrue($call['s2s'] ?? false, 'must use the S2S call path');
        $this->assertSame('idem-key-1', $call['idempotencyKey'], 'idempotency key must be forwarded');
        $this->assertSame(self::MERCHANT, $call['payload']['merchant_id']);
        $this->assertSame(self::PAYLOAD, $call['payload']['paymentPayload']);
        $this->assertSame('pending', $result['status']);
    }

    // ─── H8: cross-shop IDOR rejection (real guard) ────────────────────────────

    public function testCrossShopMerchantIdIsRejectedUnauthorized(): void
    {
        $api = new FakeBackendApiClient();
        try {
            // Agent supplies a DIFFERENT merchantId than the active shop's.
            $this->tool($api)->execute('cart-1', 'idem-1', 'merchant-uuid-OTHER', self::PAYLOAD);
            $this->fail('expected cross-shop rejection');
        } catch (McpToolException $e) {
            $this->assertSame(McpToolException::CODE_UNAUTHORIZED, $e->getMcpCode());
        }
        $this->assertSame(0, $api->callCount(), 'no settlement when cross-shop');
    }

    public function testUnprovisionedShopYieldsMerchantNotProvisioned(): void
    {
        \TestPsState::$merchantId = ''; // no merchant provisioned for the shop
        $api = new FakeBackendApiClient();
        try {
            $this->tool($api)->execute('cart-1', 'idem-1', self::MERCHANT, self::PAYLOAD);
            $this->fail('expected merchant_not_provisioned');
        } catch (McpToolException $e) {
            $this->assertSame(McpToolException::CODE_MERCHANT_NOT_PROVISIONED, $e->getMcpCode());
        }
        $this->assertSame(0, $api->callCount());
    }

    // ─── FR-014 structured codes (real class) ──────────────────────────────────

    public function testDeniedPermissionThrowsUnauthorized(): void
    {
        $api = new FakeBackendApiClient();
        try {
            $this->tool($api, grant: false)->execute('cart-1', 'idem-1', self::MERCHANT, self::PAYLOAD);
            $this->fail('expected unauthorized');
        } catch (McpToolException $e) {
            $this->assertSame(McpToolException::CODE_UNAUTHORIZED, $e->getMcpCode());
        }
        $this->assertSame(0, $api->callCount());
    }

    public function testMissingFieldsThrowsValidationFailed(): void
    {
        $api = new FakeBackendApiClient();
        try {
            $this->tool($api)->execute('', 'idem-1', self::MERCHANT, self::PAYLOAD);
            $this->fail('expected validation_failed');
        } catch (McpToolException $e) {
            $this->assertSame(McpToolException::CODE_VALIDATION_FAILED, $e->getMcpCode());
        }
    }

    public function testBackendErrorPropagatesAsStructuredCode(): void
    {
        $api = new FakeBackendApiClient();
        $api->willThrow(McpToolException::fromHttpStatus(402, 'payment required'));
        try {
            $this->tool($api)->execute('cart-1', 'idem-1', self::MERCHANT, self::PAYLOAD);
            $this->fail('expected propagated McpToolException');
        } catch (McpToolException $e) {
            // 402 → merchant_not_provisioned is not the mapping; 402 falls to default
            // (trusteed_unreachable) per codeForHttpStatus. Assert it is preserved.
            $this->assertSame(402, $e->getHttpStatus());
        }
    }
}
