<?php

declare(strict_types=1);

namespace backend\controllers;

use common\jobs\ScanSignalsJob;
use common\services\Trading\DeltaAlignment;
use common\services\Trading\MarketData;
use common\services\Trading\RsiBoard;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
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
 *   /trading/rsi-refresh   POST: queue a background data refresh + board rebuild
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
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['rsi-refresh' => ['post']],
            ],
        ];
    }

    public function actionIndex(): Response
    {
        return $this->redirect(['rsi']);
    }

    /**
     * Coin listing with multi-timeframe RSI — a Bybit-only revival of the
     * rsi-module heatmap. Each coin shows Wilder RSI(14) on 1d/4h/1h/15m.
     *
     * The page only reads the precomputed board ({@see RsiBoard}); it is
     * rebuilt in the background after every price refresh, so opening the page
     * never decodes the history files. Prices and the forming bar of every
     * timeframe are as of the last build's live tickers call.
     *
     * The default sort ('tiered') ranks rows by their overbought/oversold
     * condition hierarchically — 1D first, then 4H, 1H, 15m — with oversold at
     * the top (see {@see tieredCmp}). Clicking a column header sorts by that one
     * column instead.
     *
     *   /trading/rsi
     *   /trading/rsi?sort=1h&dir=desc
     */
    public function actionRsi(string $sort = 'tiered', string $dir = 'asc'): string
    {
        $board = (new RsiBoard())->load();
        $rows  = $board['rows'] ?? [];

        $allowed = ['tiered', 'symbol', 'price', 'chg24h', '1h', '4h', '1d', '15m', 'turnover20'];
        if (!in_array($sort, $allowed, true)) {
            $sort = 'tiered';
        }
        $mul = $dir === 'desc' ? -1 : 1;

        if ($sort === 'tiered') {
            usort($rows, static fn(array $a, array $b): int => $mul * self::tieredCmp($a, $b));
        } else {
            $val = static fn(array $r) => in_array($sort, self::RSI_TFS, true) ? ($r['rsi'][$sort] ?? null) : ($r[$sort] ?? null);
            usort($rows, static function (array $a, array $b) use ($val, $mul, $sort): int {
                $va = $val($a);
                $vb = $val($b);
                if ($va === null && $vb === null) return 0;
                if ($va === null) return 1;   // nulls always last
                if ($vb === null) return -1;
                return $mul * ($sort === 'symbol' ? strcmp((string)$va, (string)$vb) : ($va <=> $vb));
            });
        }

        return $this->render('rsi', [
            'rows'    => $rows,
            'updated' => isset($board['updated']) ? (int)$board['updated'] : null,
            'dataTs'  => isset($board['data_ts']) ? (int)$board['data_ts'] : null,
            'pricesTs' => isset($board['prices_ts']) ? (int)$board['prices_ts'] : null,
            'pending' => ScanSignalsJob::oneOffPending(),
            'sort'    => $sort,
            'dir'     => $dir,
            'tfs'     => self::RSI_TFS,
        ]);
    }

    /**
     * "Refresh" button: queue a one-off data refresh, which rebuilds the board
     * when it finishes. Returns immediately — the worker does the fetching.
     */
    public function actionRsiRefresh(string $sort = 'tiered', string $dir = 'asc'): Response
    {
        try {
            $id = ScanSignalsJob::queueOneOff('rsi-page');
            Yii::$app->session->setFlash(
                'info',
                $id !== null
                    ? "Refresh queued (job #{$id}). The board updates when the worker finishes it — reload in a minute."
                    : 'A refresh is already queued — reload in a minute.',
            );
        } catch (\Throwable $e) {
            Yii::error('RSI refresh could not be queued: ' . $e->getMessage(), 'trading.scan');
            Yii::$app->session->setFlash('error', 'Could not queue the refresh: ' . $e->getMessage());
        }
        return $this->redirect(['rsi', 'sort' => $sort, 'dir' => $dir]);
    }

    /** Timeframes shown on the heatmap, in sort-hierarchy order (1D dominant → 15m). */
    private const RSI_TFS = ['1d', '4h', '1h', '15m'];

    /** RSI thresholds for the overbought/oversold buckets used by the tiered sort. */
    private const RSI_OVERSOLD = 30.0;
    private const RSI_OVERBOUGHT = 70.0;

    /** Bucket one RSI value: 0 = oversold, 1 = neutral, 2 = overbought, 3 = missing. */
    private static function rsiTier(?float $v): int
    {
        if ($v === null) {
            return 3;
        }
        if ($v <= self::RSI_OVERSOLD) {
            return 0;
        }
        if ($v >= self::RSI_OVERBOUGHT) {
            return 2;
        }
        return 1;
    }

    /**
     * Default board ordering: compare the overbought/oversold tier on each
     * timeframe in hierarchy order (1D, then 4H, 1H, 15m). The first timeframe
     * whose tiers differ decides — oversold (tier 0) sorts to the top, then
     * neutral, then overbought; rows missing a timeframe sink below. When every
     * tier matches, fall back to the raw 1D RSI (more oversold first) and then
     * the symbol for a stable order.
     */
    private static function tieredCmp(array $a, array $b): int
    {
        foreach (self::RSI_TFS as $tf) {
            $ta = self::rsiTier(self::numOrNull($a['rsi'][$tf] ?? null));
            $tb = self::rsiTier(self::numOrNull($b['rsi'][$tf] ?? null));
            if ($ta !== $tb) {
                return $ta <=> $tb;
            }
        }
        $ra = self::numOrNull($a['rsi']['1d'] ?? null);
        $rb = self::numOrNull($b['rsi']['1d'] ?? null);
        if ($ra !== $rb) {
            if ($ra === null) return 1;
            if ($rb === null) return -1;
            return $ra <=> $rb;   // ascending: more oversold first
        }
        return strcmp((string)$a['symbol'], (string)$b['symbol']);
    }

    private static function numOrNull(mixed $v): ?float
    {
        return is_numeric($v) ? (float)$v : null;
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
        // The stored daily candle for today froze at its first fetch after
        // 00:00 UTC; the RSI board carries it re-priced at the live price.
        $liveDay = RsiBoard::liveDays((new RsiBoard())->load(), [$symbol])[$symbol] ?? null;
        $candles = RsiBoard::withLiveDay($md->dailyCandles($symbol), $liveDay);
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
            'pricesTs'    => $liveDay['at'] ?? null,
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
        $liveDays = RsiBoard::liveDays((new RsiBoard())->load(), $picked);
        $pricedAt = array_filter(array_column($liveDays, 'at'));
        $loaded = [];
        $lastTs = 0;
        foreach ($picked as $sym) {
            $candles = RsiBoard::withLiveDay($md->dailyCandles($sym), $liveDays[$sym] ?? null);
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
            'pricesTs'    => $pricedAt !== [] ? min($pricedAt) : null,   // the oldest, if they differ
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
