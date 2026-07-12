<?php

declare(strict_types=1);

/**
 * PsContextStubs.php — minimal, test-configurable PrestaShop globals so the REAL
 * payment tools (which call MerchantContextGuard::enforce → \Context + \Configuration)
 * can be unit-tested without a live PrestaShop install.
 *
 * Tests set the authoritative merchant id via TestPsState::$merchantId and the
 * active shop via TestPsState::$shopId. MerchantResolver is NOT loaded here (it is
 * `if (!defined('_PS_VERSION_')) exit`-guarded and pulls more globals); instead the
 * guard falls back to \Configuration::get('TRUSTEED_CEL_MERCHANT_ID') when the
 * MerchantResolver class is absent, which these stubs satisfy.
 */

namespace {
    if (!class_exists('TestPsState')) {
        final class TestPsState
        {
            public static string $merchantId = '';
            public static int $shopId = 1;

            public static function reset(): void
            {
                self::$merchantId = '';
                self::$shopId = 1;
            }
        }
    }

    if (!class_exists('Configuration')) {
        class Configuration
        {
            /** @return mixed */
            public static function get(string $key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)
            {
                if ($key === 'TRUSTEED_CEL_MERCHANT_ID') {
                    return TestPsState::$merchantId;
                }
                return $default;
            }
        }
    }

    if (!class_exists('Context')) {
        final class Context
        {
            public ?object $shop = null;

            private static ?Context $instance = null;

            public static function getContext(): Context
            {
                if (self::$instance === null) {
                    self::$instance = new self();
                }
                self::$instance->shop = (object) ['id' => TestPsState::$shopId];
                return self::$instance;
            }
        }
    }

    // ─── PS permission stubs (for testing the REAL PermissionGuard::hasAccess) ──

    if (!class_exists('TestPsPermissions')) {
        final class TestPsPermissions
        {
            /**
             * Permission token (e.g. "ADMINORDERS") → granted bool, keyed by the
             * fake tab id this stub assigns.
             *
             * @var array<string,bool>
             */
            public static array $granted = [];

            public static function reset(): void
            {
                self::$granted = [];
            }

            /** Grant a PS permission token for the test employee. */
            public static function grant(string $token): void
            {
                self::$granted[$token] = true;
            }
        }
    }

    if (!class_exists('Tab')) {
        final class Tab
        {
            /**
             * Maps the permission token to a deterministic non-zero tab id so the
             * guard's `Tab::getIdFromClassName()` path resolves. The token is
             * stashed in a registry so Access::isGranted can reverse-map it.
             *
             * @return int|false
             */
            public static function getIdFromClassName(string $className)
            {
                // Deterministic positive id derived from the token.
                $id = abs(crc32($className)) % 100000 + 1;
                TestTabRegistry::$idToToken[$id] = $className;
                return $id;
            }
        }
    }

    if (!class_exists('TestTabRegistry')) {
        final class TestTabRegistry
        {
            /** @var array<int,string> */
            public static array $idToToken = [];
        }
    }

    if (!class_exists('Access')) {
        final class Access
        {
            /** Mirrors PS Access::isGranted('view', $tabId, $profileId). */
            public static function isGranted(string $action, int $tabId, int $profileId): bool
            {
                $token = TestTabRegistry::$idToToken[$tabId] ?? '';
                return $token !== '' && (TestPsPermissions::$granted[$token] ?? false);
            }
        }
    }

    if (!class_exists('Employee')) {
        final class Employee
        {
            public ?int $id_profile = 1;
            public ?int $id = null;

            public function __construct(int $id = 0)
            {
                $this->id = $id > 0 ? $id : null;
                $this->id_profile = $id > 0 ? 1 : null;
            }
        }
    }
}
