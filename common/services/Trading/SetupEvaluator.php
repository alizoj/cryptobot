<?php

declare(strict_types=1);

namespace common\services\Trading;

/**
 * Evaluates the Experiment 8 trigger set (L1-S4) against the current day's
 * features + macro context. Returns a list of fired setups with entry,
 * stop, side and entry method.
 *
 * Follows TRADE.md §1-§2 verbatim; S1 and S2 are kept here for completeness
 * but the caller (SignalEngine) suppresses them via the DORMANT list.
 *
 * L5 (pullback dip-buy) and S6 (4h blow-off rejection) are multi-timeframe
 * setups added from the entry-pattern study (docs/results/MTF_ENTRY_ANALYSIS*).
 * They read the $ctx['mtf'] block produced by MtfContext. S6 keys off the
 * most-recent completed 4h bar, so it only fires fresh if the scan runs on a
 * 4h cadence (a daily-only scan catches just the 20:00-00:00 bar).
 *
 * "Memecoin price-only" symbols skip funding/OI conditions (so they can
 * still hit L3/S3/S4/L5/S6 via the price gates alone).
 */
final class SetupEvaluator
{
    public const ENTRY_WINDOW_24H = 24;
    public const ENTRY_WINDOW_48H = 48;

    /**
     * @param array<string, mixed> $today  feature row for the just-closed daily candle
     * @param array<string, mixed> $ctx    macro context: funding, funding_7d, oi_z, fng, btc_d, btc_d_30d_delta
     * @param bool                 $priceOnly  true for memecoins — skip derivs setups
     * @return list<array{setup:string,dir:'long'|'short',method:string,entry_px:?float,stop_px:float,window_h:int,reasons:array<string,mixed>}>
     */
    public function evaluate(array $today, array $ctx, bool $priceOnly = false): array
    {
        $fires = [];
        $vr  = $today['vol_ratio'];
        $c   = (float)$today['c'];
        $h   = (float)$today['h'];
        $l   = (float)$today['l'];
        $ret = (float)$today['ret'];
        $uw  = (float)$today['upper_wick'];
        $lw  = (float)$today['lower_wick'];
        $ch  = (float)$today['closed_high'];

        $fund    = $ctx['funding']         ?? null;
        $fund7d  = $ctx['funding_7d']      ?? null;
        $oiz     = $ctx['oi_z']            ?? null;
        $fng     = $ctx['fng']             ?? null;
        $btc_d   = $ctx['btc_d']           ?? null;
        $dbtc30  = $ctx['btc_d_30d_delta'] ?? null;

        // ---- L1 short-squeeze long [CORE] ----
        if (!$priceOnly
            && $vr !== null && $vr >= 1.0
            && $fund !== null && $fund <= -0.0002
            && $fng !== null && $fng >= 26 && $fng <= 45
        ) {
            $stop = max($l * 0.995, $c * 0.93);
            $fires[] = [
                'setup'    => 'L1',
                'dir'      => 'long',
                'method'   => 'stop_limit',     // stop-limit at C*1.003 valid 24h
                'entry_px' => $c * 1.003,
                'stop_px'  => $stop,
                'window_h' => self::ENTRY_WINDOW_24H,
                'reasons'  => ['funding' => $fund, 'fng' => $fng, 'vol_ratio' => $vr],
            ];
        }

        // ---- L2 capitulation reversal [SMALL-SAMPLE] ----
        if ($vr !== null && $vr >= 2.0 && $lw >= 0.20 && $ret <= 0
            && $fng !== null && $fng <= 25
        ) {
            $fires[] = [
                'setup'    => 'L2',
                'dir'      => 'long',
                'method'   => 'market_open',
                'entry_px' => null,
                'stop_px'  => $l * 0.99,
                'window_h' => self::ENTRY_WINDOW_24H,
                'reasons'  => ['fng' => $fng, 'lower_wick' => $lw, 'ret' => $ret, 'vol_ratio' => $vr],
            ];
        }

        // ---- L3 momentum continuation [CORE] ----
        if ($vr !== null && $vr >= 2.0
            && $ch >= 0.85 && $ret >= 0.04 && $ret <= 0.15 && $uw <= 0.05
            && $btc_d !== null && $btc_d >= 45 && $btc_d < 65
        ) {
            $stop = ($h + $l) / 2 * 0.995;
            $fires[] = [
                'setup'    => 'L3',
                'dir'      => 'long',
                'method'   => 'stop_buy',       // buy-stop above today's high
                'entry_px' => $h * 1.002,
                'stop_px'  => $stop,
                'window_h' => self::ENTRY_WINDOW_24H,
                'reasons'  => ['closed_high' => $ch, 'ret' => $ret, 'upper_wick' => $uw, 'vol_ratio' => $vr, 'btc_d' => $btc_d],
            ];
        }

        // ---- S1 crowded-long blowup [DORMANT] ----
        if (!$priceOnly
            && $fund !== null && $fund >= 0.0005
            && $fund7d !== null && $fund7d >= 0.0003
            && $fng !== null && $fng >= 26 && $fng <= 45
            && $oiz !== null && $oiz >= 1.0
        ) {
            $stop = max($h, $c * 1.05) * 1.005;
            $fires[] = [
                'setup'    => 'S1',
                'dir'      => 'short',
                'method'   => 'sell_limit',
                'entry_px' => $c * 1.010,
                'stop_px'  => $stop,
                'window_h' => self::ENTRY_WINDOW_48H,
                'reasons'  => ['funding' => $fund, 'funding_7d' => $fund7d, 'fng' => $fng, 'oi_z' => $oiz],
            ];
        }

        // ---- S2 euphoria-trap short [DORMANT] ----
        if (!$priceOnly
            && $fund !== null && $fund >= 0.0005
            && $fund7d !== null && $fund7d >= 0.0003
            && $fng !== null && $fng >= 76
            && $oiz !== null && $oiz >= 1.0
            && $dbtc30 !== null && $dbtc30 >= 0.5
        ) {
            $stop = max($h, $c * 1.05) * 1.005;
            $fires[] = [
                'setup'    => 'S2',
                'dir'      => 'short',
                'method'   => 'sell_limit',
                'entry_px' => $c * 1.010,
                'stop_px'  => $stop,
                'window_h' => self::ENTRY_WINDOW_48H,
                'reasons'  => ['funding' => $fund, 'funding_7d' => $fund7d, 'fng' => $fng, 'oi_z' => $oiz, 'btc_d_30d_delta' => $dbtc30],
            ];
        }

        // ---- S3 volume-exhaustion short [CORE] ----
        if ($vr !== null && $vr >= 10.0 && $ret >= 0.10) {
            $method   = $uw >= 0.20 ? 'market_open' : 'sell_limit';
            $entry_px = $method === 'market_open' ? null : $c * 1.005;
            $fires[]  = [
                'setup'    => 'S3',
                'dir'      => 'short',
                'method'   => $method,
                'entry_px' => $entry_px,
                'stop_px'  => $h * 1.005,
                'window_h' => self::ENTRY_WINDOW_24H,
                'reasons'  => ['vol_ratio' => $vr, 'ret' => $ret, 'upper_wick' => $uw],
            ];
        }

        // ---- S4 rejection short [CORE] ----
        if ($vr !== null && $vr >= 2.0 && $ret >= 0.10 && $uw >= 0.20 && $ch <= 0.50) {
            $fires[] = [
                'setup'    => 'S4',
                'dir'      => 'short',
                'method'   => 'sell_limit',
                'entry_px' => ($h + $l) / 2,
                'stop_px'  => $h * 1.005,
                'window_h' => self::ENTRY_WINDOW_24H,
                'reasons'  => ['ret' => $ret, 'upper_wick' => $uw, 'closed_high' => $ch, 'vol_ratio' => $vr],
            ];
        }

        // ---- MTF setups (L5 / S6) — evaluated here at the daily close, and
        //      also reusable intraday via evaluateMtf() for the 4h-cadence scan.
        foreach ($this->evaluateMtf($ctx['mtf'] ?? [], $c) as $fire) {
            $fires[] = $fire;
        }

        return $fires;
    }

    /**
     * Evaluate the multi-timeframe setups (L5, S6) against an MtfContext block
     * and a reference close price. Called both from evaluate() (daily close)
     * and from SignalEngine::scanIntraday() at each 4h bar close — where the
     * 4h-timeframe S6 actually fires fresh.
     *
     * @param array<string, mixed> $mtf  block from MtfContext::compute / computeAt
     * @param float                $c    reference close (daily close, or the 4h-bar close intraday)
     * @return list<array{setup:string,dir:string,method:string,entry_px:?float,stop_px:float,window_h:int,reasons:array<string,mixed>}>
     */
    public function evaluateMtf(array $mtf, float $c): array
    {
        $fires = [];
        if (($l5 = $this->tryL5($mtf, $c)) !== null) {
            $fires[] = $l5;
        }
        if (($s6 = $this->tryS6($mtf, $c)) !== null) {
            $fires[] = $s6;
        }
        return $fires;
    }

    /**
     * L5 pullback dip-buy long [MTF]. Daily pullback + a lower-timeframe
     * oversold reading. Best large-n long in the full-universe study
     * (d_down + h4/h1 rsi<40: edge ~2.3-2.4, 54-66% win) — the biggest winners
     * (ENJ/HIGH) were dips in *weekly downtrends*, so we do NOT gate on w_up;
     * it's recorded as context only. No fixed TP (time/trail exit).
     */
    private function tryL5(array $mtf, float $c): ?array
    {
        $d_down = $mtf['d_down'] ?? false;
        $h4_rsi = $mtf['h4_rsi'] ?? null;
        $h1_rsi = $mtf['h1_rsi'] ?? null;
        $oversold = ($h4_rsi !== null && $h4_rsi < 40) || ($h1_rsi !== null && $h1_rsi < 40);
        if (!$d_down || !$oversold) {
            return null;
        }
        $swingLow = (float)($mtf['d_low3'] ?? $c);     // 3-day low already includes today's low
        $stop     = max($swingLow * 0.97, $c * 0.90);  // recent-low buffer, capped at -10%
        return [
            'setup'    => 'L5',
            'dir'      => 'long',
            'method'   => 'market_open',
            'entry_px' => null,
            'stop_px'  => $stop,
            'window_h' => self::ENTRY_WINDOW_24H,
            'reasons'  => ['d_down' => 1, 'h4_rsi' => $h4_rsi, 'h1_rsi' => $h1_rsi, 'w_up' => ($mtf['w_up'] ?? null) === true ? 1 : 0],
        ];
    }

    /**
     * S6 4h blow-off rejection short [MTF]. Fade a 4h blow-off (pump + upper-
     * wick rejection) in a daily uptrend. Highest-edge short bucket in the
     * study (d_up + h4_upper_wick: f5d +15%, 73% win). The d_up gate keeps S6
     * from shorting capitulation *bounces* (a 4h pop off a crash low fires
     * d_down, not d_up). Edge<1 (blow-off ranges huge) -> wide stop, small size.
     */
    private function tryS6(array $mtf, float $c): ?array
    {
        $d_up   = $mtf['d_up'] ?? false;
        $h4last = $mtf['h4_last'] ?? null;
        if (!$d_up || $h4last === null
            || $h4last['ret'] < 0.04 || $h4last['upper_wick'] < 0.15
        ) {
            return null;
        }
        $stop = max((float)$h4last['high'], $c) * 1.005;  // above the 4h blow-off high
        return [
            'setup'    => 'S6',
            'dir'      => 'short',
            'method'   => 'market_open',
            'entry_px' => null,
            'stop_px'  => $stop,
            'window_h' => self::ENTRY_WINDOW_24H,
            'reasons'  => ['d_up' => 1, 'h4_ret' => $h4last['ret'], 'h4_upper_wick' => $h4last['upper_wick'], 'h4_rsi' => $mtf['h4_rsi'] ?? null],
        ];
    }
}
