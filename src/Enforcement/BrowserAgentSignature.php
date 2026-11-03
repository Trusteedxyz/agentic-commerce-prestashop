<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

use Trusteed\AgenticTools\BackendApiClient;
use Trusteed\AgenticTools\BackendApiClientInterface;

/**
 * Firma RFC 9421 / Web Bot Auth de un agente que compra NAVEGANDO la web.
 * Port de `Trusteed_Browser_Agent_Signature` (WooCommerce) y de `BrowserAgentSignature` (Magento): mismo
 * contrato y mismo endurecimiento.
 *
 * Modo `TRUSTEED_BROWSER_SIGNATURE_MODE` (Configuration):
 *  - off      ni una llamada.
 *  - observe  verifica y registra; NO cambia la clasificación (defecto).
 *  - enforce  {@see ValidateOrderHook} sube `verified` a `verified_signature` y trata `spoofed` como agente
 *             anónimo; unverified/error/timeout dejan la clasificación como estaba (fail-open).
 *
 * Mismo cliente HTTP y mismo secreto S2S que `VerifyAgentSignatureTool` (`TRUSTEED_EMBED_S2S_SECRET`, con
 * `TRUSTEED_BOOTSTRAP_TOKEN` de respaldo): {@see BackendApiClient::callWithS2S()}, con tope de 1,5 s y sin reintento.
 */
final class BrowserAgentSignature
{
    public const MODES        = ['off', 'observe', 'enforce'];
    public const DEFAULT_MODE = 'observe';
    public const CONFIG_MODE  = 'TRUSTEED_BROWSER_SIGNATURE_MODE';

    public const TIMEOUT_MS     = 1500;
    public const ENDPOINT       = '/api/v1/embed/agentic-tools/verify-agent-signature';
    public const KNOWN_STATUSES = ['verified', 'spoofed', 'unverified', 'skipped'];

    /** Nunca se reenvían, aunque `Signature-Input` los cubra (credenciales del navegador y de PrestaShop). */
    public const NEVER_FORWARD = [
        'cookie', 'authorization', 'proxy-authorization',
        'token', 'static_token', 'x-token', 'x-csrf-token', 'x-xsrf-token', 'x-trusteed-token',
    ];

    /** @var array{key:string, result:?array}|null Una verificación por petición (errores incluidos). */
    private ?array $cache = null;

    /** @var \Closure(): ?BackendApiClientInterface */
    private \Closure $clientFactory;

    /** @param (\Closure(): ?BackendApiClientInterface)|null $clientFactory null = {@see buildDefaultClient()}. */
    public function __construct(?\Closure $clientFactory = null)
    {
        $this->clientFactory = $clientFactory ?? static fn (): ?BackendApiClientInterface => self::buildDefaultClient();
    }

    public function mode(int $shopId = 0): string
    {
        $raw = $shopId > 0 ? \Configuration::get(self::CONFIG_MODE, null, null, $shopId) : \Configuration::get(self::CONFIG_MODE);

        return self::normalizeMode(is_string($raw) ? $raw : '');
    }

    /** Ausente o desconocido ⇒ `observe`. */
    public static function normalizeMode(?string $raw): string
    {
        $mode = strtolower(trim((string) $raw));

        return in_array($mode, self::MODES, true) ? $mode : self::DEFAULT_MODE;
    }

    /** El secreto se lee aquí, y sólo cuando hay que llamar. null = sin API o sin secreto. */
    public static function buildDefaultClient(): ?BackendApiClientInterface
    {
        $apiBase = (string) \Configuration::get('TRUSTEED_API_BASE');
        $apiBase = $apiBase !== '' ? $apiBase : 'https://api.trusteed.xyz';
        $secret  = (string) \Configuration::get('TRUSTEED_EMBED_S2S_SECRET');
        $secret  = $secret !== '' ? $secret : (string) \Configuration::get('TRUSTEED_BOOTSTRAP_TOKEN');
        if ($secret === '') {
            return null;
        }

        return new BackendApiClient($apiBase, $secret, null, self::TIMEOUT_MS, 1);
    }

    /**
     * Cabeceras relevantes de la petición (minúsculas ⇒ valor) a partir de `$_SERVER`: las tres de firma,
     * content-digest y las que cubre `Signature-Input`, sin credenciales ni cabeceras de token.
     *
     * @param array<string,mixed> $server
     * @return array<string,string>
     */
    public static function collectHeaders(array $server): array
    {
        $get = static function (string $name) use ($server): string {
            $v = $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '';

            return is_string($v) ? $v : '';
        };
        $names = ['signature', 'signature-input', 'signature-agent', 'content-digest'];
        if (preg_match_all('/\(([^)]*)\)/', $get('signature-input'), $lists)) {
            foreach ($lists[1] as $list) {
                if (preg_match_all('/"([^"]*)"/', $list, $covered)) {
                    foreach ($covered[1] as $n) {
                        if ($n !== '' && $n[0] !== '@') {
                            $names[] = strtolower($n);
                        }
                    }
                }
            }
        }
        $out = [];
        foreach (array_unique($names) as $name) {
            $value = self::isForbidden($name) ? '' : $get($name);
            if ($value !== '') {
                $out[$name] = substr($value, 0, 4096);
            }
        }

        return $out;
    }

    private static function isForbidden(string $name): bool
    {
        return in_array($name, self::NEVER_FORWARD, true);
    }

    /**
     * Atajo para el hook: lee método y URI de `$_SERVER`.
     *
     * @param array<string,mixed> $server
     * @return array{status:string, provider:string, keyId:string, identity:string}|null
     */
    public function resolveFromServer(array $server, int $shopId = 0): ?array
    {
        $uri = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '';
        $method = is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'POST';

        return $this->resolve(self::collectHeaders($server), $method, $uri, $shopId);
    }

    /**
     * Solo llama con las tres cabeceras (Signature, Signature-Input, Signature-Agent) y una etiqueta común.
     * `verified` solo se concede si la firma cubre `@authority` o `@target-uri` y hay un origen https en
     * `Signature-Agent` al que atar la identidad (`wba:<origen>#<keyId>`). Nunca lanza.
     *
     * @param array<string,string> $headers Cabeceras (nombre en minúsculas ⇒ valor), de {@see collectHeaders()}.
     * @return array{status:string, provider:string, keyId:string, identity:string}|null null = nada que hacer.
     */
    public function resolve(array $headers, string $method, string $pathAndQuery, int $shopId = 0): ?array
    {
        try {
            return $this->doResolve(array_change_key_case($headers, CASE_LOWER), $method, $pathAndQuery, $shopId);
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('[trusteed.browser_signature.failed] ' . get_class($e), 2);

            return null;
        }
    }

    private function doResolve(array $headers, string $method, string $pathAndQuery, int $shopId): ?array
    {
        $mode       = $this->mode($shopId);
        $merchantId = MerchantResolver::getMerchantId($shopId);
        $origin     = self::siteOrigin();
        if ($mode === 'off' || $merchantId === '' || $origin === '') {
            return null;
        }
        $input = (string) ($headers['signature-input'] ?? '');
        $sig   = (string) ($headers['signature'] ?? '');
        $agent = (string) ($headers['signature-agent'] ?? '');
        if ($input === '' || $sig === '' || $agent === '' || $pathAndQuery === '') {
            return null;
        }
        $components = self::coveredComponents($input);
        $labels     = array_values(array_intersect(array_keys($components), self::signatureLabels($sig)));
        if ($labels === []) {
            return null;
        }

        $key = sha1($input . "\n" . $sig . "\n" . $agent);
        if ($this->cache !== null && $this->cache['key'] === $key) {
            return $this->cache['result'];
        }

        $client = ($this->clientFactory)();
        if ($client === null) {
            return null;
        }

        $target = parse_url($pathAndQuery);
        $path   = is_array($target) && isset($target['path']) && $target['path'] !== '' ? $target['path'] : '/';
        $url    = $origin . $path . (isset($target['query']) ? '?' . $target['query'] : '');

        $forward         = array_filter($headers, static fn ($v, $k) => !self::isForbidden((string) $k) && is_string($v) && $v !== '', ARRAY_FILTER_USE_BOTH);
        $forward['host'] = (string) parse_url($origin, PHP_URL_HOST) . (($port = parse_url($origin, PHP_URL_PORT)) ? ':' . $port : '');

        $result = $this->callApi($client, $merchantId, [
            'method'  => strtoupper($method !== '' ? $method : 'POST'),
            'url'     => $url,
            'headers' => $forward,
        ]);
        $result = $this->bindIdentity($result, $components, $labels, $agent);
        \PrestaShopLogger::addLog(sprintf(
            '[trusteed.browser_signature] mode=%s status=%s provider=%s',
            $mode,
            $result['status'],
            $result['provider']
        ));
        $this->cache = ['key' => $key, 'result' => $result];

        return $result;
    }

    /** Origen (esquema+host) del dominio CONFIGURADO de la tienda; nunca de `Host`. */
    private static function siteOrigin(): string
    {
        $ssl  = (string) \Configuration::get('PS_SSL_ENABLED') === '1';
        $host = trim((string) ($ssl ? (\Configuration::get('PS_SHOP_DOMAIN_SSL') ?: \Configuration::get('PS_SHOP_DOMAIN')) : \Configuration::get('PS_SHOP_DOMAIN')));
        if ($host === '' || preg_match('/\A[A-Za-z0-9.\-]+(:\d{1,5})?\z/', $host) !== 1) {
            return '';
        }

        return ($ssl ? 'https://' : 'http://') . strtolower($host);
    }

    /** `verified` → `unverified` si no cubre autoridad/URI o no hay origen https del agente. */
    private function bindIdentity(array $result, array $components, array $labels, string $agentHeader): array
    {
        $result['identity'] = '';
        if ($result['status'] !== 'verified') {
            return $result;
        }
        $covers = false;
        foreach ($labels as $label) {
            if (array_intersect(['@authority', '@target-uri'], $components[$label])) {
                $covers = true;
            }
        }
        $origin = self::agentOrigin($agentHeader);
        if (!$covers || $origin === '' || $result['keyId'] === '') {
            $result['status'] = 'unverified';

            return $result;
        }
        $result['identity'] = 'wba:' . $origin . '#' . $result['keyId'];

        return $result;
    }

    /** @return array<string,string[]> etiqueta ⇒ componentes cubiertos (minúsculas). */
    private static function coveredComponents(string $input): array
    {
        $out = [];
        if (preg_match_all('/(?:^|,)\s*([A-Za-z*][A-Za-z0-9_.*\-]*)=\(([^)]*)\)/', $input, $m, PREG_SET_ORDER)) {
            foreach ($m as $member) {
                preg_match_all('/"([^"]*)"/', $member[2], $names);
                $out[$member[1]] = array_map('strtolower', $names[1]);
            }
        }

        return $out;
    }

    /** @return string[] */
    private static function signatureLabels(string $sig): array
    {
        return preg_match_all('/(?:^|,)\s*([A-Za-z*][A-Za-z0-9_.*\-]*)=:/', $sig, $m) ? $m[1] : [];
    }

    /** Origen https normalizado del `Signature-Agent`; '' si no hay. */
    private static function agentOrigin(string $header): string
    {
        if (!preg_match('/https?:\/\/[^"\s,;]+/i', $header, $m)) {
            return '';
        }
        $parts = parse_url($m[0]);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            return '';
        }

        return 'https://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /** @return array{status:string, provider:string, keyId:string, identity:string} `error` = timeout/HTTP/forma mala. */
    private function callApi(BackendApiClientInterface $client, string $merchantId, array $payload): array
    {
        $error = ['status' => 'error', 'provider' => '', 'keyId' => '', 'identity' => ''];
        try {
            $body = $client->callWithS2S(self::ENDPOINT, $payload, $merchantId);
        } catch (\Throwable $e) {
            return $error;
        }
        if (!in_array($body['verificationStatus'] ?? null, self::KNOWN_STATUSES, true)) {
            return $error;
        }

        return [
            'status'   => $body['verificationStatus'],
            'provider' => substr((string) ($body['provider'] ?? ''), 0, 200),
            'keyId'    => substr((string) ($body['keyId'] ?? ''), 0, 200),
            'identity' => '',
        ];
    }
}
