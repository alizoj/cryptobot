<?php

declare(strict_types=1);

namespace common\services\Trading;

use common\services\Trading\Fetcher\BinanceFutures;
use common\services\Trading\Fetcher\BybitHttp;

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
 * Two layers, like the delta screener:
 *
 *   - BASE, per symbol: the Wilder state of each timeframe up to its last
 *     stored bar, plus that bar. Derived from the history file, so it is only
 *     recomputed when the file's mtime changes.
 *
 *   - LIVE, per build: the stored bars only move when their interval is
 *     refetched — the price fetcher re-downloads a timeframe once its newest
 *     bar has closed, so today's daily bar keeps the close it had at the first
 *     fetch after 00:00 UTC. Every build therefore pulls /v5/market/tickers
 *     (one call per category) and prices the forming bar of each timeframe at
 *     the live last price. If the tickers call fails, the freshest stored
 *     close (15m) stands in.
 */
final class RsiBoard
{
    /** Heatmap column => MarketData interval label, in sort-hierarchy order (1D dominant → 15m). */
    public const TFS = ['1d' => 'D', '4h' => '4h', '1h' => '1h', '15m' => '15m'];

    /** Bar length per heatmap column, ms. */
    public const TF_PERIOD_MS = ['1d' => 86_400_000, '4h' => 14_400_000, '1h' => 3_600_000, '15m' => 900_000];

    /** Closes per timeframe fed to RSI (the last one being the forming bar). */
    public const LOOKBACK = 250;

    /** 20-day average daily turnover below which a coin is flagged "thin". */
    public const MIN_TURNOVER = 5_000_000;

    /** Bump when the row shape changes so the next build recomputes every row. */
    private const VERSION = 4;

    private const DAY_MS = 86_400_000;

    /** @var \Closure(list<string>): array<string, array{price:float, chg24h:?float}> */
    private \Closure $tickers;

    /**
     * @param (\Closure(list<string>): array<string, array{price:float, chg24h:?float}>)|null $tickers
     *        live quotes keyed "category:SYMBOL" for the given categories;
     *        defaults to Bybit /v5/market/tickers (tests inject a stub)
     */
    public function __construct(?\Closure $tickers = null)
    {
        $this->tickers = $tickers ?? static fn(array $categories): array => self::fetchTickers($categories);
    }

    /**
     * The last built board, or null if none has been built yet (or the file is
     * unreadable). Cheap: one small file read.
     *
     * @return array{version:int, updated:int, prices_ts:?int, data_ts:?int, rows:list<array<string,mixed>>, skipped:array<string,int>}|null
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
     * Rebuild the board and write it out. Symbols whose history file is
     * unchanged since the previous build reuse their base layer unless $force
     * is set; live prices are applied to every row on every build.
     *
     * @param callable(string):void|null $log
     * @return array{symbols:int, computed:int, reused:int, live:int, seconds:float}
     */
    public function build(?callable $log = null, bool $force = false, ?int $nowMs = null): array
    {
        $log ??= static function (string $_): void {};
        $started = microtime(true);
        @set_time_limit(0);

        $prev = $force ? null : $this->load();
        if ($prev !== null && (int)($prev['version'] ?? 0) !== self::VERSION) {
            $prev = null;
        }
        $prevBases = [];
        foreach ($prev['rows'] ?? [] as $r) {
            $prevBases[$r['symbol']] = $r['base'];
        }
        $prevSkipped = $prev['skipped'] ?? [];

        // The queue worker is long-lived — don't trust mtimes cached by an earlier job.
        clearstatcache();

        $md = new MarketData();
        $dir = Paths::historyPricesDir();
        $universe = self::universe();
        $bases = [];
        $skipped = [];
        $computed = 0;
        $reused = 0;
        foreach ($md->listSymbols() as $sym) {
            if ($universe !== null && !isset($universe[$sym])) {
                continue;
            }
            $mtime = (int)@filemtime("{$dir}/{$sym}.json");
            if (isset($prevBases[$sym]) && (int)($prevBases[$sym]['src_mtime'] ?? -1) === $mtime) {
                $bases[] = $prevBases[$sym];
                $reused++;
                continue;
            }
            if (($prevSkipped[$sym] ?? -1) === $mtime) {
                $skipped[$sym] = $mtime;
                continue;
            }
            $base = self::computeBase($md, $sym);
            $md->forgetSymbol();
            $computed++;
            if ($base === null) {
                $skipped[$sym] = $mtime;   // too little history — retry only when the file changes
                continue;
            }
            $base['src_mtime'] = $mtime;
            $bases[] = $base;
        }

        $nowMs ??= (int)(microtime(true) * 1000);
        $quotes = [];
        $categories = array_values(array_unique(array_filter(array_column($bases, 'category'))));
        if ($categories !== []) {
            try {
                $quotes = ($this->tickers)($categories);
            } catch (\Throwable $e) {
                $log('rsi-board: live tickers unavailable (' . $e->getMessage() . ') — using stored closes');
            }
        }

        $rows = [];
        $live = 0;
        foreach ($bases as $base) {
            $quote = $quotes[$base['category'] . ':' . $base['bybit']] ?? null;
            $rows[] = self::row($base, $quote, $nowMs);
            $live += $quote !== null ? 1 : 0;
        }

        $lastTs = array_column($bases, 'last_ts');
        $board = [
            'version'   => self::VERSION,
            'updated'   => time(),
            'prices_ts' => $live > 0 ? $nowMs : null,
            'data_ts'   => $lastTs !== [] ? max($lastTs) : null,
            'rows'      => $rows,
            'skipped'   => $skipped,
        ];
        self::writeAtomic(
            Paths::rsiBoardFile(),
            (string)json_encode($board, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
        );

        $sum = [
            'symbols'  => count($rows),
            'computed' => $computed,
            'reused'   => $reused,
            'live'     => $live,
            'seconds'  => round(microtime(true) - $started, 2),
        ];
        $log(sprintf(
            'rsi-board: %d coins  computed=%d  reused=%d  live=%d  %.1fs',
            $sum['symbols'],
            $sum['computed'],
            $sum['reused'],
            $sum['live'],
            $sum['seconds'],
        ));
        return $sum;
    }

    /**
     * The display row: base layer + the current price folded into each
     * timeframe's forming bar. The current price is the live quote when there
     * is one, else the freshest stored close.
     *
     * @param array<string,mixed> $base
     * @param array{price:float, chg24h:?float}|null $quote
     * @return array<string,mixed>
     */
    public static function row(array $base, ?array $quote, int $nowMs): array
    {
        $live = $quote !== null && $quote['price'] > 0;
        [$price, $atMs] = $live
            ? [(float)$quote['price'], $nowMs]
            : [(float)$base['fresh_close'], (int)$base['fresh_ts']];

        $rsi = [];
        foreach (self::TFS as $tf => $_) {
            $rsi[$tf] = self::rsiAt($base['tfs'][$tf], $price, $atMs, self::TF_PERIOD_MS[$tf]);
        }

        $prevClose = $base['prev_close'];
        $chg = $live && $quote['chg24h'] !== null
            ? (float)$quote['chg24h']
            : ($prevClose !== null && $prevClose > 0 ? ($price / $prevClose - 1) * 100 : null);

        return [
            'symbol'     => $base['symbol'],
            'price'      => $price,
            'chg24h'     => $chg,
            'turnover20' => $base['turnover20'],
            'tradeable'  => $base['tradeable'],
            'rsi'        => $rsi,
            'day'        => self::dayAt($base['day'], $price, $atMs),
            'live'       => $live,
            'last_ts'    => $base['last_ts'],
            'base'       => $base,
        ];
    }

    /**
     * The forming daily candle of each symbol as of the last build, for the
     * chart page: the stored history's last daily candle keeps the high, low
     * and close of its first fetch after 00:00 UTC; this one is extended with
     * the 15m bars since and priced at the build's live price. Symbols not on
     * the board (or no board at all) are left out.
     *
     * @param array<string,mixed>|null $board from {@see load}
     * @param list<string> $symbols
     * @return array<string, array{ts:int, o:float, h:float, l:float, c:float, at:?int}>
     */
    public static function liveDays(?array $board, array $symbols): array
    {
        $want = array_flip($symbols);
        $out = [];
        foreach ($board['rows'] ?? [] as $r) {
            if (isset($want[$r['symbol']], $r['day'])) {
                $out[$r['symbol']] = $r['day'];
            }
        }
        return $out;
    }

    /**
     * Daily candles (MarketData shape, ascending) with the last one replaced
     * by the board's forming day: same day → its high/low/close are merged in
     * (the stored open stays); the day after → appended; an older board day
     * (history file refetched after the build) → the file wins.
     *
     * @param list<array{ts:int,o:float,h:float,l:float,c:float,v:float}> $candles
     * @param array{ts:int, o:float, h:float, l:float, c:float}|null $day
     * @return list<array{ts:int,o:float,h:float,l:float,c:float,v:float}>
     */
    public static function withLiveDay(array $candles, ?array $day): array
    {
        $n = count($candles);
        if ($day === null || $n === 0) {
            return $candles;
        }
        $last = $candles[$n - 1];
        $dayTs = (int)$day['ts'];
        if ($dayTs === (int)$last['ts']) {
            $candles[$n - 1] = [
                'ts' => $last['ts'],
                'o'  => $last['o'],
                'h'  => max((float)$last['h'], (float)$day['h']),
                'l'  => min((float)$last['l'], (float)$day['l']),
                'c'  => (float)$day['c'],
                'v'  => $last['v'],
            ];
        } elseif ($dayTs > (int)$last['ts']) {
            $candles[] = [
                'ts' => $dayTs,
                'o'  => (float)$day['o'],
                'h'  => (float)$day['h'],
                'l'  => (float)$day['l'],
                'c'  => (float)$day['c'],
                'v'  => 0.0,
            ];
        }
        return $candles;
    }

    /**
     * The forming daily candle with $price as of $atMs folded in — same
     * windows as {@see rsiAt}: inside the stored day → extend high/low and
     * move the close; inside the next day → a new candle opening at the stored
     * close (crypto trades round the clock, so the open is the prior close);
     * further out → unchanged. 'at' is when the close was priced (null when
     * unchanged).
     *
     * @param array{ts:int, o:float, h:float, l:float, c:float} $day
     * @return array{ts:int, o:float, h:float, l:float, c:float, at:?int}
     */
    private static function dayAt(array $day, float $price, int $atMs): array
    {
        $ts = (int)$day['ts'];
        if ($price <= 0 || $atMs < $ts) {
            return $day + ['at' => null];
        }
        if ($atMs < $ts + self::DAY_MS) {
            return [
                'ts' => $ts,
                'o'  => (float)$day['o'],
                'h'  => max((float)$day['h'], $price),
                'l'  => min((float)$day['l'], $price),
                'c'  => $price,
                'at' => $atMs,
            ];
        }
        if ($atMs < $ts + 2 * self::DAY_MS) {
            $open = (float)$day['c'];
            return ['ts' => $ts + self::DAY_MS, 'o' => $open, 'h' => max($open, $price), 'l' => min($open, $price), 'c' => $price, 'at' => $atMs];
        }
        return $day + ['at' => null];
    }

    /**
     * RSI of one timeframe with $price as the close of the bar that is forming
     * at $atMs:
     *   - still inside the stored last bar  → that bar's close becomes $price;
     *   - inside the bar right after it     → the stored bar counts as closed
     *     and $price is the new bar's close;
     *   - further out (or $price predates the bar) → bars are missing, so the
     *     stored RSI stays (the page flags such coins as stale).
     *
     * @param array{ts:int, close:float, state:?array{g:float,l:float,c:float}, rsi:?float} $tf
     */
    private static function rsiAt(array $tf, float $price, int $atMs, int $periodMs): ?float
    {
        $state = $tf['state'];
        $ts = (int)$tf['ts'];
        if ($state === null || $price <= 0 || $atMs < $ts) {
            return $tf['rsi'];
        }
        if ($atMs < $ts + $periodMs) {
            return round(CoinMetrics::rsiWithClose($state, $price), 1);
        }
        if ($atMs < $ts + 2 * $periodMs) {
            return round(CoinMetrics::rsiWithClose(CoinMetrics::rsiAdvance($state, (float)$tf['close']), $price), 1);
        }
        return $tf['rsi'];
    }

    /**
     * Base layer of one symbol from its history file, or null when it has
     * fewer than 15 daily bars (not enough for RSI(14)).
     *
     * @return array<string,mixed>|null
     */
    private static function computeBase(MarketData $md, string $sym): ?array
    {
        $rec = $md->loadSymbol($sym);
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

        $tfs = [];
        $lastTs = 0;
        $freshClose = null;
        $freshTs = 0;
        foreach ($series as $tf => $candles) {
            if ($candles === []) {
                $tfs[$tf] = ['ts' => 0, 'close' => 0.0, 'state' => null, 'rsi' => null];
                continue;
            }
            $closes = array_column($candles, 'c');
            $last = $candles[count($candles) - 1];
            $r = count($closes) >= 15 ? CoinMetrics::calcRSI($closes) : null;
            $tfs[$tf] = [
                'ts'    => (int)$last['ts'],
                'close' => (float)$last['c'],
                // State over everything before the last stored bar, so the
                // live price can replace that bar's close (16+ closes: the
                // RSI(14) seed needs 15 without it).
                'state' => count($closes) >= 16 ? CoinMetrics::rsiState(array_slice($closes, 0, -1)) : null,
                'rsi'   => $r !== null ? round($r, 1) : null,
            ];
            $lastTs = max($lastTs, (int)$last['ts']);
            // TFS runs 1d → 15m: the shortest timeframe's close was fetched
            // most recently — the fallback price when there is no live quote.
            $freshClose = (float)$last['c'];
            $freshTs = (int)$last['ts'];
        }

        // The stored daily candle froze at its first fetch of the day; the 15m
        // bars since its open (refetched every 15 minutes) carry the rest of
        // the day's range and a fresher close.
        $d = $daily[count($daily) - 1];
        $day = ['ts' => (int)$d['ts'], 'o' => (float)$d['o'], 'h' => (float)$d['h'], 'l' => (float)$d['l'], 'c' => (float)$d['c']];
        foreach ($series['15m'] as $b) {
            if ($b['ts'] >= $day['ts'] && $b['ts'] < $day['ts'] + self::DAY_MS) {
                $day['h'] = max($day['h'], (float)$b['h']);
                $day['l'] = min($day['l'], (float)$b['l']);
                $day['c'] = (float)$b['c'];
            }
        }

        $turn20 = self::turnover20($daily);
        return [
            'symbol'      => $sym,
            'bybit'       => (string)($rec['bybit_symbol'] ?? ''),
            'category'    => (string)($rec['bybit_category'] ?? ''),
            'tfs'         => $tfs,
            'day'         => $day,
            'prev_close'  => isset($daily[count($daily) - 2]) ? (float)$daily[count($daily) - 2]['c'] : null,
            'fresh_close' => $freshClose,
            'fresh_ts'    => $freshTs,
            'turnover20'  => $turn20,
            'tradeable'   => $turn20 !== null && $turn20 >= self::MIN_TURNOVER,
            'last_ts'     => $lastTs,   // open time (ms) of the newest stored bar on any timeframe
        ];
    }

    /**
     * Live last price + rolling 24h change (%) for every symbol of the given
     * categories — one tickers call per category: Bybit /v5/market/tickers for
     * spot/linear, Binance /fapi/v1/ticker/24hr for the USD-M fallback. A
     * category that fails is skipped; its coins fall back to stored closes.
     *
     * @param list<string> $categories
     * @return array<string, array{price:float, chg24h:?float}> keyed "category:SYMBOL"
     */
    private static function fetchTickers(array $categories): array
    {
        $http = new BybitHttp();
        $out = [];
        $errors = [];
        foreach ($categories as $i => $cat) {
            if ($i > 0) {
                $http->throttle();
            }
            try {
                if ($cat === BinanceFutures::CATEGORY) {
                    foreach ((new BinanceFutures())->tickers() as $symbol => $quote) {
                        $out[$cat . ':' . $symbol] = $quote;
                    }
                    continue;
                }
                $res = $http->get('/v5/market/tickers', ['category' => $cat]);
            } catch (\Throwable $e) {
                $errors[] = "{$cat}: {$e->getMessage()}";
                continue;
            }
            foreach ($res['list'] ?? [] as $t) {
                if (!isset($t['symbol'], $t['lastPrice']) || (float)$t['lastPrice'] <= 0) {
                    continue;
                }
                $out[$cat . ':' . $t['symbol']] = [
                    'price'  => (float)$t['lastPrice'],
                    'chg24h' => is_numeric($t['price24hPcnt'] ?? null) ? (float)$t['price24hPcnt'] * 100 : null,
                ];
            }
        }
        if ($out === [] && $errors !== []) {
            throw new \RuntimeException(implode('; ', $errors));
        }
        return $out;
    }

    /**
     * Tickers on the master list, or null when it can't be read (then every
     * history file is shown). Files of tickers dropped from the list stay on
     * disk but are no longer refreshed — without this filter they would
     * linger on the heatmap with frozen prices.
     *
     * @return array<string, true>|null
     */
    private static function universe(): ?array
    {
        $file = Paths::symbolsFile();
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        $symbols = $data['symbols'] ?? null;
        return is_array($symbols) && $symbols !== []
            ? array_fill_keys(array_map('strtoupper', $symbols), true)
            : null;
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
