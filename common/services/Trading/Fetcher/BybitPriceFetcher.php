<?php

declare(strict_types=1);

namespace common\services\Trading\Fetcher;

use common\services\Trading\Paths;
use RuntimeException;
use Throwable;
use Yii;

/**
 * Pull lifetime OHLCV candles (daily, weekly, 4h, 2h, 1h, 30m, 15m, 5m by
 * default) for every HashHedge-listed symbol from the Bybit V5 public API and
 * write them out to common/data/history_prices/<SYMBOL>.json. Tickers Bybit
 * doesn't list fall back to Binance USD-M futures ({@see BinanceFutures}),
 * stored in the same row shape with bybit_category = 'binance_um'. The 2h/30m/15m/5m
 * intervals are kept for short multi-timeframe indicators (15m feeds the RSI
 * heatmap's lowest timeframe).
 *
 * Lifetime history is fetched for 1h-and-longer intervals; sub-hourly ones
 * (30m/15m/5m) backfill only a recent window ({@see backfillStartMs}) — crawling
 * them from a 2018 launch would blow past the KLINE page cap and never reach
 * the present.
 *
 * Subsequent runs are incremental: per interval, if the last stored bar is
 * older than one period, we re-fetch from that bar's timestamp forward and
 * merge by timestamp (the still-forming bar gets overwritten next run).
 * Sub-hourly series are then capped back to the same recent window
 * ({@see retainRows}) so they don't grow without bound. The writeback
 * preserves any sibling top-level keys (notably the 'derivatives' block
 * written by {@see BybitDerivativesFetcher}).
 */
final class BybitPriceFetcher
{
    public const KLINE_LIMIT = 1000;
    public const FALLBACK_LAUNCH_MS = 1_514_764_800_000; // 2018-01-01 UTC
    public const DEFAULT_INTERVALS = ['D', 'W', '4h', '2h', '1h', '30m', '15m', '5m'];

    /**
     * Recent-window size, in bars, for sub-hourly backfills (see
     * {@see backfillStartMs}) and the retention cap applied after each
     * incremental merge (see {@see retainRows}). The longest sub-hourly
     * lookback is the RSI heatmap's 250 × 15m; this keeps generous margin while
     * bounding the initial fetch to ~6 KLINE pages (must stay <= 50 *
     * KLINE_LIMIT, the fetchLifetimeKlines page cap).
     */
    public const SHORT_BACKFILL_BARS = 6000;

    public const INTERVAL_LABELS = [
        'M'   => 'M',
        'W'   => 'W',
        'D'   => 'D',
        '12h' => '720',
        '6h'  => '360',
        '4h'  => '240',
        '2h'  => '120',
        '1h'  => '60',
        '30m' => '30',
        '15m' => '15',
        '5m'  => '5',
        '3m'  => '3',
        '1m'  => '1',
    ];

    /** Nominal bar length per label, used to detect stale stored data. */
    public const INTERVAL_PERIOD_MS = [
        'M'   => 30 * 86_400_000,
        'W'   => 7  * 86_400_000,
        'D'   => 86_400_000,
        '12h' => 12 * 3_600_000,
        '6h'  => 6  * 3_600_000,
        '4h'  => 4  * 3_600_000,
        '2h'  => 2  * 3_600_000,
        '1h'  => 3_600_000,
        '30m' => 30 * 60_000,
        '15m' => 15 * 60_000,
        '5m'  => 5  * 60_000,
        '3m'  => 3  * 60_000,
        '1m'  => 60_000,
    ];

    private BybitHttp $http;
    private InstrumentsIndex $instruments;
    private BinanceFutures $binance;

    public function __construct(
        ?BybitHttp $http = null,
        ?InstrumentsIndex $instruments = null,
        ?BinanceFutures $binance = null,
    ) {
        $this->http = $http ?? new BybitHttp();
        $this->instruments = $instruments ?? new InstrumentsIndex($this->http, Paths::instrumentsIndex());
        $this->binance = $binance ?? new BinanceFutures(Paths::binanceIndex());
    }

    /**
     * @param array{
     *   only?:list<string>|null,
     *   refresh?:bool,
     *   refreshIndex?:bool,
     *   intervals?:list<string>,
     *   maxNewSymbols?:int|null
     * } $opts maxNewSymbols caps the from-scratch backfills per run (symbols
     *   with no history file yet); the rest are deferred to later runs
     */
    public function run(array $opts = [], ?callable $log = null): FetchResult
    {
        $log ??= static function (string $_): void {};
        $started = microtime(true);

        $onlyList     = $opts['only'] ?? null;
        $refresh      = (bool)($opts['refresh'] ?? false);
        $refreshIndex = (bool)($opts['refreshIndex'] ?? false);
        $intervals    = $opts['intervals'] ?? self::DEFAULT_INTERVALS;
        $maxNew       = isset($opts['maxNewSymbols']) ? (int)$opts['maxNewSymbols'] : null;

        foreach ($intervals as $label) {
            if (!array_key_exists($label, self::INTERVAL_LABELS)) {
                throw new RuntimeException("Unknown interval '$label'");
            }
        }

        $dataDir          = Yii::getAlias('@common/data');
        $symbolsFile      = $dataDir . DIRECTORY_SEPARATOR . 'hashhedge_symbols.json';
        $overridesFile    = $dataDir . DIRECTORY_SEPARATOR . 'bybit_symbol_overrides.json';
        $outDir           = Paths::historyPricesDir();
        $failuresFile     = $outDir . DIRECTORY_SEPARATOR . '_failures.json';

        @mkdir($outDir, 0775, recursive: true);

        $masterSymbols = $this->loadSymbols($symbolsFile);
        $overrides = $this->loadOverrides($overridesFile);
        $symbols = $masterSymbols;
        if ($onlyList !== null) {
            $upper = array_map('strtoupper', $onlyList);
            $symbols = array_values(array_filter($symbols, static fn($s) => in_array($s, $upper, true)));
        }
        $total = count($symbols);
        $log("Fetching $total HashHedge symbols from Bybit V5  intervals=" . implode(',', $intervals));

        $this->instruments->ensureLoaded($refreshIndex, $log);

        $failures = $this->loadFailures($failuresFile);
        // Drop failure entries for symbols no longer on the master list.
        $failures = array_intersect_key($failures, array_flip(array_map('strtoupper', $masterSymbols)));
        $success = 0;
        $fetched = 0;
        $skipped = 0;
        $newStarted = 0;
        $deferred = 0;

        foreach ($symbols as $i => $sym) {
            $prefix  = sprintf('[%3d/%d] %-8s', $i + 1, $total, $sym);
            $outPath = "$outDir/$sym.json";

            $existing = null;
            $legacy = false;
            if (file_exists($outPath)) {
                try {
                    $existing = json_decode((string)file_get_contents($outPath), associative: true, flags: JSON_THROW_ON_ERROR);
                    $legacy = isset($existing['history']) && !isset($existing['history']['intervals']);
                    $existing = $this->migrateLegacyHistory($existing);
                } catch (Throwable $e) {
                    $log("$prefix - existing file unreadable ({$e->getMessage()}), refetching");
                    $existing = null;
                }
            }

            $existingIntervals = $existing['history']['intervals'] ?? [];
            $nowMs = (int)(microtime(true) * 1000);
            $needed = [];
            /** @var array<string,int> $incrementalStart label => startMs (re-fetch from this bar forward) */
            $incrementalStart = [];
            foreach ($intervals as $label) {
                $rows = $existingIntervals[$label]['rows'] ?? null;
                if ($refresh || $rows === null || $rows === []) {
                    $needed[] = $label;  // full fetch from instrument launch
                    continue;
                }
                $lastTs = (int)$rows[count($rows) - 1][0];
                // Stale if at least one bar's worth of time has elapsed since
                // lastTs — that means either a new bar has opened or the last
                // bar is now closed and may have updated final values.
                $period = self::INTERVAL_PERIOD_MS[$label] ?? 3_600_000;
                if ($nowMs >= $lastTs + $period) {
                    $needed[] = $label;
                    $incrementalStart[$label] = $lastTs;
                }
            }
            if (empty($needed)) {
                $skipped++;
                $success++;
                if ($existing !== null) {
                    // Rewrite only when the structure actually changes (legacy
                    // single-interval layout, or intervals out of canonical
                    // order). Re-encoding each file just to byte-compare it
                    // (up to 0.25 s per 20 MB file) was ~2/3 of a no-op run.
                    $canonical = $this->sortIntervalsCanonical($existingIntervals);
                    if ($legacy || array_keys($canonical) !== array_keys($existingIntervals)) {
                        $existing['history']['intervals'] = $canonical;
                        file_put_contents($outPath, JsonFormatter::encode2Space($existing));
                        $log("$prefix - migrated structure (no fetch needed)");
                    } else {
                        $log("$prefix - all intervals fresh, skipping");
                    }
                }
                continue;
            }

            $resolved = $this->instruments->resolveSpotOrLinear($sym, $overrides) ?? $this->resolveBinance($sym, $log);
            if ($resolved === null) {
                $log("$prefix - no Bybit spot/linear pair and no Binance USD-M perp found");
                $failures[$sym] = ['error' => 'no bybit or binance pair', 'when' => JsonFormatter::isoUtcNow()];
                $this->saveFailures($failuresFile, $failures);
                continue;
            }
            // A new symbol costs a full lifetime backfill (~100 API calls). Cap
            // them per run so a big list change can't push one queue job past
            // its TTR — the worker would kill it before it reschedules the loop.
            if ($maxNew !== null && $existing === null && !file_exists($outPath)) {
                if ($newStarted >= $maxNew) {
                    $deferred++;
                    $log("$prefix - new symbol, backfill deferred to a later run (max $maxNew per run)");
                    continue;
                }
                $newStarted++;
            }
            [$bybitSymbol, $category, $inst] = $resolved;
            $log("$prefix -> $bybitSymbol ($category), fetching: " . implode(',', $needed));

            try {
                $launchTime = (int)($inst['launchTime'] ?? '0') ?: self::FALLBACK_LAUNCH_MS;
                $intervalsMap = $existingIntervals;
                foreach ($needed as $label) {
                    if (isset($incrementalStart[$label])) {
                        $existingRows = $existingIntervals[$label]['rows'] ?? [];
                        $fresh = $this->fetchKlines(
                            $category,
                            $bybitSymbol,
                            self::incrementalStartMs($label, $incrementalStart[$label], $launchTime, $nowMs),
                            $label,
                        );
                        $merged = $this->mergeRowsByTs($existingRows, $fresh);
                        $added  = count($merged) - count($existingRows);
                        $merged = self::retainRows($label, $merged);
                        $entry = [
                            'schema'        => ['startTime_ms', 'open', 'high', 'low', 'close', 'volume', 'turnover'],
                            'rows'          => $merged,
                            'row_count'     => count($merged),
                            'first_row_utc' => $merged ? JsonFormatter::isoUtcFromMs((int)$merged[0][0]) : null,
                            'last_row_utc'  => $merged ? JsonFormatter::isoUtcFromMs((int)$merged[count($merged) - 1][0]) : null,
                        ];
                        $log("           $label: {$entry['row_count']} candles (+$added new)");
                    } else {
                        $entry = $this->buildIntervalEntry($category, $bybitSymbol, $label, $launchTime);
                        $log("           $label: {$entry['row_count']} candles (full)");
                    }
                    $intervalsMap[$label] = $entry;
                }
                // Preserve any top-level keys we don't own (notably the
                // 'derivatives' block populated by BybitDerivativesFetcher) —
                // otherwise a price refresh wipes the derivatives data on every write.
                $record = $existing ?? [];
                $record['hashhedge_symbol'] = $sym;
                $record['bybit_symbol']     = $bybitSymbol;
                $record['bybit_category']   = $category;
                $record['fetched_at_utc']   = JsonFormatter::isoUtcNow();
                $record['instrument']       = $inst;
                $record['history']          = ['intervals' => $this->sortIntervalsCanonical($intervalsMap)];
                file_put_contents($outPath, JsonFormatter::encode2Space($record));
                $kb = intdiv((int)filesize($outPath), 1024);
                $log("           OK ($kb KB total)");
                $success++;
                $fetched++;
                unset($failures[$sym]);
            } catch (Throwable $e) {
                $err = $e::class . ': ' . $e->getMessage();
                $log("           FAILED: $err");
                $failures[$sym] = [
                    'bybit_symbol' => $bybitSymbol,
                    'category'     => $category,
                    'error'        => $err,
                    'when'         => JsonFormatter::isoUtcNow(),
                ];
                $this->saveFailures($failuresFile, $failures);
            }
        }

        $this->saveFailures($failuresFile, $failures);
        $elapsed = round(microtime(true) - $started, 2);

        $summary = "prices: $success/$total ok  fetched=$fetched  skipped=$skipped"
            . ($deferred > 0 ? "  deferred=$deferred" : '')
            . '  failures=' . count($failures);
        $log($summary);
        return FetchResult::ok('prices', $elapsed, $summary, [
            'total'    => $total,
            'success'  => $success,
            'fetched'  => $fetched,
            'skipped'  => $skipped,
            'deferred' => $deferred,
            'failures' => count($failures),
        ]);
    }

    // ----- internals (lifted verbatim from the script) -----

    private function loadSymbols(string $path): array
    {
        $data = json_decode((string)file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);
        return $data['symbols'];
    }

    private function loadOverrides(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);
        return array_filter($data, static fn($k) => !str_starts_with($k, '_'), ARRAY_FILTER_USE_KEY);
    }

    private function loadFailures(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        return json_decode((string)file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    private function saveFailures(string $path, array $failures): void
    {
        @mkdir(dirname($path), 0775, recursive: true);
        file_put_contents($path, JsonFormatter::encode2Space($failures));
    }

    /**
     * Binance USD-M for a ticker Bybit doesn't list, or null. A failed lookup
     * (network, geo-block) only costs that ticker this run.
     *
     * @return array{0:string, 1:string, 2:array<string,mixed>}|null
     */
    private function resolveBinance(string $sym, callable $log): ?array
    {
        try {
            return $this->binance->resolve($sym);
        } catch (Throwable $e) {
            $log("           Binance USD-M lookup failed: {$e->getMessage()}");
            return null;
        }
    }

    /** Rows of $label from $startMs forward, from whichever exchange the ticker resolved to. */
    private function fetchKlines(string $category, string $symbol, int $startMs, string $label): array
    {
        return $category === BinanceFutures::CATEGORY
            ? $this->binance->klines($symbol, $label, $startMs)
            : $this->fetchLifetimeKlines($category, $symbol, $startMs, self::INTERVAL_LABELS[$label]);
    }

    private function fetchLifetimeKlines(string $category, string $bybitSymbol, int $startMs, string $interval): array
    {
        $allRows = [];
        $cursor  = $startMs;
        $nowMs   = (int)(microtime(true) * 1000);
        $safety  = 0;
        while ($cursor < $nowMs && $safety < 50) {
            $safety++;
            $res = $this->http->get('/v5/market/kline', [
                'category' => $category,
                'symbol'   => $bybitSymbol,
                'interval' => $interval,
                'start'    => $cursor,
                'limit'    => self::KLINE_LIMIT,
            ]);
            $rows = $res['list'] ?? [];
            if (empty($rows)) {
                break;
            }
            usort($rows, static fn($a, $b) => (int)$a[0] <=> (int)$b[0]);
            array_push($allRows, ...$rows);
            $lastTs = (int)$rows[array_key_last($rows)][0];
            if ($lastTs <= $cursor) {
                break;
            }
            $cursor = $lastTs + 1;
            $this->http->throttle();
        }
        $seen = $dedup = [];
        foreach ($allRows as $row) {
            $ts = (int)$row[0];
            if (isset($seen[$ts])) {
                continue;
            }
            $seen[$ts] = true;
            $dedup[] = $row;
        }
        usort($dedup, static fn($a, $b) => (int)$a[0] <=> (int)$b[0]);
        return $dedup;
    }

    /**
     * Merge two kline-row lists keyed by open-time (col 0). Fresh rows
     * overwrite older ones at the same timestamp, so a still-forming bar
     * refreshed on the next run lands with its latest OHLC. Result is
     * ascending by ts.
     *
     * @param list<list<mixed>> $existing
     * @param list<list<mixed>> $fresh
     * @return list<list<mixed>>
     */
    private function mergeRowsByTs(array $existing, array $fresh): array
    {
        $byTs = [];
        foreach ($existing as $r) {
            $byTs[(int)$r[0]] = $r;
        }
        foreach ($fresh as $r) {
            $byTs[(int)$r[0]] = $r;
        }
        ksort($byTs);
        return array_values($byTs);
    }

    /**
     * Start timestamp for a *full* (from-scratch) backfill of $label.
     *
     * Lifetime intervals (1h and longer) start at the instrument launch.
     * Sub-hourly intervals (30m/15m/5m/...) would need far more than the KLINE
     * page cap (see {@see fetchLifetimeKlines}'s $safety guard) to crawl from a
     * 2018 launch to the present, so they start from a recent window of
     * SHORT_BACKFILL_BARS bars instead — enough for the DELTA_ALIGNMENT screener
     * with margin, and bounded to a few API pages. Clamped to $launchMs so a
     * brand-new listing never asks for bars before it existed. Once the first
     * windowed fetch reaches the present, later runs resume from the last
     * stored bar and {@see retainRows} keeps the series at the same window.
     */
    public static function backfillStartMs(string $label, int $launchMs, int $nowMs): int
    {
        $period = self::INTERVAL_PERIOD_MS[$label] ?? 3_600_000;
        if ($period >= 3_600_000) {
            return $launchMs;
        }
        return max($launchMs, $nowMs - self::SHORT_BACKFILL_BARS * $period);
    }

    /**
     * Start timestamp for an *incremental* refresh of $label whose newest
     * stored bar opened at $lastTs: resume from that bar, but never earlier
     * than the recent window a sub-hourly series is capped to. When the stored
     * data is older than that window (the refresh loop was down for weeks),
     * crawling every page since $lastTs would only fetch bars that
     * {@see retainRows} throws away — and past the page cap it would stop short
     * of the present. Lifetime intervals always resume from $lastTs.
     */
    public static function incrementalStartMs(string $label, int $lastTs, int $launchMs, int $nowMs): int
    {
        return max($lastTs, self::backfillStartMs($label, $launchMs, $nowMs));
    }

    /**
     * Cap a merged sub-hourly series to its newest SHORT_BACKFILL_BARS bars;
     * lifetime (1h and longer) series pass through untouched. Without this,
     * incremental merges only ever append — the 5m series alone gains ~290
     * rows a day, and within a few months the per-symbol JSON grew too large
     * to decode inside the web memory limit (/trading/rsi fataled in
     * MarketData::loadSymbol).
     *
     * @param list<list<mixed>> $rows ascending by ts
     * @return list<list<mixed>>
     */
    public static function retainRows(string $label, array $rows): array
    {
        $period = self::INTERVAL_PERIOD_MS[$label] ?? 3_600_000;
        if ($period >= 3_600_000 || count($rows) <= self::SHORT_BACKFILL_BARS) {
            return $rows;
        }
        return array_slice($rows, -self::SHORT_BACKFILL_BARS);
    }

    private function buildIntervalEntry(string $category, string $bybitSymbol, string $label, int $launchMs): array
    {
        $startMs = self::backfillStartMs($label, $launchMs, (int)(microtime(true) * 1000));
        $klines = $this->fetchKlines($category, $bybitSymbol, $startMs, $label);
        return [
            'schema'        => ['startTime_ms', 'open', 'high', 'low', 'close', 'volume', 'turnover'],
            'rows'          => $klines,
            'row_count'     => count($klines),
            'first_row_utc' => $klines ? JsonFormatter::isoUtcFromMs((int)$klines[0][0]) : null,
            'last_row_utc'  => $klines ? JsonFormatter::isoUtcFromMs((int)$klines[array_key_last($klines)][0]) : null,
        ];
    }

    private function migrateLegacyHistory(array $record): array
    {
        if (!isset($record['history']) || isset($record['history']['intervals'])) {
            return $record;
        }
        $hist = $record['history'];
        if (!isset($hist['interval'], $hist['rows'])) {
            return $record;
        }
        $code  = (string)$hist['interval'];
        $label = array_search($code, self::INTERVAL_LABELS, true) ?: $code;
        $record['history'] = [
            'intervals' => [
                $label => [
                    'schema'        => $hist['schema'] ?? ['startTime_ms','open','high','low','close','volume','turnover'],
                    'rows'          => $hist['rows'],
                    'row_count'     => $hist['row_count'] ?? count($hist['rows']),
                    'first_row_utc' => $hist['first_row_utc'] ?? null,
                    'last_row_utc'  => $hist['last_row_utc']  ?? null,
                ],
            ],
        ];
        return $record;
    }

    private function sortIntervalsCanonical(array $map): array
    {
        $sorted = [];
        foreach (array_keys(self::INTERVAL_LABELS) as $label) {
            if (isset($map[$label])) {
                $sorted[$label] = $map[$label];
            }
        }
        foreach ($map as $label => $entry) {
            if (!isset($sorted[$label])) {
                $sorted[$label] = $entry;
            }
        }
        return $sorted;
    }
}
