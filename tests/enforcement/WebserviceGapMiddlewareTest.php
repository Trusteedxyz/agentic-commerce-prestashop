<?php

declare(strict_types=1);

namespace Trusteed\Tests\Enforcement;

use PHPUnit\Framework\TestCase;
use Trusteed\Enforcement\WebserviceGapMiddleware;

require_once __DIR__ . '/../../src/Enforcement/WebserviceGapMiddleware.php';

/**
 * `POST /api/orders` of the PrestaShop Webservice does not go through the
 * PaymentModule override. The middleware only WARNS (real blocking would be a
 * separate decision) and its only signal for "this already went through the
 * proxy" was the self-declared `X-Trusteed-Proxy: true` header: anyone could
 * write it to silence the warning.
 */
final class WebserviceGapMiddlewareTest extends TestCase
{
    private const SECRET = 'installation-hmac-secret';
    private const NOW = 1_800_000_000;

    private static function signed(string $method, string $uri, int $ts = self::NOW, string $secret = self::SECRET): array
    {
        $mac = hash_hmac('sha256', $ts . '.' . strtoupper($method) . '.' . $uri, $secret);

        return [
            'X-Trusteed-Proxy' => 'true',
            'X-Trusteed-Proxy-Signature' => "t={$ts},s={$mac}",
        ];
    }

    public function testReadsAreNotWarnedAbout(): void
    {
        foreach (['GET', 'HEAD', 'OPTIONS', 'get'] as $method) {
            self::assertSame(
                WebserviceGapMiddleware::VERDICT_READ_ONLY,
                WebserviceGapMiddleware::classify($method, '/api/orders', [], self::SECRET, self::NOW),
                $method
            );
        }
    }

    public function testAWriteWithoutTheProxyHeaderIsAGap(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            self::assertSame(
                WebserviceGapMiddleware::VERDICT_NO_PROXY,
                WebserviceGapMiddleware::classify($method, '/api/orders', [], self::SECRET, self::NOW),
                $method
            );
        }
    }

    public function testTheSelfDeclaredHeaderAloneDoesNotSilenceTheWarning(): void
    {
        self::assertSame(
            WebserviceGapMiddleware::VERDICT_UNVERIFIED,
            WebserviceGapMiddleware::classify('POST', '/api/orders', ['X-Trusteed-Proxy' => 'true'], self::SECRET, self::NOW)
        );
    }

    public function testASignedProxyHeaderIsAccepted(): void
    {
        self::assertSame(
            WebserviceGapMiddleware::VERDICT_PROXY_VERIFIED,
            WebserviceGapMiddleware::classify('POST', '/api/orders', self::signed('POST', '/api/orders'), self::SECRET, self::NOW)
        );
    }

    public function testHeaderNamesAreCaseInsensitive(): void
    {
        $h = array_change_key_case(self::signed('POST', '/api/orders'), CASE_LOWER);

        self::assertSame(
            WebserviceGapMiddleware::VERDICT_PROXY_VERIFIED,
            WebserviceGapMiddleware::classify('POST', '/api/orders', $h, self::SECRET, self::NOW)
        );
    }

    public function testASignatureForAnotherPathMethodSecretOrTimeIsRejected(): void
    {
        $cases = [
            'otra ruta'   => self::signed('POST', '/api/products'),
            'otro método' => self::signed('DELETE', '/api/orders'),
            'otro secreto' => self::signed('POST', '/api/orders', self::NOW, 'someone-elses-secret'),
            'caducada'    => self::signed('POST', '/api/orders', self::NOW - 301),
            'del futuro'  => self::signed('POST', '/api/orders', self::NOW + 301),
        ];
        foreach ($cases as $label => $headers) {
            self::assertSame(
                WebserviceGapMiddleware::VERDICT_UNVERIFIED,
                WebserviceGapMiddleware::classify('POST', '/api/orders', $headers, self::SECRET, self::NOW),
                $label
            );
        }
    }

    public function testMalformedSignaturesAreRejectedNotFatal(): void
    {
        foreach (['', 'garbage', 't=abc,s=zz', 't=' . self::NOW, 's=' . str_repeat('a', 64)] as $sig) {
            self::assertSame(
                WebserviceGapMiddleware::VERDICT_UNVERIFIED,
                WebserviceGapMiddleware::classify(
                    'POST',
                    '/api/orders',
                    ['X-Trusteed-Proxy' => 'true', 'X-Trusteed-Proxy-Signature' => $sig],
                    self::SECRET,
                    self::NOW
                ),
                $sig
            );
        }
    }

    public function testWithoutASharedSecretNothingCanBeVerified(): void
    {
        self::assertSame(
            WebserviceGapMiddleware::VERDICT_UNVERIFIED,
            WebserviceGapMiddleware::classify('POST', '/api/orders', self::signed('POST', '/api/orders', self::NOW, ''), '', self::NOW)
        );
    }

    public function testTheQueryStringDoesNotBreakTheSignedPath(): void
    {
        self::assertSame(
            WebserviceGapMiddleware::VERDICT_PROXY_VERIFIED,
            WebserviceGapMiddleware::classify('POST', '/api/orders?ws_key=abc&output_format=JSON', self::signed('POST', '/api/orders'), self::SECRET, self::NOW),
            'la firma cubre el path sin query: la ws_key no debe entrar en el material firmado ni en el log'
        );
    }

    public function testTheLogLineCarriesTheVerdictAndNoCredentials(): void
    {
        $line = WebserviceGapMiddleware::logLine('POST', WebserviceGapMiddleware::VERDICT_UNVERIFIED);

        self::assertStringStartsWith('[trusteed.webservice_gap] ', $line);
        self::assertStringContainsString('"verdict":"proxy_unverified"', $line);
        self::assertStringContainsString('"method":"POST"', $line);
        self::assertStringContainsString('"blocked":false', $line);
    }
}
