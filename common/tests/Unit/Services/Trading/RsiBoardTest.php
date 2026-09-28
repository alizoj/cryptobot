<?php

declare(strict_types=1);

namespace common\tests\Unit\Services\Trading;

use Codeception\Test\Unit;
use common\services\Trading\CoinMetrics;
use common\services\Trading\RsiBoard;
use Yii;

/**
 * RsiBoard builds from per-symbol history files in a temp dir and writes the
 * board to a temp state dir; live tickers are stubbed. Nothing touches the
 * real data or the network.
 */
final class RsiBoardTest extends Unit
{
    private const DAY = 86_400_000;
    private const T0  = 1_700_006_400_000; // 2023-11-15 00:00 UTC (day-aligned)

    private string $dir;
    private array $savedParams;

    protected function _before(): void
    {
        $this->dir = sys_get_temp_dir() . '/rsi_board_test_' . uniqid();
        mkdir($this->dir . '/history', 0775, true);
        $this->savedParams = Yii::$app->params['trading'] ?? [];
        Yii::$app->params['trading'] = [
            'historyDir'  => $this->dir . '/history',
            'stateDir'    => $this->dir . '/state',
            'symbolsFile' => $this->dir . '/symbols.json',   // absent unless a test writes it → no filter
        ] + $this->savedParams;
    }

    protected function _after(): void
    {
        Yii::$app->params['trading'] = $this->savedParams;
        foreach ([...(glob($this->dir . '/*/*') ?: []), $this->dir . '/symbols.json'] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/history');
        @rmdir($this->dir . '/state');
        @rmdir($this->dir);
    }

    public function testLoadIsNullBeforeFirstBuild(): void
    {
        $this->assertNull($this->board()->load());
    }

    public function testBuildWritesRowsForEachSymbol(): void
    {
        $this->writeSymbol('AAA', 30, 1.0);    // steadily rising
        $this->writeSymbol('BBB', 30, -1.0);   // steadily falling
        $this->writeSymbol('NEW', 5, 1.0);     // too little history for RSI(14)

        $sum = $this->board()->build();
        $this->assertSame(['symbols' => 2, 'computed' => 3, 'reused' => 0, 'live' => 0], array_diff_key($sum, ['seconds' => 0]));

        $board = $this->board()->load();
        $bySym = array_column($board['rows'], null, 'symbol');
        $this->assertSame(['AAA', 'BBB'], array_keys($bySym));
        $this->assertSame(100.0, $bySym['AAA']['rsi']['1d']);
        $this->assertSame(0.0, $bySym['BBB']['rsi']['1d']);
        $this->assertNull($bySym['AAA']['rsi']['15m'], 'missing interval yields null, not an error');
        $this->assertSame(129.0, $bySym['AAA']['price']);
        $this->assertFalse($bySym['AAA']['live']);
        $this->assertNull($board['prices_ts']);
        $this->assertSame(self::T0 + 29 * self::DAY, $board['data_ts']);
    }

    public function testRebuildOnlyRecomputesChangedFiles(): void
    {
        $this->writeSymbol('AAA', 30, 1.0);
        $this->writeSymbol('BBB', 30, -1.0);
        $this->writeSymbol('NEW', 5, 1.0);
        $this->board()->build();

        $sum = $this->board()->build();
        $this->assertSame(0, $sum['computed'], 'nothing changed — every row and the skip list reused');
        $this->assertSame(2, $sum['reused']);

        $this->writeSymbol('BBB', 31, -1.0);
        touch($this->dir . '/history/BBB.json', time() + 5);
        $sum = $this->board()->build();
        $this->assertSame(1, $sum['computed']);
        $this->assertSame(1, $sum['reused']);

        $sum = $this->board()->build(force: true);
        $this->assertSame(3, $sum['computed']);
    }

    public function testRemovedSymbolDropsOffTheBoard(): void
    {
        $this->writeSymbol('AAA', 30, 1.0);
        $this->writeSymbol('BBB', 30, 1.0);
        $this->board()->build();

        unlink($this->dir . '/history/BBB.json');
        $this->board()->build();
        $this->assertSame(['AAA'], array_column($this->board()->load()['rows'], 'symbol'));
    }

    public function testTickersDroppedFromMasterListLeaveTheBoard(): void
    {
        $this->writeSymbol('AAA', 30, 1.0);
        $this->writeSymbol('OLD', 30, 1.0);   // history file still on disk, ticker delisted from the list
        file_put_contents($this->dir . '/symbols.json', json_encode(['symbols' => ['AAA', 'NEWCOIN']]));

        $this->board()->build();
        $this->assertSame(['AAA'], array_column($this->board()->load()['rows'], 'symbol'));
    }

    public function testLivePriceReplacesTheFormingBarClose(): void
    {
        // The stored daily bar for "today" (the last one) was captured early in
        // the day; the live price has moved since.
        $closes = $this->writeSymbol('AAA', 60, 0.0, 'AAAUSDT', 'spot', wobble: true);
        $now = self::T0 + 59 * self::DAY + 15 * 3_600_000;   // 15:00 on the last bar's day
        $board = $this->board(['spot:AAAUSDT' => ['price' => 90.0, 'chg24h' => -4.2]]);

        $sum = $board->build(nowMs: $now);
        $row = $board->load()['rows'][0];

        $this->assertSame(1, $sum['live']);
        $this->assertTrue($row['live']);
        $this->assertSame(90.0, $row['price']);
        $this->assertSame(-4.2, $row['chg24h'], 'rolling 24h change comes from the ticker');
        $expected = $closes;
        $expected[count($expected) - 1] = 90.0;
        $this->assertSame(round(CoinMetrics::calcRSI($expected), 1), $row['rsi']['1d']);
        $this->assertNotSame(round(CoinMetrics::calcRSI($closes), 1), $row['rsi']['1d'], 'stale stored close must not be used');
        $this->assertSame($now, $board->load()['prices_ts']);
    }

    public function testLivePriceOpensTheNextBarWhenStoredBarHasClosed(): void
    {
        // Fetcher hasn't picked up the new day yet: the stored last bar is
        // yesterday's (closed); the live price belongs to today's bar.
        $closes = $this->writeSymbol('AAA', 60, 0.0, 'AAAUSDT', 'spot', wobble: true);
        $now = self::T0 + 60 * self::DAY + 3_600_000;   // 01:00 the day after the last bar
        $board = $this->board(['spot:AAAUSDT' => ['price' => 90.0, 'chg24h' => null]]);

        $board->build(nowMs: $now);
        $row = $board->load()['rows'][0];

        $this->assertSame(round(CoinMetrics::calcRSI([...$closes, 90.0]), 1), $row['rsi']['1d']);
        $this->assertEqualsWithDelta((90.0 / $closes[58] - 1) * 100, $row['chg24h'], 1e-9, 'no ticker change → vs previous daily close');
        // The chart's day candle rolls over too, opening at the stored close.
        $this->assertSame(
            ['ts' => self::T0 + 60 * self::DAY, 'o' => $closes[59], 'h' => max($closes[59], 90.0), 'l' => min($closes[59], 90.0), 'c' => 90.0, 'at' => $now],
            $row['day'],
        );
    }

    public function testDayCandleTakesRangeFromFifteenMinuteBarsAndLivePrice(): void
    {
        // Daily candle frozen at its first fetch (00:05): o=100 h=101 l=99 c=100.5.
        // The 15m bars since then went up to 110 and down to 95, last close 108.
        $day = self::T0 + 19 * self::DAY;
        $daily = [];
        for ($i = 0; $i < 19; $i++) {
            $daily[] = [self::T0 + $i * self::DAY, 100, 100, 100, 100];
        }
        $daily[] = [$day, 100, 101, 99, 100.5];
        $m15 = [];
        foreach ([[102, 99, 101], [110, 100, 104], [104, 95, 97], [109, 97, 108]] as $k => [$h, $l, $c]) {
            $m15[] = [$day + $k * 900_000, 100, $h, $l, $c];
        }
        $this->writeRecord('AAA', ['D' => $daily, '15m' => $m15], 'AAAUSDT', 'spot');
        $lastM15 = $day + 3 * 900_000;

        // No live quote: the 15m bars alone fix the range and close.
        $b = $this->board();
        $b->build(nowMs: $day + 3_600_000);
        $this->assertSame(
            ['ts' => $day, 'o' => 100.0, 'h' => 110.0, 'l' => 95.0, 'c' => 108.0, 'at' => $lastM15],
            RsiBoard::liveDays($b->load(), ['AAA'])['AAA'],
        );

        // Live quote above the range: new high and close.
        $now = $day + 3 * 3_600_000;
        $b = $this->board(['spot:AAAUSDT' => ['price' => 112.0, 'chg24h' => 1.0]]);
        $b->build(nowMs: $now);
        $this->assertSame(
            ['ts' => $day, 'o' => 100.0, 'h' => 112.0, 'l' => 95.0, 'c' => 112.0, 'at' => $now],
            RsiBoard::liveDays($b->load(), ['AAA', 'MISSING'])['AAA'],
        );
        $this->assertSame([], RsiBoard::liveDays(null, ['AAA']), 'no board yet → nothing to patch');
    }

    public function testWithLiveDayPatchesChartCandles(): void
    {
        $c = static fn(int $ts, float $o, float $h, float $l, float $cl): array => ['ts' => $ts, 'o' => $o, 'h' => $h, 'l' => $l, 'c' => $cl, 'v' => 5.0];
        $candles = [$c(1000, 9, 10, 8, 9.5), $c(2000, 9.5, 10, 9, 9.8)];

        $same = RsiBoard::withLiveDay($candles, ['ts' => 2000, 'o' => 1, 'h' => 12, 'l' => 9.2, 'c' => 11, 'at' => 1]);
        $this->assertSame([$candles[0], ['ts' => 2000, 'o' => 9.5, 'h' => 12.0, 'l' => 9.0, 'c' => 11.0, 'v' => 5.0]], $same, 'stored open and volume kept, range merged');

        $next = RsiBoard::withLiveDay($candles, ['ts' => 3000, 'o' => 9.8, 'h' => 10.1, 'l' => 9.8, 'c' => 10.1, 'at' => 1]);
        $this->assertCount(3, $next);
        $this->assertSame(['ts' => 3000, 'o' => 9.8, 'h' => 10.1, 'l' => 9.8, 'c' => 10.1, 'v' => 0.0], $next[2]);

        $this->assertSame($candles, RsiBoard::withLiveDay($candles, ['ts' => 1000, 'o' => 1, 'h' => 99, 'l' => 1, 'c' => 50, 'at' => 1]), 'older board day → file wins');
        $this->assertSame($candles, RsiBoard::withLiveDay($candles, null));
        $this->assertSame([], RsiBoard::withLiveDay([], ['ts' => 1, 'o' => 1, 'h' => 1, 'l' => 1, 'c' => 1, 'at' => 1]));
    }

    public function testTickerFailureFallsBackToStoredCloses(): void
    {
        $closes = $this->writeSymbol('AAA', 60, 0.0, 'AAAUSDT', 'spot', wobble: true);
        $board = new RsiBoard(static function (array $_): array {
            throw new \RuntimeException('network down');
        });

        $sum = $board->build(nowMs: self::T0 + 59 * self::DAY + 3_600_000);
        $b = $board->load();

        $this->assertSame(0, $sum['live']);
        $this->assertNull($b['prices_ts']);
        $this->assertFalse($b['rows'][0]['live']);
        $this->assertSame(end($closes), $b['rows'][0]['price']);
        $this->assertSame(round(CoinMetrics::calcRSI($closes), 1), $b['rows'][0]['rsi']['1d']);
    }

    public function testRsiStateSplitMatchesFullSeries(): void
    {
        $closes = [];
        for ($i = 0; $i < 80; $i++) {
            $closes[] = 100 + 10 * sin($i / 3) + $i * 0.1;
        }
        $state = CoinMetrics::rsiState(array_slice($closes, 0, -1));
        $this->assertEqualsWithDelta(CoinMetrics::calcRSI($closes), CoinMetrics::rsiWithClose($state, end($closes)), 1e-9);
    }

    /** @param array<string, array{price:float, chg24h:?float}> $quotes */
    private function board(array $quotes = []): RsiBoard
    {
        return new RsiBoard(static fn(array $_): array => $quotes);
    }

    /**
     * Daily-only history: $n bars, close = 100 + i * $step (+ a zig-zag when
     * $wobble, so RSI isn't pinned at 0/100).
     *
     * @return list<float> the closes written
     */
    private function writeSymbol(string $sym, int $n, float $step, ?string $bybit = null, ?string $category = null, bool $wobble = false): array
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $c = 100 + $i * $step + ($wobble ? 5 * sin($i * 1.7) : 0);
            $rows[] = [(string)(self::T0 + $i * self::DAY), (string)$c, (string)$c, (string)$c, (string)$c, '1000', '0'];
        }
        $this->writeRecord($sym, ['D' => $rows], $bybit, $category);
        // Round-trip through the same string form the board reads back.
        return array_map(static fn(array $r): float => (float)$r[4], $rows);
    }

    /** @param array<string, list<list<mixed>>> $intervals label => [ts, o, h, l, c(, v)] rows */
    private function writeRecord(string $sym, array $intervals, ?string $bybit = null, ?string $category = null): void
    {
        $rec = ['history' => ['intervals' => []]];
        foreach ($intervals as $label => $rows) {
            $rec['history']['intervals'][$label] = [
                'rows' => array_map(static fn(array $r): array => array_map('strval', $r + [5 => 1000, 6 => 0]), $rows),
            ];
        }
        if ($bybit !== null) {
            $rec['bybit_symbol']   = $bybit;
            $rec['bybit_category'] = $category;
        }
        file_put_contents("{$this->dir}/history/{$sym}.json", json_encode($rec));
    }
}
