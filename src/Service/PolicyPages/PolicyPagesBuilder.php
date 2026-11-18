<?php

declare(strict_types=1);

namespace Trusteed\Service\PolicyPages;

/**
 * Construye la lista `policyPages` de `PUT /v1/embed/merchant/policy-pages`.
 *
 * Sólo manda lo que el comercio decidió: `terms` = la página de condiciones que
 * PrestaShop ya tiene configurada, `returns` = la que el comercio eligió en el
 * panel. No hay `privacy` (PS no tiene un ajuste inequívoco) y nunca se adivina
 * por título. Cada URL debe ser https, del host de la tienda y estar activa.
 */
final class PolicyPagesBuilder
{
    private const MAX_URL = 2048;

    /**
     * @return list<array{type:string,url:string,title?:string,page_modified_at:string}>
     */
    public static function build(CmsPageSource $source, int $idShop, int $termsCmsId, int $returnsCmsId): array
    {
        $out = [];
        foreach (['terms' => $termsCmsId, 'returns' => $returnsCmsId] as $type => $idCms) {
            if ($idCms <= 0) {
                continue;
            }
            $page = $source->page($idCms, $idShop);
            if ($page === null) {
                continue;
            }
            $modified = self::toUtcIso($page['modified'], $source->timezone());
            if ($modified === null || !self::isShopUrl($page['url'], $source->shopHost($idShop))) {
                continue;
            }
            $entry = ['type' => $type, 'url' => $page['url']];
            $title = mb_substr(trim($page['title']), 0, 200);
            if ($title !== '') {
                $entry['title'] = $title;
            }
            $entry['page_modified_at'] = $modified;
            $out[] = $entry;
        }

        return $out;
    }

    /** `date_upd` de PS (hora local del servidor) → ISO8601 UTC con zona; null si no es una fecha real. */
    public static function toUtcIso(string $raw, string $timezone): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || str_starts_with($raw, '0000')) {
            return null;
        }
        try {
            $d = new \DateTimeImmutable($raw, new \DateTimeZone($timezone));
        } catch (\Throwable) {
            return null;
        }

        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /** https, sin credenciales, sin IP ni localhost, ≤2048 y con el host de la tienda. */
    public static function isShopUrl(string $url, string $shopHost): bool
    {
        if ($url === '' || strlen($url) > self::MAX_URL) {
            return false;
        }
        $p = parse_url($url);
        if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass'])) {
            return false;
        }
        $host = strtolower((string) ($p['host'] ?? ''));
        if ($host === '' || $host === 'localhost' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        return $host === strtolower($shopHost);
    }
}
