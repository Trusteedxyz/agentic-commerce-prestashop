<?php

declare(strict_types=1);

namespace Trusteed\Service\PolicyPages;

use Trusteed\Service\PayPalSync\CurlHttpPost;
use Trusteed\Service\PayPalSync\EmbedTokenIssuer;
use Trusteed\Service\PayPalSync\PayPalSyncFactory;
use Trusteed\Service\PayPalSync\PrestaShopConfigStore;
use Trusteed\Service\PayPalSync\ResyncScope;

/** Cableado con PrestaShop real; misma procedencia de token y API base que PayPalSyncFactory. Nunca lanza. */
final class PolicyPagesSyncFactory
{
    public static function build(): ?PolicyPagesSyncService
    {
        $employeeId = (int) (\Context::getContext()->employee->id ?? 0);
        if ($employeeId <= 0) {
            return null;
        }
        $apiBase = (string) \Configuration::get('TRUSTEED_API_BASE', null, null, null, 'https://api.trusteed.xyz');
        $secret = (string) \Configuration::get('TRUSTEED_EMBED_S2S_SECRET');
        if ($secret === '') {
            $secret = (string) \Configuration::get('TRUSTEED_BOOTSTRAP_TOKEN');
        }
        $issuer = new EmbedTokenIssuer($apiBase, $secret, $employeeId, [CurlHttpPost::class, 'post']);

        return new PolicyPagesSyncService(
            new PrestaShopConfigStore(),
            new PrestaShopCmsPageSource(),
            static fn (int $idShop, string $merchantId): ?string => $issuer->issue($idShop, $merchantId),
            $apiBase,
            [CurlHttpPost::class, 'put']
        );
    }

    /**
     * Tras guardar el ajuste.
     *
     * @return array{ok:bool,code:string,reason?:string}|null
     */
    public static function syncNow(int $idShop): ?array
    {
        try {
            $service = self::build();

            return $service === null ? null : $service->sync(PayPalSyncFactory::shopGroupOf($idShop), $idShop);
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('Trusteed policy pages sync failed: ' . get_class($e), 2);

            return null;
        }
    }

    /** Gancho perezoso de `displayBackOfficeTop` (mismo alcance que PayPalSync). Nunca lanza. */
    public static function syncOnBackOfficePage(): void
    {
        try {
            $mayEdit = PayPalSyncFactory::employeeMayEdit();
            $service = $mayEdit ? self::build() : null;
            if ($service === null) {
                return;
            }
            $active = array_keys(PayPalSyncFactory::activeShopMap());
            foreach (ResyncScope::shops($mayEdit, $active, PayPalSyncFactory::employeeShopIds()) as $idShop) {
                $service->syncIfChanged(PayPalSyncFactory::shopGroupOf($idShop), $idShop);
            }
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('Trusteed policy pages sync failed: ' . get_class($e), 2);
        }
    }
}
