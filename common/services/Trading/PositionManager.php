<?php

declare(strict_types=1);

namespace common\services\Trading;

use Yii;

/**
 * Lifecycle manager for open positions:
 *
 *  - T+20 close reminder (mandatory per TRADE.md §5)
 *  - L1 funding-flip invalidation (funding >= +0.0002 mid-trade → exit)
 *  - S1/S2 funding-flip invalidation (funding <= 0 mid-trade → exit)
 *  - trailing stop: after T+1, once in 2% profit, ratchet stop to the
 *    most recent 4h pivot low (longs) or 4h pivot high (shorts)
 *
 * Returns a list of exit/update events ready to send to Telegram; closed
 * positions are appended to the closed-positions journal and removed
 * from the open list.
 *
 * `manage()` is idempotent for a given asOf — running it twice produces
 * the same exit events (caller is responsible for deduping via the
 * signal log).
 */
final class PositionManager
{
    private MarketData $md;
    private PositionStore $store;
    /** @var array<string,mixed> */
    private array $cfg;

    public function __construct(
        ?MarketData $md = null,
        ?PositionStore $store = null,
        ?array $config = null,
    ) {
        $this->md    = $md    ?? new MarketData();
        $this->store = $store ?? new PositionStore();
        $this->cfg   = $config ?? (Yii::$app->params['trading'] ?? []);
    }

    /**
     * Run lifecycle pass. Returns events to broadcast.
     *
     * @return list<array<string, mixed>>
     */
    public function manage(?string $asOfDay = null): array
    {
        $asOf = $asOfDay ?? gmdate('Y-m-d');
        $exitDays = (int)($this->cfg['timeExitDays'] ?? 20);
        $trailActivate = (float)($this->cfg['trailActivateProfitPct'] ?? 0.02);
        $fundingMode = (string)($this->cfg['fundingMode'] ?? 'mean');

        $events = [];
        $remaining = [];
        $now = time();

        foreach ($this->store->openPositions() as $pos) {
            // Need at least: entry_px, entry_day, side, setup, symbol, stop_px
            $sym = $pos['symbol'];
            $candles = $this->md->dailyCandles($sym);
            $last = end($candles) ?: null;

            $daysHeld = self::dayDiff($asOf, $pos['entry_day']);
            $latestPx = $last !== null ? (float)$last['c'] : (float)$pos['entry_px'];
            $pnlPct = $pos['side'] === 'long'
                ? $latestPx / $pos['entry_px'] - 1
                : $pos['entry_px'] / $latestPx - 1;

            $exited = false;

            // ---- 1) Hard stop hit on the daily candle ----
            if ($last !== null && self::touchedStop($pos, $last)) {
                $events[] = [
                    'kind'       => 'stop_hit',
                    'symbol'     => $sym,
                    'setup'      => $pos['setup'],
                    'side'       => $pos['side'],
                    'entry_px'   => $pos['entry_px'],
                    'entry_day'  => $pos['entry_day'],
                    'exit_px'    => $pos['stop_px'],
                    'pnl_pct'    => $pos['side'] === 'long'
                        ? $pos['stop_px'] / $pos['entry_px'] - 1
                        : $pos['entry_px'] / $pos['stop_px'] - 1,
                ];
                $pos['outcome'] = [
                    'exit_ts'     => $now,
                    'exit_day'    => $last['day'] ?? $asOf,
                    'exit_px'     => $pos['stop_px'],
                    'exit_reason' => 'stop',
                ];
                $this->store->appendClosed($pos);
                $exited = true;
            }

            // ---- 2) Funding-flip invalidation ----
            if (!$exited && in_array($pos['setup'], ['L1', 'S1', 'S2'], true) && $this->md->hasDerivatives($sym)) {
                $fundingByDay = Features::fundingDaily($this->md->fundingRaw($sym), $fundingMode);
                $todayFunding = $fundingByDay[$asOf] ?? $fundingByDay[gmdate('Y-m-d', $now)] ?? null;
                $flip = false;
                if ($pos['setup'] === 'L1' && $todayFunding !== null && $todayFunding >= 0.0002) {
                    $flip = true;
                }
                if (in_array($pos['setup'], ['S1', 'S2'], true) && $todayFunding !== null && $todayFunding <= 0) {
                    $flip = true;
                }
                if ($flip) {
                    $events[] = [
                        'kind'       => 'funding_invalid',
                        'symbol'     => $sym,
                        'setup'      => $pos['setup'],
                        'side'       => $pos['side'],
                        'entry_px'   => $pos['entry_px'],
                        'entry_day'  => $pos['entry_day'],
                        'exit_px'    => $latestPx,
                        'pnl_pct'    => $pnlPct,
                        'note'       => "Funding=" . sprintf('%.5f', $todayFunding) . " — premise gone. Exit next open.",
                    ];
                    $pos['outcome'] = [
                        'exit_ts'     => $now,
                        'exit_day'    => $asOf,
                        'exit_px'     => $latestPx,
                        'exit_reason' => 'funding_invalidation',
                        'funding_at_exit' => $todayFunding,
                    ];
                    $this->store->appendClosed($pos);
                    $exited = true;
                }
            }

            // ---- 3) T+N time exit (Experiment 8 default = T+20) ----
            if (!$exited && $daysHeld >= $exitDays) {
                $events[] = [
                    'kind'       => 'time_exit',
                    'symbol'     => $sym,
                    'setup'      => $pos['setup'],
                    'side'       => $pos['side'],
                    'entry_px'   => $pos['entry_px'],
                    'entry_day'  => $pos['entry_day'],
                    'exit_px'    => $latestPx,
                    'days_held'  => $daysHeld,
                    'pnl_pct'    => $pnlPct,
                    'note'       => "Mandatory close at T+{$exitDays}.",
                ];
                $pos['outcome'] = [
                    'exit_ts'     => $now,
                    'exit_day'    => $asOf,
                    'exit_px'     => $latestPx,
                    'exit_reason' => 'time_exit_T+' . $exitDays,
                ];
                $this->store->appendClosed($pos);
                $exited = true;
            }

            if ($exited) {
                continue;
            }

            // ---- 4) Trailing stop: after T+1, once in trailActivate profit ----
            if ($daysHeld >= 1 && $pnlPct >= $trailActivate) {
                $newStop = $this->trailStopPivot($sym, $pos['side']);
                if ($newStop !== null) {
                    if ($pos['side'] === 'long' && $newStop > ($pos['stop_px'] + 1e-12)) {
                        $events[] = [
                            'kind'      => 'trail_update',
                            'symbol'    => $sym,
                            'setup'     => $pos['setup'],
                            'side'      => $pos['side'],
                            'entry_px'  => $pos['entry_px'],
                            'entry_day' => $pos['entry_day'],
                            'old_stop'  => $pos['stop_px'],
                            'new_stop'  => $newStop,
                            'pnl_pct'   => $pnlPct,
                        ];
                        $pos['stop_px'] = $newStop;
                        $pos['stop_history'][] = ['day' => $asOf, 'px' => $newStop];
                    } elseif ($pos['side'] === 'short' && $newStop < ($pos['stop_px'] - 1e-12)) {
                        $events[] = [
                            'kind'      => 'trail_update',
                            'symbol'    => $sym,
                            'setup'     => $pos['setup'],
                            'side'      => $pos['side'],
                            'entry_px'  => $pos['entry_px'],
                            'entry_day' => $pos['entry_day'],
                            'old_stop'  => $pos['stop_px'],
                            'new_stop'  => $newStop,
                            'pnl_pct'   => $pnlPct,
                        ];
                        $pos['stop_px'] = $newStop;
                        $pos['stop_history'][] = ['day' => $asOf, 'px' => $newStop];
                    }
                }
            }

            $remaining[] = $pos;
        }

        $this->store->replaceOpen($remaining);
        return $events;
    }

    private static function touchedStop(array $pos, array $candle): bool
    {
        if ($pos['side'] === 'long') {
            return $candle['l'] <= $pos['stop_px'];
        }
        return $candle['h'] >= $pos['stop_px'];
    }

    private static function dayDiff(string $a, string $b): int
    {
        $da = new \DateTimeImmutable($a, new \DateTimeZone('UTC'));
        $db = new \DateTimeImmutable($b, new \DateTimeZone('UTC'));
        return (int)$da->diff($db)->days * ($da >= $db ? 1 : -1);
    }

    /**
     * 4h pivot low (longs) or high (shorts) from the most recent ~3 days.
     * Returns null if not enough 4h data.
     */
    private function trailStopPivot(string $symbol, string $side): ?float
    {
        $bars = $this->md->fourHourCandles($symbol);
        if (count($bars) < 6) {
            return null;
        }
        $look = array_slice($bars, -18);  // ~3 days of 4h bars
        if ($side === 'long') {
            $lo = PHP_FLOAT_MAX;
            foreach ($look as $b) {
                if ($b['l'] < $lo) {
                    $lo = $b['l'];
                }
            }
            return $lo * 0.998;
        }
        $hi = 0.0;
        foreach ($look as $b) {
            if ($b['h'] > $hi) {
                $hi = $b['h'];
            }
        }
        return $hi * 1.002;
    }
}
