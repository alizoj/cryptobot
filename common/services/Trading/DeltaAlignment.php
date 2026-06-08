<?php

declare(strict_types=1);

namespace common\services\Trading;

/**
 * DELTA_ALIGNMENT_SHORT_V1 — multi-timeframe SMA15-vs-SMA50 alignment screener.
 *
 * For each of the 5m / 30m / 1h / 2h timeframes we take the close-price SMA15
 * and SMA50 of the last completed bar and form
 *
 *     delta = (SMA15 − SMA50) / SMA50 · 100   (percent)
 *
 * The signal is "active" when SMA15 sits just below SMA50 — a mild, freshly
 * established bearish alignment — on ALL four timeframes simultaneously:
 *
 *     −2 < delta_5m  < 0
 *     −2 < delta_30m < 0
 *     −2 < delta_1h  < 0
 *     −2 < delta_2h  < 0
 *
 * Historically (n=769) that joint condition resolved SHORT 61.9% / LONG 38.1%,
 * so the active payload carries a SHORT bias with those probabilities. This is
 * a pure indicator: it produces no entry / stop / size (unlike the L1–S6
 * setups in {@see SetupEvaluator}) — SignalEngine surfaces it alongside fires.
 *
 * The bounds are STRICT on both sides: a delta of exactly 0 or −2 is inactive.
 * A timeframe with fewer than 50 completed closes (or missing data entirely)
 * yields a null delta, which makes the whole signal inactive (fail-safe).
 */
final class DeltaAlignment
{
    public const SIGNAL_NAME      = 'DELTA_ALIGNMENT_SHORT_V1';
    public const DIRECTION_BIAS   = 'SHORT';
    public const SHORT_PROBABILITY = 61.9;
    public const LONG_PROBABILITY  = 38.1;
    public const SAMPLE_SIZE       = 769;

    private const SMA_FAST  = 15;
    private const SMA_SLOW  = 50;
    private const DELTA_MIN = -2.0;   // exclusive lower bound
    private const DELTA_MAX = 0.0;    // exclusive upper bound

    /**
     * Tail length read per timeframe: SMA50 plus headroom for one still-forming
     * trailing bar (and a little margin). Keeps the per-symbol read off the full
     * — potentially very long — sub-hourly series.
     */
    private const LOOKBACK = self::SMA_SLOW + 5;

    /** Timeframe label => bar length in ms (for last-completed-bar resolution). */
    private const TIMEFRAMES = [
        '5m'  => 5 * 60_000,
        '30m' => 30 * 60_000,
        '1h'  => 3_600_000,
        '2h'  => 2 * 3_600_000,
    ];

    /**
     * Evaluate the signal for one symbol as of $asOfMs (default = now). Reads
     * only the tail of each 5m/30m/1h/2h series from MarketData (the SMA window
     * at/before $asOfMs) and computes each delta on that timeframe's last bar
     * fully closed at $asOfMs.
     *
     * @param int|null $asOfMs decision timestamp in ms; bars are "completed" when
     *                         their open ts + bar length is <= $asOfMs.
     * @return array{
     *   signal:string, active:bool, deltas:array<string,float|null>,
     *   bias?:string, short_probability?:float, long_probability?:float
     * }
     */
    public static function evaluate(MarketData $md, string $symbol, ?int $asOfMs = null): array
    {
        $asOfMs ??= (int)(microtime(true) * 1000);

        $deltas = [];
        foreach (self::TIMEFRAMES as $tf => $barMs) {
            $rows   = $md->intervalWindowAsOf($symbol, $tf, $asOfMs, self::LOOKBACK);
            $closes = array_column($rows, 'c');
            $end    = self::lastCompletedIdx($rows, $asOfMs, $barMs);
            $deltas[$tf] = $end < 0 ? null : self::delta($closes, $end);
        }

        return self::fromDeltas($deltas);
    }

    /**
     * Pure assembly of the signal payload from already-computed per-timeframe
     * deltas (a null delta means "no reading" and forces the signal inactive).
     *
     * @param array<string,float|null> $deltas keyed by '5m','30m','1h','2h'
     * @return array{
     *   signal:string, active:bool, deltas:array<string,float|null>,
     *   bias?:string, short_probability?:float, long_probability?:float
     * }
     */
    public static function fromDeltas(array $deltas): array
    {
        $active = true;
        foreach (array_keys(self::TIMEFRAMES) as $tf) {
            $d = $deltas[$tf] ?? null;
            if ($d === null || $d <= self::DELTA_MIN || $d >= self::DELTA_MAX) {
                $active = false;
                break;
            }
        }

        $out = [
            'signal' => self::SIGNAL_NAME,
            'active' => $active,
            'deltas' => [
                '5m'  => $deltas['5m']  ?? null,
                '30m' => $deltas['30m'] ?? null,
                '1h'  => $deltas['1h']  ?? null,
                '2h'  => $deltas['2h']  ?? null,
            ],
        ];
        if ($active) {
            $out['bias']              = self::DIRECTION_BIAS;
            $out['short_probability'] = self::SHORT_PROBABILITY;
            $out['long_probability']  = self::LONG_PROBABILITY;
        }
        return $out;
    }

    /**
     * delta = (SMA15 − SMA50) / SMA50 · 100 over $closes ending at index $end
     * (inclusive). Null if fewer than 50 closes are available up to $end, or
     * SMA50 is zero.
     *
     * @param list<float> $closes
     */
    public static function delta(array $closes, int $end): ?float
    {
        $fast = self::sma($closes, $end, self::SMA_FAST);
        $slow = self::sma($closes, $end, self::SMA_SLOW);
        if ($fast === null || $slow === null || $slow == 0.0) {
            return null;
        }
        return ($fast - $slow) / $slow * 100.0;
    }

    /** Signal metadata block (matches the spec's "Метаданные сигнала"). */
    public static function metadata(): array
    {
        return [
            'signal_name'      => self::SIGNAL_NAME,
            'direction_bias'   => self::DIRECTION_BIAS,
            'long_probability' => self::LONG_PROBABILITY,
            'short_probability' => self::SHORT_PROBABILITY,
            'sample_size'      => self::SAMPLE_SIZE,
        ];
    }

    /** Simple moving average of $period closes ending at $end, or null. */
    private static function sma(array $closes, int $end, int $period): ?float
    {
        if ($end < $period - 1 || $end >= count($closes)) {
            return null;
        }
        $s = 0.0;
        for ($k = $end - $period + 1; $k <= $end; $k++) {
            $s += $closes[$k];
        }
        return $s / $period;
    }

    /** Index of the last bar whose close (ts + barMs) is <= $asOfMs, or -1. */
    private static function lastCompletedIdx(array $rows, int $asOfMs, int $barMs): int
    {
        for ($k = count($rows) - 1; $k >= 0; $k--) {
            if ((int)$rows[$k]['ts'] + $barMs <= $asOfMs) {
                return $k;
            }
        }
        return -1;
    }
}
