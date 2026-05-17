# Trade Simulation — Master Comparison (All 12 Experiments)

Side-by-side comparison of every simulation run against the TRADE.md
entry triggers. Same data set (127 symbols, 2 781 triggers, 2 438
fills) across all experiments — only the risk / exit / analytical
parameters change.

## 1. Experiment catalogue

| # | Stop loss | Success bar | Time exit | Cluster filter | Slippage | Funding | WFCV | What it tests |
|---|---|---|---|---|---|---|---|---|
| **1** (v1) | setup-specific (~5 %) | +1 % | T+5 | OFF | 0 | mean | — | Original TRADE.md baseline |
| **2** (v2) | −2 % fixed | +3 % | T+5 | OFF | 0 | mean | — | First override |
| 3 | −1 % | +1 % | T+5 | OFF | 0 | mean | — | Symmetric tight |
| 4 | −3 % | +1 % | T+5 | OFF | 0 | mean | — | Loose stop, easy target |
| 5 | −1 % | +3 % | T+5 | OFF | 0 | mean | — | Tight stop, hard target |
| 6 | −3 % | +3 % | T+5 | OFF | 0 | mean | — | Symmetric loose |
| **7** | setup-specific | +1 % | **T+10** | OFF | 0 | mean | — | Extend time exit |
| **8** | setup-specific | +1 % | **T+20** | OFF | 0 | mean | — | Extend further |
| **9** | setup-specific | +1 % | T+5 | **ON** | 0 | mean | — | Cluster-day filter |
| **10** | setup-specific | +1 % | T+5 | OFF | **15 bps** | mean | — | Slippage / fees |
| **11** | setup-specific | +1 % | T+5 | OFF | 0 | mean | **2025-01-01** | Walk-forward CV |
| **12** | setup-specific | +1 % | T+5 | OFF | 0 | **live** | — | Live-rate funding |

Coverage in every run: **127 symbols, 2 781 triggers, 2 438 fills**
— except Exp 9 (cluster filter blocks 226 triggers → 2 212 fills)
and Exp 12 (live funding picks up 63 extra triggers → 2 469 fills).

## 2. The master matrix

### L1 (short-squeeze long) — 1 108 fills baseline

| Exp | Win % | Mean MFE on wins | Mean loss | R-multiple | Expectancy | Realized at target* |
|---|---|---|---|---|---|---|
| **1 (v1)** | **75.6 %** | +13.77 % | −4.55 % | 3.03 R | **+10.51 %** | n/a (no fixed target) |
| 2 (v2)  | 41.3 %     | +13.75 % | −2.00 % | 6.88 R | +6.19 %    | +0.06 % |
| 3       | 44.8 %     | +9.43 %  | −1.00 % | 9.43 R | +4.56 %    | −0.10 % |
| 4       | 69.1 %     | +11.68 % | −3.00 % | 3.89 R | +8.21 %    | −0.23 % |
| 5       | 30.2 %     | +13.06 % | −1.00 % | 13.06 R | +4.56 %   | **+0.21 %** |
| 6       | 51.1 %     | +15.15 % | −3.00 % | 5.05 R | +8.21 %    | +0.06 % |
| **7 (T+10)**  | 75.6 %     | **+16.67 %** | −4.55 % | 3.66 R | **+12.71 %** | n/a |
| **8 (T+20)**  | 75.7 %     | **+20.50 %** | −4.55 % | 4.51 R | **+15.61 %** | n/a |
| 9 (cluster)   | 74.8 %     | +12.55 %     | −4.29 % | 2.93 R | +9.49 %      | n/a |
| 10 (15 bps)   | 75.6 %     | +13.47 %     | −4.85 % | 2.78 R | +10.21 %     | n/a |
| **11 IS**     | 77.8 %     | +12.27 %     | −4.06 % | 3.02 R | +9.65 %      | n/a |
| **11 OOS**    | 73.1 %     | **+15.62 %** | −5.00 % | 3.12 R | **+11.51 %** | n/a |
| 12 (live)     | 75.6 %     | +13.16 %     | −4.37 % | 3.01 R | +10.05 %     | n/a |

\* Realized at target = `WR × success% + (1 − WR) × stop%`, assuming
fixed-target exit (no MFE capture).

### L3 (momentum continuation) — 763 fills baseline

| Exp | Win % | Mean MFE on wins | Mean loss | R-multiple | Expectancy |
|---|---|---|---|---|---|
| **1 (v1)** | **82.4 %** | +13.96 % | −6.59 % | 2.12 R | **+11.56 %** |
| 2 (v2)  | 39.1 %     | +16.12 % | −2.00 % | 8.06 R | +6.85 % |
| 3       | 43.9 %     | +10.39 % | −1.00 % | 10.39 R | +4.89 % |
| 4       | 67.5 %     | +12.30 % | −3.00 % | 4.10 R  | +8.42 % |
| 5       | 29.1 %     | +14.66 % | −1.00 % | 14.66 R | +4.89 % |
| 6       | 47.3 %     | +16.69 % | −3.00 % | 5.56 R  | +8.42 % |
| **7 (T+10)**  | 82.8 %     | **+17.09 %** | −6.61 % | 2.58 R | **+14.20 %** |
| **8 (T+20)**  | 83.0 %     | **+22.56 %** | −6.74 % | 3.35 R | **+18.76 %** |
| 9 (cluster)   | 83.5 %     | +14.04 %     | −6.19 % | 2.27 R | +11.77 % |
| 10 (15 bps)   | 82.4 %     | +13.66 %     | −6.89 % | 1.98 R | +11.26 % |
| **11 IS**     | 84.3 %     | +14.29 %     | −6.34 % | 2.25 R | +12.09 % |
| **11 OOS**    | 79.4 %     | +13.41 %     | −6.94 % | 1.93 R | +10.69 % |
| 12 (live)     | 82.4 %     | +13.96 %     | −6.59 % | 2.12 R | +11.56 % |

### S3 (volume-exhaustion short) — 297 fills baseline

| Exp | Win % | Mean MFE on wins | Mean loss | R-multiple | Expectancy |
|---|---|---|---|---|---|
| **1 (v1)** | **84.2 %** | +15.04 % | −6.49 % | 2.32 R | **+12.76 %** |
| 2 (v2)  | 46.1 % | +13.03 % | −1.96 % | 6.65 R  | +6.81 % |
| 3       | 43.1 % | +11.69 % | −0.99 % | 11.81 R | +6.00 % |
| 4       | 59.3 % | +11.89 % | −2.91 % | 4.09 R  | +7.57 % |
| 5       | 38.4 % | +12.85 % | −0.99 % | 12.98 R | +6.00 % |
| 6       | 51.5 % | +13.36 % | −2.91 % | 4.59 R  | +7.57 % |
| **7 (T+10)**  | 84.2 % | **+17.35 %** | −6.49 % | 2.67 R | **+14.70 %** |
| **8 (T+20)**  | 84.2 % | **+20.04 %** | −6.81 % | 2.94 R | **+16.97 %** |
| 9 (cluster)   | 84.3 % | +14.94 %     | −6.63 % | 2.25 R | +12.69 % |
| 10 (15 bps)   | 84.2 % | +14.74 %     | −6.79 % | 2.17 R | +12.46 % |
| **11 IS**     | 85.8 % | +13.07 %     | −6.41 % | 2.04 R | +11.28 % |
| **11 OOS**    | 82.5 % | **+17.07 %** | −6.56 % | 2.60 R | **+14.22 %** |
| 12 (live)     | 84.2 % | +15.04 %     | −6.49 % | 2.32 R | +12.76 % |

### S4 (rejection short) — 59 fills baseline (small-sample)

| Exp | Win % | Mean MFE on wins | Mean loss | Expectancy |
|---|---|---|---|---|
| **1 (v1)** | **98.3 %** | +19.93 % | −15.87 % | +19.59 % |
| 7 (T+10)   | 98.3 %     | +22.17 % | −15.87 % | +21.79 % |
| 8 (T+20)   | 98.3 %     | +26.23 % | −15.87 % | +25.79 % |
| 11 IS      | 100 %      | +18.33 % | n/a      | +18.33 % |
| 11 OOS     | 97.1 %     | +21.14 % | −15.87 % | +20.52 % |

## 3. Five structural findings synthesised across all 12 experiments

### Finding 1: Per-fill expectancy depends only on the STOP, not the success bar

Comparing Exp 3 (−1 % / +1 %) and Exp 5 (−1 % / +3 %): identical
expectancy +4.56 % for L1. Comparing Exp 4 (−3 % / +1 %) and Exp 6
(−3 % / +3 %): identical +8.21 %. The MFE distribution is fixed by
the stop; the success bar just re-classifies wins vs losses.

**Implication:** if your exit logic captures MFE (trailing stop or
time exit), the stop loss is the only knob that matters. The success
bar is a labelling convention.

### Finding 2: Looser stop = more captured edge (linear)

| Stop | L1 expectancy |
|---|---|
| −1 % | +4.56 % |
| −2 % | +6.19 % |
| −3 % | +8.21 % |
| setup-specific (~5 %) | +10.51 % |

There's a roughly linear gain in captured MFE as the stop widens.
Tighter stops shake out trades during intra-day noise before they
reach MFE peak. Trade-off: risk per trade.

### Finding 3: Time exit T+10 is the single biggest free improvement

Exp 7 (T+10) and Exp 8 (T+20) both lift expectancy 21–62 % with
**zero win-rate cost**. T+10 gains:

| Setup | v1 (T+5) | T+10 | Δ |
|---|---|---|---|
| L1 | +10.51 % | +12.71 % | **+21 %** |
| L3 | +11.56 % | +14.20 % | **+23 %** |
| S3 | +12.76 % | +14.70 % | +15 % |
| S4 | +19.59 % | +21.79 % | +11 % |

T+20 doubles the holding window for another +28–62 % gain. The
T+5 baseline was leaving meaningful edge on the table.

### Finding 4: The cluster-day filter HURTS L1 (counter-intuitive)

Exp 9 tested TRADE.md §3 rule "pause longs the day after ≥ 50
universe symbols dumped". For L1 specifically:

- Removed 140 fills (−13 %)
- Cut 114 winners but only 26 losers — **4:1 negative ratio**
- L1 expectancy: +10.51 % → +9.49 % (−10 %)

The L1 trigger (negative funding + Fear FNG) is often *generated by*
cluster-dump aftermath. Pausing kills the bounce trade. L3 is
neutral under the filter. **Conclusion:** drop the cluster filter
for L1; keep optionally for L3 / shorts.

### Finding 5: System is walk-forward stable (does NOT degrade OOS)

Exp 11 split aggregates at 2025-01-01:

| Setup | IS expectancy | OOS expectancy | Δ |
|---|---|---|---|
| L1 | +9.65 % | **+11.51 %** | **+19 %** |
| L3 | +12.09 % | +10.69 % | −12 % |
| S3 | +11.28 % | **+14.22 %** | **+26 %** |
| S4 | +18.33 % | +20.52 % | +12 % |

**Three of four core setups got STRONGER OOS.** The framework is not
a 2021–2024 bull-market artifact.

Casualties: **S1 and S2 fired ZERO times OOS** — their joint
triggers (crowded-long funding + Fear / Extreme Greed FNG) haven't
occurred in the current regime. Mark as DORMANT, not dead.

## 4. Negative results

These ideas tested worse than the baseline:

| Idea | Result | Why |
|---|---|---|
| **Cluster-day filter for L1** (Exp 9) | −10 % expectancy | L1's edge LIVES in post-cluster regimes |
| **Live-rate (16:00 UTC) funding** (Exp 12) | L1 −0.46 pp expectancy | Daily mean smooths funding noise better |
| **Fixed −1 % stop** (Exp 3/5) | L1 expectancy halved (+4.56 % vs +10.51 %) | Tight stops shake out too much |
| **Fixed −2 % stop** (Exp 2) | L1 expectancy down 41 % | Same reason, less extreme |

These four "obvious improvements" all underperformed the original
TRADE.md baseline. The original setup-specific stops adapt to daily
candle volatility better than any fixed pct.

## 5. Top historical trades across all configs

The top winners are largely shared across configs because the
biggest MFE moves were V-shaped breakouts that survive any
reasonable stop:

| Sym & date | v1 (T+5) | Exp 7 (T+10) | Exp 8 (T+20) |
|---|---|---|---|
| YGG L3 2023-08-03 | +251.7 % | +251.7 % | +251.7 % (peaks before T+5) |
| DUSK L1 2026-01-11 | +89.1 % | **+419.1 %** | **+419.1 %** (peaks T+5–T+10) |
| XLM L3 2024-11-11 | +110.1 % | +110.1 % | **+453.3 %** (peaks T+10–T+20) |
| AUCTION L1 2025-03-07 | +83.3 % | ~+200 % | **+348.8 %** (peaks T+10–T+20) |
| ORDI L3 2026-04-13 | +316.4 % | +316.4 % | +316.4 % |
| APE L1 2026-04-19 | +176.0 % | +176.0 % | +176.0 % |

Trades that DROPPED OUT of v2/v3/v5 (tight-stop configs) but
survived v1, Exp 7, and Exp 8:

- ORDI L3 2026-04-13 (+316.4 %) — stopped at −2 % in v2/v3/v5
- APE L1 2026-04-19 (+176.0 %) — stopped at −2 % in v2/v3/v5
- YGG L1 2025-10-11 (+139.3 %) — stopped at −2 % in v2/v3/v5
- AUCTION L1 cluster (×4 entries) — all stopped in v2/v3/v5

This is the clearest empirical argument against fixed tight stops.

## 6. Which config to actually use — decision tree

```
                       ┌─── exit at fixed target? ──── YES → Exp 5 (-1% / +3%)
                       │                                      30% WR, +0.21% net per trade
                       │                                      = the only fixed-target winner
exit logic? ───────────┤
                       │                                      ┌── T+10: v1 + T+10 (Exp 7)
                       │                                      │   +12.71 % L1 expectancy
                       └─── exit at MFE/trailing? ──── YES ──┤
                                                              │
                                                              └── T+20: v1 + T+20 (Exp 8)
                                                                  +15.61 % L1 expectancy
                                                                  (more capital lock-up)
```

**Default recommendation:** v1 setup-specific stops + T+10 time exit
+ no L1 cluster filter + bake in 15 bps slippage. This is the
configuration documented in TRADE.md v2 (the current version).

For tightest realisable edge: switch time exit to T+20 IF you can
afford the longer capital commitment per trade.

## 7. Risk-of-ruin perspective

Under 0.5 % equity risk per trade, P(5 consecutive losses) by win
rate:

| Exp | Win % | P(5 losses in row) | Implied frequency |
|---|---|---|---|
| 1 (v1) | 75.6 % | 0.08 % | ~once per 1 200 trades |
| 4 | 69.1 % | 0.24 % | ~once per 400 trades |
| 6 | 51.1 % | 3.1 % | once per 32 trades |
| 2 (v2) | 41.3 % | 7.2 % | once per 14 trades |
| 5 | 30.2 % | 17 % | once per 6 trades |

Translation: under Exp 5 (the "purest asymmetric" config), 5-in-a-row
losses are routine. Under v1 / T+10, they're rare. Both can be
profitable — the equity curve experience is very different. Live
trading psychology favours high-WR systems even when expectancy is
identical.

## 8. Files

```
common/data/simulate_trades.php          # v1 (original)
common/data/simulate_trades_v2.php       # v2 (-2 / +3 fixed)
common/data/simulate_trades_param.php    # exps 3-6 (--stop-pct, --success-pct, --label)
common/data/simulate_trades_v3.php       # exps 7-10 (--time-exit-hours, --cluster-filter, --slippage-bps, --label)
common/data/simulate_trades_v4.php       # exps 11-12 (--funding-mode, --wfcv-cutoff + v3 flags)

common/data/_simulation_report.json              # v1 raw
common/data/_simulation_report_v2.json           # v2 raw
common/data/_simulation_report_{3,4,5,6}.json    # exps 3-6
common/data/_simulation_report_{7_T10,8_T20,9_cluster,10_slip}.json
common/data/_simulation_report_{11_wfcv,12_livefunding}.json

docs/results/TRADE_SIMULATION_RESULT_{1,2,3,4,5,6}.md   # per-exp detailed reports
docs/results/TRADE_SIMULATION_NEXT_STEPS.md             # exps 7-10 combined
docs/results/TRADE_SIMULATION_WFCV_AND_LIVE_FUNDING.md  # exps 11-12 combined
docs/results/TRADE_SIMULATION_COMPARISON.md             # this file (master comparison)
```

To run any custom config:

```
# Stop / success variations:
php common/data/simulate_trades_param.php \
  --stop-pct=0.025 --success-pct=0.05 --label=custom

# Time / cluster / slippage / wfcv / funding variations:
php common/data/simulate_trades_v4.php \
  --time-exit-hours=240 --cluster-filter=0 --slippage-bps=15 \
  --funding-mode=mean --wfcv-cutoff=2025-01-01 --label=custom
```

## 9. Open questions for future experiments

The 12-experiment suite varied **risk / exit logic and analytical
knobs** but kept entry trigger thresholds constant. Three classes of
follow-up remain untested:

1. **Threshold walk-forward search.** Does L1 work better with
   `funding ≤ −0.0003` than `−0.0002`? Does L3 need
   `closed_high ≥ 0.90` rather than `0.85`? Each is a fitted
   hyperparameter from CRYPTO.md analysis.
2. **Per-symbol calibration.** TRADE.md's stops adapt to candle
   range but not to per-symbol volatility. A volatility-scaled stop
   (e.g., 1.5 × ATR(14)) might harmonise behaviour across high-vol
   and low-vol names.
3. **Multi-leg portfolio dynamics.** All 12 experiments evaluate
   each trade *independently*. The portfolio-level questions
   (correlation discount on cluster days, real fill capacity on
   illiquid alts, max-concurrent-position penalties) are unmodelled.

The first is the highest-value next step.
