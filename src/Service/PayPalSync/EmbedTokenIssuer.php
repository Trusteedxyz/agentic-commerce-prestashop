<?php

declare(strict_types=1);

namespace Trusteed\Service\PayPalSync;

/**
 * Emite el token embed para la sincronización: mismo endpoint y cuerpo que
 * `AdminTrusteedController::ajaxProcessToken()` (`POST /v1/embed/ps/issue-token`
 * con `X-Embed-Ps-Secret`), pero acotado a UNA tienda (`allowed_shops`) y sin
 * reintentos (se llama desde el hook del back office, que no debe bloquearse).
 *
 * Nunca devuelve ni registra el secreto S2S ni el cuerpo de la respuesta.
 */
final class EmbedTokenIssuer
{
    /** @var callable(string,array<string,string>,string):array{status:int,body:string} */
    private $httpPost;

    /**
     * @param callable(string,array<string,string>,string):array{status:int,body:string} $httpPost
     */
    public function __construct(
        private readonly string $apiBase,
        private readonly string $s2sSecret,
        private readonly int $employeeId,
        callable $httpPost
    ) {
        $this->httpPost = $httpPost;
    }

    public function issue(int $idShop, string $merchantId): ?string
    {
        if (
            $this->employeeId <= 0 || $idShop <= 0 || $merchantId === ''
            || preg_match('/\A[0-9a-fA-F]{64}\z/', $this->s2sSecret) !== 1
        ) {
            return null;
        }

        $body = json_encode([
            'merchant_id' => $merchantId,
            'ps_employee_id' => (string) $this->employeeId,
            'capability_attestation' => 'admin_trusteed',
            'allowed_shops' => [$idShop],
        ]);
        if (!is_string($body)) {
            return null;
        }

        $res = call_user_func(
            $this->httpPost,
            rtrim($this->apiBase, '/') . '/v1/embed/ps/issue-token',
            [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Embed-Ps-Secret' => $this->s2sSecret,
            ],
            $body
        );
        if ((int) ($res['status'] ?? 0) !== 200) {
            return null;
        }
        $decoded = json_decode((string) ($res['body'] ?? ''), true);
        if (!is_array($decoded)) {
            return null;
        }
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            $decoded = $decoded['data'];
        }
        $token = $decoded['token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }
}
