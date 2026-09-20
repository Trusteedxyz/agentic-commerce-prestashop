<?php

declare(strict_types=1);

namespace Trusteed\Service;

use Trusteed\AgenticTools\BackendApiClient;

/**
 * AutoRegister — self-serve store registration against the Trusteed backend.
 *
 * Ported from the deprecated `ps-module-trusteed` sibling (classes/AutoRegister.php)
 * and adapted to this module's conventions:
 *   - PSR-4 autoloaded (`Trusteed\Service`) — no manual require_once.
 *   - API-base validation reuses the module's own SSRF classifier
 *     (BackendApiClient::isPublicIp) instead of the old TokenBroker helper.
 *
 * POSTs the store fingerprint to `{apiBase}/v1/embed/ps/auto-register` and returns
 * the freshly minted `{merchant_id, bootstrap_secret, install_key, is_new}`, so the
 * merchant can activate the module without pasting credentials by hand. The manual
 * paste flow (module config page) remains fully supported — this is additive.
 */
final class AutoRegister
{
    private string $apiBase;

    /**
     * @throws \RuntimeException when $apiBase is not an HTTPS URL to a public host.
     */
    public function __construct(string $apiBase)
    {
        $this->validateApiBase($apiBase);
        $this->apiBase = rtrim($apiBase, '/');
    }

    /**
     * Registers (or re-registers) the PrestaShop store with Trusteed.
     *
     * @param string $storeUrl      Public store URL (https://...)
     * @param string $storeName     Store name (PS_SHOP_NAME)
     * @param string $adminEmail    Email of the employee installing the module
     * @param string $psVersion     PrestaShop version (_PS_VERSION_)
     * @param string $moduleVersion Module version
     * @param string $installKey    Empty on first install; required to re-register
     *
     * @return array{merchant_id:string, bootstrap_secret:string, install_key:string, is_new:bool}
     * @throws \RuntimeException on network error or API rejection
     */
    public function register(
        string $storeUrl,
        string $storeName,
        string $adminEmail,
        string $psVersion,
        string $moduleVersion,
        string $installKey = ''
    ): array {
        $payload = json_encode([
            'store_url'      => $storeUrl,
            'store_name'     => $storeName,
            'admin_email'    => $adminEmail,
            'ps_version'     => $psVersion,
            'module_version' => $moduleVersion,
            'install_key'    => $installKey,
            // Hueco A3 (2026-09-09): declara que este módulo sabe publicar
            // `.well-known/amcp-verify.txt` y canjear el reto. Sin esta bandera
            // el backend sigue devolviendo el 409 sin salida de siempre, que es
            // lo correcto para un módulo antiguo: un 202 caería en su rama
            // «API rejected request (HTTP 202)» y sería peor.
            'supports_domain_verification' => true,
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new \RuntimeException('auto-register: failed to encode payload');
        }

        $endpoint = $this->apiBase . '/v1/embed/ps/auto-register';

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: Trusteed-PS/1.0',
            ],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException(
                'auto-register: network error (curl errno=' . $errno . ')'
            );
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('auto-register: invalid JSON response');
        }

        // Unwrap { data: {...} } envelope
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            $decoded = $decoded['data'];
        }

        // Hueco A3 (2026-09-09) — el backend nos ofrece probar propiedad del
        // dominio en vez de dejarnos sin salida. Se lanza una excepción tipada
        // para que quien llama pueda publicar el token y reintentar; el mensaje
        // sigue siendo legible por si nadie la captura.
        if ($status === 202 && ($decoded['error'] ?? '') === 'DOMAIN_VERIFICATION_REQUIRED') {
            $challenge = is_array($decoded['challenge'] ?? null) ? $decoded['challenge'] : [];

            throw new DomainVerificationRequired(
                (string) ($challenge['token'] ?? ''),
                (string) ($decoded['message'] ?? 'Domain verification required.')
            );
        }

        if ($status === 409) {
            throw new \RuntimeException(
                'auto-register: this store URL is already registered with a different ' .
                'install key. If this is your store, update the module so it can prove ' .
                'domain ownership, or contact Trusteed support.'
            );
        }

        if ($status !== 200 && $status !== 201) {
            $reason = $decoded['error'] ?? $decoded['code'] ?? 'unknown';
            throw new \RuntimeException(
                "auto-register: API rejected request (HTTP {$status}): {$reason}"
            );
        }

        if (empty($decoded['merchantId']) || empty($decoded['bootstrapSecret'])) {
            throw new \RuntimeException('auto-register: response missing required fields');
        }

        return [
            'merchant_id'      => (string) $decoded['merchantId'],
            'bootstrap_secret' => (string) $decoded['bootstrapSecret'],
            'install_key'      => (string) ($decoded['installKey'] ?? ''),
            'is_new'           => (bool) ($decoded['isNew'] ?? true),
        ];
    }

    /**
     * Canjea un reto de dominio ya publicado — hueco A3 (2026-09-09).
     *
     * Se llama DESPUÉS de haber escrito el token en
     * `.well-known/amcp-verify.txt`: el backend viene a leerlo desde fuera, así
     * que si se llama antes el fichero todavía no está y el canje falla.
     *
     * Devuelve las mismas credenciales que `register()`, con una `install_key`
     * NUEVA: si no se rotara, quien tuviera la anterior podría volver a echar
     * al dueño en la siguiente llamada.
     *
     * @return array{merchant_id:string, bootstrap_secret:string, install_key:string, is_new:bool}
     * @throws \RuntimeException on network error or API rejection
     */
    public function verifyChallenge(string $storeUrl, string $challengeToken): array
    {
        $payload = json_encode([
            'store_url'       => $storeUrl,
            'challenge_token' => $challengeToken,
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new \RuntimeException('verify-challenge: failed to encode payload');
        }

        $ch = curl_init($this->apiBase . '/v1/embed/ps/verify-challenge');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: Trusteed-PS/1.0',
            ],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException(
                'verify-challenge: network error (curl errno=' . $errno . ')'
            );
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('verify-challenge: invalid JSON response');
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            $decoded = $decoded['data'];
        }

        if ($status !== 200) {
            // El motivo describe el estado del RETO (fichero ilegible, token
            // caducado, contenido que no coincide…), que es justo lo que el
            // comerciante necesita para corregirlo.
            $reason = $decoded['reason'] ?? $decoded['error'] ?? 'unknown';
            throw new \RuntimeException(
                "verify-challenge: domain verification failed ({$reason})."
            );
        }

        if (empty($decoded['merchantId']) || empty($decoded['bootstrapSecret'])) {
            throw new \RuntimeException('verify-challenge: response missing required fields');
        }

        return [
            'merchant_id'      => (string) $decoded['merchantId'],
            'bootstrap_secret' => (string) $decoded['bootstrapSecret'],
            'install_key'      => (string) ($decoded['installKey'] ?? ''),
            'is_new'           => false,
        ];
    }

    /**
     * Rejects non-HTTPS URLs and hosts that resolve to a private / loopback /
     * link-local / reserved IP (SSRF guard). Reuses BackendApiClient::isPublicIp
     * — the module's canonical IP classifier — for parity with the other HTTP
     * clients, instead of duplicating the old TokenBroker validation.
     *
     * @throws \RuntimeException
     */
    private function validateApiBase(string $url): void
    {
        if (!str_starts_with($url, 'https://')) {
            throw new \RuntimeException('auto-register: API base must use HTTPS; got: ' . $url);
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new \RuntimeException('auto-register: unparseable host in API base');
        }

        // Strip IPv6 brackets.
        $host = trim($host, '[]');

        if (strcasecmp($host, 'localhost') === 0) {
            throw new \RuntimeException('auto-register: API base host is not public (SSRF blocked): ' . $host);
        }

        $candidates = [];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $candidates[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ip'])) {
                        $candidates[] = (string) $record['ip'];
                    }
                    if (isset($record['ipv6'])) {
                        $candidates[] = (string) $record['ipv6'];
                    }
                }
            }
        }

        foreach ($candidates as $ip) {
            if (!BackendApiClient::isPublicIp($ip)) {
                throw new \RuntimeException(
                    'auto-register: API base host is not public (SSRF blocked): ' . $host
                );
            }
        }
    }
}
