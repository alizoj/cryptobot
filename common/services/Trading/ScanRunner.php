<?php

declare(strict_types=1);

namespace common\services\Trading;

use Yii;

/**
 * Single source of truth for the signal-scan pipeline.
 *
 * Wraps the same fetch → manage → scan → send/open steps the console cron
 * commands used to run inline, but with a log-callback instead of stdout so it
 * can be driven from three places without duplicating the send/open loop:
 *
 *   - the hourly queue job ({@see \common\jobs\ScanSignalsJob}) — runHourly()
 *   - the dashboard "Scan now" button (same runHourly())
 *   - the console commands (trading/scan, trading/scan-4h, trading/manage-positions)
 *     which delegate to emitSignals()/emitEvents() for the broadcast loop
 *
 * Why hourly scanning is safe: SignalEngine evaluates only *closed* daily and
 * 4h candles, and PositionStore::openFromSignal() dedups by (as_of, symbol,
 * setup). Re-running every hour therefore re-prices the latest closed bars and
 * fills freed slots without ever opening a duplicate or acting on a still-open
 * candle. With $onlyNotifyOpened = true a Telegram entry alert is sent exactly
 * once — when the position actually opens — so the hourly cadence never spams.
 */
final class ScanRunner
{
    private PositionStore $store;
    private TelegramNotifier $notifier;
    private ChartRenderer $chart;
    private MarketData $md;

    public function __construct(
        ?PositionStore $store = null,
        ?TelegramNotifier $notifier = null,
        ?ChartRenderer $chart = null,
        ?MarketData $md = null,
    ) {
        $this->store    = $store    ?? new PositionStore();
        $this->notifier = $notifier ?? new TelegramNotifier();
        $this->chart    = $chart    ?? new ChartRenderer();
        $this->md       = $md       ?? new MarketData();
    }

    /**
     * Full hourly pipeline used by the queue job and the dashboard button:
     * refresh every data source to "now" (incremental, cheap when nothing
     * ticked), manage open positions, then scan the daily and 4h setups and
     * broadcast/open any fresh signals.
     *
     * @param callable(string):void|null $log
     * @return array<string,int> {fetch_ok, events, daily_opened, intraday_opened, opened, sent}
     */
    public function runHourly(?callable $log = null): array
    {
        $log = $log ?? static fn(string $l) => null;

        // 1) Bring every source current. Fetches are incremental: a run when
        //    nothing has ticked over is a few hundred ms of disk reads.
        $fetcher = new DataFetcher();
        $results = $fetcher->refreshAll([], $log);
        $fetcher->recordCheckpoint($results);
        $fetchOk = 1;
        foreach ($results as $r) {
            $log(sprintf('%s %-12s %.1fs %s', $r->ok ? 'OK ' : 'FAIL', $r->step, $r->seconds, $r->summary));
            $fetchOk = $fetchOk && $r->ok ? 1 : 0;
        }

        // 2) Manage open positions (stop hits, funding flips, T+N, trailing).
        $asOf   = gmdate('Y-m-d');
        $events = (new PositionManager())->manage($asOf);
        $this->emitEvents($events, $asOf, false, $log);

        // 3) Scan the latest closed daily + 4h bars and open within the cap.
        $engine = new SignalEngine();
        $daily  = $engine->scan(null);
        $log(sprintf('daily scan: %d signal(s)', count($daily['signals'])));
        $d = $this->emitSignals($daily['signals'], false, $log, onlyNotifyOpened: true);

        $intra = $engine->scanIntraday(null);
        $log(sprintf('4h scan: %d signal(s)', count($intra['signals'])));
        $i = $this->emitSignals($intra['signals'], false, $log, onlyNotifyOpened: true);

        return [
            'fetch_ok'        => $fetchOk,
            'events'          => count($events),
            'daily_opened'    => $d['opened'],
            'intraday_opened' => $i['opened'],
            'opened'          => $d['opened'] + $i['opened'],
            'sent'            => $d['sent'] + $i['sent'],
        ];
    }

    /**
     * Broadcast + open a batch of entry signals. Single implementation shared
     * by the console scan commands and the hourly pipeline.
     *
     * @param list<array<string,mixed>> $signals
     * @param callable(string):void|null $log
     * @return array<string,int> {opened, dedup, capped, sent, cap}
     */
    public function emitSignals(
        array $signals,
        bool $dryRun,
        ?callable $log = null,
        bool $onlyNotifyOpened = false,
    ): array {
        $log = $log ?? static fn(string $l) => null;
        $cap = (int)(Yii::$app->params['trading']['maxConcurrentSingles'] ?? 5);

        $opened = $dedup = $capped = $sent = 0;
        foreach ($signals as $sig) {
            $id = PositionStore::signalId($sig['as_of'], $sig['symbol'], $sig['setup']);

            // Open first so we can decide whether this is a *new* position worth
            // alerting on (avoids re-sending an already-open signal every hour).
            $outcome = $dryRun ? 'dry_run' : $this->store->openFromSignal($sig, $cap);
            if ($outcome === 'opened')      $opened++;
            if ($outcome === 'dedup')       $dedup++;
            if ($outcome === 'cap_reached') $capped++;

            $shouldSend = !$dryRun
                && $this->notifier->isConfigured()
                && (!$onlyNotifyOpened || $outcome === 'opened');

            $sendOk = false;
            if ($shouldSend) {
                $res = $this->notifier->send(TelegramNotifier::formatEntrySignal($sig));
                $sendOk = (bool)($res['ok'] ?? false);
                if ($sendOk && $this->chart->isEnabled()) {
                    $url = $this->chart->urlForSignal($sig, $this->md->dailyCandles($sig['symbol']));
                    if ($url !== null) {
                        $this->notifier->sendPhoto($url, sprintf(
                            '%s %s %s', $sig['setup'], $sig['symbol'], strtoupper((string)$sig['side']),
                        ));
                    }
                }
                if ($sendOk) $sent++;
            } elseif ($dryRun) {
                $log("[dry-run] would send {$id}");
            }

            $this->store->logSignal([
                'id'      => $id,
                'kind'    => 'entry',
                'ts'      => time(),
                'sent_ok' => $sendOk,
                'signal'  => $sig,
            ]);

            $log(sprintf('%s %-8s %-5s entry=%s stop=%s sent=%s open=%s',
                $sig['setup'], $sig['symbol'], strtoupper((string)$sig['side']),
                $sig['entry_px'] !== null ? self::px((float)$sig['entry_px']) : 'mkt',
                self::px((float)$sig['stop_px']),
                $sendOk ? 'yes' : 'no',
                $outcome,
            ));
        }

        return ['opened' => $opened, 'dedup' => $dedup, 'capped' => $capped, 'sent' => $sent, 'cap' => $cap];
    }

    /**
     * Broadcast a batch of position-lifecycle events (stop hit, funding flip,
     * T+N, trailing-stop update). Exits remove the position so they fire once;
     * trail updates only re-fire when the stop actually ratchets — safe hourly.
     *
     * @param list<array<string,mixed>> $events
     * @param callable(string):void|null $log
     * @return int number of events sent to Telegram
     */
    public function emitEvents(array $events, string $asOf, bool $dryRun, ?callable $log = null): int
    {
        $log = $log ?? static fn(string $l) => null;
        $log(sprintf('manage: %d event(s)', count($events)));

        $sent = 0;
        foreach ($events as $ev) {
            $id = "manage-{$asOf}-{$ev['symbol']}-{$ev['setup']}-{$ev['kind']}";

            $sendOk = false;
            if (!$dryRun && $this->notifier->isConfigured()) {
                $res = $this->notifier->send(TelegramNotifier::formatExitSignal($ev));
                $sendOk = (bool)($res['ok'] ?? false);
                if ($sendOk) $sent++;
            }

            $this->store->logSignal([
                'id'      => $id,
                'kind'    => 'manage',
                'ts'      => time(),
                'sent_ok' => $sendOk,
                'event'   => $ev,
            ]);

            $log(sprintf('%-16s %-8s %-3s sent=%s', $ev['kind'], $ev['symbol'], $ev['setup'], $sendOk ? 'yes' : 'no'));
        }

        return $sent;
    }

    private static function px(float $v): string
    {
        return rtrim(rtrim(sprintf('%.6f', $v), '0'), '.');
    }
}
