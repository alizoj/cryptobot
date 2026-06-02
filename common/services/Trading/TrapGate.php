<?php

declare(strict_types=1);

namespace common\services\Trading;

/**
 * "Wait-for-the-turn" veto layer.
 *
 * Ported from the rsi-module SignalService::detectTraps — the hard-won insight
 * from the first project: an RSI/price extreme is only a setup once the reversal
 * has *started*. "Deep + still moving away" is a falling knife, not an entry.
 *
 * The live SetupEvaluator fires on the extreme bar itself with no reversal
 * confirmation, so its mean-reversion / fade family (L2 capitulation long,
 * L5 dip-buy, S3/S4 rejection shorts, S6 4h blow-off short) is exposed to
 * exactly that failure mode. This gate adds the missing confirmation as a
 * post-filter in SignalEngine — it never changes which setups exist, only
 * whether a fire is allowed through.
 *
 * Two of the three original traps port directly because they only need RSI
 * slope (which MtfContext now exposes) plus the bar's directional move:
 *
 *   1. Turn not started — every available entry-TF RSI slope is still moving
 *      against the trade (long: RSI still falling; short: RSI still rising).
 *   3. Parabolic / capitulation — a vertical move with no slope turn yet;
 *      don't fade a candle that is still accelerating.
 *
 * (The original trap #2 — "fresh impulse against" — needs the Elliott-wave
 * phase classifier the live MtfContext does not compute, so it is intentionally
 * omitted here; #1 and #3 overlap with it heavily.)
 *
 * Disabled by default (trading.requireTurnConfirmation) so live behaviour is
 * unchanged until validated with `php yii trading-backtest/gate`.
 */
final class TrapGate
{
    /**
     * Default gated set: the market-/immediate-fill fades that have no built-in
     * follow-through requirement. L1/S1/S2 use stop-/limit entries that already
     * require an adverse tick before they fill, so they are left out by default.
     *
     * S3 (volume-exhaustion short) is deliberately EXCLUDED: it fires on a still-
     * accelerating blow-off, so "RSI not yet turned down" vetoes the very fades
     * that then collapse hardest — the backtest showed kept −0.05R vs vetoed
     * +2.58R. The slope-turn confirmation helps the dip-buys (L2/L5) and the
     * rejection short S4, not the momentum-exhaustion short S3.
     *
     * @var list<string>
     */
    public const DEFAULT_SETUPS = ['L2', 'L5', 'S4', 'S6'];

    /**
     * Decide whether a fired setup should be vetoed because the reversal it is
     * betting on has not actually started yet.
     *
     * @param array<string,mixed> $fire one fire from SetupEvaluator (uses 'setup', 'dir')
     * @param array<string,mixed> $mtf  the MtfContext block (uses h4_rsi_slope, h1_rsi_slope)
     * @param float|null          $move directional move proxy for the parabolic
     *                                  rule (daily ret at the daily close); pass
     *                                  null to skip rule 3 (e.g. the intraday
     *                                  scan, where a 4h move is not comparable to
     *                                  a 24h threshold)
     * @param array<string,mixed> $cfg  trading config
     * @return string|null short human reason when vetoed, or null to allow
     */
    public static function veto(array $fire, array $mtf, ?float $move, array $cfg): ?string
    {
        if (!($cfg['requireTurnConfirmation'] ?? false)) {
            return null;
        }
        $setups = $cfg['turnConfirmSetups'] ?? self::DEFAULT_SETUPS;
        if (!in_array($fire['setup'], $setups, true)) {
            return null;
        }
        $dir = $fire['dir'] ?? null;
        if ($dir !== 'long' && $dir !== 'short') {
            return null;
        }

        $h1 = self::num($mtf['h1_rsi_slope'] ?? null);
        $h4 = self::num($mtf['h4_rsi_slope'] ?? null);
        // No slope data at all → fail open. Never block a setup purely because
        // RSI slope was unavailable (thin history, missing 4h/1h candles).
        if ($h1 === null && $h4 === null) {
            return null;
        }
        $eps = (float)($cfg['turnSlopeEps'] ?? 0.0);

        // Rule 1 — the turn has not started.
        if (self::stillAgainst($dir, $h1, $h4, $eps)) {
            return $dir === 'long'
                ? 'RSI still falling — no upward turn confirmed yet'
                : 'RSI still rising — no downward turn confirmed yet';
        }

        // Rule 3 — parabolic / capitulation move with no slope turn yet.
        $par = (float)($cfg['parabolicMovePct'] ?? 0.12);
        if ($move !== null && $par > 0 && !self::turnStarted($dir, $h1, $h4, $eps)) {
            if ($dir === 'short' && $move >= $par) {
                return 'parabolic +' . round($move * 100, 1) . '% move, reversal not started';
            }
            if ($dir === 'long' && $move <= -$par) {
                return 'capitulation ' . round($move * 100, 1) . '% move, reversal not started';
            }
        }

        return null;
    }

    /** Every available slope is still moving against the trade. */
    private static function stillAgainst(string $dir, ?float $h1, ?float $h4, float $eps): bool
    {
        $slopes = array_values(array_filter([$h1, $h4], static fn($v): bool => $v !== null));
        if (!$slopes) {
            return false;
        }
        foreach ($slopes as $s) {
            $against = $dir === 'long' ? ($s < -$eps) : ($s > $eps);
            if (!$against) {
                return false;   // at least one slope is not against → not "still against"
            }
        }
        return true;
    }

    /** At least one available slope has turned in the trade's direction. */
    private static function turnStarted(string $dir, ?float $h1, ?float $h4, float $eps): bool
    {
        foreach ([$h1, $h4] as $s) {
            if ($s === null) {
                continue;
            }
            $with = $dir === 'long' ? ($s > $eps) : ($s < -$eps);
            if ($with) {
                return true;
            }
        }
        return false;
    }

    private static function num(mixed $v): ?float
    {
        return is_numeric($v) ? (float)$v : null;
    }
}
