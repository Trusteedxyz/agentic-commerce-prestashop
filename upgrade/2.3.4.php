<?php

declare(strict_types=1);

/**
 * Upgrade script to 2.3.4: `idx_created_at` index on `trusteed_agent_carts`.
 *
 * The index was added to `createTable()`, which uses `CREATE TABLE IF NOT EXISTS`,
 * so stores that were already installed do not have it and
 * `CartAgentStorage::purgeExpired()` does a full scan on every agent cart
 * creation. This script creates it when missing (additive and idempotent: it
 * checks `SHOW INDEX` before the `ALTER`).
 *
 * Defensive, like upgrade/2.3.1.php and 2.3.3.php: a failure here must not abort
 * the module upgrade; the worst case is the previous state (table without index).
 *
 * @param \Trusteed $module
 */
function upgrade_module_2_3_4($module): bool
{
    try {
        \Trusteed\Storage\CartAgentStorage::ensureIndexes();
    } catch (\Throwable $e) {
        if (class_exists('PrestaShopLogger')) {
            \PrestaShopLogger::addLog(
                '[trusteed.upgrade.2.3.4] ensureIndexes() failed: ' . $e->getMessage(),
                3, // \PrestaShopLogger::LOG_SEVERITY_ERROR
                null,
                null,
                null,
                true
            );
        }
    }

    return true;
}
