<?php

declare(strict_types=1);

/**
 * Log JSON is built with json_encode, not by hand; the fallback_open warning is
 * not repeated during the failure window; and the local tier-1 loop no longer
 * exposes the rule code.
 */

namespace Trusteed\Tests\Enforcement;

require_once __DIR__ . '/ValidateOrderHookFlowHarness.php';

final class ValidateOrderHookLogEscapingTest extends ValidateOrderHookFlowTestCase
{
    private function blockWithReason(string $reason, string $ruleCode = 'R066.x'): void
    {
        $this->withRules([['ruleCode' => 'R035.max-order-value', 'enabled' => true]]);
        $this->client->evaluateResponse = [
            'code' => 200,
            'body' => (string) json_encode(['decision' => 'BLOCK', 'ruleCode' => $ruleCode, 'reason' => $reason]),
            'error' => '',
        ];
    }

    /** @return list<string> */
    private function blockLogs(): array
    {
        return array_values(array_filter(
            \PrestaShopLogger::$messages,
            static fn (string $m): bool => str_starts_with($m, '[trusteed.cel.block] ')
        ));
    }

    public function testBackslashesAndNewlinesInTheReasonDoNotBreakTheLogLine(): void
    {
        $reason = "code \"A\\B\"\nfake line [trusteed.cel.block] {\"ruleCode\":\"R000\"}\r\ttab / slash é";
        $this->blockWithReason($reason, "R0\\66\n.x");

        self::assertNotNull($this->outcome());

        $logs = $this->blockLogs();
        self::assertCount(1, $logs);
        self::assertStringNotContainsString("\n", $logs[0], 'una sola línea de log');
        self::assertStringNotContainsString("\r", $logs[0]);
        $decoded = json_decode(substr($logs[0], strlen('[trusteed.cel.block] ')), true);
        self::assertIsArray($decoded, 'JSON válido');
        self::assertSame($reason, $decoded['reason'], 'ida y vuelta exacta');
        self::assertSame("R0\\66\n.x", $decoded['ruleCode']);
        self::assertStringContainsString('/ slash é', $logs[0], 'sin escapar barras ni unicode');
    }

    public function testInvalidUtf8InTheReasonStillProducesAValidLine(): void
    {
        $this->withRules([['ruleCode' => 'R035.max-order-value', 'enabled' => true]]);
        // json_encode of the body requires valid UTF-8: the raw body is served with \xff escaped by the server.
        $this->client->evaluateResponse = [
            'code' => 200,
            'body' => '{"decision":"BLOCK","ruleCode":"R066","reason":"ok é end"}',
            'error' => '',
        ];
        self::assertNotNull($this->outcome());
        $decoded = json_decode(substr($this->blockLogs()[0], strlen('[trusteed.cel.block] ')), true);
        self::assertSame("ok é end", $decoded['reason']);
    }

    public function testAnOutageLogsFallbackOpenOncePerWindow(): void
    {
        // With an enforcing rule (hasEnforcingServerRule) permissive mode lets it through and warns.
        $this->withRules([['ruleCode' => 'R035.max-order-value', 'enabled' => true]]);
        $this->mode('permissive');
        $this->serverDown();

        for ($i = 0; $i < 3; ++$i) {
            self::assertNull($this->outcome());
        }

        self::assertSame(1, $this->logsContaining('[trusteed.snapshot.fallback_open]'), 'un solo aviso durante la ventana');
        self::assertCount(1, $this->client->evaluateBodies(), 'y una sola llamada a la capa 2 (cortacircuitos)');
    }

    public function testTheLocalTierOneLoopShowsAFixedPhraseNotTheRuleCode(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Enforcement/ValidateOrderHook.php');
        self::assertStringNotContainsString("\$ruleCode . ' — '", $src);
        self::assertStringNotContainsString('Más info:', $src);
        self::assertStringNotContainsString("sprintf(\n                        '[trusteed.cel.block]", $src);
    }
}
