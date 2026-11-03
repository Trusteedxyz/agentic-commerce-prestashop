<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

/**
 * Plan 04 Task 29c (L6b) — GET con cURL que no lee más de un tope de bytes.
 *
 * Un JWS o un JWKS legítimos caben con holgura en el tope; lo que lo supera no
 * se sigue leyendo: ni se acumula el cuerpo entero ni se verifica. Dos
 * mecanismos: `CURLOPT_MAXFILESIZE` (corta cuando el servidor anuncia el
 * tamaño, y cURL también lo aplica mientras lee) y `CURLOPT_WRITEFUNCTION`
 * (corta cuando no lo anuncia). Superado el tope el resultado es
 * `code 0, body '', error 'response_too_large'`.
 */
final class BoundedHttpGet
{
    /**
     * @param array<string,string> $headers
     * @return array{code:int,body:string,error:string}
     */
    public static function fetch(string $url, array $headers, int $timeout, int $maxBytes): array
    {
        $ch = curl_init();
        $body     = '';
        $received = 0;
        $tooLarge = false;
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_MAXFILESIZE    => $maxBytes,
            CURLOPT_WRITEFUNCTION  => static function ($handle, string $chunk) use (&$body, &$received, &$tooLarge, $maxBytes): int {
                $received += strlen($chunk);
                if ($received > $maxBytes) {
                    $tooLarge = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => array_map(
                static fn($k, $v) => "{$k}: {$v}",
                array_keys($headers),
                array_values($headers)
            ),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $ok    = curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err   = (string) curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($tooLarge || $errno === CURLE_FILESIZE_EXCEEDED) {
            return ['code' => 0, 'body' => '', 'error' => 'response_too_large'];
        }

        return ['code' => $code, 'body' => $ok === true ? $body : '', 'error' => $err];
    }
}
