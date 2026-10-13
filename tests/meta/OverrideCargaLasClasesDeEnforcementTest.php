<?php

declare(strict_types=1);

namespace Trusteed\Tests\Meta;

use PHPUnit\Framework\TestCase;

/**
 * `override/classes/PaymentModule.php::loadCelClasses()` loads by hand, with
 * `require_once`, the classes `ValidateOrderHook` needs when the composer
 * autoloader is not active in the override context. If `ValidateOrderHook`
 * starts using a `Trusteed\Enforcement` class that this list does not load,
 * the order fails with "Class not found" only on shops without an autoloader,
 * and no other test sees it because the autoloader is always present here.
 *
 * The gate does NOT use a hand-written list: it discovers which classes
 * `src/Enforcement/` declares (and in which file), which of them the code of
 * `ValidateOrderHook` names (comments excluded), and requires the file that
 * declares each one to be in the override's list. The closure is transitive:
 * it also counts what the classes used by the hook use.
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
                // `Foo::class` is not a declaration.
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
     * @return list<string> short names that appear as identifiers in the code (comments excluded).
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

    /** @return list<string> relative paths ('Enforcement/X.php', ...) that loadCelClasses() loads. */
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

        // TRANSITIVE closure: a class the hook does not name but that
        // `SnapshotClient` uses (e.g. BoundedHttpGet) also fails with
        // "Class not found" on a shop without an autoloader.
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
        // Sanity check: if the analysis does not even see this one, it sees nothing.
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
