<?php

declare(strict_types=1);

/**
 * Trusteed: Agentic MCP Stores PrestaShop module.
 *
 * This module extends the Trusteed Trust Center with AgenticTools support:
 * when the PrestaShop MCP Server add-on (marketplace ID 96617) is installed,
 * five canonical MCP tools are automatically registered, allowing agents
 * (Claude Desktop, etc.) to sign trust receipts and dispatch payments
 * directly from the PrestaShop back office.
 *
 * Compatible: PrestaShop 8.0.0 to 9.99.99
 * PHP:        8.1+
 *
 * @author  Trusteed
 * @license MIT
 *
 * Architecture notes:
 *  - PSR-4 autoload via composer: "Trusteed\\" → "src/"
 *  - No hard dependency on php-mcp/server (comes transitively from add-on 96617)
 *  - Graceful degradation: tools are silently skipped when add-on is absent
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Composer autoloader, present when vendor/ is generated inside the package.
$autoloader = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
} else {
    // Gap 6 fix: PSR-4 fallback autoloader for the Trusteed\ namespace when
    // vendor/ is absent (ZIP install without composer). Covers all subdirs:
    // Admin, AgenticTools, Enforcement, Hmac, Hooks, Service, Storage.
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Trusteed\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });
}

class Trusteed extends Module
{
    public function __construct()
    {
        $this->name             = 'trusteed';
        $this->tab              = 'administration';
        $this->version          = '2.3.4';
        $this->author           = 'Trusteed';
        $this->need_instance    = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '9.99.99'];
        $this->bootstrap        = true;

        parent::__construct();

        $this->displayName = $this->l('Trusteed AgenticTools');
        $this->description = $this->l(
            'Registers Trusteed AgenticTools with the PrestaShop MCP Server '
            . '(add-on 96617) for agent-native trust receipts and payments.'
        );
    }

    // ─── MCP compliance marker ─────────────────────────────────────────────────

    /**
     * Signals to the MCP Server add-on (96617) that this module participates
     * in the AgenticTools protocol.  The add-on queries `isMcpCompliant()`
     * during tool discovery to decide whether to scan the module's classes.
     */
    public function isMcpCompliant(): bool
    {
        return true;
    }

    // ─── Lifecycle ─────────────────────────────────────────────────────────────

    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }

        Configuration::updateValue('TRUSTEED_API_BASE', 'https://api.trusteed.xyz');
        // Gap 2: canonical S2S secret key (legacy TRUSTEED_BOOTSTRAP_TOKEN kept for upgrade compat).
        Configuration::updateValue('TRUSTEED_EMBED_S2S_SECRET', '');

        // Persist per-tool toggle defaults (sign+verify ON, ACP/x402/AP2 OFF).
        if (class_exists('Trusteed\\AgenticTools\\ToolToggleSettings')) {
            \Trusteed\AgenticTools\ToolToggleSettings::installDefaults();
        }

        // Register the admin Tab (back office menu)
        $this->installAdminTab();

        // CEL enforcement defaults
        Configuration::updateValue('TRUSTEED_CEL_ENABLED', '0');
        Configuration::updateValue('TRUSTEED_CEL_FALLBACK_MODE', 'balanced');
        Configuration::updateValue('TRUSTEED_CEL_MERCHANT_ID', '');
        Configuration::updateValue('TRUSTEED_CEL_INSTALLATION_ID', '');
        Configuration::updateValue('TRUSTEED_CEL_HMAC_SECRET', '');

        // UCP `.well-known` discovery redirect is OPT-IN, default OFF.
        Configuration::updateValue('TRUSTEED_UCP_WELLKNOWN_ENABLED', '0');

        // FriendlyURL route for /.well-known/ucp → ucpwellknown front controller.
        // The hook handler self-gates on the flag, so registering it is inert
        // until the merchant opts in.
        $this->registerHook('moduleRoutes');

        // Back office header badge hook
        $this->registerHook('displayBackOfficeTop');

        // CEL hook registration (PS 9+ forward compat + telemetry)
        if (class_exists('Trusteed\\Enforcement\\ValidateOrderHook')) {
            \Trusteed\Enforcement\ValidateOrderHook::register($this);
        } else {
            $this->registerHook('actionValidateOrderBefore');
            $this->registerHook('actionCartSave');
        }

        // Webservice gap detection
        if (class_exists('Trusteed\\Enforcement\\WebserviceGapMiddleware')) {
            \Trusteed\Enforcement\WebserviceGapMiddleware::register($this);
        } else {
            $this->registerHook('actionWebserviceCallBefore');
        }

        // PS MCP No-Auth mode detection (observe-by-default).
        if (class_exists('Trusteed\\Enforcement\\McpNoAuthDetector')) {
            \Trusteed\Enforcement\McpNoAuthDetector::register($this);
        }

        // Order events (fulfillment + refund proxy).
        if (class_exists('Trusteed\\Hooks\\OrderEventsEmitter')) {
            \Trusteed\Hooks\OrderEventsEmitter::register($this);
        }

        // Payment failure emitter (actionPaymentCCAdd + displayPaymentReturn).
        if (class_exists('Trusteed\\Enforcement\\PaymentFailureEmitter')) {
            \Trusteed\Enforcement\PaymentFailureEmitter::register($this);
        }

        // Coupon-attempt-failed emitter (actionValidateOrder).
        if (class_exists('Trusteed\\Enforcement\\CouponFailureEmitter')) {
            \Trusteed\Enforcement\CouponFailureEmitter::register($this);
        }

        // Transient cart→agentDid store for agentIdHash propagation.
        if (class_exists('Trusteed\\Storage\\CartAgentStorage')) {
            \Trusteed\Storage\CartAgentStorage::createTable();
        }

        $this->invalidateMcpCache();

        return true;
    }

    public function uninstall(): bool
    {
        $this->uninstallAdminTab();

        // The read key is an artifact of this module: it is deleted from the
        // store and Trusteed is asked to forget it BEFORE deregistering, while
        // the install_key and API base still exist. Never throws.
        if (class_exists('Trusteed\\Admin\\ReadKeyPanel')) {
            \Trusteed\Admin\ReadKeyPanel::revokeOnUninstall();
        }
        Configuration::deleteByName('TRUSTEED_WS_READ_KEY_ID');
        Configuration::deleteByName('TRUSTEED_WS_ENABLED_BY_MODULE');

        // Notify Trusteed BEFORE deleting anything.
        //
        // PrestaShop has no equivalent of Shopify's `app/uninstalled`. Without
        // this call the keys below were deleted silently: the merchant vanished
        // from their back office but stayed published in
        // `/.well-known/merchant-index.json`, in the directory and with an
        // announced MCP endpoint.
        //
        // It goes first because it needs `TRUSTEED_API_BASE` and
        // `TRUSTEED_INSTALL_KEY`, which the next four lines delete.
        // `deregister()` never throws: an uninstall must not stop halfway
        // because the backend does not answer.
        $this->notifyTrusteedOfUninstall();

        Configuration::deleteByName('TRUSTEED_API_BASE');
        Configuration::deleteByName('TRUSTEED_EMBED_S2S_SECRET');
        Configuration::deleteByName('TRUSTEED_BOOTSTRAP_TOKEN');
        Configuration::deleteByName('TRUSTEED_INSTALL_KEY');

        // Remove per-tool toggle config keys.
        if (class_exists('Trusteed\\AgenticTools\\ToolToggleSettings')) {
            \Trusteed\AgenticTools\ToolToggleSettings::uninstallDefaults();
        }

        // CEL configuration cleanup
        Configuration::deleteByName('TRUSTEED_CEL_ENABLED');
        Configuration::deleteByName('TRUSTEED_CEL_FALLBACK_MODE');
        Configuration::deleteByName('TRUSTEED_CEL_MERCHANT_ID');
        Configuration::deleteByName('TRUSTEED_CEL_INSTALLATION_ID');
        Configuration::deleteByName('TRUSTEED_CEL_HMAC_SECRET');
        Configuration::deleteByName('TRUSTEED_CEL_CAPS_REPORTED_VERSION');
        Configuration::deleteByName('TRUSTEED_CEL_CAPS_REPORTED_AT');
        Configuration::deleteByName('TRUSTEED_CEL_CAPS_ATTEMPT_AT');

        // UCP `.well-known` discovery flag cleanup.
        Configuration::deleteByName('TRUSTEED_UCP_WELLKNOWN_ENABLED');

        // Unregister CEL hooks
        if (class_exists('Trusteed\\Enforcement\\ValidateOrderHook')) {
            \Trusteed\Enforcement\ValidateOrderHook::unregister($this);
        }
        if (class_exists('Trusteed\\Enforcement\\WebserviceGapMiddleware')) {
            \Trusteed\Enforcement\WebserviceGapMiddleware::unregister($this);
        }
        if (class_exists('Trusteed\\Enforcement\\McpNoAuthDetector')) {
            \Trusteed\Enforcement\McpNoAuthDetector::unregister($this);
        }
        if (class_exists('Trusteed\\Hooks\\OrderEventsEmitter')) {
            \Trusteed\Hooks\OrderEventsEmitter::unregister($this);
        }
        if (class_exists('Trusteed\\Enforcement\\PaymentFailureEmitter')) {
            \Trusteed\Enforcement\PaymentFailureEmitter::unregister($this);
        }
        if (class_exists('Trusteed\\Enforcement\\CouponFailureEmitter')) {
            \Trusteed\Enforcement\CouponFailureEmitter::unregister($this);
        }
        if (class_exists('Trusteed\\Storage\\CartAgentStorage')) {
            \Trusteed\Storage\CartAgentStorage::dropTable();
        }

        // Symmetry with install(): unregister the directly-registered hooks.
        $this->unregisterHook('moduleRoutes');
        $this->unregisterHook('displayBackOfficeTop');

        return parent::uninstall();
    }

    /**
     * Deregisters the store with Trusteed before `uninstall()` deletes the
     * credentials. Best-effort and silent: a failure here must never stop the
     * merchant from uninstalling the module.
     */
    private function notifyTrusteedOfUninstall(): void
    {
        if (!class_exists('Trusteed\\Service\\AutoRegister')) {
            return;
        }

        $installKey = (string) Configuration::get('TRUSTEED_INSTALL_KEY');
        if ($installKey === '') {
            return;
        }

        $apiBase = (string) Configuration::get(
            'TRUSTEED_API_BASE',
            null,
            null,
            null,
            'https://api.trusteed.xyz'
        );

        try {
            $service = new \Trusteed\Service\AutoRegister($apiBase);
            $service->deregister((string) Tools::getShopDomainSsl(true), $installKey);
        } catch (\Throwable $e) {
            // The constructor validates apiBase and may throw; a misconfigured
            // URL must not break the uninstall.
        }
    }

    // ─── AgenticTools bootstrap ────────────────────────────────────────────────

    /**
     * Instantiates and returns all AgenticTool objects for the current
     * back-office employee session.
     *
     * Called by the MCP Server add-on after confirming `isMcpCompliant()`.
     * Returns an empty array when the add-on SDK is not present (graceful
     * degradation), when credentials are not configured, or when the employee
     * context is unavailable.
     *
     * @return list<object>
     */
    public function getAgenticTools(): array
    {
        // Guard: require the php-mcp/server attribute classes to be loadable.
        if (!\Trusteed\AgenticTools\McpToolGuard::isAvailable()) {
            return [];
        }

        $apiBase = (string) Configuration::get('TRUSTEED_API_BASE');
        // Gap 2: prefer canonical key, fall back to legacy for backward compat.
        $token = (string) Configuration::get('TRUSTEED_EMBED_S2S_SECRET');
        if ($token === '') {
            $token = (string) Configuration::get('TRUSTEED_BOOTSTRAP_TOKEN');
        }

        if ($apiBase === '' || $token === '') {
            return [];
        }

        $employeeId = (int) ($this->context->employee->id ?? 0);

        if ($employeeId <= 0) {
            return [];
        }

        $apiClient      = new \Trusteed\AgenticTools\BackendApiClient($apiBase, $token);
        $permissionGuard = new \Trusteed\AgenticTools\PermissionGuard();

        // Compute the effective enabled set.
        //  - Read the bundled catalog (agentic-tools-catalog.json) to get
        //    each tool's honest backendStatus. The remote catalog endpoint is the
        //    SSOT, but on first bootstrap / when unreachable the bundle is the
        //    fallback (the module never blocks activation on a remote fetch).
        //  - Combine with the per-tool merchant toggles. Disabled or
        //    not-module-fulfillable or backendStatus:"planned" tools are OMITTED.
        $enabledMap = $this->resolveEnabledToolMap();

        $candidates = [
            'sign-trust-receipt'     => static fn () => new \Trusteed\AgenticTools\Tools\SignTrustReceiptTool($apiClient, $permissionGuard, $employeeId),
            'verify-agent-signature' => static fn () => new \Trusteed\AgenticTools\Tools\VerifyAgentSignatureTool($apiClient, $permissionGuard, $employeeId),
            'dispatch-payment-acp'   => static fn () => new \Trusteed\AgenticTools\Tools\DispatchPaymentAcpTool($apiClient, $permissionGuard, $employeeId),
            'dispatch-payment-x402'  => static fn () => new \Trusteed\AgenticTools\Tools\DispatchPaymentX402Tool($apiClient, $permissionGuard, $employeeId),
            'dispatch-payment-ap2'   => static fn () => new \Trusteed\AgenticTools\Tools\DispatchPaymentAp2Tool($apiClient, $permissionGuard, $employeeId),
        ];

        $tools = [];
        foreach ($candidates as $toolKey => $factory) {
            if ($enabledMap[$toolKey] ?? false) {
                $tools[] = $factory();
            }
        }

        return $tools;
    }

    /**
     * Resolves the effective per-tool enabled map.
     *
     * Reads the bundled catalog for backendStatus and the persisted merchant
     * toggles, then combines them via ToolToggleSettings. If the bundle cannot be
     * read (packaging error), every tool with an unknown status defaults to
     * "planned" (disabled): fail-closed, never crash plugin activation.
     *
     * @return array<string,bool>
     */
    private function resolveEnabledToolMap(): array
    {
        $toggleClass = 'Trusteed\\AgenticTools\\ToolToggleSettings';
        if (!class_exists($toggleClass)) {
            return [];
        }

        $merchantToggles = \Trusteed\AgenticTools\ToolToggleSettings::merchantToggles();

        try {
            $catalog = \Trusteed\AgenticTools\ToolToggleSettings::bundledCatalog();
            $statusMap = \Trusteed\AgenticTools\ToolToggleSettings::backendStatusMap($catalog);
        } catch (\Throwable $e) {
            // Bundle missing/corrupt: fail closed (everything "planned" → disabled),
            // log, but never block module activation (activation is non-blocking).
            if (class_exists('\\PrestaShopLogger')) {
                \PrestaShopLogger::addLog('Trusteed: bundled catalog unreadable: ' . $e->getMessage(), 2);
            }
            $statusMap = [];
        }

        return \Trusteed\AgenticTools\ToolToggleSettings::effectiveEnabledMap($merchantToggles, $statusMap);
    }

    // ─── Admin Tab ────────────────────────────────────────────────────────────

    /**
     * Registers the admin Tab in the back office menu.
     * It is shown under "Modules" (CONFIGURE) as a standalone entry.
     */
    /**
     * Module tabs: parent `AdminTrusteed` plus children. The ORDER of this array
     * is the menu order (seven entries, same order and wording as Shopify,
     * WooCommerce and Magento).
     *
     * `visible => false` leaves the tab registered with `active = 0`: it is not
     * in the menu, but the controller still exists and resolves by URL (and from
     * the SPA's internal links). `class_name` must never change: it is
     * PrestaShop's permission key and the target of those links.
     *
     * The one-line format `'class_name' => ..., 'label' => ..., 'label_en' => ...`
     * is read by tests/enforcement/FallbackModeDefaultTest.php to check the READMEs.
     *
     * @var array<int, array{class_name: string, label: string, label_en: string, visible: bool}>
     */
    private const ADMIN_TABS = [
        ['class_name' => 'AdminTrusteedInicio',         'label' => 'Inicio',              'label_en' => 'Home',            'visible' => true],
        ['class_name' => 'AdminTrusteedVentas',         'label' => 'Mis ventas',          'label_en' => 'My sales',        'visible' => true],
        ['class_name' => 'AdminTrusteedAgentes',        'label' => 'Agentes',             'label_en' => 'Agents',          'visible' => true],
        ['class_name' => 'AdminTrusteedReglas',         'label' => 'Mis reglas',          'label_en' => 'My rules',        'visible' => true],
        // Its own entry since 2.3.3 (before, it was only a Merchant Center tab).
        ['class_name' => 'AdminTrusteedPaymentMethods', 'label' => 'Métodos de pago',     'label_en' => 'Payment methods', 'visible' => true],
        ['class_name' => 'AdminTrusteedTrustCenter',    'label' => 'Confianza',           'label_en' => 'Trust',           'visible' => true],
        ['class_name' => 'AdminTrusteedConfig',         'label' => 'Ajustes',             'label_en' => 'Settings',        'visible' => true],
        // Outside the menu, still reachable by URL.
        ['class_name' => 'AdminTrusteedMerchantCenter', 'label' => 'Centro de comercio',  'label_en' => 'Merchant Center', 'visible' => false],
        ['class_name' => 'AdminTrusteedSeguridad',      'label' => 'Seguridad',           'label_en' => 'Security',        'visible' => false],
        // Agent-friendly dashboard (Observed column).
        ['class_name' => 'AdminTrusteedAgentReadiness', 'label' => '¿Pueden comprar los agentes?', 'label_en' => 'Agent Readiness', 'visible' => false],
    ];

    private function installAdminTab(): void
    {
        // Evitar duplicados si install() se llama dos veces
        if (Tab::getIdFromClassName('AdminTrusteed') !== false) {
            return;
        }

        $configureId = (int) Tab::getIdFromClassName('CONFIGURE');
        if ($configureId === 0) {
            $configureId = (int) Tab::getIdFromClassName('AdminParentModulesSf');
        }

        // Tab padre (collapsible en sidebar)
        $parent               = new Tab();
        $parent->active       = 1;
        $parent->class_name   = 'AdminTrusteed';
        $parent->module       = $this->name;
        $parent->id_parent    = $configureId;
        $parent->icon         = 'security';
        foreach (Language::getLanguages() as $lang) {
            $parent->name[(int) $lang['id_lang']] = 'Trusteed';
        }
        $parent->add();

        $this->syncAdminTabs();
    }

    /**
     * Leaves the child tabs as ADMIN_TABS declares them: creates the missing ones
     * and, on existing ones, renames per language, sets `active` and reorders.
     * Idempotent, and used by both install() and upgrade/2.3.3.php so an upgraded
     * store and a fresh install end up with the same menu. It does not touch
     * `class_name` or the parent.
     *
     * Public because the upgrade script calls it on the module instance.
     */
    public function syncAdminTabs(): bool
    {
        $parentId = (int) Tab::getIdFromClassName('AdminTrusteed');
        if ($parentId === 0) {
            return false;
        }

        $languages = Language::getLanguages();
        $position  = 0;

        foreach (self::ADMIN_TABS as $def) {
            $id  = (int) Tab::getIdFromClassName($def['class_name']);
            $tab = $id > 0 ? new Tab($id) : new Tab();

            $tab->active     = $def['visible'] ? 1 : 0;
            $tab->class_name = $def['class_name'];
            $tab->module     = $this->name;
            $tab->id_parent  = $parentId;
            foreach ($languages as $lang) {
                $iso = strtolower((string) ($lang['iso_code'] ?? ''));
                $tab->name[(int) $lang['id_lang']] = ($iso === 'en' && isset($def['label_en']))
                    ? $def['label_en']
                    : $def['label'];
            }

            if ($id > 0) {
                $tab->update();
            } else {
                $tab->add();
            }

            // add() puts `position` at the end of the list: it is written
            // explicitly so the array order also holds on already installed stores.
            if ((int) $tab->id > 0) {
                \Db::getInstance()->update(
                    'tab',
                    ['position' => $position],
                    'id_tab = ' . (int) $tab->id
                );
            }
            ++$position;
        }

        return true;
    }

    /**
     * Removes the parent Tab and all children when the module is uninstalled.
     */
    private function uninstallAdminTab(): void
    {
        foreach (self::ADMIN_TABS as $def) {
            $id = (int) Tab::getIdFromClassName($def['class_name']);
            if ($id > 0) {
                (new Tab($id))->delete();
            }
        }

        $tabId = (int) Tab::getIdFromClassName('AdminTrusteed');
        if ($tabId > 0) {
            (new Tab($tabId))->delete();
        }
    }

    // ─── Hook: badge in the back office header ──────────────────────────────

    /**
     * Shows a "Trusteed" badge in the back office top bar, in one of three
     * states.
     *
     * This used to return early when `$merchantId === ''`, so the module's only
     * visual indicator appeared ONLY once configured. That is backwards: someone
     * who installed but did not finish got no signal and assumed they were done,
     * and someone who did finish saw the same green whether enforcement was
     * evaluating rules or silent for lack of credentials.
     *
     * Which state is which is decided in `Trusteed\Admin\SetupStatus`, which is
     * pure logic and has tests.
     */
    public function hookDisplayBackOfficeTop(array $params): string
    {
        $status = new \Trusteed\Admin\SetupStatus(
            (string) Configuration::get('TRUSTEED_CEL_MERCHANT_ID'),
            (string) Configuration::get('TRUSTEED_CEL_INSTALLATION_ID'),
            (string) Configuration::get('TRUSTEED_CEL_HMAC_SECRET')
        );

        // Report signal capabilities when the module version changes. It hangs
        // here because this is the cheapest back office hook already registered:
        // the usual check is a `Configuration` read, and an HTTP request only
        // happens on the first load after an upgrade.
        //
        // Still conditional on a registered store: without a `merchantId` there
        // is nothing to report and no one to send it to.
        if (!$status->needsAttention()
            || $status->state() === \Trusteed\Admin\SetupStatus::STATE_ENFORCEMENT_INCOMPLETE
        ) {
            \Trusteed\Enforcement\CapabilitiesReporter::maybeReport((string) $this->version);
        }

        $adminLink = $this->context->link->getAdminLink('AdminTrusteed');
        $label     = $this->l($status->labelKey());
        $tooltip   = $this->l($status->tooltipKey());

        return '<a href="' . htmlspecialchars($adminLink, ENT_QUOTES, 'UTF-8') . '"'
            . ' title="' . htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8') . '"'
            . ' style="display:inline-flex;align-items:center;gap:6px;'
            . 'background:' . htmlspecialchars($status->badgeColor(), ENT_QUOTES, 'UTF-8') . ';'
            . 'color:#fff;padding:4px 10px;border-radius:4px;'
            . 'font-size:12px;font-weight:600;text-decoration:none;margin-left:8px;">'
            . ($status->needsAttention() ? '⚠ ' : '🛡 ')
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</a>';
    }

    // ─── Hook: FriendlyURL route for /.well-known/ucp ──────────────────────────

    /**
     * Maps `/.well-known/ucp` to the `ucpwellknown` front controller, which
     * 302-redirects to the centrally generated per-store UCP manifest.
     *
     * Self-gated: returns an empty route set when the opt-in flag
     * (`TRUSTEED_UCP_WELLKNOWN_ENABLED`) is OFF, so no pretty route is added and
     * behavior stays byte-for-byte unchanged. The front controller re-checks the
     * gate as defense in depth.
     *
     * @param array<string,mixed> $params
     * @return array<string,array<string,mixed>>
     */
    public function hookModuleRoutes(array $params): array
    {
        $routes = [];

        if (\Trusteed\Discovery\UcpWellknownResolver::isEnabled()) {
            $routes['module-trusteed-ucpwellknown'] = [
                'controller' => 'ucpwellknown',
                'rule'       => '.well-known/ucp',
                'keywords'   => [],
                'params'     => [
                    'fc'     => 'module',
                    'module' => 'trusteed',
                ],
            ];
        }

        // Domain ownership challenge. It does NOT sit behind the UCP gate: they
        // are different things, and tying them together would leave no recovery
        // path for anyone with UCP off, which is the default.
        //
        // The route is always registered; the controller gates itself and
        // answers 404 while there is no active challenge. Registering it only
        // when one exists would force clearing PrestaShop's route cache in the
        // middle of sign-up, right when the merchant is waiting.
        $routes['module-trusteed-amcpverify'] = [
            'controller' => 'amcpverify',
            'rule'       => '.well-known/amcp-verify.txt',
            'keywords'   => [],
            'params'     => [
                'fc'     => 'module',
                'module' => 'trusteed',
            ],
        ];

        return $routes;
    }

    // ─── CEL Hook dispatchers ──────────────────────────────────────────────────

    /**
     * PS 9+ forward-compat: fires before validateOrder when the hook exists.
     * No-op in PS 8.2 (hook not registered in core).
     */
    public function hookActionValidateOrderBefore(array $params): void
    {
        if (!class_exists('Trusteed\\Enforcement\\ValidateOrderHook')) {
            return;
        }

        (new \Trusteed\Enforcement\ValidateOrderHook())->hookActionValidateOrderBefore($params);
    }

    /**
     * Coupon-attempt-failed emitter.
     *
     * Fires on actionValidateOrder (note: distinct from the PS 9+
     * actionValidateOrderBefore hook above). Delegates to CouponFailureEmitter,
     * which inspects rejected cart rules and POSTs a fire-and-forget signed event.
     */
    public function hookActionValidateOrder(array $params): void
    {
        if (!class_exists('Trusteed\\Enforcement\\CouponFailureEmitter')) {
            return;
        }

        (new \Trusteed\Enforcement\CouponFailureEmitter())->hookActionValidateOrder($params);
    }

    /**
     * Non-blocking telemetry: fires when a cart is saved.
     */
    public function hookActionCartSave(array $params): void
    {
        // Renews the capabilities report (every 7 days) even if nobody opens the
        // back office; the request is sent after the response.
        if (class_exists('Trusteed\\Enforcement\\CapabilitiesReporter')) {
            \Trusteed\Enforcement\CapabilitiesReporter::maybeReportAfterResponse((string) $this->version);
        }

        if (!class_exists('Trusteed\\Enforcement\\ValidateOrderHook')) {
            return;
        }

        (new \Trusteed\Enforcement\ValidateOrderHook())->hookActionCartSave($params);
    }

    /**
     * Webservice gap detection: fires before each Webservice API call.
     */
    public function hookActionWebserviceCallBefore(array $params): void
    {
        if (!class_exists('Trusteed\\Enforcement\\WebserviceGapMiddleware')) {
            return;
        }

        (new \Trusteed\Enforcement\WebserviceGapMiddleware())->hookActionWebserviceCallBefore($params);
    }

    /**
     * Back office sweep for PS MCP No-Auth mode.
     *
     * Observe-only here: surfaces the admin notice without ever blocking the
     * back office. The fail-closed enforce path (strict + unknown agent) lives
     * on the agentic request path, not on admin page renders, so we never let
     * a thrown PrestaShopException break the merchant's back office.
     */
    public function hookDisplayBackOfficeHeader(array $params): string
    {
        if (class_exists('Trusteed\\Enforcement\\McpNoAuthDetector')) {
            try {
                // Pass unknownAgent=false: the back-office sweep has no agent
                // context, so it must stay observe-only (never fail-closed).
                (new \Trusteed\Enforcement\McpNoAuthDetector())->detect(false);
            } catch (\Throwable $e) {
                // Defensive: admin UI must never break on detection.
            }
        }

        return '';
    }

    /**
     * Order state transitions to FULFILLED/REFUNDED.
     */
    public function hookActionOrderStatusUpdate(array $params): void
    {
        if (!class_exists('Trusteed\\Hooks\\OrderEventsEmitter')) {
            return;
        }

        (new \Trusteed\Hooks\OrderEventsEmitter())->hookActionOrderStatusUpdate($params);
    }

    /**
     * OrderSlip (credit slip) as REFUNDED proxy.
     *
     * Uses AddAfter rather than AddBefore so $slip->id > 0 and we don't emit
     * REFUNDED for failed slip creations.
     */
    public function hookActionObjectOrderSlipAddAfter(array $params): void
    {
        if (!class_exists('Trusteed\\Hooks\\OrderEventsEmitter')) {
            return;
        }

        (new \Trusteed\Hooks\OrderEventsEmitter())->hookActionObjectOrderSlipAddAfter($params);
    }

    /**
     * Payment failure emitter (credit-card flows).
     *
     * Fires when a payment record is added; PaymentFailureEmitter inspects
     * the related order state and emits a checkout-failure event when the
     * state is PS_OS_ERROR / PS_OS_CANCELED.
     */
    public function hookActionPaymentCCAdd(array $params): void
    {
        if (!class_exists('Trusteed\\Enforcement\\PaymentFailureEmitter')) {
            return;
        }

        (new \Trusteed\Enforcement\PaymentFailureEmitter())->hookActionPaymentCCAdd($params);
    }

    /**
     * Payment failure emitter (off-site PSP return).
     *
     * Display hooks MUST return a string. PaymentFailureEmitter inspects the
     * returning order state and emits a checkout-failure event when needed.
     */
    public function hookDisplayPaymentReturn(array $params): string
    {
        if (!class_exists('Trusteed\\Enforcement\\PaymentFailureEmitter')) {
            return '';
        }

        return (new \Trusteed\Enforcement\PaymentFailureEmitter())->hookDisplayPaymentReturn($params);
    }

    // ─── Configuration page ────────────────────────────────────────────────────

    public function getContent(): string
    {
        $output = '';

        if (Tools::isSubmit('submitTrusteed')) {
            $apiBase = trim((string) Tools::getValue('TRUSTEED_API_BASE'));
            // Gap 2: accept canonical field name; fall back to legacy field if posted by old form.
            $token = trim((string) Tools::getValue('TRUSTEED_EMBED_S2S_SECRET'));
            if ($token === '') {
                $token = trim((string) Tools::getValue('TRUSTEED_BOOTSTRAP_TOKEN'));
            }
            // A-#4: the panel needs TRUSTEED_CEL_MERCHANT_ID + secret; persist the
            // merchant id from this main form too (kept compatible with the CEL form).
            $merchantId = trim((string) Tools::getValue('TRUSTEED_CEL_MERCHANT_ID'));

            if ($apiBase !== '' && !str_starts_with($apiBase, 'https://')) {
                $output .= $this->displayError(
                    $this->l('API Base URL must use HTTPS.')
                );
            } elseif ($token !== '' && preg_match('/\A[0-9a-fA-F]{64}\z/', $token) !== 1) {
                $output .= $this->displayError(
                    $this->l('S2S secret must be exactly 64 hex characters.')
                );
            } else {
                if ($apiBase !== '') {
                    Configuration::updateValue('TRUSTEED_API_BASE', $apiBase);
                }
                if ($token !== '') {
                    // Write to canonical key; clear legacy key on successful migration.
                    Configuration::updateValue('TRUSTEED_EMBED_S2S_SECRET', $token);
                    Configuration::updateValue('TRUSTEED_BOOTSTRAP_TOKEN', '');
                }
                if ($merchantId !== '') {
                    Configuration::updateValue('TRUSTEED_CEL_MERCHANT_ID', $merchantId);
                }
                $output .= $this->displayConfirmation($this->l('Settings saved.'));
            }
        }

        // Self-serve auto-registration (additive convenience; the manual paste
        // path above stays fully functional).
        if (Tools::isSubmit('submitTrusteedAutoRegister')) {
            $output .= $this->handleAutoRegister();
        }

        // Webservice read key, with explicit consent: it is only created or
        // deleted when its button is pressed.
        $readKeyPanel = class_exists('Trusteed\\Admin\\ReadKeyPanel')
            ? new \Trusteed\Admin\ReadKeyPanel($this)
            : null;
        if ($readKeyPanel !== null) {
            $output .= $readKeyPanel->processSubmit();
        }

        // Per-tool toggle submission.
        if (Tools::isSubmit('submitTrusteedToggles')) {
            $output .= $this->saveToolToggles();
        }

        // CEL settings section
        if (class_exists('Trusteed\\Enforcement\\AdminSettings')) {
            $celSettings = new \Trusteed\Enforcement\AdminSettings($this);
            $output      .= $celSettings->render();
        }

        return $output
            . $this->renderAutoRegisterForm()
            . ($readKeyPanel !== null ? $readKeyPanel->renderForm() : '')
            . $this->renderForm()
            . $this->renderToolToggleForm();
    }

    /**
     * Self-serve auto-registration: POSTs the store fingerprint to Trusteed and
     * persists the returned merchant id + S2S secret + install key, so the
     * merchant does not have to paste them by hand. Never removes the manual
     * paste path. It just pre-fills the same config keys.
     */
    private function handleAutoRegister(): string
    {
        if (!class_exists('Trusteed\\Service\\AutoRegister')) {
            return $this->displayError($this->l('Auto-registration is unavailable in this build.'));
        }

        $apiBase = (string) Configuration::get('TRUSTEED_API_BASE', null, null, null, 'https://api.trusteed.xyz');
        $storeUrl   = (string) Tools::getShopDomainSsl(true);
        $storeName  = (string) Configuration::get('PS_SHOP_NAME');
        $adminEmail = (string) ($this->context->employee->email ?? '');
        $installKey = (string) Configuration::get('TRUSTEED_INSTALL_KEY');

        try {
            $autoRegister = new \Trusteed\Service\AutoRegister($apiBase);
            $result = $autoRegister->register(
                $storeUrl,
                $storeName,
                $adminEmail,
                (string) _PS_VERSION_,
                (string) $this->version,
                $installKey
            );
        } catch (\Trusteed\Service\DomainVerificationRequired $challenge) {
            // The URL is already registered with another install_key. Instead of
            // dead-ending, the token is published at `.well-known/amcp-verify.txt`,
            // the backend reads it from outside and, if it finds it, hands the
            // store over.
            //
            // This happens in the SAME request and asks nothing of the merchant:
            // the module can already write to its own domain, so showing them a
            // token to upload by hand would be work for them to prove something
            // the module can prove by itself.
            Configuration::updateValue(
                \Trusteed\Service\DomainChallengeStore::CONFIG_TOKEN,
                $challenge->challengeToken()
            );
            Configuration::updateValue(
                \Trusteed\Service\DomainChallengeStore::CONFIG_ISSUED_AT,
                time()
            );

            try {
                $result = $autoRegister->verifyChallenge(
                    $storeUrl,
                    $challenge->challengeToken()
                );
            } catch (\Throwable $e) {
                return $this->displayError(
                    $this->l(
                        'This store URL is already registered with Trusteed under a '
                        . 'different install key, and we could not prove that this site '
                        . 'controls the domain. Check that '
                    )
                    . htmlspecialchars($storeUrl . '/.well-known/amcp-verify.txt', ENT_QUOTES, 'UTF-8')
                    . $this->l(
                        ' is reachable from the internet (no maintenance mode, no '
                        . 'password protection, no CDN rule blocking it) and try again. '
                        . 'Details: '
                    )
                    . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
                );
            } finally {
                // El token deja de publicarse en cuanto se usa, salga bien o
                // mal: es de un solo uso y dejarlo expuesto no aporta nada.
                Configuration::updateValue(
                    \Trusteed\Service\DomainChallengeStore::CONFIG_TOKEN,
                    ''
                );
            }
        } catch (\Throwable $e) {
            return $this->displayError(
                $this->l('Auto-registration failed: ') . $e->getMessage()
            );
        }

        Configuration::updateValue('TRUSTEED_CEL_MERCHANT_ID', $result['merchant_id']);
        Configuration::updateValue('TRUSTEED_EMBED_S2S_SECRET', $result['bootstrap_secret']);
        // Clear any legacy token so the canonical key wins.
        Configuration::updateValue('TRUSTEED_BOOTSTRAP_TOKEN', '');
        if ($result['install_key'] !== '') {
            Configuration::updateValue('TRUSTEED_INSTALL_KEY', $result['install_key']);
        }

        $confirmation = $this->displayConfirmation(
            $result['is_new']
                ? $this->l('Store registered with Trusteed. Merchant ID and S2S secret configured automatically.')
                : $this->l('Store re-registered with Trusteed. Merchant ID and S2S secret refreshed.')
        );

        // The message used to say "Credentials configured automatically" after
        // writing only TWO of the five keys the module uses.
        // `TRUSTEED_CEL_INSTALLATION_ID` and `TRUSTEED_CEL_HMAC_SECRET` have no
        // automatic writer anywhere in the module, and without both of them no
        // rule is evaluated on any checkout. The merchant left believing the
        // opposite.
        $status = new \Trusteed\Admin\SetupStatus(
            $result['merchant_id'],
            (string) Configuration::get('TRUSTEED_CEL_INSTALLATION_ID'),
            (string) Configuration::get('TRUSTEED_CEL_HMAC_SECRET')
        );

        if (!$status->enforcementActive()) {
            $confirmation .= $this->displayWarning(
                $this->l(
                    'Checkout enforcement is NOT active yet: every checkout is allowed '
                    . 'through without evaluating a single rule. It needs two more values '
                    . 'that auto-registration cannot issue — the Installation ID and the '
                    . 'HMAC secret. Ask Trusteed for them and enter them in the '
                    . '"Checkout Enforcement" section below.'
                )
            );
        }

        return $confirmation;
    }

    /**
     * Renders the one-click auto-registration button. Shown above the manual
     * credential form so the merchant can pick either path.
     */
    private function renderAutoRegisterForm(): string
    {
        $fieldsForm = [
            'form' => [
                'legend' => ['title' => $this->l('Auto-register with Trusteed')],
                'description' => $this->l(
                    'One-click registration: creates (or refreshes) this store in Trusteed '
                    . 'and fills the Merchant ID and S2S secret automatically. You can still '
                    . 'paste them by hand in the form below.'
                ),
                'input'  => [],
                'submit' => [
                    'title' => $this->l('Auto-register this store'),
                    'name'  => 'submitTrusteedAutoRegister',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->module          = $this;
        $helper->name_controller = $this->name;
        $helper->token           = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex    = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->fields_value = [];

        return $helper->generateForm([$fieldsForm]);
    }

    /**
     * Persists the 5 per-tool toggles from the toggle form.
     */
    private function saveToolToggles(): string
    {
        if (!class_exists('Trusteed\\AgenticTools\\ToolToggleSettings')) {
            return '';
        }

        foreach (\Trusteed\AgenticTools\ToolToggleSettings::TOGGLE_KEYS as $toolKey => $cfgKey) {
            $posted = Tools::getValue($cfgKey);
            Configuration::updateValue($cfgKey, $posted === '1' ? '1' : '0');
        }

        // Invalidate MCP discovery cache so the new toggle set is re-scanned.
        $this->invalidateMcpCache();

        return $this->displayConfirmation($this->l('Agentic tool settings saved.'));
    }

    /**
     * Renders the 5 per-tool on/off toggles with safe defaults and an
     * honest annotation for tools whose backend is planned / not fulfillable.
     */
    private function renderToolToggleForm(): string
    {
        if (!class_exists('Trusteed\\AgenticTools\\ToolToggleSettings')) {
            return '';
        }

        $labels = [
            'sign-trust-receipt'     => $this->l('Sign Trust Receipt'),
            'verify-agent-signature' => $this->l('Verify Agent Signature'),
            'dispatch-payment-acp'   => $this->l('Dispatch Payment (ACP)'),
            'dispatch-payment-x402'  => $this->l('Dispatch Payment (x402)'),
            'dispatch-payment-ap2'   => $this->l('Dispatch Payment (AP2 v0.2)'),
        ];

        $toggles = \Trusteed\AgenticTools\ToolToggleSettings::merchantToggles();

        $inputs = [];
        $values = [];
        foreach (\Trusteed\AgenticTools\ToolToggleSettings::TOGGLE_KEYS as $toolKey => $cfgKey) {
            $fulfillable = \Trusteed\AgenticTools\ToolToggleSettings::moduleFulfillable($toolKey);
            $hint = $fulfillable
                ? $this->l('Enabled tools are exposed to the MCP server.')
                : $this->l('Not deployable yet — registered disabled even when ON (planned backend).');

            $inputs[] = [
                'type'    => 'switch',
                'label'   => $labels[$toolKey] ?? $toolKey,
                'name'    => $cfgKey,
                'is_bool' => true,
                'desc'    => $hint,
                'values'  => [
                    ['id' => $cfgKey . '_on',  'value' => 1, 'label' => $this->l('On')],
                    ['id' => $cfgKey . '_off', 'value' => 0, 'label' => $this->l('Off')],
                ],
            ];
            $values[$cfgKey] = ($toggles[$toolKey] ?? false) ? '1' : '0';
        }

        $fieldsForm = [
            'form' => [
                'legend' => ['title' => $this->l('Agentic Tools — per-tool enablement (FR-018b)')],
                'description' => $this->l(
                    'Trust + Identity are ON by default; the three payment rails are OFF '
                    . '(explicit opt-in). Disabled tools are omitted from MCP registration.'
                ),
                'input'  => $inputs,
                'submit' => [
                    'title' => $this->l('Save tool settings'),
                    'name'  => 'submitTrusteedToggles',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->module          = $this;
        $helper->name_controller = $this->name;
        $helper->token           = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex    = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->fields_value = $values;

        return $helper->generateForm([$fieldsForm]);
    }

    private function renderForm(): string
    {
        $mcpAvailable = class_exists('PhpMcp\\Server\\Attributes\\McpTool')
            ? $this->l('MCP Server add-on detected — AgenticTools will be registered.')
            : $this->l('MCP Server add-on (96617) not detected — install it to enable AgenticTools.');

        $fieldsForm = [
            'form' => [
                'legend' => ['title' => $this->l('Trusteed AgenticTools Configuration')],
                'description' => $mcpAvailable,
                'input'  => [
                    [
                        'type'     => 'text',
                        'label'    => $this->l('API Base URL'),
                        'name'     => 'TRUSTEED_API_BASE',
                        'desc'     => $this->l('Default: https://api.trusteed.xyz'),
                        'required' => true,
                    ],
                    [
                        'type'     => 'text',
                        'label'    => $this->l('Merchant ID'),
                        'name'     => 'TRUSTEED_CEL_MERCHANT_ID',
                        'desc'     => $this->l('Trusteed merchant UUID from onboarding — required to activate the admin panel.'),
                        'required' => true,
                    ],
                    [
                        'type'     => 'password',
                        'label'    => $this->l('Embed S2S Secret'),
                        'name'     => 'TRUSTEED_EMBED_S2S_SECRET',
                        'desc'     => $this->l('S2S secret (64 hex characters) from Trusteed onboarding — shown once at PrestaShop store connect.'),
                        'required' => true,
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'name'  => 'submitTrusteed',
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
            'TRUSTEED_API_BASE'           => Configuration::get('TRUSTEED_API_BASE'),
            'TRUSTEED_CEL_MERCHANT_ID'       => Configuration::get('TRUSTEED_CEL_MERCHANT_ID'),
            'TRUSTEED_EMBED_S2S_SECRET'   => '', // never echo the secret back to the browser
        ];

        return $helper->generateForm([$fieldsForm]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Deletes the MCP Server add-on discovery cache so newly registered tools
     * are picked up on the next request.
     */
    private function invalidateMcpCache(): void
    {
        $cacheFile = _PS_ROOT_DIR_ . DIRECTORY_SEPARATOR . '.mcp' . DIRECTORY_SEPARATOR . '.cache.json';

        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }
    }
}
