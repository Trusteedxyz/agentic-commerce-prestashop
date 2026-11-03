<?php

declare(strict_types=1);

/**
 * Arnés compartido (no es un *Test.php) de ValidateOrderHookAgenticFlowTest y
 * ValidateOrderHookDegradedFlowTest.
 *
 * Auditoría 2026-09-27 (A9 + M3/M4; plan 04 Tasks 8 y 22) — el flujo principal
 * de `ValidateOrderHook::evaluateCart()` ejecutado DE VERDAD, no leído como
 * texto:
 *
 *   - enrutado derivado: toda regla habilitada llega al servidor (R035 sola,
 *     R001 sola), y sin ninguna habilitada no se llama;
 *   - token presentado e inválido fuera de `strict` = agente para el servidor
 *     (`endpointClass: agentic_endpoint` + `_agent_token_*`), no un orgánico;
 *   - Task 22: el token legítimo VERIFICA contra el resolver en forma de LISTA
 *     que emite el servidor, y uno falsificado no;
 *   - Task 22: la causa de la avería decide (snapshot manipulado, no
 *     disponible, capa 2 caída con snapshot verificado —también de caché—,
 *     capa apagada); I1-PS: agente `strict` + capa 2 caída + caché ⇒ BLOCK;
 *   - D1: un humano nunca se bloquea por una avería nuestra; sólo por una
 *     regla ALL del comerciante en la válvula offline;
 *   - el payload orgánico sale byte a byte con la forma de siempre.
 *
 * El `SnapshotClient` es el REAL de punta a punta (descarga, verificación con
 * reintento del JWKS, caché de 5 min y de 24 h, cuerpo y HMAC de la capa 2,
 * nonce): sólo se sustituyen `httpGet`/`httpPost`, el borde de red. El snapshot
 * servido es el `snapshotPayload` del fixture compartido (forma real, resolver
 * en LISTA) firmado con una clave de plataforma de prueba publicada en el JWKS
 * servido, con las fechas al día y la clave del agente de prueba añadida al
 * final de la lista. Los tokens se firman de verdad (Ed25519, sodium).
 *
 * Stubs globales de PS declarados sólo si no existen: ningún test de las
 * suites de phpunit.xml declara `\Cart`, `\Tools`, `\Currency`, `\Db`,
 * `\PrestaShopLogger` ni `\PrestaShopException` (comprobado 2026-09-30), así
 * que no colisionan con el bootstrap.
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

            /** @var list<string> SQL de lectura recibido por getRow() */
            public static array $queried = [];

            /** @var array<string,mixed>|null Fila que devuelve getRow() (null = ninguna). */
            public static ?array $rowToReturn = null;

            /** Simula que execute() falla (columna ausente, error de BD). */
            public static bool $executeFails = false;

            public static function getInstance(): self
            {
                return new self();
            }

            public function execute($sql): bool
            {
                self::$executed[] = (string) $sql;
                return !self::$executeFails;
            }

            /** @return array<string,mixed>|false */
            public function getRow($sql)
            {
                self::$queried[] = (string) $sql;
                return self::$rowToReturn ?? false;
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

    /** Cliente REAL: sólo el borde de red (`httpGet`/`httpPost`) es de prueba. */
    final class FlowNetSnapshotClient extends SnapshotClient
    {
        /** @var array{code:int,body:string,error:string} */
        public array $snapshotResponse = ['code' => 0, 'body' => '', 'error' => 'not set'];
        /** @var list<array{code:int,body:string,error:string}> Respuestas del JWKS en orden; la última se repite. */
        public array $jwksResponses = [];
        public int $jwksFetches = 0;
        public int $snapshotFetches = 0;
        public ?\Throwable $snapshotThrows = null;
        /** Simula que la caché larga de 24 h no existe (sin tocar la de 5 min). */
        public bool $noLocalCache = false;
        /** Reloj falso de la caché negativa del JWKS (null = el real). */
        public ?int $fakeNow = null;
        /** Simula que la escritura de la caché negativa del JWKS falla. */
        public bool $retryCacheWriteFails = false;
        /** @var array{code:int,body:string,error:string} */
        public array $evaluateResponse = ['code' => 200, 'body' => '{"decision":"ALLOW"}', 'error' => ''];
        /** @var array{code:int,body:string,error:string} */
        public array $nonceResponse = ['code' => 200, 'body' => '{}', 'error' => ''];
        /** @var list<array{url:string,body:string}> */
        public array $posts = [];
        /** @var list<string> Llamadas de red que ningún test esperaba. */
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

        /** @return list<array<string,mixed>> Cuerpos enviados a /v1/rules/evaluate. */
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

        // ── Arnés ────────────────────────────────────────────────────────────

        protected static function realPayload(): array
        {
            $path = __DIR__ . '/../../../shared/src/enforcement/__fixtures__/platform-flow-conformance.json';
            $json = json_decode((string) file_get_contents($path), true);
            self::assertIsArray($json, 'el fixture tiene que ser alcanzable desde el monorepo (monta la raíz)');
            return $json['snapshotResolverShape']['snapshotPayload'];
        }

        /** `snapshotPayload` real + fechas al día + la clave del agente de prueba al final de la LISTA. */
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

        /** Firmado de verdad y luego alterado: el payload servido ya no es el firmado. */
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
         * Token Ed25519 ligado al carrito vacío del stub (hash de ValidateOrderHook).
         *
         * @param array<string,mixed> $extraClaims Reclamaciones adicionales (p. ej. agentTrustScore).
         * @param array<string,mixed> $extraHeader Campos extra de la cabecera protegida (p. ej. `crit`); firmada igual.
         * @param list<string>        $dropClaims  Reclamaciones que se QUITAN del payload (B5: `['jti']` da la forma
         *                                         exacta de generate-test-agent-token.ts, `nonce` sin `jti`).
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

        /** Ejecuta el hook y devuelve el mensaje del bloqueo, o null si pasa. */
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
