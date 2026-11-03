<?php

declare(strict_types=1);

/**
 * BackendApiClientRetryTest — spec-046 H7 (double-charge prevention).
 *
 * Validates the PURE retry classifier so 4xx responses (notably 402 Payment
 * Required / 409 Conflict / 429 Too Many Requests) are NEVER retried, while
 * transport errors and 5xx ARE retried once.
 */

namespace Trusteed\Tests\AgenticTools;

use Trusteed\AgenticTools\BackendApiClient;
use PHPUnit\Framework\TestCase;

final class BackendApiClientRetryTest extends TestCase
{
    public function testTransportErrorIsRetryable(): void
    {
        $this->assertTrue(BackendApiClient::isRetryableStatus(null));
    }

    /**
     * @dataProvider serverErrors
     */
    public function testFiveXxIsRetryable(int $status): void
    {
        $this->assertTrue(BackendApiClient::isRetryableStatus($status));
    }

    /** @return list<array{int}> */
    public static function serverErrors(): array
    {
        return [[500], [502], [503], [504], [599]];
    }

    /**
     * @dataProvider clientErrors
     */
    public function testFourXxIsNeverRetried(int $status): void
    {
        $this->assertFalse(
            BackendApiClient::isRetryableStatus($status),
            "HTTP $status must NOT be retried (double-charge risk on payment rails)"
        );
    }

    /** @return list<array{int}> */
    public static function clientErrors(): array
    {
        // 402/409/429 are the dangerous ones for the payment tools.
        return [[400], [401], [402], [403], [404], [409], [422], [429]];
    }

    public function testTwoXxIsNotRetryable(): void
    {
        // 2xx never reaches the retry path, but the classifier must still be safe.
        $this->assertFalse(BackendApiClient::isRetryableStatus(200));
    }
}
