<?php

declare(strict_types=1);

/**
 * MerchantContextGuardTest — spec-046 H8 (cross-shop IDOR prevention).
 *
 * Tests the PURE assertMatches() against the REAL guard class.
 */

namespace Trusteed\Tests\AgenticTools;

use Trusteed\AgenticTools\McpToolException;
use Trusteed\AgenticTools\MerchantContextGuard;
use PHPUnit\Framework\TestCase;

final class MerchantContextGuardTest extends TestCase
{
    public function testMatchingMerchantIdPasses(): void
    {
        $this->expectNotToPerformAssertions();
        MerchantContextGuard::assertMatches('m-123', 'm-123');
    }

    public function testMismatchedMerchantIdIsUnauthorized(): void
    {
        try {
            MerchantContextGuard::assertMatches('m-OTHER', 'm-123');
            $this->fail('expected unauthorized');
        } catch (McpToolException $e) {
            $this->assertSame(McpToolException::CODE_UNAUTHORIZED, $e->getMcpCode());
        }
    }

    public function testEmptyInputMerchantIdIsUnauthorized(): void
    {
        $this->expectException(McpToolException::class);
        MerchantContextGuard::assertMatches('', 'm-123');
    }

    public function testNoProvisionedMerchantIsNotProvisioned(): void
    {
        try {
            MerchantContextGuard::assertMatches('m-123', '');
            $this->fail('expected merchant_not_provisioned');
        } catch (McpToolException $e) {
            $this->assertSame(McpToolException::CODE_MERCHANT_NOT_PROVISIONED, $e->getMcpCode());
        }
    }

    public function testComparisonIsCaseSensitiveExactMatch(): void
    {
        // hash_equals is exact — a near-miss must be rejected.
        $this->expectException(McpToolException::class);
        MerchantContextGuard::assertMatches('M-123', 'm-123');
    }
}
