<?php

declare(strict_types=1);

/**
 * ValidateOrderHookPs9BlockAttemptTest — GHSA-2j2x-5q52-g48m, D3.
 *
 * `PaymentModule::validateOrder()` remains the SINGLE authoritative blocking
 * point in every PrestaShop version this module supports — this hook
 * (`actionValidateOrderBefore`, PS 9+ forward-compat only, does not fire on
 * 8.2 core) is belt-and-suspenders, never the only defense. There is no PS9
 * core available in this repo to confirm whether `Hook::exec()` still
 * swallows an exception raised from a hook on that branch.
 *
 * What IS verifiable here: `hookActionValidateOrderBefore()` now logs
 * `[trusteed.cel.ps9_hook_block_attempt]` IMMEDIATELY BEFORE re-throwing the
 * BLOCK exception, so a blocked attempt on this path leaves a searchable
 * trail even if PS9 core discards the exception that follows.
 *
 * Run:
 *   docker run --rm -v $(pwd):/app -w /app php:8.3-cli \
 *     php vendor/bin/phpunit tests/enforcement/ValidateOrderHookPs9BlockAttemptTest.php
 */

namespace {
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

    if (!class_exists('PrestaShopException')) {
        class PrestaShopException extends \Exception
        {
        }
    }

    if (!class_exists('PrestaShopLogger')) {
        class PrestaShopLogger
        {
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
}

namespace Trusteed\Tests\Enforcement {

    use PHPUnit\Framework\TestCase;
    use Trusteed\Enforcement\ValidateOrderHook;

    require_once __DIR__ . '/PsEnforcementStubs.php';
    require_once __DIR__ . '/../../src/Enforcement/ValidateOrderHook.php';

    /** Forces evaluateCart() to BLOCK or ALLOW on demand, without the full snapshot pipeline. */
    final class ScriptedValidateOrderHook extends ValidateOrderHook
    {
        public static ?\Throwable $blockWith = null;

        public function evaluateCart(\Cart $cart): void
        {
            if (self::$blockWith !== null) {
                throw self::$blockWith;
            }
            // else: ALLOW — return normally, matching the base contract.
        }
    }

    final class ValidateOrderHookPs9BlockAttemptTest extends TestCase
    {
        protected function setUp(): void
        {
            \PrestaShopLogger::reset();
            ScriptedValidateOrderHook::$blockWith = null;
        }

        private function taggedLogs(): array
        {
            return array_values(array_filter(
                \PrestaShopLogger::$logs,
                static fn (array $log) => str_contains($log['message'], '[trusteed.cel.ps9_hook_block_attempt]')
            ));
        }

        public function testLogsBlockAttemptImmediatelyBeforeRethrowing(): void
        {
            ScriptedValidateOrderHook::$blockWith = new \PrestaShopException('R019: country blocked');
            $hook = new ScriptedValidateOrderHook();

            try {
                $hook->hookActionValidateOrderBefore(['cart' => new \Cart(7)]);
                $this->fail('Expected PrestaShopException to propagate');
            } catch (\PrestaShopException $e) {
                $this->assertSame('R019: country blocked', $e->getMessage());
            }

            $this->assertCount(1, $this->taggedLogs(), 'Expected exactly one block-attempt log entry');
        }

        public function testDoesNotLogWhenCartParamIsMissing(): void
        {
            $hook = new ScriptedValidateOrderHook();

            // No 'cart' key at all — evaluateCart() is never reached.
            $hook->hookActionValidateOrderBefore([]);

            $this->assertEmpty($this->taggedLogs());
        }

        public function testDoesNotLogOnSuccessfulAllow(): void
        {
            $hook = new ScriptedValidateOrderHook();

            $hook->hookActionValidateOrderBefore(['cart' => new \Cart(8)]);

            $this->assertEmpty($this->taggedLogs(), 'ALLOW must not log a block-attempt entry');
        }
    }
}
