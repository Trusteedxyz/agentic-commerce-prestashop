<?php

declare(strict_types=1);

namespace Trusteed\Tests\Meta;

use PHPUnit\Framework\TestCase;

/**
 * `override/classes/PaymentModule.php::loadCelClasses()` carga a mano, con
 * `require_once`, las clases que necesita `ValidateOrderHook` cuando el
 * autoloader de composer no está activo en el contexto del override. Si
 * `ValidateOrderHook` empieza a usar una clase de `Trusteed\Enforcement` que
 * esa lista no carga, el pedido revienta con «Class not found» sólo en las
 * tiendas sin autoloader — y ningún otro test lo ve, porque aquí el
 * autoloader siempre está.
 *
 * El gate NO usa una lista escrita a mano: descubre qué clases declara
 * `src/Enforcement/` (y en qué fichero), cuáles de ellas nombra el código de
 * `ValidateOrderHook` (comentarios fuera) y exige que el fichero que declara
 * cada una esté en la lista del override. Desde la Task 29c el cierre es
 * transitivo: también cuenta lo que usan las clases que el hook usa.
 */
final class OverrideCargaLasClasesDeEnforcementTest extends TestCase
{
    private const ENFORCEMENT_NS = 'Trusteed\\Enforcement\\';

    private static function moduleDir(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string,string> nombre corto de clase => 'Enforcement/Fichero.php' */
    private static function declaredEnforcementClasses(): array
    {
        $out   = [];
        $files = glob(self::moduleDir() . '/src/Enforcement/*.php') ?: [];
        foreach ($files as $file) {
            $tokens = token_get_all((string) file_get_contents($file));
            $count  = count($tokens);
            for ($i = 0; $i < $count; $i++) {
                if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                    continue;
                }
                // `Foo::class` no es una declaración.
                $prev = $i - 1;
                while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                    $prev--;
                }
                if ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_DOUBLE_COLON) {
                    continue;
                }
                $next = $i + 1;
                while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                    $next++;
                }
                if ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
                    $out[$tokens[$next][1]] = 'Enforcement/' . basename($file);
                }
            }
        }

        return $out;
    }

    /**
     * @param string $file Ruta relativa a `src/Enforcement/`.
     * @return list<string> nombres cortos que aparecen como identificador en el código (sin comentarios).
     */
    private static function identifiersUsedBy(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents(self::moduleDir() . '/src/' . $file));
        $names  = [];
        foreach ($tokens as $t) {
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_STRING) {
                $names[] = $t[1];
                continue;
            }
            if ($t[0] === T_NAME_QUALIFIED || $t[0] === T_NAME_FULLY_QUALIFIED) {
                $name = ltrim($t[1], '\\');
                if (str_starts_with($name, self::ENFORCEMENT_NS)) {
                    $names[] = substr($name, strlen(self::ENFORCEMENT_NS));
                }
            }
        }

        return array_values(array_unique($names));
    }

    /** @return list<string> rutas relativas ('Enforcement/X.php', …) que carga loadCelClasses(). */
    private static function overrideLoaderPaths(): array
    {
        $src = (string) file_get_contents(self::moduleDir() . '/override/classes/PaymentModule.php');
        $start = strpos($src, 'function loadCelClasses');
        self::assertNotFalse($start, 'loadCelClasses() ya no existe en el override');
        $body = substr($src, $start);
        $end  = strpos($body, 'foreach ($classes');
        self::assertNotFalse($end, 'loadCelClasses() cambió de forma: el gate no sabe leerla');
        preg_match_all("/=>\\s*'([^']+\\.php)'/", substr($body, 0, $end), $m);

        return $m[1];
    }

    public function testElOverrideCargaTodaClaseDeEnforcementQueUsaValidateOrderHook(): void
    {
        $declared = self::declaredEnforcementClasses();
        self::assertArrayHasKey('ValidateOrderHook', $declared, 'no se encontraron las clases de src/Enforcement');

        // Task 29c: cierre TRANSITIVO. Una clase que el hook no nombra pero que
        // usa `SnapshotClient` (p. ej. BoundedHttpGet) también falla con
        // «Class not found» en una tienda sin autoloader.
        $used  = [];
        $queue = ['ValidateOrderHook'];
        while ($queue !== []) {
            $class = array_shift($queue);
            foreach (self::identifiersUsedBy($declared[$class]) as $name) {
                if (isset($declared[$name]) && $name !== $class && !in_array($name, $used, true)) {
                    $used[]  = $name;
                    $queue[] = $name;
                }
            }
        }
        // Sanidad: si el análisis no ve ni esta, no está viendo nada.
        self::assertContains('SnapshotClient', $used);

        $loaded  = self::overrideLoaderPaths();
        $missing = [];
        foreach ($used as $class) {
            if (!in_array($declared[$class], $loaded, true)) {
                $missing[] = $class . ' (' . $declared[$class] . ')';
            }
        }
        sort($missing);

        self::assertSame([], $missing, 'ValidateOrderHook (o lo que éste usa) necesita clases que loadCelClasses() no carga');
    }
}
