<?php

declare(strict_types=1);

/**
 * Upgrade script to 2.3.4 — índice `idx_created_at` de `trusteed_agent_carts`.
 *
 * Revisión de seguridad 2026-10-10 (B5): el índice se añadió a `createTable()`,
 * que usa `CREATE TABLE IF NOT EXISTS`, así que las tiendas ya instaladas no lo
 * tienen y `CartAgentStorage::purgeExpired()` hace un barrido completo en cada
 * alta agéntica. Este script lo crea si falta (aditivo e idempotente: comprueba
 * `SHOW INDEX` antes del `ALTER`).
 *
 * Defensivo, como upgrade/2.3.1.php y 2.3.3.php: un fallo aquí no debe abortar la
 * actualización del módulo; el peor caso es el estado anterior (tabla sin índice).
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
