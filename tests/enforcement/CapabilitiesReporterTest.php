<?php

declare(strict_types=1);

namespace Trusteed\Tests\Enforcement;

use PHPUnit\Framework\TestCase;
use Trusteed\Enforcement\CapabilitiesReporter;

require_once __DIR__ . '/../../src/Enforcement/CapabilitiesReporter.php';

/**
 * The server treats a store as dead when its `lastSeenAt` is 30 days old, and
 * that mark is only renewed by the capabilities report or by /rules/* traffic.
 * PrestaShop used to report ONCE per module version, so a quiet store looked
 * "dead" after 30 days even though it was working. The report is now also
 * renewed every 7 days (the server dedupes the content with `unchanged: true`
 * and only touches the heartbeat).
 */
final class CapabilitiesReporterTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const DAY = 86400;

    public function testAVersionNeverReportedIsDue(): void
    {
        self::assertTrue(CapabilitiesReporter::isDue('2.4.0', '', 0, 0, self::NOW));
    }

    public function testAChangedVersionIsDueImmediately(): void
    {
        self::assertTrue(CapabilitiesReporter::isDue('2.4.1', '2.4.0', self::NOW - 60, 0, self::NOW));
    }

    public function testTheSameVersionReportedRecentlyIsNotDue(): void
    {
        self::assertFalse(CapabilitiesReporter::isDue('2.4.0', '2.4.0', self::NOW - 6 * self::DAY, 0, self::NOW));
    }

    public function testTheSameVersionIsRenewedAfterSevenDays(): void
    {
        self::assertTrue(CapabilitiesReporter::isDue('2.4.0', '2.4.0', self::NOW - 7 * self::DAY, 0, self::NOW));
        self::assertTrue(CapabilitiesReporter::isDue('2.4.0', '2.4.0', self::NOW - 40 * self::DAY, 0, self::NOW));
    }

    public function testAnOldReportWithoutTimestampIsRenewed(): void
    {
        // Installs from before this change: version remembered, no timestamp.
        self::assertTrue(CapabilitiesReporter::isDue('2.4.0', '2.4.0', 0, 0, self::NOW));
    }

    public function testAFailedAttemptBacksOffForAnHour(): void
    {
        // The server is not answering: without this, every back-office load or
        // cart save would pay up to a 3 s timeout.
        self::assertFalse(CapabilitiesReporter::isDue('2.4.1', '2.4.0', 0, self::NOW - 600, self::NOW));
        self::assertTrue(CapabilitiesReporter::isDue('2.4.1', '2.4.0', 0, self::NOW - 3601, self::NOW));
    }

    public function testTheModuleRenewsTheReportFromTheStorefrontToo(): void
    {
        // A quiet store may not open the back office for weeks, so the trigger
        // also hangs off a front hook (it runs after the response is sent, never
        // on the buyer's path).
        $src = (string) file_get_contents(__DIR__ . '/../../trusteed.php');

        self::assertMatchesRegularExpression(
            '/function hookActionCartSave\(.*?CapabilitiesReporter::maybeReportAfterResponse\(/s',
            $src
        );
    }
}
