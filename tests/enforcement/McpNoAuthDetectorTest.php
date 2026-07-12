<?php

declare(strict_types=1);

/**
 * McpNoAuthDetectorTest — PHPUnit 10 tests for spec-048 W1.1 PrestaShop
 * MCP "No-Auth" mode detector (src/Enforcement/McpNoAuthDetector.php).
 *
 * PrestaShop 9.x ships the official MCP server addon (ps_mcp_server, addon
 * 96617). That server can be configured in a "No-Auth" mode — a fail-open
 * foot-gun that lets any agent invoke webservice/MCP operations without an
 * authentication key, undermining Trusteed agent-identity binding.
 *
 * The detector must:
 *   - classify the PS MCP auth posture from Configuration (no live PS kernel);
 *   - in No-Auth: surface an OBSERVE signal (admin notice flag + PS log) and
 *     NOT block, regardless of fallback mode by itself;
 *   - in Token mode: no-op (no notice, no exception);
 *   - in ENFORCE (strict) mode + No-Auth + unknown agent: fail-closed via the
 *     existing PrestaShopException enforce path.
 *
 * Coverage:
 *   MNA-1  classifyAuthMode → NO_AUTH for explicit none/no-auth/disabled/open
 *   MNA-2  classifyAuthMode → TOKEN for token/bearer/apikey/oauth
 *   MNA-3  classifyAuthMode → NO_AUTH when MCP enabled but no auth key present
 *   MNA-4  classifyAuthMode → UNKNOWN when MCP server is not installed/enabled
 *   MNA-5  detect() in No-Auth sets the admin notice flag (observe, no throw)
 *   MNA-6  detect() in Token mode is a no-op (no notice flag set, no throw)
 *   MNA-7  detect() does nothing when CEL is disabled for the shop
 *   MNA-8  detect() in strict + No-Auth + unknown agent throws (fail-closed)
 *   MNA-9  detect() in strict + No-Auth + KNOWN agent does NOT throw (observe)
 *   MNA-10 notice flag lifecycle: hasPendingNotice + acknowledge clears it
 *
 * Run from the module dir (PHP 8.1+ required; not available in agent sandbox):
 *   vendor/bin/phpunit tests/enforcement/McpNoAuthDetectorTest.php
 */

namespace {
    require_once __DIR__ . '/PsEnforcementStubs.php';

    if (!class_exists('Configuration')) {
        class Configuration
        {
            /** @var array<string, mixed> */
            private static array $store = [];

            public static function set(string $key, mixed $value): void
            {
                self::$store[$key] = $value;
            }

            public static function reset(): void
            {
                self::$store = [];
            }

            /**
             * Mirror of PS Configuration::get(key, idLang, idShopGroup, idShop).
             * The detector only relies on the first argument under test.
             *
             * @return mixed
             */
            public static function get(string $key, $idLang = null, $idShopGroup = null, $idShop = null)
            {
                return self::$store[$key] ?? false;
            }

            public static function updateValue(string $key, mixed $value): bool
            {
                self::$store[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
                return true;
            }
        }
    }

    if (!class_exists('PrestaShopLogger')) {
        class PrestaShopLogger
        {
            public const LOG_SEVERITY_INFORMATIVE = 1;
            public const LOG_SEVERITY_WARNING = 2;
            public const LOG_SEVERITY_ERROR = 3;

            public static function addLog(
                string $message,
                int $severity = 1,
                ?int $errorCode = null,
                ?string $objectType = null,
                ?int $objectId = null,
                bool $allowDuplicate = false
            ): void {
                // no-op for tests
            }
        }
    }

    if (!class_exists('PrestaShopException')) {
        class PrestaShopException extends \Exception
        {
        }
    }

    // ── Minimal Context stub so MerchantResolver::currentShopId() resolves to
    //    the global scope (0). No `shop` property → not a \Shop → returns 0. ──
    if (!class_exists('Context')) {
        class Context
        {
            public ?object $shop = null;

            private static ?Context $instance = null;

            public static function getContext(): Context
            {
                if (self::$instance === null) {
                    self::$instance = new self();
                }
                return self::$instance;
            }
        }
    }

    require_once __DIR__ . '/../../src/Enforcement/MerchantResolver.php';
    require_once __DIR__ . '/../../src/Enforcement/McpNoAuthDetector.php';
}

namespace Trusteed\Tests\Enforcement {

    use Trusteed\Enforcement\McpNoAuthDetector;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;

    final class McpNoAuthDetectorTest extends TestCase
    {
        protected function setUp(): void
        {
            \Configuration::reset();
            // CEL enabled by default for the global shop scope in most cases.
            \Configuration::set('TRUSTEED_CEL_ENABLED', '1');
        }

        // ── Group 1: classifyAuthMode ───────────────────────────────────────

        public function testClassifyNoAuthForExplicitOpenModes(): void
        {
            foreach (['none', 'no-auth', 'noauth', 'disabled', 'off', 'open', 'public'] as $value) {
                \Configuration::reset();
                \Configuration::set('PS_MCP_SERVER_AUTH_MODE', $value);
                $this->assertSame(
                    McpNoAuthDetector::MODE_NO_AUTH,
                    $this->classify(),
                    "auth-mode value '{$value}' must classify as NO_AUTH"
                );
            }
        }

        public function testClassifyTokenForSecureModes(): void
        {
            foreach (['token', 'bearer', 'apikey', 'api_key', 'oauth', 'oauth2'] as $value) {
                \Configuration::reset();
                \Configuration::set('PS_MCP_SERVER_AUTH_MODE', $value);
                $this->assertSame(
                    McpNoAuthDetector::MODE_TOKEN,
                    $this->classify(),
                    "auth-mode value '{$value}' must classify as TOKEN"
                );
            }
        }

        public function testClassifyNoAuthWhenEnabledButNoKey(): void
        {
            \Configuration::reset();
            // MCP server enabled, but neither an explicit mode nor an auth key.
            \Configuration::set('PS_MCP_SERVER_ENABLED', '1');
            \Configuration::set('PS_MCP_SERVER_AUTH_KEY', '');
            $this->assertSame(
                McpNoAuthDetector::MODE_NO_AUTH,
                $this->classify(),
                'MCP enabled with no auth key is a No-Auth posture'
            );
        }

        public function testClassifyUnknownWhenServerAbsent(): void
        {
            \Configuration::reset();
            // No ps_mcp_server config at all.
            $this->assertSame(
                McpNoAuthDetector::MODE_UNKNOWN,
                $this->classify(),
                'absent MCP server config must classify as UNKNOWN (no-op)'
            );
        }

        public function testClassifyTokenWhenEnabledWithKey(): void
        {
            \Configuration::reset();
            \Configuration::set('PS_MCP_SERVER_ENABLED', '1');
            \Configuration::set('PS_MCP_SERVER_AUTH_KEY', 'sk_live_abc123');
            $this->assertSame(
                McpNoAuthDetector::MODE_TOKEN,
                $this->classify(),
                'MCP enabled WITH an auth key is a Token posture'
            );
        }

        // ── Group 2: detect() observe behaviour ─────────────────────────────

        public function testDetectNoAuthSetsObserveNoticeAndDoesNotThrow(): void
        {
            \Configuration::set('PS_MCP_SERVER_AUTH_MODE', 'none');

            // unknownAgent=true but fallback NOT strict → observe, no throw.
            \Configuration::set('TRUSTEED_CEL_FALLBACK_MODE', 'balanced');

            (new McpNoAuthDetector())->detect(true);

            $this->assertTrue(
                McpNoAuthDetector::hasPendingNotice(),
                'No-Auth detection must raise an admin observe notice'
            );
        }

        public function testDetectTokenModeIsNoOp(): void
        {
            \Configuration::set('PS_MCP_SERVER_AUTH_MODE', 'token');
            \Configuration::set('TRUSTEED_CEL_FALLBACK_MODE', 'strict');

            (new McpNoAuthDetector())->detect(true);

            $this->assertFalse(
                McpNoAuthDetector::hasPendingNotice(),
                'Token mode must NOT raise a notice'
            );
        }

        public function testDetectNoOpWhenCelDisabled(): void
        {
            \Configuration::set('TRUSTEED_CEL_ENABLED', '0');
            \Configuration::set('PS_MCP_SERVER_AUTH_MODE', 'none');
            \Configuration::set('TRUSTEED_CEL_FALLBACK_MODE', 'strict');

            // Must neither throw nor set a notice when CEL is off.
            (new McpNoAuthDetector())->detect(true);

            $this->assertFalse(
                McpNoAuthDetector::hasPendingNotice(),
                'CEL disabled → detector is fully inert'
            );
        }

        // ── Group 3: enforce path ───────────────────────────────────────────

        public function testDetectStrictNoAuthUnknownAgentFailsClosed(): void
        {
            \Configuration::set('PS_MCP_SERVER_AUTH_MODE', 'none');
            \Configuration::set('TRUSTEED_CEL_FALLBACK_MODE', 'strict');

            $this->expectException(\PrestaShopException::class);
            (new McpNoAuthDetector())->detect(true);
        }

        public function testDetectStrictNoAuthKnownAgentDoesNotThrow(): void
        {
            \Configuration::set('PS_MCP_SERVER_AUTH_MODE', 'none');
            \Configuration::set('TRUSTEED_CEL_FALLBACK_MODE', 'strict');

            // Known agent (verified) — observe even in strict; do not block.
            (new McpNoAuthDetector())->detect(false);

            $this->assertTrue(
                McpNoAuthDetector::hasPendingNotice(),
                'strict + No-Auth + KNOWN agent still surfaces the observe notice'
            );
        }

        // ── Group 4: notice lifecycle ───────────────────────────────────────

        public function testNoticeLifecycle(): void
        {
            $this->assertFalse(McpNoAuthDetector::hasPendingNotice());

            \Configuration::set('PS_MCP_SERVER_AUTH_MODE', 'none');
            \Configuration::set('TRUSTEED_CEL_FALLBACK_MODE', 'balanced');
            (new McpNoAuthDetector())->detect(true);
            $this->assertTrue(McpNoAuthDetector::hasPendingNotice());

            McpNoAuthDetector::acknowledgeNotice();
            $this->assertFalse(
                McpNoAuthDetector::hasPendingNotice(),
                'acknowledge must clear the pending notice'
            );
        }

        public function testAdminBannerStringMentionsNoAuth(): void
        {
            $banner = McpNoAuthDetector::adminBannerMessage();
            $this->assertIsString($banner);
            $this->assertNotSame('', $banner);
            $this->assertStringContainsStringIgnoringCase('no-auth', $banner);
        }

        // ── Helpers ─────────────────────────────────────────────────────────

        private function classify(): string
        {
            $ref = new ReflectionClass(McpNoAuthDetector::class);
            $m   = $ref->getMethod('classifyAuthMode');
            $m->setAccessible(true);
            return (string) $m->invoke(null, 0);
        }
    }
}
