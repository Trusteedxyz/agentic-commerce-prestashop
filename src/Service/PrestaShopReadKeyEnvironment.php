<?php

declare(strict_types=1);

namespace Trusteed\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Real implementation of `ReadKeyEnvironment` on top of the core classes.
 *
 * Checked against the PrestaShop 1.7.8.11 and 8.1.7 code:
 *  - `WebserviceKey` is an ObjectModel over `webservice_account`, associated
 *    with shops (`Shop::$asso_tables['webservice_account'] = type shop`).
 *    `add()` inserts into `webservice_account_shop` the shops in
 *    `id_shop_list` or, if empty, ALL shops in the context;
 *    `WebserviceRequest::shopHasRight()` answers 403 for a key with no row for
 *    the requested shop. That is why `id_shop_list` is set to the registered
 *    shop: one shop in a multistore setup does not grant read access to the
 *    others.
 *  - `setPermissionForAccount($id, ['products' => ['GET' => 1], ...])` reads
 *    the KEYS of each resource as methods and discards unknown resources.
 *  - `delete()` also deletes `webservice_permission` and the `id_shop_list`
 *    associations; the main row only goes if no associations remain, so all
 *    shops are passed on revoke.
 *  - Note: the core `add()` and `delete()` write the key VALUE to the
 *    PrestaShop log ("Webservice key created: %s"). The same audience can
 *    already see it under Advanced Parameters > Webservice; the module does not
 *    log it anywhere else.
 */
final class PrestaShopReadKeyEnvironment implements ReadKeyEnvironment
{
    public const CONFIG_KEY_ID = 'TRUSTEED_WS_READ_KEY_ID';
    public const CONFIG_ENABLED_BY_MODULE = 'TRUSTEED_WS_ENABLED_BY_MODULE';

    public function __construct(
        private int $shopId,
        private int $shopGroupId,
    ) {
    }

    public function webserviceEnabled(): bool
    {
        return (bool) \Configuration::get('PS_WEBSERVICE', null, $this->shopGroupId, $this->shopId);
    }

    public function enableWebservice(): void
    {
        // Scope to the registered shop, not global: the consent is for this
        // shop. Without multistore, `updateValue` falls back to global scope.
        \Configuration::updateValue('PS_WEBSERVICE', 1, false, $this->shopGroupId, $this->shopId);
    }

    public function disableWebservice(): void
    {
        \Configuration::updateValue('PS_WEBSERVICE', 0, false, $this->shopGroupId, $this->shopId);
    }

    public function createKey(string $key, string $description, array $permissions): int
    {
        $account = new \WebserviceKey();
        $account->key = $key;
        $account->description = $description;
        $account->active = true;
        $account->id_shop_list = [$this->shopId];

        // `add()` returns false if the key already exists (collision: 36^32,
        // not retried).
        if (!$account->add()) {
            return 0;
        }

        $id = (int) $account->id;
        if (!\WebserviceKey::setPermissionForAccount($id, $permissions)) {
            // An account without the right permissions is not left half created.
            $this->deleteKey($id);

            return 0;
        }

        return $id;
    }

    public function deleteKey(int $id): bool
    {
        $account = new \WebserviceKey($id);
        if (!\Validate::isLoadedObject($account)) {
            return true;
        }
        // Never delete a key that is not ours: if the merchant deleted ours and
        // the id was reused, the stored id points at another account.
        if ((string) $account->description !== ReadOnlyWebserviceKey::DESCRIPTION) {
            return true;
        }
        $account->id_shop_list = \Shop::getCompleteListOfShopsID();

        return (bool) $account->delete();
    }

    public function keyExists(int $id): bool
    {
        $account = new \WebserviceKey($id);

        return \Validate::isLoadedObject($account)
            && (string) $account->description === ReadOnlyWebserviceKey::DESCRIPTION;
    }

    public function countKeys(): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'webservice_account`'
        );
    }

    public function storedKeyId(): int
    {
        return (int) \Configuration::get(self::CONFIG_KEY_ID, null, $this->shopGroupId, $this->shopId);
    }

    public function storeKeyId(int $id): void
    {
        \Configuration::updateValue(self::CONFIG_KEY_ID, $id, false, $this->shopGroupId, $this->shopId);
    }

    public function enabledByModule(): bool
    {
        return (string) \Configuration::get(self::CONFIG_ENABLED_BY_MODULE, null, $this->shopGroupId, $this->shopId) === '1';
    }

    public function setEnabledByModule(bool $value): void
    {
        \Configuration::updateValue(self::CONFIG_ENABLED_BY_MODULE, $value ? '1' : '0', false, $this->shopGroupId, $this->shopId);
    }
}
