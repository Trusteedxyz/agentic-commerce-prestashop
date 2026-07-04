<?php
/**
 * Trusteed — Agentic MCP Stores PrestaShop module.
 *
 * Embeds the Trusteed Trust Center inside PrestaShop Back Office via the
 * opaque embed-token flow (spec-042 §"Implementation Deviation Note"):
 *   1. Employee logs into PS Back Office.
 *   2. Module relays S2S to apps/api POST /v1/embed/ps/issue-token with the
 *      per-merchant X-Embed-Ps-Secret header (stored at onboarding).
 *   3. apps/api validates the secret and returns an opaque Redis-backed token
 *      (UUID, TTL <= 900s) that the embed data routes validate via Redis.
 *
 * Compatible: PrestaShop 8.0.0 – 9.99.99
 * PHP:        8.1+
 *
 * @author  Trusteed
 * @license MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/TokenBroker.php';

class Trusteed extends Module
{
    /**
     * Configuration keys — single source of truth shared by the module main
     * class, the onboarding wizard and the Trust Center controller so the three
     * never drift apart. (spec-042 C9: previously the wizard used `TRUSTEED_*`
     * while install()/getContent()/the hook used `AGENTICMCP_*`.)
     */
    public const CFG_MERCHANT_ID     = 'AGENTICMCP_MERCHANT_ID';
    public const CFG_BOOTSTRAP_SECRET = 'AGENTICMCP_BOOTSTRAP_SECRET';
    public const CFG_API_BASE        = 'AGENTICMCP_API_BASE';

    public function __construct()
    {
        $this->name             = 'trusteed';
        $this->tab              = 'administration';
        $this->version          = '1.0.0';
        $this->author           = 'Trusteed';
        $this->need_instance    = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '9.99.99'];
        $this->bootstrap        = true;

        parent::__construct();

        $this->displayName = $this->l('Trusteed Trust Center');
        $this->description = $this->l(
            'Embed the Trusteed Trust Center inside PrestaShop Back Office.'
        );
    }

    // ─── Lifecycle ─────────────────────────────────────────────────────────────

    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }

        // Generate a fresh bootstrap secret on first install.
        // Production onboarding overwrites this with the portal-issued secret.
        $defaultSecret = bin2hex(random_bytes(32));
        Configuration::updateValue(self::CFG_BOOTSTRAP_SECRET, $defaultSecret);
        Configuration::updateValue(self::CFG_MERCHANT_ID, '');
        Configuration::updateValue(self::CFG_API_BASE, 'https://api.trusteed.xyz');

        $this->registerHook('displayBackOfficeTop');

        return $this->installTabs();
    }

    public function uninstall(): bool
    {
        $this->uninstallTabs();
        Configuration::deleteByName(self::CFG_BOOTSTRAP_SECRET);
        Configuration::deleteByName(self::CFG_MERCHANT_ID);
        Configuration::deleteByName(self::CFG_API_BASE);
        return parent::uninstall();
    }

    // ─── Tab management ────────────────────────────────────────────────────────

    private function installTabs(): bool
    {
        // Attempt to nest under "Improve" section; fall back to top level.
        $idParent = (int) Tab::getIdFromClassName('AdminParentStats');
        if ($idParent <= 0) {
            $idParent = -1;
        }

        // Main Trust Center tab.
        $tabTrust = new Tab();
        $tabTrust->active     = 1;
        $tabTrust->class_name = 'AdminAgenticTrust';
        $tabTrust->name       = [];
        foreach (Language::getLanguages(true) as $lang) {
            $tabTrust->name[(int) $lang['id_lang']] = 'Trusteed';
        }
        $tabTrust->id_parent = $idParent;
        $tabTrust->module    = $this->name;
        if (!(bool) $tabTrust->add()) {
            return false;
        }

        // Quick Setup wizard tab — shown until merchant_id is configured.
        $idTrustParent = (int) Tab::getIdFromClassName('AdminAgenticTrust');
        if ($idTrustParent <= 0) {
            $idTrustParent = $idParent;
        }

        $tabWizard = new Tab();
        $tabWizard->active     = 1;
        $tabWizard->class_name = 'AdminAgenticWizard';
        $tabWizard->name       = [];
        foreach (Language::getLanguages(true) as $lang) {
            $tabWizard->name[(int) $lang['id_lang']] = $this->l('Quick Setup');
        }
        $tabWizard->id_parent = $idTrustParent;
        $tabWizard->module    = $this->name;
        return (bool) $tabWizard->add();
    }

    private function uninstallTabs(): bool
    {
        $ok = true;
        foreach (['AdminAgenticWizard', 'AdminAgenticTrust'] as $className) {
            $idTab = (int) Tab::getIdFromClassName($className);
            if ($idTab > 0) {
                $tab = new Tab($idTab);
                $ok  = $ok && (bool) $tab->delete();
            }
        }
        return $ok;
    }

    // ─── Hook: trust score mini-badge in BO header ─────────────────────────────

    /**
     * T042-060 — displayBackOfficeTop hook.
     *
     * Renders a compact trust score badge in the Back Office header on every page.
     * Only shown when the module is fully configured (merchant_id set).
     * Score is fetched client-side to avoid blocking server response.
     */
    public function hookDisplayBackOfficeTop(): string
    {
        $merchantId = (string) Configuration::get(self::CFG_MERCHANT_ID);
        if ($merchantId === '') {
            return '';
        }

        $trustUrl = $this->context->link->getAdminLink('AdminAgenticTrust');

        return '
<style>
.amcp-badge{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;
 border-radius:999px;background:#f0fdf4;border:1px solid #bbf7d0;
 font-size:12px;color:#15803d;cursor:pointer;text-decoration:none;
 margin-left:8px;vertical-align:middle;}
.amcp-badge:hover{background:#dcfce7;}
.amcp-badge__dot{width:7px;height:7px;border-radius:50%;background:#22c55e;}
</style>
<a href="' . htmlspecialchars($trustUrl, ENT_QUOTES, 'UTF-8') . '" class="amcp-badge" title="' . $this->l('Open Trust Center') . '">
  <span class="amcp-badge__dot"></span>
  <span class="amcp-badge__label">Trusteed</span>
</a>';
    }

    // ─── Configuration page ────────────────────────────────────────────────────

    public function getContent(): string
    {
        // Redirect to the Quick Setup wizard when credentials are not yet set.
        $merchantId = (string) Configuration::get(self::CFG_MERCHANT_ID);
        $secret     = (string) Configuration::get(self::CFG_BOOTSTRAP_SECRET);
        $isDefaultSecret = strlen($secret) === 64 && $merchantId === '';

        if ($merchantId === '' || $isDefaultSecret) {
            $wizardUrl = $this->context->link->getAdminLink('AdminAgenticWizard');
            Tools::redirectAdmin($wizardUrl);
            return ''; // unreachable — redirect exits
        }

        $output = '';
        if (Tools::isSubmit('submitAgenticMcpStores')) {
            $merchantId = (string) Tools::getValue(self::CFG_MERCHANT_ID);
            $secret     = (string) Tools::getValue(self::CFG_BOOTSTRAP_SECRET);
            $apiBase    = trim((string) Tools::getValue(self::CFG_API_BASE));

            // Validate api_base before saving (SSRF prevention).
            if ($apiBase !== '') {
                try {
                    \Trusteed\Mvp\TokenBroker::validateApiBasePublic($apiBase);
                } catch (\InvalidArgumentException $e) {
                    $output .= $this->displayError(
                        $this->l('Invalid API Base URL: must be HTTPS and target an external host.')
                    );
                    return $output . $this->renderForm();
                }
            }

            Configuration::updateValue(self::CFG_MERCHANT_ID, $merchantId);

            // S042-002: validate hex format before saving; empty means "keep existing".
            if ($secret !== '') {
                if (!preg_match('/\A[0-9a-fA-F]{64}\z/', $secret)) {
                    $output .= $this->displayError(
                        $this->l('Invalid bootstrap secret: must be exactly 64 hex characters.')
                    );
                    return $output . $this->renderForm();
                }
                Configuration::updateValue(self::CFG_BOOTSTRAP_SECRET, $secret);
            }

            Configuration::updateValue(self::CFG_API_BASE, $apiBase);
            $output .= $this->displayConfirmation($this->l('Settings updated'));
        }
        return $output . $this->renderForm();
    }

    private function renderForm(): string
    {
        $fieldsForm = [
            'form' => [
                'legend' => ['title' => $this->l('Trusteed connection')],
                'input'  => [
                    [
                        'type'     => 'text',
                        'label'    => $this->l('Merchant ID'),
                        'name'     => self::CFG_MERCHANT_ID,
                        'desc'     => $this->l('UUID provided by Trusteed onboarding'),
                        'required' => true,
                    ],
                    [
                        'type'     => 'password',
                        'label'    => $this->l('Bootstrap Secret'),
                        'name'     => self::CFG_BOOTSTRAP_SECRET,
                        'desc'     => $this->l('Hex secret from portal onboarding'),
                        'required' => true,
                    ],
                    [
                        'type'     => 'text',
                        'label'    => $this->l('API Base URL'),
                        'name'     => self::CFG_API_BASE,
                        'desc'     => $this->l('Default: https://api.trusteed.xyz'),
                        'required' => true,
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'name'  => 'submitAgenticMcpStores',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->module          = $this;
        $helper->name_controller = $this->name;
        $helper->token           = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex    = AdminController::$currentIndex
            . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->fields_value = [
            self::CFG_MERCHANT_ID      => Configuration::get(self::CFG_MERCHANT_ID),
            self::CFG_BOOTSTRAP_SECRET => '', // S042-001: never echo the secret back to the browser
            self::CFG_API_BASE         => Configuration::get(self::CFG_API_BASE),
        ];
        return $helper->generateForm([$fieldsForm]);
    }
}
