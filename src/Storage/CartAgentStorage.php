<?php

declare(strict_types=1);

namespace Trusteed\Storage;

/**
 * Transient storage for agentDid per cart, bridging enforcement (ValidateOrderHook)
 * to order event emission (OrderEventsEmitter) for R023 agentIdHash propagation.
 *
 * Schema (created by module install):
 *   trusteed_agent_carts (id_cart INT PK, agent_did VARCHAR(255), created_at DATETIME)
 *
 * RETENTION: the DID is stored in clear text, so it cannot be kept forever. It
 * is not deleted on first read because three emitters read it at different
 * times (order creation, shipping/delivery, refund): a row lives
 * {@see RETENTION_DAYS} days from its last write; after that `get()` no longer
 * returns it and every `set()` purges expired rows. `delete()` remains for
 * targeted removal (e.g. a specific data subject request).
 */
final class CartAgentStorage
{
    private const TABLE = _DB_PREFIX_ . 'trusteed_agent_carts';

    /** Days a cart's DID is kept (retention period; may change). */
    public const RETENTION_DAYS = 30;

    public static function set(int $cartId, string $agentDid): void
    {
        self::purgeExpired();
        $db = \Db::getInstance();
        $db->execute(
            'INSERT INTO `' . self::TABLE . '` (`id_cart`, `agent_did`, `created_at`) '
            . 'VALUES (' . (int) $cartId . ', \'' . pSQL($agentDid) . '\', NOW()) '
            . 'ON DUPLICATE KEY UPDATE `agent_did` = VALUES(`agent_did`), `created_at` = NOW()'
        );
    }

    public static function get(int $cartId): ?string
    {
        $db = \Db::getInstance();
        $row = $db->getRow(
            'SELECT `agent_did` FROM `' . self::TABLE . '` WHERE `id_cart` = ' . (int) $cartId
            . ' AND `created_at` >= DATE_SUB(NOW(), INTERVAL ' . self::RETENTION_DAYS . ' DAY)'
        );
        return is_array($row) && isset($row['agent_did']) ? (string) $row['agent_did'] : null;
    }

    /** Deletes rows past the retention period. */
    public static function purgeExpired(): void
    {
        \Db::getInstance()->execute(
            'DELETE FROM `' . self::TABLE . '` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL '
            . self::RETENTION_DAYS . ' DAY)'
        );
    }

    public static function delete(int $cartId): void
    {
        \Db::getInstance()->execute(
            'DELETE FROM `' . self::TABLE . '` WHERE `id_cart` = ' . (int) $cartId
        );
    }

    /** Called from module install(). */
    public static function createTable(): bool
    {
        return \Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` ('
            . '`id_cart` INT UNSIGNED NOT NULL, '
            . '`agent_did` VARCHAR(255) NOT NULL, '
            . '`created_at` DATETIME NOT NULL, '
            . 'PRIMARY KEY (`id_cart`), '
            . 'KEY `idx_created_at` (`created_at`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * `CREATE TABLE IF NOT EXISTS` does not add the index to shops that already
     * had the table. Idempotent and additive: it only runs the ALTER if
     * `idx_created_at` does not exist. Without it, `purgeExpired()` scans the
     * whole table on every agentic write.
     */
    public static function ensureIndexes(): bool
    {
        $db  = \Db::getInstance();
        $row = $db->getRow(
            'SHOW INDEX FROM `' . self::TABLE . '` WHERE `Key_name` = \'idx_created_at\''
        );
        if (is_array($row) && $row !== []) {
            return true;
        }

        return (bool) $db->execute(
            'ALTER TABLE `' . self::TABLE . '` ADD KEY `idx_created_at` (`created_at`)'
        );
    }

    /** Called from module uninstall(). */
    public static function dropTable(): bool
    {
        return \Db::getInstance()->execute('DROP TABLE IF EXISTS `' . self::TABLE . '`');
    }
}
