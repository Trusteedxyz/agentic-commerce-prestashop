<?php

declare(strict_types=1);

namespace Trusteed\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Trusteed\Service\AutoRegister;
use Trusteed\Service\PrestaShopReadKeyEnvironment;
use Trusteed\Service\ReadKeyManager;
use Trusteed\Service\ReadKeyResult;
use Trusteed\Service\ReadOnlyWebserviceKey;

/**
 * Consent block ("Allow Trusteed to read the catalog") on the module
 * configuration page. Same pattern as `Enforcement\AdminSettings`: it handles
 * its own submit and renders a HelperForm.
 *
 * Nothing is created until the button is pressed. The text lists exactly what
 * gets created, derived from `ReadOnlyWebserviceKey`, so what is shown and what
 * is granted cannot diverge.
 */
final class ReadKeyPanel
{
    public const SUBMIT_ENABLE = 'submitTrusteedReadKeyEnable';
    public const SUBMIT_REVOKE = 'submitTrusteedReadKeyRevoke';

    public function __construct(private \Trusteed $module)
    {
    }

    /** Handles the submit if there is one; returns the notice for the page header. */
    public function processSubmit(): string
    {
        if (\Tools::isSubmit(self::SUBMIT_ENABLE)) {
            return $this->handle(true);
        }
        if (\Tools::isSubmit(self::SUBMIT_REVOKE)) {
            return $this->handle(false);
        }

        return '';
    }

    /**
     * Silent revocation for `uninstall()`: never throws. The key belongs to the
     * module and must not outlive it.
     */
    public static function revokeOnUninstall(): void
    {
        try {
            $context = \Context::getContext();
            $apiBase = (string) \Configuration::get('TRUSTEED_API_BASE', null, null, null, 'https://api.trusteed.xyz');
            $manager = new ReadKeyManager(
                new PrestaShopReadKeyEnvironment((int) $context->shop->id, (int) $context->shop->id_shop_group),
                new AutoRegister($apiBase)
            );
            $manager->revoke(
                (string) \Tools::getShopDomainSsl(true),
                (string) \Configuration::get('TRUSTEED_INSTALL_KEY')
            );
        } catch (\Throwable $e) {
            // An uninstall must not be left half done because of this.
        }
    }

    private function handle(bool $enable): string
    {
        $context = \Context::getContext();
        $m = $this->module;

        // CSRF: the form carries the AdminModules token. PrestaShop already
        // checks it when entering the controller; it is required explicitly
        // here because this action creates credentials.
        $token = (string) \Tools::getValue('token');
        if (!hash_equals(\Tools::getAdminTokenLite('AdminModules'), $token)) {
            return $m->displayError($m->l('Invalid security token. Reload the page and try again.'));
        }

        // Creating or deleting Webservice keys is governed by the
        // AdminWebservice tab; being able to configure modules is not enough.
        $employee = $context->employee;
        if (!$employee || !$employee->can($enable ? 'add' : 'delete', 'AdminWebservice')) {
            return $m->displayError($m->l('Your employee profile is not allowed to manage Webservice keys.'));
        }

        // The key belongs to ONE shop: in the "all shops" or "group" context
        // it would be unclear which shop is being granted read access.
        if (\Shop::isFeatureActive() && \Shop::getContext() !== \Shop::CONTEXT_SHOP) {
            return $m->displayError($m->l('Select a single shop in the shop selector first.'));
        }

        try {
            $manager = new ReadKeyManager(
                new PrestaShopReadKeyEnvironment((int) $context->shop->id, (int) $context->shop->id_shop_group),
                new AutoRegister((string) \Configuration::get('TRUSTEED_API_BASE', null, null, null, 'https://api.trusteed.xyz'))
            );
        } catch (\Throwable $e) {
            return $m->displayError($m->l('The Trusteed API base URL is not valid.'));
        }

        $storeUrl = (string) \Tools::getShopDomainSsl(true);
        $installKey = (string) \Configuration::get('TRUSTEED_INSTALL_KEY');

        $result = $enable
            ? $manager->enable($storeUrl, $installKey)
            : $manager->revoke($storeUrl, $installKey);

        return $this->message($result);
    }

    private function message(ReadKeyResult $result): string
    {
        $m = $this->module;

        switch ($result->code()) {
            case 'enabled':
                return $m->displayConfirmation($m->l('Read access granted. Trusteed can now read your catalog with a read-only Webservice key.'));
            case 'already_enabled':
                return $m->displayConfirmation($m->l('Read access is already granted.'));
            case 'not_registered':
                return $m->displayError($m->l('Register this store with Trusteed first (Auto-register button).'));
            case 'key_creation_failed':
                return $m->displayError($m->l('PrestaShop could not create the Webservice key. Nothing was changed.'));
            case 'backend_rejected':
                return $m->displayError(
                    $this->rejectionText($result->detail())
                    . ' ' . $m->l('The key was deleted and the Webservice setting restored; nothing was left behind.')
                );
            case 'key_deletion_failed':
                return $m->displayError($m->l('PrestaShop could not delete the key. Delete the "Trusteed read-only" key in Advanced Parameters > Webservice.'))
                    . ($result->backendNotified() ? $m->displayConfirmation($m->l('Trusteed has stopped using it.')) : '');
        }

        // Revocations that did remove the local key.
        $out = $m->displayConfirmation($m->l('Read access revoked. The Webservice key was deleted from PrestaShop.'));
        if (!$result->backendNotified()) {
            $out .= $m->displayWarning($m->l('Trusteed could not be notified right now. The key no longer works, so it cannot be used; click Revoke again later to clean it up on Trusteed.'));
        }
        if ($result->webserviceLeftEnabled()) {
            $out .= $m->displayWarning($m->l(
                'The PrestaShop Webservice is still ON. This module turned it on when you granted read access and no Webservice keys remain. '
                . 'It was not turned off automatically in case other tools rely on it; you can turn it off in Advanced Parameters > Webservice.'
            ));
        }

        return $out;
    }

    private function rejectionText(string $reason): string
    {
        $m = $this->module;

        switch ($reason) {
            case 'INVALID_PRESTASHOP_KEY':
            case 'read_key_rejected':
                return $m->l('Your store refused the key when Trusteed tried it. If PHP runs as CGI/FastCGI, enable "CGI mode for PHP" in Advanced Parameters > Webservice and try again.');
            case 'PRESTASHOP_TIMEOUT':
            case 'PRESTASHOP_NETWORK_ERROR':
            case 'PRESTASHOP_API_ERROR':
            case 'store_unreachable':
                return $m->l('Trusteed could not reach your store\'s /api/ endpoint (maintenance mode, firewall or disabled URL rewriting).');
            case 'install_key_mismatch':
                return $m->l('Trusteed did not recognise this installation. Click Auto-register again and retry.');
            case 'store_inactive':
                return $m->l('This store is deactivated in Trusteed. Click Auto-register again and retry.');
            default:
                return $m->l('Trusteed did not accept the key') . ' (' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . ').';
        }
    }

    public function renderForm(): string
    {
        $m = $this->module;
        $context = \Context::getContext();
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $installKey = (string) \Configuration::get('TRUSTEED_INSTALL_KEY');
        $env = new PrestaShopReadKeyEnvironment((int) $context->shop->id, (int) $context->shop->id_shop_group);
        $keyId = $env->storedKeyId();
        $enabled = $keyId > 0 && $env->keyExists($keyId);

        $html = '<p>' . $e($m->l(
            'Trusteed compares what AI shopping agents are told about your store with your real catalog '
            . '(prices, stock, variants, taxes). For that it needs to read your catalog. '
            . 'Nothing is created until you click the button below.'
        )) . '</p>';

        if ($installKey === '') {
            $html .= '<p><strong>' . $e($m->l('Register this store with Trusteed first (Auto-register button above).')) . '</strong></p>';
        } elseif ($enabled) {
            $html .= '<p><strong>' . $e($m->l('Read access is ON')) . '</strong> — '
                . $e(sprintf($m->l('Webservice key #%d "%s", read-only, this shop only.'), $keyId, ReadOnlyWebserviceKey::DESCRIPTION))
                . '</p><p>' . $e($m->l('Revoke deletes the key from PrestaShop and asks Trusteed to forget it.')) . '</p>';
        } else {
            $html .= '<p>' . $e($m->l('If you accept, this module will:')) . '</p><ul>'
                . '<li>' . $e(sprintf($m->l('Create a PrestaShop Webservice key named "%s", linked to this shop only.'), ReadOnlyWebserviceKey::DESCRIPTION)) . '</li>'
                . '<li>' . $e($m->l('Give it read-only access (GET) to:')) . ' <code>' . $e(implode(', ', ReadOnlyWebserviceKey::RESOURCES)) . '</code>. '
                . $e($m->l('It cannot create, modify or delete anything, and has no access to customers, addresses, orders or carts.')) . '</li>';
            if (!$env->webserviceEnabled()) {
                $html .= '<li><strong>' . $e($m->l('Turn ON the PrestaShop Webservice for this shop (it is currently OFF).')) . '</strong></li>';
            }
            $html .= '<li>' . $e($m->l(
                'Send the key once to Trusteed over the module\'s registration channel. Trusteed checks that it works, '
                . 'stores it encrypted and uses it only to read. This module keeps only the key\'s ID, never the key itself.'
            )) . '</li></ul>'
                . '<p>' . $e($m->l('You can revoke it at any time from this page.')) . ' '
                . $e($m->l('Note: PrestaShop itself records the creation of every Webservice key, including its value, in Advanced Parameters > Logs.')) . '</p>';
        }

        $form = [
            'form' => [
                'legend' => ['title' => $m->l('Catalog read access for Trusteed (optional)')],
                'input'  => [
                    ['type' => 'html', 'name' => 'trusteed_read_key_consent', 'html_content' => $html],
                ],
            ],
        ];
        if ($installKey !== '') {
            $form['form']['submit'] = $enabled
                ? ['title' => $m->l('Revoke read access'), 'name' => self::SUBMIT_REVOKE, 'class' => 'btn btn-danger pull-right']
                : ['title' => $m->l('I agree — grant read-only access'), 'name' => self::SUBMIT_ENABLE];
        }

        $helper = new \HelperForm();
        $helper->module          = $m;
        $helper->name_controller = $m->name;
        $helper->token           = \Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex    = \AdminController::$currentIndex . '&configure=' . $m->name;
        $helper->default_form_language = (int) \Configuration::get('PS_LANG_DEFAULT');
        $helper->fields_value = ['trusteed_read_key_consent' => ''];

        return $helper->generateForm([$form]);
    }
}
