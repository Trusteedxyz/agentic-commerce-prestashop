<?php

declare(strict_types=1);

/**
 * Upgrade script to 2.3.1 — GHSA-2j2x-5q52-g48m.
 *
 * PrestaShop copies a module's `override/` files into the shop's own
 * `override/` tree ONLY when `Module::install()` runs. There is no automatic
 * re-copy when a module's files change on disk between releases. Before this
 * release the module shipped NO `upgrade/` directory at all, so a store that
 * had already installed 2.3.0 or earlier kept running the old, vulnerable
 * `PaymentModule.php` override after updating the module's files on disk —
 * until an administrator either reinstalled the module or (now) clicked
 * "Upgrade" in the PrestaShop admin, which core auto-discovers this file and
 * calls `upgrade_module_2_3_1($module)` for.
 *
 * Guarded and defensive on purpose: a failure to refresh the override must
 * never abort the rest of the module's upgrade (DB migrations, etc.) — the
 * override staying stale is exactly the state before this release, not a
 * regression this script could cause. See the security advisory's "Known
 * limitation" for what remains unverified: this has only been unit-tested
 * against a stub module class (tests/upgrade/Upgrade231Test.php), not
 * against a real PrestaShop "Upgrade" admin flow — no PS runtime is
 * available in this environment to exercise that end to end.
 *
 * @param \Trusteed $module
 */
function upgrade_module_2_3_1($module): bool
{
    if (method_exists($module, 'installOverrides')) {
        try {
            $module->installOverrides();
        } catch (\Throwable $e) {
            if (class_exists('PrestaShopLogger')) {
                \PrestaShopLogger::addLog(
                    '[trusteed.upgrade.2.3.1] installOverrides() failed: ' . $e->getMessage(),
                    3, // \PrestaShopLogger::LOG_SEVERITY_ERROR
                    null,
                    null,
                    null,
                    true
                );
            }
        }
    }

    return true;
}
