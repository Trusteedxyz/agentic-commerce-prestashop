<?php

declare(strict_types=1);

/**
 * DispatchPaymentAp2ToolTest — tests the REAL DispatchPaymentAp2Tool
 * (spec-046 H1 + OLA-1 honesty contract).
 *
 * AP2 v0.2 is catalog backendStatus:"planned" (dormant, gated behind
 * SD_JWT_ENABLED, no dispatch route). The real tool returns a structured
 * capability_disabled error and never reaches the backend.
 */

namespace Trusteed\Tests\AgenticTools\Tools;

use Trusteed\AgenticTools\McpToolException;
use Trusteed\AgenticTools\Tools\DispatchPaymentAp2Tool;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fakes.php';

final class DispatchPaymentAp2ToolTest extends TestCase
{
    private const MERCHANT = 'merchant-uuid-aaa';
    private const MANDATE  = 'eyJ.mandate.jwt';

    protected function setUp(): void
    {
        \TestPsState::reset();
        \TestPsState::$merchantId = self::MERCHANT;
    }

    private function tool(FakeBackendApiClient $api, bool $grant = true): DispatchPaymentAp2Tool
    {
        return new DispatchPaymentAp2Tool($api, new FakePermissionGuard($grant), 7);
    }

    public function testRealExecuteCarriesCanonicalMcpToolName(): void
    {
        $ref   = new \ReflectionMethod(DispatchPaymentAp2Tool::class, 'execute');
        $attrs = $ref->getAttributes('PhpMcp\\Server\\Attributes\\McpTool');
        $this->assertSame('trusteed_dispatch_payment_ap2', $attrs[0]->newInstance()->name);
    }

    public function testValidInputReturnsCapabilityDisabledAndNeverCallsBackend(): void
    {
        $api = new FakeBackendApiClient();
        try {
            $this->tool($api)->execute('cart-1', 'idem-1', self::MERCHANT, self::MANDATE);
            $this->fail('expected capability_disabled');
        } catch (McpToolException $e) {
            $this->assertSame(McpToolException::CODE_CAPABILITY_DISABLED, $e->getMcpCode());
        }
        $this->assertSame(0, $api->callCount(), 'dormant AP2 must never reach the backend');
    }

    public function testCrossShopMerchantIdRejected(): void
    {
        $api = new FakeBackendApiClient();
        $this->expectException(McpToolException::class);
        $this->tool($api)->execute('cart-1', 'idem-1', 'merchant-uuid-OTHER', self::MANDATE);
    }

    public function testMissingMandateThrowsValidationFailed(): void
    {
        $api = new FakeBackendApiClient();
        try {
            $this->tool($api)->execute('cart-1', 'idem-1', self::MERCHANT, '');
            $this->fail('expected validation_failed');
        } catch (McpToolException $e) {
            $this->assertSame(McpToolException::CODE_VALIDATION_FAILED, $e->getMcpCode());
        }
    }

    public function testDeniedPermissionThrowsUnauthorized(): void
    {
        $api = new FakeBackendApiClient();
        try {
            $this->tool($api, grant: false)->execute('cart-1', 'idem-1', self::MERCHANT, self::MANDATE);
            $this->fail('expected unauthorized');
        } catch (McpToolException $e) {
            $this->assertSame(McpToolException::CODE_UNAUTHORIZED, $e->getMcpCode());
        }
    }
}
