<?php

declare(strict_types=1);

namespace common\jobs;

use common\services\Trading\ScanRunner;
use Yii;
use yii\base\BaseObject;
use yii\queue\JobInterface;
use yii\queue\Queue;
use yii\queue\RetryableJobInterface;

/**
 * Signal scan as a queue job: refresh prices/derivatives/macro to "now",
 * manage open positions, then scan the daily + 4h setups and broadcast/open
 * fresh signals. This is the queue-native replacement for the old cron jobs.
 *
 * Hourly cadence without cron: when {@see $recurring} is true the job
 * re-enqueues itself with a 1-hour delay in a `finally` block, so the chain
 * survives even a failed scan. Start the chain once with
 * `./yii trading/schedule-hourly`; the Redis driver persists the delayed job,
 * so it also survives a worker restart.
 *
 * The dashboard "Scan now" button and `./yii trading/queue-scan` push this with
 * recurring = false — a one-off run that does not touch the hourly schedule.
 */
final class ScanSignalsJob extends BaseObject implements JobInterface, RetryableJobInterface
{
    /** Re-enqueue another scan {@see $intervalSeconds} later (hourly loop). */
    public bool $recurring = false;

    /** Delay before the next recurring run. 3600 = hourly. */
    public int $intervalSeconds = 3600;

    /** Where this run came from — for the log only ('dashboard', 'schedule', 'cli'). */
    public string $source = 'cli';

    public function execute($queue): void
    {
        $tag = 'trading.scan';
        Yii::info("ScanSignalsJob start (source={$this->source}, recurring=" . ($this->recurring ? '1' : '0') . ')', $tag);

        try {
            $summary = (new ScanRunner())->runHourly(
                static fn(string $line) => Yii::info($line, $tag),
            );
            Yii::info(sprintf(
                'ScanSignalsJob done — events=%d opened=%d sent=%d',
                $summary['events'], $summary['opened'], $summary['sent'],
            ), $tag);
        } catch (\Throwable $e) {
            // Swallow so the recurring chain is never broken by a transient
            // fetch/network error; the next hourly run retries from scratch.
            Yii::error('ScanSignalsJob failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), $tag);
        } finally {
            if ($this->recurring && $queue instanceof Queue) {
                $queue->delay($this->intervalSeconds)->push(new self([
                    'recurring'       => true,
                    'intervalSeconds' => $this->intervalSeconds,
                    'source'          => 'schedule',
                ]));
            }
        }
    }

    /** A full fetch + scan is normally under a minute; the first-ever pull can take several. */
    public function getTtr(): int
    {
        return 1800;
    }

    /**
     * No automatic retry: the recurring job already reschedules itself every
     * hour, and a one-off button run should not silently fire twice.
     */
    public function canRetry($attempt, $error): bool
    {
        return false;
    }
}
