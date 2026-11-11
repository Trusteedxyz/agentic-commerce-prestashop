<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Trusteed CEL Admin Settings renderer.
 *
 * Renders and handles the CEL configuration section within the module's
 * back-office configuration page. Uses PS HelperForm for native styling.
 *
 * Settings managed:
 *  - TRUSTEED_CEL_ENABLED          (checkbox)
 *  - TRUSTEED_CEL_MERCHANT_ID      (text)
 *  - TRUSTEED_CEL_INSTALLATION_ID  (text)
 *  - TRUSTEED_CEL_HMAC_SECRET      (password — never echoed back)
 *  - TRUSTEED_CEL_FALLBACK_MODE    (select: strict|balanced|permissive)
 *
 * @package Trusteed\Enforcement
 */
class AdminSettings
{
    /** Form submit name for CEL settings */
    private const SUBMIT_KEY = 'submitTrusteedCEL';

    private \Trusteed $module;

    public function __construct(\Trusteed $module)
    {
        $this->module = $module;
    }

    /**
     * Handle form submit and render the CEL settings block.
     * Called from Trusteed::getContent().
     */
    public function render(): string
    {
        $output = '';

        if (\Tools::isSubmit(self::SUBMIT_KEY)) {
            $output .= $this->save();
        }

        if (\Tools::isSubmit('submitTrusteedCELRefreshSnapshot')) {
            $output .= $this->refreshSnapshot();
        }

        if (\Tools::isSubmit('submitTrusteedCELAckGap')) {
            WebserviceGapMiddleware::acknowledgeGapNotice();
            $output .= $this->module->displayConfirmation(
                $this->module->l('Webservice notice dismissed.')
            );
        }

        if (\Tools::isSubmit('submitTrusteedCELAckMcpNoAuth')) {
            McpNoAuthDetector::acknowledgeNotice();
            $output .= $this->module->displayConfirmation(
                $this->module->l('Notice dismissed.')
            );
        }

        if (WebserviceGapMiddleware::hasPendingGapNotice()) {
            $output .= $this->module->displayWarning(
                $this->module->l(
                    'Orders created through the PrestaShop Webservice (/api/orders) were detected without the Trusteed proxy, '
                    . 'so checkout protection does not apply to them. '
                    . 'Set up the Trusteed proxy or dismiss this notice below.'
                )
            );
        }

        if (McpNoAuthDetector::hasPendingNotice()) {
            $output .= $this->module->displayWarning(
                $this->module->l(McpNoAuthDetector::adminBannerMessage())
            );
        }

        return $output . $this->renderForm() . $this->renderStatsBlock();
    }

    /**
     * Save CEL settings from POST data.
     */
    public function save(): string
    {
        $enabled = \Tools::getValue('TRUSTEED_CEL_ENABLED') === '1' ? '1' : '0';
        \Configuration::updateValue('TRUSTEED_CEL_ENABLED', $enabled);

        $merchantId = trim((string) \Tools::getValue('TRUSTEED_CEL_MERCHANT_ID'));
        if ($merchantId !== '') {
            \Configuration::updateValue('TRUSTEED_CEL_MERCHANT_ID', $merchantId);
        }

        $installationId = trim((string) \Tools::getValue('TRUSTEED_CEL_INSTALLATION_ID'));
        if ($installationId !== '') {
            \Configuration::updateValue('TRUSTEED_CEL_INSTALLATION_ID', $installationId);
        }

        // HMAC secret — only update when explicitly provided (never echo back)
        $hmacSecret = trim((string) \Tools::getValue('TRUSTEED_CEL_HMAC_SECRET'));
        if ($hmacSecret !== '') {
            \Configuration::updateValue('TRUSTEED_CEL_HMAC_SECRET', $hmacSecret);
        }

        $fallbackMode = (string) \Tools::getValue('TRUSTEED_CEL_FALLBACK_MODE');
        if (in_array($fallbackMode, ['strict', 'balanced', 'permissive'], true)) {
            \Configuration::updateValue('TRUSTEED_CEL_FALLBACK_MODE', $fallbackMode);
        }

        $browserMode = (string) \Tools::getValue(BrowserAgentSignature::CONFIG_MODE);
        if (in_array($browserMode, BrowserAgentSignature::MODES, true)) {
            \Configuration::updateValue(BrowserAgentSignature::CONFIG_MODE, $browserMode);
        }

        $webmcp = (string) \Tools::getValue(WebmcpCheckout::CONFIG);
        if (in_array($webmcp, ['off', 'on'], true)) {
            \Configuration::updateValue(WebmcpCheckout::CONFIG, $webmcp);
        }

        return $this->module->displayConfirmation(
            $this->module->l('Checkout protection settings saved.')
        );
    }

    /**
     * Force-refresh the cached snapshot for the current merchant.
     */
    private function refreshSnapshot(): string
    {
        $client = MerchantResolver::buildSnapshotClient(MerchantResolver::currentShopId());
        if ($client === null) {
            return $this->module->displayError(
                $this->module->l('Checkout protection is not fully configured: your rules cannot be refreshed.')
            );
        }

        $jws = $client->pull();
        if ($jws === null) {
            return $this->module->displayError(
                $this->module->l('Your rules could not be downloaded from Trusteed. Check the credentials and the connection.')
            );
        }

        return $this->module->displayConfirmation(
            $this->module->l('Your rules have been refreshed.')
        );
    }

    /**
     * Render the HelperForm for CEL settings.
     */
    private function renderForm(): string
    {
        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->module->l('Checkout protection'),
                    'icon'  => 'icon-shield',
                ],
                'description' => $this->module->l(
                    'Blocks the checkouts of AI agents that break your rules. '
                    . 'It needs the store to be connected to Trusteed (see "Auto-register this store").'
                ),
                'input' => [
                    [
                        'type'   => 'switch',
                        'label'  => $this->module->l('Protect my checkout'),
                        'name'   => 'TRUSTEED_CEL_ENABLED',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'cel_on', 'value' => 1, 'label' => $this->module->l('Enabled')],
                            ['id' => 'cel_off', 'value' => 0, 'label' => $this->module->l('Disabled')],
                        ],
                    ],
                    [
                        'type'     => 'text',
                        'label'    => $this->module->l('Store ID in Trusteed'),
                        'name'     => 'TRUSTEED_CEL_MERCHANT_ID',
                        'desc'     => $this->module->l('Filled in automatically by "Auto-register this store".'),
                        'required' => false,
                    ],
                    [
                        'type'     => 'text',
                        'label'    => $this->module->l('Installation ID'),
                        'name'     => 'TRUSTEED_CEL_INSTALLATION_ID',
                        'desc'     => $this->module->l('Identifies this PrestaShop installation. Filled in automatically.'),
                        'required' => false,
                    ],
                    [
                        'type'     => 'password',
                        'label'    => $this->module->l('Signing key'),
                        'name'     => 'TRUSTEED_CEL_HMAC_SECRET',
                        'desc'     => $this->module->l('Used to sign the requests this store sends to Trusteed. Leave it blank to keep the current one.'),
                        'required' => false,
                    ],
                    [
                        'type'    => 'select',
                        'label'   => $this->module->l('If Trusteed does not answer'),
                        'name'    => 'TRUSTEED_CEL_FALLBACK_MODE',
                        'desc'    => $this->module->l(
                            'Only applies to checkouts by AI agents: a customer without an agent is never blocked '
                            . 'because Trusteed is unavailable. '
                            . 'It decides what happens when Trusteed does not answer but a verified copy of your rules is available. '
                            . 'Strict: block the agent checkout. '
                            . 'Balanced: let it through and raise an alert. '
                            . 'Permissive: let it through. '
                            . 'In every mode, an agent checkout is held if your signed rules cannot be loaded or their signature does not check out.'
                        ),
                        'options' => [
                            'query' => [
                                ['id' => 'strict',      'name' => $this->module->l('Strict')],
                                ['id' => 'balanced',    'name' => $this->module->l('Balanced (default)')],
                                ['id' => 'permissive',  'name' => $this->module->l('Permissive')],
                            ],
                            'id'   => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type'    => 'select',
                        'label'   => $this->module->l('Signature of browser agents'),
                        'name'    => BrowserAgentSignature::CONFIG_MODE,
                        'desc'    => $this->module->l(
                            'Checks the signature (Web Bot Auth) that an AI agent browsing your store can attach. '
                            . 'Observe: check and record only. '
                            . 'Enforce: a verified agent is identified, and a forged signature is treated as an anonymous agent. '
                            . 'A failed check never changes how an agent is classified.'
                        ),
                        'options' => [
                            'query' => [
                                ['id' => 'off',     'name' => $this->module->l('Off')],
                                ['id' => 'observe', 'name' => $this->module->l('Observe (default)')],
                                ['id' => 'enforce', 'name' => $this->module->l('Enforce')],
                            ],
                            'id'   => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type'    => 'select',
                        'label'   => $this->module->l('Checkout tools for browser agents'),
                        'name'    => WebmcpCheckout::CONFIG,
                        'desc'    => $this->module->l(
                            'Lets AI agents that browse your store read and update the cart and ask to complete the checkout, from the checkout page. '
                            . 'They never place the order: the buyer always confirms it in the checkout. '
                            . 'A cart handled this way is treated as an agent checkout by your rules.'
                        ),
                        'options' => [
                            'query' => [
                                ['id' => 'off', 'name' => $this->module->l('Off (default)')],
                                ['id' => 'on',  'name' => $this->module->l('On')],
                            ],
                            'id'   => 'id',
                            'name' => 'name',
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->module->l('Save checkout protection'),
                    'name'  => self::SUBMIT_KEY,
                ],
                'buttons' => [
                    [
                        'title'  => $this->module->l('Refresh my rules now'),
                        'name'   => 'submitTrusteedCELRefreshSnapshot',
                        'type'   => 'submit',
                        'class'  => 'btn btn-default',
                        'icon'   => 'process-icon-refresh',
                    ],
                    [
                        'title'  => $this->module->l('Dismiss the Webservice notice'),
                        'name'   => 'submitTrusteedCELAckGap',
                        'type'   => 'submit',
                        'class'  => 'btn btn-default',
                        'icon'   => 'process-icon-check',
                    ],
                ],
            ],
        ];

        $helper                    = new \HelperForm();
        $helper->module            = $this->module;
        $helper->name_controller   = $this->module->name;
        $helper->token             = \Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex      = \AdminController::$currentIndex
            . '&configure=' . $this->module->name;
        $helper->default_form_language = (int) \Configuration::get('PS_LANG_DEFAULT');
        $helper->fields_value      = [
            'TRUSTEED_CEL_ENABLED'         => \Configuration::get('TRUSTEED_CEL_ENABLED'),
            'TRUSTEED_CEL_MERCHANT_ID'     => \Configuration::get('TRUSTEED_CEL_MERCHANT_ID'),
            'TRUSTEED_CEL_INSTALLATION_ID' => \Configuration::get('TRUSTEED_CEL_INSTALLATION_ID'),
            'TRUSTEED_CEL_HMAC_SECRET'     => '', // Never echo secret back to browser
            // El modo que APLICA el módulo (MerchantResolver: ausente o no
            // reconocido ⇒ strict), no un `balanced` que no rige.
            'TRUSTEED_CEL_FALLBACK_MODE'   => MerchantResolver::getFallbackMode(),
            BrowserAgentSignature::CONFIG_MODE => (new BrowserAgentSignature())->mode(),
            WebmcpCheckout::CONFIG => WebmcpCheckout::isOn((string) \Configuration::get(WebmcpCheckout::CONFIG)) ? 'on' : 'off',
        ];

        return $helper->generateForm([$fieldsForm]);
    }

    /**
     * Fetch enforcement stats from the Trusteed API and render an HTML summary.
     *
     * Non-critical path — returns a graceful degradation message on any error.
     * Uses cURL consistent with SnapshotClient to avoid wp_remote_get dependency.
     */
    private function renderStatsBlock(): string
    {
        $merchantId = (string) \Configuration::get('TRUSTEED_CEL_MERCHANT_ID');
        $installId  = (string) \Configuration::get('TRUSTEED_CEL_INSTALLATION_ID');
        $hmacSecret = (string) \Configuration::get('TRUSTEED_CEL_HMAC_SECRET');
        $apiBase    = (string) \Configuration::get(
            'TRUSTEED_API_BASE',
            null,
            null,
            null,
            'https://api.trusteed.xyz'
        );

        if (empty($merchantId) || empty($installId) || empty($hmacSecret)) {
            return '<div class="alert alert-info">'
                . $this->module->l(
                        'Connect the store to Trusteed first to see the activity of your checkout protection.'
                    )
                . '</div>';
        }

        $ts  = time();
        // Ruta HMAC por instalación: `/v1/embed/merchant/stats` es la del panel (token de embed) y devolvía 401.
        $url = rtrim($apiBase, '/') . '/v1/embed/merchant/enforcement-stats?window=7&installationId=' . rawurlencode($installId);
        $sig = InstallationSignature::header($hmacSecret, '', $ts);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT        => 5,
            \CURLOPT_HTTPHEADER     => [
                'X-Trusteed-Installation-Id: ' . $installId,
                'X-Trusteed-Signature: ' . $sig,
                'Accept: application/json',
            ],
            \CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !is_string($body) || $body === '') {
            return '<div class="alert alert-warning">'
                . $this->module->l('The activity could not be loaded from Trusteed right now.')
                . '</div>';
        }

        $data  = json_decode($body, true);
        $stats = is_array($data['stats'] ?? null) ? $data['stats'] : [];

        $html  = '<h4>' . $this->module->l('Recent activity (7 days)') . '</h4>';

        if (empty($stats)) {
            $html .= '<p class="help-block">'
                . $this->module->l('No checkout was checked in the last 7 days.')
                . '</p>';
            return $html;
        }

        $html .= '<table class="table">'
            . '<thead><tr>'
            . '<th>' . $this->module->l('Rule') . '</th>'
            . '<th>' . $this->module->l('Blocked') . '</th>'
            . '<th>' . $this->module->l('Allowed') . '</th>'
            . '<th>' . $this->module->l('Let through on error') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($stats as $row) {
            $ruleCode  = htmlspecialchars((string) ($row['ruleCode'] ?? ''), ENT_QUOTES, 'UTF-8');
            $blocked   = (int) ($row['blockedCount'] ?? 0);
            $allowed   = (int) ($row['allowedCount'] ?? 0);
            $failOpen  = (int) ($row['failOpenCount'] ?? 0);

            $blockedCell = $blocked > 0
                ? '<span style="background:#d9534f;color:#fff;padding:2px 6px;border-radius:3px;font-weight:600">' . $blocked . '</span>'
                : '<span style="color:#888">0</span>';

            $html .= '<tr>'
                . '<td><strong>' . $ruleCode . '</strong></td>'
                . '<td>' . $blockedCell . '</td>'
                . '<td>' . $allowed . '</td>'
                . '<td>' . $failOpen . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table>';

        if (isset($data['windowStart'], $data['windowEnd'])) {
            // Sólo la fecha: el instante completo (2026-11-04T18:22:24.365Z) no le dice nada al comerciante.
            $wStart = htmlspecialchars(substr((string) $data['windowStart'], 0, 10), ENT_QUOTES, 'UTF-8');
            $wEnd   = htmlspecialchars(substr((string) $data['windowEnd'], 0, 10), ENT_QUOTES, 'UTF-8');
            $html  .= '<p class="help-block">'
                . $this->module->l('Period:')
                . ' ' . $wStart . ' → ' . $wEnd
                . '</p>';
        }

        return $html;
    }
}
