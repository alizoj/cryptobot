<?php

declare(strict_types=1);

namespace common\services\Trading\Fetcher;

use common\services\Trading\Paths;
use RuntimeException;
use Throwable;
use Yii;

/**
 * Pull lifetime OHLCV candles (daily, 4h, 1h + weekly by default) for every
 * HashHedge-listed symbol from the Bybit V5 public API and write them out
 * to common/data/history_prices/<SYMBOL>.json.
 *
 * Subsequent runs are incremental: per interval, if the last stored bar is
 * older than one period, we re-fetch from that bar's timestamp forward and
 * merge by timestamp (the still-forming bar gets overwritten next run). The
 * writeback preserves any sibling top-level keys (notably the 'derivatives'
 * block written by {@see BybitDerivativesFetcher}).
 */
final class BybitPriceFetcher
{
    public const KLINE_LIMIT = 1000;
    public const FALLBACK_LAUNCH_MS = 1_514_764_800_000; // 2018-01-01 UTC
    public const DEFAULT_INTERVALS = ['D', 'W', '4h', '1h'];

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

    public function __construct(
        ?BybitHttp $http = null,
        ?InstrumentsIndex $instruments = null,
    ) {
        $this->http = $http ?? new BybitHttp();
        $this->instruments = $instruments ?? new InstrumentsIndex($this->http, Paths::instrumentsIndex());
    }

    /**
     * @param array{
     *   only?:list<string>|null,
     *   refresh?:bool,
     *   refreshIndex?:bool,
     *   intervals?:list<string>
     * } $opts
     */
    public function run(array $opts = [], ?callable $log = null): FetchResult
    {
        $log ??= static function (string $_): void {};
        $started = microtime(true);

        $onlyList     = $opts['only'] ?? null;
        $refresh      = (bool)($opts['refresh'] ?? false);
        $refreshIndex = (bool)($opts['refreshIndex'] ?? false);
        $intervals    = $opts['intervals'] ?? self::DEFAULT_INTERVALS;

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

        foreach ($symbols as $i => $sym) {
            $prefix  = sprintf('[%3d/%d] %-8s', $i + 1, $total, $sym);
            $outPath = "$outDir/$sym.json";

            $existing = null;
            if (file_exists($outPath)) {
                try {
                    $existing = json_decode((string)file_get_contents($outPath), associative: true, flags: JSON_THROW_ON_ERROR);
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
                    $reordered = $existing;
                    $reordered['history']['intervals'] = $this->sortIntervalsCanonical($existingIntervals);
                    $newJson = JsonFormatter::encode2Space($reordered);
                    if ($newJson !== file_get_contents($outPath)) {
                        file_put_contents($outPath, $newJson);
                        $log("$prefix - migrated structure (no fetch needed)");
                    } else {
                        $log("$prefix - all intervals fresh, skipping");
                    }
                }
                continue;
            }

            $resolved = $this->instruments->resolveSpotOrLinear($sym, $overrides);
            if ($resolved === null) {
                $log("$prefix - no Bybit spot or linear pair found");
                $failures[$sym] = ['error' => 'no bybit pair', 'when' => JsonFormatter::isoUtcNow()];
                $this->saveFailures($failuresFile, $failures);
                continue;
            }
            [$bybitSymbol, $category, $inst] = $resolved;
            $log("$prefix -> $bybitSymbol ($category), fetching: " . implode(',', $needed));

            try {
                $launchTime = (int)($inst['launchTime'] ?? '0') ?: self::FALLBACK_LAUNCH_MS;
                $intervalsMap = $existingIntervals;
                foreach ($needed as $label) {
                    if (isset($incrementalStart[$label])) {
                        $existingRows = $existingIntervals[$label]['rows'] ?? [];
                        $fresh = $this->fetchLifetimeKlines(
                            $category,
                            $bybitSymbol,
                            $incrementalStart[$label],
                            self::INTERVAL_LABELS[$label],
                        );
                        $merged = $this->mergeRowsByTs($existingRows, $fresh);
                        $added  = count($merged) - count($existingRows);
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

        $summary = "prices: $success/$total ok  fetched=$fetched  skipped=$skipped  failures=" . count($failures);
        $log($summary);
        return FetchResult::ok('prices', $elapsed, $summary, [
            'total'    => $total,
            'success'  => $success,
            'fetched'  => $fetched,
            'skipped'  => $skipped,
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

    private function buildIntervalEntry(string $category, string $bybitSymbol, string $label, int $launchMs): array
    {
        $klines = $this->fetchLifetimeKlines($category, $bybitSymbol, $launchMs, self::INTERVAL_LABELS[$label]);
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
