<?php

declare(strict_types=1);

/**
 * R022PaymentRailTest — PHPUnit 10 tests for R022 payment-method propagation (spec-048).
 *
 * R022 requires that the payment gateway selected by the buyer reaches the
 * Layer-2 callRulesEvaluate() context so that CEL rules can enforce
 * payment-rail restrictions (e.g. block BNPL for flagged agents).
 *
 * Two complementary fixes are covered:
 *
 *   Fix A  — override/classes/PaymentModule.php
 *            validateOrder() now passes $payment_method to runCelEvaluation(),
 *            which builds $cartCtx = ['paymentMethod' => $paymentMethod] and
 *            forwards it to callRulesEvaluate().
 *
 *   Fix B  — src/Enforcement/ValidateOrderHook.php — buildCartContext()
 *            reads \Tools::getValue('payment', '') from the POST, sanitises
 *            the value with strtolower + preg_replace(/[^a-zA-Z0-9_\-]/, ''),
 *            and stores it in $ctx['paymentMethod'] before passing to
 *            callRulesEvaluate() via the hook path.
 *
 * Because both files depend on the PrestaShop kernel we cannot load them
 * directly.  Instead we use focused in-test doubles that mirror the exact
 * R022 code paths — the same strategy as ValidateOrderHookTest.php (T078).
 *
 * Tests:
 *   Group 1 — PaymentModule: paymentMethod reaches callRulesEvaluate
 *     R022-1  'stripe' forwarded as $cartCtx['paymentMethod'] === 'stripe'
 *     R022-2  empty $payment_method → $cartCtx has no 'paymentMethod' key
 *     R022-3  'paypal' forwarded as $cartCtx['paymentMethod'] === 'paypal'
 *
 *   Group 2 — ValidateOrderHook::buildCartContext(): POST value extracted
 *     R022-4  'bankwire' from POST → $ctx['paymentMethod'] === 'bankwire'
 *     R022-5  'Stripe' normalised to 'stripe' (strtolower)
 *     R022-6  'stripe<script>' stripped to 'stripescript'
 *     R022-7  empty POST value → $ctx has no 'paymentMethod' key
 *
 * Run from repo root:
 *   vendor/bin/phpunit \
 *     packages/prestashop-module-agenticmcpstores/tests/enforcement/R022PaymentRailTest.php
 */

namespace Trusteed\Tests\Enforcement;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/PsEnforcementStubs.php';

// ── Stubs for PS classes used by buildCartContext() ──────────────────────────

/**
 * Stub for \Tools — the production code calls \Tools::getValue('payment', '').
 * The static $mockValues map is populated per-test via Tools::setMockValue().
 */
if (!class_exists('Tools')) {
    class Tools
    {
        /** @var array<string, string> */
        private static array $mockValues = [];

        public static function setMockValue(string $key, string $value): void
        {
            self::$mockValues[$key] = $value;
        }

        public static function reset(): void
        {
            self::$mockValues = [];
        }

        /**
         * Mirrors Tools::getValue($key, $default).
         *
         * @param mixed $default
         * @return mixed
         */
        public static function getValue(string $key, mixed $default = false): mixed
        {
            return self::$mockValues[$key] ?? $default;
        }
    }
}

// ── End stubs ─────────────────────────────────────────────────────────────────

class R022PaymentRailTest extends TestCase
{
    protected function tearDown(): void
    {
        \Tools::reset();
    }

    // ════════════════════════════════════════════════════════════════════════════
    // Group 1 — PaymentModule: $payment_method forwarded to callRulesEvaluate()
    // ════════════════════════════════════════════════════════════════════════════

    // ── R022-1: 'stripe' forwarded verbatim ──────────────────────────────────

    /**
     * When validateOrder() receives $payment_method = 'stripe', the value must
     * arrive in $cartCtx['paymentMethod'] passed to callRulesEvaluate().
     *
     * Mirrors the fix in PaymentModule::runCelEvaluation():
     *   $cartCtx = [];
     *   if ($paymentMethod !== '') {
     *       $cartCtx['paymentMethod'] = $paymentMethod;
     *   }
     */
    public function testPaymentMethodPassedToCallRulesEvaluate(): void
    {
        $capture = new MockSnapshotClient();

        $evaluator = new MockPaymentModuleEvaluator($capture);
        $evaluator->runCelEvaluation('stripe');

        $this->assertTrue(
            $capture->wasCalled(),
            'callRulesEvaluate() must be called when a payment method is supplied'
        );
        $this->assertSame(
            'stripe',
            $capture->capturedCartContext()['paymentMethod'] ?? null,
            '$cartCtx[paymentMethod] must equal the payment_method passed to validateOrder()'
        );
    }

    // ── R022-2: empty payment_method → key absent ────────────────────────────

    /**
     * When $payment_method is an empty string, no 'paymentMethod' key must be
     * added to $cartCtx (mirrors the guard `if ($paymentMethod !== '')`).
     */
    public function testEmptyPaymentMethodNotIncludedInCartContext(): void
    {
        $capture = new MockSnapshotClient();

        $evaluator = new MockPaymentModuleEvaluator($capture);
        $evaluator->runCelEvaluation('');

        $this->assertArrayNotHasKey(
            'paymentMethod',
            $capture->capturedCartContext(),
            'Empty payment_method must not add paymentMethod key to $cartCtx'
        );
    }

    // ── R022-3: 'paypal' forwarded verbatim ──────────────────────────────────

    /**
     * When $payment_method = 'paypal', $cartCtx['paymentMethod'] must equal 'paypal'.
     * Confirms the propagation is not specific to 'stripe'.
     */
    public function testPaymentMethodPassedAsString(): void
    {
        $capture = new MockSnapshotClient();

        $evaluator = new MockPaymentModuleEvaluator($capture);
        $evaluator->runCelEvaluation('paypal');

        $this->assertSame(
            'paypal',
            $capture->capturedCartContext()['paymentMethod'] ?? null,
            '$cartCtx[paymentMethod] must equal the payment_method string passed in'
        );
    }

    // ════════════════════════════════════════════════════════════════════════════
    // Group 2 — ValidateOrderHook::buildCartContext(): POST value extraction
    // ════════════════════════════════════════════════════════════════════════════

    // ── R022-4: 'bankwire' extracted from POST ───────────────────────────────

    /**
     * When \Tools::getValue('payment') returns 'bankwire', buildCartContext()
     * must set $ctx['paymentMethod'] = 'bankwire'.
     */
    public function testBuildCartContextIncludesPaymentMethodFromPost(): void
    {
        \Tools::setMockValue('payment', 'bankwire');

        $ctx = (new MockValidateOrderHook())->exposeBuildCartContext();

        $this->assertArrayHasKey(
            'paymentMethod',
            $ctx,
            'buildCartContext() must include paymentMethod when POST value is non-empty'
        );
        $this->assertSame(
            'bankwire',
            $ctx['paymentMethod'],
            'paymentMethod must equal the value returned by Tools::getValue("payment")'
        );
    }

    // ── R022-5: uppercase normalised to lowercase ────────────────────────────

    /**
     * 'Stripe' (initial capital) must be normalised to 'stripe' via strtolower().
     * Gateway IDs in the CEL rule catalogue are always lowercase slugs.
     */
    public function testBuildCartContextNormalizesPaymentMethodToLowercase(): void
    {
        \Tools::setMockValue('payment', 'Stripe');

        $ctx = (new MockValidateOrderHook())->exposeBuildCartContext();

        $this->assertSame(
            'stripe',
            $ctx['paymentMethod'] ?? null,
            'buildCartContext() must lowercase the payment method value'
        );
    }

    // ── R022-6: invalid characters stripped ─────────────────────────────────

    /**
     * Characters outside [a-zA-Z0-9_-] must be removed.
     * 'stripe<script>' → 'stripescript' (angle brackets and letters stripped out).
     */
    public function testBuildCartContextStripsInvalidCharsFromPaymentMethod(): void
    {
        \Tools::setMockValue('payment', 'stripe<script>');

        $ctx = (new MockValidateOrderHook())->exposeBuildCartContext();

        $this->assertSame(
            'stripescript',
            $ctx['paymentMethod'] ?? null,
            'buildCartContext() must strip characters outside [a-zA-Z0-9_-] from paymentMethod'
        );
    }

    // ── R022-7: empty POST value → key absent ───────────────────────────────

    /**
     * When \Tools::getValue('payment') returns '', no 'paymentMethod' key must
     * appear in $ctx (mirrors the guard `if ($paymentGateway !== '')`).
     */
    public function testBuildCartContextSkipsPaymentMethodWhenEmpty(): void
    {
        \Tools::setMockValue('payment', '');

        $ctx = (new MockValidateOrderHook())->exposeBuildCartContext();

        $this->assertArrayNotHasKey(
            'paymentMethod',
            $ctx,
            'buildCartContext() must not set paymentMethod when Tools::getValue returns empty string'
        );
    }
}

// ── In-test doubles ──────────────────────────────────────────────────────────

/**
 * MockSnapshotClient
 *
 * Captures the $cartContext argument passed to callRulesEvaluate() so that
 * Group 1 tests can assert that paymentMethod was forwarded correctly.
 * Returns an ALLOW decision so runCelEvaluation() does not throw.
 */
class MockSnapshotClient
{
    private bool  $called          = false;
    private array $capturedContext = [];

    public function wasCalled(): bool
    {
        return $this->called;
    }

    /** @return array<string, mixed> */
    public function capturedCartContext(): array
    {
        return $this->capturedContext;
    }

    /**
     * Mirrors SnapshotClient::callRulesEvaluate() signature.
     *
     * @return array{decision:string,ruleCode:string,reason:string,evaluationId:string}
     */
    public function callRulesEvaluate(
        string $agentId,
        int $cartTotalCents,
        string $currency,
        int $itemCount,
        ?float $agentTrustScore,
        array $cartContext = []
    ): array {
        $this->called          = true;
        $this->capturedContext = $cartContext;

        return [
            'decision'     => 'ALLOW',
            'ruleCode'     => '',
            'reason'       => '',
            'evaluationId' => 'test-eval-r022',
        ];
    }
}

/**
 * MockPaymentModuleEvaluator
 *
 * Mirrors the R022-specific portion of PaymentModule::runCelEvaluation():
 *
 *   $cartCtx = [];
 *   if ($paymentMethod !== '') {
 *       $cartCtx['paymentMethod'] = $paymentMethod;
 *   }
 *   $layer2 = $snapshotClient->callRulesEvaluate(
 *       $claims['agentId'],
 *       $cartTotalCents,
 *       $currency,
 *       $itemCount,
 *       $trustScore,
 *       $cartCtx
 *   );
 *
 * PS kernel dependencies (Cart, MerchantResolver, TokenVerifier) are replaced
 * by direct constants so only the cartCtx-building logic is exercised.
 */
class MockPaymentModuleEvaluator
{
    public function __construct(private MockSnapshotClient $snapshotClient) {}

    /**
     * Expose runCelEvaluation for testing.
     * Always proceeds to callRulesEvaluate (agent token / snapshot checks skipped).
     */
    public function runCelEvaluation(string $paymentMethod): void
    {
        // Mirror of PaymentModule fix: build $cartCtx with paymentMethod guard
        $cartCtx = [];
        if ($paymentMethod !== '') {
            $cartCtx['paymentMethod'] = $paymentMethod;
        }

        // Fixed constants — these values are not under test here
        $this->snapshotClient->callRulesEvaluate(
            agentId:        'did:key:test-agent',
            cartTotalCents: 1000,
            currency:       'EUR',
            itemCount:      1,
            agentTrustScore: null,
            cartContext:    $cartCtx
        );
    }
}

/**
 * MockValidateOrderHook
 *
 * Exposes the R022-specific fragment of ValidateOrderHook::buildCartContext()
 * without requiring any PrestaShop Cart object or kernel classes.
 *
 * The extracted fragment mirrors the exact production code:
 *
 *   $paymentGateway = \Tools::getValue('payment', '');
 *   if (is_string($paymentGateway) && $paymentGateway !== '') {
 *       $ctx['paymentMethod'] = strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', $paymentGateway));
 *   }
 *
 * Other fields from buildCartContext() (lineItems, shippingCountry, etc.) are
 * omitted because they are not under test for R022.
 */
class MockValidateOrderHook
{
    /**
     * Execute only the R022 payment-method extraction block from buildCartContext().
     *
     * @return array<string, mixed> Partial context containing only paymentMethod (if present).
     */
    public function exposeBuildCartContext(): array
    {
        $ctx = [];

        // R022: Payment gateway selected by the buyer — available in POST at hook time.
        $paymentGateway = \Tools::getValue('payment', '');
        if (is_string($paymentGateway) && $paymentGateway !== '') {
            // Strip anything outside [a-z0-9_-] — gateway IDs are always slug-like.
            $ctx['paymentMethod'] = strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', $paymentGateway));
        }

        return $ctx;
    }
}
