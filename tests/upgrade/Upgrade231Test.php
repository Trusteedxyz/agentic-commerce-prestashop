<?php

declare(strict_types=1);

/**
 * Upgrade231Test — GHSA-2j2x-5q52-g48m, D4 (deployment).
 *
 * PrestaShop copies a module's `override/` files into the shop's own
 * `override/` tree ONLY when `Module::install()` runs (via
 * `installOverrides()`). There is no automatic re-copy when a module's files
 * are updated in place on disk. Before this release the module shipped no
 * `upgrade/` directory at all, so a store that had already installed 2.3.0 or
 * earlier would keep running the OLD, vulnerable `PaymentModule.php` override
 * after updating the module's files — until an administrator reinstalled the
 * module outright.
 *
 * `upgrade/2.3.1.php` makes PrestaShop's "Upgrade" button in the admin
 * (which core auto-discovers and runs `upgrade_module_2_3_1($module)` for)
 * re-run `installOverrides()`, refreshing the override on disk.
 *
 * NOT verified against a real PrestaShop "Upgrade" admin flow — no PS
 * runtime is available in this environment. Only unit-tested against a stub
 * `Trusteed` module class. See the security advisory's "Known limitation".
 *
 * Run:
 *   docker run --rm -v $(pwd):/app -w /app php:8.3-cli \
 *     php vendor/bin/phpunit tests/upgrade/Upgrade231Test.php
 */

namespace {
    if (!defined('_PS_VERSION_')) {
        define('_PS_VERSION_', '8.2.0');
    }

    class StubTrusteedModule
    {
        public bool $installOverridesCalled = false;
        public bool $installOverridesShouldThrow = false;
        public bool $installOverridesReturn = true;

        public function installOverrides(): bool
        {
            $this->installOverridesCalled = true;
            if ($this->installOverridesShouldThrow) {
                throw new \RuntimeException('override install boom');
            }

            return $this->installOverridesReturn;
        }
    }
}

namespace Trusteed\Tests\Upgrade {

    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../../upgrade/2.3.1.php';

    final class Upgrade231Test extends TestCase
    {
        public function testCallsInstallOverridesWhenMethodExists(): void
        {
            $module = new \StubTrusteedModule();

            $result = upgrade_module_2_3_1($module);

            $this->assertTrue($result);
            $this->assertTrue($module->installOverridesCalled);
        }

        public function testNeverAbortsTheUpgradeWhenInstallOverridesThrows(): void
        {
            $module = new \StubTrusteedModule();
            $module->installOverridesShouldThrow = true;

            // The upgrade as a whole must still be reported as successful —
            // a failure to refresh the override must not roll back the rest
            // of the module's file/DB upgrade.
            $result = upgrade_module_2_3_1($module);

            $this->assertTrue($result);
        }

        public function testDoesNotCallMissingInstallOverridesMethod(): void
        {
            // A module object without installOverrides() at all (defensive:
            // some PS core forks/mocks may lack it) must not fatal.
            $module = new class {
            };

            $result = upgrade_module_2_3_1($module);

            $this->assertTrue($result);
        }
    }
}
