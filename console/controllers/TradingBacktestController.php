<?php

declare(strict_types=1);

namespace console\controllers;

use common\services\Trading\CoinMetrics;
use common\services\Trading\ConfluenceGrader;
use common\services\Trading\Features;
use common\services\Trading\MarketData;
use common\services\Trading\MtfContext;
use common\services\Trading\SetupEvaluator;
use common\services\Trading\TrapGate;
use common\services\Trading\Universe;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * A/B validator for the "wait-for-the-turn" gate (TrapGate).
 *
 *   ./yii trading-backtest/gate                  # default window, gated setups
 *   ./yii trading-backtest/gate --from=2022-01-01 --to=2025-12-31
 *   ./yii trading-backtest/gate --setups=all     # report every setup
 *   ./yii trading-backtest/gate --symbols=BTC,ETH,SOL
 *   ./yii trading-backtest/gate --parabolicMovePct=0.10 --slopeEps=0.5
 *
 * Replays the REAL daily SetupEvaluator over historical candles and, for each
 * fired setup, simulates the trade to a hard stop or T+N time-exit, expressing
 * the result in R (multiples of initial risk). Every fire is also passed to the
 * REAL TrapGate. Results split into three arms per setup:
 *
 *   off     — every filled trade (gate disabled = today's live behaviour)
 *   kept    — trades the gate would let through
 *   vetoed  — trades the gate would remove
 *
 * If "vetoed" has clearly worse expectancy than "off", the gate is removing
 * losers and is worth enabling (trading.requireTurnConfirmation = true).
 *
 * This drives the exact production code path (SetupEvaluator + MtfContext +
 * TrapGate), so it is a faithful test of the change, not a re-implementation.
 * The trade model is intentionally simple — entry-fill matching, a hard stop,
 * and a T+N close — and omits trailing; that omission is identical across all
 * three arms, so the relative comparison stays fair.
 */
final class TradingBacktestController extends Controller
{
    /** @var string YYYY-MM-DD first signal day (default: ~540 days ago). */
    public string $from = '';

    /** @var string YYYY-MM-DD last signal day (default: yesterday UTC). */
    public string $to = '';

    /** @var string comma list of symbols, or '' for the whole universe. */
    public string $symbols = '';

    /** @var string comma list of setups to evaluate, or 'all'. Default: the gated set. */
    public string $setups = '';

    /** @var float override trading.parabolicMovePct (-1 = use config). */
    public float $parabolicMovePct = -1.0;

    /** @var float override trading.turnSlopeEps (-1 = use config). */
    public float $slopeEps = -1.0;

    /** @var int override trading.timeExitDays (0 = use config). */
    public int $timeExitDays = 0;

    /** @var float universe turnover floor in USD (-1 = use config). Lower this to include thin alts. */
    public float $minTurnover = -1.0;

    /** @var string comma list overriding which setups the gate applies to ('' = config turnConfirmSetups). */
    public string $gatedSetups = '';

    /** @var int confluence scorer: min confidence to KEEP a fire (actionConfluence). */
    public int $minConfidence = 70;

    /** @var int confluence scorer: min primary-match count to KEEP a fire (actionConfluence). */
    public int $minPrimary = 2;

    /** @var string standalone grader decision cadence: '1d' or '4h' (actionGrader). */
    public string $step = '1d';

    /** @var float standalone grader: min |24h change| catalyst gate (actionGrader). */
    public float $minChange24h = 7.0;

    /** @var int standalone grader: max trap vetoes allowed (actionGrader). */
    public int $maxTraps = 1;

    /** @var string standalone grader trend gate: none|ema|ms|either|both (actionGrader). */
    public string $trendGate = 'either';

    /** @var float standalone grader: per-(symbol,side) dedup window in hours (actionGrader). */
    public float $dedupHours = 24.0;

    public function options($actionID): array
    {
        return ['from', 'to', 'symbols', 'setups', 'parabolicMovePct', 'slopeEps', 'timeExitDays', 'minTurnover', 'gatedSetups', 'minConfidence', 'minPrimary', 'step', 'minChange24h', 'maxTraps', 'trendGate', 'dedupHours'];
    }

    public function actionGate(): int
    {
        $cfg = Yii::$app->params['trading'] ?? [];

        $from = $this->from !== '' ? $this->from : gmdate('Y-m-d', strtotime('-540 days'));
        $to   = $this->to !== ''   ? $this->to   : gmdate('Y-m-d', strtotime('-1 day'));

        $timeExitDays = $this->timeExitDays > 0
            ? $this->timeExitDays
            : (int)($cfg['timeExitDays'] ?? 20);

        // The gate config used for the backtest: force it ON (live default is
        // OFF) and apply any CLI threshold overrides so we can sweep them.
        $gateCfg = $cfg;
        $gateCfg['requireTurnConfirmation'] = true;
        if ($this->parabolicMovePct >= 0) {
            $gateCfg['parabolicMovePct'] = $this->parabolicMovePct;
        }
        if ($this->slopeEps >= 0) {
            $gateCfg['turnSlopeEps'] = $this->slopeEps;
        }

        $gatedSet = $gateCfg['turnConfirmSetups'] ?? TrapGate::DEFAULT_SETUPS;
        if ($this->gatedSetups !== '') {
            $gatedSet = array_map('trim', explode(',', strtoupper($this->gatedSetups)));
            $gateCfg['turnConfirmSetups'] = $gatedSet;
        }
        $reportAll = strtolower($this->setups) === 'all';
        $setupFilter = $this->setups !== '' && !$reportAll
            ? array_map('trim', explode(',', strtoupper($this->setups)))
            : null;

        $md        = new MarketData();
        $universe  = new Universe($cfg);
        $evaluator = new SetupEvaluator();

        $symbols = $this->symbols !== ''
            ? array_map('trim', explode(',', strtoupper($this->symbols)))
            : $md->listSymbols();

        $fundingMode = (string)($cfg['fundingMode'] ?? 'mean');

        $floor = $this->minTurnover >= 0 ? $this->minTurnover : null;   // null = config default

        $this->stdout(sprintf(
            "[gate-backtest] window %s..%s  setups=%s  T+%d  parabolic=%.3f  slopeEps=%.2f  floor=%s\n",
            $from, $to, $reportAll ? 'all' : ($setupFilter ? implode(',', $setupFilter) : implode(',', $gatedSet)),
            $timeExitDays, $gateCfg['parabolicMovePct'] ?? 0.12, $gateCfg['turnSlopeEps'] ?? 0.0,
            $floor !== null ? '$' . number_format($floor) : 'config',
        ), Console::FG_GREEN);
        $this->stdout(sprintf("  %d symbol(s) to scan\n", count($symbols)));

        // Per-setup, per-arm tallies: ['n'=>, 'wins'=>, 'sumR'=>].
        $stats = [];
        $tally = static function (string $setup, string $arm, float $r) use (&$stats): void {
            $stats[$setup][$arm] ??= ['n' => 0, 'wins' => 0, 'sumR' => 0.0];
            $stats[$setup][$arm]['n']++;
            $stats[$setup][$arm]['sumR'] += $r;
            if ($r > 0) {
                $stats[$setup][$arm]['wins']++;
            }
        };

        $scanned = 0;
        $fills = 0;
        foreach ($symbols as $symbol) {
            $candles = $md->dailyCandles($symbol);
            if (!$candles) {
                continue;
            }
            $features = Features::computeDaily($candles);
            if ($universe->rejectReason($symbol, $features, $floor) !== null) {
                continue;
            }
            $scanned++;
            $priceOnly = $universe->isMemecoinPriceOnly($symbol);

            // Pre-compute the per-symbol derivative maps once (not per row).
            $fundingByDay = [];
            $oiByDay = [];
            if (!$priceOnly && $md->hasDerivatives($symbol)) {
                $fundingByDay = Features::fundingDaily($md->fundingRaw($symbol), $fundingMode);
                $oiByDay = Features::oiDaily($md->openInterestRaw($symbol));
            }

            $n = count($features);
            for ($i = 0; $i < $n - 1; $i++) {        // need i+1 for any entry fill
                $today = $features[$i];
                $day = $today['day'];
                if ($day < $from || $day > $to) {
                    continue;
                }

                $ctx = $this->buildContext($md, $fundingByDay, $oiByDay, $day);
                $ctx['mtf'] = MtfContext::compute($md, $symbol, $features, $i);

                foreach ($evaluator->evaluate($today, $ctx, $priceOnly) as $fire) {
                    $setup = $fire['setup'];
                    if ($setupFilter !== null && !in_array($setup, $setupFilter, true)) {
                        continue;
                    }
                    $sim = $this->simulate($features, $i, $fire, $timeExitDays);
                    if ($sim === null) {
                        continue;   // no fill / no room — not a decided trade
                    }
                    $fills++;
                    $r = $sim['R'];

                    $vetoed = in_array($setup, $gatedSet, true)
                        && TrapGate::veto($fire, $ctx['mtf'], (float)$today['ret'], $gateCfg) !== null;

                    $tally($setup, 'off', $r);
                    $tally($setup, $vetoed ? 'vetoed' : 'kept', $r);
                }
            }
            $md->forgetSymbol();
        }

        $this->stdout(sprintf("  scanned %d tradeable symbol(s), %d filled trade(s)\n\n", $scanned, $fills));
        $this->printReport($stats, $reportAll ? null : $gatedSet);

        return ExitCode::OK;
    }

    /**
     * A/B validator for the CONFLUENCE SCORER overlay (ConfluenceGrader on top
     * of the L1–S6 setups). For every daily fire it builds the CoinMetrics row
     * as of that day's close and asks the grader how strongly it AGREES with
     * the fire's direction. Three arms per setup:
     *
     *   off       — every filled trade (no scorer = today's behaviour)
     *   kept      — fires the scorer would keep (agree & conf≥X & primaries≥Y)
     *   filtered  — fires the scorer would drop
     *
     *   ./yii trading-backtest/confluence
     *   ./yii trading-backtest/confluence --from=2022-01-01 --to=2025-12-31
     *   ./yii trading-backtest/confluence --minConfidence=60 --minPrimary=2
     *   ./yii trading-backtest/confluence --setups=L5 --minTurnover=1000000
     *
     * If 'filtered' expectancy is clearly worse than 'off', the scorer is
     * removing losers and is worth wiring into SignalEngine as a fire filter.
     * This drives the REAL SetupEvaluator + CoinMetrics + ConfluenceGrader, so
     * it is a faithful test of the overlay, not a re-implementation.
     */
    public function actionConfluence(): int
    {
        $cfg = Yii::$app->params['trading'] ?? [];
        $from = $this->from !== '' ? $this->from : gmdate('Y-m-d', strtotime('-540 days'));
        $to   = $this->to !== ''   ? $this->to   : gmdate('Y-m-d', strtotime('-1 day'));
        $timeExitDays = $this->timeExitDays > 0 ? $this->timeExitDays : (int)($cfg['timeExitDays'] ?? 20);

        $reportAll = $this->setups === '' || strtolower($this->setups) === 'all';
        $setupFilter = !$reportAll ? array_map('trim', explode(',', strtoupper($this->setups))) : null;

        $md        = new MarketData();
        $universe  = new Universe($cfg);
        $evaluator = new SetupEvaluator();
        $symbols = $this->symbols !== ''
            ? array_map('trim', explode(',', strtoupper($this->symbols)))
            : $md->listSymbols();
        $fundingMode = (string)($cfg['fundingMode'] ?? 'mean');
        $floor = $this->minTurnover >= 0 ? $this->minTurnover : null;

        $this->stdout(sprintf(
            "[confluence-backtest] window %s..%s  T+%d  keep: conf>=%d & prim>=%d  floor=%s\n",
            $from, $to, $timeExitDays, $this->minConfidence, $this->minPrimary,
            $floor !== null ? '$' . number_format($floor) : 'config',
        ), Console::FG_GREEN);
        $this->stdout(sprintf("  %d symbol(s) to scan\n", count($symbols)));

        // Per-fire records: ['setup'=>, 'r'=>, 'conf'=>, 'prim'=>, 'agree'=>bool].
        $records = [];
        $scanned = 0;
        $fills = 0;

        foreach ($symbols as $symbol) {
            $candles = $md->dailyCandles($symbol);
            if (!$candles) {
                continue;
            }
            $features = Features::computeDaily($candles);
            if ($universe->rejectReason($symbol, $features, $floor) !== null) {
                continue;
            }
            $scanned++;
            $priceOnly = $universe->isMemecoinPriceOnly($symbol);

            $fundingByDay = [];
            $oiByDay = [];
            if (!$priceOnly && $md->hasDerivatives($symbol)) {
                $fundingByDay = Features::fundingDaily($md->fundingRaw($symbol), $fundingMode);
                $oiByDay = Features::oiDaily($md->openInterestRaw($symbol));
            }

            $metricsCache = [];   // closeTs => coin row (one symbol-day computed once)
            $n = count($features);
            for ($i = 0; $i < $n - 1; $i++) {
                $today = $features[$i];
                $day = $today['day'];
                if ($day < $from || $day > $to) {
                    continue;
                }

                $ctx = $this->buildContext($md, $fundingByDay, $oiByDay, $day);
                $ctx['mtf'] = MtfContext::compute($md, $symbol, $features, $i);

                foreach ($evaluator->evaluate($today, $ctx, $priceOnly) as $fire) {
                    $setup = $fire['setup'];
                    if ($setupFilter !== null && !in_array($setup, $setupFilter, true)) {
                        continue;
                    }
                    $sim = $this->simulate($features, $i, $fire, $timeExitDays);
                    if ($sim === null) {
                        continue;
                    }
                    $fills++;

                    $closeTs = (int)$today['ts'] + 86_400_000 - 1;
                    if (!isset($metricsCache[$closeTs])) {
                        $metricsCache[$closeTs] = CoinMetrics::build(
                            $md,
                            $symbol,
                            $closeTs,
                            (float)$today['c'],
                            (float)$today['ret'] * 100.0,
                            $fundingByDay[$day] ?? null,
                        );
                    }
                    $sc = ConfluenceGrader::scoreFire($fire['dir'], $metricsCache[$closeTs]);

                    $records[] = [
                        'setup' => $setup,
                        'r'     => $sim['R'],
                        'conf'  => $sc['confidence'],
                        'prim'  => $sc['primaries'],
                        'agree' => $sc['agree'],
                    ];
                }
            }
            $md->forgetSymbol();
        }

        $this->stdout(sprintf("  scanned %d tradeable symbol(s), %d filled trade(s)\n\n", $scanned, $fills));
        $this->printConfluenceReport($records, $this->minConfidence, $this->minPrimary);

        return ExitCode::OK;
    }

    /**
     * Split fire records into off/kept/filtered tallies at a (conf, prim) gate.
     *
     * @param list<array{setup:string,r:float,conf:int,prim:int,agree:bool}> $recs
     * @return array<string,array{n:int,wins:int,sumR:float}>
     */
    private function splitArms(array $recs, int $minConf, int $minPrim): array
    {
        $mk = static fn() => ['n' => 0, 'wins' => 0, 'sumR' => 0.0];
        $arms = ['off' => $mk(), 'kept' => $mk(), 'filtered' => $mk()];
        $add = static function (string $arm, float $r) use (&$arms): void {
            $arms[$arm]['n']++;
            $arms[$arm]['sumR'] += $r;
            if ($r > 0) {
                $arms[$arm]['wins']++;
            }
        };
        foreach ($recs as $rec) {
            $add('off', $rec['r']);
            $keep = $rec['agree'] && $rec['conf'] >= $minConf && $rec['prim'] >= $minPrim;
            $add($keep ? 'kept' : 'filtered', $rec['r']);
        }
        return $arms;
    }

    /**
     * @param list<array{setup:string,r:float,conf:int,prim:int,agree:bool}> $records
     */
    private function printConfluenceReport(array $records, int $minConf, int $minPrim): void
    {
        if (!$records) {
            $this->stdout("  no fires in window\n");
            return;
        }

        $bySetup = [];
        foreach ($records as $rec) {
            $bySetup[$rec['setup']][] = $rec;
        }
        ksort($bySetup);

        $this->stdout(sprintf("  Per-setup at keep gate (conf>=%d & prim>=%d):\n", $minConf, $minPrim), Console::BOLD);
        $this->stdout(sprintf("  %-6s %-9s %6s %8s %9s\n", 'SETUP', 'ARM', 'N', 'WIN%', 'EXP_R'), Console::BOLD);
        $this->stdout("  " . str_repeat('-', 42) . "\n");
        foreach ($bySetup as $setup => $recs) {
            $arms = $this->splitArms($recs, $minConf, $minPrim);
            foreach (['off', 'kept', 'filtered'] as $arm) {
                $a = $arms[$arm];
                if ($a['n'] === 0 && $arm !== 'off') {
                    continue;
                }
                $color = match ($arm) {
                    'kept'     => Console::FG_GREEN,
                    'filtered' => Console::FG_RED,
                    default    => Console::FG_GREY,
                };
                $this->stdout('  ' . $this->fmtRow($setup, $arm, $a), $color);
            }
            $this->stdout("\n");
        }

        $this->stdout("  " . str_repeat('-', 42) . "\n");
        $this->stdout(sprintf("  Threshold sweep across ALL setups (prim>=%d):\n", $minPrim), Console::BOLD);
        foreach ([50, 60, 70] as $thr) {
            $arms = $this->splitArms($records, $thr, $minPrim);
            $this->stdout(sprintf("  conf>=%d\n", $thr), Console::BOLD);
            foreach (['off', 'kept', 'filtered'] as $arm) {
                $a = $arms[$arm];
                if ($a['n'] === 0 && $arm !== 'off') {
                    continue;
                }
                $color = match ($arm) {
                    'kept'     => Console::FG_GREEN,
                    'filtered' => Console::FG_RED,
                    default    => Console::FG_GREY,
                };
                $this->stdout('  ' . $this->fmtRow('ALL', $arm, $a), $color);
            }
        }
        $this->stdout("\n  Read: if 'filtered' EXP_R << 'off' EXP_R, the scorer removes losers — wire it as a fire filter.\n");
    }

    /**
     * STANDALONE confluence-grader backtest. Unlike /gate and /confluence, this
     * does NOT use SetupEvaluator at all — it runs ConfluenceGrader::grade()
     * directly as its own signal source (the way rsi-module backtested it to
     * +0.60R), and simulates each FIRED trade on the grader's own entry/stop/TP.
     *
     *   ./yii trading-backtest/grader                       # daily decisions, live universe
     *   ./yii trading-backtest/grader --step=4h             # 4h decision cadence (closer to live)
     *   ./yii trading-backtest/grader --from=2022-01-01 --to=2025-12-31
     *   ./yii trading-backtest/grader --minTurnover=1000000 # widen to the broad alt universe
     *   ./yii trading-backtest/grader --minConfidence=65 --minPrimary=2
     *
     * Decision cadence: at each step (daily close, or each 4h close) it builds
     * the CoinMetrics row as of that instant and grades it. A fired signal is
     * taken unless an identical (symbol, side) fired within --dedupHours. The
     * trade fills at the decision price and exits on the first 1h-bar touch of
     * stop (R=-1) or TP1 (R=+rr1), else at the T+N time exit. Everything is in
     * R (multiples of initial risk). No look-ahead: metrics use only bars
     * closed by the decision ts; the trade is walked forward on 1h bars.
     */
    public function actionGrader(): int
    {
        $cfg = Yii::$app->params['trading'] ?? [];
        $from = $this->from !== '' ? $this->from : gmdate('Y-m-d', strtotime('-540 days'));
        $to   = $this->to !== ''   ? $this->to   : gmdate('Y-m-d', strtotime('-1 day'));
        $timeExitDays = $this->timeExitDays > 0 ? $this->timeExitDays : (int)($cfg['timeExitDays'] ?? 20);
        $maxBars1h = $timeExitDays * 24;
        $step = strtolower($this->step) === '4h' ? '4h' : '1d';
        $dedupMs = (int)round($this->dedupHours * 3600 * 1000);

        $md        = new MarketData();
        $universe  = new Universe($cfg);
        $symbols = $this->symbols !== ''
            ? array_map('trim', explode(',', strtoupper($this->symbols)))
            : $md->listSymbols();
        $fundingMode = (string)($cfg['fundingMode'] ?? 'mean');
        $floor = $this->minTurnover >= 0 ? $this->minTurnover : null;

        $opts = [
            'minConfidence'   => $this->minConfidence,
            'minPrimaryMatch' => $this->minPrimary,
            'minChange24h'    => $this->minChange24h,
            'maxTraps'        => $this->maxTraps,
            'trendGate'       => $this->trendGate,
        ];

        $fromMs = strtotime($from . ' 00:00:00 UTC') * 1000;
        $toMs   = (strtotime($to . ' 00:00:00 UTC') + 86_400) * 1000;

        $this->stdout(sprintf(
            "[grader-backtest] window %s..%s  step=%s  gates: conf>=%d prim>=%d chg>=%.1f%% traps<=%d trend=%s  dedup=%.0fh  floor=%s\n",
            $from, $to, $step, $this->minConfidence, $this->minPrimary, $this->minChange24h,
            $this->maxTraps, $this->trendGate, $this->dedupHours,
            $floor !== null ? '$' . number_format($floor) : 'config',
        ), Console::FG_GREEN);
        $this->stdout(sprintf("  %d symbol(s) to scan\n", count($symbols)));

        // records: ['side'=>, 'r'=>, 'conf'=>].
        $records = [];
        $scanned = 0;
        $fired = 0;
        $deduped = 0;

        foreach ($symbols as $symbol) {
            $candles = $md->dailyCandles($symbol);
            if (!$candles) {
                continue;
            }
            $features = Features::computeDaily($candles);
            if ($universe->rejectReason($symbol, $features, $floor) !== null) {
                continue;
            }
            $hourly = $md->hourlyCandles($symbol);
            if (count($hourly) < 50) {
                continue;   // the grader is intraday — no 1h history, no signal
            }
            $scanned++;
            $priceOnly = $universe->isMemecoinPriceOnly($symbol);

            $fundingByDay = [];
            if (!$priceOnly && $md->hasDerivatives($symbol)) {
                $fundingByDay = Features::fundingDaily($md->fundingRaw($symbol), $fundingMode);
            }

            // Decision points: (closeTs, price, day).
            $points = [];
            if ($step === '1d') {
                foreach ($features as $f) {
                    $ts = (int)$f['ts'];
                    if ($ts < $fromMs || $ts > $toMs) {
                        continue;
                    }
                    $points[] = [$ts + 86_400_000 - 1, (float)$f['c'], $f['day']];
                }
            } else {
                foreach ($md->fourHourCandles($symbol) as $b) {
                    $ts = (int)$b['ts'];
                    if ($ts < $fromMs || $ts > $toMs) {
                        continue;
                    }
                    $points[] = [$ts + 14_400_000 - 1, (float)$b['c'], gmdate('Y-m-d', intdiv($ts, 1000))];
                }
            }

            $lastFire = ['long' => PHP_INT_MIN, 'short' => PHP_INT_MIN];
            foreach ($points as [$closeTs, $price, $day]) {
                $ch = self::pctChange24h($hourly, $closeTs);
                $row = CoinMetrics::build($md, $symbol, $closeTs, $price, $ch, $fundingByDay[$day] ?? null);
                $g = ConfluenceGrader::grade($row, $opts);
                if ($g === null || !$g['fired']) {
                    continue;
                }
                $side = $g['side'];
                if ($closeTs - $lastFire[$side] < $dedupMs) {
                    $deduped++;
                    continue;
                }
                $sim = self::simulateGrader($hourly, $closeTs, (float)$g['entry'], (float)$g['stop'], (float)$g['tp1'], $side, $maxBars1h);
                if ($sim === null) {
                    continue;
                }
                $lastFire[$side] = $closeTs;
                $fired++;
                $records[] = ['side' => $side, 'r' => $sim['R'], 'conf' => (int)$g['confidence']];
            }
            $md->forgetSymbol();
        }

        $this->stdout(sprintf("  scanned %d symbol(s); %d signals fired (%d deduped)\n\n", $scanned, $fired, $deduped));
        $this->printGraderReport($records);

        return ExitCode::OK;
    }

    /** 24h % change as of $ts from the 1h series (current close vs ~24 bars prior). */
    private static function pctChange24h(array $hourly, int $ts): ?float
    {
        $n = count($hourly);
        if ($n === 0) {
            return null;
        }
        // upper_bound: first index with ts > $ts.
        $lo = 0;
        $hi = $n;
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ((int)$hourly[$mid]['ts'] <= $ts) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        if ($lo < 25) {
            return null;
        }
        $cur = (float)$hourly[$lo - 1]['c'];
        $prev = (float)$hourly[$lo - 25]['c'];
        if ($prev <= 0.0) {
            return null;
        }
        return ($cur / $prev - 1.0) * 100.0;
    }

    /**
     * Walk the trade forward on 1h bars from the bar after $entryTs. First touch
     * of stop (R=-1) or TP1 (R=+rr1) wins; stop is checked before TP within a
     * bar (conservative). Else exit at the T+N close. Returns ['R'=>float]|null.
     *
     * @param list<array<string,float>> $hourly
     */
    private static function simulateGrader(array $hourly, int $entryTs, float $entry, float $stop, float $tp, string $side, int $maxBars1h): ?array
    {
        $n = count($hourly);
        // first 1h bar strictly after the decision ts.
        $lo = 0;
        $hi = $n;
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ((int)$hourly[$mid]['ts'] <= $entryTs) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        $start = $lo;
        if ($start >= $n) {
            return null;
        }
        $risk = abs($entry - $stop);
        if ($risk <= 0.0) {
            return null;
        }
        $end = min($start + $maxBars1h, $n - 1);
        if ($end <= $start) {
            return null;
        }
        for ($k = $start; $k <= $end; $k++) {
            $h = (float)$hourly[$k]['h'];
            $l = (float)$hourly[$k]['l'];
            if ($side === 'long') {
                if ($l <= $stop) {
                    return ['R' => -1.0];
                }
                if ($h >= $tp) {
                    return ['R' => ($tp - $entry) / $risk];
                }
            } else {
                if ($h >= $stop) {
                    return ['R' => -1.0];
                }
                if ($l <= $tp) {
                    return ['R' => ($entry - $tp) / $risk];
                }
            }
        }
        $exit = (float)$hourly[$end]['c'];
        $r = $side === 'long' ? ($exit - $entry) / $risk : ($entry - $exit) / $risk;
        return ['R' => $r];
    }

    /**
     * @param list<array{side:string,r:float,conf:int}> $records
     */
    private function printGraderReport(array $records): void
    {
        if (!$records) {
            $this->stdout("  no signals fired in window\n");
            return;
        }

        $agg = function (array $recs): array {
            $n = count($recs);
            $wins = 0;
            $sum = 0.0;
            $pos = 0.0;
            $neg = 0.0;
            foreach ($recs as $r) {
                $sum += $r['r'];
                if ($r['r'] > 0) {
                    $wins++;
                    $pos += $r['r'];
                } else {
                    $neg += $r['r'];
                }
            }
            return [
                'n' => $n,
                'win' => $n ? 100.0 * $wins / $n : 0.0,
                'exp' => $n ? $sum / $n : 0.0,
                'pf' => $neg < 0 ? $pos / -$neg : INF,
            ];
        };
        $print = function (string $label, array $recs) use ($agg): void {
            if (!$recs) {
                return;
            }
            $a = $agg($recs);
            $pf = is_finite($a['pf']) ? sprintf('%6.2f', $a['pf']) : '   inf';
            $color = $a['exp'] > 0 ? Console::FG_GREEN : Console::FG_RED;
            $this->stdout(sprintf("  %-8s %6d %7.1f%% %+8.3f %s\n", $label, $a['n'], $a['win'], $a['exp'], $pf), $color);
        };

        $this->stdout(sprintf("  %-8s %6s %8s %9s %6s\n", 'GROUP', 'N', 'WIN%', 'EXP_R', 'PF'), Console::BOLD);
        $this->stdout("  " . str_repeat('-', 41) . "\n");
        $print('long', array_values(array_filter($records, fn($r) => $r['side'] === 'long')));
        $print('short', array_values(array_filter($records, fn($r) => $r['side'] === 'short')));
        $print('ALL', $records);

        $this->stdout("\n  By confidence bucket (all sides):\n", Console::BOLD);
        $buckets = [
            '<40'   => fn($c) => $c < 40,
            '40-49' => fn($c) => $c >= 40 && $c < 50,
            '50-59' => fn($c) => $c >= 50 && $c < 60,
            '60-69' => fn($c) => $c >= 60 && $c < 70,
            '70-79' => fn($c) => $c >= 70 && $c < 80,
            '80+'   => fn($c) => $c >= 80,
        ];
        foreach ($buckets as $label => $test) {
            $print($label, array_values(array_filter($records, fn($r) => $test($r['conf']))));
        }

        $this->stdout("\n  Read: ALL EXP_R > 0 with PF > ~1.3 and a usable trade count = the standalone\n");
        $this->stdout("  grader has edge on this data — worth building a live scan path. Compare to the\n");
        $this->stdout("  rsi-module claim of +0.60R / 55% win / PF 2.36.\n");
    }

    /**
     * Intraday A/B for the multi-timeframe setups (S6 4h blow-off short, L5
     * dip-buy) on the 4h cadence — the path the daily backtest can never reach,
     * because S6 keys off the latest completed 4h bar and only the 20:00–00:00
     * bar survives to a daily close.
     *
     *   ./yii trading-backtest/s6 --from=2024-01-01
     *   ./yii trading-backtest/s6 --minTurnover=250000     # widen to thin alts
     *
     * Replays every completed 4h bar through the real MtfContext::computeAt +
     * SetupEvaluator::evaluateMtf + TrapGate, simulating each fire on the 4h
     * series to a hard stop or a T+N (× 6 bars/day) time-exit.
     */
    public function actionS6(): int
    {
        $cfg = Yii::$app->params['trading'] ?? [];
        $from = $this->from !== '' ? $this->from : gmdate('Y-m-d', strtotime('-365 days'));
        $to   = $this->to !== ''   ? $this->to   : gmdate('Y-m-d', strtotime('-1 day'));
        $timeExitDays = $this->timeExitDays > 0 ? $this->timeExitDays : (int)($cfg['timeExitDays'] ?? 20);
        $maxBars = $timeExitDays * 6;   // six 4h bars per day

        $gateCfg = $cfg;
        $gateCfg['requireTurnConfirmation'] = true;
        if ($this->parabolicMovePct >= 0) {
            $gateCfg['parabolicMovePct'] = $this->parabolicMovePct;
        }
        if ($this->slopeEps >= 0) {
            $gateCfg['turnSlopeEps'] = $this->slopeEps;
        }
        $gatedSet = $gateCfg['turnConfirmSetups'] ?? TrapGate::DEFAULT_SETUPS;
        if ($this->gatedSetups !== '') {
            $gatedSet = array_map('trim', explode(',', strtoupper($this->gatedSetups)));
            $gateCfg['turnConfirmSetups'] = $gatedSet;
        }

        // Default to the relaxed S6 universe floor (the live intraday scan trades
        // S6 on thinner alts than the $5M daily gate).
        $floor = $this->minTurnover >= 0
            ? $this->minTurnover
            : (float)($cfg['s6MinTurnover20dUsd'] ?? 1_000_000);

        $md        = new MarketData();
        $universe  = new Universe($cfg);
        $evaluator = new SetupEvaluator();
        $symbols = $this->symbols !== ''
            ? array_map('trim', explode(',', strtoupper($this->symbols)))
            : $md->listSymbols();

        $fromMs = strtotime($from . ' 00:00:00 UTC') * 1000;
        $toMs   = (strtotime($to . ' 00:00:00 UTC') + 86_400) * 1000;
        $h4ms   = 4 * 3600 * 1000;

        $this->stdout(sprintf(
            "[s6-backtest] 4h cadence %s..%s  T+%d (%d bars)  parabolic=%.3f  slopeEps=%.2f  floor=\$%s\n",
            $from, $to, $timeExitDays, $maxBars, $gateCfg['parabolicMovePct'] ?? 0.12,
            $gateCfg['turnSlopeEps'] ?? 0.0, number_format($floor),
        ), Console::FG_GREEN);
        $this->stdout(sprintf("  %d symbol(s) to scan\n", count($symbols)));

        $stats = [];
        $tally = static function (string $setup, string $arm, float $r) use (&$stats): void {
            $stats[$setup][$arm] ??= ['n' => 0, 'wins' => 0, 'sumR' => 0.0];
            $stats[$setup][$arm]['n']++;
            $stats[$setup][$arm]['sumR'] += $r;
            if ($r > 0) {
                $stats[$setup][$arm]['wins']++;
            }
        };

        $scanned = 0;
        $fills = 0;
        foreach ($symbols as $symbol) {
            $candles = $md->dailyCandles($symbol);
            if (!$candles) {
                continue;
            }
            $features = Features::computeDaily($candles);
            if ($universe->rejectReason($symbol, $features, $floor) !== null) {
                continue;
            }
            $h4 = $md->fourHourCandles($symbol);
            $m  = count($h4);
            if ($m < 20) {
                continue;
            }
            $scanned++;

            for ($j = 14; $j < $m - 1; $j++) {
                $closeTs = (int)$h4[$j]['ts'] + $h4ms;
                if ($closeTs < $fromMs || $closeTs > $toMs) {
                    continue;
                }
                $mtf = MtfContext::computeAt($md, $symbol, $closeTs);
                if ($mtf === null) {
                    continue;
                }
                foreach ($evaluator->evaluateMtf($mtf, (float)$mtf['ref_close']) as $fire) {
                    $setup = $fire['setup'];
                    $sim = $this->simulate4h($h4, $j, $fire, $maxBars);
                    if ($sim === null) {
                        continue;
                    }
                    $fills++;
                    $r = $sim['R'];
                    $vetoed = in_array($setup, $gatedSet, true)
                        && TrapGate::veto($fire, $mtf, null, $gateCfg) !== null;
                    $tally($setup, 'off', $r);
                    $tally($setup, $vetoed ? 'vetoed' : 'kept', $r);
                }
            }
            $md->forgetSymbol();
        }

        $this->stdout(sprintf("  scanned %d symbol(s), %d filled trade(s)\n\n", $scanned, $fills));
        $this->printReport($stats, $gatedSet);

        return ExitCode::OK;
    }

    /**
     * 4h-series trade simulation — the intraday analogue of simulate(): fill on
     * the next 4h bar (or a limit touch within window_h/4 bars), then exit on
     * the first hard-stop touch or the T+N close ($maxBars four-hour bars).
     *
     * @param list<array<string,mixed>> $bars 4h OHLC candles
     * @return array{R:float}|null
     */
    private function simulate4h(array $bars, int $j, array $fire, int $maxBars): ?array
    {
        $n   = count($bars);
        $dir = $fire['dir'];
        $stop = (float)$fire['stop_px'];
        $entryPx = $fire['entry_px'];
        $windowBars = max(1, (int)ceil(((int)$fire['window_h']) / 4));

        if ($entryPx === null) {
            $fillIdx = $j + 1;
            if ($fillIdx >= $n) {
                return null;
            }
            $entry = (float)$bars[$fillIdx]['o'];
        } else {
            $entryPx = (float)$entryPx;
            $fillIdx = -1;
            $last = min($j + $windowBars, $n - 1);
            for ($k = $j + 1; $k <= $last; $k++) {
                if ((float)$bars[$k]['h'] >= $entryPx) {
                    $fillIdx = $k;
                    break;
                }
            }
            if ($fillIdx === -1) {
                return null;
            }
            $entry = $entryPx;
        }

        $risk = abs($entry - $stop);
        if ($risk <= 0.0) {
            return null;
        }
        $exitMax = min($fillIdx + $maxBars, $n - 1);
        if ($exitMax <= $fillIdx) {
            return null;
        }
        for ($k = $fillIdx + 1; $k <= $exitMax; $k++) {
            if ($dir === 'long' && (float)$bars[$k]['l'] <= $stop) {
                return ['R' => ($stop - $entry) / $risk];
            }
            if ($dir === 'short' && (float)$bars[$k]['h'] >= $stop) {
                return ['R' => ($entry - $stop) / $risk];
            }
        }
        $exitPx = (float)$bars[$exitMax]['c'];
        $r = $dir === 'long' ? ($exitPx - $entry) / $risk : ($entry - $exitPx) / $risk;
        return ['R' => $r];
    }

    /** Macro context for one day — mirror of SignalEngine::buildContext. */
    private function buildContext(MarketData $md, array $fundingByDay, array $oiByDay, string $day): array
    {
        $fng = $md->fng();
        $dom = $md->dominance();
        return [
            'funding'         => $fundingByDay[$day] ?? null,
            'funding_7d'      => $fundingByDay ? Features::fundingPriorMean($fundingByDay, $day, 7) : null,
            'oi_z'            => $oiByDay ? Features::oiZscore($oiByDay, $day, 20) : null,
            'fng'             => isset($fng[$day]) ? (int)$fng[$day]['v'] : null,
            'btc_d'           => isset($dom[$day]) ? (float)$dom[$day]['btc_d'] : null,
            'btc_d_30d_delta' => $md->btcDelta30d($day),
        ];
    }

    /**
     * Simulate one fire on daily bars: resolve the entry fill, then exit on the
     * first hard-stop touch or the T+N close. Returns ['R'=>float] or null when
     * the order never fills or there is no room to evaluate an exit.
     *
     * @param list<array<string,mixed>> $features
     * @return array{R:float}|null
     */
    private function simulate(array $features, int $i, array $fire, int $timeExitDays): ?array
    {
        $n   = count($features);
        $dir = $fire['dir'];
        $stop = (float)$fire['stop_px'];
        $entryPx = $fire['entry_px'];                 // null = market-open
        $windowDays = max(1, (int)ceil(((int)$fire['window_h']) / 24));

        // ---- entry fill ----
        if ($entryPx === null) {
            $fillIdx = $i + 1;                        // next-bar open
            $entry = (float)$features[$fillIdx]['o'];
        } else {
            $entryPx = (float)$entryPx;
            $fillIdx = -1;
            $last = min($i + $windowDays, $n - 1);
            for ($k = $i + 1; $k <= $last; $k++) {
                // Both buy-stop (long) and sell-limit (short) levels fill when
                // price trades up to the level (the bar's high reaches it).
                if ((float)$features[$k]['h'] >= $entryPx) {
                    $fillIdx = $k;
                    break;
                }
            }
            if ($fillIdx === -1) {
                return null;                          // never filled inside the window
            }
            $entry = $entryPx;
        }

        $risk = abs($entry - $stop);
        if ($risk <= 0.0) {
            return null;
        }
        $exitMax = min($fillIdx + $timeExitDays, $n - 1);
        if ($exitMax <= $fillIdx) {
            return null;                              // no forward bars to evaluate
        }

        // ---- exit: first hard-stop touch (checked from the bar after fill) ----
        for ($k = $fillIdx + 1; $k <= $exitMax; $k++) {
            if ($dir === 'long' && (float)$features[$k]['l'] <= $stop) {
                return ['R' => ($stop - $entry) / $risk];
            }
            if ($dir === 'short' && (float)$features[$k]['h'] >= $stop) {
                return ['R' => ($entry - $stop) / $risk];
            }
        }

        // ---- time exit at T+N close ----
        $exitPx = (float)$features[$exitMax]['c'];
        $r = $dir === 'long' ? ($exitPx - $entry) / $risk : ($entry - $exitPx) / $risk;
        return ['R' => $r];
    }

    /**
     * @param array<string, array<string, array{n:int,wins:int,sumR:float}>> $stats
     * @param list<string>|null $gatedSet null = show every setup's arms
     */
    private function printReport(array $stats, ?array $gatedSet): void
    {
        ksort($stats);
        $this->stdout(sprintf("  %-6s %-8s %6s %8s %9s\n", 'SETUP', 'ARM', 'N', 'WIN%', 'EXP_R'), Console::BOLD);
        $this->stdout("  " . str_repeat('-', 41) . "\n");

        $totals = ['off' => ['n' => 0, 'wins' => 0, 'sumR' => 0.0],
                   'kept' => ['n' => 0, 'wins' => 0, 'sumR' => 0.0],
                   'vetoed' => ['n' => 0, 'wins' => 0, 'sumR' => 0.0]];

        foreach ($stats as $setup => $arms) {
            $isGated = $gatedSet === null || in_array($setup, $gatedSet, true);
            foreach (['off', 'kept', 'vetoed'] as $arm) {
                $a = $arms[$arm] ?? null;
                if ($a === null || $a['n'] === 0) {
                    if ($arm !== 'off' && !$isGated) {
                        continue;   // non-gated setups: only the 'off' arm is meaningful
                    }
                    if ($a === null) {
                        continue;
                    }
                }
                $color = match ($arm) {
                    'kept'   => Console::FG_GREEN,
                    'vetoed' => Console::FG_RED,
                    default  => Console::FG_GREY,
                };
                $this->stdout('  ' . $this->fmtRow($setup, $arm, $a), $color);
                if ($isGated) {
                    $totals[$arm]['n'] += $a['n'];
                    $totals[$arm]['wins'] += $a['wins'];
                    $totals[$arm]['sumR'] += $a['sumR'];
                }
            }
            $this->stdout("\n");
        }

        $this->stdout("  " . str_repeat('-', 41) . "\n");
        $this->stdout("  TOTAL across gated setups:\n", Console::BOLD);
        foreach (['off', 'kept', 'vetoed'] as $arm) {
            if ($totals[$arm]['n'] === 0) {
                continue;
            }
            $color = match ($arm) {
                'kept'   => Console::FG_GREEN,
                'vetoed' => Console::FG_RED,
                default  => Console::FG_GREY,
            };
            $this->stdout('  ' . $this->fmtRow('ALL', $arm, $totals[$arm]), $color);
        }
        $this->stdout("\n  Read: if 'vetoed' EXP_R << 'off' EXP_R, the gate removes losers — enable it.\n");
    }

    /** @param array{n:int,wins:int,sumR:float} $a */
    private function fmtRow(string $setup, string $arm, array $a): string
    {
        $win = $a['n'] > 0 ? 100.0 * $a['wins'] / $a['n'] : 0.0;
        $exp = $a['n'] > 0 ? $a['sumR'] / $a['n'] : 0.0;
        return sprintf("%-6s %-8s %6d %7.1f%% %+9.3f\n", $setup, $arm, $a['n'], $win, $exp);
    }
}
