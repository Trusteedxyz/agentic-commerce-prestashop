<?php

declare(strict_types=1);

/**
 * FallbackModeDefaultTest — A1 (audit 2026-08-17).
 *
 * The module documented a **fail-closed** default for CEL enforcement, in two
 * places at once:
 *
 *   - `MerchantResolver::getFallbackMode()`'s docblock ("Sprint C T13: default
 *     flipped from 'balanced' to 'strict'"), and
 *   - the README `## Features` bullet ("Fail-closed defaults").
 *
 * Neither was true. `install()` persists `TRUSTEED_CEL_FALLBACK_MODE = 'balanced'`,
 * so `getFallbackMode()` reads back a *valid* value and returns it — the
 * `'strict'` branch is only ever reached for an absent or corrupt value, never
 * on a normal install. The effective default was fail-OPEN.
 *
 * These tests pin the real behaviour AND pin the docs to it, so the claim and
 * the code cannot drift apart again. They exercise the REAL
 * `Trusteed\Enforcement\MerchantResolver` (per the bootstrap's PSR-4 autoloader)
 * against the shared `\Configuration` stub.
 */

namespace Trusteed\Tests\Enforcement;

use PHPUnit\Framework\TestCase;
use Trusteed\Enforcement\MerchantResolver;
use TestPsState;

final class FallbackModeDefaultTest extends TestCase
{
    private const MODULE_ROOT = __DIR__ . '/../..';

    /** READMEs that document the enforcement defaults. */
    private const READMES = ['README.md', 'README.es.md', 'README.fr.md', 'README.de.md'];

    protected function setUp(): void
    {
        TestPsState::reset();
    }

    protected function tearDown(): void
    {
        TestPsState::reset();
    }

    // ── Real code: what install() actually persists ───────────────────────────

    /**
     * `install()` seeds `TRUSTEED_CEL_FALLBACK_MODE`. Assert the literal it
     * writes, because that literal — not the resolver's invalid-value branch —
     * is the effective default every merchant gets.
     */
    public function testInstallPersistsBalancedAsTheFallbackModeDefault(): void
    {
        $source = (string) file_get_contents(self::MODULE_ROOT . '/trusteed.php');

        $matched = preg_match(
            "/Configuration::updateValue\(\s*'TRUSTEED_CEL_FALLBACK_MODE'\s*,\s*'([a-z]+)'\s*\)/",
            $source,
            $m
        );

        self::assertSame(1, $matched, 'install() no longer seeds TRUSTEED_CEL_FALLBACK_MODE.');
        self::assertSame(
            'balanced',
            $m[1],
            'The install default changed. If this is intentional, update the '
            . 'MerchantResolver::getFallbackMode() docblock and all four READMEs '
            . 'in the same commit — that mismatch is exactly what this test guards.'
        );
    }

    /**
     * With the install default in place, the resolver returns 'balanced'.
     * This is the assertion the docblock contradicted.
     */
    public function testResolverReturnsBalancedForTheInstalledDefault(): void
    {
        TestPsState::$config['TRUSTEED_CEL_FALLBACK_MODE'] = 'balanced';

        self::assertSame('balanced', MerchantResolver::getFallbackMode());
    }

    /**
     * The 'strict' branch is reachable only for an absent or corrupt value —
     * i.e. it is a corruption guard, not the default.
     */
    public function testResolverFallsBackToStrictOnlyForAbsentOrInvalidValues(): void
    {
        self::assertSame('strict', MerchantResolver::getFallbackMode(), 'absent value');

        foreach (['', 'BALANCED', 'lenient', 'off'] as $invalid) {
            TestPsState::$config['TRUSTEED_CEL_FALLBACK_MODE'] = $invalid;
            self::assertSame(
                'strict',
                MerchantResolver::getFallbackMode(),
                sprintf('invalid value %s should be treated as corrupt', var_export($invalid, true))
            );
        }
    }

    /** An explicit merchant override is honoured verbatim. */
    public function testResolverHonoursExplicitMerchantOverrides(): void
    {
        foreach (['strict', 'balanced', 'permissive'] as $mode) {
            TestPsState::$config['TRUSTEED_CEL_FALLBACK_MODE'] = $mode;
            self::assertSame($mode, MerchantResolver::getFallbackMode());
        }
    }

    // ── Doc-drift gates ──────────────────────────────────────────────────────

    /**
     * The docblock must not claim a strict default while install() seeds
     * 'balanced'.
     */
    public function testResolverDocblockDoesNotClaimAStrictDefault(): void
    {
        $source = (string) file_get_contents(self::MODULE_ROOT . '/src/Enforcement/MerchantResolver.php');

        self::assertDoesNotMatchRegularExpression(
            "/default flipped from 'balanced' to/",
            $source,
            "getFallbackMode()'s docblock claims the default is 'strict'; install() seeds 'balanced'."
        );
    }

    /**
     * No README may advertise fail-closed defaults while the shipped default is
     * fail-open. The backend snapshot builder defaults to BALANCED too
     * (`apps/api/src/services/enforcement/snapshot-builder.service.ts`), so
     * 'balanced' is the product-wide contract, not a PrestaShop accident.
     */
    public function testNoReadmeAdvertisesFailClosedDefaults(): void
    {
        foreach (self::READMES as $readme) {
            $text = (string) file_get_contents(self::MODULE_ROOT . '/' . $readme);

            self::assertDoesNotMatchRegularExpression(
                '/^- \*\*[^*]*[Ff]ail-closed[^*]*\*\*/mu',
                $text,
                $readme . ' still advertises fail-closed defaults as a feature.'
            );
        }
    }

    /**
     * Every config key `install()` seeds must appear in each README's
     * configuration-keys table. A key the merchant cannot discover is a key
     * they cannot set — and three of these are what actually arm enforcement.
     */
    public function testEveryInstalledCelKeyIsDocumentedInAllReadmes(): void
    {
        $required = [
            'TRUSTEED_CEL_ENABLED',
            'TRUSTEED_CEL_FALLBACK_MODE',
            'TRUSTEED_CEL_MERCHANT_ID',
            'TRUSTEED_CEL_INSTALLATION_ID',
            'TRUSTEED_CEL_HMAC_SECRET',
        ];

        foreach (self::READMES as $readme) {
            $text = (string) file_get_contents(self::MODULE_ROOT . '/' . $readme);

            foreach ($required as $key) {
                self::assertStringContainsString(
                    '| `' . $key . '` |',
                    $text,
                    $key . ' is missing from the configuration-keys table in ' . $readme
                );
            }
        }
    }

    /**
     * The documented default for the fallback mode must be the literal
     * `install()` writes.
     */
    public function testReadmesDocumentTheRealFallbackModeDefault(): void
    {
        foreach (self::READMES as $readme) {
            $text = (string) file_get_contents(self::MODULE_ROOT . '/' . $readme);

            $matched = preg_match(
                '/^\| `TRUSTEED_CEL_FALLBACK_MODE` \| `([a-z]+)` \|/mu',
                $text,
                $m
            );

            self::assertSame(1, $matched, 'No documented default for TRUSTEED_CEL_FALLBACK_MODE in ' . $readme);
            self::assertSame('balanced', $m[1], $readme . ' documents the wrong default.');
        }
    }

    /**
     * M1 — the "Admin Pages" table must use the labels the back-office sidebar
     * actually renders, which come from `trusteed.php`'s ADMIN_TABS constant.
     */
    public function testAdminPagesTableUsesTheRealSidebarLabels(): void
    {
        $source = (string) file_get_contents(self::MODULE_ROOT . '/trusteed.php');

        preg_match_all(
            "/'class_name' => 'AdminTrusteed\w+',\s*'label' => '([^']+)',\s*'label_en' => '([^']+)'/u",
            $source,
            $tabs,
            PREG_SET_ORDER
        );

        self::assertCount(8, $tabs, 'ADMIN_TABS no longer holds 8 entries.');

        $english = (string) file_get_contents(self::MODULE_ROOT . '/README.md');
        foreach ($tabs as $tab) {
            self::assertStringContainsString(
                '| ' . $tab[2] . ' |',
                $english,
                sprintf('README.md omits the real English sidebar label %s.', var_export($tab[2], true))
            );
        }

        // Non-English locales fall back to the Spanish `label` (only `label_en`
        // is localised), so those are the strings the sidebar really shows.
        foreach (['README.es.md', 'README.fr.md', 'README.de.md'] as $readme) {
            $text = (string) file_get_contents(self::MODULE_ROOT . '/' . $readme);
            foreach ($tabs as $tab) {
                self::assertStringContainsString(
                    '| ' . $tab[1] . ' |',
                    $text,
                    sprintf('%s omits the real sidebar label %s.', $readme, var_export($tab[1], true))
                );
            }
        }
    }

    /**
     * M2 — the changelog must not claim the prompt-sanitizer delimiter fix.
     * `<<<MERCHANT_CONTENT_START>>>` is produced by
     * `packages/shared/src/security/prompt-sanitizer.ts` and stripped for
     * display in `packages/mcp-server/src/tools/browse-categories.ts`; the
     * string appears nowhere in this module, bundle included.
     */
    public function testChangelogDoesNotClaimTheSanitizerDelimiterFix(): void
    {
        foreach (self::READMES as $readme) {
            $text = (string) file_get_contents(self::MODULE_ROOT . '/' . $readme);

            self::assertStringNotContainsString(
                'MERCHANT_CONTENT_START',
                $text,
                $readme . ' credits this module with a fix that lives in packages/mcp-server.'
            );
        }

        self::assertStringNotContainsString(
            'MERCHANT_CONTENT',
            (string) file_get_contents(self::MODULE_ROOT . '/views/js/admin-spa.js'),
            'The delimiters now DO appear in the admin bundle — re-check the changelog claim.'
        );
    }
}
