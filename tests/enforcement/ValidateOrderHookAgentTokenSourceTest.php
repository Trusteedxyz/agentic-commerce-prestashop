<?php

declare(strict_types=1);

/**
 * ValidateOrderHookAgentTokenSourceTest — GHSA-2j2x-5q52-g48m issue 2 (D1).
 *
 * `ValidateOrderHook::resolveAgentToken()` read the cookie fallback via
 * `$cart->getContext()->cookie` — a method that does not exist on
 * PrestaShop's `\Cart` (nor its parent `\ObjectModel`) in the 8.2.x branch,
 * and there is no `__call` magic method to fall back to. ANY checkout that
 * reached this line without `_trusteed_agent_token` already present in the
 * POST body — i.e. every human customer, and any agent using the cookie
 * instead of the request parameter — threw a PHP `\Error` before the module
 * ever requested the signed rule snapshot, landing in PaymentModule's
 * catch(\Throwable) fail-open branch.
 *
 * Fixed to read `\Context::getContext()->cookie`, the correct PrestaShop API.
 *
 * This test uses a faithful `\Cart` stub that does NOT define `getContext()`
 * — same shape as the real PS core — specifically so a regression back to
 * `$cart->getContext()` reproduces the exact production `\Error`.
 *
 * Run:
 *   docker run --rm -v $(pwd):/app -w /app php:8.3-cli \
 *     php vendor/bin/phpunit tests/enforcement/ValidateOrderHookAgentTokenSourceTest.php
 */

namespace {
    // Faithful stub: NO getContext() method, matching real PS \Cart /
    // \ObjectModel in the 8.2.x branch. This is the entire point of the test.
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

    if (!class_exists('Tools')) {
        class Tools
        {
            /** @var array<string,string> */
            public static array $mockValues = [];

            public static function reset(): void
            {
                self::$mockValues = [];
            }

            public static function setMockValue(string $key, string $value): void
            {
                self::$mockValues[$key] = $value;
            }

            /** @param mixed $default @return mixed */
            public static function getValue(string $key, $default = false)
            {
                return self::$mockValues[$key] ?? $default;
            }
        }
    }
}

namespace Trusteed\Tests\Enforcement {

    use PHPUnit\Framework\TestCase;
    use Trusteed\Enforcement\ValidateOrderHook;

    require_once __DIR__ . '/PsEnforcementStubs.php';
    require_once __DIR__ . '/../../src/Enforcement/ValidateOrderHook.php';

    final class ValidateOrderHookAgentTokenSourceTest extends TestCase
    {
        protected function setUp(): void
        {
            \Tools::reset();
            \Context::getContext()->cookie = null;
        }

        /** Invokes the private resolveAgentToken() via reflection. */
        private function resolveAgentToken(\Cart $cart): string
        {
            $hook = new ValidateOrderHook();
            $ref  = new \ReflectionMethod(ValidateOrderHook::class, 'resolveAgentToken');
            $ref->setAccessible(true);

            return (string) $ref->invoke($hook, $cart);
        }

        public function testCookieFallbackResolvesWithoutThrowing(): void
        {
            \Context::getContext()->cookie = (object) ['trusteed_agent_token' => 'cookie-token-value'];
            $cart = new \Cart(42);

            $token = $this->resolveAgentToken($cart);

            $this->assertSame('cookie-token-value', $token);
        }

        public function testNoTokenAnywhereReturnsEmptyStringWithoutThrowing(): void
        {
            // No POST value, no cookie — this is EVERY organic human checkout.
            // Before the fix this line threw \Error before returning at all.
            $cart = new \Cart(43);

            $token = $this->resolveAgentToken($cart);

            $this->assertSame('', $token);
        }

        public function testPostParameterTakesPrecedenceOverCookie(): void
        {
            \Tools::setMockValue('_trusteed_agent_token', 'post-token-value');
            \Context::getContext()->cookie = (object) ['trusteed_agent_token' => 'cookie-token-value'];
            $cart = new \Cart(44);

            $token = $this->resolveAgentToken($cart);

            $this->assertSame('post-token-value', $token);
        }
    }
}
