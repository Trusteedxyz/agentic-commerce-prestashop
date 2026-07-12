<?php

declare(strict_types=1);

/**
 * VerifyAgentSignatureToolTest — tests the REAL VerifyAgentSignatureTool
 * (spec-046 H1 de-tautologization).
 *
 * The previous revision tested an inline MockVerifyAgentSignatureTool. This
 * version instantiates the production class and injects fakes ONLY for the
 * external seams (HTTP transport + PS permission check).
 */

namespace Trusteed\Tests\AgenticTools\Tools;

use Trusteed\AgenticTools\McpToolException;
use Trusteed\AgenticTools\Tools\VerifyAgentSignatureTool;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fakes.php';

final class VerifyAgentSignatureToolTest extends TestCase
{
    private const HEADERS = ['Signature' => 'sig=:abc:', 'Signature-Input' => 'sig=("@method")'];
    private const MERCHANT = 'merchant-uuid-aaa';

    protected function setUp(): void
    {
        // LANE 046-F1: the merchant-facing S2S route authenticates by the
        // authoritative per-store merchant id resolved from the PS shop context.
        \TestPsState::reset();
        \TestPsState::$merchantId = self::MERCHANT;
        \TestPsState::$shopId = 1;
    }

    private function tool(FakeBackendApiClient $api, bool $grant = true): VerifyAgentSignatureTool
    {
        return new VerifyAgentSignatureTool($api, new FakePermissionGuard($grant), 42);
    }

    // ─── Attribute presence (real class reflection) ────────────────────────────

    public function testRealExecuteCarriesCanonicalMcpToolName(): void
    {
        $ref   = new \ReflectionMethod(VerifyAgentSignatureTool::class, 'execute');
        $attrs = $ref->getAttributes('PhpMcp\\Server\\Attributes\\McpTool');

        $this->assertNotEmpty($attrs, 'execute() must declare #[McpTool]');
        $this->assertSame('trusteed_verify_agent_signature', $attrs[0]->newInstance()->name);
    }

    // ─── Happy path → REAL backend route ───────────────────────────────────────

    public function testCallsMerchantFacingS2SEndpoint(): void
    {
        $api = new FakeBackendApiClient(['verified' => true, 'verificationStatus' => 'verified']);
        $result = $this->tool($api)->execute('post', 'https://shop.example/x', self::HEADERS);

        $this->assertSame(1, $api->callCount());
        // LANE 046-F1 Option A — merchant-facing per-store S2S route.
        $this->assertSame('/api/v1/embed/agentic-tools/verify-agent-signature', $api->lastCall()['endpoint']);
        $this->assertTrue($api->lastCall()['s2s'] ?? false, 'must use the S2S call path');
        $this->assertSame(self::MERCHANT, $api->lastCall()['merchantId']);
        $this->assertTrue($result['verified']);
    }

    public function testUppercasesMethodInPayload(): void
    {
        $api = new FakeBackendApiClient();
        $this->tool($api)->execute('post', 'https://shop.example/x', self::HEADERS);

        $this->assertSame('POST', $api->lastCall()['payload']['method']);
        $this->assertSame(self::HEADERS, $api->lastCall()['payload']['headers']);
    }

    // ─── Permission gate (real class) ──────────────────────────────────────────

    public function testDeniedPermissionThrowsUnauthorizedAndSkipsBackend(): void
    {
        $api = new FakeBackendApiClient();
        try {
            $this->tool($api, grant: false)->execute('GET', 'https://x/y', self::HEADERS);
            $this->fail('expected McpToolException');
        } catch (McpToolException $e) {
            $this->assertSame(McpToolException::CODE_UNAUTHORIZED, $e->getMcpCode());
        }
        $this->assertSame(0, $api->callCount(), 'backend must not be called when denied');
    }

    // ─── Validation (real class) → structured code ─────────────────────────────

    public function testEmptyUrlThrowsValidationFailed(): void
    {
        $api = new FakeBackendApiClient();
        try {
            $this->tool($api)->execute('GET', '', self::HEADERS);
            $this->fail('expected McpToolException');
        } catch (McpToolException $e) {
            $this->assertSame(McpToolException::CODE_VALIDATION_FAILED, $e->getMcpCode());
        }
    }

    public function testEmptyHeadersThrowsValidationFailed(): void
    {
        $api = new FakeBackendApiClient();
        $this->expectException(McpToolException::class);
        $this->tool($api)->execute('GET', 'https://x/y', []);
    }
}
