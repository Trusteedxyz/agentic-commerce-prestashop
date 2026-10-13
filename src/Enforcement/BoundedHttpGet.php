<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

/**
 * cURL GET that never reads more than a byte cap.
 *
 * A legitimate JWS or JWKS fits well under the cap. Anything larger is not
 * read further: the body is neither accumulated nor verified. Two mechanisms
 * enforce it: `CURLOPT_MAXFILESIZE` (aborts when the server announces the size,
 * and cURL also applies it while reading) and `CURLOPT_WRITEFUNCTION` (aborts
 * when no size is announced). Past the cap the result is
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
