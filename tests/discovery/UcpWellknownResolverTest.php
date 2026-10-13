<?php

declare(strict_types=1);

/**
 * UCP `.well-known` discovery resolver (PrestaShop).
 *
 * Unit-tests the REAL Trusteed\Discovery\UcpWellknownResolver against a
 * configurable \Configuration stub. Verifies:
 *   - default OFF (no flag → disabled),
 *   - central per-store URL composition from TRUSTEED_API_BASE + merchant id,
 *   - fail-closed (empty) URL when the merchant id is missing,
 *   - storefront REFERENCES, never DUPLICATES (URL output, not a manifest body).
 *
 * The stub lives in the global namespace and is configurable per-test via
 * TestUcpConfigState, matching the inline-stub pattern used by the enforcement
 * suite (each file defines the PS globals it needs, guarded by class_exists).
 */

namespace {
    if (!defined('_PS_VERSION_')) {
        define('_PS_VERSION_', '8.2.0');
    }

    if (!class_exists('TestUcpConfigState', false)) {
        final class TestUcpConfigState
        {
            /** @var array<string,string> */
            public static array $values = [];

            public static function reset(): void
            {
                self::$values = [];
            }
        }
    }

    if (!class_exists('Configuration', false)) {
        class Configuration
        {
            /** @return mixed */
            public static function get(string $key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)
            {
                return TestUcpConfigState::$values[$key] ?? $default;
            }
        }
    }
}

namespace Trusteed\Tests\Discovery {

    use Trusteed\Discovery\UcpWellknownResolver;
    use PHPUnit\Framework\TestCase;
    use TestUcpConfigState;

    require_once __DIR__ . '/../../src/Discovery/UcpWellknownResolver.php';

    final class UcpWellknownResolverTest extends TestCase
    {
        protected function setUp(): void
        {
            TestUcpConfigState::reset();
            // When the whole suite runs, the bootstrap has already declared
            // `\Configuration` (tests/stubs/PsContextStubs.php), which reads
            // `TestPsState::$config`. This file's stub is skipped by the
            // `class_exists` guard, so the tests that set the flag wrote to a
            // store nobody read. The two stores are linked so the test measures
            // the same thing in isolation and in the full suite.
            if (class_exists('TestPsState', false)) {
                \TestPsState::$config = &TestUcpConfigState::$values;
                // Another test in the suite may have left its merchant id set;
                // the stub's special case would take precedence over it.
                $this->merchantIdPrevio = \TestPsState::$merchantId;
                \TestPsState::$merchantId = '';
            }
        }

        private string $merchantIdPrevio = '';

        protected function tearDown(): void
        {
            if (class_exists('TestPsState', false)) {
                $vacio = [];
                \TestPsState::$config = &$vacio;
                \TestPsState::$merchantId = $this->merchantIdPrevio;
            }
        }

        public function testDisabledByDefault(): void
        {
            // No flag configured → OFF (current behavior, no redirect route).
            $this->assertFalse(UcpWellknownResolver::isEnabled());
        }

        public function testEnabledWhenFlagIsOne(): void
        {
            TestUcpConfigState::$values['TRUSTEED_UCP_WELLKNOWN_ENABLED'] = '1';
            $this->assertTrue(UcpWellknownResolver::isEnabled());
        }

        public function testEnabledWhenFlagIsTrueString(): void
        {
            TestUcpConfigState::$values['TRUSTEED_UCP_WELLKNOWN_ENABLED'] = 'true';
            $this->assertTrue(UcpWellknownResolver::isEnabled());
        }

        public function testDisabledForAnyOtherValue(): void
        {
            TestUcpConfigState::$values['TRUSTEED_UCP_WELLKNOWN_ENABLED'] = '0';
            $this->assertFalse(UcpWellknownResolver::isEnabled());

            TestUcpConfigState::$values['TRUSTEED_UCP_WELLKNOWN_ENABLED'] = 'yes';
            $this->assertFalse(UcpWellknownResolver::isEnabled());
        }

        public function testTargetUrlComposesCentralPerStoreEndpoint(): void
        {
            TestUcpConfigState::$values['TRUSTEED_API_BASE']     = 'https://api.trusteed.xyz';
            TestUcpConfigState::$values['TRUSTEED_CEL_MERCHANT_ID'] = 'acme-store';

            $this->assertSame(
                'https://api.trusteed.xyz/.well-known/ucp?store=acme-store',
                UcpWellknownResolver::targetUrl()
            );
        }

        public function testTargetUrlDefaultsApiBaseWhenUnset(): void
        {
            TestUcpConfigState::$values['TRUSTEED_CEL_MERCHANT_ID'] = 'acme-store';

            $this->assertSame(
                'https://api.trusteed.xyz/.well-known/ucp?store=acme-store',
                UcpWellknownResolver::targetUrl()
            );
        }

        public function testTargetUrlTrimsTrailingSlash(): void
        {
            TestUcpConfigState::$values['TRUSTEED_API_BASE']     = 'https://api.trusteed.xyz/';
            TestUcpConfigState::$values['TRUSTEED_CEL_MERCHANT_ID'] = 'acme-store';

            $this->assertSame(
                'https://api.trusteed.xyz/.well-known/ucp?store=acme-store',
                UcpWellknownResolver::targetUrl()
            );
        }

        public function testTargetUrlRawencodesMerchantId(): void
        {
            TestUcpConfigState::$values['TRUSTEED_API_BASE']     = 'https://api.trusteed.xyz';
            TestUcpConfigState::$values['TRUSTEED_CEL_MERCHANT_ID'] = 'a b/c';

            $this->assertSame(
                'https://api.trusteed.xyz/.well-known/ucp?store=a%20b%2Fc',
                UcpWellknownResolver::targetUrl()
            );
        }

        public function testTargetUrlFailsClosedWithoutMerchantId(): void
        {
            // Fail-closed: no merchant id means no redirect target (404).
            TestUcpConfigState::$values['TRUSTEED_API_BASE'] = 'https://api.trusteed.xyz';
            $this->assertSame('', UcpWellknownResolver::targetUrl());
        }

        public function testTargetUrlIsReferenceNotManifestBody(): void
        {
            // REFERENCE, never DUPLICATE: bare URL only.
            TestUcpConfigState::$values['TRUSTEED_API_BASE']     = 'https://api.trusteed.xyz';
            TestUcpConfigState::$values['TRUSTEED_CEL_MERCHANT_ID'] = 'acme-store';

            $url = UcpWellknownResolver::targetUrl();
            $this->assertStringStartsWith('https://', $url);
            $this->assertStringNotContainsString('{', $url);
            $this->assertStringNotContainsString('capabilities', $url);
        }
    }
}
