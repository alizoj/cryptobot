<?php

declare(strict_types=1);

namespace common\services\Trading;

/**
 * Per-coin technical-metrics pipeline — a faithful port of the pure-OHLCV
 * helpers in rsi-module's RsiService::computeCoinMetrics(). It produces the
 * "coin row" the ConfluenceGrader consumes (rsi, rsiSlope, divergence, pinbar,
 * wave, levels, volStd, volRatio, patterns, trendline, technical).
 *
 * Differences vs the rsi-module original (deliberate, see the merge scope):
 *   - Timeframes are 1h/4h/1d only. The live engine never fetched 15m/30m, so
 *     those TFs are simply absent; the grader skips missing TFs gracefully and
 *     its weights re-normalise over what's present.
 *   - Wilder RSI(14) is used here (the grader was tuned on Wilder). This is
 *     SEPARATE from MtfContext's simple-average RSI, which stays untouched so
 *     the L5/S6 setup backtest is unaffected.
 *   - build() is point-in-time: every TF series is sliced to bars that had
 *     closed by $closeTs, so the backtester gets no look-ahead. Single-bar
 *     pattern reads (pinbar) use the last *completed* bar (index -2) exactly as
 *     the live snapshot does; in a historical replay that means the bar before
 *     the decision bar — conservative (past-only), never future.
 *
 * Everything here is a pure function of candle data; there is no HTTP or state.
 */
final class CoinMetrics
{
    private const RSI_PERIOD = 14;

    /** Timeframes we carry. 1h/4h/1d — the set the live engine actually has. */
    public const TIMEFRAMES = ['1h', '4h', '1d'];

    /** Divergence + single-bar patterns are too noisy below 1h. */
    private const DIVERGENCE_TFS = ['1h' => 1, '4h' => 1, '1d' => 1];
    private const PINBAR_TFS     = ['1h' => 1, '4h' => 1, '1d' => 1];

    /** Map a live MarketData candle (o/h/l/c/v) to the rsi-module row shape. */
    private const KEY_MAP = ['o' => 'open', 'h' => 'high', 'l' => 'low', 'c' => 'close', 'v' => 'volume'];

    /**
     * Build a graded-ready coin row as of $closeTs (ms). Reads cached candle
     * arrays from MarketData, slices each TF to bars closed by $closeTs, and
     * runs the metric pipeline. $price is the decision price (the daily close),
     * $change24h is the last-bar % change, $funding the per-8h rate (or null).
     *
     * @return array<string,mixed> the row computeBias()/grade() consume
     */
    public static function build(
        MarketData $md,
        string $symbol,
        int $closeTs,
        float $price,
        ?float $change24h,
        ?float $funding,
        int $maxBars = 120
    ): array {
        $rowsByTf = [
            '1h' => self::slice($md->hourlyCandles($symbol), $closeTs, $maxBars),
            '4h' => self::slice($md->fourHourCandles($symbol), $closeTs, $maxBars),
            '1d' => self::slice($md->dailyCandles($symbol), $closeTs, $maxBars),
        ];

        $row = self::compute($rowsByTf, $change24h);
        $row['symbol']    = $symbol;
        $row['price']     = $price;
        $row['change24h'] = $change24h;
        $row['funding']   = $funding;
        return $row;
    }

    /**
     * Take the last $maxBars candles with ts <= $closeTs (ascending input) and
     * re-key them to {open,high,low,close,volume}. Uses an upper-bound binary
     * search so per-fire cost stays O(log n + maxBars), not O(n).
     *
     * @param list<array<string,float|int>> $candles ascending by ts
     * @return list<array<string,float>>
     */
    private static function slice(array $candles, int $closeTs, int $maxBars): array
    {
        $n = count($candles);
        if ($n === 0) {
            return [];
        }
        // upper_bound: first index with ts > closeTs.
        $lo = 0;
        $hi = $n;
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ((int)$candles[$mid]['ts'] <= $closeTs) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        $end = $lo;                       // count of bars with ts <= closeTs
        if ($end === 0) {
            return [];
        }
        $start = max(0, $end - $maxBars);
        $out = [];
        for ($i = $start; $i < $end; $i++) {
            $c = $candles[$i];
            $out[] = [
                'open'   => (float)$c['o'],
                'high'   => (float)$c['h'],
                'low'    => (float)$c['l'],
                'close'  => (float)$c['c'],
                'volume' => (float)($c['v'] ?? 0.0),
            ];
        }
        return $out;
    }

    // ===============================================================
    // Orchestration  (port of RsiService::computeCoinMetrics)
    // ===============================================================

    /**
     * @param array<string,list<array<string,float>>> $rowsByTf
     * @return array<string,mixed>
     */
    public static function compute(array $rowsByTf, ?float $change24h = null): array
    {
        $rsi        = array_fill_keys(self::TIMEFRAMES, null);
        $rsiSlope   = array_fill_keys(self::TIMEFRAMES, null);
        $volRatio   = array_fill_keys(self::TIMEFRAMES, null);
        $divergence = array_fill_keys(array_keys(self::DIVERGENCE_TFS), null);
        $pinbar     = array_fill_keys(array_keys(self::PINBAR_TFS), null);

        foreach (self::TIMEFRAMES as $tf) {
            $rows = $rowsByTf[$tf] ?? null;
            if (!$rows) {
                continue;
            }
            $closes = array_column($rows, 'close');
            $rsi[$tf] = self::calcRSI($closes);

            $series = self::calcRSISeries($closes);
            if (is_array($series) && count($series) >= 2) {
                $rsiSlope[$tf] = $series[count($series) - 1] - $series[count($series) - 2];
            }

            $vols = array_column($rows, 'volume');
            $vn = count($vols);
            if ($vn >= 6) {
                $window = min(20, $vn - 2);
                $slice = array_slice($vols, -($window + 2), $window);
                $mean = $slice ? array_sum($slice) / count($slice) : 0.0;
                if ($mean > 0.0) {
                    $volRatio[$tf] = $vols[$vn - 2] / $mean;
                }
            }

            if (isset(self::DIVERGENCE_TFS[$tf])) {
                $divergence[$tf] = self::detectDivergence($closes);
            }
            if (isset(self::PINBAR_TFS[$tf])) {
                $pinbar[$tf] = self::detectPinbar($rows);
            }
        }

        $levels = null;
        $volStd = null;
        $rows1h = $rowsByTf['1h'] ?? null;
        if ($rows1h) {
            $closes = array_column($rows1h, 'close');
            $highs  = array_column($rows1h, 'high');
            $lows   = array_column($rows1h, 'low');
            $atr = self::calcATR($highs, $lows, $closes, 14);
            if (count($closes) > 0) {
                $window = min(30, count($closes));
                $slH = array_slice($highs, -$window);
                $slL = array_slice($lows, -$window);
                $levels = [
                    'swingLow'  => $slL ? min($slL) : null,
                    'swingHigh' => $slH ? max($slH) : null,
                    'atr'       => $atr,
                ];
                $vols1h = array_column($rows1h, 'volume');
                [$hvnAbove, $hvnBelow] = self::findHvn($highs, $lows, $closes, $vols1h);
                if ($hvnAbove !== null) {
                    $levels['hvnAbove'] = $hvnAbove;
                }
                if ($hvnBelow !== null) {
                    $levels['hvnBelow'] = $hvnBelow;
                }
            }
            $volStd = self::calcReturnStd($closes, 20);
        }

        $wave = null;
        $waveRows = $rowsByTf['4h'] ?? $rowsByTf['1h'] ?? null;
        if ($waveRows) {
            $wave = self::detectWavePattern($waveRows);
        }

        $patterns  = self::detectMovementPatterns($rowsByTf, $divergence, $change24h);
        $trendline = $rows1h ? self::detectTrendlines($rows1h) : ['up' => null, 'down' => null];
        $technical = $rows1h
            ? self::detectTechnicalLevels($rowsByTf, $levels)
            : ['all' => [], 'confluence' => [], 'ema' => null, 'macd' => null, 'marketStructure' => null];

        return [
            'rsi'        => $rsi,
            'rsiSlope'   => $rsiSlope,
            'volRatio'   => $volRatio,
            'divergence' => $divergence,
            'pinbar'     => $pinbar,
            'wave'       => $wave,
            'levels'     => $levels,
            'volStd'     => $volStd,
            'patterns'   => $patterns,
            'trendline'  => $trendline,
            'technical'  => $technical,
        ];
    }

    // ===============================================================
    // RSI
    // ===============================================================

    public static function calcRSI(array $closes, int $period = self::RSI_PERIOD): ?float
    {
        $n = count($closes);
        if ($n < $period + 1) {
            return null;
        }
        $gainSum = 0.0;
        $lossSum = 0.0;
        for ($i = 1; $i <= $period; $i++) {
            $d = $closes[$i] - $closes[$i - 1];
            if ($d >= 0) {
                $gainSum += $d;
            } else {
                $lossSum += -$d;
            }
        }
        $avgG = $gainSum / $period;
        $avgL = $lossSum / $period;
        for ($i = $period + 1; $i < $n; $i++) {
            $d = $closes[$i] - $closes[$i - 1];
            $g = $d > 0 ? $d : 0.0;
            $l = $d < 0 ? -$d : 0.0;
            $avgG = ($avgG * ($period - 1) + $g) / $period;
            $avgL = ($avgL * ($period - 1) + $l) / $period;
        }
        return self::rsiFromAvgs($avgG, $avgL);
    }

    public static function calcRSISeries(array $closes, int $period = self::RSI_PERIOD): ?array
    {
        $n = count($closes);
        if ($n < $period + 1) {
            return null;
        }
        $gainSum = 0.0;
        $lossSum = 0.0;
        for ($i = 1; $i <= $period; $i++) {
            $d = $closes[$i] - $closes[$i - 1];
            if ($d >= 0) {
                $gainSum += $d;
            } else {
                $lossSum += -$d;
            }
        }
        $avgG = $gainSum / $period;
        $avgL = $lossSum / $period;
        $rsi = [self::rsiFromAvgs($avgG, $avgL)];
        for ($i = $period + 1; $i < $n; $i++) {
            $d = $closes[$i] - $closes[$i - 1];
            $g = $d > 0 ? $d : 0.0;
            $l = $d < 0 ? -$d : 0.0;
            $avgG = ($avgG * ($period - 1) + $g) / $period;
            $avgL = ($avgL * ($period - 1) + $l) / $period;
            $rsi[] = self::rsiFromAvgs($avgG, $avgL);
        }
        return $rsi;
    }

    private static function rsiFromAvgs(float $avgG, float $avgL): float
    {
        if ($avgL == 0.0) {
            return $avgG == 0.0 ? 50.0 : 100.0;
        }
        $rs = $avgG / $avgL;
        return 100.0 - (100.0 / (1.0 + $rs));
    }

    // ===============================================================
    // Divergence / ATR / volume profile
    // ===============================================================

    public static function detectDivergence(array $closes, int $period = self::RSI_PERIOD, int $window = 3, int $lookback = 30): ?string
    {
        $series = self::calcRSISeries($closes, $period);
        if ($series === null) {
            return null;
        }
        $offset = count($closes) - count($series);
        if (count($series) < 6) {
            return null;
        }
        $endIdx = count($closes) - 1 - $window;
        $startIdx = max($offset + $window, count($closes) - $lookback);
        if ($endIdx - $startIdx < 4) {
            return null;
        }
        $lows = [];
        $highs = [];
        for ($i = $startIdx; $i <= $endIdx; $i++) {
            $isLow = true;
            $isHigh = true;
            for ($k = 1; $k <= $window; $k++) {
                if ($closes[$i] >= $closes[$i - $k] || $closes[$i] >= $closes[$i + $k]) {
                    $isLow = false;
                }
                if ($closes[$i] <= $closes[$i - $k] || $closes[$i] <= $closes[$i + $k]) {
                    $isHigh = false;
                }
                if (!$isLow && !$isHigh) {
                    break;
                }
            }
            if ($isLow) {
                $lows[] = ['p' => $closes[$i], 'r' => $series[$i - $offset]];
            }
            if ($isHigh) {
                $highs[] = ['p' => $closes[$i], 'r' => $series[$i - $offset]];
            }
        }
        if (count($lows) >= 2) {
            $a = $lows[count($lows) - 2];
            $b = $lows[count($lows) - 1];
            if ($b['p'] < $a['p'] && $b['r'] > $a['r'] && $b['r'] < 50.0) {
                return 'bullish';
            }
        }
        if (count($highs) >= 2) {
            $a = $highs[count($highs) - 2];
            $b = $highs[count($highs) - 1];
            if ($b['p'] > $a['p'] && $b['r'] < $a['r'] && $b['r'] > 50.0) {
                return 'bearish';
            }
        }
        return null;
    }

    public static function calcATR(array $highs, array $lows, array $closes, int $period = 14): ?float
    {
        $n = min(count($highs), count($lows), count($closes));
        if ($n < $period + 1) {
            return null;
        }
        $trSum = 0.0;
        for ($i = 1; $i <= $period; $i++) {
            $tr = max($highs[$i] - $lows[$i], abs($highs[$i] - $closes[$i - 1]), abs($lows[$i] - $closes[$i - 1]));
            $trSum += $tr;
        }
        $atr = $trSum / $period;
        for ($i = $period + 1; $i < $n; $i++) {
            $tr = max($highs[$i] - $lows[$i], abs($highs[$i] - $closes[$i - 1]), abs($lows[$i] - $closes[$i - 1]));
            $atr = ($atr * ($period - 1) + $tr) / $period;
        }
        return $atr;
    }

    public static function findHvn(array $highs, array $lows, array $closes, array $volumes): array
    {
        $n = min(count($highs), count($lows), count($closes), count($volumes));
        if ($n < 20) {
            return [null, null];
        }
        $current = (float)$closes[$n - 1];
        $min = INF;
        $max = -INF;
        for ($i = 0; $i < $n; $i++) {
            if ($lows[$i] < $min) {
                $min = (float)$lows[$i];
            }
            if ($highs[$i] > $max) {
                $max = (float)$highs[$i];
            }
        }
        if ($max <= $min) {
            return [null, null];
        }
        $bins = 20;
        $width = ($max - $min) / $bins;
        $vp = array_fill(0, $bins, 0.0);
        for ($i = 0; $i < $n; $i++) {
            $v = (float)$volumes[$i];
            if ($v <= 0.0) {
                continue;
            }
            $lo = (float)$lows[$i];
            $hi = (float)$highs[$i];
            $loBin = (int)max(0, min($bins - 1, floor(($lo - $min) / $width)));
            $hiBin = (int)max(0, min($bins - 1, floor(($hi - $min) / $width)));
            $spans = max(1, $hiBin - $loBin + 1);
            $share = $v / $spans;
            for ($b = $loBin; $b <= $hiBin; $b++) {
                $vp[$b] += $share;
            }
        }
        $aboveBest = null;
        $aboveVol = -1.0;
        $belowBest = null;
        $belowVol = -1.0;
        for ($b = 0; $b < $bins; $b++) {
            $center = $min + ($b + 0.5) * $width;
            if ($center > $current) {
                if ($vp[$b] > $aboveVol) {
                    $aboveVol = $vp[$b];
                    $aboveBest = $center;
                }
            } elseif ($center < $current) {
                if ($vp[$b] > $belowVol) {
                    $belowVol = $vp[$b];
                    $belowBest = $center;
                }
            }
        }
        return [$aboveBest, $belowBest];
    }

    // ===============================================================
    // EMA / MACD / market structure / fib / round numbers
    // ===============================================================

    public static function calcEma(array $values, int $period): ?array
    {
        $n = count($values);
        if ($n < $period) {
            return null;
        }
        $k = 2.0 / ($period + 1);
        $seed = array_sum(array_slice($values, 0, $period)) / $period;
        $out = array_fill(0, $period - 1, null);
        $out[] = $seed;
        $cur = $seed;
        for ($i = $period; $i < $n; $i++) {
            $cur = (float)$values[$i] * $k + $cur * (1 - $k);
            $out[] = $cur;
        }
        return $out;
    }

    public static function calcMacd(array $closes): ?array
    {
        $n = count($closes);
        if ($n < 35) {
            return null;
        }
        $ema12 = self::calcEma($closes, 12);
        $ema26 = self::calcEma($closes, 26);
        if (!$ema12 || !$ema26) {
            return null;
        }
        $line = [];
        for ($i = 0; $i < $n; $i++) {
            if ($ema12[$i] !== null && $ema26[$i] !== null) {
                $line[] = $ema12[$i] - $ema26[$i];
            }
        }
        if (count($line) < 10) {
            return null;
        }
        $signal = self::calcEma($line, 9);
        if (!$signal) {
            return null;
        }
        $lastLine = end($line);
        $lastSignal = end($signal);
        $prevLine = $line[count($line) - 2] ?? null;
        $prevSignal = $signal[count($signal) - 2] ?? null;
        $cross = null;
        if ($prevLine !== null && $prevSignal !== null) {
            if ($prevLine < $prevSignal && $lastLine > $lastSignal) {
                $cross = 'bull';
            } elseif ($prevLine > $prevSignal && $lastLine < $lastSignal) {
                $cross = 'bear';
            }
        }
        return [
            'line' => $lastLine,
            'signal' => $lastSignal,
            'histogram' => $lastLine - $lastSignal,
            'direction' => $lastLine > $lastSignal ? 'up' : 'down',
            'cross' => $cross,
        ];
    }

    public static function detectMarketStructure(array $rows): ?array
    {
        $n = count($rows);
        if ($n < 30) {
            return null;
        }
        $highs = array_column($rows, 'high');
        $lows = array_column($rows, 'low');
        $closes = array_column($rows, 'close');
        $pivotHighs = self::findPivotHighs($highs, 3);
        $pivotLows = self::findPivotLows($lows, 3);
        if (count($pivotHighs) < 2 || count($pivotLows) < 2) {
            return null;
        }
        $hh = end($pivotHighs);
        $ph = prev($pivotHighs);
        $ll = end($pivotLows);
        $pl = prev($pivotLows);
        $direction = 'range';
        if ($hh && $ph && $ll && $pl) {
            if ($hh['v'] > $ph['v'] && $ll['v'] > $pl['v']) {
                $direction = 'up';
            } elseif ($hh['v'] < $ph['v'] && $ll['v'] < $pl['v']) {
                $direction = 'down';
            }
        }
        $lastClose = (float)end($closes);
        $bos = null;
        if ($direction === 'up' && $hh && $lastClose > $hh['v']) {
            $bos = 'up';
        } elseif ($direction === 'down' && $ll && $lastClose < $ll['v']) {
            $bos = 'down';
        }
        $choch = null;
        if ($direction === 'down' && $hh && $lastClose > $hh['v']) {
            $choch = 'up';
        } elseif ($direction === 'up' && $ll && $lastClose < $ll['v']) {
            $choch = 'down';
        }
        return [
            'direction' => $direction,
            'bos' => $bos,
            'choch' => $choch,
            'lastSwingHigh' => $hh['v'] ?? null,
            'lastSwingLow' => $ll['v'] ?? null,
        ];
    }

    private static function findFibSwing(array $rows, int $window = 50): ?array
    {
        $n = count($rows);
        if ($n < 20) {
            return null;
        }
        $w = min($window, $n);
        $highs = array_slice(array_column($rows, 'high'), -$w);
        $lows = array_slice(array_column($rows, 'low'), -$w);
        $maxIdx = 0;
        $maxVal = $highs[0];
        $minIdx = 0;
        $minVal = $lows[0];
        for ($i = 1; $i < $w; $i++) {
            if ($highs[$i] > $maxVal) {
                $maxVal = $highs[$i];
                $maxIdx = $i;
            }
            if ($lows[$i] < $minVal) {
                $minVal = $lows[$i];
                $minIdx = $i;
            }
        }
        if ($maxVal <= $minVal) {
            return null;
        }
        return [
            'low' => (float)$minVal,
            'high' => (float)$maxVal,
            'direction' => $maxIdx > $minIdx ? 'up' : 'down',
        ];
    }

    private static function roundNumberLevels(float $price): array
    {
        if ($price <= 0) {
            return [];
        }
        $step = pow(10, (int)floor(log10($price)));
        $base = floor($price / $step) * $step;
        $candidates = [$base, $base + $step, $base - $step, $base + 2 * $step, $base - 2 * $step];
        $out = [];
        foreach ($candidates as $c) {
            if ($c > 0) {
                $out[] = (float)$c;
            }
        }
        return array_values(array_unique($out));
    }

    public static function detectTechnicalLevels(array $rowsByTf, ?array $levels = null): array
    {
        $rows1h = $rowsByTf['1h'] ?? null;
        $rows4h = $rowsByTf['4h'] ?? null;
        $emptyResult = ['all' => [], 'confluence' => [], 'ema' => null, 'macd' => null, 'marketStructure' => null];
        if (!$rows1h || count($rows1h) < 30) {
            return $emptyResult;
        }
        $closes1h = array_column($rows1h, 'close');
        $highs1h = array_column($rows1h, 'high');
        $lows1h = array_column($rows1h, 'low');
        $current = (float)end($closes1h);
        $atr = self::calcATR($highs1h, $lows1h, $closes1h, 14);

        $all = [];
        $fibRows = $rows4h ?: $rows1h;
        $swing = self::findFibSwing($fibRows, 50);
        if ($swing) {
            $low = $swing['low'];
            $high = $swing['high'];
            $range = $high - $low;
            $fibs = [
                '0.236' => ['ratio' => 0.236, 'strength' => 55],
                '0.382' => ['ratio' => 0.382, 'strength' => 70],
                '0.500' => ['ratio' => 0.500, 'strength' => 85],
                '0.618' => ['ratio' => 0.618, 'strength' => 95],
                '0.786' => ['ratio' => 0.786, 'strength' => 65],
            ];
            $upSwing = $swing['direction'] === 'up';
            foreach ($fibs as $lbl => $f) {
                $price = $upSwing ? ($high - $range * $f['ratio']) : ($low + $range * $f['ratio']);
                $all[] = ['price' => $price, 'type' => 'fib_' . $lbl, 'strength' => $f['strength']];
            }
        }
        if (count($rows1h) >= 48) {
            $prevDayRows = array_slice($rows1h, -48, 24);
            $pdH = max(array_column($prevDayRows, 'high'));
            $pdL = min(array_column($prevDayRows, 'low'));
            $pdC = (float)end($prevDayRows)['close'];
            $pp = ($pdH + $pdL + $pdC) / 3.0;
            $rng = $pdH - $pdL;
            $all[] = ['price' => $pp, 'type' => 'pivot_pp', 'strength' => 80];
            $all[] = ['price' => 2.0 * $pp - $pdL, 'type' => 'pivot_r1', 'strength' => 75];
            $all[] = ['price' => 2.0 * $pp - $pdH, 'type' => 'pivot_s1', 'strength' => 75];
            $all[] = ['price' => $pp + $rng, 'type' => 'pivot_r2', 'strength' => 65];
            $all[] = ['price' => $pp - $rng, 'type' => 'pivot_s2', 'strength' => 65];
            $all[] = ['price' => (float)$pdH, 'type' => 'pdh', 'strength' => 85];
            $all[] = ['price' => (float)$pdL, 'type' => 'pdl', 'strength' => 85];
        }
        foreach (self::roundNumberLevels($current) as $rn) {
            $all[] = ['price' => $rn, 'type' => 'round', 'strength' => 50];
        }
        if (is_array($levels)) {
            if (isset($levels['swingHigh'])) {
                $all[] = ['price' => (float)$levels['swingHigh'], 'type' => 'swing_high', 'strength' => 80];
            }
            if (isset($levels['swingLow'])) {
                $all[] = ['price' => (float)$levels['swingLow'], 'type' => 'swing_low', 'strength' => 80];
            }
            if (isset($levels['hvnAbove'])) {
                $all[] = ['price' => (float)$levels['hvnAbove'], 'type' => 'hvn_above', 'strength' => 75];
            }
            if (isset($levels['hvnBelow'])) {
                $all[] = ['price' => (float)$levels['hvnBelow'], 'type' => 'hvn_below', 'strength' => 75];
            }
        }
        $all = array_values(array_filter($all, fn($l) => $l['price'] > 0 && abs($l['price'] - $current) / $current < 0.10));
        usort($all, fn($a, $b) => $a['price'] <=> $b['price']);

        $confluence = [];
        if ($atr && $atr > 0 && $all) {
            $i = 0;
            $m = count($all);
            while ($i < $m) {
                $cluster = [$all[$i]];
                $j = $i + 1;
                while ($j < $m && abs($all[$j]['price'] - $cluster[0]['price']) <= $atr * 0.4) {
                    $cluster[] = $all[$j];
                    $j++;
                }
                if (count($cluster) >= 2) {
                    $prices = array_column($cluster, 'price');
                    $types = array_values(array_unique(array_column($cluster, 'type')));
                    $strengths = array_column($cluster, 'strength');
                    $confluence[] = [
                        'price' => array_sum($prices) / count($prices),
                        'count' => count($cluster),
                        'types' => $types,
                        'strength' => min(100, (int)round(array_sum($strengths) / 2)),
                    ];
                }
                $i = $j;
            }
        }

        $emaRows = $rows4h && count($rows4h) >= 60 ? $rows4h : $rows1h;
        $emaCloses = array_column($emaRows, 'close');
        $emaTfLabel = $emaRows === $rows4h ? '4h' : '1h';
        $ema21Series = self::calcEma($emaCloses, 21);
        $ema55Series = self::calcEma($emaCloses, 55);
        $ema = null;
        if ($ema21Series && $ema55Series) {
            $e21 = end($ema21Series);
            $e55 = end($ema55Series);
            $prev21 = $ema21Series[count($ema21Series) - 2] ?? null;
            $prev55 = $ema55Series[count($ema55Series) - 2] ?? null;
            $cross = null;
            if ($prev21 !== null && $prev55 !== null) {
                if ($prev21 < $prev55 && $e21 > $e55) {
                    $cross = 'golden';
                } elseif ($prev21 > $prev55 && $e21 < $e55) {
                    $cross = 'death';
                }
            }
            $ema = [
                'tf' => $emaTfLabel,
                'ema21' => $e21,
                'ema55' => $e55,
                'direction' => $e21 > $e55 ? 'up' : 'down',
                'cross' => $cross,
                'priceVs21' => $current > $e21 ? 'above' : 'below',
            ];
        }

        $macd = self::calcMacd($closes1h);
        $marketStructure = $rows4h ? self::detectMarketStructure($rows4h) : null;

        return [
            'all' => $all,
            'confluence' => $confluence,
            'ema' => $ema,
            'macd' => $macd,
            'marketStructure' => $marketStructure,
        ];
    }

    // ===============================================================
    // Pre-move patterns
    // ===============================================================

    public static function detectMovementPatterns(array $rowsByTf, array $divergence, ?float $change24h): array
    {
        $prePump = ['score' => 0, 'signals' => ['squeeze' => null, 'accumulation' => null, 'higherLows' => null]];
        $preDump = ['score' => 0, 'signals' => ['parabolic' => null, 'distribution' => null, 'climax' => null]];

        $rows1h = $rowsByTf['1h'] ?? null;
        if (!$rows1h || count($rows1h) < 30) {
            return ['prePump' => $prePump, 'preDump' => $preDump];
        }
        $closes = array_column($rows1h, 'close');
        $highs = array_column($rows1h, 'high');
        $lows = array_column($rows1h, 'low');
        $vols = array_column($rows1h, 'volume');
        $n = count($closes);

        $stdSeries = self::rollingReturnStdSeries($closes, 14);
        if (count($stdSeries) >= 20) {
            $current = end($stdSeries);
            $hist = array_slice($stdSeries, -50, -1);
            if ($current !== null && $current > 0 && count($hist) >= 10) {
                sort($hist);
                $median = $hist[(int)floor(count($hist) / 2)];
                if ($median > 0) {
                    $ratio = $current / $median;
                    $prePump['signals']['squeeze'] = $ratio >= 1.0 ? 0.0 : (float)min(100.0, round((1.0 - $ratio) * 200, 1));
                }
            }
        }

        if ($n >= 22) {
            $obv = self::calcObvSeries($closes, $vols);
            $obvWin = array_slice($obv, -20);
            $priceWin = array_slice($closes, -20);
            $obvSlope = self::linRegSlope($obvWin);
            $priceSlope = self::linRegSlope($priceWin);
            $obvNorm = end($obv) > 0 ? $obvSlope / max(1.0, abs(end($obv))) : 0.0;
            $priceMean = array_sum($priceWin) / count($priceWin);
            $priceNorm = $priceMean > 0 ? $priceSlope / $priceMean : 0.0;
            if ($obvNorm > 0 && $priceNorm <= 0.0005) {
                $intensity = min(100.0, abs($obvNorm) * 80000 + abs($priceNorm) * 5000);
                $prePump['signals']['accumulation'] = round(max(20.0, $intensity), 1);
            } else {
                $prePump['signals']['accumulation'] = 0.0;
            }
        }

        $window = min(30, $n);
        $pivots = self::findPivotLows(array_slice($lows, -$window), 3);
        if (count($pivots) >= 3) {
            $ascending = 0;
            for ($i = 1; $i < count($pivots); $i++) {
                if ($pivots[$i]['v'] > $pivots[$i - 1]['v']) {
                    $ascending++;
                }
            }
            $strength = ($ascending / (count($pivots) - 1)) * 100;
            $prePump['signals']['higherLows'] = round($strength, 1);
        } else {
            $prePump['signals']['higherLows'] = 0.0;
        }

        if (is_numeric($change24h) && $n >= 48) {
            $dailyRanges = [];
            $bars = min(168, $n);
            for ($i = $n - $bars; $i + 24 < $n; $i += 24) {
                $sliceH = array_slice($highs, $i, 24);
                $sliceL = array_slice($lows, $i, 24);
                if ($sliceH && $sliceL) {
                    $hi = max($sliceH);
                    $lo = min($sliceL);
                    if ($lo > 0) {
                        $dailyRanges[] = (($hi - $lo) / $lo) * 100;
                    }
                }
            }
            if ($dailyRanges) {
                sort($dailyRanges);
                $medianRange = $dailyRanges[(int)floor(count($dailyRanges) / 2)];
                if ($medianRange > 0) {
                    $ratio = abs($change24h) / $medianRange;
                    if ($change24h > 0 && $ratio >= 1.5) {
                        $intensity = min(100.0, ($ratio - 1.5) * 80);
                        $preDump['signals']['parabolic'] = round(max(20.0, $intensity), 1);
                    } else {
                        $preDump['signals']['parabolic'] = 0.0;
                    }
                }
            }
        }

        $bearishDiv = (($divergence['4h'] ?? null) === 'bearish') || (($divergence['1d'] ?? null) === 'bearish');
        if ($bearishDiv && $n >= 30) {
            $recentHigh = max(array_slice($highs, -30));
            $lastClose = $closes[$n - 1];
            $distFromHigh = $recentHigh > 0 ? ($recentHigh - $lastClose) / $recentHigh : 1.0;
            $volMean = $n >= 22 ? array_sum(array_slice($vols, -22, 20)) / 20 : 0.0;
            $volRatio = $volMean > 0 ? $vols[$n - 2] / $volMean : 0.0;
            if ($distFromHigh <= 0.05 && $volRatio >= 1.0) {
                $intensity = min(100.0, (1.0 - $distFromHigh / 0.05) * 50 + ($volRatio - 1.0) * 30);
                $preDump['signals']['distribution'] = round(max(30.0, $intensity), 1);
            } else {
                $preDump['signals']['distribution'] = 0.0;
            }
        } else {
            $preDump['signals']['distribution'] = 0.0;
        }

        if ($n >= 22) {
            $volMean = array_sum(array_slice($vols, -22, 20)) / 20;
            if ($volMean > 0) {
                $vRatio = $vols[$n - 2] / $volMean;
                $lastH = $highs[$n - 2];
                $lastL = $lows[$n - 2];
                $lastC = $closes[$n - 2];
                $range = $lastH - $lastL;
                $closePos = $range > 0 ? ($lastC - $lastL) / $range : 0.5;
                $recentHigh = max(array_slice($highs, -30));
                $nearHigh = $recentHigh > 0 ? ($lastH / $recentHigh) >= 0.96 : false;
                if ($vRatio >= 2.0 && $closePos <= 0.4 && $nearHigh) {
                    $intensity = min(100.0, ($vRatio - 2.0) * 25 + (0.4 - $closePos) * 100);
                    $preDump['signals']['climax'] = round(max(40.0, $intensity), 1);
                } else {
                    $preDump['signals']['climax'] = 0.0;
                }
            }
        }

        $prePump['score'] = self::averageSignals($prePump['signals']);
        $preDump['score'] = self::averageSignals($preDump['signals']);
        return ['prePump' => $prePump, 'preDump' => $preDump];
    }

    private static function averageSignals(array $signals): int
    {
        $vals = array_filter($signals, fn($v) => $v !== null);
        if (!$vals) {
            return 0;
        }
        return (int)round(array_sum($vals) / count($vals));
    }

    private static function rollingReturnStdSeries(array $closes, int $window): array
    {
        $n = count($closes);
        if ($n < $window + 1) {
            return [];
        }
        $rets = [];
        for ($i = 1; $i < $n; $i++) {
            $rets[] = $closes[$i - 1] > 0 ? ($closes[$i] - $closes[$i - 1]) / $closes[$i - 1] : 0.0;
        }
        $out = [];
        for ($i = $window - 1; $i < count($rets); $i++) {
            $slice = array_slice($rets, $i - $window + 1, $window);
            $mean = array_sum($slice) / $window;
            $sq = 0.0;
            foreach ($slice as $r) {
                $sq += ($r - $mean) ** 2;
            }
            $out[] = sqrt($sq / $window);
        }
        return $out;
    }

    private static function calcObvSeries(array $closes, array $vols): array
    {
        $n = min(count($closes), count($vols));
        if ($n === 0) {
            return [];
        }
        $obv = [0.0];
        for ($i = 1; $i < $n; $i++) {
            $sign = $closes[$i] > $closes[$i - 1] ? 1 : ($closes[$i] < $closes[$i - 1] ? -1 : 0);
            $obv[] = $obv[$i - 1] + $sign * (float)$vols[$i];
        }
        return $obv;
    }

    private static function linRegSlope(array $y): float
    {
        $n = count($y);
        if ($n < 2) {
            return 0.0;
        }
        $sumX = $sumY = $sumXY = $sumXX = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $x = (float)$i;
            $yi = (float)$y[$i];
            $sumX += $x;
            $sumY += $yi;
            $sumXY += $x * $yi;
            $sumXX += $x * $x;
        }
        $den = $n * $sumXX - $sumX * $sumX;
        if ($den == 0.0) {
            return 0.0;
        }
        return ($n * $sumXY - $sumX * $sumY) / $den;
    }

    // ===============================================================
    // Pivots / trendlines / pinbar / vol-std / wave
    // ===============================================================

    private static function findPivotLows(array $lows, int $pad = 2): array
    {
        $n = count($lows);
        $out = [];
        for ($i = $pad; $i < $n - $pad; $i++) {
            $isPivot = true;
            for ($j = $i - $pad; $j <= $i + $pad; $j++) {
                if ($j === $i) {
                    continue;
                }
                if ($lows[$j] <= $lows[$i]) {
                    $isPivot = false;
                    break;
                }
            }
            if ($isPivot) {
                $out[] = ['i' => $i, 'v' => (float)$lows[$i]];
            }
        }
        return $out;
    }

    private static function findPivotHighs(array $highs, int $pad = 2): array
    {
        $n = count($highs);
        $out = [];
        for ($i = $pad; $i < $n - $pad; $i++) {
            $isPivot = true;
            for ($j = $i - $pad; $j <= $i + $pad; $j++) {
                if ($j === $i) {
                    continue;
                }
                if ($highs[$j] >= $highs[$i]) {
                    $isPivot = false;
                    break;
                }
            }
            if ($isPivot) {
                $out[] = ['i' => $i, 'v' => (float)$highs[$i]];
            }
        }
        return $out;
    }

    public static function detectTrendlines(array $rows1h): array
    {
        $n = count($rows1h);
        if ($n < 20) {
            return ['up' => null, 'down' => null];
        }
        $highs = array_column($rows1h, 'high');
        $lows = array_column($rows1h, 'low');
        $closes = array_column($rows1h, 'close');
        $atr = self::calcATR($highs, $lows, $closes, 14);
        if (!$atr || $atr <= 0) {
            $atr = null;
        }
        $currentIdx = $n - 1;
        $current = (float)$closes[$currentIdx];
        $pivotLows = self::findPivotLows($lows, 3);
        $pivotHighs = self::findPivotHighs($highs, 3);

        $fitLine = function (array $pivots, callable $ordered) use ($currentIdx, $current, $atr) {
            $count = count($pivots);
            for ($i = $count - 1; $i > 0; $i--) {
                $p2 = $pivots[$i];
                $p1 = $pivots[$i - 1];
                if (!$ordered($p1, $p2)) {
                    continue;
                }
                $dx = $p2['i'] - $p1['i'];
                if ($dx <= 0) {
                    continue;
                }
                $slope = ($p2['v'] - $p1['v']) / $dx;
                $intercept = $p2['v'] - $slope * $p2['i'];
                $project = $slope * $currentIdx + $intercept;
                if ($project <= 0) {
                    continue;
                }
                $delta = $current - $project;
                return [
                    'slope' => $slope,
                    'lastPivotBar' => $p2['i'],
                    'priorPivotBar' => $p1['i'],
                    'price' => $project,
                    'distanceAtr' => $atr ? $delta / $atr : null,
                    'distancePct' => ($delta / $project) * 100,
                    'barsSinceTouch' => $currentIdx - $p2['i'],
                ];
            }
            return null;
        };

        $up = $fitLine($pivotLows, fn($a, $b) => $b['v'] > $a['v']);
        $down = $fitLine($pivotHighs, fn($a, $b) => $b['v'] < $a['v']);
        return ['up' => $up, 'down' => $down];
    }

    public static function calcReturnStd(array $closes, int $window = 20): ?float
    {
        $n = count($closes);
        if ($n < $window + 1) {
            return null;
        }
        $start = $n - $window;
        $rets = [];
        for ($i = $start; $i < $n; $i++) {
            $prev = $closes[$i - 1];
            if ($prev <= 0.0) {
                continue;
            }
            $rets[] = ($closes[$i] - $prev) / $prev;
        }
        $m = count($rets);
        if ($m < 2) {
            return null;
        }
        $mean = array_sum($rets) / $m;
        $sq = 0.0;
        foreach ($rets as $r) {
            $sq += ($r - $mean) * ($r - $mean);
        }
        return sqrt($sq / $m);
    }

    public static function detectPinbar(array $rows, int $lookback = 10): ?string
    {
        $n = count($rows);
        if ($n < $lookback + 2) {
            return null;
        }
        $bar = $rows[$n - 2];
        if (!isset($bar['open'], $bar['high'], $bar['low'], $bar['close'])) {
            return null;
        }
        $open = (float)$bar['open'];
        $high = (float)$bar['high'];
        $low = (float)$bar['low'];
        $close = (float)$bar['close'];
        $range = $high - $low;
        if ($range <= 0.0) {
            return null;
        }
        $body = abs($close - $open);
        $bodyTop = max($open, $close);
        $bodyBot = min($open, $close);
        $lowerWick = $bodyBot - $low;
        $upperWick = $high - $bodyTop;
        $bodyPct = $body / $range;
        $lowerWickPct = $lowerWick / $range;
        $upperWickPct = $upperWick / $range;
        $start = $n - 2 - $lookback;
        $priorLow = INF;
        $priorHigh = -INF;
        for ($i = $start; $i < $n - 2; $i++) {
            if (!isset($rows[$i]['low'], $rows[$i]['high'])) {
                continue;
            }
            $priorLow = min($priorLow, (float)$rows[$i]['low']);
            $priorHigh = max($priorHigh, (float)$rows[$i]['high']);
        }
        if ($bodyPct <= 0.35 && $lowerWick >= 2.0 * $body && $upperWickPct <= 0.20 && $low <= $priorLow * 1.001) {
            return 'bullish';
        }
        if ($bodyPct <= 0.35 && $upperWick >= 2.0 * $body && $lowerWickPct <= 0.20 && $high >= $priorHigh * 0.999) {
            return 'bearish';
        }
        return null;
    }

    public static function detectWavePattern(array $rows, float $swingMinPct = 0.015, int $pivotWindow = 3): ?array
    {
        $n = count($rows);
        if ($n < 20) {
            return null;
        }
        $closes = array_column($rows, 'close');
        $highs = array_column($rows, 'high');
        $lows = array_column($rows, 'low');
        if (count($closes) !== $n) {
            return null;
        }
        $rawPivots = [];
        for ($i = $pivotWindow; $i < $n - $pivotWindow; $i++) {
            $isHi = true;
            $isLo = true;
            for ($k = 1; $k <= $pivotWindow; $k++) {
                if ($highs[$i] <= $highs[$i - $k] || $highs[$i] <= $highs[$i + $k]) {
                    $isHi = false;
                }
                if ($lows[$i] >= $lows[$i - $k] || $lows[$i] >= $lows[$i + $k]) {
                    $isLo = false;
                }
                if (!$isHi && !$isLo) {
                    break;
                }
            }
            if ($isHi) {
                $rawPivots[] = ['i' => $i, 'p' => $highs[$i], 'kind' => 'H'];
            } elseif ($isLo) {
                $rawPivots[] = ['i' => $i, 'p' => $lows[$i], 'kind' => 'L'];
            }
        }
        $sig = [];
        foreach ($rawPivots as $piv) {
            if (!$sig) {
                $sig[] = $piv;
                continue;
            }
            $prev = end($sig);
            if ($prev['kind'] === $piv['kind']) {
                if (($piv['kind'] === 'H' && $piv['p'] > $prev['p']) || ($piv['kind'] === 'L' && $piv['p'] < $prev['p'])) {
                    array_pop($sig);
                    $sig[] = $piv;
                }
                continue;
            }
            $moveFrac = $prev['p'] > 0 ? abs($piv['p'] - $prev['p']) / $prev['p'] : 0.0;
            if ($moveFrac >= $swingMinPct) {
                $sig[] = $piv;
            }
        }
        if (count($sig) < 3) {
            return null;
        }
        $tail = array_slice($sig, -6);
        $hs = [];
        $ls = [];
        foreach ($tail as $p) {
            if ($p['kind'] === 'H') {
                $hs[] = $p['p'];
            } else {
                $ls[] = $p['p'];
            }
        }
        $direction = 'range';
        if (count($hs) >= 2 && count($ls) >= 2) {
            $hUp = $hs[count($hs) - 1] > $hs[0];
            $lUp = $ls[count($ls) - 1] > $ls[0];
            $hDn = $hs[count($hs) - 1] < $hs[0];
            $lDn = $ls[count($ls) - 1] < $ls[0];
            if ($hUp && $lUp) {
                $direction = 'up';
            } elseif ($hDn && $lDn) {
                $direction = 'down';
            }
        }
        $phase = 'corrective';
        $lastClose = $closes[$n - 1];
        $atr = self::calcATR($highs, $lows, $closes, 14);
        if ($direction === 'up') {
            $lastHigh = null;
            for ($i = count($sig) - 1; $i >= 0; $i--) {
                if ($sig[$i]['kind'] === 'H') {
                    $lastHigh = $sig[$i];
                    break;
                }
            }
            if ($lastHigh) {
                $impulseLeg = null;
                for ($i = count($sig) - 1; $i >= 0; $i--) {
                    if ($sig[$i]['kind'] === 'L' && $sig[$i]['i'] < $lastHigh['i']) {
                        $impulseLeg = $lastHigh['p'] - $sig[$i]['p'];
                        break;
                    }
                }
                $distToHigh = $lastHigh['p'] - $lastClose;
                if ($atr !== null && $distToHigh <= $atr) {
                    $phase = 'impulse';
                } elseif ($impulseLeg !== null && $impulseLeg > 0 && $distToHigh >= $impulseLeg * 0.30) {
                    $phase = 'pullback';
                }
            }
        } elseif ($direction === 'down') {
            $lastLow = null;
            for ($i = count($sig) - 1; $i >= 0; $i--) {
                if ($sig[$i]['kind'] === 'L') {
                    $lastLow = $sig[$i];
                    break;
                }
            }
            if ($lastLow) {
                $impulseLeg = null;
                for ($i = count($sig) - 1; $i >= 0; $i--) {
                    if ($sig[$i]['kind'] === 'H' && $sig[$i]['i'] < $lastLow['i']) {
                        $impulseLeg = $sig[$i]['p'] - $lastLow['p'];
                        break;
                    }
                }
                $distToLow = $lastClose - $lastLow['p'];
                if ($atr !== null && $distToLow <= $atr) {
                    $phase = 'impulse';
                } elseif ($impulseLeg !== null && $impulseLeg > 0 && $distToLow >= $impulseLeg * 0.30) {
                    $phase = 'pullback';
                }
            }
        }
        return [
            'direction' => $direction,
            'phase' => $phase,
            'swings' => count($sig),
            'pivots' => count($rawPivots),
        ];
    }
}
