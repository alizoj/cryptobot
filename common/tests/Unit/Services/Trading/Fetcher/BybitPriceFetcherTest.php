<?php

declare(strict_types=1);

namespace common\tests\Unit\Services\Trading\Fetcher;

use Codeception\Test\Unit;
use common\services\Trading\Fetcher\BybitPriceFetcher;

/**
 * Pure-logic tests for the sub-hourly recent-window backfill. The networked
 * fetch path is not exercised here — only backfillStartMs(), which decides
 * where a from-scratch fetch starts per interval.
 */
final class BybitPriceFetcherTest extends Unit
{
    private const LAUNCH_2018 = 1_514_764_800_000; // 2018-01-01 UTC
    private const NOW         = 1_700_000_000_000; // 2023-11-14 UTC

    public function testHourlyAndLongerBackfillFromLaunch(): void
    {
        foreach (['D', 'W', '4h', '2h', '1h'] as $label) {
            $this->assertSame(
                self::LAUNCH_2018,
                BybitPriceFetcher::backfillStartMs($label, self::LAUNCH_2018, self::NOW),
                "$label should fetch full lifetime history",
            );
        }
    }

    public function testSubHourlyUsesRecentWindow(): void
    {
        $win5m  = BybitPriceFetcher::SHORT_BACKFILL_BARS * 5 * 60_000;
        $win30m = BybitPriceFetcher::SHORT_BACKFILL_BARS * 30 * 60_000;

        $this->assertSame(self::NOW - $win5m, BybitPriceFetcher::backfillStartMs('5m', self::LAUNCH_2018, self::NOW));
        $this->assertSame(self::NOW - $win30m, BybitPriceFetcher::backfillStartMs('30m', self::LAUNCH_2018, self::NOW));
        // The window start must be well after a 2018 launch (the whole point).
        $this->assertGreaterThan(self::LAUNCH_2018, BybitPriceFetcher::backfillStartMs('5m', self::LAUNCH_2018, self::NOW));
    }

    public function testWindowClampsToLaunchForNewListings(): void
    {
        // A coin listed a day ago: the recent window predates launch -> clamp.
        $launch = self::NOW - 86_400_000;
        $this->assertSame($launch, BybitPriceFetcher::backfillStartMs('5m', $launch, self::NOW));
    }

    public function testRecentWindowFitsThePageCap(): void
    {
        // Invariant: a windowed sub-hourly backfill must fit inside the 50-page
        // KLINE safety cap in fetchLifetimeKlines, or it could never reach now.
        $this->assertLessThanOrEqual(50 * BybitPriceFetcher::KLINE_LIMIT, BybitPriceFetcher::SHORT_BACKFILL_BARS);
    }
}
