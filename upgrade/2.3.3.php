<?php

declare(strict_types=1);

/**
 * Upgrade script to 2.3.3: unified back-office menu.
 *
 * The Trusteed menu shows seven entries (Home, My sales, Agents, My rules,
 * Payment methods, Trust, Settings), in the same order and wording as Shopify, WooCommerce and Magento. A store
 * that installed an earlier version has the old ten-entry menu stored in the `tab` / `tab_lang` tables, and PrestaShop
 * does not re-read the module's tab list on update, so this script brings it in line:
 *
 *  - renames every tab per language (the old Spanish labels),
 *  - creates the new "Payment methods" tab (AdminTrusteedPaymentMethods),
 *  - hides Merchant Center, Security and Agent Readiness (`active = 0`; their
 *    controllers keep resolving by URL and from the SPA's internal links),
 *  - rewrites the positions so the order matches.
 *
 * Idempotent: it delegates to Trusteed::syncAdminTabs(), which only sets state
 * (names, active, position) and creates what is missing, so running it twice, or
 * on a store that already installed 2.3.3 from scratch, changes nothing. It never
 * renames a `class_name`: that is PrestaShop's permission key.
 *
 * Defensive on purpose, like upgrade/2.3.1.php: a failure here must not abort the
 * module's upgrade; the worst case is the old menu staying as it was.
 *
 * Not verified against a real PrestaShop "Upgrade" flow; unit tested against a
 * stub module class only (tests/upgrade/Upgrade233Test.php).
 *
 * @param \Trusteed $module
 */
function upgrade_module_2_3_3($module): bool
{
    if (!method_exists($module, 'syncAdminTabs')) {
        return true;
    }

    try {
        $module->syncAdminTabs();
    } catch (\Throwable $e) {
        if (class_exists('PrestaShopLogger')) {
            \PrestaShopLogger::addLog(
                '[trusteed.upgrade.2.3.3] syncAdminTabs() failed: ' . $e->getMessage(),
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
