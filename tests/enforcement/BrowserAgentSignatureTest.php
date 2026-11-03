<?php

declare(strict_types=1);

/**
 * Port de BrowserAgentSignatureTest (WooCommerce/Magento): firma RFC 9421 / Web Bot Auth de un agente que
 * compra NAVEGANDO, verificada contra la API de Trusteed con el secreto S2S del módulo, y su efecto en
 * ValidateOrderHook::evaluateCart() (flujo real, sólo el borde de red sustituido).
 */

namespace Trusteed\Tests\Enforcement;

require_once __DIR__ . '/ValidateOrderHookFlowHarness.php';

use TestPsState;
use Trusteed\AgenticTools\BackendApiClientInterface;
use Trusteed\AgenticTools\McpToolException;
use Trusteed\Enforcement\BrowserAgentSignature;
use Trusteed\Enforcement\ValidateOrderHook;

final class FakeSignatureApiClient implements BackendApiClientInterface
{
    /** @var list<array{endpoint:string,payload:array<string,mixed>,merchantId:string}> */
    public array $calls = [];
    public bool $throws = false;
    /** @var array<string,mixed>|null */
    public ?array $response = null;

    public function call(string $endpoint, array $payload, ?string $idempotencyKey = null): array
    {
        throw new \LogicException('no debe usarse la ruta Bearer');
    }

    public function callWithS2S(string $endpoint, array $payload, string $merchantId, ?string $idempotencyKey = null): array
    {
        $this->calls[] = ['endpoint' => $endpoint, 'payload' => $payload, 'merchantId' => $merchantId];
        if ($this->throws) {
            throw new McpToolException(McpToolException::CODE_UNREACHABLE, 'timeout', null);
        }
        return $this->response ?? [];
    }
}

final class BrowserAgentSignatureTest extends ValidateOrderHookFlowTestCase
{
    private const INPUT = 'sig1=("@method" "@authority" "x-custom");created=1;keyid="k1";tag="web-bot-auth"';

    private FakeSignatureApiClient $api;
    private int $factoryCalls = 0;
    /** @var array<string,mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->api          = new FakeSignatureApiClient();
        $this->factoryCalls = 0;
        $this->apiSays('verified');
        TestPsState::$config['PS_SHOP_DOMAIN_SSL'] = 'shop.example.com';
        TestPsState::$config['PS_SSL_ENABLED']     = '1';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        parent::tearDown();
    }

    private function apiSays(string $status, string $key = 'key-123'): void
    {
        $this->api->response = ['verified' => $status === 'verified', 'verificationStatus' => $status, 'provider' => 'openai', 'keyId' => $key];
    }

    /** @return array<string,string> */
    private static function signed(array $extra = []): array
    {
        return array_merge([
            'signature-input' => self::INPUT,
            'signature'       => 'sig1=:YWJj:',
            'signature-agent' => '"https://agent.example"',
            'x-custom'        => 'covered',
        ], $extra);
    }

    private function sig(string $mode = 'enforce'): BrowserAgentSignature
    {
        TestPsState::$config['TRUSTEED_BROWSER_SIGNATURE_MODE'] = $mode;
        return new BrowserAgentSignature(function (): ?BackendApiClientInterface {
            $this->factoryCalls++;
            return $this->api;
        });
    }

    // ── M3 ───────────────────────────────────────────────────────────────

    public function testNoCallWithoutAllThreeHeaders(): void
    {
        $sig = $this->sig();
        foreach (['signature', 'signature-input', 'signature-agent'] as $missing) {
            $h = self::signed();
            unset($h[$missing]);
            self::assertNull($sig->resolve($h, 'POST', '/order'));
        }
        self::assertNull($sig->resolve(self::signed(['signature' => 'other=:YWJj:']), 'POST', '/order'), 'etiqueta no común');
        self::assertSame([], $this->api->calls);
        self::assertSame(0, $this->factoryCalls, 'el secreto solo se lee si hay que llamar');
    }

    public function testOffModeMakesNoCall(): void
    {
        self::assertNull($this->sig('off')->resolve(self::signed(), 'POST', '/order'));
        self::assertSame([], $this->api->calls);
        self::assertSame(0, $this->factoryCalls);
    }

    public function testModeDefaultsToObserveAndRejectsUnknownValues(): void
    {
        self::assertSame('observe', BrowserAgentSignature::normalizeMode(null));
        self::assertSame('observe', BrowserAgentSignature::normalizeMode('bogus'));
        self::assertSame('enforce', BrowserAgentSignature::normalizeMode(' ENFORCE '));
        self::assertSame('off', BrowserAgentSignature::normalizeMode('off'));
        unset(TestPsState::$config['TRUSTEED_BROWSER_SIGNATURE_MODE']);
        self::assertSame('observe', (new BrowserAgentSignature(fn () => null))->mode());
    }

    public function testNoCallWhenTheClientIsNotConfigured(): void
    {
        TestPsState::$config['TRUSTEED_BROWSER_SIGNATURE_MODE'] = 'enforce';
        self::assertNull((new BrowserAgentSignature(fn () => null))->resolve(self::signed(), 'POST', '/order'));
    }

    // ── request / H1 ─────────────────────────────────────────────────────

    public function testRequestCarriesMerchantEndpointAndCoveredHeaders(): void
    {
        $r = $this->sig()->resolve(self::signed(), 'post', '/order?x=1');
        self::assertSame('verified', $r['status']);
        self::assertCount(1, $this->api->calls);
        $c = $this->api->calls[0];
        self::assertSame('/api/v1/embed/agentic-tools/verify-agent-signature', $c['endpoint']);
        self::assertSame($this->merchantId, $c['merchantId']);
        self::assertSame('POST', $c['payload']['method']);
        self::assertSame('covered', $c['payload']['headers']['x-custom']);
        self::assertSame('shop.example.com', $c['payload']['headers']['host']);
    }

    public function testUrlFromConfiguredShopDomainNotHostHeader(): void
    {
        $this->sig()->resolve(self::signed(['host' => 'evil.example']), 'POST', '/order?y=2');
        self::assertSame('https://shop.example.com/order?y=2', $this->api->calls[0]['payload']['url']);
        self::assertSame('shop.example.com', $this->api->calls[0]['payload']['headers']['host']);
    }

    public function testRequestUriCannotSmuggleAnotherAuthority(): void
    {
        $this->sig()->resolve(self::signed(), 'POST', '//evil.example/path');
        self::assertSame('shop.example.com', parse_url($this->api->calls[0]['payload']['url'], PHP_URL_HOST));
    }

    public function testNeverForwardsCookieAuthorizationOrPrestaShopTokenHeaders(): void
    {
        $input = 'sig1=("@authority" "cookie" "authorization" "proxy-authorization" "token" "x-csrf-token" "x-custom")';
        $h = self::signed([
            'signature-input' => $input,
            'cookie' => 'PrestaShop-abc=1', 'authorization' => 'Bearer t', 'proxy-authorization' => 'x',
            'token' => 'tk', 'x-csrf-token' => 'tk',
        ]);
        $this->sig()->resolve($h, 'POST', '/order');
        $sent = $this->api->calls[0]['payload']['headers'];
        foreach (['cookie', 'authorization', 'proxy-authorization', 'token', 'x-csrf-token'] as $name) {
            self::assertArrayNotHasKey($name, $sent);
        }
        self::assertArrayHasKey('x-custom', $sent);
    }

    public function testNeverForwardsOurOwnCsrfHeader(): void
    {
        $h = self::signed([
            'signature-input' => 'sig1=("@authority" "x-trusteed-token" "x-custom")',
            'x-trusteed-token' => 'tk',
        ]);
        $this->sig()->resolve($h, 'POST', '/order');
        $sent = $this->api->calls[0]['payload']['headers'];
        self::assertArrayNotHasKey('x-trusteed-token', $sent);
        self::assertArrayHasKey('x-custom', $sent);
    }

    public function testCollectHeadersFromServerSkipsCredentials(): void
    {
        $server = [
            'HTTP_SIGNATURE_INPUT' => 'sig1=("@authority" "cookie" "token" "x-custom")',
            'HTTP_SIGNATURE'       => 'sig1=:YWJj:',
            'HTTP_SIGNATURE_AGENT' => '"https://agent.example"',
            'HTTP_X_CUSTOM'        => 'covered',
            'HTTP_COOKIE'          => 'PrestaShop-abc=1',
            'HTTP_TOKEN'           => 'tk',
            'HTTP_X_OTHER'         => 'not covered',
        ];
        $out = BrowserAgentSignature::collectHeaders($server);
        self::assertSame(['signature', 'signature-input', 'signature-agent', 'x-custom'], array_keys($out));
    }

    // ── H1 / M1 ──────────────────────────────────────────────────────────

    public function testVerifiedWithoutAuthorityOrTargetUriDowngrades(): void
    {
        $h = self::signed(['signature-input' => 'sig1=("@method" "x-custom");keyid="k1"']);
        $r = $this->sig()->resolve($h, 'POST', '/order');
        self::assertSame('unverified', $r['status']);
        self::assertSame('', $r['identity']);
    }

    public function testTargetUriCoveredIsEnough(): void
    {
        $h = self::signed(['signature-input' => 'sig1=("@target-uri");keyid="k1"']);
        self::assertSame('verified', $this->sig()->resolve($h, 'POST', '/order')['status']);
    }

    public function testIdentityBoundToHttpsSignatureAgentOrigin(): void
    {
        $r = $this->sig()->resolve(self::signed(['signature-agent' => '"https://Agent.Example:8443/dir"']), 'POST', '/order');
        self::assertSame('wba:https://agent.example:8443#key-123', $r['identity']);
    }

    public function testIdentityRequiresHttpsSignatureAgent(): void
    {
        foreach (['"http://agent.example"', 'garbage'] as $agent) {
            $r = $this->sig()->resolve(self::signed(['signature-agent' => $agent]), 'POST', '/order');
            self::assertSame('unverified', $r['status']);
            self::assertSame('', $r['identity']);
        }
    }

    public function testResultIsCachedWithinTheRequestIncludingErrors(): void
    {
        $sig = $this->sig();
        $sig->resolve(self::signed(), 'POST', '/order');
        $sig->resolve(self::signed(), 'POST', '/order');
        self::assertCount(1, $this->api->calls);

        $this->api->throws = true;
        $err = $this->sig();
        $err->resolve(self::signed(['x-custom' => 'other']), 'POST', '/order');
        $err->resolve(self::signed(['x-custom' => 'other']), 'POST', '/order');
        self::assertCount(2, $this->api->calls, 'el error también se cachea: una llamada por petición');
    }

    // ── fallos: nunca reclasifican ───────────────────────────────────────

    public function testVerifierTimeoutIsError(): void
    {
        $this->api->throws = true;
        $r = $this->sig()->resolve(self::signed(), 'POST', '/order');
        self::assertSame('error', $r['status']);
        self::assertSame('', $r['identity']);
    }

    public function testUnknownResponseShapeIsError(): void
    {
        $this->api->response = ['verificationStatus' => 'maybe'];
        self::assertSame('error', $this->sig()->resolve(self::signed(), 'POST', '/a')['status']);
        $this->api->response = [];
        self::assertSame('error', $this->sig()->resolve(self::signed(['x-custom' => 'b']), 'POST', '/b')['status']);
    }

    public function testDefaultClientUsesTheS2sSecretAndTheShortTimeout(): void
    {
        TestPsState::$config['TRUSTEED_API_BASE'] = 'https://api.example.test';
        self::assertNull(BrowserAgentSignature::buildDefaultClient(), 'sin secreto no hay cliente');
        TestPsState::$config['TRUSTEED_EMBED_S2S_SECRET'] = 's2s-secret';
        $client = BrowserAgentSignature::buildDefaultClient();
        self::assertInstanceOf(BackendApiClientInterface::class, $client);
        $p = static fn (string $n) => (new \ReflectionProperty($client, $n))->getValue($client);
        self::assertSame('s2s-secret', $p('s2sSecret') ?? $p('bootstrapToken'));
        self::assertSame(1500, $p('timeoutMs'));
        self::assertSame(1, $p('maxAttempts'));
    }

    // ── flujo real del hook ──────────────────────────────────────────────

    /** @return array<string,mixed> cuerpo enviado a /v1/rules/evaluate */
    private function checkout(string $mode, ?string $apiStatus = 'verified'): array
    {
        $this->api          = new FakeSignatureApiClient();
        $this->client->posts = [];
        if ($apiStatus === null) {
            $this->api->throws = true;
        } else {
            $this->apiSays($apiStatus);
        }
        foreach (self::signed() as $name => $value) {
            $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/order';
        $this->withRules([['ruleCode' => 'R035.max-order-value', 'enabled' => true]]);
        $client = $this->client;
        $hook   = new ValidateOrderHook(static fn (int $shopId) => $client, $this->sig($mode));
        $hook->evaluateCart(new \Cart());
        $bodies = $this->client->evaluateBodies();
        self::assertCount(1, $bodies);
        return $bodies[0];
    }

    public function testEnforceVerifiedSetsVerifiedSignatureIdentity(): void
    {
        $b = $this->checkout('enforce');
        self::assertSame('wba:https://agent.example#key-123', $b['agentId']);
        self::assertSame('verified_signature', $b['agentIdentityLevel']);
        self::assertSame('agentic_endpoint', $b['endpointClass']);
    }

    public function testEnforceSpoofedMarksAnonymousAgent(): void
    {
        $b = $this->checkout('enforce', 'spoofed');
        self::assertNull($b['agentId']);
        self::assertSame('agentic_endpoint', $b['endpointClass']);
        self::assertArrayNotHasKey('agentIdentityLevel', $b);
    }

    public function testObserveNeverReclassifies(): void
    {
        foreach (['verified', 'spoofed'] as $status) {
            $b = $this->checkout('observe', $status);
            self::assertNull($b['agentId']);
            self::assertArrayNotHasKey('endpointClass', $b);
            self::assertArrayNotHasKey('agentIdentityLevel', $b);
            self::assertNotSame([], $this->api->calls, 'observe sí verifica');
        }
    }

    public function testEnforceUnverifiedOrTimeoutKeepsClassification(): void
    {
        foreach (['unverified', null] as $status) {
            $b = $this->checkout('enforce', $status);
            self::assertNull($b['agentId']);
            self::assertArrayNotHasKey('endpointClass', $b);
            self::assertArrayNotHasKey('agentIdentityLevel', $b);
        }
    }

    public function testHumanCheckoutWithoutSignatureHeadersIsUnchanged(): void
    {
        $this->withRules([['ruleCode' => 'R035.max-order-value', 'enabled' => true]]);
        $client = $this->client;
        $hook   = new ValidateOrderHook(static fn (int $shopId) => $client, $this->sig('enforce'));
        $hook->evaluateCart(new \Cart());
        self::assertSame([], $this->api->calls);
        self::assertSame(['merchantId', 'agentId', 'orderContext', 'platform', 'installationId', 'timestamp'], array_keys($this->client->evaluateBodies()[0]));
    }
}
