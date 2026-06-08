<?php

declare(strict_types=1);

/** @var yii\web\View $this */

use yii\helpers\Html;

/** Translation shorthand for the `app` category. */
$t = static fn(string $message, array $params = []): string => Yii::t('app', $message, $params);

$lang = str_starts_with((string)Yii::$app->language, 'ru') ? 'ru' : 'en';
$this->title = $t('How it works') . ' — ' . $t('the signal algorithm');
$this->render('_kit');

$css = <<<CSS
.doc { max-width: 940px; }
.doc h1 { font-size: 1.7rem; font-weight: 700; }
.doc h2 {
    font-size: 1.25rem; font-weight: 650; margin-top: 2.3rem; padding-top: .5rem;
    border-top: 1px solid var(--bs-border-color);
}
.doc h3 { font-size: 1.02rem; font-weight: 600; margin-top: 1.4rem; }
.doc p, .doc li { line-height: 1.62; }
.doc pre.f {
    background: var(--bs-tertiary-bg); border: 1px solid var(--bs-border-color);
    border-radius: .5rem; padding: .55rem .8rem; font-size: .82rem; overflow-x: auto;
    margin: .6rem 0;
}
.doc table { font-size: .86rem; }
.doc table code { white-space: nowrap; }
.doc .lead { color: var(--bs-secondary-color); }
.doc .toc { font-size: .9rem; }
.doc .toc a { text-decoration: none; }
.doc .toc ol { margin-bottom: 0; }
.doc code { color: var(--bs-emphasis-color); }
.doc .anchor { scroll-margin-top: 4.8rem; }
.doc .pill {
    display: inline-block; font-family: var(--bs-font-monospace); font-weight: 600;
    font-size: .74rem; padding: .08rem .4rem; border-radius: .35rem;
}
.doc .pill-long  { background: var(--bs-success-bg-subtle); color: var(--bs-success-text-emphasis); }
.doc .pill-short { background: var(--bs-danger-bg-subtle);  color: var(--bs-danger-text-emphasis); }
CSS;
$this->registerCss($css);

// Section titles — reused by both the table of contents and the headings.
$sections = [
    's0'  => $t('Pipeline overview'),
    's1'  => $t('Daily-candle features'),
    's2'  => $t('RSI indicator (Wilder)'),
    's3'  => $t('Volatility regime and adaptive RSI bands'),
    's4'  => $t('Multi-timeframe RSI bias'),
    's5'  => $t('Confidence'),
    's6'  => $t('The five primary structures'),
    's7'  => $t('Auxiliary detectors'),
    's8'  => $t('Setup detector (hard rules)'),
    's9'  => $t('From signal to position: risk and parameters'),
    's10' => $t('Wait-for-the-turn veto (TrapGate)'),
    's11' => $t('DELTA_ALIGNMENT_SHORT_V1 screener'),
    's12' => $t('The full pipeline'),
];
?>
<div class="container py-4 doc">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <h1 class="mb-0 me-auto"><?= Html::encode($t('How it works')) ?></h1>
        <div class="btn-group btn-group-sm" role="group" aria-label="Language">
            <?= Html::a('EN', ['/site/language', 'lang' => 'en'], ['class' => 'btn ' . ($lang === 'en' ? 'btn-primary' : 'btn-outline-secondary')]) ?>
            <?= Html::a('RU', ['/site/language', 'lang' => 'ru'], ['class' => 'btn ' . ($lang === 'ru' ? 'btn-primary' : 'btn-outline-secondary')]) ?>
        </div>
    </div>

    <p class="lead"><?= $t('The system scans the whole coin universe and produces trade signals as a composition of several independent layers: hard boolean setup rules, a continuous RSI-confluence confidence model, a "wait-for-the-turn" veto, and a separate multi-timeframe DELTA_ALIGNMENT_SHORT_V1 screener. Below is the entire pipeline, layer by layer.') ?></p>

    <div class="card card-section my-4"><div class="card-body py-3"><div class="toc">
        <strong><?= Html::encode($t('Contents')) ?></strong>
        <ol class="mt-2">
            <?php $n = 0; foreach ($sections as $id => $title): ?>
                <li><a href="#<?= $id ?>"><?= Html::encode($title) ?></a></li>
            <?php endforeach ?>
        </ol>
    </div></div></div>

    <h2 id="s0" class="anchor">0. <?= Html::encode($sections['s0']) ?></h2>
    <p><?= $t('A signal is born as a composition of three independent layers:') ?></p>
    <ul>
        <li><strong>(A) <?= $t('Setup detector') ?> — <code>SetupEvaluator</code>.</strong>
            <?= $t('Hard boolean rules over the daily candle features and macro context. Each satisfied rule emits a "fire" of one of the types L1…L5 (long) or S1…S6 (short). It is a binary trigger: the condition is either true or not.') ?></li>
        <li><strong>(B) <?= $t('Confluence grading') ?> — <code>ConfluenceGrader</code>.</strong>
            <?= $t('A continuous scoring model. For an already-fired setup it measures how much an independent multi-timeframe RSI model agrees with the trade direction. Returns a confidence in [0, 95] and a count of confirming structures.') ?></li>
        <li><strong>(C) <?= $t('Wait-for-the-turn veto') ?> — <code>TrapGate</code>.</strong>
            <?= $t('A post-filter. Rejects counter-trend setups when the reversal they bet on has not statistically begun. Off by default.') ?></li>
    </ul>
    <p><?= $t('The final set of signals:') ?></p>
    <pre class="f">Signals = { f ∈ Fires : ¬Veto(f) ∧ Cluster_ok(f) }</pre>
    <p><?= $t('Each f carries a score Score(f) = (confidence, primaries); position size is computed separately by the risk model (§9). SignalEngine orchestrates everything.') ?></p>

    <h2 id="s1" class="anchor">1. <?= Html::encode($sections['s1']) ?></h2>
    <p><?= $t('Each daily candle yields dimensionless features; wick quantities are normalised by the close so they are comparable across assets of different price. Candle: O open, H high, L low, C close, V volume. Index 0 is the last closed candle.') ?></p>
    <pre class="f">ret          = C_t / C_{t-1} − 1
upper_wick   = (H − max(O, C)) / C
lower_wick   = (min(O, C) − L) / C
closed_high  = (C − L) / (H − L)        ∈ [0,1]
turnover     = V · C</pre>
    <p><?= $t('Rolling statistics over a W = 20-day window: vol20 (realised volatility, also reused as the volatility-regime variable volStd in §3), vol_ratio (relative volume) and turnover20 (the liquidity gate).') ?></p>

    <h2 id="s2" class="anchor">2. <?= Html::encode($sections['s2']) ?></h2>
    <p><?= $t('Classic RSI(14), Wilder smoothing. With Δ = C_i − C_{i-1}, g = max(Δ,0), l = max(−Δ,0), period p = 14:') ?></p>
    <pre class="f">avgG ← (avgG·(p−1) + g) / p
avgL ← (avgL·(p−1) + l) / p
RS  = avgG / avgL
RSI = 100 − 100 / (1 + RS)

avgL = 0, avgG = 0  ⇒ RSI = 50
avgL = 0, avgG &gt; 0  ⇒ RSI = 100
rsiSlope = RSI_t − RSI_{t-1}</pre>
    <p><?= $t('RSI is computed on 1h, 4h, 1d (plus the full series, for divergences and slope). In the MTF context the 4h/1h RSI uses a simple average — a deliberate simplification for the intraday scanner.') ?></p>

    <h2 id="s3" class="anchor">3. <?= Html::encode($sections['s3']) ?></h2>
    <p><?= $t('Overbought/oversold thresholds depend on realised volatility volStd: the higher the volatility, the wider the bands.') ?></p>
    <div class="table-responsive"><table class="table table-sm table-bordered align-middle">
        <thead><tr><th><?= Html::encode($t('Regime')) ?></th><th><code>volStd</code></th><th>os</th><th>ob</th><th>xLow</th><th>xHigh</th></tr></thead>
        <tbody>
            <tr><td><?= $t('High vol.') ?></td><td><code>≥ 0.025</code></td><td>22</td><td>78</td><td>15</td><td>85</td></tr>
            <tr><td><?= $t('Medium') ?></td><td><code>0.012 … 0.025</code></td><td>28</td><td>72</td><td>20</td><td>80</td></tr>
            <tr><td><?= $t('Low') ?></td><td><code>&lt; 0.012</code></td><td>35</td><td>65</td><td>25</td><td>75</td></tr>
            <tr><td><?= $t('Default') ?></td><td><?= $t('unset / ≤ 0') ?></td><td>30</td><td>70</td><td>20</td><td>80</td></tr>
        </tbody>
    </table></div>
    <p><?= $t('os/ob are the soft bands (oversold/overbought), xLow/xHigh the extreme levels.') ?></p>

    <h2 id="s4" class="anchor">4. <?= Html::encode($sections['s4']) ?></h2>
    <p><?= $t('The core of the scoring model: a continuous signed score bias ∈ [−1, +1] aggregating RSI across three timeframes with weights (higher TF = more weight).') ?></p>
    <pre class="f">w(1h) = 1.0,  w(4h) = 1.6,  w(1d) = 2.4

dist     = |50 − RSI| / 50            ∈ [0,1]
dir      = +1 if RSI &lt; 50; −1 if RSI &gt; 50
strength = dist^1.4
strength ← strength · (1 − min(0.4, spread/30))

bias = Σ wτ·dirτ·strengthτ / Σ wτ

|bias| &lt; 0.10 ⇒ wait;  bias &gt; 0 ⇒ long;  bias &lt; 0 ⇒ short</pre>

    <h2 id="s5" class="anchor">5. <?= Html::encode($sections['s5']) ?></h2>
    <p><?= $t('After the side is picked, a scalar confidence is built as a base plus bonuses/penalties, then clipped by a cap that depends on the number of confirmed structures.') ?></p>
    <pre class="f">conf := |bias| · 80                                  (base)
+ 4·agree   + 3·extremeAligned   + 5·cascade   − 12·conflict
vol_ratio(1h): ≥1.5 ⇒ +6;  ≥1.1 ⇒ +3;  ≤0.5 ⇒ −5
RSI↔price divergence (right sign) ⇒ +12
RSI slope: slopeOK ⇒ +7, else ⇒ −10
pinbar (1d/4h/1h): aligned +10/+7/+4; opposite −8/−5/−3
wave: aligned+pullback +8; aligned+impulse +2; against+impulse −6
funding (long): ≤−VERY +5; ≤−HOT +3; ≥+VERY −5; ≥+HOT −2   (short: mirrored)
trend gate: short vs up-regime / long vs down-regime ⇒ −35
parabolic without a turn (|change24h| &gt; 12%) ⇒ −12</pre>
    <p><?= $t('Cap by the number of matched primary structures k ∈ {0..5}:') ?></p>
    <pre class="f">PRIMARY_CAPS = [30, 50, 70, 82, 90, 95]   (index = k)
confidence = round( clip(conf, 0, PRIMARY_CAPS[k]) )
if side = wait ⇒ confidence = min(confidence, 25)</pre>
    <p><?= $t('Meaning: with no structural confirmation (k=0) confidence cannot exceed 30; the full 95 is reachable only with all five confirmations.') ?></p>

    <h2 id="s6" class="anchor">6. <?= Html::encode($sections['s6']) ?></h2>
    <p><?= $t('Each returns match ∈ {0,1} and score ∈ [0,100]; only the count of match=true feeds the cap (§5).') ?></p>
    <ul>
        <li><strong>P1 · WAVE</strong> — <?= $t('wave context: trend on the trade side + phase (pullback ⇒ 95, impulse ⇒ 60); trend against the side ⇒ no match.') ?></li>
        <li><strong>P2 · PINBAR</strong> — <?= $t('rejection candle of the right direction on 1d/4h/1h (base scores 100/85/55).') ?></li>
        <li><strong>P3 · <?= $t('RSI alignment') ?></strong> — <code>score = min(100, round((2·n_ext + n_soft)/(2·n_tf)·100))</code>.</li>
        <li><strong>P4 · <?= $t('S/R confluence') ?></strong> — <?= $t('proximity to a level in ATR units: d_atr ≤ 0.5 ⇒ 100; ≤ 1.5 ⇒ 82; ≤ 3.0 ⇒ 55; a zone of ≥3 levels ⇒ +8.') ?></li>
        <li><strong>P5 · TRENDLINE</strong> — <?= $t('trendline touch (least-squares over pivots): d_atr ≤ 0.5 ⇒ 100; ≤ 1.5 ⇒ 80; ≤ 3.0 ⇒ 55; else 30.') ?></li>
    </ul>

    <h2 id="s7" class="anchor">7. <?= Html::encode($sections['s7']) ?></h2>
    <ul>
        <li><strong><?= $t('RSI↔price divergence') ?></strong> — <?= $t('price makes a new extreme, RSI does not (lookback 30, 3-bar frame).') ?></li>
        <li><strong>ATR(14)</strong> — <code>TR = max(H−L, |H−C₋₁|, |L−C₋₁|)</code>, <code>ATR = (ATR₋₁·13 + TR)/14</code>.</li>
        <li><strong><?= $t('Pinbar') ?></strong> — <?= $t('small body (body/R ≤ 0.35), long rejection wick (≥ 2·body), short opposite wick, sweeps the 10-bar extreme.') ?></li>
        <li><strong><?= $t('Wave') ?></strong> — <?= $t('over pivots (1.5% swing filter): higher highs / higher lows ⇒ up/down; impulse/pullback phase by distance and retrace.') ?></li>
        <li><strong><?= $t('Pre-move patterns') ?></strong> — <?= $t('Squeeze, Accumulation, Higher-lows, Parabolic, Distribution/Climax.') ?></li>
    </ul>

    <h2 id="s8" class="anchor">8. <?= Html::encode($sections['s8']) ?></h2>
    <p><?= $t('Each rule is a conjunction of conditions over the features (§1) and macro context: fund (funding), fund7d, oiz (open-interest z-score), fng (fear & greed), btc_d (BTC dominance).') ?></p>
    <h3><?= Html::encode($t('Longs')) ?></h3>
    <div class="table-responsive"><table class="table table-sm table-bordered align-middle">
        <thead><tr><th><?= Html::encode($t('Setup')) ?></th><th><?= Html::encode($t('Condition')) ?></th><th><?= Html::encode($t('Entry / Stop')) ?></th></tr></thead>
        <tbody>
            <tr><td><span class="pill pill-long">L1</span> <?= $t('Short-squeeze') ?></td><td><code>vol_ratio ≥ 1.0 ∧ fund ≤ −0.0002 ∧ 26 ≤ fng ≤ 45</code></td><td><code>C·1.003 (24h) / max(L·0.995, C·0.93)</code></td></tr>
            <tr><td><span class="pill pill-long">L2</span> <?= $t('Capitulation') ?></td><td><code>vol_ratio ≥ 2.0 ∧ lower_wick ≥ 0.20 ∧ ret ≤ 0 ∧ fng ≤ 25</code></td><td><code>open / L·0.99</code></td></tr>
            <tr><td><span class="pill pill-long">L3</span> <?= $t('Momentum') ?></td><td><code>vol_ratio ≥ 2.0 ∧ closed_high ≥ 0.85 ∧ 0.04 ≤ ret ≤ 0.15 ∧ upper_wick ≤ 0.05</code></td><td><code>H·1.002 / (H+L)/2·0.995</code></td></tr>
            <tr><td><span class="pill pill-long">L4</span> <?= $t('Cluster bottom') ?></td><td><?= $t('extreme fear after a cluster dump') ?></td><td>—</td></tr>
            <tr><td><span class="pill pill-long">L5</span> <?= $t('Pullback dip-buy (MTF)') ?></td><td><code>d_down ∧ (RSI_4h &lt; 40 ∨ RSI_1h &lt; 40)</code></td><td><code>market / max(d_low3·0.97, C·0.90)</code></td></tr>
        </tbody>
    </table></div>
    <h3><?= Html::encode($t('Shorts')) ?></h3>
    <div class="table-responsive"><table class="table table-sm table-bordered align-middle">
        <thead><tr><th><?= Html::encode($t('Setup')) ?></th><th><?= Html::encode($t('Condition')) ?></th><th><?= Html::encode($t('Entry / Stop')) ?></th></tr></thead>
        <tbody>
            <tr><td><span class="pill pill-short">S1</span> <?= $t('Crowded-long blowup') ?></td><td><code>fund ≥ 0.0005 ∧ fund7d ≥ 0.0003 ∧ 26 ≤ fng ≤ 45 ∧ oiz ≥ 1.0</code></td><td><code>C·1.010 (48h)</code></td></tr>
            <tr><td><span class="pill pill-short">S2</span> <?= $t('Euphoria trap') ?></td><td><code>fund ≥ 0.0005 ∧ fng ≥ 76 ∧ oiz ≥ 1.0 ∧ Δbtc_d30 ≥ 0.5</code></td><td><code>C·1.010 (48h)</code></td></tr>
            <tr><td><span class="pill pill-short">S3</span> <?= $t('Volume exhaustion') ?></td><td><code>vol_ratio ≥ 10.0 ∧ ret ≥ 0.10</code></td><td><code>market / H·1.005</code></td></tr>
            <tr><td><span class="pill pill-short">S4</span> <?= $t('Rejection') ?></td><td><code>vol_ratio ≥ 2.0 ∧ ret ≥ 0.10 ∧ upper_wick ≥ 0.20 ∧ closed_high ≤ 0.50</code></td><td><code>(H+L)/2 / H·1.005</code></td></tr>
            <tr><td><span class="pill pill-short">S6</span> <?= $t('4h blow-off (MTF)') ?></td><td><code>d_up ∧ 4h: ret ≥ 0.04 ∧ upper_wick ≥ 0.15</code></td><td><code>market / max(H_4h, C)·1.005</code></td></tr>
        </tbody>
    </table></div>
    <p class="lead"><?= $t('⚠️ S3 is deliberately excluded from the veto (§10): it shorts a still-accelerating blow-off.') ?></p>
    <h3><?= Html::encode($t('Multi-timeframe context (MtfContext)')) ?></h3>
    <pre class="f">weekly: SMA10, c4=close[−4] ⇒ w_up = close &gt; SMA10 ∧ close &gt; c4   (w_down mirrored)
daily:  SMA20, c5=close[−5] ⇒ d_up = close &gt; SMA20 ∧ close &gt; c5   (d_down mirrored)
        d_low3 = min(low[−2..0])
4h &amp; 1h: simple-average RSI(14) + slope + last-bar features</pre>

    <h2 id="s9" class="anchor">9. <?= Html::encode($sections['s9']) ?></h2>
    <p><?= $t('A fired, un-vetoed signal becomes a position via a fixed-fractional risk model with a beta adjustment:') ?></p>
    <pre class="f">risk_$ = equityUsd · riskPct
qty    = risk_$ / |entry_px − stop_px|
        (capped by maxNotionalPct per trade and maxPortfolioNotionalPct per portfolio)</pre>
    <div class="table-responsive"><table class="table table-sm table-bordered align-middle">
        <thead><tr><th><?= Html::encode($t('Parameter')) ?></th><th><?= Html::encode($t('Value')) ?></th><th><?= Html::encode($t('Description')) ?></th></tr></thead>
        <tbody>
            <tr><td><code>riskPct</code></td><td>0.005</td><td><?= $t('0.5% risk per trade') ?></td></tr>
            <tr><td><code>maxNotionalPct</code></td><td>0.20</td><td><?= $t('≤20% notional per trade') ?></td></tr>
            <tr><td><code>maxPortfolioNotionalPct</code></td><td>0.60</td><td><?= $t('≤60% total open notional') ?></td></tr>
            <tr><td><code>maxConcurrentSingles</code></td><td>10</td><td><?= $t('≤10 concurrent trades') ?></td></tr>
            <tr><td><code>timeExitDays</code></td><td>20</td><td><?= $t('forced exit at T+20') ?></td></tr>
            <tr><td><code>requireTurnConfirmation</code></td><td>false</td><td><?= $t('enable the veto layer (§10)') ?></td></tr>
            <tr><td><code>minTurnover20dUsd</code></td><td>5,000,000</td><td><?= $t('liquidity gate ($5M)') ?></td></tr>
        </tbody>
    </table></div>

    <h2 id="s10" class="anchor">10. <?= Html::encode($sections['s10']) ?></h2>
    <p><?= $t('Hard rules fire on the most extreme candle without reversal confirmation, so counter-trend setups (L2, L5, S4, S6) are vulnerable to catching a falling knife. The veto adds that confirmation as a post-filter — it does not change the set of setups, only blocks entry. Active only when requireTurnConfirmation = true; it uses the 4h/1h RSI slopes.') ?></p>
    <pre class="f">Rule 1: veto if ALL available slopes are against the trade
    long:  all slopes &lt; −ε   ⇒ "RSI still falling"
    short: all slopes &gt; +ε   ⇒ "RSI still rising"
Rule 3: parabolic without a turn (par = 0.12)
    short ∧ ret ≥ +par ∧ turn not started ⇒ veto
    long  ∧ ret ≤ −par ∧ turn not started ⇒ veto</pre>
    <p><?= $t('If both slopes are unavailable the layer opens (never blocks on missing data). S3 is excluded: the backtest showed reversal confirmation cuts exactly the best exhaustion fades (kept −0.05R vs rejected +2.58R).') ?></p>

    <h2 id="s11" class="anchor">11. <?= Html::encode($sections['s11']) ?></h2>
    <p><?= $t('A separate multi-timeframe indicator of SMA15 vs SMA50. A pure indicator — it produces no entry/stop/size, it flags a statistically significant tilt toward the short side. For each instrument it reads 5m, 30m, 1h, 2h candles.') ?></p>
    <pre class="f">For each TF, on close prices:  SMA15, SMA50
    delta = (SMA15 − SMA50) / SMA50 · 100        (percent)

Active when, on ALL four TFs simultaneously:
    −2 &lt; delta_5m  &lt; 0
    −2 &lt; delta_30m &lt; 0
    −2 &lt; delta_1h  &lt; 0
    −2 &lt; delta_2h  &lt; 0
(strict bounds: exactly 0 or −2 ⇒ inactive)</pre>
    <p><?= $t('Meaning: SMA15 sits just below SMA50 — a mild, freshly established bearish alignment on every timeframe at once. The historical sample (n = 769) gave a tilt of SHORT 61.9% / LONG 38.1%.') ?></p>
    <pre class="f">{ "signal": "DELTA_ALIGNMENT_SHORT_V1", "active": true,
  "bias": "SHORT", "short_probability": 61.9, "long_probability": 38.1 }</pre>
    <p><?= $t('If any TF is out of band or data is insufficient (fewer than 50 closes), the result is active:false (fail-safe). It is computed at the daily close on each TF last completed bar, so historical/replay scans are reproducible.') ?></p>
    <ul>
        <li><strong><?= $t('Candle fetch') ?>.</strong> <?= $t('5m/30m/2h were added to the fetcher intervals. Sub-hourly intervals backfill from a recent window (SHORT_BACKFILL_BARS = 6000 bars) rather than from listing — otherwise the KLINE page cap would keep history from reaching the present. One fetch run makes the signal live.') ?></li>
        <li><strong><?= $t('Tail read') ?>.</strong> <?= $t('The screener reads only the tail of each series (≈55 bars: SMA50 + margin) via a binary search for the as-of bar — not the whole dense 5m series — and the read is as-of-aware, so historical scans stay correct.') ?></li>
    </ul>
    <p><?= $t('Active hits are collected into the scan result (delta_alignment) and the diagnostics (delta_alignment_active).') ?></p>

    <h2 id="s12" class="anchor">12. <?= Html::encode($sections['s12']) ?></h2>
    <p><strong><?= $t('Daily scan:') ?></strong></p>
    <ol>
        <li><?= $t('Iterate the universe; liquidity gate and blacklist.') ?></li>
        <li><?= $t('Compute daily features (§1) and the current row.') ?></li>
        <li><?= $t('Gather macro context: funding, OI, FNG, BTC dominance.') ?></li>
        <li><?= $t('Gather the MTF context (§8.1) and the DELTA_ALIGNMENT screener (§11).') ?></li>
        <li><?= $t('SetupEvaluator.evaluate() ⇒ list of fires.') ?></li>
        <li><?= $t('For each fire: confluence scoring → TrapGate veto → cluster pause → beta-adjusted sizing → signal record.') ?></li>
        <li><?= $t('Sort by setup priority; return the list + diagnostics + active delta_alignment.') ?></li>
    </ol>
    <p><strong><?= $t('Intraday scan') ?></strong> — <?= $t('for L5/S6 at each 4h close: as-of MTF context at the 4h close, the same gates and sizing; veto rule 3 is skipped.') ?></p>
    <p class="lead mt-3"><?= $t('Bottom line: signal = (hard setup trigger) ∧ (RSI-confluence agreement under the structure cap) ∧ (passes the wait-for-the-turn veto) ∧ (passes the cluster filter). Position size comes from the fixed-fractional risk model with a beta adjustment. The DELTA_ALIGNMENT screener runs in parallel as an independent bias indicator.') ?></p>
</div>
