<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

/**
 * Pide a Trusteed la instalación de enforcement de esta tienda
 * (`POST /api/v1/enforcement/installations/provision`) y devuelve el
 * Installation ID y el secreto HMAC que el módulo necesita para evaluar reglas.
 *
 * Auditoría agent-readiness 2026-09-26, A3: el auto-registro sólo entregaba el
 * Merchant ID y el secreto S2S, así que el back office mostraba «checkout not
 * protected» hasta que alguien pegara a mano las otras dos credenciales.
 *
 * Autenticación: el secreto S2S de embed que el alta acaba de guardar, en la
 * misma cabecera que usa `/issue-token`. **Pedirla con una instalación ya
 * existente rota el secreto**: quien llama debe consultar `needed()` antes.
 *
 * Nunca lanza y nunca registra ni devuelve el secreto fuera del resultado.
 */
final class InstallationProvisioner
{
    /** @var callable(string,array<string,string>,string):array{status:int,body:string} */
    private $httpPost;

    /**
     * @param callable(string,array<string,string>,string):array{status:int,body:string} $httpPost
     */
    public function __construct(private readonly string $apiBase, callable $httpPost)
    {
        $this->httpPost = $httpPost;
    }

    /** ¿Falta alguna de las dos credenciales? Con las dos presentes no se pide (rotaría el secreto). */
    public static function needed(string $installationId, string $hmacSecret): bool
    {
        return trim($installationId) === '' || trim($hmacSecret) === '';
    }

    /** @return array{installationId:string,hmacSecret:string}|null */
    public function provision(string $merchantId, string $s2sSecret): ?array
    {
        if (
            preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $merchantId) !== 1
            || preg_match('/\A[0-9a-fA-F]{64}\z/', $s2sSecret) !== 1
        ) {
            return null;
        }

        $body = json_encode(['merchantId' => $merchantId, 'platform' => 'PRESTASHOP']);
        if (!is_string($body)) {
            return null;
        }

        try {
            $res = call_user_func(
                $this->httpPost,
                rtrim($this->apiBase, '/') . '/api/v1/enforcement/installations/provision',
                [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-Embed-Ps-Secret' => $s2sSecret,
                ],
                $body
            );
        } catch (\Throwable) {
            return null;
        }

        if ((int) ($res['status'] ?? 0) !== 201) {
            return null;
        }
        $decoded = json_decode((string) ($res['body'] ?? ''), true);
        if (!is_array($decoded)) {
            return null;
        }
        $id = $decoded['installationId'] ?? null;
        $hmac = $decoded['hmacSecret'] ?? null;

        return is_string($id) && $id !== '' && is_string($hmac) && $hmac !== ''
            ? ['installationId' => $id, 'hmacSecret' => $hmac]
            : null;
    }
}
