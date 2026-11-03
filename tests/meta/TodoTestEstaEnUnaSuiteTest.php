<?php

declare(strict_types=1);

/**
 * Gate — todo `*Test.php` de este módulo está en alguna suite de phpunit.xml,
 * o figura aquí como excepción con su motivo.
 *
 * POR QUÉ (lote R2, auditoría agent-readiness 2026-09-26). `tests/hooks` —la red
 * del arreglo A4, sin la cual PrestaShop sale «cumple el 100 %» en C16— existía
 * y no estaba en phpunit.xml: sus tests no corrían en ningún sitio, y dos de
 * ellos llevaban tiempo dando error sin que nadie lo viera. Un test que no
 * corre no es un test, y lo que lo deja fuera no avisa: `phpunit` no se queja
 * de un fichero que nadie le ha pedido ejecutar.
 *
 * CÓMO SE LEE phpunit.xml: las entradas `<directory>` (con el sufijo por
 * defecto `Test.php`) y `<file>` de todas las `<testsuite>`. Un `*Test.php` que
 * no cae bajo ninguna y no está en EXCEPCIONES pone este test en rojo.
 *
 * Anti-podredumbre: una excepción cuyo fichero ya está en una suite, o ya no
 * existe, también lo pone en rojo. La lista tiene que encoger, no acumular.
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
     * Ficheros fuera de toda suite A PROPÓSITO, con el motivo. Ruta relativa a
     * la raíz del módulo.
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
            'Al ejecutarlo revienta con un error fatal (medido 2026-09-26 con PHP 8.2). '
            . 'Hay que arreglarlo antes de meterlo en una suite, no meterlo roto.',
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
        // Si el lector del xml dejara de ver entradas, el gate de abajo daría
        // por NO cubierto todo… o, con un cambio de formato, por cubierto nada.
        // Esto impide que se apague solo.
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
