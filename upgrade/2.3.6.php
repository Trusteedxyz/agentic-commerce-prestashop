<?php

declare(strict_types=1);

/**
 * Upgrade script to 2.3.6 — herramientas WebMCP del checkout.
 *
 *  - `trusteed_agent_carts.channel`: la marca de canal puesta por el servidor (aditiva e idempotente).
 *  - hook `displayHeader`: inyecta las tools sólo en la página de checkout y sólo con el ajuste
 *    `TRUSTEED_WEBMCP_CHECKOUT_TOOLS` en `on` (defecto `off`).
 *
 * Defensivo, como 2.3.1/2.3.3/2.3.4: un fallo no aborta la actualización; el peor caso es el estado anterior.
 *
 * @param \Trusteed $module
 */
function upgrade_module_2_3_6($module): bool
{
    try {
        \Trusteed\Storage\CartAgentStorage::ensureChannelColumn();
        $module->registerHook('displayHeader');
    } catch (\Throwable $e) {
        if (class_exists('PrestaShopLogger')) {
            \PrestaShopLogger::addLog('[trusteed.upgrade.2.3.6] failed: ' . $e->getMessage(), 3, null, null, null, true);
        }
    }

    return true;
}
