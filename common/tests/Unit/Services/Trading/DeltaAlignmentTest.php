<?php

declare(strict_types=1);

namespace common\tests\Unit\Services\Trading;

use Codeception\Test\Unit;
use common\services\Trading\DeltaAlignment;

/**
 * Pure-logic tests for the DELTA_ALIGNMENT_SHORT_V1 detector. Covers the SMA15/
 * SMA50 delta math and the strict (−2, 0) alignment gate; the file-loading
 * evaluate() glue is exercised by the live scan, not here.
 */
final class DeltaAlignmentTest extends Unit
{
    public function testDeltaMatchesFormula(): void
    {
        // 35 closes at 100 then 15 at 101: over the 50-bar window ending at idx 49
        // SMA50 = (35*100 + 15*101)/50 = 100.3, SMA15 = 101.
        $closes = array_merge(array_fill(0, 35, 100.0), array_fill(0, 15, 101.0));
        $expected = (101.0 - 100.3) / 100.3 * 100.0;

        $this->assertEqualsWithDelta($expected, DeltaAlignment::delta($closes, 49), 1e-9);
    }

    public function testDeltaSignFollowsFastVsSlow(): void
    {
        // SMA15 below SMA50 -> negative delta (the bearish alignment we screen for).
        $closes = array_merge(array_fill(0, 35, 100.0), array_fill(0, 15, 99.0));
        $this->assertLessThan(0.0, DeltaAlignment::delta($closes, 49));
    }

    public function testDeltaNullWhenInsufficientHistory(): void
    {
        $closes = array_fill(0, 50, 100.0);
        $this->assertNull(DeltaAlignment::delta($closes, 48));        // only 49 closes up to idx 48
        $this->assertNotNull(DeltaAlignment::delta($closes, 49));     // exactly 50 -> ok
    }

    public function testActiveWhenAllTimeframesInBand(): void
    {
        $out = DeltaAlignment::fromDeltas(['5m' => -0.5, '30m' => -1.0, '1h' => -1.9, '2h' => -0.1]);

        $this->assertTrue($out['active']);
        $this->assertSame('DELTA_ALIGNMENT_SHORT_V1', $out['signal']);
        $this->assertSame('SHORT', $out['bias']);
        $this->assertSame(61.9, $out['short_probability']);
        $this->assertSame(38.1, $out['long_probability']);
        $this->assertSame(-1.9, $out['deltas']['1h']);
    }

    public function testInactiveWhenAnyTimeframeOutOfBand(): void
    {
        // 2h is positive -> not a bearish alignment.
        $out = DeltaAlignment::fromDeltas(['5m' => -0.5, '30m' => -1.0, '1h' => -1.0, '2h' => 0.3]);

        $this->assertFalse($out['active']);
        $this->assertArrayNotHasKey('bias', $out);
        $this->assertArrayNotHasKey('short_probability', $out);
    }

    public function testBoundsAreStrictAtZeroAndMinusTwo(): void
    {
        // Exactly 0 and exactly −2 are both inactive (strict inequalities).
        $atZero  = DeltaAlignment::fromDeltas(['5m' => -1.0, '30m' => -1.0, '1h' => -1.0, '2h' => 0.0]);
        $atMinus2 = DeltaAlignment::fromDeltas(['5m' => -2.0, '30m' => -1.0, '1h' => -1.0, '2h' => -1.0]);

        $this->assertFalse($atZero['active']);
        $this->assertFalse($atMinus2['active']);
    }

    public function testInactiveWhenAnyTimeframeMissing(): void
    {
        // Null delta (no data on a timeframe) forces the signal inactive.
        $out = DeltaAlignment::fromDeltas(['5m' => -0.5, '30m' => null, '1h' => -1.0, '2h' => -1.0]);

        $this->assertFalse($out['active']);
        $this->assertNull($out['deltas']['30m']);
    }
}
