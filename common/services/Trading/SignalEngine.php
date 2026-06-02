<?php

declare(strict_types=1);

namespace common\services\Trading;

use Yii;

/**
 * Scans the entire universe at the daily close (00:00 UTC), evaluates
 * Experiment 8 triggers (v1 setup-specific stops + T+20 time exit) for
 * each symbol, computes entry/stop/size, and returns a list of fresh
 * Signal records ready to send / persist.
 *
 * The engine itself does NOT send or store — that's the caller's job.
 * This separation lets the backend admin run dry-runs.
 */
final class SignalEngine
{
    private MarketData $md;
    private Universe $universe;
    private SetupEvaluator $evaluator;
    private PositionSizer $sizer;

    /** @var array<string, mixed> */
    private array $cfg;

    /** @var array<string, float>|null cache of per-symbol beta */
    private ?array $betaCache = null;

    public function __construct(
        ?MarketData $md = null,
        ?Universe $universe = null,
        ?SetupEvaluator $evaluator = null,
        ?PositionSizer $sizer = null,
        ?array $config = null,
    ) {
        $this->md        = $md        ?? new MarketData();
        $this->universe  = $universe  ?? new Universe($config);
        $this->evaluator = $evaluator ?? new SetupEvaluator();
        $this->sizer     = $sizer     ?? new PositionSizer($config);
        $this->cfg       = $config ?? (Yii::$app->params['trading'] ?? []);
    }

    /**
     * Run a full scan as of `$asOfDay` (default = the just-closed UTC day).
     *
     * @return array{
     *   as_of: string,
     *   signals: list<array<string,mixed>>,
     *   diagnostics: array<string,mixed>
     * }
     */
    public function scan(?string $asOfDay = null): array
    {
        $asOf = $asOfDay ?? gmdate('Y-m-d', strtotime('-1 day', time())); // most recent fully-closed UTC day
        $symbols = $this->md->listSymbols();

        $dormant = array_flip($this->cfg['dormantSetups'] ?? []);
        $applyClusterOthers = (bool)($this->cfg['applyClusterFilterOtherSetups'] ?? true);
        $clusterThr = (int)($this->cfg['clusterPauseThreshold'] ?? 50);
        $fundingMode = (string)($this->cfg['fundingMode'] ?? 'mean');

        // Pre-compute yesterday's cluster dump count.
        $yesterday = (new \DateTimeImmutable($asOf, new \DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
        $clusterDumpCount = $this->countUniverseDumps($symbols, $yesterday);
        $clusterTriggered = $applyClusterOthers && $clusterDumpCount >= $clusterThr;

        $signals     = [];
        $skipReasons = [];
        $trapVetoes  = [];
        $scanned     = 0;
        $noFeatureRow = 0;

        // Build BTC return series once (used for beta).
        $btcRets = $this->dailyReturns('BTC');

        foreach ($symbols as $symbol) {
            $candles = $this->md->dailyCandles($symbol);
            $features = $candles ? Features::computeDaily($candles) : [];
            $reject = $this->universe->rejectReason($symbol, $features);
            if ($reject !== null) {
                $skipReasons[$reject] = ($skipReasons[$reject] ?? 0) + 1;
                continue;
            }
            $scanned++;

            // Locate the feature row for asOf day.
            $today = null;
            for ($i = count($features) - 1; $i >= 0; $i--) {
                if ($features[$i]['day'] === $asOf) {
                    $today = $features[$i];
                    break;
                }
            }
            if ($today === null) {
                $noFeatureRow++;
                continue;
            }

            $priceOnly = $this->universe->isMemecoinPriceOnly($symbol);

            // Build per-day macro context for this symbol.
            $ctx = $this->buildContext($symbol, $asOf, $fundingMode, $priceOnly);

            // Multi-timeframe block for the MTF setups (L5 / S6). $i is the
            // index of the as-of feature row located in the loop above.
            $ctx['mtf'] = MtfContext::compute($this->md, $symbol, $features, $i);

            $fires = $this->evaluator->evaluate($today, $ctx, $priceOnly);
            if (empty($fires)) {
                continue;
            }

            $beta = $this->betaForSymbol($symbol, $btcRets);

            foreach ($fires as $fire) {
                if (isset($dormant[$fire['setup']])) {
                    continue;
                }
                // Cluster filter — L1 always allowed (Exp 9), others gated.
                if ($clusterTriggered && $fire['setup'] !== 'L1') {
                    continue;
                }

                // Wait-for-the-turn veto (rsi-module detectTraps port). No-op
                // unless trading.requireTurnConfirmation is enabled.
                if (TrapGate::veto($fire, $ctx['mtf'] ?? [], (float)$today['ret'], $this->cfg) !== null) {
                    $trapVetoes[$fire['setup']] = ($trapVetoes[$fire['setup']] ?? 0) + 1;
                    continue;
                }

                // For market-open entries we don't have a known fill price yet:
                // use today's close as the *estimate* for sizing purposes only.
                $entryPx = $fire['entry_px'] ?? (float)$today['c'];

                $size = $this->sizer->size(
                    entryPx: $entryPx,
                    stopPx:  $fire['stop_px'],
                    beta:    $beta ?? 1.0,
                );

                $aggressive = $this->isAggressive($fire, $ctx);
                if ($aggressive) {
                    $size = $this->sizer->size(
                        entryPx: $entryPx,
                        stopPx:  $fire['stop_px'],
                        beta:    $beta ?? 1.0,
                        aggressive: true,
                    );
                }

                $signals[] = [
                    'as_of'          => $asOf,
                    'symbol'         => $symbol,
                    'setup'          => $fire['setup'],
                    'side'           => $fire['dir'],
                    'method'         => $fire['method'],
                    'entry_px'       => $fire['entry_px'],            // null for market-open
                    'entry_estimate' => $entryPx,                      // for sizing/preview
                    'stop_px'        => $fire['stop_px'],
                    'window_h'       => $fire['window_h'],
                    'time_exit_days' => (int)($this->cfg['timeExitDays'] ?? 20),
                    'beta'           => $beta,
                    'aggressive'     => $aggressive,
                    'size'           => $size,
                    'reasons'        => $fire['reasons'],
                    'context'        => $ctx,
                    'price_only'     => $priceOnly,
                ];
            }
        }

        // Sort: deterministic order — setup → -|risk_pct_used*expectancy_proxy| then symbol.
        usort($signals, static function (array $a, array $b): int {
            $setupOrder = ['L1' => 0, 'L5' => 1, 'L3' => 2, 'S3' => 3, 'S4' => 4, 'S6' => 5, 'L2' => 6, 'L4' => 7, 'S1' => 8, 'S2' => 9];
            $ao = $setupOrder[$a['setup']] ?? 9;
            $bo = $setupOrder[$b['setup']] ?? 9;
            return $ao <=> $bo ?: strcmp($a['symbol'], $b['symbol']);
        });

        return [
            'as_of'   => $asOf,
            'signals' => $signals,
            'diagnostics' => [
                'symbols_total'        => count($symbols),
                'symbols_scanned'      => $scanned,
                'symbols_no_feature'   => $noFeatureRow,
                'skip_reasons'         => $skipReasons,
                'trap_vetoes'          => $trapVetoes,
                'cluster_dumps_yday'   => $clusterDumpCount,
                'cluster_pause_active' => $clusterTriggered,
                'time_exit_days'       => (int)($this->cfg['timeExitDays'] ?? 20),
                'funding_mode'         => $fundingMode,
            ],
        ];
    }

    /**
     * Intraday scan at one 4h bar close — evaluates ONLY the multi-timeframe
     * setups (L5 / S6) so the daily L1-S4 don't re-fire six times a day. This
     * is where the 4h-timeframe S6 actually fires: run it on a 4h cadence (or
     * hourly — dedup by the 4h-grained as_of prevents duplicates within a bar).
     *
     * @param int|null $asOfMs decision timestamp (ms); default = latest completed 4h boundary.
     * @return array{as_of:string, as_of_ts:int, signals:list<array<string,mixed>>, diagnostics:array<string,mixed>}
     */
    public function scanIntraday(?int $asOfMs = null): array
    {
        $asOfMs  = $asOfMs ?? self::latest4hBoundaryMs();
        $symbols = $this->md->listSymbols();
        $dormant = array_flip($this->cfg['dormantSetups'] ?? []);

        $btcRets  = $this->dailyReturns('BTC');
        $s6Floor  = (float)($this->cfg['s6MinTurnover20dUsd'] ?? 1_000_000);
        $signals  = [];
        $trapVetoes = [];
        $scanned  = 0;
        $s6Relaxed = 0;
        $noMtf    = 0;

        foreach ($symbols as $symbol) {
            $mtf = MtfContext::computeAt($this->md, $symbol, $asOfMs);
            if ($mtf === null) {
                $noMtf++;
                continue;
            }
            // Two-tier universe gate: the standard $5M floor for L1-L5, and a
            // looser floor for S6 (its edge is on thinner alts — see backtest).
            $candles  = $this->md->dailyCandles($symbol);
            $features = $candles ? Features::computeDaily($candles) : [];
            $rejStd = $this->universe->rejectReason($symbol, $features);
            $rejS6  = $this->universe->rejectReason($symbol, $features, $s6Floor);
            if ($rejStd !== null && $rejS6 !== null) {
                continue;   // fails even the relaxed gate
            }
            $scanned++;
            if ($rejStd !== null) {
                $s6Relaxed++;   // tradeable only via the relaxed S6 gate
            }

            $refClose = (float)$mtf['ref_close'];
            $fires = $this->evaluator->evaluateMtf($mtf, $refClose);
            // Gate fires by setup: S6 may trade the relaxed universe; every
            // other setup requires the standard turnover floor.
            $fires = array_values(array_filter(
                $fires,
                static fn(array $f): bool => $f['setup'] === 'S6' ? $rejS6 === null : $rejStd === null,
            ));
            if (empty($fires)) {
                continue;
            }

            $priceOnly = $this->universe->isMemecoinPriceOnly($symbol);
            $beta      = $this->betaForSymbol($symbol, $btcRets);
            $barTime   = gmdate('Y-m-d\TH:i', intdiv((int)$mtf['ref_ts'], 1000));

            foreach ($fires as $fire) {
                if (isset($dormant[$fire['setup']])) {
                    continue;
                }
                // Wait-for-the-turn veto. Pass null for the parabolic move: a 4h
                // move is not comparable to the daily threshold, so only the
                // RSI-slope rule applies on the intraday cadence.
                if (TrapGate::veto($fire, $mtf, null, $this->cfg) !== null) {
                    $trapVetoes[$fire['setup']] = ($trapVetoes[$fire['setup']] ?? 0) + 1;
                    continue;
                }
                $entryPx = $fire['entry_px'] ?? $refClose;
                $size = $this->sizer->size(
                    entryPx: $entryPx,
                    stopPx:  $fire['stop_px'],
                    beta:    $beta ?? 1.0,
                );
                $signals[] = [
                    'as_of'          => $barTime,                       // 4h-grained -> per-bar dedup
                    'as_of_day'      => substr($barTime, 0, 10),
                    'symbol'         => $symbol,
                    'setup'          => $fire['setup'],
                    'side'           => $fire['dir'],
                    'method'         => $fire['method'],
                    'entry_px'       => $fire['entry_px'],              // null for market-open
                    'entry_estimate' => $entryPx,
                    'stop_px'        => $fire['stop_px'],
                    'window_h'       => $fire['window_h'],
                    'time_exit_days' => (int)($this->cfg['timeExitDays'] ?? 20),
                    'beta'           => $beta,
                    'aggressive'     => false,
                    'size'           => $size,
                    'reasons'        => $fire['reasons'],
                    'context'        => [],
                    'price_only'     => $priceOnly,
                ];
            }
        }

        usort($signals, static function (array $a, array $b): int {
            $order = ['L5' => 0, 'S6' => 1];
            return ($order[$a['setup']] ?? 9) <=> ($order[$b['setup']] ?? 9) ?: strcmp($a['symbol'], $b['symbol']);
        });

        return [
            'as_of'    => gmdate('Y-m-d\TH:i', intdiv($asOfMs, 1000)),
            'as_of_ts' => $asOfMs,
            'signals'  => $signals,
            'diagnostics' => [
                'symbols_total'      => count($symbols),
                'symbols_scanned'    => $scanned,
                'symbols_s6_relaxed' => $s6Relaxed,
                'trap_vetoes'        => $trapVetoes,
                's6_min_turnover'    => $s6Floor,
                'symbols_no_mtf'     => $noMtf,
                'time_exit_days'     => (int)($this->cfg['timeExitDays'] ?? 20),
            ],
        ];
    }

    /** Most recent completed 4h boundary (00/04/08/12/16/20 UTC), in ms. */
    private static function latest4hBoundaryMs(): int
    {
        $step = 4 * 3600;
        return (intdiv(time(), $step) * $step) * 1000;
    }

    /**
     * Macro context (funding / OI / FNG / BTC.D) for one symbol on one day.
     */
    private function buildContext(string $symbol, string $day, string $fundingMode, bool $priceOnly): array
    {
        $fng = $this->md->fng();
        $dom = $this->md->dominance();

        $fundingByDay = [];
        $oiByDay = [];
        if (!$priceOnly && $this->md->hasDerivatives($symbol)) {
            $fundingByDay = Features::fundingDaily($this->md->fundingRaw($symbol), $fundingMode);
            $oiByDay = Features::oiDaily($this->md->openInterestRaw($symbol));
        }

        return [
            'funding'         => $fundingByDay[$day] ?? null,
            'funding_7d'      => $fundingByDay ? Features::fundingPriorMean($fundingByDay, $day, 7) : null,
            'oi_z'            => $oiByDay ? Features::oiZscore($oiByDay, $day, 20) : null,
            'fng'             => isset($fng[$day]) ? (int)$fng[$day]['v'] : null,
            'btc_d'           => isset($dom[$day]) ? (float)$dom[$day]['btc_d'] : null,
            'btc_d_30d_delta' => $this->md->btcDelta30d($day),
        ];
    }

    /** TRADE.md §3 — count yesterday's dumps (≤−10% OR ≤−2.5σ). */
    private function countUniverseDumps(array $symbols, string $yesterday): int
    {
        $cnt = 0;
        foreach ($symbols as $symbol) {
            if ($this->universe->isBlacklisted($symbol)) {
                continue;
            }
            $candles = $this->md->dailyCandles($symbol);
            $features = $candles ? Features::computeDaily($candles) : [];
            foreach ($features as $row) {
                if ($row['day'] !== $yesterday) {
                    continue;
                }
                $r = $row['ret'];
                $sigma = $row['vol20'] !== null && $row['vol20'] > 0 ? $r / $row['vol20'] : 0;
                if ($r <= -0.10 || $sigma <= -2.5) {
                    $cnt++;
                }
                break;
            }
        }
        return $cnt;
    }

    /** TRADE.md §3 — "be aggressive" overrides. */
    private function isAggressive(array $fire, array $ctx): bool
    {
        if ($fire['setup'] === 'L1' && isset($ctx['funding']) && $ctx['funding'] <= -0.001) {
            return true;
        }
        if ($fire['setup'] === 'S2' && isset($ctx['funding'], $ctx['fng'])
            && $ctx['funding'] >= 0.0010 && $ctx['fng'] >= 80) {
            return true;
        }
        return false;
    }

    /** Cached daily-return series for one symbol (chronological order). */
    private function dailyReturns(string $symbol): array
    {
        $candles = $this->md->dailyCandles($symbol);
        $rets = [];
        for ($i = 1, $n = count($candles); $i < $n; $i++) {
            $prev = $candles[$i - 1]['c'];
            $rets[] = $prev > 0 ? $candles[$i]['c'] / $prev - 1 : 0.0;
        }
        return $rets;
    }

    /**
     * 365d beta to BTC for one symbol; cached across the scan.
     */
    private function betaForSymbol(string $symbol, array $btcRets): ?float
    {
        if ($symbol === 'BTC') {
            return 1.0;
        }
        if ($this->betaCache !== null && array_key_exists($symbol, $this->betaCache)) {
            return $this->betaCache[$symbol];
        }
        $rets = $this->dailyReturns($symbol);
        $beta = Features::betaToBtc($rets, $btcRets, 365);
        $this->betaCache[$symbol] = $beta;
        return $beta;
    }
}
