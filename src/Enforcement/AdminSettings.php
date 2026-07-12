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
                $this->module->l('Webservice gap notice acknowledged.')
            );
        }

        if (\Tools::isSubmit('submitTrusteedCELAckMcpNoAuth')) {
            McpNoAuthDetector::acknowledgeNotice();
            $output .= $this->module->displayConfirmation(
                $this->module->l('MCP No-Auth notice acknowledged.')
            );
        }

        if (WebserviceGapMiddleware::hasPendingGapNotice()) {
            $output .= $this->module->displayWarning(
                $this->module->l(
                    'CEL: Webservice /api/orders traffic detected without Trusteed MCP proxy. '
                    . 'CEL enforcement is bypassed on this path. '
                    . 'Configure the Trusteed MCP proxy or acknowledge below.'
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

        return $this->module->displayConfirmation(
            $this->module->l('CEL settings saved.')
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
                $this->module->l('CEL not fully configured. Cannot refresh snapshot.')
            );
        }

        $jws = $client->pull();
        if ($jws === null) {
            return $this->module->displayError(
                $this->module->l('Failed to pull snapshot from Trusteed API. Check credentials and connectivity.')
            );
        }

        return $this->module->displayConfirmation(
            $this->module->l('Snapshot refreshed successfully.')
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
                    'title' => $this->module->l('Checkout Enforcement Layer (CEL)'),
                    'icon'  => 'icon-shield',
                ],
                'description' => $this->module->l(
                    'CEL blocks agentic checkouts that violate your trust rules. '
                    . 'Requires a Trusteed merchant account and installation credentials.'
                ),
                'input' => [
                    [
                        'type'   => 'switch',
                        'label'  => $this->module->l('Enable CEL Enforcement'),
                        'name'   => 'TRUSTEED_CEL_ENABLED',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'cel_on', 'value' => 1, 'label' => $this->module->l('Enabled')],
                            ['id' => 'cel_off', 'value' => 0, 'label' => $this->module->l('Disabled')],
                        ],
                    ],
                    [
                        'type'     => 'text',
                        'label'    => $this->module->l('Merchant ID'),
                        'name'     => 'TRUSTEED_CEL_MERCHANT_ID',
                        'desc'     => $this->module->l('UUID from Trusteed dashboard.'),
                        'required' => false,
                    ],
                    [
                        'type'     => 'text',
                        'label'    => $this->module->l('Installation ID'),
                        'name'     => 'TRUSTEED_CEL_INSTALLATION_ID',
                        'desc'     => $this->module->l('Installation UUID for this PS store.'),
                        'required' => false,
                    ],
                    [
                        'type'     => 'password',
                        'label'    => $this->module->l('HMAC Secret'),
                        'name'     => 'TRUSTEED_CEL_HMAC_SECRET',
                        'desc'     => $this->module->l('Leave blank to keep existing secret.'),
                        'required' => false,
                    ],
                    [
                        'type'    => 'select',
                        'label'   => $this->module->l('Fallback Mode'),
                        'name'    => 'TRUSTEED_CEL_FALLBACK_MODE',
                        'desc'    => $this->module->l(
                            'strict: block when snapshot unavailable. '
                            . 'balanced: allow but alert. '
                            . 'permissive: always allow when offline.'
                        ),
                        'options' => [
                            'query' => [
                                ['id' => 'strict',      'name' => $this->module->l('Strict (fail-closed)')],
                                ['id' => 'balanced',    'name' => $this->module->l('Balanced (default)')],
                                ['id' => 'permissive',  'name' => $this->module->l('Permissive (fail-open)')],
                            ],
                            'id'   => 'id',
                            'name' => 'name',
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->module->l('Save CEL Settings'),
                    'name'  => self::SUBMIT_KEY,
                ],
                'buttons' => [
                    [
                        'title'  => $this->module->l('Refresh Snapshot Now'),
                        'name'   => 'submitTrusteedCELRefreshSnapshot',
                        'type'   => 'submit',
                        'class'  => 'btn btn-default',
                        'icon'   => 'process-icon-refresh',
                    ],
                    [
                        'title'  => $this->module->l('Acknowledge Webservice Gap'),
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
            'TRUSTEED_CEL_FALLBACK_MODE'   => \Configuration::get('TRUSTEED_CEL_FALLBACK_MODE') ?: 'balanced',
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
                . htmlspecialchars(
                    $this->module->l(
                        'Configure Merchant ID, Installation ID and HMAC Secret to see enforcement stats.'
                    ),
                    ENT_QUOTES,
                    'UTF-8'
                )
                . '</div>';
        }

        $ts  = time();
        $url = rtrim($apiBase, '/') . '/v1/embed/merchant/stats?window=7&installationId=' . rawurlencode($installId);
        $sig = 't=' . $ts . ',v1=' . hash_hmac('sha256', 't=' . $ts, $hmacSecret);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT        => 5,
            \CURLOPT_HTTPHEADER     => [
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
                . htmlspecialchars(
                    $this->module->l('Enforcement stats not available — Trusteed API unreachable.'),
                    ENT_QUOTES,
                    'UTF-8'
                )
                . '</div>';
        }

        $data  = json_decode($body, true);
        $stats = is_array($data['stats'] ?? null) ? $data['stats'] : [];

        $html  = '<h4>' . htmlspecialchars($this->module->l('Actividad reciente (7 días)'), ENT_QUOTES, 'UTF-8') . '</h4>';

        if (empty($stats)) {
            $html .= '<p class="help-block">'
                . htmlspecialchars(
                    $this->module->l('No enforcement events recorded in the last 7 days.'),
                    ENT_QUOTES,
                    'UTF-8'
                )
                . '</p>';
            return $html;
        }

        $html .= '<table class="table">'
            . '<thead><tr>'
            . '<th>' . htmlspecialchars($this->module->l('Regla'), ENT_QUOTES, 'UTF-8') . '</th>'
            . '<th>' . htmlspecialchars($this->module->l('Bloqueados'), ENT_QUOTES, 'UTF-8') . '</th>'
            . '<th>' . htmlspecialchars($this->module->l('Permitidos'), ENT_QUOTES, 'UTF-8') . '</th>'
            . '<th>' . htmlspecialchars($this->module->l('Fail-open'), ENT_QUOTES, 'UTF-8') . '</th>'
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
            $wStart = htmlspecialchars((string) $data['windowStart'], ENT_QUOTES, 'UTF-8');
            $wEnd   = htmlspecialchars((string) $data['windowEnd'], ENT_QUOTES, 'UTF-8');
            $html  .= '<p class="help-block">'
                . htmlspecialchars($this->module->l('Período:'), ENT_QUOTES, 'UTF-8')
                . ' ' . $wStart . ' → ' . $wEnd
                . '</p>';
        }

        return $html;
    }
}
