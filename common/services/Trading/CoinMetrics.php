<?php

declare(strict_types=1);

namespace common\services\Trading;

/**
 * Wilder RSI(14) helper — the only per-coin metric the backend still computes
 * (the RSI heatmap). The rest of the old metrics pipeline (divergence, pinbar,
 * waves, confluence levels) was removed together with the signal engine; a new
 * strategy will bring its own indicators.
 *
 * Pure functions of candle closes; no HTTP or stored state.
 */
final class CoinMetrics
{
    private const RSI_PERIOD = 14;

    public static function calcRSI(array $closes, int $period = self::RSI_PERIOD): ?float
    {
        $state = self::rsiState($closes, $period);
        return $state !== null ? self::rsiFromAvgs($state['g'], $state['l']) : null;
    }

    /**
     * Wilder smoothing state after the last of $closes: average gain, average
     * loss and that close. Lets a caller price in a still-forming bar later
     * with {@see rsiWithClose} instead of re-running the whole series — e.g.
     * state over the closed bars + the live ticker price. Null with fewer than
     * $period + 1 closes.
     *
     * @return array{g:float, l:float, c:float}|null
     */
    public static function rsiState(array $closes, int $period = self::RSI_PERIOD): ?array
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
        $state = ['g' => $gainSum / $period, 'l' => $lossSum / $period, 'c' => (float)$closes[$period]];
        for ($i = $period + 1; $i < $n; $i++) {
            $state = self::rsiAdvance($state, (float)$closes[$i], $period);
        }
        return $state;
    }

    /**
     * One Wilder step: the state after appending $close.
     *
     * @param array{g:float, l:float, c:float} $state
     * @return array{g:float, l:float, c:float}
     */
    public static function rsiAdvance(array $state, float $close, int $period = self::RSI_PERIOD): array
    {
        $d = $close - $state['c'];
        return [
            'g' => ($state['g'] * ($period - 1) + ($d > 0 ? $d : 0.0)) / $period,
            'l' => ($state['l'] * ($period - 1) + ($d < 0 ? -$d : 0.0)) / $period,
            'c' => $close,
        ];
    }

    /**
     * RSI once $close is appended to the series $state was built from —
     * identical to calcRSI() over the full series.
     *
     * @param array{g:float, l:float, c:float} $state
     */
    public static function rsiWithClose(array $state, float $close, int $period = self::RSI_PERIOD): float
    {
        $next = self::rsiAdvance($state, $close, $period);
        return self::rsiFromAvgs($next['g'], $next['l']);
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
