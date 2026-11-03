<?php

declare(strict_types=1);

/**
 * DispatchPaymentAcpToolTest — tests the REAL DispatchPaymentAcpTool
 * (spec-046 H1 + OLA-1 honesty contract).
 *
 * ACP is catalog backendStatus:"implemented" globally, but NOT module-fulfillable
 * from PrestaShop (process_agent_payment needs a store-slug MCP gateway call the
 * module does not provision). The real tool therefore returns a structured
 * capability_disabled error, and is registered disabled.
 */

namespace Trusteed\Tests\AgenticTools\Tools;

use Trusteed\AgenticTools\McpToolException;
use Trusteed\AgenticTools\Tools\DispatchPaymentAcpTool;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fakes.php';

final class DispatchPaymentAcpToolTest extends TestCase
{
    private const MERCHANT = 'merchant-uuid-aaa';

    protected function setUp(): void
    {
        \TestPsState::reset();
        \TestPsState::$merchantId = self::MERCHANT;
    }

    private function tool(FakeBackendApiClient $api, bool $grant = true): DispatchPaymentAcpTool
    {
        return new DispatchPaymentAcpTool($api, new FakePermissionGuard($grant), 7);
    }

    public function testRealExecuteCarriesCanonicalMcpToolName(): void
    {
        $ref   = new \ReflectionMethod(DispatchPaymentAcpTool::class, 'execute');
        $attrs = $ref->getAttributes('PhpMcp\\Server\\Attributes\\McpTool');
        $this->assertSame('trusteed_dispatch_payment_acp', $attrs[0]->newInstance()->name);
    }

    public function testValidInputReturnsCapabilityDisabledAndNeverCallsBackend(): void
    {
        $api = new FakeBackendApiClient();
        try {
            $this->tool($api)->execute('cart-1', 'idem-1', self::MERCHANT);
            $this->fail('expected capability_disabled');
        } catch (McpToolException $e) {
            $this->assertSame(McpToolException::CODE_CAPABILITY_DISABLED, $e->getMcpCode());
        }
        $this->assertSame(0, $api->callCount(), 'ACP must never reach the backend from PS');
    }

    public function testCrossShopMerchantIdRejectedBeforeDisabledCheck(): void
    {
        $api = new FakeBackendApiClient();
        try {
            $this->tool($api)->execute('cart-1', 'idem-1', 'merchant-uuid-OTHER');
            $this->fail('expected unauthorized');
        } catch (McpToolException $e) {
            $this->assertSame(McpToolException::CODE_UNAUTHORIZED, $e->getMcpCode());
        }
        $this->assertSame(0, $api->callCount());
    }

    public function testDeniedPermissionThrowsUnauthorized(): void
    {
        $api = new FakeBackendApiClient();
        $this->expectException(McpToolException::class);
        $this->expectExceptionMessage('Insufficient permissions');
        $this->tool($api, grant: false)->execute('cart-1', 'idem-1', self::MERCHANT);
    }

    public function testMissingFieldsThrowsValidationFailed(): void
    {
        $api = new FakeBackendApiClient();
        try {
            $this->tool($api)->execute('cart-1', '', self::MERCHANT);
            $this->fail('expected validation_failed');
        } catch (McpToolException $e) {
            $this->assertSame(McpToolException::CODE_VALIDATION_FAILED, $e->getMcpCode());
        }
    }
}
