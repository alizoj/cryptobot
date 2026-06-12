<?php

declare(strict_types=1);

namespace common\jobs;

use common\services\Trading\DeltaAlignment;
use Yii;
use yii\base\BaseObject;
use yii\queue\JobInterface;
use yii\queue\Queue;
use yii\queue\RetryableJobInterface;

/**
 * 5-minute refresh of the DELTA_ALIGNMENT board (closed-candle SMA sums per
 * symbol and timeframe — see {@see DeltaAlignment}).
 *
 * Same queue-native recurring machinery as {@see ScanSignalsJob}, with one
 * twist: the next run is delayed to land shortly AFTER the next 5m candle
 * boundary (UTC), not a flat interval later. The board only changes when a
 * candle closes, so aligning runs to candle closes keeps the stored sums in
 * lockstep with the exchange — the live deltas then tick on price alone and
 * a fresh candle is never missed by a few seconds of drift.
 *
 * Start the chain with `./yii trading/schedule-delta`; a worker
 * (`./yii queue/listen`) must be running.
 */
final class DeltaBoardJob extends BaseObject implements JobInterface, RetryableJobInterface
{
    /** Generation token key — bumping it (schedule-delta) retires older chains. */
    public const SCHEDULE_GEN_KEY = 'crypto:delta_schedule_gen';

    /** Re-enqueue another refresh at the next boundary (5-min loop). */
    public bool $recurring = false;

    /** Candle boundary the runs align to. 300 = the 5m candles. */
    public int $intervalSeconds = 300;

    /** Generation token of the chain this job belongs to (null = untracked). */
    public ?int $scheduleGen = null;

    /** Where this run came from — for the log only ('schedule', 'cli'). */
    public string $source = 'cli';

    public function execute($queue): void
    {
        $tag = 'trading.delta';
        Yii::info("DeltaBoardJob start (source={$this->source}, recurring=" . ($this->recurring ? '1' : '0') . ')', $tag);

        try {
            $sum = (new DeltaAlignment())->rebuildBoard(static fn(string $line) => Yii::info($line, $tag));
            Yii::info(sprintf(
                'DeltaBoardJob done — %d symbols, %d kline calls (%d reused), %d failed, %.1fs',
                $sum['symbols'],
                $sum['calls'],
                $sum['reused'],
                $sum['failed'],
                $sum['seconds'],
            ), $tag);
        } catch (\Throwable $e) {
            // Swallow so the recurring chain survives a transient network blip;
            // the next 5-minute run rebuilds from scratch.
            Yii::error('DeltaBoardJob failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), $tag);
        } finally {
            if ($this->recurring && $queue instanceof Queue) {
                $myGen  = $this->scheduleGen;
                $curGen = self::currentScheduleGen();
                $isCurrentChain = $curGen === null || ($myGen !== null && $myGen === $curGen);
                if ($isCurrentChain) {
                    $queue->delay(self::delayToNextBoundary($this->intervalSeconds))->push(new self([
                        'recurring'       => true,
                        'intervalSeconds' => $this->intervalSeconds,
                        'source'          => 'schedule',
                        'scheduleGen'     => $myGen ?? $curGen,
                    ]));
                } else {
                    Yii::info("DeltaBoardJob: stale chain (gen={$myGen}, current={$curGen}) — stopping, not rescheduling.", $tag);
                }
            }
        }
    }

    /**
     * Seconds until shortly after the next interval boundary (UTC). The small
     * offset gives the exchange time to print the just-closed candle before
     * we ask for it.
     */
    public static function delayToNextBoundary(int $intervalSeconds, int $offsetSeconds = 10): int
    {
        return $intervalSeconds - (time() % $intervalSeconds) + $offsetSeconds;
    }

    /** Current recurring-chain generation from Redis, or null if untracked/unreadable. */
    public static function currentScheduleGen(): ?int
    {
        try {
            $v = Yii::$app->redis->get(self::SCHEDULE_GEN_KEY);
            return ($v === null || $v === false) ? null : (int)$v;
        } catch (\Throwable) {
            return null;
        }
    }

    /** A steady-state rebuild is well under a minute; a cold one does ~450 throttled calls. */
    public function getTtr(): int
    {
        return 900;
    }

    /** The recurring chain already retries every 5 minutes; no extra attempts. */
    public function canRetry($attempt, $error): bool
    {
        return false;
    }
}
