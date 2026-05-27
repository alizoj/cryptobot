<?php

declare(strict_types=1);

namespace backend\controllers;

use common\services\Trading\MarketData;
use common\services\Trading\PositionStore;
use common\services\Trading\SignalEngine;
use common\services\Trading\TelegramNotifier;
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
                'actions' => ['test-telegram' => ['post']],
            ],
        ];
    }

    public function actionIndex(): string
    {
        $store = new PositionStore();
        return $this->render('index', [
            'open'    => $store->openPositions(),
            'closed'  => array_slice($store->closedPositions(), -25),
            'signals' => array_reverse($store->recentSignals(40)),
            'telegramConfigured' => (new TelegramNotifier())->isConfigured(),
            'params'  => Yii::$app->params['trading'] ?? [],
        ]);
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

        return $this->render('chart', [
            'symbol' => $symbol,
            'series' => $series,
            'entry'  => $entry !== null && $entry !== '' ? (float)$entry : null,
            'stop'   => $stop  !== null && $stop  !== '' ? (float)$stop  : null,
            'side'   => $side,
            'setup'  => $setup,
            'asOf'   => $asOf,
        ]);
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
