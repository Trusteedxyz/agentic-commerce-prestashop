<?php

declare(strict_types=1);

namespace Trusteed\Tests\Enforcement;

require_once __DIR__ . '/ValidateOrderHookFlowHarness.php';

use PHPUnit\Framework\TestCase;
use Trusteed\Enforcement\WebmcpCartPort;
use Trusteed\Enforcement\WebmcpCheckout;

/** Carrito de pruebas: registra el orden de las llamadas. */
final class FakeWebmcpCart implements WebmcpCartPort
{
    /** @var list<string> */
    public array $log = [];
    public bool $empty = false;
    /** @var list<string> */
    public array $carriers = ['3', '7'];
    /** @var list<array<string,mixed>> */
    public array $applied = [];

    public function cartId(): int { return 42; }
    public function isEmpty(): bool { return $this->empty; }
    public function recalculate(): void { $this->log[] = 'recalculate'; }
    public function carrierIds(): array { $this->log[] = 'carriers'; return $this->carriers; }
    public bool $hasAddress = true;
    /** @var list<string> */
    public array $ignoredByPort = [];
    public function hasDeliveryAddress(): bool { return $this->hasAddress; }
    public function applyUpdate(array $update): array { $this->log[] = 'apply'; $this->applied[] = $update; return $this->ignoredByPort; }
    public function describe(): array { $this->log[] = 'describe'; return ['status' => 'open', 'currency' => 'EUR']; }
    public function checkoutUrl(): string { return 'https://shop.test/order'; }
}

/** B1 / Task 7 — endpoint WebMCP del checkout de PrestaShop (misma conducta que Magento y WooCommerce). */
final class WebmcpCheckoutControllerTest extends TestCase
{
    private const TOKEN = 'static-token-abc';
    private const SHOP  = 'https://shop.test';

    private FakeWebmcpCart $cart;
    /** @var list<int> */
    private array $marks = [];
    private int $signatureCalls = 0;
    private bool $signatureThrows = false;
    private bool $markOk = true;

    protected function setUp(): void
    {
        $this->cart = new FakeWebmcpCart();
        $this->marks = [];
        $this->signatureCalls = 0;
        $this->signatureThrows = false;
        $this->markOk = true;
    }

    private function sut(): WebmcpCheckout
    {
        return new WebmcpCheckout(
            $this->cart,
            function (int $cartId): bool {
                $this->marks[] = $cartId;
                $this->cart->log[] = 'mark';

                return $this->markOk;
            },
            function (): void {
                $this->signatureCalls++;
                if ($this->signatureThrows) {
                    throw new \RuntimeException('boom');
                }
            }
        );
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function call(array $body, array $over = []): array
    {
        $a = $over + [
            'enabled' => true, 'method' => 'POST', 'sent' => self::TOKEN, 'expected' => self::TOKEN,
            'origin' => null, 'shop' => self::SHOP, 'raw' => json_encode($body),
        ];

        return $this->sut()->handle($a['enabled'], $a['method'], $a['sent'], $a['expected'], $a['origin'], $a['shop'], $a['raw']);
    }

    private function getTool(): array { return ['tool' => 'get_checkout', 'arguments' => []]; }

    public function testOffAnswers404AndTouchesNothing(): void
    {
        [$code] = $this->call($this->getTool(), ['enabled' => false]);
        self::assertSame(404, $code);
        self::assertSame([], $this->marks);
        self::assertSame(0, $this->signatureCalls);
        self::assertSame([], $this->cart->log);
    }

    public function testNonPostIs405(): void
    {
        [$code] = $this->call($this->getTool(), ['method' => 'GET']);
        self::assertSame(405, $code);
        self::assertSame([], $this->marks);
    }

    /** @dataProvider badTokens */
    public function testMissingOrWrongTokenIs403AndUnmarked(?string $sent): void
    {
        [$code] = $this->call($this->getTool(), ['sent' => $sent]);
        self::assertSame(403, $code);
        self::assertSame([], $this->marks);
        self::assertSame([], $this->cart->log);
    }

    public static function badTokens(): array
    {
        return [[null], [''], ['nope']];
    }

    public function testEmptyExpectedTokenNeverMatches(): void
    {
        [$code] = $this->call($this->getTool(), ['sent' => '', 'expected' => '']);
        self::assertSame(403, $code);
    }

    public function testTokenInTheBodyDoesNotCount(): void
    {
        $body = $this->getTool() + ['token' => self::TOKEN, 'static_token' => self::TOKEN];
        [$code] = $this->call($body, ['sent' => null]);
        self::assertSame(403, $code);
        self::assertSame([], $this->marks);
    }

    /** @dataProvider foreignOrigins */
    public function testForeignOriginIs403Unmarked(string $origin): void
    {
        [$code] = $this->call($this->getTool(), ['origin' => $origin]);
        self::assertSame(403, $code);
        self::assertSame([], $this->marks);
        self::assertSame(0, $this->signatureCalls);
    }

    public static function foreignOrigins(): array
    {
        return [['https://evil.test'], ['null'], ['http://shop.test'], ['https://shop.test:8443'], ['https://shop.test.evil.test']];
    }

    public function testAbsentOriginAndDefaultPortOriginAreAllowed(): void
    {
        self::assertSame(200, $this->call($this->getTool(), ['origin' => null])[0]);
        self::assertSame(200, $this->call($this->getTool(), ['origin' => 'HTTPS://Shop.Test:443'])[0]);
    }

    public function testSignatureIsCheckedOnEveryAuthorizedCallAndNeverBlocks(): void
    {
        $this->signatureThrows = true;
        self::assertSame(200, $this->call($this->getTool())[0]);
        self::assertSame(200, $this->call($this->getTool())[0]);
        self::assertSame(2, $this->signatureCalls);
    }

    public function testOversizedBodyIs413(): void
    {
        [$code] = $this->call([], ['raw' => str_repeat('a', WebmcpCheckout::MAX_BODY_BYTES + 1)]);
        self::assertSame(413, $code);
        self::assertSame([], $this->marks);
    }

    /** @dataProvider unknownBodies */
    public function testUnknownToolOrBadJsonIs400(string $raw): void
    {
        [$code] = $this->call([], ['raw' => $raw]);
        self::assertSame(400, $code);
        self::assertSame([], $this->marks);
    }

    public static function unknownBodies(): array
    {
        return [['{"tool":"delete_everything"}'], ['not json'], ['{"tool":7}'], ['[]']];
    }

    public function testEmptyCartIsEmptyAndUnmarked(): void
    {
        $this->cart->empty = true;
        [$code, $body] = $this->call($this->getTool());
        self::assertSame([200, ['status' => 'empty']], [$code, $body]);
        self::assertSame([], $this->marks);
    }

    public function testInvalidShippingMethodIs400WritesNothingAndIsUnmarked(): void
    {
        $body = ['tool' => 'update_checkout', 'arguments' => ['checkout' => [
            'buyer' => ['email' => 'a@b.test'], 'fulfillment' => ['method_id' => '999'],
        ]]];
        [$code, $out] = $this->call($body);
        self::assertSame(400, $code);
        self::assertSame('invalid_shipping_method', $out['error']);
        self::assertSame([], $this->cart->applied);
        self::assertSame([], $this->marks);
    }

    public function testRecalculatesBeforeValidatingCarriers(): void
    {
        $body = ['tool' => 'update_checkout', 'arguments' => ['checkout' => ['fulfillment' => ['method_id' => '3']]]];
        $this->call($body);
        self::assertSame(['recalculate', 'carriers', 'mark', 'apply', 'recalculate', 'describe'], $this->stripRepeats($this->cart->log));
    }

    /** Quita 'recalculate' del final duplicado en caso de que el adaptador lo repita: el orden relevante es mark antes de apply. */
    private function stripRepeats(array $log): array { return $log; }

    public function testUpdateAppliesOnlyTheWhitelistAndMarksBeforeDispatch(): void
    {
        $body = ['tool' => 'update_checkout', 'arguments' => ['checkout' => [
            'buyer' => ['email' => 'a@b.test', 'phone' => '600', 'is_admin' => true],
            'fulfillment' => [
                'method_id' => '7',
                'address' => ['address1' => '<b>Calle 1</b>', 'city' => 'Madrid', 'id_customer' => 9, 'postal_code' => '28001'],
            ],
            'payment' => ['x' => 1],
            'total' => 0,
        ]]];
        [$code, $out] = $this->call($body);
        self::assertSame(200, $code);
        self::assertSame('open', $out['status']);
        self::assertSame([42], $this->marks);
        self::assertLessThan(array_search('apply', $this->cart->log, true), array_search('mark', $this->cart->log, true));
        self::assertSame([[
            'email' => 'a@b.test',
            'address' => ['address1' => 'Calle 1', 'city' => 'Madrid', 'postal_code' => '28001'],
            'method_id' => '7',
        ]], $this->cart->applied);
    }

    public function testBadEmailIsDroppedAndReportedAsIgnored(): void
    {
        $body = ['tool' => 'update_checkout', 'arguments' => ['checkout' => ['buyer' => ['email' => 'not-an-email']]]];
        [, $out] = $this->call($body);
        self::assertSame([[]], $this->cart->applied);
        self::assertSame(['buyer.email'], $out['ignored']);
    }

    public function testFieldsTheCartCouldNotApplyAreReturnedAsIgnored(): void
    {
        $this->cart->ignoredByPort = ['buyer.email', 'fulfillment.address'];
        $body = ['tool' => 'update_checkout', 'arguments' => ['checkout' => [
            'buyer' => ['email' => 'a@b.test'], 'fulfillment' => ['address' => ['city' => 'Madrid']],
        ]]];
        [$code, $out] = $this->call($body);
        self::assertSame(200, $code);
        self::assertSame(['buyer.email', 'fulfillment.address'], $out['ignored']);
    }

    public function testNothingIgnoredMeansNoIgnoredKey(): void
    {
        $body = ['tool' => 'update_checkout', 'arguments' => ['checkout' => ['fulfillment' => ['method_id' => '3']]]];
        [, $out] = $this->call($body);
        self::assertArrayNotHasKey('ignored', $out);
    }

    public function testMethodWithoutDeliveryAddressIsAddressRequiredAndWritesNothing(): void
    {
        $this->cart->hasAddress = false;
        $body = ['tool' => 'update_checkout', 'arguments' => ['checkout' => ['fulfillment' => ['method_id' => '3']]]];
        [$code, $out] = $this->call($body);
        self::assertSame(400, $code);
        self::assertSame('address_required', $out['error']);
        self::assertSame([], $this->cart->applied);
        self::assertSame([], $this->marks);
    }

    public function testFailedMarkIs500AndNothingIsDispatched(): void
    {
        $this->markOk = false;
        $body = ['tool' => 'update_checkout', 'arguments' => ['checkout' => ['fulfillment' => ['method_id' => '3']]]];
        [$code, $out] = $this->call($body);
        self::assertSame([500, 'mark_failed'], [$code, $out['error']]);
        self::assertSame([], $this->cart->applied);
        self::assertNotContains('describe', $this->cart->log);
        [$code2, $out2] = $this->call(['tool' => 'complete_checkout', 'arguments' => []]);
        self::assertSame([500, 'mark_failed'], [$code2, $out2['error'] ?? null]);
    }

    public function testCompleteNeverCreatesAnOrderAndReturnsTheCheckoutUrl(): void
    {
        [$code, $out] = $this->call(['tool' => 'complete_checkout', 'arguments' => []]);
        self::assertSame(200, $code);
        self::assertSame(['status' => 'requires_buyer_confirmation', 'checkout_url' => 'https://shop.test/order'], $out);
        self::assertSame([], $this->cart->applied);
        self::assertSame([42], $this->marks);
    }

    public function testGetMarksAndDescribes(): void
    {
        [$code, $out] = $this->call($this->getTool());
        self::assertSame(200, $code);
        self::assertSame('open', $out['status']);
        self::assertSame([42], $this->marks);
    }

    // ── Inyección del script en la página de checkout ───────────────────────

    public function testHeaderHtmlIsEmptyWhenOffOrOffTheCheckoutPage(): void
    {
        self::assertSame('', WebmcpCheckout::headerHtml(false, 'order', 'https://s/e', 't', 'https://s/js'));
        self::assertSame('', WebmcpCheckout::headerHtml(true, 'product', 'https://s/e', 't', 'https://s/js'));
    }

    public function testHeaderHtmlInjectsEscapedConfigAndTheScript(): void
    {
        $html = WebmcpCheckout::headerHtml(true, 'order', 'https://s/e?a=1&b=2', "t</script><b>'\"&", 'https://s/js/checkout-tools.iife.js');
        self::assertStringContainsString('window.__trusteedCheckoutTools=', $html);
        self::assertStringContainsString('<script src="https://s/js/checkout-tools.iife.js"', $html);
        self::assertSame(2, substr_count($html, '</script>'), 'el token no puede cerrar el script');
        self::assertStringNotContainsString("'", explode('</script>', $html)[0]);
        self::assertStringContainsString('\u003C/script\u003E', $html);
        preg_match('/window\.__trusteedCheckoutTools=(\{.*?\});/s', $html, $m);
        $cfg = json_decode($m[1], true);
        self::assertSame("t</script><b>'\"&", $cfg['nonce']);
        self::assertSame('X-Trusteed-Token', $cfg['nonceHeader']);
        self::assertSame('prestashop', $cfg['platform']);
        self::assertSame('https://s/e?a=1&b=2', $cfg['endpoint']);
    }

    public function testSettingIsExactOnAndUninstallDeletesIt(): void
    {
        self::assertTrue(WebmcpCheckout::isOn('on'));
        foreach (['', 'off', 'ON', ' on', '1', null] as $v) {
            self::assertFalse(WebmcpCheckout::isOn($v), var_export($v, true));
        }
        $src = (string) file_get_contents(__DIR__ . '/../../trusteed.php');
        self::assertStringContainsString("Configuration::deleteByName('TRUSTEED_WEBMCP_CHECKOUT_TOOLS')", $src);
        self::assertStringContainsString("registerHook('displayHeader')", $src);
        self::assertStringContainsString('function hookDisplayHeader', $src);
        self::assertFileExists(__DIR__ . '/../../views/js/checkout-tools.iife.js');
        self::assertFileExists(__DIR__ . '/../../controllers/front/webmcpcheckout.php');
    }
}
