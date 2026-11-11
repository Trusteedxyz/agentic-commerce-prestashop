<?php

declare(strict_types=1);

namespace Trusteed\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Trusteed\Enforcement\InstallationSignature;

/**
 * Firma `X-Trusteed-Signature` de una instalación: `t=<ts>,s=<hmac-sha256 de "<ts>.<cuerpo>">`
 * (apps/api/src/services/enforcement/installation-hmac.service.ts). Los vectores se calcularon con
 * `crypto.createHmac` de Node, o sea con el algoritmo del servidor y no con el de este módulo.
 */
final class InstallationSignatureTest extends TestCase
{
    private const SECRET = 'not-a-real-hmac-secret';

    public function testGetSinCuerpo(): void
    {
        $this->assertSame(
            't=1700000000,s=4e8d1bae0e8b0be8694cddbbb0de40a5f1361696e6d703015499005c6cef460b',
            InstallationSignature::header(self::SECRET, '', 1700000000)
        );
    }

    public function testConCuerpo(): void
    {
        $this->assertSame(
            't=1700000000,s=deef8b2a629b1ec03b92dc731cde011a2a1532ac0efd213509ba10815bebf811',
            InstallationSignature::header(self::SECRET, '{"a":1}', 1700000000)
        );
    }

    public function testUsaElCampoSYNoV1(): void
    {
        $this->assertMatchesRegularExpression('/^t=\d+,s=[0-9a-f]{64}$/', InstallationSignature::header(self::SECRET, ''));
    }
}
