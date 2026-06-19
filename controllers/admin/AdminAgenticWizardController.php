<?php
/**
 * AdminAgenticWizardController — 4-step onboarding wizard.
 *
 * Guides a Back-Office employee through:
 *   Step 1 (welcome)      — displays pre-flight checklist.
 *   Step 2 (auto-connect) — one-click auto-registration via
 *                           POST /v1/embed/ps/auto-register. No portal
 *                           account required. Credentials saved automatically.
 *   Step 3 (test)         — fires an AJAX opaque-token relay via TokenBroker
 *                           (POST /v1/embed/ps/issue-token); displays success
 *                           or error feedback.
 *   Step 4 (done)         — success + link to Trust Center.
 *
 * Security:
 *   - Every AJAX handler verifies CSRF via Tools::getAdminTokenLite.
 *   - Employee auth is asserted on every request (parent::__construct enforces).
 *   - Super-admin only for auto-register and save-credentials.
 *   - Secrets are never echoed back to the DOM.
 *   - X-Frame-Options: SAMEORIGIN on every response.
 *
 * Routes:
 *   GET  index.php?controller=AdminAgenticWizard         — render wizard.tpl
 *   POST …&ajax=1&action=autoRegister                    — step 2 auto-register
 *   POST …&ajax=1&action=saveCredentials                 — step 2 manual (fallback)
 *   POST …&ajax=1&action=testConnection                  — step 3 test
 *
 * @author  Trusteed
 * @license MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'trusteed/classes/TokenBroker.php';
require_once _PS_MODULE_DIR_ . 'trusteed/classes/AutoRegister.php';

use Trusteed\Mvp\TokenBroker;
use Trusteed\Mvp\AutoRegister;

class AdminAgenticWizardController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->display   = 'view';
        parent::__construct();
        $this->meta_title = $this->module->l('Quick Setup — Trusteed');
    }

    // ─── Page render ───────────────────────────────────────────────────────────

    public function initContent(): void
    {
        header('X-Frame-Options: SAMEORIGIN');

        parent::initContent();

        $employee   = $this->context->employee;
        $isMainShop = ((int) $this->context->shop->id === (int) Configuration::get('PS_SHOP_DEFAULT'));

        $merchantId = (string) Configuration::get('TRUSTEED_MERCHANT_ID');
        $hasSecret  = (string) Configuration::get('TRUSTEED_BOOTSTRAP_SECRET') !== '';
        $apiBase    = (string) Configuration::get('TRUSTEED_API_BASE');

        // Wizard is only useful when configuration is incomplete.
        $isConfigured = ($merchantId !== '' && $hasSecret);

        $shopBaseUrl   = $this->context->shop->getBaseURL(true);
        $shopName      = (string) Configuration::get('PS_SHOP_NAME');
        $adminEmail    = (string) ($this->context->employee->email ?? '');
        $hasInstallKey = (string) Configuration::get('TRUSTEED_INSTALL_KEY') !== '';

        $this->context->smarty->assign([
            'csrf_token'    => Tools::getAdminTokenLite('AdminAgenticWizard'),
            'is_main_shop'  => $isMainShop,
            'is_configured' => $isConfigured,
            'merchant_id'   => $merchantId,
            'api_base'      => $apiBase,
            'controller'    => 'AdminAgenticWizard',
            // Step 2 auto-register data
            'shop_base_url' => $shopBaseUrl,
            'shop_name'     => $shopName,
            'admin_email'   => $adminEmail,
            'has_install_key' => $hasInstallKey,
        ]);

        $this->setTemplate('wizard.tpl');
    }

    // ─── AJAX step 2: auto-register ────────────────────────────────────────────

    /**
     * POST …&ajax=1&action=autoRegister
     *
     * Calls POST /v1/embed/ps/auto-register with store data derived from
     * the PS context (no user input required). On success saves
     * TRUSTEED_MERCHANT_ID, TRUSTEED_BOOTSTRAP_SECRET, and TRUSTEED_INSTALL_KEY
     * to PS Configuration automatically.
     */
    public function ajaxProcessAutoRegister(): void
    {
        header('Content-Type: application/json');
        header('X-Frame-Options: SAMEORIGIN');

        if (!$this->assertCsrf('AdminAgenticWizard')) {
            return;
        }

        $employee     = $this->context->employee;
        $isSuperAdmin = ((int) $employee->id_profile === (int) _PS_ADMIN_PROFILE_);
        if (!$isSuperAdmin) {
            http_response_code(403);
            echo json_encode(['error' => 'super_admin_required']);
            exit(0);
        }

        $apiBase       = (string) (Configuration::get('TRUSTEED_API_BASE') ?: 'https://api.trusteed.xyz');
        $storeUrl      = (string) $this->context->shop->getBaseURL(true);
        $storeName     = (string) Configuration::get('PS_SHOP_NAME');
        $adminEmail    = (string) ($employee->email ?? '');
        $psVersion     = (string) _PS_VERSION_;
        $moduleVersion = (string) $this->module->version;
        $installKey    = (string) (Configuration::get('TRUSTEED_INSTALL_KEY') ?: '');

        try {
            $autoReg = new AutoRegister($apiBase);
            $result  = $autoReg->register(
                $storeUrl,
                $storeName,
                $adminEmail,
                $psVersion,
                $moduleVersion,
                $installKey
            );

            Configuration::updateValue('TRUSTEED_MERCHANT_ID', $result['merchant_id']);
            Configuration::updateValue('TRUSTEED_BOOTSTRAP_SECRET', $result['bootstrap_secret']);
            if ($result['install_key'] !== '') {
                Configuration::updateValue('TRUSTEED_INSTALL_KEY', $result['install_key']);
            }

            echo json_encode([
                'success'     => true,
                'merchant_id' => $result['merchant_id'],
                'is_new'      => $result['is_new'],
            ]);
        } catch (\Throwable $e) {
            http_response_code(502);
            echo json_encode([
                'error'  => 'auto_register_failed',
                'detail' => $e->getMessage(),
            ]);
        }

        exit(0);
    }

    // ─── AJAX step 3 (fallback): save credentials manually ─────────────────────

    /**
     * POST …&ajax=1&action=saveCredentials
     *
     * Expected body params (URL-encoded or JSON via Tools::getValue):
     *   token            — CSRF token
     *   merchant_id      — non-empty string
     *   bootstrap_secret — non-empty hex string (≥ 32 chars)
     */
    public function ajaxProcessSaveCredentials(): void
    {
        header('Content-Type: application/json');
        header('X-Frame-Options: SAMEORIGIN');

        if (!$this->assertCsrf('AdminAgenticWizard')) {
            return;
        }

        // Solo super-admin puede guardar credenciales globales del módulo
        $employee = $this->context->employee;
        $isSuperAdmin = ((int) $employee->id_profile === (int) _PS_ADMIN_PROFILE_);
        if (!$isSuperAdmin) {
            http_response_code(403);
            echo json_encode(['error' => 'super_admin_required']);
            exit(0);
        }

        $merchantId = trim((string) Tools::getValue('merchant_id'));
        $secret     = trim((string) Tools::getValue('bootstrap_secret'));
        $apiBase    = trim((string) Tools::getValue('api_base'));

        if ($merchantId === '') {
            $this->emitJsonError('merchant_id_required');
            return;
        }

        if ($secret === '') {
            $this->emitJsonError('bootstrap_secret_required');
            return;
        }

        // S042-002: secrets from the portal are exactly 64 hex characters (32 random bytes).
        // Reject anything that does not match — prevents weak/non-hex HMAC keys.
        if (!preg_match('/\A[0-9a-fA-F]{64}\z/', $secret)) {
            $this->emitJsonError('bootstrap_secret_invalid_format');
            return;
        }

        // Validate api_base (SSRF prevention) if provided.
        if ($apiBase !== '') {
            try {
                \Trusteed\Mvp\TokenBroker::validateApiBasePublic($apiBase);
            } catch (\InvalidArgumentException $e) {
                http_response_code(400);
                echo json_encode(['error' => 'invalid_api_base']);
                exit(0);
            }
            Configuration::updateValue('TRUSTEED_API_BASE', $apiBase);
        }

        Configuration::updateValue('TRUSTEED_MERCHANT_ID', $merchantId);
        Configuration::updateValue('TRUSTEED_BOOTSTRAP_SECRET', $secret);

        echo json_encode(['success' => true]);
        exit(0);
    }

    // ─── AJAX step 4: test connection ──────────────────────────────────────────

    /**
     * POST …&ajax=1&action=testConnection
     *
     * Reads credentials from Configuration (already saved by step 3) and fires
     * a real opaque-token relay to /v1/embed/ps/issue-token. The opaque token is
     * returned in the JSON `access_token` field (key name kept for SPA compat);
     * the wizard UI only reads `success` for connectivity feedback.
     */
    public function ajaxProcessTestConnection(): void
    {
        header('Content-Type: application/json');
        header('X-Frame-Options: SAMEORIGIN');

        if (!$this->assertCsrf('AdminAgenticWizard')) {
            return;
        }

        $merchantId = (string) Configuration::get('TRUSTEED_MERCHANT_ID');
        $secret     = (string) Configuration::get('TRUSTEED_BOOTSTRAP_SECRET');
        $apiBase    = (string) Configuration::get('TRUSTEED_API_BASE');

        if ($merchantId === '' || $secret === '') {
            $this->emitJsonError('configure_module_first');
            return;
        }

        $employee = $this->context->employee;
        $idShop   = (int) $this->context->shop->id;

        // Derive allowed_shops from the employee profile, not the BO context.
        // Using BO context (getContextListShopID) is unsafe: a restricted employee
        // who switches context to "all shops" would gain global scope.
        $isSuperAdmin = ((int) ($employee->id ?? 0) > 0 && ((int) $employee->id_profile === (int) _PS_ADMIN_PROFILE_));
        if ($isSuperAdmin) {
            // Super-admin obtiene acceso a todas las tiendas activas.
            $allowedShops = array_map('intval', Shop::getCompleteListOfShopsID() ?: [$idShop]);
        } else {
            // Derivar del perfil real del empleado (employee_shop), NO del contexto BO.
            $rawShops = Db::getInstance()->executeS(
                'SELECT id_shop FROM `' . _DB_PREFIX_ . 'employee_shop` WHERE id_employee = ' . (int) $employee->id
            );
            if (!$rawShops) {
                // Empty ACL fails closed — no shops mapped = no access.
                http_response_code(403);
                echo json_encode(['error' => 'shop_acl']);
                exit(0);
            }
            $allowedShops = array_map(static fn(array $r) => (int) $r['id_shop'], $rawShops);
        }

        // Guard: current shop must be in employee's allowed list.
        if (!$isSuperAdmin && !in_array($idShop, $allowedShops, true)) {
            http_response_code(403);
            echo json_encode(['error' => 'shop_acl']);
            exit(0);
        }

        $broker = new TokenBroker(
            $merchantId,
            $secret,
            $apiBase,
            (string) Tools::getShopDomain(true, true),
            $idShop,
            $allowedShops,
            (int) ($employee->id ?? 0),
            $isSuperAdmin  // all_shops scope — consistente con TrustController
        );

        try {
            $result = $broker->exchange();

            // Opaque token devuelto en el body bajo la clave `access_token` (compat SPA).
            // No usar httpOnly cookie: JS no puede leerla para enviarla como Bearer token.
            echo json_encode([
                'success'      => true,
                'access_token' => $result['access_token'],
                'merchant_id'  => $result['merchant_id'],
                'expires_in'   => $result['expires_in'],
            ]);
        } catch (\Throwable $e) {
            // Do not forward exception message — may contain tenant information.
            http_response_code(502);
            echo json_encode(['error' => 'bootstrap_failed']);
        }

        exit(0);
    }

    // ─── Private helpers ───────────────────────────────────────────────────────

    /**
     * Validate CSRF token from the POST body.  Sends a 403 JSON response and
     * exits on failure.  Returns true when the token is valid.
     */
    private function assertCsrf(string $controllerClass): bool
    {
        $token    = (string) Tools::getValue('token');
        $expected = Tools::getAdminTokenLite($controllerClass);

        if (!hash_equals($expected, $token)) {
            http_response_code(403);
            echo json_encode(['error' => 'csrf']);
            exit(0);
        }

        return true;
    }

    /**
     * Emit a JSON error and terminate the process.
     */
    private function emitJsonError(string $code, int $httpStatus = 400): void
    {
        http_response_code($httpStatus);
        echo json_encode(['error' => $code]);
        exit(0);
    }
}
