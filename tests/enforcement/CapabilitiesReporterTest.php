<?php

declare(strict_types=1);

namespace Trusteed\Tests\Enforcement;

use PHPUnit\Framework\TestCase;
use Trusteed\Enforcement\CapabilitiesReporter;

require_once __DIR__ . '/../../src/Enforcement/CapabilitiesReporter.php';

/**
 * 8b6de3808 (barrido 2026-10-10). El servidor da por muerta una tienda cuyo
 * `lastSeenAt` tiene 30 días, y esa marca sólo la renueva el reporte de
 * capacidades o el tráfico de /rules/*. PrestaShop reportaba UNA vez por versión
 * del módulo: una tienda tranquila quedaba «muerta» a los 30 días aunque siguiera
 * funcionando. Ahora además se renueva cada 7 días (el servidor deduplica el
 * contenido con `unchanged: true` y sólo toca el latido).
 */
final class CapabilitiesReporterTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const DAY = 86400;

    public function testAVersionNeverReportedIsDue(): void
    {
        self::assertTrue(CapabilitiesReporter::isDue('2.4.0', '', 0, 0, self::NOW));
    }

    public function testAChangedVersionIsDueImmediately(): void
    {
        self::assertTrue(CapabilitiesReporter::isDue('2.4.1', '2.4.0', self::NOW - 60, 0, self::NOW));
    }

    public function testTheSameVersionReportedRecentlyIsNotDue(): void
    {
        self::assertFalse(CapabilitiesReporter::isDue('2.4.0', '2.4.0', self::NOW - 6 * self::DAY, 0, self::NOW));
    }

    public function testTheSameVersionIsRenewedAfterSevenDays(): void
    {
        self::assertTrue(CapabilitiesReporter::isDue('2.4.0', '2.4.0', self::NOW - 7 * self::DAY, 0, self::NOW));
        self::assertTrue(CapabilitiesReporter::isDue('2.4.0', '2.4.0', self::NOW - 40 * self::DAY, 0, self::NOW));
    }

    public function testAnOldReportWithoutTimestampIsRenewed(): void
    {
        // Instalaciones anteriores a este cambio: versión recordada, sin fecha.
        self::assertTrue(CapabilitiesReporter::isDue('2.4.0', '2.4.0', 0, 0, self::NOW));
    }

    public function testAFailedAttemptBacksOffForAnHour(): void
    {
        // El servidor no responde: sin esto cada carga del back-office o guardado
        // de carrito pagaría hasta 3 s de timeout.
        self::assertFalse(CapabilitiesReporter::isDue('2.4.1', '2.4.0', 0, self::NOW - 600, self::NOW));
        self::assertTrue(CapabilitiesReporter::isDue('2.4.1', '2.4.0', 0, self::NOW - 3601, self::NOW));
    }

    public function testTheModuleRenewsTheReportFromTheStorefrontToo(): void
    {
        // Una tienda tranquila puede no abrir el back-office en semanas: el
        // disparador también cuelga de un hook de front (se ejecuta tras
        // enviar la respuesta, nunca en la ruta del comprador).
        $src = (string) file_get_contents(__DIR__ . '/../../trusteed.php');

        self::assertMatchesRegularExpression(
            '/function hookActionCartSave\(.*?CapabilitiesReporter::maybeReportAfterResponse\(/s',
            $src
        );
    }
}
