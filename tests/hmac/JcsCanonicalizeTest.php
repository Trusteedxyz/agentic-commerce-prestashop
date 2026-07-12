<?php

declare(strict_types=1);

/**
 * Spec 042 R1 (codex remediation P1#1) — PHPUnit tests for JcsCanonicalize.
 *
 * Loads the SAME golden vectors used by Node + Odoo. Cross-language CI gate.
 *
 * Run: vendor/bin/phpunit tests/hmac/JcsCanonicalizeTest.php
 */

namespace Trusteed\Tests\Hmac;

use Trusteed\Hmac\JcsCanonicalize;
use PHPUnit\Framework\TestCase;

final class JcsCanonicalizeTest extends TestCase
{
    /** @return iterable<array{string, mixed, string}> */
    public static function vectorProvider(): iterable
    {
        $path = __DIR__ . '/../../../../apps/api/src/__tests__/fixtures/jcs-golden-vectors.json';
        $raw = file_get_contents($path);
        self::assertNotFalse($raw, 'golden vectors file readable');
        $fixture = json_decode($raw, true);
        self::assertIsArray($fixture['vectors']);

        foreach ($fixture['vectors'] as $v) {
            yield $v['id'] => [$v['id'], $v['input'], $v['canonical']];
        }
    }

    /**
     * @dataProvider vectorProvider
     */
    public function testGoldenVector(string $id, mixed $input, string $expectedCanonical): void
    {
        $actual = JcsCanonicalize::canonicalize($input);
        self::assertSame(
            $expectedCanonical,
            $actual,
            "Vector {$id} canonical mismatch.\nExpected: {$expectedCanonical}\nActual:   {$actual}"
        );
    }

    public function testDeterministicSha256(): void
    {
        $input = ['b' => 2, 'a' => 1];
        $c1 = JcsCanonicalize::canonicalize($input);
        $c2 = JcsCanonicalize::canonicalize(['a' => 1, 'b' => 2]);
        self::assertSame($c1, $c2);
        self::assertSame(hash('sha256', $c1), hash('sha256', $c2));
    }

    public function testNonFiniteThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        JcsCanonicalize::canonicalize(INF);
    }

    public function testNestedArray(): void
    {
        $input = [
            'list' => [
                ['b' => 2, 'a' => 1],
                ['d' => 4, 'c' => 3],
            ],
        ];
        $expected = '{"list":[{"a":1,"b":2},{"c":3,"d":4}]}';
        self::assertSame($expected, JcsCanonicalize::canonicalize($input));
    }
}
