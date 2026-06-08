<?php

declare(strict_types=1);

namespace common\tests\Unit\Services\Trading;

use Codeception\Test\Unit;
use common\services\Trading\DeltaAlignment;
use common\services\Trading\MarketData;

/**
 * Pure-logic tests for the as-of tail window (windowFromRows) that backs the
 * DeltaAlignment screener's tail read. The file-loading wrapper
 * intervalWindowAsOf() is the thin part and is exercised by the live scan.
 */
final class MarketDataTest extends Unit
{
    private const P = 300_000; // 5m in ms

    /** @return list<array<int,float|int>> synthetic ascending [ts,o,h,l,c,v] rows; close = 100 + i */
    private function rows(int $n): array
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = [$i * self::P, 0.0, 0.0, 0.0, 100.0 + $i, 1.0];
        }
        return $rows;
    }

    public function testWindowReturnsLookbackEndingAtAsOf(): void
    {
        $w = MarketData::windowFromRows($this->rows(100), 79 * self::P, 10);

        $this->assertCount(10, $w);
        $this->assertSame(70 * self::P, $w[0]['ts']);
        $this->assertSame(79 * self::P, $w[9]['ts']);
        $this->assertSame(179.0, $w[9]['c']);
    }

    public function testWindowEndpointIsLastBarOpenedAtOrBeforeAsOf(): void
    {
        // as-of mid-bar-79 (bar 79 open, still forming) -> endpoint is bar 79.
        $w = MarketData::windowFromRows($this->rows(100), 79 * self::P + 123, 5);
        $this->assertSame(79 * self::P, $w[4]['ts']);
    }

    public function testWindowClampsToSeriesStart(): void
    {
        $w = MarketData::windowFromRows($this->rows(100), 3 * self::P, 10);

        $this->assertCount(4, $w); // bars 0..3 only
        $this->assertSame(0, $w[0]['ts']);
    }

    public function testWindowEmptyBeforeFirstBarOrNoRows(): void
    {
        $this->assertSame([], MarketData::windowFromRows($this->rows(10), -1, 5));
        $this->assertSame([], MarketData::windowFromRows([], 999, 5));
        $this->assertSame([], MarketData::windowFromRows($this->rows(10), 999, 0));
    }

    public function testTailReadReproducesFullSeriesDelta(): void
    {
        // The whole point: a windowed read must yield the same delta as reading
        // the full series and computing at the same completed bar.
        $rows = $this->rows(200);
        $asOf = 200 * self::P;            // bar 199 fully closed, no later bar

        $w = MarketData::windowFromRows($rows, $asOf, 55);
        $this->assertCount(55, $w);

        $deltaTail = DeltaAlignment::delta(array_column($w, 'c'), count($w) - 1);
        $deltaFull = DeltaAlignment::delta(array_column($rows, 4), 199); // close is col 4 in raw tuples

        $this->assertNotNull($deltaTail);
        $this->assertEqualsWithDelta($deltaFull, $deltaTail, 1e-9);
    }
}
