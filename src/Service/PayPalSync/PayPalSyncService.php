<?php

declare(strict_types=1);

namespace Trusteed\Service\PayPalSync;

use Trusteed\Service\ApiBaseAllowlist;

/**
 * Sincroniza la cuenta PayPal del módulo `paypal` (202 ecommerce) con la
 * plataforma, por tienda. Misma semántica que `Trusteed_PayPal_Sync` y sus
 * rutas en el plugin de WooCommerce (plan 2026-10-12, F3/F4.1):
 *
 *  - opt-in explícito por tienda (`TRUSTEED_PAYPAL_SYNC_ENABLED`);
 *  - entrega `POST …/paypal/connect` con `{flow:"apiKey", accountId, apiKey,
 *    mode, force:true}`, Bearer del token embed y `X-Embed-Source: ps-embed`;
 *  - idempotencia con la huella sha256 de id|secreto|modo
 *    (`TRUSTEED_PAYPAL_SYNC_HASH`);
 *  - D5: si el comercio vacía PayPal y ya se había entregado, `…/disconnect`.
 *
 * Nunca registra ni devuelve el secreto; los resultados llevan códigos, no
 * cuerpos de respuesta ni mensajes de excepción.
 */
final class PayPalSyncService
{
    public const KEY_ENABLED = 'TRUSTEED_PAYPAL_SYNC_ENABLED';
    public const KEY_MERCHANT = 'TRUSTEED_CEL_MERCHANT_ID';
    public const KEY_HASH = 'TRUSTEED_PAYPAL_SYNC_HASH';
    /** Instante (epoch) del último fallo del re-sync perezoso. */
    public const KEY_RETRY_AT = 'TRUSTEED_PAYPAL_SYNC_RETRY_AT';

    /** Claves propias que borra la desinstalación (las de PayPal son ajenas). */
    public const OWN_CONFIG_KEYS = [self::KEY_ENABLED, self::KEY_HASH, self::KEY_RETRY_AT];

    /**
     * El re-sync perezoso corre en cada página del back office: tras un fallo
     * espera esto antes de volver a llamar a la red. El envío manual no espera.
     */
    public const RETRY_COOLDOWN_SECONDS = 300;

    private const CONNECT_PATH = '/v1/embed/merchant/payment-methods/paypal/connect';
    private const DISCONNECT_PATH = '/v1/embed/merchant/payment-methods/paypal/disconnect';

    private readonly string $apiBase;

    /** @var callable(int,string):?string */
    private $tokenProvider;

    /** @var callable(string,array<string,string>,string):array{status:int,body:string} */
    private $httpPost;

    /** @var callable():int */
    private $clock;

    /** @var (callable():array<int,?int>)|null */
    private $shopMap;

    /**
     * @param callable(int,string):?string                                                 $tokenProvider (idShop, merchantId) → token embed o null
     * @param callable(string,array<string,string>,string):array{status:int,body:string}   $httpPost
     * @param (callable():int)|null                                                        $clock
     * @param (callable():array<int,?int>)|null                                            $shopMap       () → [idShop => idGroup] de las tiendas activas.
     *        Sin él se asume multitienda (lo más estricto): el comercio debe estar mapeado en la propia tienda.
     */
    public function __construct(
        private readonly ConfigStore $config,
        private readonly CredentialReader $reader,
        callable $tokenProvider,
        string $apiBase,
        callable $httpPost,
        ?callable $clock = null,
        ?callable $shopMap = null
    ) {
        $this->shopMap = $shopMap;
        $this->apiBase = rtrim($apiBase, '/');
        $this->tokenProvider = $tokenProvider;
        $this->httpPost = $httpPost;
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Estado para el panel. Nunca el secreto ni el client id entero.
     *
     * @return array{available:bool,enabled:bool,mode:?string,last4:?string}
     */
    public function describe(?int $g, ?int $s): array
    {
        $c = $this->reader->read($g, $s);
        $ok = $c->state() === PayPalCredentials::STATE_OK;

        return [
            'available' => $ok,
            'enabled' => $this->isEnabled($g, $s),
            'mode' => $ok ? $c->mode() : null,
            'last4' => $ok ? $c->describe()['last4'] : null,
        ];
    }

    /**
     * Envío manual (activa el opt-in si sale bien).
     *
     * @return array{ok:bool,code:string,mode?:string,reason?:string}
     */
    public function sync(?int $g, ?int $s): array
    {
        $result = $this->deliver($g, $s);
        if ($result['ok']) {
            $this->config->set(self::KEY_ENABLED, '1', $g, $s);
            $this->config->set(self::KEY_RETRY_AT, '', $g, $s);
        }

        return $result;
    }

    /** Desactiva la sincronización de la tienda (no desconecta en la plataforma). */
    public function disable(?int $g, ?int $s): void
    {
        $this->config->set(self::KEY_ENABLED, '', $g, $s);
        $this->config->set(self::KEY_HASH, '', $g, $s);
        $this->config->set(self::KEY_RETRY_AT, '', $g, $s);
    }

    /**
     * Disparo perezoso (hook del back office): actúa sólo con opt-in y si la
     * huella cambió, o si PayPal se vació tras una entrega (D5).
     *
     * @return array{ok:bool,code:string,mode?:string,reason?:string}|null Null si no había nada que hacer.
     */
    public function resyncIfChanged(?int $g, ?int $s): ?array
    {
        if (!$this->isEnabled($g, $s)) {
            return null;
        }

        $creds = $this->reader->read($g, $s);
        $stored = $this->config->get(self::KEY_HASH, $g, $s);

        if ($creds->state() === PayPalCredentials::STATE_ABSENT) {
            // D5: sólo si había una entrega previa que retirar.
            return $stored === '' ? null : $this->guarded($g, $s, fn (): array => $this->retract($g, $s));
        }
        if ($creds->state() !== PayPalCredentials::STATE_OK) {
            // Ilegible (p. ej. cambió la clave del CMS): no es «desconectó».
            return null;
        }
        if ($stored !== '' && hash_equals($stored, $creds->fingerprint())) {
            return null;
        }

        return $this->guarded($g, $s, fn (): array => $this->deliver($g, $s));
    }

    /**
     * @param callable():array{ok:bool,code:string,mode?:string,reason?:string} $action
     * @return array{ok:bool,code:string,mode?:string,reason?:string}|null
     */
    private function guarded(?int $g, ?int $s, callable $action): ?array
    {
        $now = call_user_func($this->clock);
        $last = (int) $this->config->get(self::KEY_RETRY_AT, $g, $s);
        if ($last > 0 && $now - $last < self::RETRY_COOLDOWN_SECONDS) {
            return null;
        }
        $result = $action();
        $this->config->set(self::KEY_RETRY_AT, $result['ok'] ? '' : (string) $now, $g, $s);

        return $result;
    }

    /** @return array{ok:bool,code:string,mode?:string,reason?:string} */
    private function deliver(?int $g, ?int $s): array
    {
        $creds = $this->reader->read($g, $s);
        if ($creds->state() !== PayPalCredentials::STATE_OK) {
            return ['ok' => false, 'code' => 'no_paypal_credentials'];
        }
        $gate = $this->preflight($g, $s, $token);
        if ($gate !== null) {
            return $gate;
        }

        $body = json_encode([
            'flow' => 'apiKey',
            'accountId' => $creds->clientId(),
            'apiKey' => $creds->secret(),
            'mode' => $creds->mode(),
            // Reemplaza la conexión existente: el comercio pidió sincronizar.
            'force' => true,
        ]);
        $mode = $creds->mode();
        $fingerprint = $creds->fingerprint();
        unset($creds);
        if (!is_string($body)) {
            return ['ok' => false, 'code' => 'encode_failed'];
        }

        $res = call_user_func($this->httpPost, $this->apiBase . self::CONNECT_PATH, $this->headers($token), $body);
        unset($body);
        if ((int) ($res['status'] ?? 0) === 200) {
            $this->config->set(self::KEY_HASH, $fingerprint, $g, $s);

            return ['ok' => true, 'code' => 'connected', 'mode' => $mode];
        }

        return $this->rejected($res);
    }

    /** @return array{ok:bool,code:string,reason?:string} */
    private function retract(?int $g, ?int $s): array
    {
        $gate = $this->preflight($g, $s, $token);
        if ($gate !== null) {
            return $gate;
        }
        $res = call_user_func($this->httpPost, $this->apiBase . self::DISCONNECT_PATH, $this->headers($token), '{}');
        if ((int) ($res['status'] ?? 0) === 200) {
            // Reconectar las mismas credenciales volverá a entregarlas.
            $this->config->set(self::KEY_HASH, '', $g, $s);

            return ['ok' => true, 'code' => 'disconnected'];
        }

        return $this->rejected($res);
    }

    /**
     * Host permitido, tienda mapeada a un comercio y token disponible.
     *
     * @param-out string $token
     * @return array{ok:false,code:string}|null Null si se puede enviar.
     */
    private function preflight(?int $g, ?int $s, ?string &$token): ?array
    {
        // Viajan credenciales PayPal y el secreto S2S: sólo HTTPS a hosts de Trusteed.
        if (!ApiBaseAllowlist::isAllowedForSecrets($this->apiBase)) {
            return ['ok' => false, 'code' => 'api_host_not_allowed'];
        }
        $merchantId = $this->mappedMerchant($g, $s);
        if ($merchantId === '' || $s === null || $s <= 0) {
            return ['ok' => false, 'code' => 'shop_not_mapped'];
        }
        if ($this->sharesMerchantWithDifferentAccount($merchantId, $g, $s)) {
            return ['ok' => false, 'code' => 'shared_merchant_conflict'];
        }
        $issued = call_user_func($this->tokenProvider, $s, $merchantId);
        if (!is_string($issued) || $issued === '') {
            return ['ok' => false, 'code' => 'token_unavailable'];
        }
        $token = $issued;

        return null;
    }

    /**
     * Comercio de ESTA tienda. En multitienda sólo vale el valor guardado en la
     * propia tienda (sin caer al grupo ni al global, que son de «todas»); en
     * monotienda el global es el de la única tienda.
     */
    private function mappedMerchant(?int $g, ?int $s): string
    {
        if ($s === null || $s <= 0) {
            return '';
        }
        $own = $this->config->getOwn(self::KEY_MERCHANT, $g, $s);
        if ($own !== null && trim($own) !== '') {
            return trim($own);
        }
        $shops = $this->shopMap === null ? [] : call_user_func($this->shopMap);
        if (count($shops) === 1 && array_key_exists($s, $shops)) {
            return trim($this->config->get(self::KEY_MERCHANT, $g, $s));
        }

        return '';
    }

    /**
     * ¿Otra tienda con opt-in apunta al mismo comercio con una huella distinta?
     * Un comercio de la plataforma sólo tiene UNA cuenta PayPal: enviar (force)
     * o desconectar desde una pisaría la de la otra.
     */
    private function sharesMerchantWithDifferentAccount(string $merchantId, ?int $g, int $s): bool
    {
        $shops = $this->shopMap === null ? [] : call_user_func($this->shopMap);
        $mine = $this->accountFingerprint($g, $s);
        foreach ($shops as $otherShop => $otherGroup) {
            $otherShop = (int) $otherShop;
            if ($otherShop === $s || $otherShop <= 0) {
                continue;
            }
            $og = $otherGroup === null ? null : (int) $otherGroup;
            if (!$this->isEnabled($og, $otherShop) || $this->mappedMerchant($og, $otherShop) !== $merchantId) {
                continue;
            }
            if (!hash_equals($mine, $this->accountFingerprint($og, $otherShop))) {
                return true;
            }
        }

        return false;
    }

    /** Huella de la cuenta de la tienda; cadena vacía si no hay cuenta utilizable. */
    private function accountFingerprint(?int $g, ?int $s): string
    {
        $c = $this->reader->read($g, $s);

        return $c->state() === PayPalCredentials::STATE_OK ? $c->fingerprint() : '';
    }

    /** @return array<string,string> */
    private function headers(string $token): array
    {
        return [
            'Authorization' => 'Bearer ' . $token,
            'X-Embed-Source' => 'ps-embed',
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Sólo el código `error` de la plataforma, nunca el cuerpo entero.
     *
     * @param array{status?:int,body?:string} $res
     * @return array{ok:false,code:string,reason:string}
     */
    private function rejected(array $res): array
    {
        $body = json_decode((string) ($res['body'] ?? ''), true);
        $reason = is_array($body) && isset($body['error']) && is_string($body['error']) && preg_match('/\A[A-Za-z0-9_.:-]{1,64}\z/', $body['error']) === 1
            ? $body['error']
            : 'http_' . (int) ($res['status'] ?? 0);

        return ['ok' => false, 'code' => 'platform_rejected', 'reason' => $reason];
    }

    private function isEnabled(?int $g, ?int $s): bool
    {
        return $this->config->get(self::KEY_ENABLED, $g, $s) === '1';
    }
}
