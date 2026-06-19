<?php
/**
 * InstallTest — install/uninstall lifecycle for the AgenticMcpStores module.
 *
 * The previous version of this file was TAUTOLOGICAL: it asserted string
 * literals against themselves (e.g. `assertSame('agenticmcpstores',
 * 'agenticmcpstores')` and regexes over hand-written constants) so it could
 * never fail and tested nothing about the real module.
 *
 * A MEANINGFUL install/uninstall test requires a live PrestaShop kernel:
 * `Module::getInstanceByName('agenticmcpstores')->install()` touches
 * `Configuration`, `Db`, tab/controller registration and the `sql/install.php`
 * schema — none of which exist without a bootstrapped PS install. Standing one
 * up in CI is out of scope (no PS kernel, no DB), so rather than fake it we:
 *
 *   1. Self-SKIP every install/uninstall assertion when PS is not bootstrapped
 *      (the CI case), making the skip explicit and visible instead of a
 *      green-but-empty pass.
 *   2. Delegate the real, CI-resident coverage to TokenBrokerTest.php, which
 *      tests the only network-free, PrestaShop-independent production class
 *      (`AgenticMcpStores\Mvp\TokenBroker`) with no skips.
 *
 * When run inside a real PS environment (PHPUnit launched with the PrestaShop
 * test kernel on the include path, defining `_PS_ROOT_DIR_` and providing the
 * `Module` / `Configuration` classes), these tests execute the real
 * install → uninstall → install idempotency cycle.
 *
 * Run (CI, no PS — tests self-skip):
 *   vendor/bin/phpunit -c tests/phpunit.xml --testsuite unit
 */

namespace AgenticMcpStores\Tests\Unit;

use PHPUnit\Framework\TestCase;

class InstallTest extends TestCase
{
    /**
     * Skip the whole case unless a real PrestaShop kernel is on the include
     * path. We detect this by the presence of the `Module` class together with
     * the `_PS_ROOT_DIR_` marker that the PS test bootstrap defines — the test
     * bootstrap shipped with THIS module deliberately defines only
     * `_PS_VERSION_`, never `_PS_ROOT_DIR_`, so CI always lands in the skip.
     */
    protected function setUp(): void
    {
        if (!defined('_PS_ROOT_DIR_') || !class_exists('Module')) {
            $this->markTestSkipped(
                'PrestaShop kernel not bootstrapped — install/uninstall is only '
                . 'meaningful against a live PS install. Network-free coverage '
                . 'lives in TokenBrokerTest.php (the CI-resident real test).'
            );
        }
    }

    public function testInstallIsIdempotent(): void
    {
        /** @var \Module|false $module */
        $module = \Module::getInstanceByName('agenticmcpstores');
        $this->assertNotFalse($module, 'module instance must resolve');

        // install → uninstall → install must all succeed without error.
        $this->assertTrue($module->install(), 'first install must succeed');
        $this->assertTrue($module->uninstall(), 'uninstall must succeed');
        $this->assertTrue($module->install(), 're-install must succeed (idempotent)');
    }

    public function testInstallSeedsRequiredConfiguration(): void
    {
        /** @var \Module|false $module */
        $module = \Module::getInstanceByName('agenticmcpstores');
        $this->assertNotFalse($module);
        $this->assertTrue($module->install());

        // The default API base must be the production HTTPS host.
        $apiBase = \Configuration::get('AGENTICMCP_API_BASE');
        $this->assertNotEmpty($apiBase, 'AGENTICMCP_API_BASE must be seeded on install');
        $this->assertStringStartsWith('https://', $apiBase);
    }

    public function testUninstallRemovesConfiguration(): void
    {
        /** @var \Module|false $module */
        $module = \Module::getInstanceByName('agenticmcpstores');
        $this->assertNotFalse($module);
        $this->assertTrue($module->install());
        $this->assertTrue($module->uninstall());

        $this->assertFalse(
            \Configuration::hasKey('AGENTICMCP_API_BASE'),
            'uninstall must remove the module Configuration keys'
        );
    }
}
