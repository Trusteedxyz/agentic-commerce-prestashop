<?php

declare(strict_types=1);

namespace Trusteed\Service;

/**
 * Spec-048 Sprint E (T-E40, ADR-054) — Agent history projection for PrestaShop.
 *
 * R042 velocity-cap counter: completed orders for `agent_id_hash` within a
 * sliding time window. PS does not ship with a native agent-link column on
 * `ps_orders`; this implementation looks up the optional join table
 * `ps_amcp_agent_order_links` (created by spec-048 wave A install) when
 * present, and degrades gracefully otherwise.
 *
 * Fail-soft contract:
 *   - Returns `null` when the source-of-truth table is absent or query fails,
 *     so the evaluator can distinguish "no signal" from "zero completions".
 *   - Never throws — log + return null.
 *
 * @deprecated M1 (audit 2026-06) — NOT wired to any production caller.
 *   R042 velocity-cap is evaluated AUTHORITATIVELY server-side by the backend
 *   `/v1/rules/evaluate` tier-2 path (SnapshotClient::callRulesEvaluate), which
 *   owns the canonical agent order history. This local projection additionally
 *   depends on the optional `amcp_agent_order_links` join table that the module
 *   does not install, so it would degrade to `null` (no signal) on most stores.
 *   Wiring it client-side would create a SECOND, divergent R042 verdict source
 *   with no benefit. Retained (not deleted) for its documented PS field mapping
 *   and existing unit tests; do not add a production caller without first
 *   provisioning the link table AND reconciling semantics with the backend.
 */
final class AgentHistoryFetcher
{
    /** Order state ids treated as "completed" in PS (Payment Accepted / Delivered). */
    private const COMPLETED_STATE_IDS = [2, 5];

    /** @var callable(string): ?array<int,array<string,mixed>> SQL executor (injected for testability). */
    private $executor;

    /** @var callable(string): void Warning logger. */
    private $logger;

    /**
     * @param callable(string): ?array<int,array<string,mixed>> $executor
     *   Receives a SQL string, returns rows or null on error. In real PS context
     *   bind to `Db::getInstance()->executeS(...)`. In tests inject a closure.
     * @param callable(string): void $logger
     *   Bind to PrestaShopLogger::addLog in real context.
     */
    public function __construct(callable $executor, callable $logger)
    {
        $this->executor = $executor;
        $this->logger   = $logger;
    }

    /**
     * R042 — Completed orders for this agent at this merchant inside a
     * sliding time window.
     *
     * Returns `null` (not 0) on any error so the evaluator can distinguish
     * "no signal" from "zero completions".
     */
    public function completedOrderCountInWindow(string $agentIdHash, int $windowSeconds): ?int
    {
        if ($agentIdHash === '' || $windowSeconds <= 0) {
            return null;
        }
        $hash = pSQL($agentIdHash);
        if (!is_string($hash) || $hash === '') {
            return null;
        }
        $stateList = implode(',', array_map('intval', self::COMPLETED_STATE_IDS));
        // Boundary: NOW() - INTERVAL ? SECOND. Cast to int defensively.
        $window = (int) $windowSeconds;

        $sql = sprintf(
            'SELECT COUNT(*) AS cnt FROM `%sorders` o '
            . 'INNER JOIN `%samcp_agent_order_links` al ON al.id_order = o.id_order '
            . 'WHERE al.agent_id_hash = "%s" AND o.current_state IN (%s) '
            . 'AND o.date_add >= DATE_SUB(NOW(), INTERVAL %d SECOND)',
            self::tablePrefix(),
            self::tablePrefix(),
            $hash,
            $stateList,
            $window
        );

        try {
            $rows = ($this->executor)($sql);
        } catch (\Throwable $e) {
            ($this->logger)('[trusteed] R042 query error: ' . $e->getMessage());
            return null;
        }
        if (!is_array($rows) || $rows === []) {
            // Distinguish absent-table (executor returned null) from zero-result
            // (executor returned []). For PS, executeS returns false → mapped to
            // null at the executor boundary; [] is a legit zero count.
            if ($rows === null) {
                return null;
            }
            return 0;
        }
        $first = $rows[0] ?? [];
        $cnt = $first['cnt'] ?? null;
        if ($cnt === null) {
            return 0;
        }
        if (is_int($cnt)) {
            return max(0, $cnt);
        }
        if (is_string($cnt) && ctype_digit($cnt)) {
            return (int) $cnt;
        }
        return null;
    }

    /**
     * PS table prefix. Constant `_DB_PREFIX_` only defined inside a PS runtime;
     * fall back to "ps_" so this class can be unit-tested standalone.
     */
    private static function tablePrefix(): string
    {
        if (defined('_DB_PREFIX_')) {
            // @phpstan-ignore-next-line — defined at PS bootstrap.
            return (string) _DB_PREFIX_;
        }
        return 'ps_';
    }
}

if (!function_exists(__NAMESPACE__ . '\\pSQL')) {
    /**
     * Tiny safety wrapper — real PS exposes a global `pSQL` for escaping.
     * Re-declared in namespace scope only when PS is absent (tests).
     */
    function pSQL(string $value): string
    {
        return str_replace(['\\', '"', "\0", "\n", "\r", "'"], ['\\\\', '\\"', '', '\\n', '\\r', "\\'"], $value);
    }
}
