<?php

declare(strict_types=1);

namespace Trusteed\Service\PayPalSync;

/**
 * POST JSON por cURL: TLS verificado, sin seguir redirecciones y con tiempos
 * cortos (se usa desde el hook del back office). Devuelve estado 0 ante
 * cualquier fallo de transporte, sin mensaje.
 */
final class CurlHttpPost
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string}
     */
    public static function post(string $url, array $headers, string $body): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => ''];
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => ''];
        }
        curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $body,
            \CURLOPT_HTTPHEADER => $lines,
            \CURLOPT_TIMEOUT => 10,
            \CURLOPT_CONNECTTIMEOUT => 5,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        curl_close($ch);

        return is_string($response) ? ['status' => $status, 'body' => $response] : ['status' => 0, 'body' => ''];
    }
}
