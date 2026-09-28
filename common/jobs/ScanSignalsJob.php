<?php

declare(strict_types=1);

namespace common\jobs;

use common\services\Trading\DataFetcher;
use common\services\Trading\Paths;
use common\services\Trading\RsiBoard;
use Yii;
use yii\base\BaseObject;
use yii\queue\JobInterface;
use yii\queue\Queue;
use yii\queue\RetryableJobInterface;

/**
 * Hourly data-refresh job: bring prices/derivatives/macro current so the coin
 * charts and the RSI heatmap stay fresh, then rebuild the precomputed RSI board
 * ({@see RsiBoard}) that /trading/rsi reads.
 *
 * The signal engine has been removed (a new strategy is being built), so this
 * job no longer manages positions or scans for setups — it only refreshes the
 * market data and idles. The class name and the recurring machinery are kept
 * so the existing queue chain and the dashboard/cron entry points keep working
 * unchanged.
 *
 * Hourly cadence without cron: when {@see $recurring} is true the job
 * re-enqueues itself with a 1-hour delay in a `finally` block, so the chain
 * survives even a failed fetch. Start the chain once with
 * `./yii trading/schedule-hourly`; the Redis driver persists the delayed job,
 * so it also survives a worker restart.
 */
final class ScanSignalsJob extends BaseObject implements JobInterface, RetryableJobInterface
{
    /**
     * Redis key holding the "current" recurring-chain generation. Each
     * `trading/schedule-hourly` bumps it; a recurring job only reschedules
     * itself while its own {@see $scheduleGen} still matches. This makes
     * re-running schedule-hourly idempotent — old chains stop instead of
     * piling up parallel hourly loops.
     */
    public const SCHEDULE_GEN_KEY = 'crypto:scan_schedule_gen';

    /**
     * Cache key set while a one-off refresh is queued ({@see queueOneOff}).
     * Cleared when that job finishes; the TTL covers a worker that never picks
     * it up.
     */
    private const ONE_OFF_PENDING_KEY = 'trading.one_off_refresh_pending';
    private const ONE_OFF_PENDING_TTL = 900;

    /** Re-enqueue another refresh {@see $intervalSeconds} later (hourly loop). */
    public bool $recurring = false;

    /** Delay before the next recurring run. 3600 = hourly. */
    public int $intervalSeconds = 3600;

    /** Generation token of the chain this job belongs to (null = untracked). */
    public ?int $scheduleGen = null;

    /** Where this run came from — for the log only ('dashboard', 'schedule', 'cli'). */
    public string $source = 'cli';

    /**
     * Skip the macro (dominance + FNG) HTTP pull unless the stored copy is older
     * than this. Those series update once a day, so 12h keeps them effectively
     * current while sparing the unofficial endpoints on a sub-hourly loop.
     */
    private const MACRO_MAX_AGE_SEC = 12 * 3600;

    /**
     * Symbols newly added to hashhedge_symbols.json get their lifetime history
     * backfilled at most this many per run (~30 s each); the rest follow on
     * the next runs. Keeps a big list change well inside {@see getTtr} — past
     * it the worker kills the job before the `finally` reschedules the loop.
     */
    private const MAX_NEW_SYMBOLS_PER_RUN = 8;

    public function execute($queue): void
    {
        $tag = 'trading.scan';
        Yii::info("ScanSignalsJob start (source={$this->source}, recurring=" . ($this->recurring ? '1' : '0') . ')', $tag);

        try {
            $fetcher = new DataFetcher();
            $log = static fn(string $line) => Yii::info($line, $tag);
            // Prices + derivatives are incremental and skip cheaply when no bar
            // has closed (a few hundred ms of disk reads, no HTTP), so they are
            // safe to run on a sub-hourly loop. Macro (BTC dominance + Fear &
            // Greed) is a once-a-day series whose fetchers make a live HTTP call
            // on *every* run (unofficial CMC endpoint + alternative.me), so we
            // only pull it when the stored copy is stale — otherwise a 60s loop
            // would hammer those free endpoints ~1,440×/day. The RSI heatmap
            // uses none of the macro data; this keeps the minute loop cheap.
            $results = array_merge(
                $fetcher->refreshPrices(['maxNewSymbols' => self::MAX_NEW_SYMBOLS_PER_RUN], $log),
                $fetcher->refreshDerivatives([], $log),
            );
            if (self::macroStale(self::MACRO_MAX_AGE_SEC)) {
                $results = array_merge($results, $fetcher->refreshMacro([], $log));
            }
            $fetcher->recordCheckpoint($results);
            $ok = 0;
            foreach ($results as $r) {
                Yii::info(sprintf('%s %-12s %.1fs %s', $r->ok ? 'OK ' : 'FAIL', $r->step, $r->seconds, $r->summary), $tag);
                $ok += $r->ok ? 1 : 0;
            }
            Yii::info(sprintf('ScanSignalsJob done — data refresh %d/%d ok (signal engine removed, no scan).', $ok, count($results)), $tag);
        } catch (\Throwable $e) {
            // Swallow so the recurring chain is never broken by a transient
            // fetch/network error; the next hourly run retries from scratch.
            Yii::error('ScanSignalsJob failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), $tag);
        } finally {
            self::rebuildRsiBoard($tag);
            if (!$this->recurring) {
                self::clearOneOffPending();
            }
            if ($this->recurring && $queue instanceof Queue) {
                $myGen  = $this->scheduleGen ?? null;
                $curGen = self::currentScheduleGen();
                // Reschedule only if this job still belongs to the current
                // chain. A newer schedule-hourly bumps the generation, so any
                // older chain fires at most once more and then stops here —
                // no duplicate hourly loops. If the generation can't be read
                // (null), keep the chain alive so a transient blip never kills
                // scheduling.
                $isCurrentChain = $curGen === null || ($myGen !== null && $myGen === $curGen);
                if ($isCurrentChain) {
                    $queue->delay($this->intervalSeconds)->push(new self([
                        'recurring'       => true,
                        'intervalSeconds' => $this->intervalSeconds,
                        'source'          => 'schedule',
                        'scheduleGen'     => $myGen ?? $curGen,
                    ]));
                } else {
                    Yii::info("ScanSignalsJob: stale chain (gen={$myGen}, current={$curGen}) — stopping, not rescheduling.", $tag);
                }
            }
        }
    }

    /**
     * Rebuild the precomputed RSI heatmap the page reads — also after a partial
     * fetch, since only symbols whose history file changed are recomputed.
     */
    private static function rebuildRsiBoard(string $tag): void
    {
        try {
            (new RsiBoard())->build(static fn(string $line) => Yii::info($line, $tag));
        } catch (\Throwable $e) {
            Yii::error('RSI board rebuild failed: ' . $e->getMessage(), $tag);
        }
    }

    /**
     * Push a one-off refresh unless one is already waiting (the RSI page's
     * Refresh button). Returns the job id, or null when one is already pending
     * so repeated clicks don't stack up jobs.
     */
    public static function queueOneOff(string $source): int|string|null
    {
        if (!Yii::$app->cache->add(self::ONE_OFF_PENDING_KEY, time(), self::ONE_OFF_PENDING_TTL)) {
            return null;
        }
        try {
            return Yii::$app->queue->push(new self(['source' => $source]));
        } catch (\Throwable $e) {
            self::clearOneOffPending();
            throw $e;
        }
    }

    /** Unix time a one-off refresh was queued, or null if none is waiting. */
    public static function oneOffPending(): ?int
    {
        try {
            $v = Yii::$app->cache->get(self::ONE_OFF_PENDING_KEY);
            return $v === false ? null : (int)$v;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function clearOneOffPending(): void
    {
        try {
            Yii::$app->cache->delete(self::ONE_OFF_PENDING_KEY);
        } catch (\Throwable $e) {
            // cache down — the key expires on its own
        }
    }

    /** True if the macro series is missing or older than $maxAgeSec (uses the dominance file's mtime as the proxy). */
    private static function macroStale(int $maxAgeSec): bool
    {
        $file = Paths::dominanceFile();
        if (!is_file($file)) {
            return true;
        }
        return (time() - (int)filemtime($file)) > $maxAgeSec;
    }

    /** Current recurring-chain generation from Redis, or null if untracked/unreadable. */
    public static function currentScheduleGen(): ?int
    {
        try {
            $v = Yii::$app->redis->get(self::SCHEDULE_GEN_KEY);
            return ($v === null || $v === false) ? null : (int)$v;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** A full refresh is normally under a minute; the first-ever pull can take several. */
    public function getTtr(): int
    {
        return 1800;
    }

    /**
     * No automatic retry: the recurring job already reschedules itself every
     * hour, and a one-off run should not silently fire twice.
     */
    public function canRetry($attempt, $error): bool
    {
        return false;
    }
}
