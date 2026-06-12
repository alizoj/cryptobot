<?php

declare(strict_types=1);

namespace backend\controllers;

use common\services\Trading\CoinMetrics;
use common\services\Trading\DeltaAlignment;
use common\services\Trading\MarketData;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Backend views for the market data.
 *
 * The signal engine (dashboard, scans, positions) has been removed — a new
 * strategy is being built. What's left is the read-only market views:
 *
 *   /trading               redirects to the RSI heatmap
 *   /trading/rsi           multi-timeframe RSI heatmap over the whole universe
 *   /trading/chart         interactive candlestick for a symbol
 *   /trading/delta         DELTA_ALIGNMENT_SHORT_V1 live screener
 *   /trading/delta-ticks   JSON poll target feeding the screener (~1 Hz)
 *   /trading/delta-signal  spec-shaped bot response for one symbol
 */
final class TradingController extends Controller
{
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    // Read-only market views — any logged-in user (admin or guest).
                    ['allow' => true, 'roles' => ['@']],
                ],
            ],
        ];
    }

    public function actionIndex(): Response
    {
        return $this->redirect(['rsi']);
    }

    /**
     * Coin listing with multi-timeframe aggregated RSI — a Bybit-only revival of
     * the rsi-module heatmap. Each coin shows Wilder RSI(14) on 1h/4h/1d/1w plus
     * a weight-blended "aggregated" RSI (longer TFs weighted higher). The board
     * is cached to runtime so the page is snappy; ?refresh=1 forces a recompute.
     *
     *   /trading/rsi
     *   /trading/rsi?sort=1h&dir=desc
     *   /trading/rsi?refresh=1
     */
    public function actionRsi(string $sort = 'agg', string $dir = 'asc', int $refresh = 0): string
    {
        $board = $this->rsiBoard((bool)$refresh);
        $rows  = $board['rows'];

        $allowed = ['symbol', 'price', 'chg24h', 'agg', '1h', '4h', '1d', '1w', 'turnover20'];
        if (!in_array($sort, $allowed, true)) {
            $sort = 'agg';
        }
        $mul = $dir === 'desc' ? -1 : 1;
        $val = static fn(array $r) => in_array($sort, ['1h', '4h', '1d', '1w'], true) ? ($r['rsi'][$sort] ?? null) : ($r[$sort] ?? null);
        usort($rows, static function (array $a, array $b) use ($val, $mul, $sort): int {
            $va = $val($a);
            $vb = $val($b);
            if ($va === null && $vb === null) return 0;
            if ($va === null) return 1;   // nulls always last
            if ($vb === null) return -1;
            return $mul * ($sort === 'symbol' ? strcmp((string)$va, (string)$vb) : ($va <=> $vb));
        });

        return $this->render('rsi', [
            'rows'    => $rows,
            'updated' => $board['updated'],
            'sort'    => $sort,
            'dir'     => $dir,
            'tfs'     => self::RSI_TFS,
        ]);
    }

    /** Timeframe label => aggregation weight (longer TF = more weight). */
    private const RSI_TFS = ['1h' => 1.0, '4h' => 1.6, '1d' => 2.4, '1w' => 3.0];

    /**
     * Build (or load from a 15-min runtime cache) the per-coin RSI board over
     * the whole fetched universe. Returns ['updated'=>int ts, 'rows'=>list].
     *
     * @return array{updated:int, rows:list<array<string,mixed>>}
     */
    private function rsiBoard(bool $force): array
    {
        $cacheFile = Yii::getAlias('@runtime') . '/rsi_board.json';
        $ttl = 900;
        if (!$force && is_file($cacheFile)) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached) && (time() - (int)($cached['updated'] ?? 0)) < $ttl) {
                return $cached;
            }
        }

        @set_time_limit(180);
        $md = new MarketData();
        $rows = [];
        foreach ($md->listSymbols() as $sym) {
            $daily = $md->dailyCandles($sym);
            if (count($daily) < 15) {
                $md->forgetSymbol();
                continue;
            }
            $rsi = [
                '1h' => self::rsiOf($md->hourlyCandles($sym)),
                '4h' => self::rsiOf($md->fourHourCandles($sym)),
                '1d' => self::rsiOf($daily),
                '1w' => self::rsiOf($md->weeklyCandles($sym)),
            ];
            $acc = 0.0;
            $wsum = 0.0;
            foreach (self::RSI_TFS as $tf => $w) {
                if (is_numeric($rsi[$tf])) {
                    $acc += $w * $rsi[$tf];
                    $wsum += $w;
                }
            }
            $agg = $wsum > 0 ? $acc / $wsum : null;

            $last  = end($daily);
            $prev  = $daily[count($daily) - 2] ?? null;
            $price = (float)$last['c'];
            $chg   = ($prev !== null && (float)$prev['c'] > 0) ? ($price / (float)$prev['c'] - 1) * 100 : null;
            $turn20 = self::turnover20($daily);

            $rows[] = [
                'symbol'     => $sym,
                'price'      => $price,
                'chg24h'     => $chg,
                'turnover20' => $turn20,
                'tradeable'  => $turn20 !== null && $turn20 >= 5_000_000,
                'rsi'        => $rsi,
                'agg'        => $agg !== null ? round($agg, 1) : null,
            ];
            $md->forgetSymbol();
        }

        $out = ['updated' => time(), 'rows' => $rows];
        @file_put_contents($cacheFile, json_encode($out));
        return $out;
    }

    /** Wilder RSI(14) of the last ~250 closes of an OHLCV series, or null. */
    private static function rsiOf(array $candles): ?float
    {
        if (count($candles) < 15) {
            return null;
        }
        $closes = array_column(array_slice($candles, -250), 'c');
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
     * DELTA_ALIGNMENT_SHORT_V1 screener — live SMA15/SMA50 deltas on
     * 5m/30m/1h/2h, computed from the live price + the closed-candle sums the
     * 5-minute board cron maintains (./yii trading/delta-board). The page
     * renders once with current values and then polls delta-ticks every second.
     */
    public function actionDelta(): string
    {
        $svc   = new DeltaAlignment();
        $board = $svc->loadBoard();
        $rows  = $board !== null ? $svc->computeRows($board, $svc->lastPrices($board)) : [];

        return $this->render('delta', [
            'rows'         => $rows,
            'boardUpdated' => $board !== null ? (int)$board['updated'] : null,
            'meta'         => DeltaAlignment::META,
        ]);
    }

    /** JSON poll target for the delta screener: live prices folded into the board. */
    public function actionDeltaTicks(): Response
    {
        $svc   = new DeltaAlignment();
        $board = $svc->loadBoard();
        if ($board === null) {
            return $this->asJson(['ok' => false, 'error' => 'board-missing']);
        }
        return $this->asJson([
            'ok'            => true,
            'now'           => time(),
            'board_updated' => (int)$board['updated'],
            'rows'          => $svc->computeRows($board, $svc->lastPrices($board)),
        ]);
    }

    /**
     * The bot-facing response from the signal spec, for one symbol:
     *
     *   /trading/delta-signal?symbol=BTC
     *   -> {"signal":"DELTA_ALIGNMENT_SHORT_V1","active":true,"bias":"SHORT",...}
     */
    public function actionDeltaSignal(string $symbol): Response
    {
        $symbol = strtoupper($symbol);
        $svc    = new DeltaAlignment();
        $board  = $svc->loadBoard();
        if ($board === null) {
            throw new NotFoundHttpException('Delta board not built yet — run ./yii trading/delta-board first.');
        }
        foreach ($svc->computeRows($board, $svc->lastPrices($board)) as $row) {
            if ($row['symbol'] === $symbol || $row['bybit'] === $symbol) {
                return $this->asJson(DeltaAlignment::specResponse($row));
            }
        }
        throw new NotFoundHttpException("Unknown symbol {$symbol}.");
    }

    /** Compared coins are capped to the view's line-color palette. */
    private const COMPARE_MAX = 8;

    /**
     * Interactive candlestick chart for one coin — or a rebased multi-coin
     * comparison (TradingView Lightweight Charts). Entry/stop/side/setup are
     * passed via query string so price lines can be drawn for any external
     * signal source.
     *
     *   /trading/chart                       BTC by default
     *   /trading/chart?symbol=SOL&zoom=60
     *   /trading/chart?symbol=BTC&entry=65000&stop=62000&side=long&setup=L1&asOf=2026-05-26
     *
     * Passing several coins switches to compare mode: each close is rebased to
     * its % change since the start of the visible window, so coins with very
     * different prices share one axis.
     *
     *   /trading/chart?symbols=BTC,ETH,SOL&zoom=90
     */
    public function actionChart(
        ?string $symbol = null,
        ?string $symbols = null,
        ?string $entry = null,
        ?string $stop = null,
        ?string $side = null,
        ?string $setup = null,
        ?string $asOf = null,
        int $zoom = 90,
    ): string {
        $md   = new MarketData();
        $all  = $md->listSymbols();
        $zoom = max(14, min(365, $zoom));

        $requested = $symbols !== null && trim($symbols) !== ''
            ? (preg_split('/[,\s]+/', strtoupper($symbols), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            : [strtoupper(trim((string)$symbol) !== '' ? trim((string)$symbol) : 'BTC')];
        $picked = array_values(array_intersect(array_unique($requested), $all));
        if ($picked === []) {
            throw new NotFoundHttpException('No daily candles for ' . implode(', ', $requested) . '.');
        }
        $picked = array_slice($picked, 0, self::COMPARE_MAX);

        if (count($picked) > 1) {
            return $this->renderCompare($md, $picked, $all, $zoom);
        }

        $symbol  = $picked[0];
        $candles = $md->dailyCandles($symbol);
        if ($candles === []) {
            throw new NotFoundHttpException("No daily candles for {$symbol}.");
        }

        // Lightweight Charts wants {time: unix_seconds, open, high, low, close}.
        $series = [];
        foreach ($candles as $c) {
            $series[] = [
                'time'  => intdiv((int)$c['ts'], 1000),
                'open'  => (float)$c['o'],
                'high'  => (float)$c['h'],
                'low'   => (float)$c['l'],
                'close' => (float)$c['c'],
            ];
        }

        // Default visible window = last $zoom days so candles render at a
        // useful size; older bars stay scrollable.
        $lastTs    = (int)$series[count($series) - 1]['time'];
        $visibleFrom = $lastTs - $zoom * 86400;
        $visibleTo   = $lastTs + 5 * 86400;  // small right-side padding

        // S/R levels: 5-bar fractal pivots over the trailing ~zoom+30 candles,
        // keep the 3 most-recent uniques on each side (deduped within 0.5%).
        [$support, $resistance] = self::supportResistance($series, $zoom + 30);

        return $this->render('chart', [
            'mode'       => 'single',
            'symbol'     => $symbol,
            'symbols'    => [$symbol],
            'allSymbols' => $all,
            'compare'    => [],
            'series' => $series,
            'entry'  => $entry !== null && $entry !== '' ? (float)$entry : null,
            'stop'   => $stop  !== null && $stop  !== '' ? (float)$stop  : null,
            'side'   => $side,
            'setup'  => $setup,
            'asOf'   => $asOf,
            'zoom'   => $zoom,
            'visibleFrom' => $visibleFrom,
            'visibleTo'   => $visibleTo,
            'support'     => $support,
            'resistance'  => $resistance,
        ]);
    }

    /**
     * Compare mode: one line per coin, every close rebased to its % change
     * since the start of the visible window (coins listed mid-window rebase to
     * their first candle), so any mix of prices shares one percentage axis.
     *
     * @param list<string> $picked validated coin tickers (2+)
     * @param list<string> $all the full universe, for the add-coin picker
     */
    private function renderCompare(MarketData $md, array $picked, array $all, int $zoom): string
    {
        $loaded = [];
        $lastTs = 0;
        foreach ($picked as $sym) {
            $candles = $md->dailyCandles($sym);
            if ($candles === []) {
                continue;
            }
            $closes = [];
            foreach ($candles as $c) {
                $closes[] = [intdiv((int)$c['ts'], 1000), (float)$c['c']];
            }
            $loaded[$sym] = $closes;
            $lastTs = max($lastTs, $closes[count($closes) - 1][0]);
        }
        if ($loaded === []) {
            throw new NotFoundHttpException('No daily candles for ' . implode(', ', $picked) . '.');
        }
        $visibleFrom = $lastTs - $zoom * 86400;
        $visibleTo   = $lastTs + 5 * 86400;

        $compare = [];
        foreach ($loaded as $sym => $closes) {
            $anchor = null;
            foreach ($closes as [$ts, $close]) {
                if ($ts >= $visibleFrom && $close > 0) {
                    $anchor = $close;
                    break;
                }
            }
            $anchor ??= ($closes[0][1] > 0 ? $closes[0][1] : 1.0);
            $data = [];
            foreach ($closes as [$ts, $close]) {
                $data[] = ['time' => $ts, 'value' => round(($close / $anchor - 1) * 100, 4)];
            }
            $compare[] = ['symbol' => $sym, 'data' => $data];
        }

        return $this->render('chart', [
            'mode'        => 'compare',
            'symbol'      => array_key_first($loaded),
            'symbols'     => array_keys($loaded),
            'allSymbols'  => $all,
            'compare'     => $compare,
            'zoom'        => $zoom,
            'visibleFrom' => $visibleFrom,
            'visibleTo'   => $visibleTo,
            // single-mode extras, empty so the view template stays uniform
            'series' => [], 'entry' => null, 'stop' => null, 'side' => null,
            'setup' => null, 'asOf' => null, 'support' => [], 'resistance' => [],
        ]);
    }

    /**
     * Williams-fractal pivots over the trailing $lookback bars; returns the
     * three most-recent unique support and resistance prices (dedup within
     * 0.5%). Most-recent first.
     *
     * @param list<array{time:int,open:float,high:float,low:float,close:float}> $series
     * @return array{0:list<float>,1:list<float>}
     */
    private static function supportResistance(array $series, int $lookback, int $k = 2, int $keep = 3, float $minSepPct = 0.005): array
    {
        $window = array_slice($series, -max($lookback, 2 * $k + 1));
        $n = count($window);
        $highs = [];
        $lows  = [];
        for ($i = $k; $i < $n - $k; $i++) {
            $h = $window[$i]['high'];
            $l = $window[$i]['low'];
            $isHigh = true;
            $isLow  = true;
            for ($j = 1; $j <= $k; $j++) {
                if ($window[$i - $j]['high'] >= $h || $window[$i + $j]['high'] >= $h) $isHigh = false;
                if ($window[$i - $j]['low']  <= $l || $window[$i + $j]['low']  <= $l) $isLow  = false;
                if (!$isHigh && !$isLow) break;
            }
            if ($isHigh) $highs[] = $h;
            if ($isLow)  $lows[]  = $l;
        }
        return [self::dedupRecent($lows, $keep, $minSepPct), self::dedupRecent($highs, $keep, $minSepPct)];
    }

    /** @param list<float> $pivots @return list<float> most-recent first, dedup within $minSepPct */
    private static function dedupRecent(array $pivots, int $keep, float $minSepPct): array
    {
        $out = [];
        for ($i = count($pivots) - 1; $i >= 0 && count($out) < $keep; $i--) {
            $p = $pivots[$i];
            $tooClose = false;
            foreach ($out as $q) {
                if (abs($p - $q) / max($q, 1e-12) < $minSepPct) {
                    $tooClose = true;
                    break;
                }
            }
            if (!$tooClose) $out[] = $p;
        }
        return $out;
    }
}
