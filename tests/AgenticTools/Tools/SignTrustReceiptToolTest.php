<?php

declare(strict_types=1);

/**
 * SignTrustReceiptToolTest — tests the REAL SignTrustReceiptTool
 * (spec-046 H1 + OLA-1 honesty contract).
 *
 * sign-trust-receipt is catalog backendStatus:"planned" — there is NO standalone
 * receipt-sign write endpoint. The real tool returns a structured
 * capability_disabled error and never POSTs to a non-existent route.
 */

namespace Trusteed\Tests\AgenticTools\Tools;

use Trusteed\AgenticTools\McpToolException;
use Trusteed\AgenticTools\Tools\SignTrustReceiptTool;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fakes.php';

final class SignTrustReceiptToolTest extends TestCase
{
    private function tool(FakeBackendApiClient $api, bool $grant = true): SignTrustReceiptTool
    {
        return new SignTrustReceiptTool($api, new FakePermissionGuard($grant), 42);
    }

    public function testRealExecuteCarriesCanonicalMcpToolName(): void
    {
        $ref   = new \ReflectionMethod(SignTrustReceiptTool::class, 'execute');
        $attrs = $ref->getAttributes('PhpMcp\\Server\\Attributes\\McpTool');

        $this->assertNotEmpty($attrs, 'execute() must declare #[McpTool]');
        $this->assertSame('trusteed_sign_trust_receipt', $attrs[0]->newInstance()->name);
    }

    public function testValidOrderIdReturnsCapabilityDisabledAndNeverCallsBackend(): void
    {
        $api = new FakeBackendApiClient();
        try {
            $this->tool($api)->execute('42', null);
            $this->fail('expected capability_disabled');
        } catch (McpToolException $e) {
            $this->assertSame(McpToolException::CODE_CAPABILITY_DISABLED, $e->getMcpCode());
        }
        $this->assertSame(0, $api->callCount(), 'planned tool must never reach the backend');
    }

    public function testEmptyOrderIdThrowsValidationFailed(): void
    {
        $api = new FakeBackendApiClient();
        try {
            $this->tool($api)->execute('', null);
            $this->fail('expected validation_failed');
        } catch (McpToolException $e) {
            $this->assertSame(McpToolException::CODE_VALIDATION_FAILED, $e->getMcpCode());
        }
        $this->assertSame(0, $api->callCount());
    }

    public function testDeniedPermissionThrowsUnauthorizedBeforeValidation(): void
    {
        $api = new FakeBackendApiClient();
        try {
            $this->tool($api, grant: false)->execute('42', null);
            $this->fail('expected unauthorized');
        } catch (McpToolException $e) {
            $this->assertSame(McpToolException::CODE_UNAUTHORIZED, $e->getMcpCode());
        }
        $this->assertSame(0, $api->callCount());
    }
}
