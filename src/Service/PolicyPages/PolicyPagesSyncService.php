<?php

declare(strict_types=1);

namespace Trusteed\Service\PolicyPages;

use Trusteed\Service\ApiBaseAllowlist;
use Trusteed\Service\PayPalSync\ConfigStore;

/**
 * Comunica a Trusteed las páginas de condiciones y devoluciones del comercio
 * (`PUT /v1/embed/merchant/policy-pages`, token embed como el resto de /v1/embed/merchant/*).
 *
 * Idempotente por huella del cuerpo: el envío perezoso sólo llama si la lista
 * cambió. Sin páginas no se envía nada. Un fallo devuelve un código (nunca
 * lanza); tras un fallo el envío perezoso espera RETRY_COOLDOWN_SECONDS.
 */
final class PolicyPagesSyncService
{
    public const KEY_RETURNS_CMS = 'TRUSTEED_POLICY_RETURNS_CMS_ID';
    public const KEY_TERMS_CMS = 'PS_CONDITIONS_CMS_ID';
    public const KEY_HASH = 'TRUSTEED_POLICY_PAGES_HASH';
    public const KEY_RETRY_AT = 'TRUSTEED_POLICY_PAGES_RETRY_AT';
    public const KEY_MERCHANT = 'TRUSTEED_CEL_MERCHANT_ID';

    /** Claves propias que borra la desinstalación (PS_CONDITIONS_CMS_ID es de PrestaShop). */
    public const OWN_CONFIG_KEYS = [self::KEY_RETURNS_CMS, self::KEY_HASH, self::KEY_RETRY_AT];

    public const RETRY_COOLDOWN_SECONDS = 300;

    private const PATH = '/v1/embed/merchant/policy-pages';

    private readonly string $apiBase;

    /** @var callable(int,string):?string */
    private $tokenProvider;

    /** @var callable(string,array<string,string>,string):array{status:int,body:string} */
    private $httpPut;

    /** @var callable():int */
    private $clock;

    /**
     * @param callable(int,string):?string                                               $tokenProvider
     * @param callable(string,array<string,string>,string):array{status:int,body:string} $httpPut
     */
    public function __construct(
        private readonly ConfigStore $config,
        private readonly CmsPageSource $source,
        callable $tokenProvider,
        string $apiBase,
        callable $httpPut,
        ?callable $clock = null
    ) {
        $this->apiBase = rtrim($apiBase, '/');
        $this->tokenProvider = $tokenProvider;
        $this->httpPut = $httpPut;
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Envío tras guardar el ajuste: ignora la huella y el enfriamiento.
     *
     * @return array{ok:bool,code:string,reason?:string}
     */
    public function sync(?int $g, int $s): array
    {
        return $this->deliver($g, $s, true);
    }

    /**
     * Envío perezoso/periódico: sólo si cambió la lista y no hay enfriamiento.
     *
     * @return array{ok:bool,code:string,reason?:string}|null Null si no había nada que hacer.
     */
    public function syncIfChanged(?int $g, int $s): ?array
    {
        $last = (int) $this->config->get(self::KEY_RETRY_AT, $g, $s);
        if ($last > 0 && call_user_func($this->clock) - $last < self::RETRY_COOLDOWN_SECONDS) {
            return null;
        }
        $result = $this->deliver($g, $s, false);
        if ($result['code'] === 'unchanged' || $result['code'] === 'no_pages') {
            return null;
        }
        $this->config->set(self::KEY_RETRY_AT, $result['ok'] ? '' : (string) call_user_func($this->clock), $g, $s);

        return $result;
    }

    /** @return array{ok:bool,code:string,reason?:string} */
    private function deliver(?int $g, int $s, bool $force): array
    {
        $pages = PolicyPagesBuilder::build(
            $this->source,
            $s,
            (int) $this->config->get(self::KEY_TERMS_CMS, $g, $s),
            (int) $this->config->get(self::KEY_RETURNS_CMS, $g, $s)
        );
        $stored = $this->config->get(self::KEY_HASH, $g, $s);
        // Nada que enviar y nunca se envió nada: no hace falta llamar. Si ya se envió algo, se
        // manda la lista vacía para que el servidor borre lo importado (su huella cuenta como cuerpo distinto).
        if ($pages === [] && $stored === '') {
            return ['ok' => true, 'code' => 'no_pages'];
        }
        $body = json_encode(['policyPages' => $pages], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            return ['ok' => false, 'code' => 'encode_failed'];
        }
        $hash = hash('sha256', $body);
        if (!$force && hash_equals($stored, $hash)) {
            return ['ok' => true, 'code' => 'unchanged'];
        }
        if (!ApiBaseAllowlist::isAllowedForSecrets($this->apiBase)) {
            return ['ok' => false, 'code' => 'api_host_not_allowed'];
        }
        $merchantId = trim($this->config->get(self::KEY_MERCHANT, $g, $s));
        if ($merchantId === '' || $s <= 0) {
            return ['ok' => false, 'code' => 'shop_not_mapped'];
        }
        $token = call_user_func($this->tokenProvider, $s, $merchantId);
        if (!is_string($token) || $token === '') {
            return ['ok' => false, 'code' => 'token_unavailable'];
        }
        $res = call_user_func($this->httpPut, $this->apiBase . self::PATH, [
            'Authorization' => 'Bearer ' . $token,
            'X-Embed-Source' => 'ps-embed',
            'Content-Type' => 'application/json',
        ], $body);
        $status = (int) ($res['status'] ?? 0);
        if ($status === 200) {
            $ack = json_decode((string) ($res['body'] ?? ''), true);
            // `stored:false` = la plataforma no lo guardó: no se marca como entregado. Con la lista
            // vacía significa «no había nada que borrar»: eso sí es un envío cumplido.
            if ($pages !== [] && is_array($ack) && ($ack['stored'] ?? null) === false) {
                return ['ok' => false, 'code' => 'not_stored'];
            }
            $this->config->set(self::KEY_HASH, $hash, $g, $s);

            return ['ok' => true, 'code' => 'sent'];
        }
        $err = json_decode((string) ($res['body'] ?? ''), true);
        $reason = is_array($err) && isset($err['error']) && is_string($err['error'])
            && preg_match('/\A[A-Za-z0-9_.:-]{1,64}\z/', $err['error']) === 1 ? $err['error'] : 'http_' . $status;

        return ['ok' => false, 'code' => 'platform_rejected', 'reason' => $reason];
    }
}
