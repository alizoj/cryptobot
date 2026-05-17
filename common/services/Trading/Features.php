<?php

declare(strict_types=1);

namespace common\services\Trading;

/**
 * Pure functions: daily candle feature engineering used by the SignalEngine.
 *
 * Mirrors the formulas in TRADE.md §0 and the simulator's compute_features().
 * Kept stateless so it's trivially testable.
 */
final class Features
{
    public const VOL_WINDOW = 20;

    /**
     * @param array<int, array{ts:int,o:float,h:float,l:float,c:float,v:float}> $candles
     * @return list<array<string, mixed>>
     */
    public static function computeDaily(array $candles): array
    {
        $n = count($candles);
        $f = [];
        for ($i = 0; $i < $n; $i++) {
            $c = $candles[$i];
            $row = [
                'ts'          => $c['ts'],
                'day'         => gmdate('Y-m-d', intdiv($c['ts'], 1000)),
                'o'           => $c['o'],
                'h'           => $c['h'],
                'l'           => $c['l'],
                'c'           => $c['c'],
                'v'           => $c['v'],
                'ret'         => 0.0,
                'upper_wick'  => 0.0,
                'lower_wick'  => 0.0,
                'closed_high' => 0.0,
                'vol20'       => null,
                'vol_ratio'   => null,
                'turnover'    => $c['v'] * $c['c'],
                'turnover20'  => null,
            ];
            if ($i > 0 && $candles[$i - 1]['c'] > 0) {
                $row['ret'] = $c['c'] / $candles[$i - 1]['c'] - 1;
            }
            if ($c['c'] > 0) {
                $row['upper_wick'] = ($c['h'] - max($c['o'], $c['c'])) / $c['c'];
                $row['lower_wick'] = (min($c['o'], $c['c']) - $c['l']) / $c['c'];
            }
            if ($c['h'] > $c['l']) {
                $row['closed_high'] = ($c['c'] - $c['l']) / ($c['h'] - $c['l']);
            }
            $f[] = $row;
        }
        for ($i = self::VOL_WINDOW; $i < $n; $i++) {
            $rets = $vols = $turns = [];
            for ($j = $i - self::VOL_WINDOW; $j < $i; $j++) {
                $rets[]  = $f[$j]['ret'];
                $vols[]  = $f[$j]['v'];
                $turns[] = $f[$j]['turnover'];
            }
            $m = array_sum($rets) / count($rets);
            $var = 0.0;
            foreach ($rets as $r) {
                $var += ($r - $m) ** 2;
            }
            $f[$i]['vol20']      = sqrt($var / count($rets));
            $avgv                = array_sum($vols) / count($vols);
            $f[$i]['vol_ratio']  = $avgv > 0 ? $f[$i]['v'] / $avgv : null;
            $f[$i]['turnover20'] = array_sum($turns) / count($turns);
        }
        return $f;
    }

    /**
     * Daily mean of 8h funding events for one symbol, keyed by 'Y-m-d'.
     *
     * @param array<int, array{0:int|string,1:string|float}> $rows raw [ts_ms, rate] tuples
     * @param 'mean'|'live' $mode mean = daily avg of 3 events (Exp 12 default).
     * @return array<string, float>
     */
    public static function fundingDaily(array $rows, string $mode = 'mean'): array
    {
        $byDay = [];
        foreach ($rows as $r) {
            $ts = (int)$r[0];
            $day = gmdate('Y-m-d', intdiv($ts, 1000));
            $byDay[$day][] = ['ts' => $ts, 'rate' => (float)$r[1]];
        }
        $out = [];
        foreach ($byDay as $d => $arr) {
            if ($mode === 'live') {
                usort($arr, static fn($a, $b) => $b['ts'] <=> $a['ts']);
                $out[$d] = $arr[0]['rate'];
            } else {
                $rates = array_column($arr, 'rate');
                $out[$d] = array_sum($rates) / count($rates);
            }
        }
        return $out;
    }

    /**
     * Open-interest snapshots reduced to one per day (last observation).
     * @param array<int, array{0:int|string,1:string|float}> $rows
     * @return array<string, float>
     */
    public static function oiDaily(array $rows): array
    {
        $byDay = [];
        foreach ($rows as $r) {
            $ts  = (int)$r[0];
            $day = gmdate('Y-m-d', intdiv($ts, 1000));
            $byDay[$day] = (float)$r[1];
        }
        return $byDay;
    }

    /**
     * Trailing 7-day mean of the funding-by-day map, anchored at $day.
     */
    public static function fundingPriorMean(array $fundingByDay, string $day, int $days = 7): ?float
    {
        $dt = new \DateTimeImmutable($day, new \DateTimeZone('UTC'));
        $arr = [];
        for ($k = $days; $k >= 1; $k--) {
            $d = $dt->modify("-{$k} day")->format('Y-m-d');
            if (isset($fundingByDay[$d])) {
                $arr[] = $fundingByDay[$d];
            }
        }
        return empty($arr) ? null : array_sum($arr) / count($arr);
    }

    /**
     * OI z-score for $day computed against the prior $window days.
     */
    public static function oiZscore(array $oiByDay, string $day, int $window = 20): ?float
    {
        $dt = new \DateTimeImmutable($day, new \DateTimeZone('UTC'));
        $prior = [];
        for ($k = $window; $k >= 1; $k--) {
            $d = $dt->modify("-{$k} day")->format('Y-m-d');
            if (isset($oiByDay[$d])) {
                $prior[] = $oiByDay[$d];
            }
        }
        if (count($prior) < 10) {
            return null;
        }
        $today = $oiByDay[$day] ?? null;
        if ($today === null) {
            return null;
        }
        $m = array_sum($prior) / count($prior);
        $v = 0.0;
        foreach ($prior as $x) {
            $v += ($x - $m) ** 2;
        }
        $sd = sqrt($v / count($prior));
        return $sd > 0 ? ($today - $m) / $sd : null;
    }

    /**
     * Symbol beta to BTC over a trailing window of daily returns.
     * Pass aligned daily-return series (same length, in chronological order).
     */
    public static function betaToBtc(array $symRets, array $btcRets, int $window = 365): ?float
    {
        $n = min(count($symRets), count($btcRets));
        if ($n < 30) {
            return null;
        }
        $start = max(0, $n - $window);
        $cov = $vx = 0.0;
        $count = 0;
        $mx = $my = 0.0;
        for ($i = $start; $i < $n; $i++) {
            $mx += $btcRets[$i];
            $my += $symRets[$i];
            $count++;
        }
        if ($count === 0) {
            return null;
        }
        $mx /= $count;
        $my /= $count;
        for ($i = $start; $i < $n; $i++) {
            $dx = $btcRets[$i] - $mx;
            $dy = $symRets[$i] - $my;
            $cov += $dx * $dy;
            $vx  += $dx * $dx;
        }
        return $vx > 0 ? $cov / $vx : null;
    }
}
