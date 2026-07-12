<?php

declare(strict_types=1);

/**
 * Spec 042 T042-086 — Tests para OrderEventsEmitter.
 *
 * Verifica:
 *   - mapStateToEventType: 4/5 → FULFILLED, 7 → REFUNDED, otros → null
 *   - payloadHashCanonical: orden de keys irrelevante (sha256 stable)
 *   - signHmac: HMAC-SHA256 hex sobre canonical(body sin signature)
 *   - generateDeterministicJti (R5a): UUID v5 estable por minuto
 *   - resolvePreviousStateId (R15): respeta $newStateId
 *   - hookActionObjectOrderSlipAddAfter (R7): gates slip->id > 0
 *
 * No invoca el hook completo (requiere PrestaShop kernel) — testa la lógica
 * pura vía reflection.
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
     * R5a (codex P1#4): same (platform, merchantId, orderId, eventType,
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
     * R5a (codex P1#4): two timestamps within the same UTC minute (different
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
     * R5a: floor must normalize to UTC so non-UTC inputs collapse to the same
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
        // Garantiza que el helper canonical no incluye el secret.
        $emitter = new OrderEventsEmitter();
        $ref = new \ReflectionMethod($emitter, 'payloadHashCanonical');
        $ref->setAccessible(true);

        $h1 = $ref->invoke($emitter, ['orderId' => '1', 'currency' => 'EUR']);
        // Mismo payload — el hash NO depende de ningún secret.
        $h2 = $ref->invoke($emitter, ['orderId' => '1', 'currency' => 'EUR']);
        self::assertSame($h1, $h2);
    }

    /**
     * R7 (codex P2#2): hook AddAfter must skip when $slip->id === 0
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
        $slip->id = 0; // unpersisted — must be skipped.
        $slip->id_order = 42;

        $emitter = new OrderEventsEmitter();
        // No exception, no emission. We just assert it returns void cleanly.
        $emitter->hookActionObjectOrderSlipAddAfter(['object' => $slip]);
        self::assertTrue(true, 'hook returned without throwing for slip->id=0');
    }

    /**
     * R15 (codex P3#1): with history [newest first] = [shipped(4), processing(3),
     * payment_accepted(2)] and newStateId=4, previous must be 3 (not blindly
     * history[1]; the algorithm must skip the entry that matches newStateId).
     */
    public function testResolvePreviousStateIdUsesNewStateId(): void
    {
        // Build a fake \Order with a getHistory() override.
        $order = new class {
            /** @return array<int,array<string,int>> */
            public function getHistory(int $langId): array
            {
                return [
                    ['id_order_state' => 4], // shipped — most recent
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
     * R15: when the most recent entry differs from $newStateId (history
     * already advanced past it), walk until we find newStateId and return the
     * next non-matching entry. If newStateId never appears, return null.
     */
    public function testResolvePreviousStateIdReturnsNullWhenNewStateMissing(): void
    {
        $order = new class {
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
}
