<?php

declare(strict_types=1);

namespace backend\controllers;

use common\jobs\ScanSignalsJob;
use common\services\Trading\CoinMetrics;
use common\services\Trading\MarketData;
use common\services\Trading\PositionStore;
use common\services\Trading\ScanRunner;
use common\services\Trading\SignalEngine;
use common\services\Trading\TelegramNotifier;
use common\services\Trading\UiSettings;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Backend admin views for the Experiment-8 trading bot.
 *
 *   /trading                 dashboard: KPIs + open positions + signals + closed P&L
 *   /trading/scan-preview    dry-run daily scan for any UTC day (does not send)
 *   /trading/scan4h-preview  dry-run 4h MTF scan (L5/S6) at a 4h close
 *   /trading/chart           interactive candlestick for a symbol/signal
 *   /trading/test-telegram   POST: send a test message
 */
final class TradingController extends Controller
{
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    ['allow' => true, 'roles' => ['@']],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'test-telegram'  => ['post'],
                    'save-settings'  => ['post'],
                    'close-position' => ['post'],
                    'close-bulk'     => ['post'],
                    'close-all'      => ['post'],
                    'scan-now'       => ['post'],
                ],
            ],
        ];
    }

    public function actionIndex(): string
    {
        $store = new PositionStore();
        $md    = new MarketData();
        $open  = $store->openPositions();

        $unrealisedUsd = 0.0;
        foreach ($open as &$p) {
            $candles = $md->dailyCandles((string)($p['symbol'] ?? ''));
            $last = $candles !== [] ? end($candles) : null;
            $entry = (float)($p['entry_px'] ?? 0);
            if ($last === null || $entry <= 0) {
                $p['last_px'] = $p['last_ts'] = $p['pnl_pct'] = $p['pnl_usd'] = null;
                continue;
            }
            $lastPx = (float)$last['c'];
            $units  = (float)($p['size']['size_units'] ?? 0);
            $pct = ($p['side'] ?? '') === 'long'
                ? $lastPx / $entry - 1
                : $entry / $lastPx - 1;
            $usd = ($p['side'] ?? '') === 'long'
                ? ($lastPx - $entry) * $units
                : ($entry - $lastPx) * $units;
            $p['last_px'] = $lastPx;
            $p['last_ts'] = (int)$last['ts'];
            $p['pnl_pct'] = $pct;
            $p['pnl_usd'] = $usd;
            $unrealisedUsd += $usd;
        }
        unset($p);

        return $this->render('index', [
            'open'    => $open,
            'closed'  => array_slice($store->closedPositions(), -25),
            'signals' => array_reverse($store->recentSignals(40)),
            'telegramConfigured' => (new TelegramNotifier())->isConfigured(),
            'params'  => Yii::$app->params['trading'] ?? [],
            'unrealisedUsd' => $unrealisedUsd,
            'uiSettings'    => UiSettings::load(),
            'uiTemplates'   => UiSettings::TEMPLATES,
        ]);
    }

    public function actionSaveSettings(): Response
    {
        $patch = UiSettings::resolve(Yii::$app->request->post());
        UiSettings::save($patch);
        Yii::$app->session->setFlash('success', 'Equity saved.');
        return $this->redirect(['index']);
    }

    /**
     * One-click signal scan: push a job onto the queue (refresh prices →
     * manage open positions → daily + 4h scan → send/open signals). A worker
     * (`./yii queue/listen`) picks it up; the page returns immediately rather
     * than blocking the request on the fetch + scan.
     */
    public function actionScanNow(): Response
    {
        try {
            $id = Yii::$app->queue->push(new ScanSignalsJob(['source' => 'dashboard']));
            Yii::$app->session->setFlash('success',
                "Сканирование поставлено в очередь (job #{$id}). Новые сигналы появятся, как только их обработает воркер.");
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', 'Не удалось поставить задачу в очередь: ' . $e->getMessage());
        }
        return $this->redirect(['index']);
    }

    /**
     * Close one open position by id, marking it 'manual_close' with P&L
     * vs the latest daily close, then run daily + 4h scans so newly-fired
     * signals can fill the freed slot up to `maxConcurrentSingles`.
     */
    public function actionClosePosition(): Response
    {
        $id   = (string)Yii::$app->request->post('id', '');
        $store = new PositionStore();
        $open  = $store->openPositions();

        $closing   = null;
        $remaining = [];
        foreach ($open as $p) {
            if (($p['id'] ?? '') === $id && $closing === null) {
                $closing = $p;
            } else {
                $remaining[] = $p;
            }
        }
        if ($closing === null) {
            Yii::$app->session->setFlash('error', "Position not found: $id");
            return $this->redirect(['index']);
        }

        $candles = (new MarketData())->dailyCandles((string)$closing['symbol']);
        $last    = $candles !== [] ? end($candles) : null;
        $entry   = (float)$closing['entry_px'];
        $exitPx  = $last !== null ? (float)$last['c'] : $entry;
        $pnlPct  = $entry > 0
            ? (($closing['side'] ?? '') === 'long' ? $exitPx / $entry - 1 : $entry / $exitPx - 1)
            : 0.0;

        $closing['outcome'] = [
            'exit_ts'     => time(),
            'exit_day'    => gmdate('Y-m-d'),
            'exit_px'     => $exitPx,
            'exit_reason' => 'manual_close',
            'pnl_pct'     => $pnlPct,
        ];
        $store->appendClosed($closing);
        $store->replaceOpen($remaining);

        // Broadcast + log the manual close, then re-scan to fill the freed slot.
        // Both go through ScanRunner so the dashboard path has the same side
        // effects as the queue path (Telegram alert + signal_log entry).
        $runner = new ScanRunner();
        $runner->emitEvents([[
            'kind'      => 'manual_close',
            'symbol'    => $closing['symbol'],
            'setup'     => $closing['setup'],
            'side'      => $closing['side'] ?? '',
            'entry_px'  => $closing['entry_px'],
            'entry_day' => $closing['entry_day'] ?? '',
            'exit_px'   => $exitPx,
            'pnl_pct'   => $pnlPct,
            'note'      => 'Closed manually from dashboard.',
        ]], gmdate('Y-m-d'), false);

        $engine = new SignalEngine();
        $opened = $runner->emitSignals($engine->scan(null)['signals'], false, null, onlyNotifyOpened: true)['opened']
                + $runner->emitSignals($engine->scanIntraday(null)['signals'], false, null, onlyNotifyOpened: true)['opened'];

        Yii::$app->session->setFlash('success', sprintf(
            'Closed %s %s at %s (%+.2f%%). %d new position(s) opened from re-scan.',
            $closing['symbol'], $closing['setup'],
            rtrim(rtrim(sprintf('%.6f', $exitPx), '0'), '.'),
            $pnlPct * 100, $opened,
        ));
        return $this->redirect(['index']);
    }

    /**
     * Bulk-close: same semantics as actionClosePosition but for a list of ids
     * submitted as `ids[]`. The re-scan runs once at the end, not per-close.
     */
    public function actionCloseBulk(): Response
    {
        $ids = (array)Yii::$app->request->post('ids', []);
        $ids = array_values(array_filter(array_map('strval', $ids), static fn($s) => $s !== ''));
        if ($ids === []) {
            Yii::$app->session->setFlash('error', 'No positions selected.');
            return $this->redirect(['index']);
        }

        $store = new PositionStore();
        $idSet = array_flip($ids);

        $closing   = [];
        $remaining = [];
        foreach ($store->openPositions() as $p) {
            if (isset($idSet[(string)($p['id'] ?? '')])) {
                $closing[] = $p;
            } else {
                $remaining[] = $p;
            }
        }
        if ($closing === []) {
            Yii::$app->session->setFlash('error', 'None of the selected positions were found.');
            return $this->redirect(['index']);
        }

        $opened = $this->closeAndRescan($store, $closing, $remaining);

        Yii::$app->session->setFlash('success', sprintf(
            'Closed %d position(s). %d new position(s) opened from re-scan.',
            count($closing), $opened,
        ));
        return $this->redirect(['index']);
    }

    /**
     * Close every open position in one click, then run daily + 4h scans so a
     * fresh set of entries can fill the now-empty book up to
     * `maxConcurrentSingles`. Same close semantics as actionCloseBulk.
     */
    public function actionCloseAll(): Response
    {
        $store = new PositionStore();
        $open  = $store->openPositions();
        if ($open === []) {
            Yii::$app->session->setFlash('error', 'No open positions to close.');
            return $this->redirect(['index']);
        }

        $opened = $this->closeAndRescan($store, $open, []);

        Yii::$app->session->setFlash('success', sprintf(
            'Closed all %d position(s). %d new position(s) opened from re-scan.',
            count($open), $opened,
        ));
        return $this->redirect(['index']);
    }

    /**
     * Close $closing (mark each 'manual_close' with P&L vs the latest daily
     * close, append to the closed journal), persist $remaining as the new open
     * list, then broadcast the closes and re-scan daily + 4h through ScanRunner
     * so the dashboard path matches the queue path (Telegram + signal_log).
     *
     * @param list<array<string,mixed>> $closing
     * @param list<array<string,mixed>> $remaining
     * @return int number of new positions opened by the re-scan
     */
    private function closeAndRescan(PositionStore $store, array $closing, array $remaining): int
    {
        $md     = new MarketData();
        $events = [];
        foreach ($closing as $p) {
            $candles = $md->dailyCandles((string)$p['symbol']);
            $last    = $candles !== [] ? end($candles) : null;
            $entry   = (float)$p['entry_px'];
            $exitPx  = $last !== null ? (float)$last['c'] : $entry;
            $pnlPct  = $entry > 0
                ? (($p['side'] ?? '') === 'long' ? $exitPx / $entry - 1 : $entry / $exitPx - 1)
                : 0.0;
            $p['outcome'] = [
                'exit_ts'     => time(),
                'exit_day'    => gmdate('Y-m-d'),
                'exit_px'     => $exitPx,
                'exit_reason' => 'manual_close',
                'pnl_pct'     => $pnlPct,
            ];
            $store->appendClosed($p);
            $events[] = [
                'kind'      => 'manual_close',
                'symbol'    => $p['symbol'],
                'setup'     => $p['setup'],
                'side'      => $p['side'] ?? '',
                'entry_px'  => $p['entry_px'],
                'entry_day' => $p['entry_day'] ?? '',
                'exit_px'   => $exitPx,
                'pnl_pct'   => $pnlPct,
                'note'      => 'Closed manually from dashboard.',
            ];
        }
        $store->replaceOpen($remaining);

        $runner = new ScanRunner();
        $runner->emitEvents($events, gmdate('Y-m-d'), false);

        $engine = new SignalEngine();
        return $runner->emitSignals($engine->scan(null)['signals'], false, null, onlyNotifyOpened: true)['opened']
             + $runner->emitSignals($engine->scanIntraday(null)['signals'], false, null, onlyNotifyOpened: true)['opened'];
    }

    public function actionScanPreview(?string $asOf = null): string
    {
        $engine = new SignalEngine();
        $result = $engine->scan($asOf);
        return $this->render('scan-preview', [
            'asOf'        => $result['as_of'],
            'signals'     => $result['signals'],
            'diagnostics' => $result['diagnostics'],
            'intraday'    => false,
        ]);
    }

    /**
     * Dry-run preview of the 4h MTF setups (L5 / S6) at a 4h close.
     * $asOf accepts a UTC datetime ('2026-05-14 16:00'); default = latest bar.
     */
    public function actionScan4hPreview(?string $asOf = null): string
    {
        $asOfMs = ($asOf !== null && $asOf !== '') ? (int)(strtotime($asOf . ' UTC') * 1000) : null;
        $engine = new SignalEngine();
        $result = $engine->scanIntraday($asOfMs ?: null);
        return $this->render('scan-preview', [
            'asOf'        => $result['as_of'],
            'signals'     => $result['signals'],
            'diagnostics' => $result['diagnostics'],
            'intraday'    => true,
        ]);
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

    /** 20-day average daily turnover (close × volume), matching Features. */
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
     * Interactive candlestick chart for one symbol (TradingView Lightweight
     * Charts). Entry/stop/side/setup are passed via query string so we can draw
     * the signal's price lines without re-running a scan.
     *
     *   /trading/chart?symbol=BTC&entry=65000&stop=62000&side=long&setup=L1&asOf=2026-05-26
     */
    public function actionChart(
        string $symbol,
        ?string $entry = null,
        ?string $stop = null,
        ?string $side = null,
        ?string $setup = null,
        ?string $asOf = null,
        int $zoom = 90,
    ): string {
        $symbol  = strtoupper($symbol);
        $candles = (new MarketData())->dailyCandles($symbol);
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

        // Default visible window = last $zoom days (clamped to [14, 365]) so
        // candles render at a useful size; older bars stay scrollable.
        $zoom = max(14, min(365, $zoom));
        $lastTs    = (int)$series[count($series) - 1]['time'];
        $visibleFrom = $lastTs - $zoom * 86400;
        $visibleTo   = $lastTs + 5 * 86400;  // small right-side padding

        // S/R levels: 5-bar fractal pivots over the trailing ~zoom+30 candles,
        // keep the 3 most-recent uniques on each side (deduped within 0.5%).
        [$support, $resistance] = self::supportResistance($series, $zoom + 30);

        return $this->render('chart', [
            'symbol' => $symbol,
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

    public function actionTestTelegram(): Response
    {
        $tg = new TelegramNotifier();
        if (!$tg->isConfigured()) {
            Yii::$app->session->setFlash('error', 'Telegram not configured. Edit common/config/params-local.php.');
            return $this->redirect(['index']);
        }
        $res = $tg->send("<b>Crypto Bot</b>\nTest from admin at " . gmdate('c'));
        if ($res['ok']) {
            Yii::$app->session->setFlash('success', 'Test message delivered.');
        } else {
            Yii::$app->session->setFlash('error', 'Telegram send failed: ' . ($res['error'] ?? '?'));
        }
        return $this->redirect(['index']);
    }
}
