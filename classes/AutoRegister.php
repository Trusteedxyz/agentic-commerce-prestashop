<?php

namespace Trusteed\Mvp;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'trusteed/classes/TokenBroker.php';

class AutoRegister
{
    private string $apiBase;

    public function __construct(string $apiBase)
    {
        TokenBroker::validateApiBasePublic($apiBase);
        $this->apiBase = rtrim($apiBase, '/');
    }

    /**
     * Registra (o re-registra) la tienda PS en Trusteed.
     *
     * @param string $storeUrl      URL pública de la tienda (https://...)
     * @param string $storeName     Nombre de la tienda (PS_SHOP_NAME)
     * @param string $adminEmail    Email del empleado que instala el módulo
     * @param string $psVersion     Versión de PrestaShop (_PS_VERSION_)
     * @param string $moduleVersion Versión del módulo
     * @param string $installKey    Vacío en primera instalación; requerido en reinstalación
     *
     * @return array{merchant_id:string, bootstrap_secret:string, install_key:string, is_new:bool}
     * @throws \RuntimeException en error de red o rechazo del API
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

        if ($status === 409) {
            throw new \RuntimeException(
                'auto-register: store already registered. ' .
                'Provide TRUSTEED_INSTALL_KEY to re-register.'
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
}
