<?php

declare(strict_types=1);

namespace Trusteed\Tests\Enforcement;

use Trusteed\Service\AgentHistoryFetcher;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Service/AgentHistoryFetcher.php';

/**
 * Spec-048 Sprint E (T-E40) — PrestaShop AgentHistoryFetcher R042 stub.
 *
 * Tests the public contract:
 *   - returns null for empty agent hash or non-positive window
 *   - returns null when the join table is absent (executor → null)
 *   - returns the COUNT(*) when executor returns rows
 *   - never throws on executor errors
 */
final class AgentHistoryFetcherTest extends TestCase
{
    public function test_returns_null_for_empty_agent_hash(): void
    {
        $fetcher = new AgentHistoryFetcher(
            fn(string $sql) => [['cnt' => 42]],
            fn(string $_) => null,
        );
        $this->assertNull($fetcher->completedOrderCountInWindow('', 3600));
    }

    public function test_returns_null_for_non_positive_window(): void
    {
        $fetcher = new AgentHistoryFetcher(
            fn(string $sql) => [['cnt' => 42]],
            fn(string $_) => null,
        );
        $this->assertNull($fetcher->completedOrderCountInWindow('h', 0));
        $this->assertNull($fetcher->completedOrderCountInWindow('h', -1));
    }

    public function test_returns_count_from_executor(): void
    {
        $captured = null;
        $fetcher = new AgentHistoryFetcher(
            function (string $sql) use (&$captured) {
                $captured = $sql;
                return [['cnt' => '5']];
            },
            fn(string $_) => null,
        );
        $this->assertSame(5, $fetcher->completedOrderCountInWindow('agent_hash_abc', 3600));
        $this->assertStringContainsString('agent_id_hash = "agent_hash_abc"', (string) $captured);
        $this->assertStringContainsString('INTERVAL 3600 SECOND', (string) $captured);
    }

    public function test_returns_null_when_executor_returns_null(): void
    {
        $fetcher = new AgentHistoryFetcher(
            fn(string $sql) => null,
            fn(string $_) => null,
        );
        $this->assertNull($fetcher->completedOrderCountInWindow('h', 60));
    }

    public function test_returns_zero_when_executor_returns_empty_array(): void
    {
        $fetcher = new AgentHistoryFetcher(
            fn(string $sql) => [],
            fn(string $_) => null,
        );
        $this->assertSame(0, $fetcher->completedOrderCountInWindow('h', 60));
    }

    public function test_returns_null_and_logs_when_executor_throws(): void
    {
        $logged = [];
        $fetcher = new AgentHistoryFetcher(
            fn(string $sql) => throw new \RuntimeException('db down'),
            function (string $msg) use (&$logged) { $logged[] = $msg; },
        );
        $this->assertNull($fetcher->completedOrderCountInWindow('h', 60));
        $this->assertNotEmpty($logged);
        $this->assertStringContainsString('R042', $logged[0]);
    }

    public function test_returns_zero_when_cnt_missing(): void
    {
        $fetcher = new AgentHistoryFetcher(
            fn(string $sql) => [['other_col' => 1]],
            fn(string $_) => null,
        );
        $this->assertSame(0, $fetcher->completedOrderCountInWindow('h', 60));
    }
}
