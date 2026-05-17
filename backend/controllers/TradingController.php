<?php

declare(strict_types=1);

namespace backend\controllers;

use common\services\Trading\PositionStore;
use common\services\Trading\SignalEngine;
use common\services\Trading\TelegramNotifier;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

/**
 * Backend admin views for the Experiment-8 trading bot.
 *
 *   /trading                 dashboard: open positions + recent signals + diag
 *   /trading/scan-preview    dry-run scan for any UTC day (does not send)
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
