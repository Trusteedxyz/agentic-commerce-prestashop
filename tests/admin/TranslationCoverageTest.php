<?php

declare(strict_types=1);

namespace Trusteed\Tests\Admin;

use PHPUnit\Framework\TestCase;

/**
 * Cada texto que el módulo pasa por `l()` o `{l s=…}` tiene su entrada en
 * `translations/es.php`. Sin esto, la pantalla de ajustes sale en inglés para un
 * empleado en español y nadie lo ve en los tests (el 2026-11-11 salía entera así).
 *
 * `Module::l()` usa el nombre del módulo como fuente (no el fichero) y `md5(texto)` sin escapar:
 * la clave es `<{trusteed}prestashop>trusteed_{md5(texto)}`.
 */
final class TranslationCoverageTest extends TestCase
{
    /** Textos que el módulo calcula en tiempo de ejecución y no aparecen como literal en un `l()`. */
    private const DYNAMIC = [
        'Trusteed: finish setup',
        'Trusteed: checkout not protected',
        'Your store is not registered with Trusteed yet. Agents cannot find it. Click to finish setup.',
        'Your store is registered, but checkout protection is not active: every checkout goes through without any of your rules being checked. Click to finish the setup.',
        'Your store is registered and checkout protection is active.',
    ];

    /** @return array<string, string> clave => texto */
    private function expectedKeys(): array
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            if (preg_match('#/(vendor|tests|dist|node_modules|translations)/#', $path)
                || !preg_match('/\.(php|tpl)$/', $path)
                || str_ends_with($path, 'AdminTrusteedController.php')) {
                continue;
            }
            $src = (string) file_get_contents($path);
            $strings = [];
            if (preg_match_all('/\{l s=\'((?:[^\'\\\\]|\\\\.)*)\'/', $src, $m)) {
                foreach ($m[1] as $s) {
                    $strings[] = stripslashes($s);
                }
            }
            if (preg_match_all('/->l\(\s*((?:\'(?:[^\'\\\\]|\\\\.)*\'\s*\.?\s*)+)\)/s', $src, $m)) {
                foreach ($m[1] as $chunk) {
                    preg_match_all('/\'((?:[^\'\\\\]|\\\\.)*)\'/', $chunk, $q);
                    $strings[] = stripslashes(implode('', $q[1]));
                }
            }
            foreach ($strings as $s) {
                $found[self::key($s)] = $s;
            }
        }
        foreach (self::DYNAMIC as $s) {
            $found[self::key($s)] = $s;
        }

        return $found;
    }

    private static function key(string $text): string
    {
        return '<{trusteed}prestashop>trusteed_' . md5($text);
    }

    public function testEveryStringHasASpanishTranslation(): void
    {
        $_MODULE = [];
        global $_MODULE;
        $_MODULE = [];
        require dirname(__DIR__, 2) . '/translations/es.php';

        $missing = [];
        foreach ($this->expectedKeys() as $key => $text) {
            if (!isset($_MODULE[$key]) || trim((string) $_MODULE[$key]) === '') {
                $missing[] = $text;
            }
        }

        $this->assertSame([], $missing, "Faltan traducciones al español:\n- " . implode("\n- ", $missing));
        $this->assertGreaterThan(100, count($this->expectedKeys()), 'el extractor no encuentra los textos');
    }

    public function testTheSettingsScreenDoesNotShowInternalJargon(): void
    {
        $root = dirname(__DIR__, 2);
        $shown = (string) file_get_contents($root . '/trusteed.php')
            . (string) file_get_contents($root . '/src/Enforcement/AdminSettings.php');
        foreach (['S2S', 'HMAC Secret', 'CEL Enforcement', 'Save CEL', 'add-on (96617) not detected'] as $jargon) {
            $this->assertStringNotContainsString("l('" . $jargon, $shown);
        }
        $this->assertStringNotContainsString('Actividad reciente', $shown, 'texto en español hardcodeado: va en inglés con su traducción');
    }
}
