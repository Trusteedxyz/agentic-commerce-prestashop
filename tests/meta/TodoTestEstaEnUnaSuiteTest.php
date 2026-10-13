<?php

declare(strict_types=1);

/**
 * Gate: every `*Test.php` in this module is in some phpunit.xml suite, or is
 * listed here as an exception with its reason.
 *
 * WHY: `tests/hooks` existed but was not in phpunit.xml, so its tests ran
 * nowhere, and two of them had been erroring for a while unnoticed. A test that
 * does not run is not a test, and whatever leaves it out gives no warning:
 * `phpunit` does not complain about a file nobody asked it to run.
 *
 * HOW phpunit.xml IS READ: the `<directory>` entries (with the default
 * `Test.php` suffix) and the `<file>` entries of every `<testsuite>`. A
 * `*Test.php` that falls under none of them and is not in EXCEPCIONES fails
 * this test.
 *
 * Anti-rot: an exception whose file is already in a suite, or no longer
 * exists, also fails. The list has to shrink, not accumulate.
 */

namespace Trusteed\Tests\Meta;

use PHPUnit\Framework\TestCase;

final class TodoTestEstaEnUnaSuiteTest extends TestCase
{
    private const COLISION_DE_STUBS =
        'Declara stubs globales de PrestaShop (\\Configuration::set, \\Context, …) que '
        . 'colisionan con los del bootstrap compartido: fuera del xml hasta aislar sus '
        . 'stubs. Mismo motivo que ya documentaba phpunit.xml para tests/enforcement.';

    /**
     * Files deliberately outside every suite, with the reason. Path relative to
     * the module root.
     *
     * @var array<string,string>
     */
    private const EXCEPCIONES = [
        'tests/enforcement/AgentHistoryFetcherTest.php' => self::COLISION_DE_STUBS,
        'tests/enforcement/CartSignalsR035R036Test.php' => self::COLISION_DE_STUBS,
        'tests/enforcement/CartSignalsSprintETest.php' => self::COLISION_DE_STUBS,
        'tests/enforcement/FileCacheTest.php' => self::COLISION_DE_STUBS,
        'tests/enforcement/McpNoAuthDetectorTest.php' => self::COLISION_DE_STUBS,
        'tests/enforcement/NonceConsumeTest.php' => self::COLISION_DE_STUBS,
        'tests/enforcement/OfflineSafetyValveConformanceTest.php' => self::COLISION_DE_STUBS,
        'tests/enforcement/OrganicCheckoutUniversalRulesTest.php' => self::COLISION_DE_STUBS,
        'tests/enforcement/PaymentFailureEmitterTest.php' => self::COLISION_DE_STUBS,
        'tests/enforcement/PriceSnapVerifierTest.php' => self::COLISION_DE_STUBS,
        'tests/enforcement/PsSnapshotClientTest.php' => self::COLISION_DE_STUBS,
        'tests/enforcement/R022PaymentRailTest.php' => self::COLISION_DE_STUBS,
        'tests/enforcement/R043HitlGateTest.php' => self::COLISION_DE_STUBS,
        'tests/enforcement/SnapshotFallbackTest.php' => self::COLISION_DE_STUBS,
        'tests/enforcement/TokenVerifierMalformedSignatureTest.php' => self::COLISION_DE_STUBS,
        'tests/enforcement/ValidateOrderHookAgentTokenSourceTest.php' => self::COLISION_DE_STUBS,
        'tests/enforcement/ValidateOrderHookPs9BlockAttemptTest.php' => self::COLISION_DE_STUBS,
        'tests/enforcement/ValidateOrderHookTest.php' => self::COLISION_DE_STUBS,
        'tests/hmac/JcsCanonicalizeTest.php' =>
            'It dies with a fatal error when run (measured on PHP 8.2). '
            . 'Fix it before adding it to a suite instead of registering it broken.',
    ];

    private static function raiz(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return array{directorios: list<string>, ficheros: list<string>}
     */
    private static function entradasDelXml(): array
    {
        $xml = simplexml_load_file(self::raiz() . '/phpunit.xml');
        self::assertNotFalse($xml, 'phpunit.xml no se puede leer');

        $directorios = [];
        $ficheros = [];
        foreach ($xml->testsuites->testsuite as $suite) {
            foreach ($suite->directory as $d) {
                $directorios[] = rtrim(trim((string) $d), '/') . '/';
            }
            foreach ($suite->file as $f) {
                $ficheros[] = trim((string) $f);
            }
        }
        return ['directorios' => $directorios, 'ficheros' => $ficheros];
    }

    /** @return list<string> */
    private static function ficherosDeTest(): array
    {
        $raiz = self::raiz();
        $salida = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($raiz . '/tests', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if (!$f->isFile() || !str_ends_with($f->getFilename(), 'Test.php')) {
                continue;
            }
            $rel = substr($f->getPathname(), strlen($raiz) + 1);
            $salida[] = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
        }
        sort($salida);
        return $salida;
    }

    private static function cubierto(string $fichero, array $entradas): bool
    {
        if (in_array($fichero, $entradas['ficheros'], true)) {
            return true;
        }
        foreach ($entradas['directorios'] as $d) {
            if (str_starts_with($fichero, $d)) {
                return true;
            }
        }
        return false;
    }

    public function testElLectorVeDeVerdadLasSuites(): void
    {
        // If the xml reader stopped seeing entries, the gate below would report
        // everything as NOT covered or, after a format change, nothing as
        // covered. This keeps the gate from switching itself off.
        $entradas = self::entradasDelXml();
        self::assertContains('tests/hooks/', $entradas['directorios']);
        self::assertGreaterThan(20, count(self::ficherosDeTest()));
    }

    public function testTodoTestEstaEnUnaSuiteOEsUnaExcepcionDeclarada(): void
    {
        $entradas = self::entradasDelXml();
        $fuera = array_values(array_filter(
            self::ficherosDeTest(),
            static fn (string $f): bool =>
                !self::cubierto($f, $entradas) && !array_key_exists($f, self::EXCEPCIONES)
        ));

        self::assertSame(
            [],
            $fuera,
            "Estos tests no están en ninguna suite de phpunit.xml, así que no corren en CI:\n  "
            . implode("\n  ", $fuera)
            . "\nAñádelos a una suite, o a EXCEPCIONES con el motivo escrito."
        );
    }

    public function testNingunaExcepcionSobra(): void
    {
        $entradas = self::entradasDelXml();
        $existentes = self::ficherosDeTest();
        $muertas = [];
        foreach (array_keys(self::EXCEPCIONES) as $f) {
            if (!in_array($f, $existentes, true)) {
                $muertas[] = "$f (ya no existe)";
            } elseif (self::cubierto($f, $entradas)) {
                $muertas[] = "$f (ya está en una suite)";
            }
        }
        self::assertSame([], $muertas, 'Borra estas excepciones de EXCEPCIONES.');
    }
}
