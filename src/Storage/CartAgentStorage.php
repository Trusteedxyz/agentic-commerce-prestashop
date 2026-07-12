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
 * Rows are deleted after the first read to keep the table lean.
 */
final class CartAgentStorage
{
    private const TABLE = _DB_PREFIX_ . 'trusteed_agent_carts';

    public static function set(int $cartId, string $agentDid): void
    {
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
        );
        return is_array($row) && isset($row['agent_did']) ? (string) $row['agent_did'] : null;
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
            . 'PRIMARY KEY (`id_cart`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /** Called from module uninstall(). */
    public static function dropTable(): bool
    {
        return \Db::getInstance()->execute('DROP TABLE IF EXISTS `' . self::TABLE . '`');
    }
}
