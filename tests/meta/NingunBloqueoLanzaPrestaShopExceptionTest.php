<?php

declare(strict_types=1);

/**
 * Gate: no block in src/ may be thrown as a bare `\PrestaShopException`.
 *
 * WHY: the order check tells our verdicts
 * ({@see \Trusteed\Enforcement\CelVerdictException}) apart from faults. A
 * `\PrestaShopException` from the core is treated as our own fault (a human
 * passes, an agent is held with CEL-INTERNAL-ERROR). If a new block were thrown
 * as a `\PrestaShopException`, it would be read as a fault and let through a
 * human the merchant meant to stop (an R030 cap, a country, ...), with no
 * visible error.
 */

namespace Trusteed\Tests\Meta;

use PHPUnit\Framework\TestCase;

final class NingunBloqueoLanzaPrestaShopExceptionTest extends TestCase
{
    /** @return list<string> */
    private static function fuentes(): array
    {
        $raiz = dirname(__DIR__, 2);
        $ficheros = [$raiz . '/override/classes/PaymentModule.php'];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->isFile() && $f->getExtension() === 'php') {
                $ficheros[] = $f->getPathname();
            }
        }
        sort($ficheros);
        return $ficheros;
    }

    public function testNadieInstanciaPrestaShopExceptionADesnudo(): void
    {
        $fuentes = self::fuentes();
        self::assertGreaterThan(10, count($fuentes), 'el gate no ve el árbol');

        $culpables = [];
        foreach ($fuentes as $ruta) {
            $codigo = '';
            foreach (token_get_all((string) file_get_contents($ruta)) as $tok) {
                // Code only: comments and docblocks may mention it.
                if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $codigo .= is_array($tok) ? $tok[1] : $tok;
            }
            if (preg_match('/new\s+\\\\?PrestaShopException\b/', $codigo) === 1) {
                $culpables[] = basename($ruta);
            }
        }

        self::assertSame(
            [],
            $culpables,
            'Usa CelVerdictException para un bloqueo: una PrestaShopException desnuda se lee como avería y deja pasar a un humano'
        );
    }

    public function testElUnicoCapturadorDeVeredictosDeLosPuntosDeEntradaEsCelVerdictException(): void
    {
        $raiz = dirname(__DIR__, 2);
        foreach (['/src/Enforcement/ValidateOrderHook.php', '/override/classes/PaymentModule.php'] as $rel) {
            $src = (string) file_get_contents($raiz . $rel);
            self::assertMatchesRegularExpression('/catch\s*\(\s*\\\\?(?:Trusteed\\\\Enforcement\\\\)?CelVerdictException\b/', $src, $rel);
        }
    }
}
