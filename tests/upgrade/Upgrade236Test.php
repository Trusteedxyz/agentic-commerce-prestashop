<?php

declare(strict_types=1);

namespace Trusteed\Tests\Upgrade;

require_once __DIR__ . '/../enforcement/ValidateOrderHookFlowHarness.php';
require_once __DIR__ . '/../../src/Storage/CartAgentStorage.php';
require_once __DIR__ . '/../../upgrade/2.3.6.php';

use PHPUnit\Framework\TestCase;

/** Task 7 — la columna `channel` y el hook de cabecera llegan a las tiendas ya instaladas. */
final class Upgrade236Test extends TestCase
{
    protected function setUp(): void
    {
        \Db::$executed = [];
        \Db::$queried  = [];
        \Db::$rowToReturn = null;
    }

    public function testAddsTheChannelColumnAndRegistersTheHeaderHook(): void
    {
        $module = new class () {
            /** @var list<string> */
            public array $hooks = [];
            public function registerHook($h): bool { $this->hooks[] = $h; return true; }
        };

        self::assertTrue(upgrade_module_2_3_6($module));
        self::assertStringContainsString('ADD COLUMN `channel` VARCHAR(16) NULL', \Db::$executed[0]);
        self::assertSame(['displayHeader'], $module->hooks);
    }

    public function testModuleVersionIsAtLeastTheUpgradeScript(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../trusteed.php');
        self::assertSame(1, preg_match("/\\\$this->version\\s*=\\s*'([0-9.]+)'/", $src, $m));
        self::assertTrue(version_compare($m[1], '2.3.6', '>='), $m[1]);
    }
}
