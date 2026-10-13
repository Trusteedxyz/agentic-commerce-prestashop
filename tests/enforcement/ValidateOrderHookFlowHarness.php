<?php

declare(strict_types=1);

/**
 * Shared harness (not a *Test.php) for ValidateOrderHookAgenticFlowTest and
 * ValidateOrderHookDegradedFlowTest.
 *
 * Runs the main flow of `ValidateOrderHook::evaluateCart()` FOR REAL instead of
 * reading it as text:
 *
 *   - derived routing: every enabled rule reaches the server (R035 alone, R001
 *     alone), and with none enabled the server is not called;
 *   - a presented but invalid token outside `strict` is an agent for the server
 *     (`endpointClass: agentic_endpoint` + `_agent_token_*`), not an organic one;
 *   - a legitimate token VERIFIES against the LIST-shaped resolver the server
 *     emits, and a forged one does not;
 *   - the cause of the failure decides (tampered snapshot, unavailable, layer 2
 *     down with a verified snapshot, also from cache, layer switched off); a
 *     `strict` agent with layer 2 down and a cache gets BLOCK;
 *   - a human is never blocked by a failure of ours, only by a merchant ALL rule
 *     in the offline valve;
 *   - the organic payload comes out byte for byte in its usual shape.
 *
 * The `SnapshotClient` is the REAL one end to end (download, verification with
 * JWKS retry, 5 min and 24 h caches, layer 2 body and HMAC, nonce): only
 * `httpGet`/`httpPost`, the network edge, are replaced. The served snapshot is
 * the shared fixture's `snapshotPayload` (real shape, LIST resolver) signed with
 * a test platform key published in the served JWKS, with current dates and the
 * test agent's key appended at the end of the list. Tokens are really signed
 * (Ed25519, sodium).
 *
 * PS global stubs are declared only if they do not exist: no test in the
 * phpunit.xml suites declares `\Cart`, `\Tools`, `\Currency`, `\Db`,
 * `\PrestaShopLogger` or `\PrestaShopException`, so they do not collide with
 * the bootstrap.
 */

namespace {
    if (!defined('_DB_PREFIX_')) {
        define('_DB_PREFIX_', 'ps_');
    }

    if (!function_exists('pSQL')) {
        function pSQL($string): string
        {
            return addslashes((string) $string);
        }
    }

    if (!class_exists('Db')) {
        class Db
        {
            /** @var list<string> */
            public static array $executed = [];

            /** @var list<string> read SQL received by getRow() */
            public static array $queried = [];

            public static function getInstance(): self
            {
                return new self();
            }

            public function execute($sql): bool
            {
                self::$executed[] = (string) $sql;
                return true;
            }

            /** @return array<string,mixed>|false */
            public function getRow($sql)
            {
                self::$queried[] = (string) $sql;
                return false;
            }
        }
    }

    if (!class_exists('Cart')) {
        class Cart
        {
            public const BOTH = 3;

            public $id = 42;
            public $id_currency = 1;
            public $id_customer = 0;
            public $id_address_delivery = 0;
            public $id_address_invoice = 0;
            public float $total = 100.0;

            public function getProducts(): array
            {
                return [];
            }

            public function getOrderTotal($withTaxes = true, $type = self::BOTH): float
            {
                return $this->total;
            }
        }
    }

    if (!class_exists('Currency')) {
        class Currency
        {
            public static function getCurrencyInstance(int $id): object
            {
                return (object) ['iso_code' => 'EUR'];
            }
        }
    }

    if (!class_exists('Tools')) {
        class Tools
        {
            /** @var array<string,string> */
            public static array $mockValues = [];

            /** @param mixed $default @return mixed */
            public static function getValue(string $key, $default = false)
            {
                return self::$mockValues[$key] ?? $default;
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
            public const LOG_SEVERITY_INFO = 1;
            public const LOG_SEVERITY_WARNING = 2;
            public const LOG_SEVERITY_ERROR = 3;

            /** @var list<string> */
            public static array $messages = [];

            public static function addLog($message, $severity = 1, $errorCode = null, $objectType = null, $objectId = null, $allowDuplicate = false): bool
            {
                self::$messages[] = (string) $message;
                return true;
            }
        }
    }
}

namespace Trusteed\Tests\Enforcement {

    use PHPUnit\Framework\TestCase;
    use TestPsState;
    use Trusteed\Enforcement\SnapshotClient;
    use Trusteed\Enforcement\ValidateOrderHook;

    /** REAL client: only the network edge (`httpGet`/`httpPost`) is a test double. */
    final class FlowNetSnapshotClient extends SnapshotClient
    {
        /** @var array{code:int,body:string,error:string} */
        public array $snapshotResponse = ['code' => 0, 'body' => '', 'error' => 'not set'];
        /** @var list<array{code:int,body:string,error:string}> JWKS responses in order; the last one repeats. */
        public array $jwksResponses = [];
        public int $jwksFetches = 0;
        public int $snapshotFetches = 0;
        public ?\Throwable $snapshotThrows = null;
        /** Simulates the 24 h long cache not existing (without touching the 5 min one). */
        public bool $noLocalCache = false;
        /** Fake clock of the JWKS negative cache (null = the real one). */
        public ?int $fakeNow = null;
        /** Simulates the JWKS negative cache write failing. */
        public bool $retryCacheWriteFails = false;
        /** @var array{code:int,body:string,error:string} */
        public array $evaluateResponse = ['code' => 200, 'body' => '{"decision":"ALLOW"}', 'error' => ''];
        /** @var array{code:int,body:string,error:string} */
        public array $nonceResponse = ['code' => 200, 'body' => '{}', 'error' => ''];
        /** @var list<array{url:string,body:string}> */
        public array $posts = [];
        /** @var list<string> Network calls no test expected. */
        public array $unexpected = [];

        protected function httpGet(string $url, array $headers, int $timeout = 8): array
        {
            if (str_ends_with($url, '/.well-known/jwks.json')) {
                $this->jwksFetches++;
                return count($this->jwksResponses) > 1 ? array_shift($this->jwksResponses) : ($this->jwksResponses[0] ?? ['code' => 0, 'body' => '', 'error' => 'no jwks']);
            }
            if (str_contains($url, '/v1/rules/snapshot/')) {
                $this->snapshotFetches++;
                if ($this->snapshotThrows !== null) {
                    throw $this->snapshotThrows;
                }
                return $this->snapshotResponse;
            }
            $this->unexpected[] = 'GET ' . $url;
            return ['code' => 0, 'body' => '', 'error' => 'unexpected'];
        }

        protected function httpPost(string $url, array $headers, string $body, int $timeout): array
        {
            $this->posts[] = ['url' => $url, 'body' => $body];
            if (str_ends_with($url, '/v1/rules/evaluate')) {
                return $this->evaluateResponse;
            }
            if (str_ends_with($url, '/v1/agent-events/nonce-consume')) {
                return $this->nonceResponse;
            }
            $this->unexpected[] = 'POST ' . $url;
            return ['code' => 0, 'body' => '', 'error' => 'unexpected'];
        }

        protected function now(): int
        {
            return $this->fakeNow ?? parent::now();
        }

        protected function persistJwksRetryVerdict(array $entry): void
        {
            if ($this->retryCacheWriteFails) {
                throw new \RuntimeException('cache write failed');
            }
            parent::persistJwksRetryVerdict($entry);
        }

        public function getLocalSnapshotCache(): ?array
        {
            return $this->noLocalCache ? null : parent::getLocalSnapshotCache();
        }

        /** @return list<array<string,mixed>> Bodies sent to /v1/rules/evaluate. */
        public function evaluateBodies(): array
        {
            return $this->bodiesTo('/v1/rules/evaluate');
        }

        /** @return list<array<string,mixed>> */
        public function bodiesTo(string $suffix): array
        {
            $out = [];
            foreach ($this->posts as $p) {
                if (str_ends_with($p['url'], $suffix)) {
                    $out[] = json_decode($p['body'], true);
                }
            }
            return $out;
        }
    }

    abstract class ValidateOrderHookFlowTestCase extends TestCase
    {
        protected const AGENT_DID  = 'did:web:agent.example.test';
        protected const SNAP_KID   = 'trusteed-snapshot-example-kid';
        protected const MODES      = ['strict', 'balanced', 'permissive'];

        protected FlowNetSnapshotClient $client;
        protected ValidateOrderHook $hook;
        protected string $merchantId = '';
        protected string $agentSecretKey = '';
        protected string $agentPublicX = '';
        protected string $platformSecretKey = '';
        protected string $platformPublicX = '';
        protected array $rules = [];
        protected bool $internalErrorExpected = false;

        protected function setUp(): void
        {
            TestPsState::reset();
            (new \ReflectionProperty(SnapshotClient::class, 'memCache'))->setValue(null, []);
            \Tools::$mockValues = [];
            \PrestaShopLogger::$messages = [];
            \Db::$executed = [];
            $this->internalErrorExpected = false;

            $this->merchantId = self::realPayload()['merchantId'];
            TestPsState::$merchantId = $this->merchantId;
            TestPsState::$config = [
                'TRUSTEED_CEL_ENABLED'       => '1',
                'TRUSTEED_CEL_FALLBACK_MODE' => 'balanced',
            ];

            $agent                  = sodium_crypto_sign_keypair();
            $this->agentSecretKey   = sodium_crypto_sign_secretkey($agent);
            $this->agentPublicX     = SnapshotClient::base64UrlEncode(sodium_crypto_sign_publickey($agent));
            $platform               = sodium_crypto_sign_keypair();
            $this->platformSecretKey = sodium_crypto_sign_secretkey($platform);
            $this->platformPublicX  = SnapshotClient::base64UrlEncode(sodium_crypto_sign_publickey($platform));

            $this->client = new FlowNetSnapshotClient('https://api.example.test', $this->merchantId, 'inst_test', 'example-hmac-secret');
            $this->client->jwksResponses = [$this->jwks([self::SNAP_KID => $this->platformPublicX])];
            $this->rules = self::realPayload()['rules'];
            $this->serveSnapshot();

            $client     = $this->client;
            $this->hook = new ValidateOrderHook(static fn (int $shopId): ?SnapshotClient => $client);
        }

        protected function tearDown(): void
        {
            self::assertSame([], $this->client->unexpected, 'llamadas de red inesperadas');
            if (!$this->internalErrorExpected) {
                self::assertSame(0, $this->logsContaining('[trusteed.cel.evaluator_error_human_allowed]'), 'ningún error interno tragado en silencio');
            }
            TestPsState::reset();
            \Tools::$mockValues = [];
        }

        // ── Harness ──────────────────────────────────────────────────────────

        protected static function realPayload(): array
        {
            $path = __DIR__ . '/../fixtures/platform-flow-conformance.json';
            $json = json_decode((string) file_get_contents($path), true);
            self::assertIsArray($json, 'the conformance fixture must exist under tests/fixtures');
            return $json['snapshotResolverShape']['snapshotPayload'];
        }

        /** Real `snapshotPayload` + current dates + the test agent's key at the end of the LIST. */
        protected function payload(): array
        {
            $p = self::realPayload();
            self::assertTrue(array_is_list($p['agentDidResolver']), 'el servidor emite una lista');
            $p['generatedAt']      = gmdate('Y-m-d\TH:i:s\Z', time() - 5);
            $p['validUntil']       = gmdate('Y-m-d\TH:i:s\Z', time() + 300);
            $p['rules']            = $this->rules;
            $p['agentDidResolver'] = array_merge($p['agentDidResolver'], [[
                'did'          => self::AGENT_DID,
                'publicKeyJwk' => ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => $this->agentPublicX],
            ]]);
            return $p;
        }

        protected function signSnapshot(array $payload, ?string $secretKey = null, string $kid = self::SNAP_KID): string
        {
            $input = SnapshotClient::base64UrlEncode(json_encode(['alg' => 'EdDSA', 'kid' => $kid], JSON_THROW_ON_ERROR))
                . '.' . SnapshotClient::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
            return $input . '.' . SnapshotClient::base64UrlEncode(sodium_crypto_sign_detached($input, $secretKey ?? $this->platformSecretKey));
        }

        protected function serveSnapshot(): void
        {
            $this->client->snapshotResponse = ['code' => 200, 'body' => $this->signSnapshot($this->payload()), 'error' => ''];
        }

        /** Really signed and then altered: the served payload is no longer the signed one. */
        protected function serveTamperedSnapshot(): void
        {
            [$h, , $s] = explode('.', $this->signSnapshot($this->payload()));
            $forged    = array_merge($this->payload(), ['rules' => []]);
            $this->client->snapshotResponse = [
                'code'  => 200,
                'body'  => $h . '.' . SnapshotClient::base64UrlEncode(json_encode($forged, JSON_THROW_ON_ERROR)) . '.' . $s,
                'error' => '',
            ];
        }

        protected function snapshotDown(): void
        {
            $this->client->snapshotResponse = ['code' => 0, 'body' => '', 'error' => 'Connection refused'];
        }

        /** @param array<string,string> $keys kid => x */
        protected function jwks(array $keys): array
        {
            $out = [];
            foreach ($keys as $kid => $x) {
                $out[] = ['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => $kid, 'x' => $x];
            }
            return ['code' => 200, 'body' => json_encode(['keys' => $out], JSON_THROW_ON_ERROR), 'error' => ''];
        }

        protected function withRules(array $rules): void
        {
            $this->rules = $rules;
            $this->serveSnapshot();
        }

        protected function mode(string $mode): void
        {
            TestPsState::$config['TRUSTEED_CEL_FALLBACK_MODE'] = $mode;
        }

        protected function presentToken(string $token): void
        {
            \Tools::$mockValues['_trusteed_agent_token'] = $token;
        }

        /**
         * Ed25519 token bound to the stub's empty cart (ValidateOrderHook hash).
         *
         * @param array<string,mixed> $extraClaims Additional claims (e.g. agentTrustScore).
         * @param array<string,mixed> $extraHeader Extra fields of the protected header (e.g. `crit`); signed all the same.
         * @param list<string>        $dropClaims  Claims REMOVED from the payload (`['jti']` gives the exact shape
         *                                         of the canonical test token generator: `nonce` without `jti`).
         */
        protected function token(?string $secretKey = null, string $did = self::AGENT_DID, array $extraClaims = [], array $extraHeader = [], array $dropClaims = []): string
        {
            $intent = hash('sha256', json_encode([
                'merchantId' => $this->merchantId,
                'lineItems'  => [],
                'currency'   => 1,
            ], JSON_THROW_ON_ERROR));
            $header  = ['alg' => 'EdDSA', 'typ' => 'trusteed-agent-token+jwt', 'kid' => $did . '#k1'] + $extraHeader;
            $payload = $extraClaims + [
                'iss'                => $did,
                'aud'                => 'trusteed',
                'merchantId'         => $this->merchantId,
                'iat'                => time(),
                'exp'                => time() + 120,
                'nonce'              => bin2hex(random_bytes(12)),
                'jti'                => bin2hex(random_bytes(12)),
                'checkoutIntentHash' => $intent,
            ];
            foreach ($dropClaims as $claim) {
                unset($payload[$claim]);
            }
            $input = SnapshotClient::base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR))
                . '.' . SnapshotClient::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
            return $input . '.' . SnapshotClient::base64UrlEncode(sodium_crypto_sign_detached($input, $secretKey ?? $this->agentSecretKey));
        }

        protected function forgedToken(string $did = self::AGENT_DID): string
        {
            return $this->token(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()), $did);
        }

        protected function serverDown(): void
        {
            $this->client->evaluateResponse = ['code' => 503, 'body' => '', 'error' => ''];
        }

        protected function logsContaining(string $needle): int
        {
            return count(array_filter(\PrestaShopLogger::$messages, static fn (string $m): bool => str_contains($m, $needle)));
        }

        /** Runs the hook and returns the block message, or null if it passes. */
        protected function outcome(): ?string
        {
            try {
                $this->hook->evaluateCart(new \Cart());
                return null;
            } catch (\PrestaShopException $e) {
                return $e->getMessage();
            }
        }

        protected function storedDids(): array
        {
            return array_values(array_filter(\Db::$executed, static fn (string $sql): bool => str_starts_with($sql, 'INSERT') && str_contains($sql, 'trusteed_agent_carts')));
        }
    }
}
