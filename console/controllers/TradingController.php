<?php

declare(strict_types=1);

namespace console\controllers;

use common\services\Trading\ChartRenderer;
use common\services\Trading\DataFetcher;
use common\services\Trading\MarketData;
use common\services\Trading\Paths;
use common\services\Trading\PositionManager;
use common\services\Trading\PositionStore;
use common\services\Trading\SignalEngine;
use common\services\Trading\TelegramNotifier;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Trading-bot cron entry points.
 *
 *   ./yii trading/fetch-data          — refresh prices, derivs, dominance, FNG
 *   ./yii trading/scan                — evaluate Experiment 8 triggers + send entry signals (daily)
 *   ./yii trading/scan-4h             — evaluate the 4h MTF setups (L5/S6) at the latest 4h close
 *   ./yii trading/run-4h              — refresh prices + derivatives, then scan-4h (one self-contained 4h job)
 *   ./yii trading/manage-positions    — trail / funding-flip / T+20 / stop-hit lifecycle
 *   ./yii trading/run-daily           — fetch-data + scan + manage-positions
 *   ./yii trading/run-hourly          — manage-positions (intra-day funding-flip + price moves)
 *   ./yii trading/test-telegram       — send a hello-world to confirm credentials
 *   ./yii trading/test-chart SYMBOL   — render a candlestick chart and send it (verifies image pipeline)
 *   ./yii trading/status              — print open positions + last signals
 *
 * Recommended cron (server timezone irrelevant — all logic is in UTC):
 *
 *   5 0    * * *  cd /path/to/project && ./yii trading/run-daily  >> runtime/logs/trading.log 2>&1
 *   5 *    * * *  cd /path/to/project && ./yii trading/run-hourly >> runtime/logs/trading.log 2>&1
 *   2 0,4,8,12,16,20 * * *  cd /path/to/project && ./yii trading/scan-4h >> runtime/logs/trading.log 2>&1
 *
 * On Windows use Task Scheduler with the same schedule; see
 * docs/TRADING_BOT_SETUP.md for the exact actions.
 */
final class TradingController extends Controller
{
    /** @var string YYYY-MM-DD override for the as-of day used by scan/manage. */
    public string $asOf = '';

    /** @var bool dry-run: do everything except actually sending Telegram messages */
    public bool $dryRun = false;

    /** @var bool also include DORMANT setups (S1/S2) in scan output. */
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

    // ---------- scan ----------

    public function actionScan(): int
    {
        $asOf = $this->resolveAsOf();
        $this->stdout("[scan] as_of={$asOf} dry_run=" . ($this->dryRun ? 'yes' : 'no') . "\n", Console::FG_GREEN);

        $engine = new SignalEngine();
        $result = $engine->scan($asOf);

        $diag = $result['diagnostics'];
        $this->stdout(sprintf(
            "  scanned %d/%d  cluster_dumps_yday=%d%s  funding=%s  exit=T+%d\n",
            $diag['symbols_scanned'], $diag['symbols_total'],
            $diag['cluster_dumps_yday'],
            $diag['cluster_pause_active'] ? ' (PAUSED non-L1)' : '',
            $diag['funding_mode'], $diag['time_exit_days'],
        ));
        if (!empty($diag['skip_reasons'])) {
            $bits = [];
            foreach ($diag['skip_reasons'] as $k => $v) {
                $bits[] = "$k=$v";
            }
            $this->stdout("  skip: " . implode(' ', $bits) . "\n");
        }
        $this->stdout("  signals=" . count($result['signals']) . "\n");

        $store    = new PositionStore();
        $notifier = new TelegramNotifier();
        $chart    = new ChartRenderer();
        $md       = new MarketData();

        // Send a one-line scan summary first
        $summary = TelegramNotifier::formatScanSummary($asOf, count($result['signals']), $diag);
        $this->maybeSend($notifier, $summary, 'scan_summary');

        $sent = 0;
        $deduped = 0;
        foreach ($result['signals'] as $sig) {
            $id = PositionStore::signalId($sig['as_of'], $sig['symbol'], $sig['setup']);

            // Dedup: don't re-emit the same as_of+symbol+setup
            if ($store->hasOpenForSymbolSetup($sig['symbol'], $sig['setup'], $sig['as_of'])) {
                $deduped++;
                continue;
            }

            $msg = TelegramNotifier::formatEntrySignal($sig);
            $sendResult = $this->maybeSend($notifier, $msg, $id);

            // Attach a candlestick chart (entry/stop drawn in) when enabled.
            if ($chart->isEnabled() && ($sendResult['ok'] ?? false)) {
                $url = $chart->urlForSignal($sig, $md->dailyCandles($sig['symbol']));
                if ($url !== null) {
                    $caption = sprintf('%s %s %s', $sig['setup'], $sig['symbol'], strtoupper($sig['side']));
                    $photoRes = $notifier->sendPhoto($url, $caption);
                    if (!($photoRes['ok'] ?? false)) {
                        $this->stderr("    chart send FAILED ({$id}): " . ($photoRes['error'] ?? '?') . "\n", Console::FG_YELLOW);
                    }
                } else {
                    $this->stderr("    chart build FAILED ({$id})\n", Console::FG_YELLOW);
                }
            }

            $store->logSignal([
                'id'      => $id,
                'kind'    => 'entry',
                'ts'      => time(),
                'sent_ok' => $sendResult['ok'] ?? false,
                'signal'  => $sig,
            ]);

            if (!$this->dryRun && ($sendResult['ok'] ?? false)) {
                $store->appendOpen([
                    'id'           => $id,
                    'as_of'        => $sig['as_of'],
                    'symbol'       => $sig['symbol'],
                    'setup'        => $sig['setup'],
                    'side'         => $sig['side'],
                    'method'       => $sig['method'],
                    'entry_px'     => $sig['entry_px'] ?? $sig['entry_estimate'],
                    'entry_day'    => $sig['as_of'],
                    'stop_px'      => $sig['stop_px'],
                    'time_exit_days' => $sig['time_exit_days'],
                    'beta'         => $sig['beta'],
                    'size'         => $sig['size'],
                    'reasons'      => $sig['reasons'],
                    'context'      => $sig['context'],
                    'opened_ts'    => time(),
                    'stop_history' => [],
                ]);
                $sent++;
            }
            $this->stdout(sprintf("  %s %-8s %-3s %-4s entry=%s stop=%s sent=%s\n",
                $sig['setup'], $sig['symbol'], strtoupper($sig['side']), $sig['method'],
                $sig['entry_px'] !== null ? rtrim(rtrim(sprintf('%.6f', $sig['entry_px']), '0'), '.') : 'mkt',
                rtrim(rtrim(sprintf('%.6f', $sig['stop_px']), '0'), '.'),
                ($sendResult['ok'] ?? false) ? 'yes' : 'no'
            ));
        }
        $this->stdout("  sent=$sent dedup=$deduped\n");
        return ExitCode::OK;
    }

    // ---------- 4h MTF scan (L5 / S6) ----------

    /**
     * Intraday scan of the multi-timeframe setups at the latest completed 4h
     * close. This is where S6 (and L5's lower-TF oversold timing) actually
     * fire. Safe to run hourly: dedup is by the 4h-grained as_of, so a setup
     * won't re-open within the same 4h bar.
     *
     *   ./yii trading/scan-4h
     *   ./yii trading/scan-4h --asOf="2026-05-07 16:00" --dryRun
     */
    public function actionScan4h(): int
    {
        $asOfMs = $this->resolveAsOfMs();
        $engine = new SignalEngine();
        $result = $engine->scanIntraday($asOfMs);

        $diag = $result['diagnostics'];
        $this->stdout("[scan-4h] as_of={$result['as_of']} dry_run=" . ($this->dryRun ? 'yes' : 'no') . "\n", Console::FG_GREEN);
        $this->stdout(sprintf("  scanned %d/%d  signals=%d\n",
            $diag['symbols_scanned'], $diag['symbols_total'], count($result['signals'])));

        $store    = new PositionStore();
        $notifier = new TelegramNotifier();
        $chart    = new ChartRenderer();
        $md       = new MarketData();

        $sent = 0;
        $deduped = 0;
        foreach ($result['signals'] as $sig) {
            $id = PositionStore::signalId($sig['as_of'], $sig['symbol'], $sig['setup']);

            // Dedup per 4h bar (as_of is the 4h-bar datetime).
            if ($store->hasOpenForSymbolSetup($sig['symbol'], $sig['setup'], $sig['as_of'])) {
                $deduped++;
                continue;
            }

            $msg = TelegramNotifier::formatEntrySignal($sig);
            $sendResult = $this->maybeSend($notifier, $msg, $id);

            if ($chart->isEnabled() && ($sendResult['ok'] ?? false)) {
                $url = $chart->urlForSignal($sig, $md->dailyCandles($sig['symbol']));
                if ($url !== null) {
                    $notifier->sendPhoto($url, sprintf('%s %s %s', $sig['setup'], $sig['symbol'], strtoupper($sig['side'])));
                }
            }

            $store->logSignal([
                'id'      => $id,
                'kind'    => 'entry',
                'ts'      => time(),
                'sent_ok' => $sendResult['ok'] ?? false,
                'signal'  => $sig,
            ]);

            if (!$this->dryRun && ($sendResult['ok'] ?? false)) {
                $store->appendOpen([
                    'id'             => $id,
                    'as_of'          => $sig['as_of'],
                    'symbol'         => $sig['symbol'],
                    'setup'          => $sig['setup'],
                    'side'           => $sig['side'],
                    'method'         => $sig['method'],
                    'entry_px'       => $sig['entry_px'] ?? $sig['entry_estimate'],
                    'entry_day'      => $sig['as_of_day'],
                    'stop_px'        => $sig['stop_px'],
                    'time_exit_days' => $sig['time_exit_days'],
                    'beta'           => $sig['beta'],
                    'size'           => $sig['size'],
                    'reasons'        => $sig['reasons'],
                    'context'        => $sig['context'],
                    'opened_ts'      => time(),
                    'stop_history'   => [],
                ]);
                $sent++;
            }
            $this->stdout(sprintf("  %s %-8s %-5s stop=%s sent=%s\n",
                $sig['setup'], $sig['symbol'], strtoupper($sig['side']),
                rtrim(rtrim(sprintf('%.6f', $sig['stop_px']), '0'), '.'),
                ($sendResult['ok'] ?? false) ? 'yes' : 'no'));
        }
        $this->stdout("  sent=$sent dedup=$deduped\n");
        return ExitCode::OK;
    }

    // ---------- manage ----------

    public function actionManagePositions(): int
    {
        $asOf = $this->resolveAsOf(allowToday: true);
        $this->stdout("[manage-positions] as_of={$asOf} dry_run=" . ($this->dryRun ? 'yes' : 'no') . "\n", Console::FG_GREEN);

        $manager  = new PositionManager();
        $store    = new PositionStore();
        $notifier = new TelegramNotifier();

        $events = $manager->manage($asOf);
        $this->stdout("  events=" . count($events) . "\n");

        foreach ($events as $ev) {
            $id = "manage-{$asOf}-{$ev['symbol']}-{$ev['setup']}-{$ev['kind']}";
            $msg = TelegramNotifier::formatExitSignal($ev);
            $send = $this->maybeSend($notifier, $msg, $id);

            $store->logSignal([
                'id'      => $id,
                'kind'    => 'manage',
                'ts'      => time(),
                'sent_ok' => $send['ok'] ?? false,
                'event'   => $ev,
            ]);
            $this->stdout(sprintf("  %-18s %-8s %-3s sent=%s\n",
                $ev['kind'], $ev['symbol'], $ev['setup'], ($send['ok'] ?? false) ? 'yes' : 'no'));
        }
        return ExitCode::OK;
    }

    // ---------- combined ----------

    public function actionRunDaily(): int
    {
        $this->stdout("[run-daily] " . gmdate('c') . "\n", Console::FG_GREEN);
        $rc = $this->actionFetchData();
        if ($rc !== ExitCode::OK) {
            $this->stderr("fetch-data failed (exit $rc); continuing to scan/manage anyway\n", Console::FG_YELLOW);
        }
        $this->actionManagePositions();
        return $this->actionScan();
    }

    public function actionRunHourly(): int
    {
        $this->stdout("[run-hourly] " . gmdate('c') . "\n", Console::FG_GREEN);
        // Cheap incremental refresh — prices + derivatives (skip macro to keep it under a minute).
        $f = new DataFetcher();
        $forward = fn(string $line) => $this->stdout("    $line\n");
        $r = array_merge($f->refreshPrices([], $forward), $f->refreshDerivatives([], $forward));
        $f->recordCheckpoint($r);
        foreach ($r as $res) {
            $tag = $res->ok ? 'OK ' : 'FAIL';
            $this->stdout(
                sprintf("  %s  %-12s  %.1fs  %s\n", $tag, $res->step, $res->seconds, $res->summary),
                $res->ok ? Console::FG_GREEN : Console::FG_RED,
            );
        }
        return $this->actionManagePositions();
    }

    /**
     * Self-contained 4h job: refresh prices + derivatives (so the just-closed
     * 4h bar is on disk), then run the 4h MTF scan. Use this on a 4h cron
     * instead of relying on run-hourly + scan-4h ordering.
     */
    public function actionRun4h(): int
    {
        $this->stdout("[run-4h] " . gmdate('c') . "\n", Console::FG_GREEN);
        $f = new DataFetcher();
        $forward = fn(string $line) => $this->stdout("    $line\n");
        $r = array_merge($f->refreshPrices([], $forward), $f->refreshDerivatives([], $forward));
        $f->recordCheckpoint($r);
        foreach ($r as $res) {
            $tag = $res->ok ? 'OK ' : 'FAIL';
            $this->stdout(
                sprintf("  %s  %-12s  %.1fs  %s\n", $tag, $res->step, $res->seconds, $res->summary),
                $res->ok ? Console::FG_GREEN : Console::FG_RED,
            );
        }
        return $this->actionScan4h();
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

    public function actionStatus(): int
    {
        $store = new PositionStore();
        $open  = $store->openPositions();
        $sigs  = $store->recentSignals(20);
        $this->stdout("Open positions: " . count($open) . "\n", Console::FG_GREEN);
        foreach ($open as $p) {
            $this->stdout(sprintf("  %s %s %s entry=%s stop=%s opened=%s\n",
                $p['symbol'] ?? '?', $p['setup'] ?? '?',
                strtoupper($p['side'] ?? '?'),
                rtrim(rtrim(sprintf('%.6f', $p['entry_px'] ?? 0), '0'), '.'),
                rtrim(rtrim(sprintf('%.6f', $p['stop_px']  ?? 0), '0'), '.'),
                $p['entry_day'] ?? '?',
            ));
        }
        $this->stdout("\nRecent signals (last " . count($sigs) . "):\n");
        foreach ($sigs as $s) {
            $kind = $s['kind'] ?? '?';
            $what = $s['signal']['symbol'] ?? $s['event']['symbol'] ?? '?';
            $setup = $s['signal']['setup'] ?? $s['event']['setup'] ?? '?';
            $sent = ($s['sent_ok'] ?? false) ? 'sent' : 'unsent';
            $this->stdout(sprintf("  [%s] %s %s %s — %s\n",
                gmdate('Y-m-d H:i', (int)($s['ts'] ?? 0)), $kind, $what, $setup, $sent));
        }
        $this->stdout("\nState files: " . Paths::stateDir() . "\n");
        return ExitCode::OK;
    }

    // ---------- helpers ----------

    /** Sends if not dry-run and Telegram is configured; logs the outcome. */
    private function maybeSend(TelegramNotifier $tg, string $text, string $tag): array
    {
        if ($this->dryRun) {
            $this->stdout("    [dry-run] would send ({$tag}, " . strlen($text) . " chars)\n", Console::FG_YELLOW);
            return ['ok' => false, 'error' => 'dry_run', 'response' => null];
        }
        if (!$tg->isConfigured()) {
            $this->stderr("    telegram not configured — skipping send ({$tag})\n", Console::FG_YELLOW);
            return ['ok' => false, 'error' => 'not_configured', 'response' => null];
        }
        $res = $tg->send($text);
        if (!$res['ok']) {
            $this->stderr("    send FAILED ({$tag}): " . $res['error'] . "\n", Console::FG_RED);
        }
        return $res;
    }

    /**
     * Most-recent fully-closed UTC day by default. With $allowToday=true,
     * defaults to today (for intra-day lifecycle checks).
     */
    private function resolveAsOf(bool $allowToday = false): string
    {
        if ($this->asOf !== '') {
            return $this->asOf;
        }
        return $allowToday
            ? gmdate('Y-m-d')
            : gmdate('Y-m-d', strtotime('-1 day', time()));
    }

    /**
     * Parse --asOf as a UTC datetime into epoch-ms for the 4h scan. Empty =>
     * null (engine uses the latest completed 4h boundary). Accepts a date
     * ('2026-05-07') or a full datetime ('2026-05-07 16:00').
     */
    private function resolveAsOfMs(): ?int
    {
        if ($this->asOf === '') {
            return null;
        }
        $ts = strtotime($this->asOf . ' UTC');
        return $ts === false ? null : $ts * 1000;
    }
}
