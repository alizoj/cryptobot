<?php

declare(strict_types=1);

namespace common\services\Trading;

/**
 * Wilder RSI(14) helper — the only per-coin metric the backend still computes
 * (the RSI heatmap). The rest of the old metrics pipeline (divergence, pinbar,
 * waves, confluence levels) was removed together with the signal engine; a new
 * strategy will bring its own indicators.
 *
 * Pure function of candle closes; no HTTP or state.
 */
final class CoinMetrics
{
    private const RSI_PERIOD = 14;

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

    private static function rsiFromAvgs(float $avgG, float $avgL): float
    {
        if ($avgL == 0.0) {
            return $avgG == 0.0 ? 50.0 : 100.0;
        }
        $rs = $avgG / $avgL;
        return 100.0 - (100.0 / (1.0 + $rs));
    }
}
