<?php

declare(strict_types=1);

/**
 * PaymentModuleOverrideUnexpectedErrorTest — GHSA-2j2x-5q52-g48m issue 2.
 *
 * `override/classes/PaymentModule.php::validateOrder()` caught `\Throwable`
 * from CEL evaluation and unconditionally logged + let the order proceed —
 * ANY unexpected exception (a malformed-input `SodiumException`, a missing
 * PS-core method, a bug) was implicit permission to skip every rule,
 * including the merchant-wide ones (country block, business hours, max
 * order amount…) that apply to EVERY checkout, agentic or not.
 *
 * Fixed behaviour: the catch(\Throwable) branch now asks
 * `SnapshotUnavailablePolicy::decide(null, $fallbackMode, true)` what to do —
 * `strict` (or an absent/corrupt configured mode, which
 * MerchantResolver::getFallbackMode() itself already treats as `strict`)
 * BLOCKS with a generic message; `balanced`/`permissive` proceed but leave an
 * unambiguous `[trusteed.cel.evaluator_error]` log entry. The exception's own
 * message is never exposed to the buyer.
 *
 * This test loads the REAL `override/classes/PaymentModule.php` and the REAL
 * `MerchantResolver`/`SnapshotUnavailablePolicy`, but substitutes a FAKE
 * `Trusteed\Enforcement\ValidateOrderHook` — declared here under the real
 * FQN — so `runCelEvaluation()` throws an arbitrary exception on demand
 * without needing the full CEL pipeline. Declaring a class under the real
 * FQN means this file CANNOT run in the same process as any test that loads
 * the genuine `ValidateOrderHook` (class redeclaration) — run standalone.
 *
 * Run:
 *   docker run --rm -v $(pwd):/app -w /app php:8.3-cli \
 *     php vendor/bin/phpunit tests/enforcement/PaymentModuleOverrideUnexpectedErrorTest.php
 */

namespace {
    if (!defined('_PS_MODULE_DIR_')) {
        define('_PS_MODULE_DIR_', '/nonexistent/modules/');
    }

    if (!class_exists('Cart')) {
        class Cart
        {
            public $id;

            public function __construct($id = null)
            {
                $this->id = $id;
            }
        }
    }

    if (!class_exists('Validate')) {
        class Validate
        {
            /** @param mixed $object */
            public static function isLoadedObject($object): bool
            {
                return true;
            }
        }
    }

    if (!class_exists('PrestaShopException')) {
        class PrestaShopException extends \Exception
        {
        }
    }

    if (!class_exists('PrestaShopLogger')) {
        class PrestaShopLogger
        {
            public const LOG_SEVERITY_INFORMATIVE = 1;
            public const LOG_SEVERITY_WARNING = 2;
            public const LOG_SEVERITY_ERROR = 3;

            /** @var array<int,array{message:string,severity:int}> */
            public static array $logs = [];

            public static function addLog(
                string $message,
                int $severity = 1,
                ?int $errorCode = null,
                ?string $objectType = null,
                ?int $objectId = null,
                bool $allowDuplicate = false
            ): void {
                self::$logs[] = ['message' => $message, 'severity' => $severity];
            }

            public static function reset(): void
            {
                self::$logs = [];
            }
        }
    }

    // `Configuration` and `Context` are NOT stubbed here — tests/bootstrap.php
    // already requires tests/stubs/PsContextStubs.php unconditionally, which
    // defines both globally (backed by `TestPsState`) for every test file in
    // the process. Redeclaring them here would silently no-op (guarded by
    // `class_exists`) and leave a stub without the methods this file expects,
    // which is exactly what happened before this comment existed. `Shop`
    // needs to exist as a class for MerchantResolver::currentShopId()'s
    // `instanceof \Shop` check — PsContextStubs.php's `Context::getContext()`
    // never actually assigns a real `Shop` instance (it uses a plain
    // `(object)['id' => ...]`), so `currentShopId()` always resolves to 0
    // here; that is fine — `MerchantResolver::getFallbackMode(0)` reads the
    // global (non-shop-scoped) `Configuration::get($key)`, which is exactly
    // what `TestPsState::$config` backs.
    if (!class_exists('Shop')) {
        class Shop
        {
            public $id;

            public function __construct($id = 1)
            {
                $this->id = $id;
            }
        }
    }

    if (!class_exists('PaymentModuleCore')) {
        class PaymentModuleCore
        {
            public function validateOrder(
                $id_cart,
                $id_order_state,
                $amount_paid,
                $payment_method = 'Unknown',
                $message = null,
                $extra_vars = [],
                $currency_special = null,
                $dont_touch_amount = false,
                $secure_key = false,
                \Shop $shop = null
            ) {
                // Marker return so tests can assert the order-creation path
                // was reached (i.e. CEL did NOT block).
                return 'parent_validate_order_called';
            }
        }
    }
}

namespace Trusteed\Enforcement {
    /**
     * Fake ValidateOrderHook declared under the REAL FQN — the production
     * autoloader (registered in tests/bootstrap.php) never fires for this
     * class name because PHP finds it already declared. Lets the test force
     * an arbitrary \Throwable out of runCelEvaluation() without needing the
     * full snapshot/token pipeline.
     */
    class ValidateOrderHook
    {
        public static ?\Throwable $throwable = null;

        public function evaluateCart(\Cart $cart): void
        {
            if (self::$throwable !== null) {
                throw self::$throwable;
            }
        }
    }
}

namespace Trusteed\Tests\Enforcement {

    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/PsEnforcementStubs.php';
    require_once __DIR__ . '/../../override/classes/PaymentModule.php';

    class PaymentModuleOverrideUnexpectedErrorTest extends TestCase
    {
        protected function setUp(): void
        {
            \TestPsState::reset();
            \PrestaShopLogger::reset();
            \Trusteed\Enforcement\ValidateOrderHook::$throwable = null;
        }

        private function moduleThrowing(\Throwable $e): \PaymentModule
        {
            \Trusteed\Enforcement\ValidateOrderHook::$throwable = $e;

            return new \PaymentModule();
        }

        // ── strict (and the corruption-guard default) blocks ────────────────

        public function testStrictModeBlocksOnUnexpectedError(): void
        {
            \TestPsState::$config['TRUSTEED_CEL_FALLBACK_MODE'] = 'strict';
            $module = $this->moduleThrowing(new \RuntimeException('boom, internal detail'));

            $this->expectException(\PrestaShopException::class);
            $module->validateOrder(1, 2, 10.0);
        }

        public function testAbsentFallbackModeBlocksAsCorruptionGuard(): void
        {
            // No TRUSTEED_CEL_FALLBACK_MODE set at all — MerchantResolver::
            // getFallbackMode() treats an absent/invalid value as 'strict',
            // never as 'balanced'. An unconfigured merchant is not consent.
            $module = $this->moduleThrowing(new \RuntimeException('boom'));

            $this->expectException(\PrestaShopException::class);
            $module->validateOrder(1, 2, 10.0);
        }

        public function testBlockExceptionMessageIsGenericNotLeakingInternalDetail(): void
        {
            \TestPsState::$config['TRUSTEED_CEL_FALLBACK_MODE'] = 'strict';
            $module = $this->moduleThrowing(new \RuntimeException('super-secret-internal-stack-trace-detail'));

            try {
                $module->validateOrder(1, 2, 10.0);
                $this->fail('Expected PrestaShopException');
            } catch (\PrestaShopException $e) {
                $this->assertStringNotContainsString('super-secret-internal-stack-trace-detail', $e->getMessage());
            }
        }

        // ── balanced / permissive proceed (merchant's own choice) ───────────

        public function testBalancedModeProceedsWithOrder(): void
        {
            \TestPsState::$config['TRUSTEED_CEL_FALLBACK_MODE'] = 'balanced';
            $module = $this->moduleThrowing(new \RuntimeException('boom'));

            $result = $module->validateOrder(1, 2, 10.0);
            $this->assertSame('parent_validate_order_called', $result);
        }

        public function testPermissiveModeProceedsWithOrder(): void
        {
            \TestPsState::$config['TRUSTEED_CEL_FALLBACK_MODE'] = 'permissive';
            $module = $this->moduleThrowing(new \RuntimeException('boom'));

            $result = $module->validateOrder(1, 2, 10.0);
            $this->assertSame('parent_validate_order_called', $result);
        }

        // ── the fail-open path leaves an unambiguous trail ───────────────────

        public function testBalancedModeLogsEvaluatorErrorTag(): void
        {
            \TestPsState::$config['TRUSTEED_CEL_FALLBACK_MODE'] = 'balanced';
            $module = $this->moduleThrowing(new \RuntimeException('boom-detail'));

            $module->validateOrder(1, 2, 10.0);

            $tagged = array_filter(
                \PrestaShopLogger::$logs,
                static fn (array $log) => str_contains($log['message'], '[trusteed.cel.evaluator_error]')
            );
            $this->assertNotEmpty($tagged, 'Expected a [trusteed.cel.evaluator_error] log entry');
        }
    }
}
