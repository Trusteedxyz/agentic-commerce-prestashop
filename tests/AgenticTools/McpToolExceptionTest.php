<?php

declare(strict_types=1);

/**
 * McpToolExceptionTest — spec-046 FR-014 structured error codes.
 */

namespace Trusteed\Tests\AgenticTools;

use Trusteed\AgenticTools\McpToolException;
use PHPUnit\Framework\TestCase;

final class McpToolExceptionTest extends TestCase
{
    public function testCanonicalCodesAreValid(): void
    {
        foreach ([
            McpToolException::CODE_CAPABILITY_DISABLED,
            McpToolException::CODE_MERCHANT_NOT_PROVISIONED,
            McpToolException::CODE_UNREACHABLE,
            McpToolException::CODE_VALIDATION_FAILED,
            McpToolException::CODE_UNAUTHORIZED,
        ] as $code) {
            $this->assertTrue(McpToolException::isValidCode($code), "$code must be valid");
        }
    }

    public function testUnknownCodeIsInvalid(): void
    {
        $this->assertFalse(McpToolException::isValidCode('totally_made_up'));
    }

    /**
     * @dataProvider httpMappings
     */
    public function testCodeForHttpStatus(int $status, string $expected): void
    {
        $this->assertSame($expected, McpToolException::codeForHttpStatus($status));
    }

    /** @return list<array{int,string}> */
    public static function httpMappings(): array
    {
        return [
            [401, McpToolException::CODE_UNAUTHORIZED],
            [403, McpToolException::CODE_UNAUTHORIZED],
            [400, McpToolException::CODE_VALIDATION_FAILED],
            [422, McpToolException::CODE_VALIDATION_FAILED],
            [404, McpToolException::CODE_MERCHANT_NOT_PROVISIONED],
            [409, McpToolException::CODE_MERCHANT_NOT_PROVISIONED],
            [500, McpToolException::CODE_UNREACHABLE],
            [502, McpToolException::CODE_UNREACHABLE],
            [402, McpToolException::CODE_UNREACHABLE],
            [429, McpToolException::CODE_UNREACHABLE],
        ];
    }

    public function testFromHttpStatusCarriesStatusAndCode(): void
    {
        $e = McpToolException::fromHttpStatus(404, 'not found');
        $this->assertSame(404, $e->getHttpStatus());
        $this->assertSame(McpToolException::CODE_MERCHANT_NOT_PROVISIONED, $e->getMcpCode());
        $this->assertSame('not found', $e->getMessage());
    }

    public function testIsRuntimeException(): void
    {
        $e = new McpToolException(McpToolException::CODE_UNREACHABLE, 'x');
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }
}
