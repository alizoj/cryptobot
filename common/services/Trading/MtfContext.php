<?php

declare(strict_types=1);

namespace common\services\Trading;

/**
 * Multi-timeframe context for one symbol at one daily close.
 *
 * Computes the 1w / 1d / 4h / 1h signals that the L5 (pullback dip-buy) and
 * S6 (4h blow-off rejection) setups need — derived from the entry-pattern
 * study in docs/results/MTF_ENTRY_ANALYSIS*.md. Kept separate from
 * SetupEvaluator so the trigger logic there stays a pure function of context.
 *
 * All candle accessors hit MarketData's single-symbol cache, so calling this
 * inside the per-symbol scan loop costs no extra file decode.
 *
 * Returned block (merged into SignalEngine's $ctx['mtf']):
 *   w_up / w_down : weekly close vs 10-week SMA and 4 weeks ago
 *   d_up / d_down : daily close vs 20-day SMA and 5 days ago (trend / pullback)
 *   d_low3        : lowest daily low over the last 3 candles (stop reference)
 *   h4_rsi        : RSI(14) of the last completed 4h bar at the daily close
 *   h1_rsi        : RSI(14) of the last completed 1h bar at the daily close
 *   h4_last       : last completed 4h bar -> {ret, upper_wick, high} (or null)
 */
final class MtfContext
{
    private const H4_MS = 4 * 3600 * 1000;
    private const H1_MS = 3600 * 1000;

    /**
     * @param array<int, array<string,mixed>> $features daily feature rows (Features::computeDaily)
     * @param int $i index of the as-of (just-closed) daily row in $features
     * @return array<string, mixed>
     */
    public static function compute(MarketData $md, string $symbol, array $features, int $i): array
    {
        $today      = $features[$i];
        $c          = (float)$today['c'];
        $dayCloseTs = (int)$today['ts'] + 86400000; // daily close = next 00:00 UTC

        // ---- daily trend: SMA20 + 5-day momentum ----
        $sma20 = null;
        if ($i >= 19) {
            $s = 0.0;
            for ($j = $i - 19; $j <= $i; $j++) {
                $s += (float)$features[$j]['c'];
            }
            $sma20 = $s / 20;
        }
        $c5     = $i >= 5 ? (float)$features[$i - 5]['c'] : null;
        $d_up   = $sma20 !== null && $c5 !== null && $c > $sma20 && $c > $c5;
        $d_down = $sma20 !== null && $c5 !== null && $c < $sma20 && $c < $c5;

        $d_low3 = (float)$today['l'];
        for ($j = max(0, $i - 2); $j <= $i; $j++) {
            $d_low3 = min($d_low3, (float)$features[$j]['l']);
        }

        // ---- weekly trend: 10wk SMA + 4wk slope (last weekly bar at/before today) ----
        $wk = $md->weeklyCandles($symbol);
        $w_up = null;
        $w_down = null;
        $wi = -1;
        for ($w = count($wk) - 1; $w >= 0; $w--) {
            if ($wk[$w]['ts'] <= (int)$today['ts']) {
                $wi = $w;
                break;
            }
        }
        if ($wi >= 10) {
            $wsma = 0.0;
            for ($j = $wi - 9; $j <= $wi; $j++) {
                $wsma += $wk[$j]['c'];
            }
            $wsma /= 10;
            $w4 = $wk[$wi - 4]['c'] ?? null;
            if ($w4 !== null) {
                $w_up   = $wk[$wi]['c'] > $wsma && $wk[$wi]['c'] > $w4;
                $w_down = $wk[$wi]['c'] < $wsma && $wk[$wi]['c'] < $w4;
            }
        }

        // ---- 4h: last completed bar + RSI ----
        $h4   = $md->fourHourCandles($symbol);
        $h4i  = self::lastCompletedIdx($h4, $dayCloseTs, self::H4_MS);
        $h4_rsi  = self::rsiAt(array_column($h4, 'c'), $h4i, 14);
        $h4_last = self::barFeatures($h4, $h4i);

        // ---- 1h: RSI of last completed bar ----
        $h1  = $md->hourlyCandles($symbol);
        $h1i = self::lastCompletedIdx($h1, $dayCloseTs, self::H1_MS);
        $h1_rsi = self::rsiAt(array_column($h1, 'c'), $h1i, 14);

        return [
            'w_up'    => $w_up,
            'w_down'  => $w_down,
            'd_up'    => $d_up,
            'd_down'  => $d_down,
            'd_low3'  => $d_low3,
            'h4_rsi'  => $h4_rsi,
            'h1_rsi'  => $h1_rsi,
            'h4_last' => $h4_last,
        ];
    }

    /**
     * Intraday variant: MTF context as of an arbitrary timestamp (used by the
     * 4h-cadence scan). Resolves the daily trend from the last fully-closed
     * daily candle before $asOfMs, and the 4h/1h state from the last completed
     * bar at $asOfMs. Adds 'ref_close' (the decision 4h-bar close) and 'ref_ts'
     * (that bar's close timestamp, ms). Returns null if data is insufficient.
     *
     * @return array<string, mixed>|null
     */
    public static function computeAt(MarketData $md, string $symbol, int $asOfMs): ?array
    {
        $dl = $md->dailyCandles($symbol);
        if (count($dl) < 20) {
            return null;
        }
        // last fully-closed daily candle before $asOfMs
        $di = -1;
        for ($k = count($dl) - 1; $k >= 0; $k--) {
            if ($dl[$k]['ts'] + 86400000 <= $asOfMs) {
                $di = $k;
                break;
            }
        }
        if ($di < 19) {
            return null;
        }
        $closes = array_column($dl, 'c');
        $sma20 = 0.0;
        for ($j = $di - 19; $j <= $di; $j++) {
            $sma20 += $closes[$j];
        }
        $sma20 /= 20;
        $c      = (float)$closes[$di];
        $c5     = $di >= 5 ? (float)$closes[$di - 5] : null;
        $d_up   = $c5 !== null && $c > $sma20 && $c > $c5;
        $d_down = $c5 !== null && $c < $sma20 && $c < $c5;
        $d_low3 = (float)$dl[$di]['l'];
        for ($j = max(0, $di - 2); $j <= $di; $j++) {
            $d_low3 = min($d_low3, (float)$dl[$j]['l']);
        }

        // weekly trend (last weekly bar at/before $asOfMs)
        $wk = $md->weeklyCandles($symbol);
        $w_up = null;
        $w_down = null;
        $wi = -1;
        for ($w = count($wk) - 1; $w >= 0; $w--) {
            if ($wk[$w]['ts'] <= $asOfMs) {
                $wi = $w;
                break;
            }
        }
        if ($wi >= 10) {
            $wsma = 0.0;
            for ($j = $wi - 9; $j <= $wi; $j++) {
                $wsma += $wk[$j]['c'];
            }
            $wsma /= 10;
            $w4 = $wk[$wi - 4]['c'] ?? null;
            if ($w4 !== null) {
                $w_up   = $wk[$wi]['c'] > $wsma && $wk[$wi]['c'] > $w4;
                $w_down = $wk[$wi]['c'] < $wsma && $wk[$wi]['c'] < $w4;
            }
        }

        // 4h: last completed bar at $asOfMs
        $h4  = $md->fourHourCandles($symbol);
        $h4i = self::lastCompletedIdx($h4, $asOfMs, self::H4_MS);
        if ($h4i < 1) {
            return null;
        }
        $h4_rsi  = self::rsiAt(array_column($h4, 'c'), $h4i, 14);
        $h4_last = self::barFeatures($h4, $h4i);

        // 1h: last completed bar at $asOfMs
        $h1  = $md->hourlyCandles($symbol);
        $h1i = self::lastCompletedIdx($h1, $asOfMs, self::H1_MS);
        $h1_rsi = self::rsiAt(array_column($h1, 'c'), $h1i, 14);

        return [
            'w_up'      => $w_up,
            'w_down'    => $w_down,
            'd_up'      => $d_up,
            'd_down'    => $d_down,
            'd_low3'    => $d_low3,
            'h4_rsi'    => $h4_rsi,
            'h1_rsi'    => $h1_rsi,
            'h4_last'   => $h4_last,
            'ref_close' => (float)$h4[$h4i]['c'],
            'ref_ts'    => (int)$h4[$h4i]['ts'] + self::H4_MS,
        ];
    }

    /** Index of the last bar whose close (ts + barMs) is <= $ts, or -1. */
    private static function lastCompletedIdx(array $bars, int $ts, int $barMs): int
    {
        for ($k = count($bars) - 1; $k >= 0; $k--) {
            if ($bars[$k]['ts'] + $barMs <= $ts) {
                return $k;
            }
        }
        return -1;
    }

    /** ret / upper_wick / high of bar $idx vs the prior bar's close, or null. */
    private static function barFeatures(array $bars, int $idx): ?array
    {
        if ($idx < 1) {
            return null;
        }
        $b = $bars[$idx];
        $p = $bars[$idx - 1];
        $ret = $p['c'] > 0 ? $b['c'] / $p['c'] - 1 : 0.0;
        $uw  = $b['c'] > 0 ? ($b['h'] - max($b['o'], $b['c'])) / $b['c'] : 0.0;
        return ['ret' => $ret, 'upper_wick' => $uw, 'high' => (float)$b['h']];
    }

    /** Simple-average RSI(14) at index $end over $closes (matches the analysis script). */
    private static function rsiAt(array $closes, int $end, int $p = 14): ?float
    {
        if ($end < $p) {
            return null;
        }
        $gain = 0.0;
        $loss = 0.0;
        for ($k = $end - $p + 1; $k <= $end; $k++) {
            $d = $closes[$k] - $closes[$k - 1];
            if ($d >= 0) {
                $gain += $d;
            } else {
                $loss -= $d;
            }
        }
        $ag = $gain / $p;
        $al = $loss / $p;
        if ($al == 0.0) {
            return 100.0;
        }
        $rs = $ag / $al;
        return 100 - 100 / (1 + $rs);
    }
}
