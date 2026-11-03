<?php

declare(strict_types=1);

namespace Trusteed\Tests\Enforcement;

require_once __DIR__ . '/ValidateOrderHookFlowHarness.php';
require_once __DIR__ . '/../../src/Storage/CartAgentStorage.php';

use Trusteed\Storage\CartAgentStorage;

/** Task 7 — la marca de canal vive en `trusteed_agent_carts` (servidor), no en la cookie, y cuenta como agéntica. */
final class WebmcpChannelMarkTest extends ValidateOrderHookFlowTestCase
{
    protected function tearDown(): void
    {
        \Db::$rowToReturn = null;
        \Db::$executeFails = false;
        parent::tearDown();
    }

    public function testMarkWritesTheChannelWithoutTouchingAnExistingDid(): void
    {
        \Db::$executed = [];
        CartAgentStorage::markChannel(42, 'webmcp');

        $sql = implode("\n", \Db::$executed);
        self::assertStringContainsString('`channel`', $sql);
        self::assertStringContainsString("'webmcp'", $sql);
        self::assertMatchesRegularExpression('/ON DUPLICATE KEY UPDATE\s+`channel`\s*=\s*VALUES\(`channel`\)/', $sql);
        self::assertStringNotContainsString('`agent_did` = VALUES', $sql, 'un DID existente no se pisa');
        self::assertStringContainsString("''", $sql, 'fila nueva: agent_did vacío (la columna es NOT NULL)');
    }

    public function testOnlyKnownChannelsAreStored(): void
    {
        \Db::$executed = [];
        self::assertFalse(CartAgentStorage::markChannel(42, 'x\' OR 1=1'));
        self::assertSame([], \Db::$executed);
    }

    public function testMarkReportsWhetherTheRowWasWritten(): void
    {
        self::assertTrue(CartAgentStorage::markChannel(42, 'webmcp'));
        \Db::$executeFails = true;
        self::assertFalse(CartAgentStorage::markChannel(42, 'webmcp'), 'columna ausente / error de BD');
    }

    public function testSetKeepsTheChannelColumnUntouched(): void
    {
        \Db::$executed = [];
        CartAgentStorage::set(42, 'did:web:a.test');
        self::assertStringNotContainsString('channel', implode("\n", \Db::$executed));
    }

    public function testAWebmcpOnlyRowYieldsNoFakeDid(): void
    {
        \Db::$rowToReturn = ['agent_did' => '', 'channel' => 'webmcp'];
        self::assertNull(CartAgentStorage::get(42), "'' es ausente para todos los lectores del DID");
        self::assertSame('webmcp', CartAgentStorage::getChannel(42));
    }

    public function testARowWithADidKeepsItAndNoChannelIsNull(): void
    {
        \Db::$rowToReturn = ['agent_did' => 'did:web:a.test', 'channel' => null];
        self::assertSame('did:web:a.test', CartAgentStorage::get(42));
        self::assertNull(CartAgentStorage::getChannel(42));
        \Db::$rowToReturn = ['channel' => 'rubbish'];
        self::assertNull(CartAgentStorage::getChannel(42), 'solo canales conocidos');
    }

    public function testFreshInstallsCreateTheChannelColumn(): void
    {
        \Db::$executed = [];
        CartAgentStorage::createTable();
        self::assertStringContainsString('`channel` VARCHAR(16) NULL', implode("\n", \Db::$executed));
    }

    public function testEnsureChannelColumnIsIdempotent(): void
    {
        \Db::$executed = [];
        \Db::$queried = [];
        \Db::$rowToReturn = ['Field' => 'channel'];
        self::assertTrue(CartAgentStorage::ensureChannelColumn());
        self::assertSame([], \Db::$executed, 'ya existe: ni ALTER');

        \Db::$rowToReturn = null;
        self::assertTrue(CartAgentStorage::ensureChannelColumn());
        self::assertStringContainsString('SHOW COLUMNS', \Db::$queried[1]);
        self::assertStringContainsString('ADD COLUMN `channel` VARCHAR(16) NULL', \Db::$executed[0]);
    }

    public function testAMarkedHumanCartCountsAsAgenticForTheServer(): void
    {
        $this->withRules([['ruleCode' => 'R035.max-order-value', 'enabled' => true]]);
        \Db::$rowToReturn = ['agent_did' => '', 'channel' => 'webmcp'];

        $this->hook->evaluateCart(new \Cart());

        $body = json_decode($this->client->posts[0]['body'], true);
        self::assertSame('agentic_endpoint', $body['endpointClass'] ?? null);
        self::assertNull($body['agentId'], 'sin DID falso');
    }

    public function testAnUnmarkedHumanCartStaysOrganic(): void
    {
        $this->withRules([['ruleCode' => 'R035.max-order-value', 'enabled' => true]]);
        $this->hook->evaluateCart(new \Cart());
        $body = json_decode($this->client->posts[0]['body'], true);
        self::assertArrayNotHasKey('endpointClass', $body);
    }

    public function testOrderEventsCarryTheChannelOnlyWhenMarked(): void
    {
        $emitter = new \Trusteed\Hooks\OrderEventsEmitter();
        $m = new \ReflectionMethod($emitter, 'channelPayload');
        $m->setAccessible(true);

        \Db::$rowToReturn = ['agent_did' => '', 'channel' => 'webmcp'];
        self::assertSame(['agent_channel' => 'webmcp'], $m->invoke($emitter, 42));
        \Db::$rowToReturn = null;
        self::assertSame([], $m->invoke($emitter, 42));
        self::assertSame([], $m->invoke($emitter, 0));
    }

    public function testEmittersTreatAnEmptyDidAsAbsent(): void
    {
        \Db::$rowToReturn = ['agent_did' => '', 'channel' => 'webmcp'];
        $emitter = new \Trusteed\Hooks\OrderEventsEmitter();
        $m = new \ReflectionMethod($emitter, 'resolveCartAgentDid');
        $m->setAccessible(true);
        self::assertNull($m->invoke($emitter, 42));
    }
}
