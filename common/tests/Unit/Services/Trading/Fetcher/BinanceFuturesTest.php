<?php

declare(strict_types=1);

namespace common\tests\Unit\Services\Trading\Fetcher;

use Codeception\Test\Unit;
use common\services\Trading\Fetcher\BinanceFutures;
use common\services\Trading\Fetcher\BybitPriceFetcher;

/**
 * Pure-logic tests for the Binance USD-M fallback source; the networked
 * paths (exchangeInfo, klines, tickers) are not exercised here.
 */
final class BinanceFuturesTest extends Unit
{
    public function testKlineBecomesTheStoredRowShape(): void
    {
        // Real /fapi/v1/klines row: openTime is an int, the rest strings.
        $k = [1775001600000, '2.87900', '2.88800', '2.78500', '2.81900', '2710957.6', 1775087999999, '7713284.853800', 36109, '1438169.7', '4093800.631300', '0'];
        $this->assertSame(
            ['1775001600000', '2.87900', '2.88800', '2.78500', '2.81900', '2710957.6', '7713284.853800'],
            BinanceFutures::toRow($k),
            '[ts, o, h, l, c, volume, turnover=quoteVolume] — the same columns Bybit rows carry',
        );
    }

    public function testEveryFetcherIntervalHasABinanceEquivalent(): void
    {
        // The price fetcher may request any of its labels for a Binance-sourced ticker.
        $missing = array_diff(array_keys(BybitPriceFetcher::INTERVAL_LABELS), array_keys(BinanceFutures::INTERVALS));
        $this->assertSame([], array_values($missing));
    }
}
