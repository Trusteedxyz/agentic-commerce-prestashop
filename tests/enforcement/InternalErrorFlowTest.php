<?php

declare(strict_types=1);

/**
 * Plan 04, Task 26 — una avería propia no es un permiso, en el flujo REAL.
 *
 * Una excepción NUESTRA durante el chequeo del pedido de un AGENTE lo retiene
 * en todos los modos (`trusteed:CEL-INTERNAL-ERROR`); a un humano no lo bloquea
 * nunca. Antes el override decidía con `SnapshotUnavailablePolicy` y un agente
 * en `balanced`/`permissive` pasaba.
 *
 * Aquí se ejecutan el `ValidateOrderHook` real con el `SnapshotClient` real (sólo
 * la red es de prueba) y el override `PaymentModule` real (el fichero de
 * `override/classes/`), cuyo único sustituto es la costura `newEvaluationHook()`
 * que inyecta el cliente de prueba. `PaymentModuleCore` es un stub que devuelve
 * una marca cuando se llega a crear el pedido.
 *
 * Arnés: ValidateOrderHookFlowHarness.php.
 */

namespace {
    if (!defined('_PS_MODULE_DIR_')) {
        define('_PS_MODULE_DIR_', '/nonexistent/modules/');
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
    if (!class_exists('Shop')) {
        class Shop
        {
            public $id = 1;
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
                return 'order_created';
            }
        }
    }
}

namespace Trusteed\Tests\Enforcement {

    require_once __DIR__ . '/ValidateOrderHookFlowHarness.php';
    require_once __DIR__ . '/../../override/classes/PaymentModule.php';

    use Trusteed\Enforcement\CelVerdictException;
    use Trusteed\Enforcement\InternalErrorGuard;
    use Trusteed\Enforcement\ValidateOrderHook;

    /** El override REAL; sólo cambia de dónde sale el hook (costura de prueba). */
    final class OverrideUnderTest extends \PaymentModule
    {
        public static ?ValidateOrderHook $hook = null;
        public static ?\Throwable $hookFactoryThrows = null;

        protected function newEvaluationHook(): ValidateOrderHook
        {
            if (self::$hookFactoryThrows !== null) {
                throw self::$hookFactoryThrows;
            }
            return self::$hook ?? new ValidateOrderHook();
        }
    }

    final class InternalErrorFlowTest extends ValidateOrderHookFlowTestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            $this->internalErrorExpected = true;
            OverrideUnderTest::$hook = $this->hook;
            OverrideUnderTest::$hookFactoryThrows = null;
        }

        protected function tearDown(): void
        {
            OverrideUnderTest::$hook = null;
            OverrideUnderTest::$hookFactoryThrows = null;
            parent::tearDown();
        }

        /** Crea el pedido por el override real: devuelve el mensaje del bloqueo o null si se creó. */
        private function place(): ?string
        {
            try {
                $result = (new OverrideUnderTest())->validateOrder(42, 2, 10.0);
                self::assertSame('order_created', $result);
                return null;
            } catch (\PrestaShopException $e) {
                return $e->getMessage();
            }
        }

        // ── error dentro de evaluateCart (llega por el override y por el hook) ──

        public function testAnInternalErrorHoldsAnAgentInEveryModeThroughTheOverride(): void
        {
            $this->client->snapshotThrows = new \RuntimeException('boom');
            $this->presentToken('not.a.token');
            foreach (self::MODES as $mode) {
                $this->mode($mode);
                $msg = $this->place();
                self::assertNotNull($msg, 'antes pasaba en ' . $mode);
                self::assertStringContainsString('trusteed:CEL-INTERNAL-ERROR', $msg, $mode);
            }
        }

        public function testAnInternalErrorNeverBlocksAHumanInAnyModeThroughTheOverride(): void
        {
            $this->client->snapshotThrows = new \RuntimeException('boom');
            foreach (self::MODES as $mode) {
                $this->mode($mode);
                self::assertNull($this->place(), $mode);
            }
            self::assertSame(3, $this->logsContaining('[trusteed.cel.evaluator_error_human_allowed]'));
        }

        public function testAnInternalErrorOnTheHookPathAlsoHoldsAnAgentWithTheVerdictException(): void
        {
            // PS 9: el hook decide por sí mismo; no puede subir una excepción cruda.
            $this->client->snapshotThrows = new \RuntimeException('boom');
            $this->presentToken('not.a.token');
            $this->mode('permissive');

            try {
                $this->hook->evaluateCart(new \Cart());
                self::fail('un agente no puede pasar por una avería propia');
            } catch (CelVerdictException $e) {
                self::assertStringContainsString('trusteed:CEL-INTERNAL-ERROR', $e->getMessage());
            }
        }

        public function testTheBlockNeverBlamesARuleNorLeaksTheInternalDetail(): void
        {
            $this->client->snapshotThrows = new \RuntimeException('secret-internal-detail');
            $this->presentToken('not.a.token');
            $this->mode('strict');

            $msg = (string) $this->place();
            self::assertStringNotContainsString('secret-internal-detail', $msg);
            self::assertDoesNotMatchRegularExpression('/\bR0\d\d\b/', $msg, 'una avería no es «una regla te bloqueó»');
            self::assertSame(1, $this->logsContaining('secret-internal-detail'), 'el detalle sólo va al log interno');
        }

        public function testAVerdictOfOursStillBlocksAHumanWhenTheMerchantRuleSaysSo(): void
        {
            // Sanidad del cambio de tipo: el veredicto propio no se confunde con una avería.
            $this->withRules([['ruleCode' => 'R030', 'enabled' => true, 'appliesTo' => 'ALL', 'params' => ['maxAmountCents' => 5000]]]);
            $this->serverDown();
            foreach (self::MODES as $mode) {
                $this->mode($mode);
                $msg = $this->place();
                self::assertNotNull($msg, $mode);
                self::assertStringStartsWith('trusteed:CEL-BLOCKED', $msg, $mode);
            }
        }

        // ── excepciones del núcleo de PrestaShop (no son nuestros veredictos) ──

        public function testACorePrestaShopExceptionIsAnInternalErrorNotAVerdict(): void
        {
            $this->client->snapshotThrows = new \PrestaShopException('core: database went away');

            // Humano: pasa en todos los modos (antes se bloqueaba como si fuera una regla).
            foreach (self::MODES as $mode) {
                $this->mode($mode);
                self::assertNull($this->place(), 'humano ' . $mode);
            }

            // Agente: retenido, con el código de avería y no con el texto del núcleo.
            $this->presentToken('not.a.token');
            foreach (self::MODES as $mode) {
                $this->mode($mode);
                $msg = $this->place();
                self::assertNotNull($msg, 'agente ' . $mode);
                self::assertStringContainsString('trusteed:CEL-INTERNAL-ERROR', $msg, $mode);
                self::assertStringNotContainsString('database went away', $msg, $mode);
            }
        }

        // ── error fuera de evaluateCart (el override no tiene carrito ni hook) ──

        public function testAnErrorOutsideEvaluateCartHoldsAnAgentInEveryMode(): void
        {
            OverrideUnderTest::$hookFactoryThrows = new \RuntimeException('constructor roto');
            $this->presentToken('not.a.token');
            foreach (self::MODES as $mode) {
                $this->mode($mode);
                $msg = $this->place();
                self::assertNotNull($msg, $mode);
                self::assertStringContainsString('trusteed:CEL-INTERNAL-ERROR', $msg, $mode);
            }
        }

        public function testAnErrorOutsideEvaluateCartNeverBlocksAHuman(): void
        {
            OverrideUnderTest::$hookFactoryThrows = new \RuntimeException('constructor roto');
            foreach (self::MODES as $mode) {
                $this->mode($mode);
                self::assertNull($this->place(), $mode);
            }
        }

        public function testAnErrorOutsideEvaluateCartHoldsWhenTheTokenCannotEvenBeRead(): void
        {
            // No se sabe si es humano: no se le abre a un agente (D1 sólo protege al humano probado).
            OverrideUnderTest::$hookFactoryThrows = new \RuntimeException('constructor roto');
            \Context::getContext()->cookie = new class () {
                public function __isset(string $n): bool
                {
                    return true;
                }

                public function __get(string $n)
                {
                    throw new \LogicException('cookie ilegible');
                }
            };
            try {
                $this->mode('permissive');
                self::assertNotNull($this->place());
            } finally {
                \Context::getContext()->cookie = null;
            }
        }

        // ── Task 27c (L5): el token de un ENLACE (GET) no convierte a un humano en agente ──

        /** Simula PS real: `Tools::getValue` devuelve el valor venga de POST o de GET. */
        private function presentTokenVia(string $token, bool $post, bool $get): void
        {
            $this->presentToken($token);
            if ($post) {
                $_POST['_trusteed_agent_token'] = $token;
            }
            if ($get) {
                $_GET['_trusteed_agent_token'] = $token;
            }
        }

        private function clearRequestGlobals(): void
        {
            unset($_POST['_trusteed_agent_token'], $_GET['_trusteed_agent_token']);
        }

        public function testATokenOnlyInTheQueryStringDoesNotMakeAHumanAnAgent(): void
        {
            try {
                $this->presentTokenVia('from-a-link', false, true);
                self::assertSame('', InternalErrorGuard::presentedToken());
                self::assertFalse(InternalErrorGuard::requestIsAgentic());

                // Avería propia: una persona con un enlace `?_trusteed_agent_token=x` pasa en todos los modos.
                $this->client->snapshotThrows = new \RuntimeException('boom');
                foreach (self::MODES as $mode) {
                    $this->mode($mode);
                    self::assertNull($this->place(), $mode);
                }
            } finally {
                $this->clearRequestGlobals();
            }
        }

        public function testATokenOnlyInTheQueryStringDoesNotTriggerR001OnAHuman(): void
        {
            try {
                $this->withRules([['ruleCode' => 'R001', 'enabled' => true, 'appliesTo' => 'AGENT', 'tier' => 1]]);
                $this->serverDown();
                $this->presentTokenVia('from-a-link', false, true);
                $this->mode('strict');
                self::assertNull($this->place(), 'R001 es sólo de agentes y el enlace no crea un agente');
            } finally {
                $this->clearRequestGlobals();
            }
        }

        public function testATokenInThePostBodyStillMakesTheRequestAgentic(): void
        {
            try {
                $this->presentTokenVia('from-the-form', true, false);
                self::assertSame('from-the-form', InternalErrorGuard::presentedToken());
                self::assertTrue(InternalErrorGuard::requestIsAgentic());

                $this->client->snapshotThrows = new \RuntimeException('boom');
                foreach (self::MODES as $mode) {
                    $this->mode($mode);
                    $msg = $this->place();
                    self::assertNotNull($msg, $mode);
                    self::assertStringContainsString('trusteed:CEL-INTERNAL-ERROR', $msg, $mode);
                }
            } finally {
                $this->clearRequestGlobals();
            }
        }

        public function testAPostTokenWinsWhenTheSameFieldAlsoComesInTheQueryString(): void
        {
            try {
                $this->presentTokenVia('both', true, true);
                self::assertSame('both', InternalErrorGuard::presentedToken());
            } finally {
                $this->clearRequestGlobals();
            }
        }

        public function testTheCookieStillCountsWhenTheQueryStringCarriesAToken(): void
        {
            try {
                $this->presentTokenVia('from-a-link', false, true);
                \Context::getContext()->cookie = (object) ['trusteed_agent_token' => 'cookie-token'];
                self::assertSame('cookie-token', InternalErrorGuard::presentedToken());
            } finally {
                \Context::getContext()->cookie = null;
                $this->clearRequestGlobals();
            }
        }

        public function testThePolicyHoldsAnAgentAndNeverAHuman(): void
        {
            self::assertSame('BLOCK', \Trusteed\Enforcement\FailurePolicy::decideOnInternalError(true));
            self::assertSame('ALLOW', \Trusteed\Enforcement\FailurePolicy::decideOnInternalError(false));
            self::assertSame('trusteed:CEL-INTERNAL-ERROR', InternalErrorGuard::RULE_CODE);
        }
    }
}
