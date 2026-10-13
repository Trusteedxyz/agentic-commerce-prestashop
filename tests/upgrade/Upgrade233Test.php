<?php

declare(strict_types=1);

/**
 * Upgrade233Test: unified back-office menu.
 *
 * Two layers:
 *  1. upgrade/2.3.3.php delegates to Trusteed::syncAdminTabs() and never aborts the
 *     module upgrade (unit-tested against a stub module, no PS runtime here).
 *  2. A static gate on `ADMIN_TABS` in trusteed.php: the seven visible entries, their
 *     order and wording, the three hidden pages, and that no `class_name` was renamed
 *     (it is PrestaShop's permission key) and each one still has its controller.
 *
 * Run:
 *   php phpunit.phar tests/upgrade/Upgrade233Test.php
 */

namespace {
    if (!defined('_PS_VERSION_')) {
        define('_PS_VERSION_', '8.2.0');
    }

    class StubTrusteedTabsModule
    {
        public int $syncCalls = 0;
        public bool $syncShouldThrow = false;

        public function syncAdminTabs(): bool
        {
            ++$this->syncCalls;
            if ($this->syncShouldThrow) {
                throw new \RuntimeException('tab sync boom');
            }

            return true;
        }
    }
}

namespace Trusteed\Tests\Upgrade {

    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../../upgrade/2.3.3.php';

    final class Upgrade233Test extends TestCase
    {
        private const MODULE_ROOT = __DIR__ . '/../..';

        /** @return list<array{class_name:string,label:string,label_en:string,visible:bool}> */
        private function adminTabs(): array
        {
            $source = (string) file_get_contents(self::MODULE_ROOT . '/trusteed.php');
            preg_match_all(
                "/\['class_name' => '(AdminTrusteed\w+)',\s*'label' => '([^']+)',\s*'label_en' => '([^']+)',\s*'visible' => (true|false)\]/u",
                $source,
                $m,
                PREG_SET_ORDER
            );

            return array_map(static fn (array $r): array => [
                'class_name' => $r[1],
                'label' => $r[2],
                'label_en' => $r[3],
                'visible' => $r[4] === 'true',
            ], $m);
        }

        public function testUpgradeSyncsTheTabs(): void
        {
            $module = new \StubTrusteedTabsModule();

            self::assertTrue(upgrade_module_2_3_3($module));
            self::assertSame(1, $module->syncCalls);
        }

        public function testUpgradeIsRepeatableAndNeverAbortsWhenTheSyncThrows(): void
        {
            $module = new \StubTrusteedTabsModule();
            $module->syncShouldThrow = true;

            self::assertTrue(upgrade_module_2_3_3($module));
            self::assertTrue(upgrade_module_2_3_3($module));
            self::assertSame(2, $module->syncCalls);
        }

        public function testUpgradeToleratesAModuleWithoutTheSyncMethod(): void
        {
            self::assertTrue(upgrade_module_2_3_3(new class {
            }));
        }

        public function testTheModuleExposesAPublicSyncAdminTabs(): void
        {
            $source = (string) file_get_contents(self::MODULE_ROOT . '/trusteed.php');
            self::assertMatchesRegularExpression('/public function syncAdminTabs\(\): bool/', $source);
            self::assertMatchesRegularExpression("/\\\$this->version\s*=\s*'2\.3\.\d+';/", $source);
        }

        public function testVisibleMenuIsTheSevenUnifiedEntriesInOrder(): void
        {
            $visible = array_values(array_filter($this->adminTabs(), static fn (array $t): bool => $t['visible']));

            self::assertSame(
                ['Home', 'My sales', 'Agents', 'My rules', 'Payment methods', 'Trust', 'Settings'],
                array_column($visible, 'label_en')
            );
            self::assertSame(
                ['Inicio', 'Mis ventas', 'Agentes', 'Mis reglas', 'Métodos de pago', 'Confianza', 'Ajustes'],
                array_column($visible, 'label')
            );
            self::assertSame(
                [
                    'AdminTrusteedInicio', 'AdminTrusteedVentas', 'AdminTrusteedAgentes', 'AdminTrusteedReglas',
                    'AdminTrusteedPaymentMethods', 'AdminTrusteedTrustCenter', 'AdminTrusteedConfig',
                ],
                array_column($visible, 'class_name')
            );
        }

        public function testPagesThatLeftTheMenuStayRegisteredButHidden(): void
        {
            $hidden = array_column(
                array_filter($this->adminTabs(), static fn (array $t): bool => !$t['visible']),
                'class_name'
            );

            self::assertEqualsCanonicalizing(
                ['AdminTrusteedMerchantCenter', 'AdminTrusteedSeguridad', 'AdminTrusteedAgentReadiness'],
                $hidden
            );
        }

        public function testNoClassNameWasRenamedAndEveryOneHasItsController(): void
        {
            $classNames = array_column($this->adminTabs(), 'class_name');

            // The ten pre-2.3.3 class_names (permission keys) must all still be there.
            foreach ([
                'AdminTrusteedInicio', 'AdminTrusteedTrustCenter', 'AdminTrusteedMerchantCenter',
                'AdminTrusteedVentas', 'AdminTrusteedReglas', 'AdminTrusteedSeguridad',
                'AdminTrusteedAgentes', 'AdminTrusteedAgentReadiness', 'AdminTrusteedConfig',
            ] as $legacy) {
                self::assertContains($legacy, $classNames, "$legacy was renamed or removed.");
            }
            self::assertCount(10, $classNames);

            foreach ($classNames as $class) {
                self::assertFileExists(
                    self::MODULE_ROOT . '/controllers/admin/' . $class . 'Controller.php',
                    "$class has no controller."
                );
            }
        }
    }
}
