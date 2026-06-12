<?php

declare(strict_types=1);

namespace common\services\Trading;

use common\services\Trading\Fetcher\BybitHttp;
use common\services\Trading\Fetcher\InstrumentsIndex;
use Throwable;
use Yii;

/**
 * DELTA_ALIGNMENT_SHORT_V1 — multi-timeframe SMA15/SMA50 alignment screener.
 *
 * Per timeframe: delta = (SMA15 - SMA50) / SMA50 * 100, where both SMAs are
 * "live": their newest term is the CURRENT price and the remaining terms are
 * the closes of the last 14 (resp. 49) CLOSED candles. The signal is active
 * when delta sits inside the open interval (-2, 0) on all four timeframes
 * (5m / 30m / 1h / 2h) at once. Historically (n=769) that alignment resolved
 * SHORT 61.9% of the time.
 *
 * Two moving parts, deliberately decoupled:
 *
 *   - The BOARD — per symbol/timeframe sums of the last 14 and 49 closed
 *     closes — only changes when a candle closes. `./yii trading/delta-board`
 *     (or the queue loop, `./yii trading/schedule-delta`) rebuilds it every
 *     5 minutes into trade_state/delta_board.json. The multi-MB
 *     history_prices files are NOT touched: rewriting ~1 GB of JSON every
 *     5 minutes is exactly what this small cache avoids. A timeframe is
 *     refetched only when a new candle has closed since the stored one, so a
 *     steady-state rebuild costs ~1.3 kline calls per symbol.
 *
 *   - The LIVE PRICE moves every tick. The web layer folds it into the stored
 *     sums on each poll (one cached /v5/market/tickers call per category), so
 *     deltas move second-by-second between candle closes without re-reading
 *     any candle data.
 */
final class DeltaAlignment
{
    public const NAME = 'DELTA_ALIGNMENT_SHORT_V1';

    /** Timeframe label => Bybit kline interval code. */
    public const TFS = ['5m' => '5', '30m' => '30', '1h' => '60', '2h' => '120'];

    /** Timeframe label => bar length, ms. */
    public const TF_PERIOD_MS = ['5m' => 300_000, '30m' => 1_800_000, '1h' => 3_600_000, '2h' => 7_200_000];

    public const SMA_FAST = 15;
    public const SMA_SLOW = 50;

    /** Open interval every timeframe's delta must sit in for the signal to fire. */
    public const WINDOW_LO = -2.0;
    public const WINDOW_HI = 0.0;

    /** Historically observed bias of the 769-event sample behind this signal. */
    public const META = [
        'signal_name'       => self::NAME,
        'direction_bias'    => 'SHORT',
        'long_probability'  => 38.1,
        'short_probability' => 61.9,
        'sample_size'       => 769,
    ];

    private BybitHttp $http;
    private InstrumentsIndex $instruments;

    public function __construct(?BybitHttp $http = null, ?InstrumentsIndex $instruments = null)
    {
        $this->http = $http ?? new BybitHttp();
        $this->instruments = $instruments ?? new InstrumentsIndex($this->http, Paths::instrumentsIndex());
    }

    // ----- board (closed-candle sums, rebuilt by the 5-min cron) -----

    /**
     * Rebuild trade_state/delta_board.json over the whole HashHedge universe.
     * Carries forward still-fresh timeframe entries (and whole symbols whose
     * fetch failed transiently) from the previous board.
     *
     * @return array{symbols:int, calls:int, reused:int, failed:int, seconds:float}
     */
    public function rebuildBoard(?callable $log = null): array
    {
        $log ??= static function (string $_): void {};
        $started = microtime(true);

        $dataDir   = Yii::getAlias('@common/data');
        $symbols   = $this->loadJson($dataDir . '/hashhedge_symbols.json')['symbols'] ?? [];
        $overrides = [];
        if (is_file($dataDir . '/bybit_symbol_overrides.json')) {
            $overrides = array_filter(
                $this->loadJson($dataDir . '/bybit_symbol_overrides.json'),
                static fn($k) => !str_starts_with((string)$k, '_'),
                ARRAY_FILTER_USE_KEY,
            );
        }
        $this->instruments->ensureLoaded(false, $log);

        $prevRows = [];
        foreach (($this->loadBoard()['symbols'] ?? []) as $row) {
            $prevRows[$row['symbol']] = $row;
        }

        $total  = count($symbols);
        $out    = [];
        $calls  = 0;
        $reused = 0;
        $failed = 0;
        $log("delta-board: {$total} symbols, tfs " . implode(',', array_keys(self::TFS)));

        foreach ($symbols as $i => $sym) {
            $prefix = sprintf('[%3d/%d] %-8s', $i + 1, $total, $sym);
            $resolved = $this->instruments->resolveSpotOrLinear($sym, $overrides);
            if ($resolved === null) {
                $log("$prefix - no Bybit pair, skipped");
                continue;
            }
            [$bybitSymbol, $category] = $resolved;
            $prev = ($prevRows[$sym]['bybit'] ?? null) === $bybitSymbol ? $prevRows[$sym] : null;

            $nowMs     = (int)(microtime(true) * 1000);
            $tfs       = [];
            $fetched   = [];
            $lastClose = $prev['last_close'] ?? null;
            try {
                foreach (self::TFS as $label => $code) {
                    $period = self::TF_PERIOD_MS[$label];
                    $kept   = $prev['tfs'][$label] ?? null;
                    // The stored entry stays current until the NEXT candle
                    // closes: last_ts is the open of the newest closed bar
                    // (closed at last_ts+period), the bar after closes one
                    // period later.
                    if ($kept !== null && $nowMs < (int)$kept['last_ts'] + 2 * $period) {
                        $tfs[$label] = $kept;
                        $reused++;
                        continue;
                    }
                    $win = $this->fetchClosedCloses($category, $bybitSymbol, $code, $period, $nowMs);
                    $calls++;
                    $fetched[] = $label;
                    $closes = $win['closes'];
                    $tfs[$label] = [
                        's14'     => array_sum(array_slice($closes, -(self::SMA_FAST - 1))),
                        's49'     => array_sum($closes),
                        'n'       => count($closes),
                        'last_ts' => $win['last_ts'],
                    ];
                    if ($label === '5m' && $closes !== []) {
                        $lastClose = $closes[count($closes) - 1];
                    }
                    $this->http->throttle();
                }
            } catch (Throwable $e) {
                $failed++;
                if ($prev !== null) {
                    $out[] = $prev;   // stale beats absent; the next run retries
                    $log("$prefix - FAILED ({$e->getMessage()}), kept previous entry");
                } else {
                    $log("$prefix - FAILED ({$e->getMessage()}), no previous entry to keep");
                }
                continue;
            }

            $out[] = [
                'symbol'     => $sym,
                'bybit'      => $bybitSymbol,
                'category'   => $category,
                'last_close' => $lastClose,
                'tfs'        => $tfs,
            ];
            if ($fetched !== []) {
                $log("$prefix -> $bybitSymbol ($category) fetched " . implode(',', $fetched));
            }
        }

        self::atomicWrite(Paths::deltaBoardFile(), json_encode([
            'signal'  => self::NAME,
            'updated' => time(),
            'symbols' => $out,
        ]));

        return [
            'symbols' => count($out),
            'calls'   => $calls,
            'reused'  => $reused,
            'failed'  => $failed,
            'seconds' => round(microtime(true) - $started, 2),
        ];
    }

    /** @return array{signal:string, updated:int, symbols:list<array<string,mixed>>}|null */
    public function loadBoard(): ?array
    {
        $path = Paths::deltaBoardFile();
        if (!is_file($path)) {
            return null;
        }
        $board = json_decode((string)@file_get_contents($path), true);
        return is_array($board) && isset($board['symbols']) ? $board : null;
    }

    /**
     * Closes of the last (up to) 49 CLOSED candles, ascending, plus the open
     * ts of the newest closed one. The kline endpoint without `start` returns
     * the latest bars including the still-forming one — bars whose close time
     * is in the future are dropped.
     *
     * @return array{closes: list<float>, last_ts: int}
     */
    private function fetchClosedCloses(string $category, string $bybitSymbol, string $intervalCode, int $periodMs, int $nowMs): array
    {
        $res = $this->http->get('/v5/market/kline', [
            'category' => $category,
            'symbol'   => $bybitSymbol,
            'interval' => $intervalCode,
            'limit'    => self::SMA_SLOW + 1,   // 49 closed + the forming bar, with margin
        ]);
        $rows = $res['list'] ?? [];
        usort($rows, static fn($a, $b) => (int)$a[0] <=> (int)$b[0]);

        $closes = [];
        $lastTs = 0;
        foreach ($rows as $r) {
            $ts = (int)$r[0];
            if ($ts + $periodMs <= $nowMs) {
                $closes[] = (float)$r[4];
                $lastTs   = $ts;
            }
        }
        return [
            'closes'  => array_slice($closes, -(self::SMA_SLOW - 1)),
            'last_ts' => $lastTs,
        ];
    }

    // ----- live layer (per-poll) -----

    /**
     * lastPrice per Bybit symbol for every category the board uses — one
     * /v5/market/tickers call per category, disk-cached for $ttlMs so a
     * 1-second browser poll (or several open tabs) costs at most ~one
     * upstream round-trip per second across all clients.
     *
     * @return array<string, float> bybitSymbol => lastPrice
     */
    public function lastPrices(array $board, int $ttlMs = 900): array
    {
        $want = [];
        $cats = [];
        foreach ($board['symbols'] as $row) {
            $want[$row['bybit']]  = true;
            $cats[$row['category']] = true;
        }
        $cats = array_keys($cats);

        $cacheFile = Paths::deltaTickersCacheFile();
        $nowMs = (int)(microtime(true) * 1000);
        if (is_file($cacheFile)) {
            $c = json_decode((string)@file_get_contents($cacheFile), true);
            if (
                is_array($c)
                && $nowMs - (int)($c['ts'] ?? 0) < $ttlMs
                && array_diff($cats, $c['cats'] ?? []) === []
            ) {
                return array_map('floatval', $c['prices'] ?? []);
            }
        }

        $prices = [];
        foreach ($cats as $i => $cat) {
            try {
                if ($i > 0) {
                    $this->http->throttle();
                }
                $res = $this->http->get('/v5/market/tickers', ['category' => $cat]);
                foreach (($res['list'] ?? []) as $t) {
                    $s = (string)($t['symbol'] ?? '');
                    if (isset($want[$s]) && isset($t['lastPrice'])) {
                        $prices[$s] = (float)$t['lastPrice'];
                    }
                }
            } catch (Throwable) {
                // Missing categories degrade gracefully: those rows fall back
                // to their last 5m close until the next poll succeeds.
            }
        }
        if ($prices !== []) {
            self::atomicWrite($cacheFile, json_encode(['ts' => $nowMs, 'cats' => $cats, 'prices' => $prices]));
        }
        return $prices;
    }

    /**
     * Fold live prices into the stored sums: one row per symbol with the four
     * deltas, which of them sit in the window, and the final active flag.
     * A timeframe needs the full 49 closed closes — otherwise its delta is
     * null and the signal cannot fire for that symbol.
     *
     * @param array<string,float> $prices bybitSymbol => lastPrice
     * @return list<array{symbol:string, bybit:string, price:float|null, live:bool,
     *                    deltas:array<string,float|null>, met:array<string,bool>,
     *                    met_count:int, active:bool}>
     */
    public function computeRows(array $board, array $prices): array
    {
        $rows = [];
        foreach ($board['symbols'] as $b) {
            $live  = isset($prices[$b['bybit']]) && $prices[$b['bybit']] > 0;
            $price = $live ? $prices[$b['bybit']] : (isset($b['last_close']) ? (float)$b['last_close'] : null);

            $deltas   = [];
            $met      = [];
            $metCount = 0;
            $complete = true;
            foreach (self::TFS as $label => $_) {
                $tf = $b['tfs'][$label] ?? null;
                $d  = null;
                if ($price !== null && $tf !== null && (int)$tf['n'] >= self::SMA_SLOW - 1) {
                    $d = self::delta((float)$tf['s14'], (float)$tf['s49'], $price);
                }
                $deltas[$label] = $d !== null ? round($d, 4) : null;
                $ok = $d !== null && $d > self::WINDOW_LO && $d < self::WINDOW_HI;
                $met[$label] = $ok;
                $metCount += $ok ? 1 : 0;
                $complete = $complete && $d !== null;
            }

            $rows[] = [
                'symbol'    => $b['symbol'],
                'bybit'     => $b['bybit'],
                'price'     => $price,
                'live'      => $live,
                'deltas'    => $deltas,
                'met'       => $met,
                'met_count' => $metCount,
                'active'    => $complete && $metCount === count(self::TFS),
            ];
        }
        return $rows;
    }

    /**
     * delta = (SMA15/SMA50 - 1) * 100, both SMAs seeded with the live price:
     * SMA15 = (price + Σ last 14 closed closes) / 15, SMA50 likewise over 49.
     */
    public static function delta(float $s14, float $s49, float $price): ?float
    {
        $sma50 = ($s49 + $price) / self::SMA_SLOW;
        if ($sma50 <= 0.0) {
            return null;
        }
        $sma15 = ($s14 + $price) / self::SMA_FAST;
        return ($sma15 / $sma50 - 1.0) * 100.0;
    }

    /** The bot-facing response shape from the signal spec, for one computed row. */
    public static function specResponse(?array $row): array
    {
        if ($row === null || !$row['active']) {
            return ['signal' => self::NAME, 'active' => false];
        }
        return [
            'signal'            => self::NAME,
            'active'            => true,
            'bias'              => self::META['direction_bias'],
            'short_probability' => self::META['short_probability'],
            'long_probability'  => self::META['long_probability'],
        ];
    }

    // ----- helpers -----

    private function loadJson(string $path): array
    {
        return json_decode((string)file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /** Write via tmp+rename so concurrent readers never see a torn file. */
    private static function atomicWrite(string $path, string $json): void
    {
        @mkdir(dirname($path), 0775, recursive: true);
        $tmp = $path . '.tmp';
        file_put_contents($tmp, $json);
        @rename($tmp, $path);
    }
}
