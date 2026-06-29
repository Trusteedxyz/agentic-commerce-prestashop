<?php
/**
 * AdminAgenticTrustController — MVP PS BO controller.
 *
 * Renders the embed shell SPA + handles the AJAX opaque-token relay.
 *
 * Routes:
 *   GET  index.php?controller=AdminAgenticTrust          — render trust.tpl
 *   POST index.php?controller=AdminAgenticTrust&ajax=1&action=issueBootstrap
 *     (relays S2S to POST /v1/embed/ps/issue-token; action name kept for SPA compat)
 *
 * Security hardening over spike:
 *   - X-Frame-Options: SAMEORIGIN on every response
 *   - Content-Security-Policy on every response (the Bearer token lives in JS /
 *     sessionStorage, so we lock down script/connect origins to blunt token
 *     exfiltration via injected scripts). The SPA is served from views/js as
 *     external scripts with no inline JS, so `script-src 'self'` does not break BO.
 *   - opaque token returned in JSON `access_token` field; SPA stores in sessionStorage (tab-scoped).
 *     httpOnly cookies are NOT used — JS cannot read them as Bearer.
 *   - allowed_shops derived from employee_shop DB table, NOT from BO context (T042-078).
 *   - CSRF validated via Tools::getAdminTokenLite (PS 8.x native primitive)
 *   - die() replaced with exit(0) (PS 8.2+ recommendation)
 */

require_once _PS_MODULE_DIR_ . 'trusteed/classes/TokenBroker.php';

use AgenticMcpStores\Mvp\TokenBroker;

class AdminAgenticTrustController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap  = true;
        $this->display    = 'view';
        parent::__construct();
        $this->meta_title = 'Trusteed Trust Center';
    }

    // ─── Security headers ────────────────────────────────────────────────────────

    /**
     * Emit clickjacking + Content-Security-Policy response headers.
     *
     * The Bearer/opaque token is held in JS (sessionStorage), so a CSP that
     * pins script and connect origins is our primary defence-in-depth against
     * token exfiltration through an injected/third-party script. The SPA loads
     * its bundles from views/js as external `<script src>` (no inline JS), and
     * only talks back to the BO origin and the Trusteed API, so:
     *   - script-src 'self'                       → only first-party bundles run
     *   - connect-src 'self' https://api.trusteed.xyz → token only reaches us
     *   - frame-ancestors 'self' + object-src 'none' + base-uri 'self' → hardening
     *
     * Kept additive: X-Frame-Options stays for legacy-browser clickjacking cover.
     */
    private function emitSecurityHeaders(): void
    {
        // Clickjacking protection — keep iframe sandboxed to same origin.
        header('X-Frame-Options: SAMEORIGIN');

        header(
            "Content-Security-Policy: "
            . "default-src 'self'; "
            . "script-src 'self'; "
            . "connect-src 'self' https://api.trusteed.xyz; "
            . "frame-ancestors 'self'; "
            . "object-src 'none'; "
            . "base-uri 'self'"
        );
    }

    // ─── Page render ───────────────────────────────────────────────────────────

    public function initContent(): void
    {
        $this->emitSecurityHeaders();

        parent::initContent();

        $this->context->smarty->assign([
            'csrf_token'  => Tools::getAdminTokenLite('AdminAgenticTrust'),
            'merchant_id' => Configuration::get('AGENTICMCP_MERCHANT_ID'),
            'api_base'    => Configuration::get('AGENTICMCP_API_BASE'),
            'employee_id' => (int) ($this->context->employee->id ?? 0),
            'id_shop'     => (int) ($this->context->shop->id ?? 1),
            'module_dir'  => _MODULE_DIR_ . 'trusteed/',
        ]);
        $this->setTemplate('trust.tpl');
    }

    // ─── AJAX: issue bootstrap token ───────────────────────────────────────────

    public function ajaxProcessIssueBootstrap(): void
    {
        header('Content-Type: application/json');
        $this->emitSecurityHeaders();

        // CSRF guard
        $token    = (string) Tools::getValue('token');
        $expected = Tools::getAdminTokenLite('AdminAgenticTrust');
        if (!hash_equals($expected, $token)) {
            http_response_code(403);
            echo json_encode(['error' => 'csrf']);
            exit(0);
        }

        // Auth guard
        $employee = $this->context->employee;
        if (!$employee || !$employee->id) {
            http_response_code(401);
            echo json_encode(['error' => 'unauthorized']);
            exit(0);
        }

        // Capability guard (H2) — the bootstrap exchange mints a token whose
        // capability_attestation is "admin_trusteed" (merchant-wide trust write).
        // PrestaShop's native tab ACL only guarantees the employee has *view*
        // permission on AdminAgenticTrust, which is configurable and low-privilege:
        // a restricted profile granted read access to the tab would otherwise obtain
        // an admin-capability token. We therefore fail closed for non super-admins,
        // mirroring AdminAgenticWizardController::ajaxProcessSaveCredentials.
        // The capability is derived from the real profile here — never hardcoded by
        // an arbitrary caller — so only super-admins ever reach $broker->exchange().
        $isSuperAdmin = ((int) $employee->id_profile === (int) _PS_ADMIN_PROFILE_);
        if (!$isSuperAdmin) {
            // Fail closed: only a PS super-admin may mint the merchant-wide
            // `admin_trusteed` capability token. The message is intentionally
            // generic — it states the required role without disclosing profile
            // ids, the capability name, or any tenant internals.
            http_response_code(403);
            echo json_encode([
                'error'   => 'insufficient_capability',
                'message' => 'A super-administrator account is required to authorize the Trust Center.',
            ]);
            exit(0);
        }

        // Configuration guard
        $merchantId = (string) Configuration::get('AGENTICMCP_MERCHANT_ID');
        $secret     = (string) Configuration::get('AGENTICMCP_BOOTSTRAP_SECRET');
        $apiBase    = (string) Configuration::get('AGENTICMCP_API_BASE');
        if ($merchantId === '' || $secret === '') {
            http_response_code(400);
            echo json_encode(['error' => 'configure_module_first']);
            exit(0);
        }

        // Multishop scope.
        // The capability guard above guarantees $isSuperAdmin === true here, so the
        // employee has access to every active shop. We keep the explicit super-admin
        // shop derivation (instead of trusting Shop::getContextListShopID(), which
        // mirrors the BO context selection) so the all_shops claim is sourced from
        // the real shop list, never from a caller-controlled context. (T042-078)
        $idShop       = (int) $this->context->shop->id;
        $allowedShops = array_map('intval', Shop::getCompleteListOfShopsID() ?: [$idShop]);

        $broker = new TokenBroker(
            $merchantId,
            $secret,
            $apiBase,
            (string) Tools::getShopDomain(true, true),
            $idShop,
            $allowedShops,
            (int) $employee->id,
            $isSuperAdmin
        );

        try {
            $result = $broker->exchange();

            // Opaque token (de /v1/embed/ps/issue-token) devuelto bajo la clave
            // `access_token` (compat SPA). El SPA lo guarda en sessionStorage
            // (tab-scoped) y lo refresca proactivamente vía getToken (TTL <= 900s).
            // No usar httpOnly cookie: JS no puede leerla para enviarla como Bearer.
            echo json_encode([
                'success'      => true,
                'access_token' => $result['access_token'],
                'merchant_id'  => $result['merchant_id'],
                'expires_in'   => $result['expires_in'],
            ]);
        } catch (\Throwable $e) {
            http_response_code(502);
            // Do not include exception message in response — may contain tenant info.
            echo json_encode(['error' => 'bootstrap_failed']);
        }

        exit(0);
    }
}
