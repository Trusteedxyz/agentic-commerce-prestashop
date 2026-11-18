<?php

declare(strict_types=1);

namespace Trusteed\Service\PolicyPages;

/**
 * Fuente real (CMS de PrestaShop, idioma por defecto de la tienda). Sin lógica
 * de negocio ni test unitario: depende de `CMS`, `Link` y `Shop` reales.
 */
final class PrestaShopCmsPageSource implements CmsPageSource
{
    public function page(int $idCms, int $idShop): ?array
    {
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $cms = new \CMS($idCms, $idLang, $idShop);
        if (!\Validate::isLoadedObject($cms) || !(bool) $cms->active) {
            return null;
        }
        $url = (string) \Context::getContext()->link->getCMSLink($cms, null, true, $idLang, $idShop);

        return ['url' => $url, 'title' => (string) $cms->meta_title, 'modified' => $this->selectionChangedAt()];
    }

    /**
     * La tabla `cms` de PrestaShop no tiene fecha de modificación (`CMS::$date_upd` no existe).
     * La fecha que se informa es la del último cambio de la elección: la fila de `configuration`
     * que fija la página de condiciones o la de devoluciones (`configuration.date_upd`, hora local).
     */
    private function selectionChangedAt(): string
    {
        $names = ["'PS_CONDITIONS_CMS_ID'", "'" . pSQL(PolicyPagesSyncService::KEY_RETURNS_CMS) . "'"];
        $raw = \Db::getInstance()->getValue(
            'SELECT MAX(`date_upd`) FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` IN (' . implode(',', $names) . ')'
        );

        return is_string($raw) ? $raw : '';
    }

    public function shopHost(int $idShop): string
    {
        $shop = new \Shop($idShop);

        return strtolower((string) ($shop->domain_ssl ?: $shop->domain));
    }

    public function timezone(): string
    {
        $tz = (string) \Configuration::get('PS_TIMEZONE');

        return $tz !== '' ? $tz : date_default_timezone_get();
    }

    public function activePages(int $idShop): array
    {
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $out = [];
        foreach (\CMS::getCMSPages($idLang, null, true, $idShop) as $row) {
            $out[(int) $row['id_cms']] = (string) $row['meta_title'];
        }

        return $out;
    }
}
