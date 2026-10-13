<?php

declare(strict_types=1);

namespace Trusteed\Tests\Upgrade;

require_once __DIR__ . '/../enforcement/ValidateOrderHookFlowHarness.php';
require_once __DIR__ . '/../../src/Storage/CartAgentStorage.php';
require_once __DIR__ . '/../../upgrade/2.3.4.php';

use PHPUnit\Framework\TestCase;

/** The index reaches already-installed shops, without being duplicated. */
final class Upgrade234Test extends TestCase
{
    protected function setUp(): void
    {
        \Db::$executed = [];
        \Db::$queried  = [];
    }

    public function testCreatesTheIndexWhenItIsMissing(): void
    {
        self::assertTrue(upgrade_module_2_3_4(new \stdClass()));

        self::assertCount(1, \Db::$queried);
        self::assertStringContainsString("SHOW INDEX", \Db::$queried[0]);
        self::assertStringContainsString('idx_created_at', \Db::$queried[0]);
        self::assertCount(1, \Db::$executed);
        self::assertStringContainsString('ADD KEY `idx_created_at` (`created_at`)', \Db::$executed[0]);
        self::assertStringStartsWith('ALTER TABLE', \Db::$executed[0]);
    }

    public function testModuleVersionIsAtLeastTheUpgradeScript(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../trusteed.php');
        self::assertMatchesRegularExpression("/\\\$this->version\\s*=\\s*'2\\.3\\.4'/", $src);
    }

    public function testFreshInstallsStillCreateTheIndex(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Storage/CartAgentStorage.php');
        self::assertStringContainsString('KEY `idx_created_at`', $src);
    }
}
