<?php

declare(strict_types=1);

namespace Trusteed\Tests\Enforcement;

require_once __DIR__ . '/ValidateOrderHookFlowHarness.php';
require_once __DIR__ . '/../../src/Storage/CartAgentStorage.php';

use PHPUnit\Framework\TestCase;
use Trusteed\Storage\CartAgentStorage;

/**
 * `trusteed_agent_carts` stores the agent DID in clear, and its docblock said
 * "rows deleted after the first read", but `delete()` had no caller, so DIDs
 * persisted forever in the merchant's database. They cannot be deleted on the
 * first read (three emitters read them at different moments: order creation,
 * shipping/delivery, refund), so retention is a time window: expired rows are
 * not read and get purged.
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
        // CouponFailureEmitter/PaymentFailureEmitter/OrderEventsEmitter read the
        // DID AFTER validateOrder. The override evaluates (and stores the DID on
        // ALLOW) before delegating to parent.
        $src = (string) file_get_contents(__DIR__ . '/../../override/classes/PaymentModule.php');
        $eval = strpos($src, '$this->runCelEvaluation(');
        $parent = strpos($src, 'return parent::validateOrder(');

        self::assertNotFalse($eval);
        self::assertNotFalse($parent);
        self::assertLessThan($parent, $eval, 'la evaluación (CartAgentStorage::set) precede a parent::validateOrder');
    }
}
