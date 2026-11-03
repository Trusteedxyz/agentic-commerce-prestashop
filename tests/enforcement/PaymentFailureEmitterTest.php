<?php

declare(strict_types=1);

/**
 * PaymentFailureEmitterTest — PHPUnit 10 tests for Spec-048 P0b PrestaShop
 * payment-failure emitter (src/Enforcement/PaymentFailureEmitter.php).
 *
 * The emitter exposes static private helpers (hmacSign, recursiveKsort,
 * isPaymentErrorState). We exercise them via \ReflectionClass to validate
 * the canonical HMAC envelope and the payment-error state detection. We do
 * NOT exercise the cURL POST or the full hook entry points (those depend on
 * Order / OrderPayment objects that require a live PS kernel — covered by
 * the E2E suite T045).
 *
 * Coverage:
 *   PFE-1  isPaymentErrorState returns true for PS_OS_ERROR
 *   PFE-2  isPaymentErrorState returns true for PS_OS_CANCELED
 *   PFE-3  isPaymentErrorState returns false for any other state
 *   PFE-4  hmacSign produces deterministic sha256 HMAC over canonical JSON
 *   PFE-5  hmacSign yields identical signature regardless of input key order
 *   PFE-6  recursiveKsort sorts nested associative arrays
 *   PFE-7  recursiveKsort preserves order of list (numeric-indexed) arrays
 *
 * Run from repo root:
 *   vendor/bin/phpunit \
 *     packages/prestashop-module-agenticmcpstores/tests/enforcement/PaymentFailureEmitterTest.php
 */

namespace {
    require_once __DIR__ . '/PsEnforcementStubs.php';

    // ── Global stub for \Configuration::get used by PaymentFailureEmitter ──
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
             * @return mixed
             */
            public static function get(string $key)
            {
                return self::$store[$key] ?? false;
            }
        }
    }

    // ── Global stub for \PrestaShopLogger — safeLog() must not throw ───────
    if (!class_exists('PrestaShopLogger')) {
        class PrestaShopLogger
        {
            public static function addLog(string $message, int $severity = 1): void
            {
                // no-op for tests
            }
        }
    }

    require_once __DIR__ . '/../../src/Enforcement/PaymentFailureEmitter.php';
}

namespace Trusteed\Tests\Enforcement {

    use PHPUnit\Framework\TestCase;
    use ReflectionClass;

    class PaymentFailureEmitterTest extends TestCase
    {
        protected function setUp(): void
        {
            \Configuration::reset();
            \Configuration::set('PS_OS_ERROR', 8);
            \Configuration::set('PS_OS_CANCELED', 6);
        }

        // ── Group 1: isPaymentErrorState ─────────────────────────────────────

        public function testIsPaymentErrorStateReturnsTrueForPsOsError(): void
        {
            $result = $this->invokeStatic('isPaymentErrorState', [8]);
            $this->assertTrue($result, 'PS_OS_ERROR (8) must be classified as payment-error state');
        }

        public function testIsPaymentErrorStateReturnsTrueForPsOsCanceled(): void
        {
            $result = $this->invokeStatic('isPaymentErrorState', [6]);
            $this->assertTrue($result, 'PS_OS_CANCELED (6) must be classified as payment-error state');
        }

        public function testIsPaymentErrorStateReturnsFalseForOtherStates(): void
        {
            $this->assertFalse($this->invokeStatic('isPaymentErrorState', [2]), 'PS_OS_PAYMENT (2) is not an error state');
            $this->assertFalse($this->invokeStatic('isPaymentErrorState', [5]), 'PS_OS_DELIVERED is not an error state');
            $this->assertFalse($this->invokeStatic('isPaymentErrorState', [0]), 'unknown state 0 is not an error state');
        }

        // ── Group 2: hmacSign ────────────────────────────────────────────────

        public function testHmacSignProducesDeterministicSha256Output(): void
        {
            $body = [
                'installationId' => 'inst-123',
                'merchantId'     => 'merch-abc',
                'platform'       => 'prestashop',
                'reason'         => 'payment_failed',
            ];
            $secret = 'shared-secret-xyz';

            $sig1 = $this->invokeStatic('hmacSign', [$body, $secret]);
            $sig2 = $this->invokeStatic('hmacSign', [$body, $secret]);

            $this->assertIsString($sig1);
            $this->assertSame(64, strlen($sig1), 'sha256 hex digest must be 64 chars');
            $this->assertSame($sig1, $sig2, 'identical input must produce identical signature');
        }

        public function testHmacSignNormalisesKeyOrderBeforeSigning(): void
        {
            $bodyA = [
                'reason'         => 'payment_failed',
                'merchantId'     => 'merch-abc',
                'platform'       => 'prestashop',
                'installationId' => 'inst-123',
            ];
            $bodyB = [
                'installationId' => 'inst-123',
                'merchantId'     => 'merch-abc',
                'platform'       => 'prestashop',
                'reason'         => 'payment_failed',
            ];
            $secret = 'shared-secret-xyz';

            $sigA = $this->invokeStatic('hmacSign', [$bodyA, $secret]);
            $sigB = $this->invokeStatic('hmacSign', [$bodyB, $secret]);

            $this->assertSame(
                $sigA,
                $sigB,
                'recursive-ksort canonicalisation must yield identical signatures regardless of key order'
            );
        }

        // ── Group 3: recursiveKsort ──────────────────────────────────────────

        public function testRecursiveKsortSortsNestedAssociativeArrays(): void
        {
            $input = [
                'z' => 1,
                'a' => ['y' => 2, 'x' => 3],
                'm' => 4,
            ];
            $expected = [
                'a' => ['x' => 3, 'y' => 2],
                'm' => 4,
                'z' => 1,
            ];
            $result = $this->invokeStatic('recursiveKsort', [$input]);

            // Compare by JSON encoding to assert key order, not just deep-equality.
            $this->assertSame(
                json_encode($expected),
                json_encode($result),
                'recursiveKsort must sort keys alphabetically at every depth'
            );
        }

        public function testRecursiveKsortPreservesListOrder(): void
        {
            $input  = ['items' => ['c', 'a', 'b']];
            $result = $this->invokeStatic('recursiveKsort', [$input]);

            $this->assertSame(
                ['c', 'a', 'b'],
                $result['items'],
                'numeric-indexed lists must NOT be re-ordered (only assoc maps)'
            );
        }

        // ── Helpers ──────────────────────────────────────────────────────────

        /**
         * @param array<int, mixed> $args
         * @return mixed
         */
        private function invokeStatic(string $method, array $args)
        {
            $ref = new ReflectionClass(\Trusteed\Enforcement\PaymentFailureEmitter::class);
            $m   = $ref->getMethod($method);
            $m->setAccessible(true);
            return $m->invokeArgs(null, $args);
        }
    }
}
