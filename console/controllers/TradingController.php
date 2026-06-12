<?php

declare(strict_types=1);

namespace console\controllers;

use common\jobs\DeltaBoardJob;
use common\jobs\ScanSignalsJob;
use common\services\Trading\ChartRenderer;
use common\services\Trading\DataFetcher;
use common\services\Trading\DeltaAlignment;
use common\services\Trading\MarketData;
use common\services\Trading\TelegramNotifier;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Trading cron entry points.
 *
 * The signal engine has been removed — a new strategy is being built. All the
 * old scan/manage commands are kept as no-op stubs so existing cron lines and
 * queue chains keep running without errors; the data-refresh commands still do
 * real work so the coin charts and the RSI heatmap stay current.
 *
 *   ./yii trading/fetch-data          — refresh prices, derivs, dominance, FNG
 *   ./yii trading/delta-board         — rebuild the DELTA_ALIGNMENT board (run every 5 min)
 *   ./yii trading/schedule-delta      — start the candle-aligned 5-min board loop (queue)
 *   ./yii trading/scan                — no-op stub (signal engine removed)
 *   ./yii trading/scan-4h             — no-op stub (signal engine removed)
 *   ./yii trading/manage-positions    — no-op stub (signal engine removed)
 *   ./yii trading/run-daily           — fetch-data only
 *   ./yii trading/run-hourly          — incremental prices + derivatives refresh
 *   ./yii trading/run-4h              — incremental prices + derivatives refresh
 *   ./yii trading/test-telegram       — send a hello-world to confirm credentials
 *   ./yii trading/test-chart SYMBOL   — render a candlestick chart and send it
 *   ./yii trading/queue-scan          — push a one-off data refresh onto the queue
 *   ./yii trading/schedule-hourly     — start the hourly self-rescheduling refresh loop
 */
final class TradingController extends Controller
{
    /** @var string accepted for cron compatibility; unused by the stubs. */
    public string $asOf = '';

    /** @var bool accepted for cron compatibility; unused by the stubs. */
    public bool $dryRun = false;

    /** @var bool accepted for cron compatibility; unused by the stubs. */
    public bool $includeDormant = false;

    public function options($actionID): array
    {
        return match ($actionID) {
            'scan', 'manage-positions', 'run-daily', 'run-hourly'
                => ['asOf', 'dryRun', 'includeDormant'],
            'scan-4h', 'run-4h' => ['asOf', 'dryRun'],
            default => [],
        };
    }

    public function optionAliases(): array
    {
        return ['d' => 'dryRun', 'a' => 'asOf'];
    }

    // ---------- fetch ----------

    public function actionFetchData(): int
    {
        $this->stdout("[fetch-data] " . gmdate('c') . "\n", Console::FG_GREEN);
        $fetcher = new DataFetcher();
        $results = $fetcher->refreshAll([], fn(string $line) => $this->stdout("    $line\n"));
        $fetcher->recordCheckpoint($results);
        $allOk = true;
        foreach ($results as $r) {
            $tag = $r->ok ? 'OK ' : 'FAIL';
            $this->stdout(
                sprintf("  %s  %-12s  %.1fs  %s\n", $tag, $r->step, $r->seconds, $r->summary),
                $r->ok ? Console::FG_GREEN : Console::FG_RED,
            );
            $allOk = $allOk && $r->ok;
        }
        return $allOk ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Rebuild the DELTA_ALIGNMENT board — closed-candle SMA15/SMA50 sums per
     * symbol and timeframe (5m/30m/1h/2h), consumed live by /trading/delta.
     * Run it every 5 minutes (OS scheduler), or start the queue-native loop
     * with {@see actionScheduleDelta} instead.
     *
     *   ./yii trading/delta-board
     */
    public function actionDeltaBoard(): int
    {
        $this->stdout("[delta-board] " . gmdate('c') . "\n", Console::FG_GREEN);
        try {
            $sum = (new DeltaAlignment())->rebuildBoard(fn(string $line) => $this->stdout("    $line\n"));
        } catch (\Throwable $e) {
            $this->stderr("FAILED: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(sprintf(
            "  OK   %d symbols  %d kline calls (%d reused)  %d failed  %.1fs\n",
            $sum['symbols'],
            $sum['calls'],
            $sum['reused'],
            $sum['failed'],
            $sum['seconds'],
        ), $sum['failed'] === 0 ? Console::FG_GREEN : Console::FG_YELLOW);
        return ExitCode::OK;
    }

    /**
     * Start (or restart) the 5-minute DELTA_ALIGNMENT board loop — the same
     * queue-native, generation-tokened machinery as schedule-hourly, but each
     * run lands just after a 5m candle closes so the board stays in lockstep
     * with the exchange.
     *
     *   ./yii trading/schedule-delta         # start/restart the loop
     *   ./yii queue/listen --verbose=1       # worker that runs the jobs
     */
    public function actionScheduleDelta(int $intervalSeconds = 300): int
    {
        $gen = (int)Yii::$app->redis->incr(DeltaBoardJob::SCHEDULE_GEN_KEY);
        $id = Yii::$app->queue->push(new DeltaBoardJob([
            'recurring'       => true,
            'intervalSeconds' => $intervalSeconds,
            'source'          => 'schedule',
            'scheduleGen'     => $gen,
        ]));
        $this->stdout("started delta-board loop (boundary={$intervalSeconds}s, gen={$gen}, job id={$id}).\n", Console::FG_GREEN);
        if ($gen > 1) {
            $this->stdout("Superseded the previous chain — older loops stop after their next run.\n", Console::FG_YELLOW);
        }
        $this->stdout("Run the worker to process it: ./yii queue/listen --verbose=1\n");
        return ExitCode::OK;
    }

    // ---------- signal stubs (engine removed, new logic pending) ----------

    public function actionScan(): int
    {
        return $this->noop('scan');
    }

    public function actionScan4h(): int
    {
        return $this->noop('scan-4h');
    }

    public function actionManagePositions(): int
    {
        return $this->noop('manage-positions');
    }

    // ---------- combined ----------

    public function actionRunDaily(): int
    {
        $this->stdout("[run-daily] " . gmdate('c') . "\n", Console::FG_GREEN);
        return $this->actionFetchData();
    }

    public function actionRunHourly(): int
    {
        $this->stdout("[run-hourly] " . gmdate('c') . "\n", Console::FG_GREEN);
        return $this->refreshIncremental();
    }

    public function actionRun4h(): int
    {
        $this->stdout("[run-4h] " . gmdate('c') . "\n", Console::FG_GREEN);
        return $this->refreshIncremental();
    }

    // ---------- ops ----------

    public function actionTestTelegram(): int
    {
        $notifier = new TelegramNotifier();
        if (!$notifier->isConfigured()) {
            $this->stderr("Telegram is not configured. Set telegram.botToken + telegram.chatId in common/config/params-local.php\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }
        $msg = "<b>Crypto Bot</b>\nTest message at " . gmdate('c') . "\nIf you see this, credentials work.";
        $res = $notifier->send($msg);
        if ($res['ok']) {
            $this->stdout("OK — message delivered.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }
        $this->stderr("FAILED: " . ($res['error'] ?? '?') . "\n", Console::FG_RED);
        return ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Render a candlestick chart for one symbol and send it to Telegram.
     * Verifies the QuickChart -> Telegram image pipeline end-to-end.
     *
     *   ./yii trading/test-chart BTC
     */
    public function actionTestChart(string $symbol): int
    {
        $symbol = strtoupper($symbol);
        $notifier = new TelegramNotifier();
        if (!$notifier->isConfigured()) {
            $this->stderr("Telegram is not configured. Set telegram.botToken + telegram.chatId in common/config/params-local.php\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $candles = (new MarketData())->dailyCandles($symbol);
        if ($candles === []) {
            $this->stderr("No daily candles for {$symbol} — run trading/fetch-data first.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $chart = new ChartRenderer();
        $config = $chart->buildConfig(
            symbol:  $symbol,
            setup:   'TEST',
            side:    'long',
            asOf:    gmdate('Y-m-d'),
            entryPx: null,
            stopPx:  null,
            candles: $candles,
        );
        $url = $chart->createShortUrl($config);
        if ($url === null) {
            $this->stderr("QuickChart did not return a URL (network or config issue).\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("chart url: {$url}\n");

        $res = $notifier->sendPhoto($url, "Test chart — {$symbol}");
        if ($res['ok']) {
            $this->stdout("OK — chart delivered.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }
        $this->stderr("FAILED: " . ($res['error'] ?? '?') . "\n", Console::FG_RED);
        return ExitCode::UNSPECIFIED_ERROR;
    }

    // ---------- queue ----------

    /**
     * Push a one-off data refresh onto the queue. A worker must be running to
     * process it: `./yii queue/listen`.
     */
    public function actionQueueScan(): int
    {
        $id = Yii::$app->queue->push(new ScanSignalsJob(['source' => 'cli']));
        $this->stdout("queued one-off data refresh (job id={$id}).\n", Console::FG_GREEN);
        $this->stdout("Process it with a worker: ./yii queue/listen --verbose=1\n");
        return ExitCode::OK;
    }

    /**
     * Start (or restart) the hourly refresh loop (queue-native, no cron).
     * Pushes one recurring job that re-enqueues the next run $intervalSeconds
     * later.
     *
     * Safe to re-run: each call bumps a generation token in Redis, and a
     * recurring job only reschedules itself while its generation is current.
     * So running this again supersedes the previous chain — any older loop
     * fires at most once more and then stops, instead of piling up parallel
     * hourly loops. No need to clear the queue first.
     *
     *   ./yii trading/schedule-hourly        # start/restart the loop (default 3600s)
     *   ./yii queue/listen --verbose=1       # worker that runs the jobs
     */
    public function actionScheduleHourly(int $intervalSeconds = 3600): int
    {
        $gen = (int)Yii::$app->redis->incr(ScanSignalsJob::SCHEDULE_GEN_KEY);
        $id = Yii::$app->queue->push(new ScanSignalsJob([
            'recurring'       => true,
            'intervalSeconds' => $intervalSeconds,
            'source'          => 'schedule',
            'scheduleGen'     => $gen,
        ]));
        $this->stdout("started hourly refresh loop (interval={$intervalSeconds}s, gen={$gen}, job id={$id}).\n", Console::FG_GREEN);
        if ($gen > 1) {
            $this->stdout("Superseded the previous chain — older loops stop after their next run.\n", Console::FG_YELLOW);
        }
        $this->stdout("Run the worker to process it: ./yii queue/listen --verbose=1\n");
        return ExitCode::OK;
    }

    // ---------- helpers ----------

    /** Old signal command kept as an idle stub so cron lines exit 0. */
    private function noop(string $name): int
    {
        $this->stdout("[{$name}] " . gmdate('c') . " — signal engine removed, nothing to do (new logic pending).\n", Console::FG_YELLOW);
        return ExitCode::OK;
    }

    /** Cheap incremental refresh — prices + derivatives (skip macro to keep it under a minute). */
    private function refreshIncremental(): int
    {
        $f = new DataFetcher();
        $forward = fn(string $line) => $this->stdout("    $line\n");
        $r = array_merge($f->refreshPrices([], $forward), $f->refreshDerivatives([], $forward));
        $f->recordCheckpoint($r);
        $allOk = true;
        foreach ($r as $res) {
            $tag = $res->ok ? 'OK ' : 'FAIL';
            $this->stdout(
                sprintf("  %s  %-12s  %.1fs  %s\n", $tag, $res->step, $res->seconds, $res->summary),
                $res->ok ? Console::FG_GREEN : Console::FG_RED,
            );
            $allOk = $allOk && $res->ok;
        }
        return $allOk ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
