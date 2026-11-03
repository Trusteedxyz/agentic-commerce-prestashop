<?php

declare(strict_types=1);

/**
 * Gate — ningún bloqueo de src/ se lanza como `\PrestaShopException` a secas.
 *
 * POR QUÉ (plan 04, Task 26). El chequeo del pedido distingue nuestros
 * veredictos ({@see \Trusteed\Enforcement\CelVerdictException}) de las averías:
 * una `\PrestaShopException` del núcleo se trata como avería propia (un humano
 * pasa, un agente se retiene con CEL-INTERNAL-ERROR). Si un bloqueo nuevo se
 * lanzara como `\PrestaShopException`, se leería como avería y dejaría pasar a
 * un humano que el comerciante quería parar (un tope R030, un país…), sin ningún
 * error visible.
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
                // Sólo código: los comentarios y docblocks pueden nombrarla.
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
