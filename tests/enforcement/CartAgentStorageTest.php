<?php

declare(strict_types=1);

namespace Trusteed\Tests\Enforcement;

require_once __DIR__ . '/ValidateOrderHookFlowHarness.php';
require_once __DIR__ . '/../../src/Storage/CartAgentStorage.php';

use PHPUnit\Framework\TestCase;
use Trusteed\Storage\CartAgentStorage;

/**
 * 77ab260d4 (barrido 2026-10-10, DSAR). `trusteed_agent_carts` guarda el DID del
 * agente en claro y su docblock decía «filas borradas tras la primera lectura»:
 * `delete()` no tenía ningún llamador, así que los DID persistían para siempre en
 * la base del comerciante. No se puede borrar en la primera lectura —lo leen tres
 * emisores en momentos distintos (alta del pedido, envío/entrega, reembolso)—, así
 * que la retención es un plazo: lo vencido no se lee y se purga.
 */
final class CartAgentStorageTest extends TestCase
{
    protected function setUp(): void
    {
        \Db::$executed = [];
        \Db::$queried = [];
    }

    public function testAnExpiredRowIsNeverRead(): void
    {
        CartAgentStorage::get(42);

        self::assertCount(1, \Db::$queried);
        self::assertMatchesRegularExpression(
            '/`created_at`\s*>=\s*DATE_SUB\(NOW\(\),\s*INTERVAL\s+' . CartAgentStorage::RETENTION_DAYS . '\s+DAY\)/',
            \Db::$queried[0],
            'get() ignora lo que superó la retención aunque la purga aún no haya corrido'
        );
    }

    public function testSettingARowPurgesWhatExceededTheRetention(): void
    {
        CartAgentStorage::set(7, 'did:web:agent.example.test');

        $purges = array_values(array_filter(
            \Db::$executed,
            static fn (string $sql): bool => str_starts_with($sql, 'DELETE FROM')
        ));
        self::assertCount(1, $purges, 'cada alta purga lo vencido');
        self::assertStringContainsString('`created_at` < DATE_SUB(NOW(), INTERVAL ' . CartAgentStorage::RETENTION_DAYS . ' DAY)', $purges[0]);
        self::assertStringNotContainsString('did:web', $purges[0]);
    }

    public function testThePurgeCanBeRunOnItsOwn(): void
    {
        CartAgentStorage::purgeExpired();

        self::assertCount(1, \Db::$executed);
        self::assertStringStartsWith('DELETE FROM', \Db::$executed[0]);
    }

    public function testTheDocblockNoLongerPromisesADeleteThatNobodyDoes(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Storage/CartAgentStorage.php');

        self::assertStringNotContainsString('deleted after the first read', $src);
    }

    public function testTheDidIsPersistedBeforeAnyEmitterThatReadsItCanRun(): void
    {
        // B7 (91c7f88d9 / 31441c2ca): CouponFailureEmitter/PaymentFailureEmitter/
        // OrderEventsEmitter leen el DID DESPUÉS de validateOrder. El override
        // evalúa (y guarda el DID en ALLOW) antes de delegar en parent.
        $src = (string) file_get_contents(__DIR__ . '/../../override/classes/PaymentModule.php');
        $eval = strpos($src, '$this->runCelEvaluation(');
        $parent = strpos($src, 'return parent::validateOrder(');

        self::assertNotFalse($eval);
        self::assertNotFalse($parent);
        self::assertLessThan($parent, $eval, 'la evaluación (CartAgentStorage::set) precede a parent::validateOrder');
    }
}
