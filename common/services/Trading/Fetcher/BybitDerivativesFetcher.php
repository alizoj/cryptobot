<?php

declare(strict_types=1);

namespace common\services\Trading\Fetcher;

use common\services\Trading\Paths;
use Throwable;
use Yii;

/**
 * Pull lifetime funding-rate + open-interest history for every symbol that
 * has a linear-perp pair on Bybit, and merge under the `derivatives` block
 * of common/data/history_prices/<SYMBOL>.json.
 *
 * Funding events fire every 8h, OI snapshots are daily. Per block, if the
 * last stored row is older than one period, we extend forward from that row
 * and merge by timestamp; otherwise the block is skipped with no API call.
 */
final class BybitDerivativesFetcher
{
    public const FUNDING_LIMIT = 200;
    public const OI_LIMIT = 200;
    public const OI_INTERVAL = '1d';
    public const FALLBACK_LAUNCH_MS = 1_514_764_800_000;
    public const FUNDING_PERIOD_MS  = 8 * 3_600_000;  // funding events fire 3x/day
    public const OI_PERIOD_MS       = 86_400_000;     // OI snapshots are daily

    private BybitHttp $http;
    private InstrumentsIndex $instruments;

    public function __construct(?BybitHttp $http = null, ?InstrumentsIndex $instruments = null)
    {
        $this->http = $http ?? new BybitHttp('crypto-bot-derivs-php/1.0');
        $this->instruments = $instruments ?? new InstrumentsIndex($this->http, Paths::instrumentsIndex());
    }

    /**
     * @param array{only?:list<string>|null, refresh?:bool} $opts
     */
    public function run(array $opts = [], ?callable $log = null): FetchResult
    {
        $log ??= static function (string $_): void {};
        $started = microtime(true);

        $onlyList = $opts['only'] ?? null;
        $refresh  = (bool)($opts['refresh'] ?? false);

        $dataDir       = Yii::getAlias('@common/data');
        $symbolsFile   = $dataDir . DIRECTORY_SEPARATOR . 'hashhedge_symbols.json';
        $overridesFile = $dataDir . DIRECTORY_SEPARATOR . 'bybit_symbol_overrides.json';
        $outDir        = Paths::historyPricesDir();
        $failuresFile  = $outDir . DIRECTORY_SEPARATOR . '_derivatives_failures.json';

        if (!is_file(Paths::instrumentsIndex())) {
            $msg = 'instruments index missing — run price fetcher first';
            $log($msg);
            return FetchResult::fail('derivatives', 0.0, $msg);
        }
        $this->instruments->ensureLoaded(false, $log);

        $overrides = file_exists($overridesFile)
            ? array_filter(
                json_decode((string)file_get_contents($overridesFile), true, flags: JSON_THROW_ON_ERROR),
                static fn($k) => !str_starts_with($k, '_'),
                ARRAY_FILTER_USE_KEY,
            )
            : [];

        // hashhedge_symbols.json is the single source of truth — only process
        // files for symbols still on the list. Stale per-symbol JSON files
        // (from pruned tickers) would otherwise get retried every run.
        $symbolsJson = json_decode((string)file_get_contents($symbolsFile), true, flags: JSON_THROW_ON_ERROR);
        $allowed = array_flip(array_map('strtoupper', $symbolsJson['symbols'] ?? []));

        $files = array_values(array_filter(
            glob("$outDir/*.json") ?: [],
            static fn(string $f) => !str_starts_with(basename($f), '_')
                && isset($allowed[strtoupper(pathinfo($f, PATHINFO_FILENAME))]),
        ));
        if ($onlyList !== null) {
            $upper = array_map('strtoupper', $onlyList);
            $files = array_values(array_filter(
                $files,
                static fn(string $f) => in_array(strtoupper(pathinfo($f, PATHINFO_FILENAME)), $upper, true),
            ));
        }

        $log("Fetching derivatives for " . count($files) . " symbols");
        $failures = file_exists($failuresFile)
            ? json_decode((string)file_get_contents($failuresFile), true, flags: JSON_THROW_ON_ERROR)
            : [];
        // Drop failure entries for symbols no longer on the master list.
        $failures = array_intersect_key($failures, $allowed);
        $success = 0;
        $fetched = 0;
        $skipped = 0;
        $nowMs   = (int)(microtime(true) * 1000);

        foreach ($files as $i => $file) {
            $sym = pathinfo($file, PATHINFO_FILENAME);
            $prefix = sprintf('[%3d/%d] %-8s', $i + 1, count($files), $sym);

            $record = json_decode((string)file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

            $existingFunding = $record['derivatives']['funding']['rows']       ?? [];
            $existingOi      = $record['derivatives']['open_interest']['rows'] ?? [];
            $fundingLastTs   = $existingFunding ? (int)$existingFunding[array_key_last($existingFunding)][0] : 0;
            $oiLastTs        = $existingOi      ? (int)$existingOi[array_key_last($existingOi)][0]           : 0;

            $needFunding = $refresh || !$existingFunding || ($nowMs >= $fundingLastTs + self::FUNDING_PERIOD_MS);
            $needOi      = $refresh || !$existingOi      || ($nowMs >= $oiLastTs      + self::OI_PERIOD_MS);

            if (!$needFunding && !$needOi) {
                $log("$prefix - derivatives fresh, skipping");
                $success++;
                $skipped++;
                continue;
            }

            $linearSymbol = $this->instruments->resolveLinearOnly($sym, $overrides);
            if ($linearSymbol === null) {
                $log("$prefix - no linear perp found, skipping");
                $failures[$sym] = ['error' => 'no linear perp', 'when' => JsonFormatter::isoUtcNow()];
                file_put_contents($failuresFile, json_encode($failures, JSON_PRETTY_PRINT));
                continue;
            }

            $linearInst = $this->instruments->linear()[$linearSymbol] ?? [];
            $launchMs = (int)($linearInst['launchTime'] ?? 0);
            if ($launchMs === 0) {
                $launchMs = (int)($record['instrument']['launchTime'] ?? self::FALLBACK_LAUNCH_MS);
            }

            $log("$prefix -> $linearSymbol (linear)");
            $derivatives = $record['derivatives'] ?? ['bybit_symbol' => $linearSymbol];
            $derivatives['bybit_symbol']   = $linearSymbol;
            $derivatives['fetched_at_utc'] = JsonFormatter::isoUtcNow();

            try {
                if ($needFunding) {
                    $lowerBound = ($existingFunding && !$refresh) ? $fundingLastTs : $launchMs;
                    $fresh      = $this->fetchFundingHistory($linearSymbol, $lowerBound);
                    $merged     = ($existingFunding && !$refresh)
                        ? $this->mergeDerivsRows($existingFunding, $fresh)
                        : $fresh;
                    $added = count($merged) - count($existingFunding);
                    $derivatives['funding'] = $this->makeBlock(['timestamp_ms', 'funding_rate'], $merged);
                    $tag = ($existingFunding && !$refresh) ? "(+$added new)" : '(full)';
                    $log("           funding: " . count($merged) . " events $tag");
                }
                if ($needOi) {
                    $lowerBound = ($existingOi && !$refresh) ? $oiLastTs : $launchMs;
                    $fresh      = $this->fetchOpenInterestHistory($linearSymbol, $lowerBound);
                    $merged     = ($existingOi && !$refresh)
                        ? $this->mergeDerivsRows($existingOi, $fresh)
                        : $fresh;
                    $added = count($merged) - count($existingOi);
                    $derivatives['open_interest'] = $this->makeBlock(
                        ['timestamp_ms', 'open_interest'],
                        $merged,
                        ['interval' => self::OI_INTERVAL],
                    );
                    $tag = ($existingOi && !$refresh) ? "(+$added new)" : '(full)';
                    $log("           OI:      " . count($merged) . " bars $tag");
                }

                $record['derivatives'] = $derivatives;
                file_put_contents($file, JsonFormatter::encode2Space($record));
                $kb = intdiv((int)filesize($file), 1024);
                $log("           OK ($kb KB total)");
                $success++;
                $fetched++;
                unset($failures[$sym]);
            } catch (Throwable $e) {
                $err = $e::class . ': ' . $e->getMessage();
                $log("           FAILED: $err");
                $failures[$sym] = ['linear_symbol' => $linearSymbol, 'error' => $err, 'when' => JsonFormatter::isoUtcNow()];
                file_put_contents($failuresFile, json_encode($failures, JSON_PRETTY_PRINT));
            }
        }

        file_put_contents($failuresFile, json_encode($failures, JSON_PRETTY_PRINT));
        $elapsed = round(microtime(true) - $started, 2);
        $summary = "derivatives: $success/" . count($files) . " ok  fetched=$fetched  skipped=$skipped  failures=" . count($failures);
        $log($summary);

        return FetchResult::ok('derivatives', $elapsed, $summary, [
            'total'    => count($files),
            'success'  => $success,
            'fetched'  => $fetched,
            'skipped'  => $skipped,
            'failures' => count($failures),
        ]);
    }

    private function fetchFundingHistory(string $symbol, int $launchMs): array
    {
        $all = [];
        $cursor = null;
        $safety = 0;
        while ($safety++ < 200) {
            $params = ['category' => 'linear', 'symbol' => $symbol, 'limit' => self::FUNDING_LIMIT];
            if ($cursor !== null) {
                $params['endTime'] = $cursor;
            }
            $res = $this->http->get('/v5/market/funding/history', $params);
            $list = $res['list'] ?? [];
            if (empty($list)) {
                break;
            }
            foreach ($list as $r) {
                $all[] = [(string)$r['fundingRateTimestamp'], (string)$r['fundingRate']];
            }
            $oldest = PHP_INT_MAX;
            foreach ($list as $r) {
                $t = (int)$r['fundingRateTimestamp'];
                if ($t < $oldest) {
                    $oldest = $t;
                }
            }
            if ($oldest <= $launchMs || count($list) < self::FUNDING_LIMIT) {
                break;
            }
            $cursor = $oldest - 1;
            $this->http->throttle();
        }
        return $this->dedupAndSort($all);
    }

    private function fetchOpenInterestHistory(string $symbol, int $launchMs): array
    {
        $all = [];
        $cursor = null;
        $safety = 0;
        while ($safety++ < 200) {
            $params = [
                'category'     => 'linear',
                'symbol'       => $symbol,
                'intervalTime' => self::OI_INTERVAL,
                'limit'        => self::OI_LIMIT,
            ];
            if ($cursor !== null) {
                $params['endTime'] = $cursor;
            }
            $res = $this->http->get('/v5/market/open-interest', $params);
            $list = $res['list'] ?? [];
            if (empty($list)) {
                break;
            }
            foreach ($list as $r) {
                $all[] = [(string)$r['timestamp'], (string)$r['openInterest']];
            }
            $oldest = PHP_INT_MAX;
            foreach ($list as $r) {
                $t = (int)$r['timestamp'];
                if ($t < $oldest) {
                    $oldest = $t;
                }
            }
            if ($oldest <= $launchMs || count($list) < self::OI_LIMIT) {
                break;
            }
            $cursor = $oldest - 1;
            $this->http->throttle();
        }
        return $this->dedupAndSort($all);
    }

    /**
     * Merge two derivatives row lists keyed by ms-timestamp (col 0). Newer
     * entries overwrite older ones at the same timestamp, then we sort
     * ascending. Timestamps stay in their original string form.
     *
     * @param list<list<string>> $existing
     * @param list<list<string>> $fresh
     * @return list<list<string>>
     */
    private function mergeDerivsRows(array $existing, array $fresh): array
    {
        $byTs = [];
        foreach ($existing as $r) {
            $byTs[(int)$r[0]] = $r;
        }
        foreach ($fresh as $r) {
            $byTs[(int)$r[0]] = $r;
        }
        ksort($byTs, SORT_NUMERIC);
        return array_values($byTs);
    }

    private function dedupAndSort(array $rows): array
    {
        $seen = $out = [];
        foreach ($rows as $row) {
            $k = $row[0];
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $row;
        }
        usort($out, static fn($a, $b) => (int)$a[0] <=> (int)$b[0]);
        return $out;
    }

    private function makeBlock(array $schema, array $rows, array $extra = []): array
    {
        return $extra + [
            'schema'    => $schema,
            'rows'      => $rows,
            'row_count' => count($rows),
            'first_utc' => $rows ? JsonFormatter::isoUtcFromMs((int)$rows[0][0]) : null,
            'last_utc'  => $rows ? JsonFormatter::isoUtcFromMs((int)$rows[array_key_last($rows)][0]) : null,
        ];
    }
}
