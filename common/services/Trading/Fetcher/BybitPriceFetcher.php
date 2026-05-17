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
 * Extracted line-for-line from common/data/fetch_bybit_data.php so the
 * on-disk format and behaviour are identical — the standalone script is
 * kept in place as a CLI fallback.
 */
final class BybitPriceFetcher
{
    public const KLINE_LIMIT = 1000;
    public const FALLBACK_LAUNCH_MS = 1_514_764_800_000; // 2018-01-01 UTC
    public const DEFAULT_INTERVALS = ['D', 'W', '4h', '1h'];

    /** HashHedge tickers with no usable Bybit pair (delisted, unsupported,
     *  or only available under a different symbol convention). Skipping
     *  them avoids re-hitting the API and cluttering the failures file. */
    public const SYMBOL_EXCLUDE = [
        'XAG', 'NFP', 'AI', 'PHB', 'TRU', 'JOE', 'HOOK', 'DODOX', 'BEAMX', 'MON',
    ];

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

        $symbols   = $this->loadSymbols($symbolsFile);
        $overrides = $this->loadOverrides($overridesFile);
        if ($onlyList !== null) {
            $upper = array_map('strtoupper', $onlyList);
            $symbols = array_values(array_filter($symbols, static fn($s) => in_array($s, $upper, true)));
        }
        $total = count($symbols);
        $log("Fetching $total HashHedge symbols from Bybit V5  intervals=" . implode(',', $intervals));

        $this->instruments->ensureLoaded($refreshIndex, $log);

        $failures = $this->loadFailures($failuresFile);
        $success = 0;
        $fetched = 0;
        $skipped = 0;

        foreach ($symbols as $i => $sym) {
            $prefix  = sprintf('[%3d/%d] %-8s', $i + 1, $total, $sym);
            $outPath = "$outDir/$sym.json";

            if (in_array(strtoupper($sym), self::SYMBOL_EXCLUDE, true)) {
                $log("$prefix - excluded, skipping");
                unset($failures[$sym]);
                $success++;
                $skipped++;
                continue;
            }

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
            $needed = [];
            foreach ($intervals as $label) {
                if ($refresh || !isset($existingIntervals[$label])) {
                    $needed[] = $label;
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
                        $log("$prefix - all intervals present, skipping");
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
                    $entry = $this->buildIntervalEntry($category, $bybitSymbol, $label, $launchTime);
                    $intervalsMap[$label] = $entry;
                    $log("           $label: {$entry['row_count']} candles");
                }
                $record = [
                    'hashhedge_symbol' => $sym,
                    'bybit_symbol'     => $bybitSymbol,
                    'bybit_category'   => $category,
                    'fetched_at_utc'   => JsonFormatter::isoUtcNow(),
                    'instrument'       => $inst,
                    'history'          => ['intervals' => $this->sortIntervalsCanonical($intervalsMap)],
                ];
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
