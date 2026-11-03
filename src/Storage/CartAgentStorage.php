<?php

declare(strict_types=1);

namespace Trusteed\Storage;

/**
 * Transient storage for agentDid per cart, bridging enforcement (ValidateOrderHook)
 * to order event emission (OrderEventsEmitter) for R023 agentIdHash propagation.
 *
 * Schema (created by module install):
 *   trusteed_agent_carts (id_cart INT PK, agent_did VARCHAR(255), channel VARCHAR(16) NULL, created_at DATETIME)
 *
 * `channel` (2.3.6) es la marca de canal puesta por el servidor, p. ej. `webmcp` cuando un agente
 * de navegador opera el checkout con las tools de la página. Una fila con sólo canal tiene
 * `agent_did = ''` (la columna es NOT NULL): `get()` lo devuelve como ausente, así que ningún
 * emisor fabrica un `agentDid` falso.
 *
 * RETENCIÓN (77ab260d4, DSAR). El DID se guarda en claro, así que no puede
 * quedarse para siempre. No se borra en la primera lectura porque lo leen tres
 * emisores en momentos distintos (alta del pedido, envío/entrega, reembolso):
 * la fila vive {@see RETENTION_DAYS} días desde su último alta; pasado ese plazo
 * `get()` ya no la devuelve y cada `set()` purga lo vencido. `delete()` queda
 * para el borrado puntual (p. ej. una solicitud DSAR concreta).
 */
final class CartAgentStorage
{
    private const TABLE = _DB_PREFIX_ . 'trusteed_agent_carts';

    /** Días que se conserva el DID de un carrito (decisión de retención: ver PENDIENTE). */
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

    /** Canales de agente que se guardan; cualquier otro valor se ignora. */
    public const CHANNELS = ['webmcp'];

    /**
     * Marca el carrito con un canal sin tocar un DID ya guardado. Fila nueva: `agent_did = ''`.
     * Devuelve false si no quedó escrita (canal desconocido, columna ausente, error de BD).
     */
    public static function markChannel(int $cartId, string $channel): bool
    {
        if ($cartId <= 0 || !in_array($channel, self::CHANNELS, true)) {
            return false;
        }
        self::purgeExpired();

        return (bool) \Db::getInstance()->execute(
            'INSERT INTO `' . self::TABLE . '` (`id_cart`, `agent_did`, `channel`, `created_at`) '
            . 'VALUES (' . (int) $cartId . ', \'\', \'' . pSQL($channel) . '\', NOW()) '
            . 'ON DUPLICATE KEY UPDATE `channel` = VALUES(`channel`), `created_at` = NOW()'
        );
    }

    public static function getChannel(int $cartId): ?string
    {
        $row = \Db::getInstance()->getRow(
            'SELECT `channel` FROM `' . self::TABLE . '` WHERE `id_cart` = ' . (int) $cartId
            . ' AND `created_at` >= DATE_SUB(NOW(), INTERVAL ' . self::RETENTION_DAYS . ' DAY)'
        );
        $channel = is_array($row) && isset($row['channel']) ? (string) $row['channel'] : '';

        return in_array($channel, self::CHANNELS, true) ? $channel : null;
    }

    public static function get(int $cartId): ?string
    {
        $db = \Db::getInstance();
        $row = $db->getRow(
            'SELECT `agent_did` FROM `' . self::TABLE . '` WHERE `id_cart` = ' . (int) $cartId
            . ' AND `created_at` >= DATE_SUB(NOW(), INTERVAL ' . self::RETENTION_DAYS . ' DAY)'
        );
        // '' = fila de sólo canal (markChannel): no hay DID.
        return is_array($row) && isset($row['agent_did']) && (string) $row['agent_did'] !== '' ? (string) $row['agent_did'] : null;
    }

    /** Borra lo que superó la retención. */
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
            . '`channel` VARCHAR(16) NULL, '
            . '`created_at` DATETIME NOT NULL, '
            . 'PRIMARY KEY (`id_cart`), '
            . 'KEY `idx_created_at` (`created_at`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * B5 (revisión 2026-10-10): `CREATE TABLE IF NOT EXISTS` no añade el índice a
     * las tiendas que ya tenían la tabla. Creación idempotente (aditiva): sólo
     * ejecuta el ALTER si `idx_created_at` no existe. Sin él, `purgeExpired()`
     * recorre la tabla entera en cada alta agéntica.
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

    /** 2.3.6: añade `channel` a las tablas ya creadas. Idempotente (SHOW COLUMNS antes del ALTER). */
    public static function ensureChannelColumn(): bool
    {
        $db  = \Db::getInstance();
        $row = $db->getRow('SHOW COLUMNS FROM `' . self::TABLE . '` LIKE \'channel\'');
        if (is_array($row) && $row !== []) {
            return true;
        }

        return (bool) $db->execute('ALTER TABLE `' . self::TABLE . '` ADD COLUMN `channel` VARCHAR(16) NULL');
    }

    /** Called from module uninstall(). */
    public static function dropTable(): bool
    {
        return \Db::getInstance()->execute('DROP TABLE IF EXISTS `' . self::TABLE . '`');
    }
}
