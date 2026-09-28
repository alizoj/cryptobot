<?php

declare(strict_types=1);

namespace common\services\Trading;

/**
 * The precomputed RSI heatmap behind /trading/rsi: one row per coin with
 * Wilder RSI(14) on 1d/4h/1h/15m, last price, 24h change and 20-day turnover.
 *
 * Building it means decoding every per-symbol history JSON (5-20 MB each,
 * ~2 GB for the universe), which is far too slow for a web request. So the
 * board is built in the background — after every price refresh (the
 * ScanSignalsJob loop, trading/fetch-data, run-hourly, run-4h) or on demand
 * with `./yii trading/rsi-board` — and written to one small JSON file that the
 * page only reads.
 *
 * Builds are incremental: each row remembers the mtime of the history file it
 * came from, and a symbol is only re-decoded when its file changed since the
 * last build. A refresh run that fetched nothing costs a few stat() calls.
 */
final class RsiBoard
{
    /** Heatmap column => MarketData interval label, in sort-hierarchy order (1D dominant → 15m). */
    public const TFS = ['1d' => 'D', '4h' => '4h', '1h' => '1h', '15m' => '15m'];

    /** Bars read per timeframe (RSI warm-up; also covers the 20-day turnover). */
    public const LOOKBACK = 250;

    /** 20-day average daily turnover below which a coin is flagged "thin". */
    public const MIN_TURNOVER = 5_000_000;

    /** Bump when the row shape changes so the next build recomputes every row. */
    private const VERSION = 2;

    /**
     * The last built board, or null if none has been built yet (or the file is
     * unreadable). Cheap: one small file read.
     *
     * @return array{version:int, updated:int, data_ts:?int, rows:list<array<string,mixed>>, skipped:array<string,int>}|null
     */
    public function load(): ?array
    {
        $file = Paths::rsiBoardFile();
        if (!is_file($file)) {
            return null;
        }
        $board = json_decode((string)@file_get_contents($file), true);
        return is_array($board) && isset($board['rows']) && is_array($board['rows']) ? $board : null;
    }

    /**
     * Rebuild the board from the history files and write it out. Rows whose
     * source file is unchanged since the previous build are reused as-is
     * unless $force is set.
     *
     * @param callable(string):void|null $log
     * @return array{symbols:int, computed:int, reused:int, seconds:float}
     */
    public function build(?callable $log = null, bool $force = false): array
    {
        $log ??= static function (string $_): void {};
        $started = microtime(true);
        @set_time_limit(0);

        $prev = $force ? null : $this->load();
        if ($prev !== null && (int)($prev['version'] ?? 0) !== self::VERSION) {
            $prev = null;
        }
        $prevRows = [];
        foreach ($prev['rows'] ?? [] as $r) {
            $prevRows[$r['symbol']] = $r;
        }
        $prevSkipped = $prev['skipped'] ?? [];

        // The queue worker is long-lived — don't trust mtimes cached by an earlier job.
        clearstatcache();

        $md = new MarketData();
        $dir = Paths::historyPricesDir();
        $rows = [];
        $skipped = [];
        $computed = 0;
        $reused = 0;
        foreach ($md->listSymbols() as $sym) {
            $mtime = (int)@filemtime("{$dir}/{$sym}.json");
            if (isset($prevRows[$sym]) && (int)($prevRows[$sym]['src_mtime'] ?? -1) === $mtime) {
                $rows[] = $prevRows[$sym];
                $reused++;
                continue;
            }
            if (($prevSkipped[$sym] ?? -1) === $mtime) {
                $skipped[$sym] = $mtime;
                continue;
            }
            $row = self::computeRow($md, $sym);
            $md->forgetSymbol();
            $computed++;
            if ($row === null) {
                $skipped[$sym] = $mtime;   // too little history — retry only when the file changes
                continue;
            }
            $row['src_mtime'] = $mtime;
            $rows[] = $row;
        }

        $lastTs = array_column($rows, 'last_ts');
        $board = [
            'version' => self::VERSION,
            'updated' => time(),
            'data_ts' => $lastTs !== [] ? max($lastTs) : null,
            'rows'    => $rows,
            'skipped' => $skipped,
        ];
        self::writeAtomic(
            Paths::rsiBoardFile(),
            (string)json_encode($board, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
        );

        $sum = [
            'symbols'  => count($rows),
            'computed' => $computed,
            'reused'   => $reused,
            'seconds'  => round(microtime(true) - $started, 2),
        ];
        $log(sprintf(
            'rsi-board: %d coins  computed=%d  reused=%d  %.1fs',
            $sum['symbols'],
            $sum['computed'],
            $sum['reused'],
            $sum['seconds'],
        ));
        return $sum;
    }

    /**
     * One heatmap row from the symbol's history file, or null when it has
     * fewer than 15 daily bars (not enough for RSI(14)).
     *
     * @return array<string,mixed>|null
     */
    private static function computeRow(MarketData $md, string $sym): ?array
    {
        // Tail reads only: every value below needs at most the last LOOKBACK
        // bars, and keying the full lifetime 1h/4h series instead costs tens
        // of MB per symbol on top of the decoded file.
        $series = [];
        foreach (self::TFS as $tf => $interval) {
            $series[$tf] = $md->intervalWindowAsOf($sym, $interval, PHP_INT_MAX, self::LOOKBACK);
        }
        $daily = $series['1d'];
        if (count($daily) < 15) {
            return null;
        }

        $rsi = [];
        $lastTs = 0;
        foreach ($series as $tf => $candles) {
            $rsi[$tf] = self::rsiOf($candles);
            if ($candles !== []) {
                $lastTs = max($lastTs, (int)$candles[count($candles) - 1]['ts']);
            }
        }

        $price = (float)$daily[count($daily) - 1]['c'];
        $prev  = $daily[count($daily) - 2] ?? null;
        $chg   = ($prev !== null && (float)$prev['c'] > 0) ? ($price / (float)$prev['c'] - 1) * 100 : null;
        $turn20 = self::turnover20($daily);

        return [
            'symbol'     => $sym,
            'price'      => $price,
            'chg24h'     => $chg,
            'turnover20' => $turn20,
            'tradeable'  => $turn20 !== null && $turn20 >= self::MIN_TURNOVER,
            'rsi'        => $rsi,
            'last_ts'    => $lastTs,   // open time (ms) of the newest bar on any timeframe
        ];
    }

    /** Wilder RSI(14) of the last LOOKBACK closes of an OHLCV series, or null. */
    private static function rsiOf(array $candles): ?float
    {
        if (count($candles) < 15) {
            return null;
        }
        $closes = array_column(array_slice($candles, -self::LOOKBACK), 'c');
        $r = CoinMetrics::calcRSI($closes);
        return $r !== null ? round($r, 1) : null;
    }

    /** 20-day average daily turnover (close × volume). */
    private static function turnover20(array $daily): ?float
    {
        $n = count($daily);
        if ($n < 1) {
            return null;
        }
        $w = min(20, $n);
        $sum = 0.0;
        foreach (array_slice($daily, -$w) as $c) {
            $sum += (float)$c['c'] * (float)$c['v'];
        }
        return $sum / $w;
    }

    /**
     * Write via a temp file + rename so a page load never reads a half-written
     * board. Windows refuses the rename while a reader holds the target open;
     * fall back to a locked in-place write then (the file is a few dozen KB).
     */
    private static function writeAtomic(string $file, string $json): void
    {
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json) !== false && @rename($tmp, $file)) {
            return;
        }
        @unlink($tmp);
        file_put_contents($file, $json, LOCK_EX);
    }
}
