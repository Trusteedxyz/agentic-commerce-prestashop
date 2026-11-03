<?php

declare(strict_types=1);

namespace Trusteed\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Implementación real de `ReadKeyEnvironment` sobre las clases del core.
 *
 * Verificado contra el código de PrestaShop 1.7.8.11 y 8.1.7:
 *  - `WebserviceKey` es un ObjectModel sobre `webservice_account` asociado a
 *    tienda (`Shop::$asso_tables['webservice_account'] = type shop`). `add()`
 *    inserta en `webservice_account_shop` las tiendas de `id_shop_list` o, si
 *    está vacío, TODAS las del contexto; `WebserviceRequest::shopHasRight()`
 *    rechaza con 403 una clave sin fila para la tienda pedida. Por eso se fija
 *    `id_shop_list` a la tienda registrada: una tienda de un multitienda no
 *    concede lectura sobre las demás.
 *  - `setPermissionForAccount($id, ['products' => ['GET' => 1], …])` lee las
 *    CLAVES de cada recurso como métodos y descarta recursos desconocidos.
 *  - `delete()` borra también `webservice_permission` y las asociaciones de
 *    `id_shop_list`; la fila principal sólo si no quedan asociaciones, así que
 *    al revocar se pasan todas las tiendas.
 *  - Ojo: `add()` y `delete()` del core escriben el VALOR de la clave en el
 *    registro de PrestaShop («Webservice key created: %s»). Es el mismo público
 *    que ya la ve en Parámetros avanzados > Webservice; el módulo no la
 *    registra en ningún otro sitio.
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
        // Ámbito de la tienda registrada, no global: el consentimiento es de
        // esta tienda. Sin multitienda, `updateValue` cae al ámbito global.
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

        // `add()` devuelve false si la clave ya existe (colisión: 36^32, no
        // se reintenta).
        if (!$account->add()) {
            return 0;
        }

        $id = (int) $account->id;
        if (!\WebserviceKey::setPermissionForAccount($id, $permissions)) {
            // Una cuenta sin permisos correctos no se deja a medias.
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
        // Nunca borrar una clave que no es nuestra: si el comerciante borró la
        // suya y el id se reutilizó, el id guardado apunta a otra cuenta.
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
