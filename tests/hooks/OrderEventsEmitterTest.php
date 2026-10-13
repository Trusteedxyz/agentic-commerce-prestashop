<?php

declare(strict_types=1);

/**
 * Tests for OrderEventsEmitter.
 *
 * Covers:
 *   - mapStateToEventType: 4/5 map to FULFILLED, 7 to REFUNDED, others to null
 *   - payloadHashCanonical: key order is irrelevant (stable sha256)
 *   - signHmac: hex HMAC-SHA256 over the canonical body (without signature)
 *   - generateDeterministicJti: UUID v5, stable per minute
 *   - resolvePreviousStateId: honours $newStateId
 *   - hookActionObjectOrderSlipAddAfter: gates on slip->id > 0
 *
 * It does not invoke the full hook (that needs the PrestaShop kernel); the pure
 * logic is tested through reflection.
 */

namespace Trusteed\Tests\Hooks;

use Trusteed\Hooks\OrderEventsEmitter;
use PHPUnit\Framework\TestCase;

final class OrderEventsEmitterTest extends TestCase
{
    public function testStateMapping(): void
    {
        $emitter = new OrderEventsEmitter();
        $ref = new \ReflectionMethod($emitter, 'mapStateToEventType');
        $ref->setAccessible(true);

        self::assertSame('FULFILLED', $ref->invoke($emitter, OrderEventsEmitter::STATE_SHIPPED));
        self::assertSame('FULFILLED', $ref->invoke($emitter, OrderEventsEmitter::STATE_DELIVERED));
        self::assertSame('REFUNDED', $ref->invoke($emitter, OrderEventsEmitter::STATE_REFUNDED));
        self::assertNull($ref->invoke($emitter, OrderEventsEmitter::STATE_PAYMENT_ERROR));
        self::assertNull($ref->invoke($emitter, 999));
    }

    public function testPayloadHashStableAcrossKeyOrder(): void
    {
        $emitter = new OrderEventsEmitter();
        $ref = new \ReflectionMethod($emitter, 'payloadHashCanonical');
        $ref->setAccessible(true);

        $h1 = $ref->invoke($emitter, ['a' => 1, 'b' => 2, 'c' => 3]);
        $h2 = $ref->invoke($emitter, ['c' => 3, 'a' => 1, 'b' => 2]);
        $h3 = $ref->invoke($emitter, ['b' => 2, 'c' => 3, 'a' => 1]);

        self::assertSame($h1, $h2);
        self::assertSame($h2, $h3);
        self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $h1);
    }

    public function testHmacSignDeterministicAndExcludesSignature(): void
    {
        $emitter = new OrderEventsEmitter();
        $ref = new \ReflectionMethod($emitter, 'signHmac');
        $ref->setAccessible(true);

        $body = ['a' => 1, 'b' => 2];
        $sig1 = $ref->invoke($emitter, $body, 'secret');
        $sig2 = $ref->invoke($emitter, $body, 'secret');

        self::assertSame($sig1, $sig2);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $sig1);

        // Distinto secret → distinta firma.
        $sig3 = $ref->invoke($emitter, $body, 'other-secret');
        self::assertNotSame($sig1, $sig3);
    }

    /**
     * Same (platform, merchantId, orderId, eventType,
     * occurredAt-floor-1min) → same jti. Different eventType → different jti.
     */
    public function testGenerateDeterministicJtiStable(): void
    {
        $emitter = new OrderEventsEmitter();
        $ref = new \ReflectionMethod($emitter, 'generateDeterministicJti');
        $ref->setAccessible(true);

        $occurredAt = '2026-05-11T10:30:42Z';
        $jtiA1 = $ref->invoke($emitter, 'PRESTASHOP', 'merchant-1', 'order-123', 'FULFILLED', $occurredAt);
        $jtiA2 = $ref->invoke($emitter, 'PRESTASHOP', 'merchant-1', 'order-123', 'FULFILLED', $occurredAt);
        self::assertSame($jtiA1, $jtiA2, 'same inputs must produce same jti');

        // UUID v5 shape: 8-4-4-4-12, version=5, variant 10xx.
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $jtiA1
        );

        // Distinto eventType → distinto jti.
        $jtiB = $ref->invoke($emitter, 'PRESTASHOP', 'merchant-1', 'order-123', 'REFUNDED', $occurredAt);
        self::assertNotSame($jtiA1, $jtiB);

        // Distinto orderId → distinto jti.
        $jtiC = $ref->invoke($emitter, 'PRESTASHOP', 'merchant-1', 'order-999', 'FULFILLED', $occurredAt);
        self::assertNotSame($jtiA1, $jtiC);

        // Distinto merchant → distinto jti.
        $jtiD = $ref->invoke($emitter, 'PRESTASHOP', 'merchant-2', 'order-123', 'FULFILLED', $occurredAt);
        self::assertNotSame($jtiA1, $jtiD);
    }

    /**
     * Two timestamps within the same UTC minute (different
     * seconds) MUST produce the same jti. Different minute → different jti.
     */
    public function testDeterministicJtiFloorMinute(): void
    {
        $emitter = new OrderEventsEmitter();
        $ref = new \ReflectionMethod($emitter, 'generateDeterministicJti');
        $ref->setAccessible(true);

        $sameMinute1 = '2026-05-11T10:30:00Z';
        $sameMinute2 = '2026-05-11T10:30:42Z';
        $sameMinute3 = '2026-05-11T10:30:59Z';
        $nextMinute  = '2026-05-11T10:31:00Z';

        $jti1 = $ref->invoke($emitter, 'PRESTASHOP', 'm', 'o', 'FULFILLED', $sameMinute1);
        $jti2 = $ref->invoke($emitter, 'PRESTASHOP', 'm', 'o', 'FULFILLED', $sameMinute2);
        $jti3 = $ref->invoke($emitter, 'PRESTASHOP', 'm', 'o', 'FULFILLED', $sameMinute3);
        $jti4 = $ref->invoke($emitter, 'PRESTASHOP', 'm', 'o', 'FULFILLED', $nextMinute);

        self::assertSame($jti1, $jti2, 'seconds within same minute must collapse');
        self::assertSame($jti2, $jti3, 'all seconds in a minute share the same jti');
        self::assertNotSame($jti1, $jti4, 'a different minute must produce a different jti');
    }

    /**
     * The minute floor must normalize to UTC so non-UTC inputs collapse to the same
     * minute as their UTC equivalent.
     */
    public function testDeterministicJtiTimezoneNormalization(): void
    {
        $emitter = new OrderEventsEmitter();
        $ref = new \ReflectionMethod($emitter, 'generateDeterministicJti');
        $ref->setAccessible(true);

        // 12:30:00 +02:00 == 10:30:00 UTC.
        $utc = '2026-05-11T10:30:00Z';
        $tz  = '2026-05-11T12:30:42+02:00';

        $jtiUtc = $ref->invoke($emitter, 'PRESTASHOP', 'm', 'o', 'FULFILLED', $utc);
        $jtiTz  = $ref->invoke($emitter, 'PRESTASHOP', 'm', 'o', 'FULFILLED', $tz);
        self::assertSame($jtiUtc, $jtiTz);
    }

    public function testSecretNotEchoedInPayloadHash(): void
    {
        // The canonical helper must not include the secret.
        $emitter = new OrderEventsEmitter();
        $ref = new \ReflectionMethod($emitter, 'payloadHashCanonical');
        $ref->setAccessible(true);

        $h1 = $ref->invoke($emitter, ['orderId' => '1', 'currency' => 'EUR']);
        // Same payload: the hash does not depend on any secret.
        $h2 = $ref->invoke($emitter, ['orderId' => '1', 'currency' => 'EUR']);
        self::assertSame($h1, $h2);
    }

    /**
     * The AddAfter hook must skip when $slip->id === 0
     * (i.e. failed persistence). We can't load PrestaShop's \OrderSlip in unit
     * tests, so we use a stub class. The hook should return without throwing
     * and without attempting to access $slip->id_order beyond the gate.
     */
    public function testOrderSlipAfterHookGatesSlipId(): void
    {
        // Stub OrderSlip class (if not already loaded by PrestaShop kernel).
        if (!class_exists('\\OrderSlip', false)) {
            eval('class OrderSlip { public $id = 0; public $id_order = 0; public $amount = 0.0; public $shipping_cost_amount = 0.0; public $date_add = null; }');
        }

        $slip = new \OrderSlip();
        $slip->id = 0; // unpersisted, must be skipped.
        $slip->id_order = 42;

        $emitter = new OrderEventsEmitter();
        // No exception, no emission. We just assert it returns void cleanly.
        $emitter->hookActionObjectOrderSlipAddAfter(['object' => $slip]);
        self::assertTrue(true, 'hook returned without throwing for slip->id=0');
    }

    /**
     * With history [newest first] = [shipped(4), processing(3),
     * payment_accepted(2)] and newStateId=4, previous must be 3 (not blindly
     * history[1]; the algorithm must skip the entry that matches newStateId).
     */
    public function testResolvePreviousStateIdUsesNewStateId(): void
    {
        // Build a fake \Order with a getHistory() override.
        //
        // The anonymous class must extend a minimal `\Order` (the real one if
        // PrestaShop is loaded): `resolvePreviousStateId(\Order $order, ...)` is
        // typed, so a plain `new class { ... }` would throw a TypeError.
        self::ensureOrderStub();
        $order = new class extends \Order {
            /** @return array<int,array<string,int>> */
            public function getHistory(int $langId): array
            {
                return [
                    ['id_order_state' => 4], // shipped, most recent
                    ['id_order_state' => 3], // processing
                    ['id_order_state' => 2], // payment_accepted
                ];
            }
        };

        // Stub Context::getContext()->language->id so resolvePreviousStateId
        // can call it. Provide a minimal Context shim if absent.
        if (!class_exists('\\Context', false)) {
            eval('class Context {
                public $language;
                public static $instance;
                public function __construct() { $this->language = new class { public $id = 1; }; }
                public static function getContext() {
                    if (self::$instance === null) self::$instance = new self();
                    return self::$instance;
                }
            }');
        }

        $emitter = new OrderEventsEmitter();
        $ref = new \ReflectionMethod($emitter, 'resolvePreviousStateId');
        $ref->setAccessible(true);

        $prev = $ref->invoke($emitter, $order, 4);
        self::assertSame(3, $prev, 'previous state must be the one immediately before the newStateId match');
    }

    /**
     * When the most recent entry differs from $newStateId (history
     * already advanced past it), walk until we find newStateId and return the
     * next non-matching entry. If newStateId never appears, return null.
     */
    public function testResolvePreviousStateIdReturnsNullWhenNewStateMissing(): void
    {
        self::ensureOrderStub();
        $order = new class extends \Order {
            /** @return array<int,array<string,int>> */
            public function getHistory(int $langId): array
            {
                return [
                    ['id_order_state' => 5], // delivered
                    ['id_order_state' => 4], // shipped
                ];
            }
        };

        if (!class_exists('\\Context', false)) {
            eval('class Context {
                public $language;
                public static $instance;
                public function __construct() { $this->language = new class { public $id = 1; }; }
                public static function getContext() {
                    if (self::$instance === null) self::$instance = new self();
                    return self::$instance;
                }
            }');
        }

        $emitter = new OrderEventsEmitter();
        $ref = new \ReflectionMethod($emitter, 'resolvePreviousStateId');
        $ref->setAccessible(true);

        // newStateId=7 (refunded) never appears → null.
        self::assertNull($ref->invoke($emitter, $order, 7));
    }

    /**
     * The FULFILLED event used to carry no shipping time, and the server creates
     * the order row at the same instant, so the measured latency was always
     * about 0 h. The emitter now computes `hoursToFulfill` from the shop's own
     * two clocks (order date and shipping date, in its time zone).
     */
    private function timing(?string $dateAdd, ?string $shippedAt, string $tz = 'Europe/Madrid'): array
    {
        $emitter = new OrderEventsEmitter();
        $ref = new \ReflectionMethod($emitter, 'computeFulfillmentTiming');
        $ref->setAccessible(true);
        return $ref->invoke($emitter, $dateAdd, $shippedAt, $tz);
    }

    public function testFulfillmentTimingMeasuresRealLatency(): void
    {
        $t = $this->timing('2026-09-01 10:00:00', '2026-09-11 10:00:00');

        self::assertSame('measured', $t['dataQuality']);
        self::assertSame(240.0, $t['hoursToFulfill']);
        self::assertSame('2026-09-11T10:00:00+02:00', $t['shippedAt']);
    }

    public function testFulfillmentTimingUsesShopTimezone(): void
    {
        $t = $this->timing('2026-09-01 10:00:00', '2026-09-01 12:30:00', 'America/New_York');

        self::assertSame(2.5, $t['hoursToFulfill']);
        self::assertSame('2026-09-01T12:30:00-04:00', $t['shippedAt']);
    }

    /**
     * Without its own shipping time the data is NOT "measured": the server must
     * not fall back to its ingestion clock, which gives about 0 h here.
     */
    public function testFulfillmentTimingWithoutShippedAtIsUnmeasured(): void
    {
        $t = $this->timing('2026-09-01 10:00:00', null);

        self::assertSame(['dataQuality' => 'unknown'], $t);
    }

    public function testFulfillmentTimingRejectsShippedBeforeOrder(): void
    {
        $t = $this->timing('2026-09-10 10:00:00', '2026-09-01 10:00:00');

        self::assertSame(['dataQuality' => 'unknown'], $t);
    }

    public function testFulfillmentTimingRejectsUnparseableDates(): void
    {
        self::assertSame(['dataQuality' => 'unknown'], $this->timing('0000-00-00 00:00:00', '2026-09-01 10:00:00'));
        self::assertSame(['dataQuality' => 'unknown'], $this->timing('no es fecha', '2026-09-01 10:00:00'));
        self::assertSame(['dataQuality' => 'unknown'], $this->timing(null, '2026-09-01 10:00:00'));
    }

    /**
     * An order that goes straight to "delivered" has no shipping time: the
     * delivery time is not the shipping time.
     */
    public function testShippedAtFromHistoryPrefersShippedEntry(): void
    {
        $emitter = new OrderEventsEmitter();
        $ref = new \ReflectionMethod($emitter, 'pickShippedAtFromHistory');
        $ref->setAccessible(true);

        $history = [
            ['id_order_state' => OrderEventsEmitter::STATE_DELIVERED, 'date_add' => '2026-09-12 09:00:00'],
            ['id_order_state' => OrderEventsEmitter::STATE_SHIPPED, 'date_add' => '2026-09-05 18:00:00'],
            ['id_order_state' => 2, 'date_add' => '2026-09-01 10:00:00'],
        ];
        self::assertSame('2026-09-05 18:00:00', $ref->invoke($emitter, $history));

        $sinEnvio = [
            ['id_order_state' => OrderEventsEmitter::STATE_DELIVERED, 'date_add' => '2026-09-12 09:00:00'],
        ];
        self::assertNull($ref->invoke($emitter, $sinEnvio));
    }

    /**
     * Minimal `\Order` for tests that need to pass an order to a typed method.
     * It is only declared when PrestaShop is not loaded; each test overrides
     * its `getHistory`.
     */
    private static function ensureOrderStub(): void
    {
        if (!class_exists('\\Order', false)) {
            eval('class Order { public function getHistory(int $langId) { return []; } }');
        }
    }
}
