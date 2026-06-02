<?php

declare(strict_types=1);

namespace common\services\Trading;

/**
 * Confluence grading engine — a faithful port of rsi-module's SignalService
 * V1 model (computeBias + evalPrimary* + buildLevels + detectTraps). Given a
 * CoinMetrics row it produces a weighted multi-axis bias, a 0-95 confidence
 * capped by how many of 5 "primary" structures align, and trade levels.
 *
 * Two ways to use it:
 *   - grade($row)            — the original standalone reversal signal (side +
 *                              confidence + entry/stop/TP + fire gates).
 *   - scoreFire($dir, $row)  — the SCORER: how strongly does the confluence
 *                              model AGREE with a setup fire of direction $dir?
 *                              Returns confidence/primaries only when the
 *                              model's own side matches $dir, else a 0 (it
 *                              disagrees). This is what the L1–S6 overlay uses.
 *
 * Subset note (see merge scope): timeframes are 1h/4h/1d — the live engine has
 * no 15m/30m. The weighted-RSI score auto-normalises over present TFs, so the
 * relative TF weighting is preserved. The fire-gate DEFAULT_* constants were
 * tuned in rsi-module for the model's OWN counter-trend fires; when used as a
 * scorer over L1–S6 they are a starting point to be re-validated by backtest.
 */
final class ConfluenceGrader
{
    private const TIMEFRAMES = ['1h', '4h', '1d'];
    private const TF_ORDER   = ['1h', '4h', '1d'];

    private const BIAS_TF_WEIGHTS = [
        '1h' => 1.0,
        '4h' => 1.6,
        '1d' => 2.4,
    ];

    private const PRIMARY_CAPS = [30, 50, 70, 82, 90, 95];

    public const DEFAULT_MIN_CONFIDENCE = 70;
    public const DEFAULT_MIN_PRIMARY    = 2;
    public const DEFAULT_MIN_CHANGE24H  = 7.0;
    public const DEFAULT_MAX_TRAPS      = 1;
    public const DEFAULT_TREND_GATE     = 'either'; // none|ema|ms|either|both

    // ---------------------------------------------------------------
    // SCORER — agreement of the confluence model with a setup fire
    // ---------------------------------------------------------------

    /**
     * Score how strongly the confluence model supports a setup fire whose
     * direction is fixed ($dir = 'long'|'short'). The model independently
     * computes its own bias side; if that side matches $dir the fire inherits
     * the model's confidence + primary count, otherwise it scores 0 (the
     * confluence disagrees with the setup).
     *
     * @return array{agree:bool, confidence:int, primaries:int, modelSide:string, rawConfidence:int}
     */
    public static function scoreFire(string $dir, array $row): array
    {
        $bias = self::computeBias($row);
        $agree = ($bias['side'] === $dir);
        return [
            'agree'         => $agree,
            'confidence'    => $agree ? $bias['confidence'] : 0,
            'primaries'     => $agree ? $bias['primaryMatchCount'] : 0,
            'modelSide'     => $bias['side'],
            'rawConfidence' => $bias['confidence'],
        ];
    }

    // ---------------------------------------------------------------
    // grade — the standalone V1 signal (kept for future live wiring + tests)
    // ---------------------------------------------------------------

    public static function grade(array $row, array $opts = []): ?array
    {
        $minConf    = (int)  ($opts['minConfidence']   ?? self::DEFAULT_MIN_CONFIDENCE);
        $minPrimary = (int)  ($opts['minPrimaryMatch'] ?? self::DEFAULT_MIN_PRIMARY);
        $minChange  = (float)($opts['minChange24h']    ?? self::DEFAULT_MIN_CHANGE24H);
        $maxTraps   = (int)  ($opts['maxTraps']        ?? self::DEFAULT_MAX_TRAPS);
        $trendGate  = (string)($opts['trendGate']      ?? self::DEFAULT_TREND_GATE);

        $bias = self::computeBias($row);
        if ($bias['side'] === 'wait' && $bias['confidence'] === 0 && !$bias['used']) {
            return null;
        }
        $side   = $bias['side'];
        $levels = self::buildLevels($row, $side);
        $traps  = self::detectTraps($row, $bias);

        $gates = [];
        $isDirectional = ($side === 'long' || $side === 'short');
        if (!$isDirectional) {
            $gates[] = 'no directional bias';
        } else {
            if ($bias['confidence'] < $minConf) {
                $gates[] = "confidence {$bias['confidence']} < {$minConf}";
            }
            if ($bias['primaryMatchCount'] < $minPrimary) {
                $gates[] = "only {$bias['primaryMatchCount']}/5 primaries (need {$minPrimary})";
            }
            $ch = $row['change24h'] ?? null;
            if (!is_numeric($ch) || abs($ch) < $minChange) {
                $gates[] = '24h move too small';
            }
            if (count($traps) > $maxTraps) {
                $gates[] = count($traps) . ' trap signals (max ' . $maxTraps . ')';
            }
            if (!self::trendAligned($row, $side, $trendGate)) {
                $gates[] = 'no trend confirmation';
            }
            if ($levels === null) {
                $gates[] = 'cannot size a trade — missing swing/ATR levels';
            }
        }
        $fired = empty($gates);

        return [
            'symbol'            => $row['symbol'] ?? '?',
            'side'              => $side,
            'confidence'        => $bias['confidence'],
            'score'             => $bias['score'],
            'primaryMatchCount' => $bias['primaryMatchCount'],
            'price'             => $row['price'] ?? null,
            'change24h'         => $row['change24h'] ?? null,
            'entry'             => $levels['entry'] ?? null,
            'stop'              => $levels['stop'] ?? null,
            'tp1'               => $levels['tp1'] ?? null,
            'tp2'               => $levels['tp2'] ?? null,
            'rr1'               => $levels['rr1'] ?? null,
            'rr2'               => $levels['rr2'] ?? null,
            'traps'             => $traps,
            'gates'             => $gates,
            'fired'             => $fired,
            'bias'              => $bias,
        ];
    }

    private static function trendAligned(array $row, string $side, string $gate): bool
    {
        if ($gate === 'none' || $gate === '') {
            return true;
        }
        $want = $side === 'long' ? 'up' : 'down';
        $tech = $row['technical'] ?? [];
        $ema  = $tech['ema']['direction'] ?? null;
        $ms   = $tech['marketStructure']['direction'] ?? null;
        switch ($gate) {
            case 'ema':  return $ema === $want;
            case 'ms':   return $ms === $want;
            case 'both': return $ema === $want && $ms === $want;
            case 'either':
            default:     return $ema === $want || $ms === $want;
        }
    }

    // ---------------------------------------------------------------
    // Trap detection
    // ---------------------------------------------------------------

    private static function detectTraps(array $row, array $bias): array
    {
        $side = $bias['side'];
        if ($side !== 'long' && $side !== 'short') {
            return [];
        }
        $traps = [];
        if (($bias['slopeOK'] ?? null) === false) {
            $traps[] = $side === 'long'
                ? 'RSI still falling — no upward turn confirmed yet'
                : 'RSI still rising — no downward turn confirmed yet';
        }
        $w = $row['wave'] ?? null;
        if (is_array($w) && ($w['phase'] ?? null) === 'impulse') {
            if ($side === 'long' && ($w['direction'] ?? null) === 'down') {
                $traps[] = 'price in a fresh downtrend impulse — likely to extend lower';
            }
            if ($side === 'short' && ($w['direction'] ?? null) === 'up') {
                $traps[] = 'price in a fresh uptrend impulse — likely to extend higher';
            }
        }
        $ch = $row['change24h'] ?? null;
        if (is_numeric($ch) && ($bias['slopeOK'] ?? null) !== true) {
            if ($side === 'short' && $ch > 12) {
                $traps[] = 'parabolic +' . round($ch, 1) . '% 24h move, reversal not started';
            }
            if ($side === 'long' && $ch < -12) {
                $traps[] = 'capitulation ' . round($ch, 1) . '% 24h move, reversal not started';
            }
        }
        return $traps;
    }

    // ---------------------------------------------------------------
    // Volatility-regime bands
    // ---------------------------------------------------------------

    private static function volRegimeBands($volStd): array
    {
        if (!is_numeric($volStd) || $volStd <= 0) {
            return ['os' => 30, 'ob' => 70, 'xLow' => 20, 'xHigh' => 80];
        }
        if ($volStd >= 0.025) {
            return ['os' => 22, 'ob' => 78, 'xLow' => 15, 'xHigh' => 85];
        }
        if ($volStd >= 0.012) {
            return ['os' => 28, 'ob' => 72, 'xLow' => 20, 'xHigh' => 80];
        }
        return ['os' => 35, 'ob' => 65, 'xLow' => 25, 'xHigh' => 75];
    }

    // ---------------------------------------------------------------
    // PRIMARY indicators
    // ---------------------------------------------------------------

    private static function evalPrimaryWave(array $row, string $side): array
    {
        $w = $row['wave'] ?? null;
        if (!is_array($w) || empty($w['direction']) || empty($w['phase'])) {
            return ['match' => false, 'score' => 0, 'label' => 'WAVE', 'detail' => 'no_data'];
        }
        $dir = $w['direction'];
        $ph  = $w['phase'];
        if ($side === 'long') {
            if ($dir === 'up' && $ph === 'pullback') return ['match' => true, 'score' => 95, 'label' => 'WAVE', 'detail' => 'uptrend pullback'];
            if ($dir === 'up' && $ph === 'impulse')  return ['match' => true, 'score' => 60, 'label' => 'WAVE', 'detail' => 'uptrend impulse'];
            if ($dir === 'down')                     return ['match' => false, 'score' => 0, 'label' => 'WAVE', 'detail' => 'against'];
            return ['match' => false, 'score' => 25, 'label' => 'WAVE', 'detail' => 'range'];
        }
        if ($side === 'short') {
            if ($dir === 'down' && $ph === 'pullback') return ['match' => true, 'score' => 95, 'label' => 'WAVE', 'detail' => 'downtrend rally'];
            if ($dir === 'down' && $ph === 'impulse')  return ['match' => true, 'score' => 60, 'label' => 'WAVE', 'detail' => 'downtrend impulse'];
            if ($dir === 'up')                         return ['match' => false, 'score' => 0, 'label' => 'WAVE', 'detail' => 'against'];
            return ['match' => false, 'score' => 25, 'label' => 'WAVE', 'detail' => 'range'];
        }
        return ['match' => false, 'score' => 0, 'label' => 'WAVE', 'detail' => 'no side'];
    }

    private static function evalPrimaryPinbar(array $row, string $side): array
    {
        $p    = $row['pinbar'] ?? [];
        $want = $side === 'long' ? 'bullish' : 'bearish';
        $scoreByTf = ['1d' => 100, '4h' => 85, '1h' => 55];
        $bestMatch = null;
        $opposite  = null;
        foreach (['1d', '4h', '1h'] as $tf) {
            $v = $p[$tf] ?? null;
            if (!$v) {
                continue;
            }
            if ($v === $want && !$bestMatch) {
                $bestMatch = ['tf' => $tf, 'score' => $scoreByTf[$tf]];
            } elseif ($v && $v !== $want && !$opposite) {
                $opposite = ['tf' => $tf];
            }
        }
        if ($bestMatch) {
            return ['match' => true, 'score' => $bestMatch['score'], 'label' => 'PIN', 'detail' => "{$want} on {$bestMatch['tf']}"];
        }
        if ($opposite) {
            return ['match' => false, 'score' => 0, 'label' => 'PIN', 'detail' => "opposite on {$opposite['tf']}"];
        }
        return ['match' => false, 'score' => 0, 'label' => 'PIN', 'detail' => 'no pinbar'];
    }

    private static function evalPrimaryRsi(array $row, string $side, array $bands): array
    {
        $alignedExt = 0;
        $alignedSoft = 0;
        $total = 0;
        foreach (self::TIMEFRAMES as $tf) {
            $v = $row['rsi'][$tf] ?? null;
            if (!is_numeric($v)) {
                continue;
            }
            $total++;
            if ($side === 'long') {
                if ($v <= $bands['xLow']) $alignedExt++;
                elseif ($v <= $bands['os']) $alignedSoft++;
            } elseif ($side === 'short') {
                if ($v >= $bands['xHigh']) $alignedExt++;
                elseif ($v >= $bands['ob']) $alignedSoft++;
            }
        }
        if ($total === 0) {
            return ['match' => false, 'score' => 0, 'label' => 'RSI', 'detail' => 'no data'];
        }
        $score = (int) min(100, round((($alignedExt * 2 + $alignedSoft) / ($total * 2)) * 100));
        $detail = $alignedExt
            ? "{$alignedExt} extreme + {$alignedSoft} " . ($side === 'long' ? 'OS' : 'OB') . " of {$total}"
            : "{$alignedSoft} " . ($side === 'long' ? 'OS' : 'OB') . " of {$total} TFs";
        return ['match' => $score >= 50, 'score' => $score, 'label' => 'RSI', 'detail' => $detail];
    }

    private static function evalPrimarySRConfluence(array $row, string $side): array
    {
        $tech = $row['technical'] ?? null;
        $px   = $row['price'] ?? null;
        $atr  = $row['levels']['atr'] ?? null;
        if (!is_array($tech) || !is_numeric($px) || !is_numeric($atr) || $atr <= 0) {
            return ['match' => false, 'score' => 0, 'label' => 'SR', 'detail' => 'no_data'];
        }
        $onSide = fn($lvlPrice) => $side === 'long' ? $lvlPrice <= $px : $lvlPrice >= $px;

        $zone = null;
        $zoneDist = INF;
        foreach (($tech['confluence'] ?? []) as $z) {
            if (!$onSide($z['price'])) {
                continue;
            }
            $d = abs($z['price'] - $px) / $atr;
            if ($d < $zoneDist) {
                $zoneDist = $d;
                $zone = $z;
            }
        }
        $single = null;
        $singleDist = INF;
        foreach (($tech['all'] ?? []) as $l) {
            if (!$onSide($l['price'])) {
                continue;
            }
            if (($l['strength'] ?? 0) < 70) {
                continue;
            }
            $d = abs($l['price'] - $px) / $atr;
            if ($d < $singleDist) {
                $singleDist = $d;
                $single = $l;
            }
        }
        if ($zone && $zoneDist <= 3.0) {
            if ($zoneDist <= 0.5) $score = 100;
            elseif ($zoneDist <= 1.5) $score = 82;
            else $score = 55;
            if (($zone['count'] ?? 0) >= 3) {
                $score = min(100, $score + 8);
            }
            $types = str_replace('_', '.', implode('+', array_slice($zone['types'] ?? [], 0, 3)));
            return ['match' => $score >= 50, 'score' => $score, 'label' => 'SR', 'detail' => "{$zone['count']}-level zone (" . $types . ') — ' . round($zoneDist, 1) . ' ATR'];
        }
        if ($single && $singleDist <= 1.5) {
            $score = $singleDist <= 0.5 ? 75 : 55;
            return ['match' => $score >= 50, 'score' => $score, 'label' => 'SR', 'detail' => 'single ' . str_replace('_', '.', $single['type'] ?? 'level') . ' — ' . round($singleDist, 1) . ' ATR'];
        }
        return ['match' => false, 'score' => 0, 'label' => 'SR', 'detail' => 'no qualifying S/R on side'];
    }

    private static function evalPrimaryTrendline(array $row, string $side): array
    {
        $tl = $row['trendline'] ?? null;
        if (!is_array($tl)) {
            return ['match' => false, 'score' => 0, 'label' => 'TL', 'detail' => 'no_data'];
        }
        $t = $side === 'long' ? ($tl['up'] ?? null) : ($tl['down'] ?? null);
        if (!is_array($t)) {
            return ['match' => false, 'score' => 0, 'label' => 'TL', 'detail' => 'no line'];
        }
        $dist = $t['distanceAtr'] ?? null;
        if (!is_numeric($dist)) {
            return ['match' => false, 'score' => 30, 'label' => 'TL', 'detail' => 'line exists, no ATR'];
        }
        $expected = $side === 'long' ? 1 : -1;
        $sign = $dist <=> 0;
        if ($sign !== $expected && abs($dist) > 0.5) {
            return ['match' => false, 'score' => 0, 'label' => 'TL', 'detail' => 'broken'];
        }
        $abs = abs($dist);
        if ($abs <= 0.5) { $score = 100; $detail = 'touching line'; }
        elseif ($abs <= 1.5) { $score = 80; $detail = 'within 1.5 ATR'; }
        elseif ($abs <= 3.0) { $score = 55; $detail = 'within 3 ATR'; }
        else { $score = 30; $detail = 'extended'; }
        return ['match' => $score >= 50, 'score' => $score, 'label' => 'TL', 'detail' => $detail];
    }

    // ---------------------------------------------------------------
    // computeBias — the seven-axis confluence grader
    // ---------------------------------------------------------------

    public static function computeBias(array $row): array
    {
        $bands = self::volRegimeBands($row['volStd'] ?? null);

        $weightedSum = 0.0;
        $totalWeight = 0.0;
        $longTfs = 0;
        $shortTfs = 0;
        $extremeAligned = 0;
        $used = [];

        foreach (self::TIMEFRAMES as $tf) {
            $v = $row['rsi'][$tf] ?? null;
            if (!is_numeric($v)) {
                continue;
            }
            $w = self::BIAS_TF_WEIGHTS[$tf] ?? 1.0;
            $dist = abs(50 - $v) / 50;
            $dir  = $v < 50 ? 1 : ($v > 50 ? -1 : 0);
            $strength = pow($dist, 1.4);

            $sp = $row['spread'][$tf] ?? null;
            if (is_numeric($sp) && $sp > 0) {
                $penalty = min(0.4, $sp / 30);
                $strength *= (1 - $penalty);
            }

            $weightedSum += $w * $dir * $strength;
            $totalWeight += $w;

            if ($v <= $bands['os']) $longTfs++;
            elseif ($v >= $bands['ob']) $shortTfs++;

            $used[] = ['tf' => $tf, 'v' => $v, 'w' => $w];
        }

        if ($totalWeight == 0.0 || !$used) {
            return [
                'side' => 'wait', 'confidence' => 0, 'score' => 0, 'used' => [],
                'longTfs' => 0, 'shortTfs' => 0, 'extremeAligned' => 0,
                'slopeOK' => null, 'divergence' => null, 'cascade' => 0,
                'regime' => null, 'primary' => null, 'primaryMatchCount' => 0,
                'primaryAvgScore' => 0, 'notes' => ['no RSI data on active timeframes'],
            ];
        }

        $score = $weightedSum / $totalWeight;
        $abs   = abs($score);
        $side  = $abs < 0.10 ? 'wait' : ($score > 0 ? 'long' : 'short');
        $notes = [];

        // (2) Trend-regime gate.
        $rsi1d = $row['rsi']['1d'] ?? null;
        $rsi4h = $row['rsi']['4h'] ?? null;
        $strongUp   = (is_numeric($rsi1d) && $rsi1d >= 65) || (is_numeric($rsi4h) && $rsi4h >= 70);
        $strongDown = (is_numeric($rsi1d) && $rsi1d <= 35) || (is_numeric($rsi4h) && $rsi4h <= 30);
        $regime = 'neutral';
        if ($strongUp && !$strongDown) $regime = 'up';
        elseif ($strongDown && !$strongUp) $regime = 'down';

        $trendPenalty = 0;
        if ($side === 'short' && $regime === 'up')   { $trendPenalty = 35; $notes[] = 'counter-trend short vs uptrend'; }
        if ($side === 'long'  && $regime === 'down') { $trendPenalty = 35; $notes[] = 'counter-trend long vs downtrend'; }

        // (3) RSI velocity / slope.
        $slopeAligned = 0;
        $slopeAgainst = 0;
        $slopeTfCount = 0;
        foreach (['1h', '4h', '1d'] as $tf) {
            $s = $row['rsiSlope'][$tf] ?? null;
            if (!is_numeric($s)) {
                continue;
            }
            $slopeTfCount++;
            $aligns = $side === 'long' ? $s > 0 : ($side === 'short' ? $s < 0 : false);
            if ($aligns) $slopeAligned++;
            elseif (abs($s) > 0.5) $slopeAgainst++;
        }
        $slopeOK = $slopeTfCount === 0 ? null : ($slopeAligned > $slopeAgainst);

        // (4) Divergence.
        $wantDiv = $side === 'long' ? 'bullish' : ($side === 'short' ? 'bearish' : null);
        $divergenceTf = null;
        foreach (['1d', '4h', '1h'] as $tf) {
            $d = $row['divergence'][$tf] ?? null;
            if ($d && $d === $wantDiv) {
                $divergenceTf = $tf;
                break;
            }
        }

        // (5) Alignment cascade.
        $cascade = 0;
        if ($side !== 'wait') {
            foreach (self::TF_ORDER as $tf) {
                $v = $row['rsi'][$tf] ?? null;
                if (!is_numeric($v)) {
                    break;
                }
                $inCamp = $side === 'long' ? $v < 50 : $v > 50;
                if ($inCamp) $cascade++;
                else break;
            }
        }

        // (6) Volume confirmation on 1h.
        $vol1h = $row['volRatio']['1h'] ?? null;
        $volBonus = 0;
        if (is_numeric($vol1h)) {
            if ($vol1h >= 1.5) $volBonus = 6;
            elseif ($vol1h >= 1.1) $volBonus = 3;
            elseif ($vol1h <= 0.5) $volBonus = -5;
        }

        // (7) 24h-change parabolic sanity.
        $parabolicPenalty = 0;
        $ch = $row['change24h'] ?? null;
        if (is_numeric($ch)) {
            if ($side === 'short' && $ch > 12 && $slopeOK !== true) $parabolicPenalty = 12;
            if ($side === 'long'  && $ch < -12 && $slopeOK !== true) $parabolicPenalty = 12;
        }

        // (8) Pinbar confluence.
        $pinbarBonus = 0;
        $pinbarTf    = null;
        $wantPin = $side === 'long' ? 'bullish' : 'bearish';
        foreach (['1d', '4h', '1h'] as $tf) {
            $p = $row['pinbar'][$tf] ?? null;
            if (!$p) {
                continue;
            }
            if ($p === $wantPin) {
                $pinbarBonus = $tf === '1d' ? 10 : ($tf === '4h' ? 7 : 4);
                $pinbarTf = $tf;
                break;
            }
            if (!$pinbarTf) {
                $pinbarBonus = $tf === '1d' ? -8 : ($tf === '4h' ? -5 : -3);
                $pinbarTf = $tf . ':against';
            }
        }

        // (10) Funding rate.
        $fundingBonus = 0;
        $fundingNote  = null;
        $fr = $row['funding'] ?? null;
        if (is_numeric($fr) && $side !== 'wait') {
            $HOT = 0.0005;
            $VERY = 0.0010;
            if ($side === 'long') {
                if      ($fr <= -$VERY) { $fundingBonus =  5; $fundingNote = 'long_supportive_strong'; }
                elseif  ($fr <= -$HOT)  { $fundingBonus =  3; $fundingNote = 'long_supportive'; }
                elseif  ($fr >=  $VERY) { $fundingBonus = -5; $fundingNote = 'long_overheated'; }
                elseif  ($fr >=  $HOT)  { $fundingBonus = -2; $fundingNote = 'long_crowded'; }
            } else {
                if      ($fr >=  $VERY) { $fundingBonus =  5; $fundingNote = 'short_supportive_strong'; }
                elseif  ($fr >=  $HOT)  { $fundingBonus =  3; $fundingNote = 'short_supportive'; }
                elseif  ($fr <= -$VERY) { $fundingBonus = -5; $fundingNote = 'short_overheated'; }
                elseif  ($fr <= -$HOT)  { $fundingBonus = -2; $fundingNote = 'short_crowded'; }
            }
        }

        // (11) Pre-move patterns.
        $patternBonus = 0;
        $patternNote  = null;
        $patterns = $row['patterns'] ?? null;
        if (is_array($patterns) && $side !== 'wait') {
            $pp = $patterns['prePump']['score'] ?? 0;
            $pd = $patterns['preDump']['score'] ?? 0;
            if ($side === 'long') {
                if ($pp >= 50)     { $patternBonus = min(10, (int)round(($pp - 50) / 5)); $patternNote = 'pre_pump_align'; }
                elseif ($pd >= 60) { $patternBonus = -min(8, (int)round(($pd - 60) / 5)); $patternNote = 'pre_dump_warn'; }
            } else {
                if ($pd >= 50)     { $patternBonus = min(10, (int)round(($pd - 50) / 5)); $patternNote = 'pre_dump_align'; }
                elseif ($pp >= 60) { $patternBonus = -min(8, (int)round(($pp - 60) / 5)); $patternNote = 'pre_pump_warn'; }
            }
        }

        // (9) Wave context.
        $waveBonus = 0;
        $waveNote  = null;
        $w = $row['wave'] ?? null;
        if (is_array($w) && !empty($w['direction']) && !empty($w['phase'])) {
            $dir = $w['direction'];
            $ph = $w['phase'];
            if ($side === 'long' && $dir === 'up') {
                if ($ph === 'pullback')    { $waveBonus = 8; $waveNote = 'pullback_up'; }
                elseif ($ph === 'impulse') { $waveBonus = 2; $waveNote = 'impulse_up'; }
            } elseif ($side === 'short' && $dir === 'down') {
                if ($ph === 'pullback')    { $waveBonus = 8; $waveNote = 'pullback_down'; }
                elseif ($ph === 'impulse') { $waveBonus = 2; $waveNote = 'impulse_down'; }
            } elseif ($side === 'long' && $dir === 'down' && $ph === 'impulse') {
                $waveBonus = -6; $waveNote = 'against_impulse_down';
            } elseif ($side === 'short' && $dir === 'up' && $ph === 'impulse') {
                $waveBonus = -6; $waveNote = 'against_impulse_up';
            }
        }

        foreach ($used as $u) {
            if ($side === 'long' && $u['v'] <= $bands['xLow']) $extremeAligned++;
            elseif ($side === 'short' && $u['v'] >= $bands['xHigh']) $extremeAligned++;
        }

        $agree    = $side === 'long' ? $longTfs  : ($side === 'short' ? $shortTfs : 0);
        $conflict = $side === 'long' ? $shortTfs : ($side === 'short' ? $longTfs  : 0);

        $confidence  = $abs * 80;
        $confidence += $agree * 4;
        $confidence += $extremeAligned * 3;
        $confidence += $cascade * 5;
        $confidence += $volBonus;
        $confidence += $pinbarBonus;
        $confidence += $waveBonus;
        $confidence += $fundingBonus;
        $confidence += $patternBonus;
        if ($divergenceTf) $confidence += 12;
        if ($slopeOK === true)  $confidence += 7;
        if ($slopeOK === false) $confidence -= 10;
        $confidence -= $conflict * 12;
        $confidence -= $trendPenalty;
        $confidence -= $parabolicPenalty;

        // PRIMARY indicators gate the confidence cap.
        $primary = null;
        $primaryMatchCount = 0;
        $primaryAvgScore = 0;
        if ($side === 'long' || $side === 'short') {
            $primary = [
                'wave'      => self::evalPrimaryWave($row, $side),
                'pinbar'    => self::evalPrimaryPinbar($row, $side),
                'rsi'       => self::evalPrimaryRsi($row, $side, $bands),
                'trendline' => self::evalPrimaryTrendline($row, $side),
                'sr'        => self::evalPrimarySRConfluence($row, $side),
            ];
            $sum = 0;
            foreach ($primary as $p) {
                $sum += $p['score'];
                if (!empty($p['match'])) $primaryMatchCount++;
            }
            $primaryAvgScore = $sum / count($primary);
        }
        $primaryCap = $primary ? self::PRIMARY_CAPS[$primaryMatchCount] : 95;

        $confidence = (int) max(0, min($primaryCap, round($confidence)));
        if ($side === 'wait') {
            $confidence = min($confidence, 25);
        }

        return [
            'side' => $side, 'confidence' => $confidence, 'score' => $score, 'used' => $used,
            'longTfs' => $longTfs, 'shortTfs' => $shortTfs, 'extremeAligned' => $extremeAligned,
            'slopeOK' => $slopeOK, 'slopeAligned' => $slopeAligned, 'slopeAgainst' => $slopeAgainst,
            'divergence' => $divergenceTf ? ['tf' => $divergenceTf, 'kind' => $wantDiv] : null,
            'cascade' => $cascade, 'regime' => $regime, 'bands' => $bands,
            'volBonus' => $volBonus, 'trendPenalty' => $trendPenalty, 'parabolicPenalty' => $parabolicPenalty,
            'pinbar' => $pinbarBonus !== 0 ? ['tf' => $pinbarTf, 'bonus' => $pinbarBonus, 'kind' => $wantPin] : null,
            'wave' => $waveNote ? ['note' => $waveNote, 'bonus' => $waveBonus] : null,
            'funding' => is_numeric($fr) ? ['rate' => $fr, 'bonus' => $fundingBonus, 'note' => $fundingNote] : null,
            'pattern' => $patternNote ? ['note' => $patternNote, 'bonus' => $patternBonus] : null,
            'primary' => $primary, 'primaryMatchCount' => $primaryMatchCount,
            'primaryAvgScore' => $primaryAvgScore, 'primaryCap' => $primaryCap,
            'notes' => $notes,
        ];
    }

    // ---------------------------------------------------------------
    // buildLevels
    // ---------------------------------------------------------------

    private static function buildLevels(array $row, string $side): ?array
    {
        $lv = $row['levels'] ?? null;
        if (!is_array($lv) || ($side !== 'long' && $side !== 'short')) {
            return null;
        }
        $px = $row['price'] ?? null;
        if (!is_numeric($px) || $px <= 0) {
            return null;
        }
        $atr = $lv['atr'] ?? null;
        if (!is_numeric($atr) || $atr <= 0) {
            return null;
        }
        if ($side === 'long') {
            $swingLow = $lv['swingLow'] ?? null;
            if (!is_numeric($swingLow)) {
                return null;
            }
            $stop = min($swingLow, $px - $atr);
            $risk = $px - $stop;
            if ($risk <= 0) {
                return null;
            }
            return ['side' => 'long', 'entry' => $px, 'stop' => $stop, 'tp1' => $px + $atr * 2, 'tp2' => $px + $atr * 3, 'rr1' => ($atr * 2) / $risk, 'rr2' => ($atr * 3) / $risk, 'atr' => $atr];
        }
        $swingHigh = $lv['swingHigh'] ?? null;
        if (!is_numeric($swingHigh)) {
            return null;
        }
        $stop = max($swingHigh, $px + $atr);
        $risk = $stop - $px;
        if ($risk <= 0) {
            return null;
        }
        return ['side' => 'short', 'entry' => $px, 'stop' => $stop, 'tp1' => $px - $atr * 2, 'tp2' => $px - $atr * 3, 'rr1' => ($atr * 2) / $risk, 'rr2' => ($atr * 3) / $risk, 'atr' => $atr];
    }
}
