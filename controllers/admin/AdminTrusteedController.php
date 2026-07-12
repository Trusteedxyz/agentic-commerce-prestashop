<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * AdminTrusteedController — Panel de administración Trusteed para PrestaShop.
 *
 * Renderiza la SPA React (amcp-admin.js) en el back office de PS usando el
 * mismo bundle compilado que usa el plugin WordPress. La SPA se monta con
 * source='ps-embed' y un token broker específico de PS.
 *
 * Flujo de autenticación (server-side relay — sin CORS):
 *  1. JS llama a ajaxProcessToken() → PHP hace relay S2S a /v1/embed/ps/issue-token
 *     con header X-Embed-Ps-Secret (igual que WP usa X-Embed-Wp-Secret).
 *  2. PHP devuelve { token, expires_at, jti } al navegador sin pasar secretos al DOM.
 *  3. TrusteedEmbed.mount() usa ese token para todas las llamadas embed.
 *
 * Secciones disponibles (mismas que WP y Shopify):
 *  - inicio, trust-center, merchant-center, payment-methods,
 *    settings, mis-ventas, mis-reglas, seguridad, agentes
 *
 * @package Trusteed
 */
class AdminTrusteedController extends ModuleAdminController
{
    /** Sección por defecto al cargar esta instancia del controller. Las subclases la sobreescriben. */
    protected string $defaultSection = 'inicio';

    /** Secciones permitidas en la SPA (allowlist). */
    private const ALLOWED_SECTIONS = [
        'inicio',
        'trust-center',
        'merchant-center',
        'payment-methods',
        'settings',
        'mis-ventas',
        'mis-reglas',
        'seguridad',
        'agentes',
    ];

    /** Hostnames permitidos para el API base en producción (S042-004 SSRF allowlist). */
    private const ALLOWED_API_HOSTS = [
        'api.trusteed.xyz',
        'agenticmcpstores-production.up.railway.app',
        'staging-api.trusteed.xyz',
    ];

    /** IPs/hosts privados permitidos sólo en entornos de desarrollo (HTTP sin TLS). */
    private const DEV_API_HOSTS = [
        'localhost',
        '127.0.0.1',
        '172.17.0.1',
        '172.18.0.1',
        '172.20.0.1',
        'host.docker.internal',
        '38.242.232.176',
    ];

    public function __construct()
    {
        $this->bootstrap     = true;
        $this->display       = 'view';
        parent::__construct();

        $this->meta_title = $this->module->l('Trusteed');
    }

    // ─── Renderizado principal ─────────────────────────────────────────────────

    public function initContent(): void
    {
        $this->content = $this->renderTrusteedPanel();
        parent::initContent();
    }

    /**
     * Renderiza el panel Trusteed y devuelve el HTML.
     * Usa $this->module->getLocalPath() para resolver la ruta absoluta del template,
     * evitando el problema de resolución del prefijo module: en PS 8.1.
     */
    private function renderTrusteedPanel(): string
    {
        $section = $this->resolveSection((string) \Tools::getValue('section', $this->defaultSection));

        // TRUSTEED_BROWSER_API_BASE overrides the URL sent to the SPA (browser).
        // Use this in local dev when the PHP relay reaches the API via host.docker.internal
        // but the browser must use localhost. In production both keys are identical.
        $apiBaseSpa = (string) \Configuration::get('TRUSTEED_BROWSER_API_BASE');
        if ($apiBaseSpa === '') {
            $apiBaseSpa = (string) \Configuration::get('TRUSTEED_API_BASE', null, null, null, 'https://api.trusteed.xyz');
        }
        $merchantId = (string) \Configuration::get('TRUSTEED_CEL_MERCHANT_ID');
        $shopId     = (int) $this->context->shop->id;
        $shopDomain = (string) $this->context->shop->domain;

        $ajaxUrl = $this->context->link->getAdminLink('AdminTrusteed', false);

        /** @var \Trusteed\Admin\SpaLoader $loader */
        $loader = new \Trusteed\Admin\SpaLoader(
            __DIR__ . '/../../views/js/',
            $this->module->getPathUri() . 'views/js/'
        );
        $bundleUrl = $loader->getBundleUrl();

        // trusteed-init.js es un archivo estático en views/js/ — siempre presente
        // tras compilar el módulo (no depende del build SPA).
        $initUrl = $this->module->getPathUri() . 'views/js/trusteed-init.js';

        // PS Context->language has `locale` (e.g. "es-ES") since PS 1.7. Fallback to
        // iso_code ("es") for older installs. The SPA normalises both to "es" / "en".
        $lang = $this->context->language;
        $psLocale = (string) ($lang->locale ?? '');
        if ($psLocale === '') {
            $psLocale = (string) ($lang->iso_code ?? 'en');
        }

        $this->context->smarty->assign([
            'bundle_url'   => $bundleUrl,
            'bundle_ok'    => $bundleUrl !== null,
            'init_url'     => $initUrl,
            'section'      => $section,
            'ajax_url'     => $ajaxUrl,
            'admin_token'  => \Tools::getAdminTokenLite('AdminTrusteed'),
            'merchant_id'  => $merchantId,
            'api_base'     => $apiBaseSpa,
            'shop_id'      => $shopId,
            'shop_domain'  => $shopDomain,
            'ps_locale'    => $psLocale,
            'configured'   => $merchantId !== '' && $this->hasBootstrapSecret(),
        ]);

        $tplPath = $this->module->getLocalPath() . 'views/templates/admin/trusteed.tpl';
        return $this->context->smarty->fetch($tplPath);
    }

    // ─── Token broker (relay S2S a /v1/embed/ps/issue-token) ─────────────────

    /**
     * AJAX action: relay S2S a /v1/embed/ps/issue-token (igual que WP usa /v1/embed/wp/issue-token).
     *
     * El navegador no llama a la API directamente (CORS). PHP hace la llamada
     * servidor-a-servidor con X-Embed-Ps-Secret en la cabecera.
     *
     * POST /index.php?controller=AdminTrusteed&ajax=1&action=token&token={ps_token}
     *
     * Response: { "token": "...", "expires_at": "...", "jti": "..." } | { "error": "..." }
     */
    public function ajaxProcessToken(): void
    {
        if (!$this->hasBootstrapSecret()) {
            $this->sendJsonResponse(['error' => 'not_configured']);
        }

        $merchantId = (string) \Configuration::get('TRUSTEED_CEL_MERCHANT_ID');
        if ($merchantId === '') {
            $this->sendJsonResponse(['error' => 'merchant_id_missing']);
        }

        // Gap 2 fix: canonical key is TRUSTEED_EMBED_S2S_SECRET; fall back to
        // legacy TRUSTEED_BOOTSTRAP_TOKEN for installs that haven't migrated yet.
        $secret = (string) \Configuration::get('TRUSTEED_EMBED_S2S_SECRET');
        if ($secret === '') {
            $secret = (string) \Configuration::get('TRUSTEED_BOOTSTRAP_TOKEN');
        }
        $employeeId = (int) ($this->context->employee->id ?? 0);

        if ($employeeId <= 0) {
            $this->sendJsonResponse(['error' => 'no_employee_context']);
        }

        $apiBase = (string) \Configuration::get('TRUSTEED_API_BASE', null, null, null, 'https://api.trusteed.xyz');
        if (!$this->isAllowedApiBase($apiBase)) {
            $this->sendJsonResponse(['error' => 'api_base_not_allowed']);
        }

        // Gap 3: super-admins get all_shops: true instead of an enumerated shop list.
        $isSuperAdmin = ($this->context->employee->id_profile == _PS_ADMIN_PROFILE_);

        // Gap 5: resolve PS multishop scope for this employee → allowed_shops[].
        // Skip the DB query for super-admins; the backend accepts all_shops: true instead.
        $allowedShops = $isSuperAdmin ? [] : $this->resolveAllowedShops($employeeId);

        $endpoint = rtrim($apiBase, '/') . '/v1/embed/ps/issue-token';
        $result   = $this->remotePost($endpoint, $secret, $merchantId, (string) $employeeId, $allowedShops, $isSuperAdmin);
        $this->sendJsonResponse($result);
    }

    /**
     * Gap 5 — Resolve PS multishop scope for an employee.
     *
     * Reads ps_employee_shop to determine which shops this employee can manage.
     * Falls back to a single-element list with the current shop ID when the
     * table is absent (PS <1.5) or DB error occurs. Bounded to 64 entries to
     * match backend Zod schema.
     *
     * @return list<int>
     */
    private function resolveAllowedShops(int $employeeId): array
    {
        try {
            $db = \Db::getInstance(\_PS_USE_SQL_SLAVE_);
            $rows = $db->executeS(
                'SELECT id_shop FROM `' . _DB_PREFIX_ . 'employee_shop` ' .
                'WHERE id_employee = ' . (int) $employeeId . ' LIMIT 64'
            );
            if (is_array($rows) && count($rows) > 0) {
                $shops = [];
                foreach ($rows as $row) {
                    $shopId = (int) ($row['id_shop'] ?? 0);
                    if ($shopId > 0) {
                        $shops[] = $shopId;
                    }
                }
                if (count($shops) > 0) {
                    return array_values(array_unique($shops));
                }
            }
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog(
                'AdminTrusteed: resolveAllowedShops query failed: ' . $e->getMessage(),
                2
            );
        }

        // Fallback: current shop context only.
        $currentShop = (int) ($this->context->shop->id ?? 0);
        return $currentShop > 0 ? [$currentShop] : [];
    }

    /**
     * Hace un POST servidor-a-servidor a /v1/embed/ps/issue-token.
     * Usa cURL si está disponible, fallback a file_get_contents + stream_context.
     *
     * @param list<int> $allowedShops
     * @return array<string, mixed>
     */
    private function remotePost(string $url, string $secret, string $merchantId, string $employeeId, array $allowedShops = [], bool $allShops = false): array
    {
        $body = [
            'merchant_id'             => $merchantId,
            'ps_employee_id'          => $employeeId,
            'capability_attestation'  => 'admin_trusteed',
        ];
        if ($allShops) {
            $body['all_shops'] = true;
        } elseif (count($allowedShops) > 0) {
            $body['allowed_shops'] = $allowedShops;
        }
        $payload = json_encode($body, \JSON_THROW_ON_ERROR);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Embed-Ps-Secret: ' . $secret,
        ];

        if (function_exists('curl_init')) {
            return $this->remotePostCurl($url, $payload, $headers);
        }

        return $this->remotePostFopen($url, $payload, $headers);
    }

    /** @param string[] $headers */
    private function remotePostCurl(string $url, string $payload, array $headers): array
    {
        // Backoff delays (µs) entre reintentos: 150ms, 400ms.
        // Solo reintenta errores de RED/transporte (curl_exec=false o curl_error no vacío).
        // Respuestas HTTP 4xx/5xx son determinísticas → no se reintentan.
        $retryDelays = [150000, 400000];
        $maxAttempts = 1 + count($retryDelays); // 3 intentos totales

        $lastError   = '';
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                \CURLOPT_RETURNTRANSFER => true,
                \CURLOPT_POST           => true,
                \CURLOPT_POSTFIELDS     => $payload,
                \CURLOPT_HTTPHEADER     => $headers,
                \CURLOPT_TIMEOUT        => 10,
                \CURLOPT_CONNECTTIMEOUT => 8,
                \CURLOPT_SSL_VERIFYPEER => true,
                \CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);
            $lastError = curl_error($ch);
            curl_close($ch);

            // Error de red/transporte → reintenta si quedan intentos
            if ($response === false || $lastError !== '') {
                if ($attempt < $maxAttempts) {
                    $delayUs = $retryDelays[$attempt - 1];
                    PrestaShopLogger::addLog(
                        sprintf(
                            '[AdminTrusteed] relay cURL network error (attempt %d/%d), retrying in %dms — %s',
                            $attempt,
                            $maxAttempts,
                            (int) ($delayUs / 1000),
                            $lastError
                        ),
                        1,
                        null,
                        'AdminTrusteedController',
                        null,
                        true
                    );
                    usleep($delayUs);
                    continue;
                }
                // Agotados los reintentos
                return ['error' => 'relay_network_error', 'detail' => $lastError];
            }

            // A partir de aquí tenemos una respuesta HTTP — no reintentamos.
            $decoded = json_decode((string) $response, true);
            if (!is_array($decoded)) {
                return ['error' => 'relay_invalid_json'];
            }

            if ($httpCode !== 200) {
                return ['error' => $decoded['error'] ?? 'relay_http_' . $httpCode];
            }

            // Desenvuelve wrapper { data: {...} } si lo hay
            return isset($decoded['data']) && is_array($decoded['data'])
                ? $decoded['data']
                : $decoded;
        }

        // Nunca se alcanza, pero satisface el analizador de flujo de PHP
        return ['error' => 'relay_network_error', 'detail' => $lastError];
    }

    /** @param string[] $headers */
    private function remotePostFopen(string $url, string $payload, array $headers): array
    {
        // Backoff delays (µs) entre reintentos: 150ms, 400ms.
        // Solo reintenta errores de RED/transporte (file_get_contents=false).
        $retryDelays = [150000, 400000];
        $maxAttempts = 1 + count($retryDelays); // 3 intentos totales

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $context = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => implode("\r\n", $headers),
                    'content'       => $payload,
                    'timeout'       => 10,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer'      => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $response = @file_get_contents($url, false, $context);

            if ($response === false) {
                if ($attempt < $maxAttempts) {
                    $delayUs = $retryDelays[$attempt - 1];
                    PrestaShopLogger::addLog(
                        sprintf(
                            '[AdminTrusteed] relay fopen network error (attempt %d/%d), retrying in %dms',
                            $attempt,
                            $maxAttempts,
                            (int) ($delayUs / 1000)
                        ),
                        1,
                        null,
                        'AdminTrusteedController',
                        null,
                        true
                    );
                    usleep($delayUs);
                    continue;
                }
                return ['error' => 'relay_network_error'];
            }

            // Respuesta recibida — no reintenta errores HTTP determinísticos
            $decoded = json_decode($response, true);
            if (!is_array($decoded)) {
                return ['error' => 'relay_invalid_json'];
            }

            return isset($decoded['data']) && is_array($decoded['data'])
                ? $decoded['data']
                : $decoded;
        }

        // Nunca se alcanza
        return ['error' => 'relay_network_error'];
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Valida y devuelve la sección. Rechaza secciones desconocidas → 'inicio'.
     */
    private function resolveSection(string $raw): string
    {
        return in_array($raw, self::ALLOWED_SECTIONS, true) ? $raw : 'inicio';
    }

    /**
     * Comprueba que el bootstrap secret está configurado y tiene el formato correcto.
     * El secret debe ser exactamente 64 caracteres hex (S042-002 validation).
     */
    private function hasBootstrapSecret(): bool
    {
        // Gap 2 fix: prefer canonical key, fall back to legacy for backward compat.
        $secret = (string) \Configuration::get('TRUSTEED_EMBED_S2S_SECRET');
        if ($secret === '') {
            $secret = (string) \Configuration::get('TRUSTEED_BOOTSTRAP_TOKEN');
        }
        return $secret !== '' && preg_match('/\A[0-9a-fA-F]{64}\z/', $secret) === 1;
    }

    /**
     * Comprueba que el API base URL está en el allowlist (S042-004 SSRF guard).
     */
    private function isAllowedApiBase(string $url): bool
    {
        $host = parse_url($url, \PHP_URL_HOST);
        if (!is_string($host)) {
            return false;
        }
        // Producción: sólo HTTPS con hosts conocidos
        if (str_starts_with($url, 'https://')) {
            return in_array($host, self::ALLOWED_API_HOSTS, true);
        }
        // Desarrollo local: HTTP permitido sólo para IPs/hosts privados
        if (str_starts_with($url, 'http://')) {
            return in_array($host, self::DEV_API_HOSTS, true);
        }
        return false;
    }

    /**
     * Emite JSON y termina la respuesta AJAX.
     *
     * @param array<string, mixed> $data
     */
    private function sendJsonResponse(array $data): never
    {
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode($data, \JSON_THROW_ON_ERROR));
    }
}
