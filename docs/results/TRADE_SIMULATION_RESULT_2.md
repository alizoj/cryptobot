# Trade Simulation — Experiment 2

**Configuration changes from Experiment 1:**

| Parameter | v1 | **v2 (this run)** |
|---|---|---|
| Stop loss               | Setup-specific (5–15 %) | **Fixed −2 % from fill** |
| Success threshold (MFE) | +1 %                    | **+3 %** |
| Entry triggers          | TRADE.md L1/L2/L3/S1/S2/S3/S4 | unchanged |
| Entry methods           | TRADE.md formulas       | unchanged |
| Time exit               | T+5 (120 h)             | unchanged |
| 1h bar resolution       | conservative "stop-first" | unchanged |

So this is the same set of entry triggers and entry orders, but with
**tight uniform risk management** (−2 % per trade) and a **higher
success bar** (+3 %).

Re-run: `php common/data/simulate_trades_v2.php`
Raw report: `common/data/_simulation_report_v2.json` (15.8 KB).

## 1. Coverage (identical to v1)

| | Value |
|---|---|
| Symbols simulated     | 127 (4 blacklisted) |
| Triggers fired        | 2 781 |
| Entries filled        | 2 438 |
| Fill rate             | 87.67 % |

Entry triggers and fills are unchanged — the only differences are
the stop-loss override and the higher success bar.

## 2. Results by setup

Sorted by sample size (descending). Setups with < 25 fills are
flagged as **small sample / unreliable**.

| Setup | Fills | Wins | Losses | Win % | Mean MFE on wins | Mean loss | R-multiple* | Expectancy** |
|---|---|---|---|---|---|---|---|---|
| **L1** short-squeeze long      | **1 108** | 457 | 647 | 41.25 % | +13.75 % | −2.00 % | **6.88 R** | **+6.19 %** |
| **L3** momentum continuation    | **763**   | 298 | 465 | 39.06 % | +16.12 % | −2.00 % | **8.06 R** | **+6.85 %** |
| **S3** volume-exhaustion short  | 297       | 137 | 160 | 46.13 % | +13.03 % | −1.96 % | 6.65 R     | +6.81 % |
| **S2** euphoria-trap short      | 181       | 77  | 104 | 42.54 % | +11.77 % | −1.96 % | 6.01 R     | +5.51 % |
| **S4** rejection short          | 59        | 32  | 27  | **54.24 %** | +14.63 % | −1.96 % | 7.46 R | +9.19 % |
| L2 capitulation reversal (small) | 21       | 4   | 17  | 19.05 % | +27.01 % | −2.00 % | n/a (small) | +5.61 % |
| S1 crowded-long blowup (tiny)    | 9        | 7   | 2   | 77.78 % | +23.16 % | −1.96 % | 11.82 R     | +18.15 % |

\* R-multiple = mean MFE on winning trades ÷ |mean loss|.
\** Expectancy = mean MFE across **all** fills (wins, losses, timeouts).

**Headline:** win rates collapse versus v1 (75–98 % → 39–54 %) because
+3 % needs a real move while −2 % gets shaken out by ordinary 1h
noise. But the **R-multiple roughly triples** for every setup, and the
**per-fill expectancy stays strongly positive** (+5.5 % to +9.2 %).

## 3. Comparison to Experiment 1

| Setup | v1 Win % | v2 Win % | v1 R | v2 R | v2 / v1 R | Same direction? |
|---|---|---|---|---|---|---|
| L1 | 75.6 % | 41.3 % | 3.03 R | 6.88 R | **2.27×** | Better R, worse hit rate |
| L3 | 82.4 % | 39.1 % | 2.12 R | 8.06 R | **3.80×** | Better R, worse hit rate |
| S3 | 84.2 % | 46.1 % | 2.32 R | 6.65 R | 2.87× | Better R, worse hit rate |
| S2 | 80.1 % | 42.5 % | 1.83 R | 6.01 R | 3.28× | Better R, worse hit rate |
| S4 | 98.3 % | 54.2 % | 1.26 R | 7.46 R | **5.92×** | Better R, worse hit rate |

**The tight stop pays.** Even with win rate cut roughly in half, every
setup has a higher R-multiple per trade. The +3 % bar also filters
out the marginal moves that barely cleared +1 % in v1.

**Practical implication:** v2 is a much choppier system to live with
(more losses than wins on L1/L3/S2/S3) but each win is several times
larger than each loss. v1 felt like a high-win-rate scalp; v2 feels
like an asymmetric breakout system.

**Cumulative arithmetic on L1 alone (1 108 fills, expectancy basis):**

- v1: 1 108 × (+10.5 % mean MFE) = ~+11 600 % total MFE
- v2: 1 108 × (+6.19 % mean MFE) = ~+6 860 % total MFE

So v2 has lower **gross** MFE accumulation (because more shaken-out
trades drag the mean down), but the loss per trade is bounded at
−2 % vs −4.55 % — much easier on real-world risk management.

## 4. Top 30 winning trades (by MFE)

These are essentially the **same top winners as v1** — moves that
went straight enough to never dip −2 % below entry before running.

| # | Date | Sym | Setup | MFE % | Exit |
|---|---|---|---|---|---|
| 1  | 2023-08-03 | YGG    | L3 | **+251.7 %** | time-exit |
| 2  | 2024-02-15 | WLD    | L3 | +127.9 %     | time-exit |
| 3  | 2023-01-03 | LDO    | L1 | +118.6 %     | time-exit |
| 4  | 2024-11-11 | XLM    | L3 | +110.1 %     | time-exit |
| 5  | 2025-11-06 | FIL    | L1 | +108.9 %     | time-exit |
| 6  | 2025-05-26 | TRB    | L3 | +101.6 %     | time-exit |
| 7  | 2026-04-15 | BOME   | L3 | +98.4 %      | time-exit |
| 8  | 2026-01-11 | DUSK   | L1 | +89.1 %      | time-exit |
| 9  | 2023-11-01 | CAKE   | L3 | +84.9 %      | time-exit |
| 10 | 2024-05-21 | EDU    | L3 | +83.2 %      | time-exit |
| 11 | 2025-03-18 | API3   | L1 | +82.9 %      | time-exit |
| 12 | 2024-11-15 | XLM    | L3 | +81.9 %      | time-exit |
| 13 | 2024-03-20 | DUSK   | L3 | +81.3 %      | time-exit |
| 14 | 2024-11-06 | ADA    | L3 | +79.8 %      | time-exit |
| 15 | 2023-06-30 | STORJ  | L3 | +79.4 %      | time-exit |
| 16 | 2024-02-17 | ARKM   | L3 | +75.9 %      | time-exit |
| 17 | 2024-03-03 | BONK   | L3 | +74.1 %      | stop_after_profit |
| 18 | 2024-10-10 | ENA    | L1 | +70.5 %      | time-exit |
| 19 | 2025-07-10 | XLM    | L3 | +68.5 %      | time-exit |
| 20 | 2024-11-06 | STG    | L3 | +64.9 %      | time-exit |
| 21 | 2026-04-19 | PORTAL | L1 | +63.2 %      | stop_after_profit |
| 22 | 2024-11-07 | ADA    | L3 | +61.8 %      | time-exit |
| 23 | 2023-11-10 | AVAX   | L3 | +61.8 %      | time-exit |
| 24 | 2025-10-11 | TAO    | L1 | +61.1 %      | time-exit |
| 25 | 2022-11-02 | AR     | L1 | +59.9 %      | time-exit |
| 26 | 2026-04-19 | HIGH   | L1 | +55.9 %      | stop_after_profit |
| 27 | 2025-04-10 | JASMY  | L1 | +55.8 %      | time-exit |
| 28 | 2025-03-23 | ZETA   | L1 | +54.2 %      | time-exit |
| 29 | 2024-02-17 | FET    | L3 | +52.9 %      | time-exit |
| 30 | 2026-03-17 | ENJ    | L1 | +51.6 %      | time-exit |

**Notable v1 winners that DROPPED OUT of the v2 top-30** (got
shaken out by the tight stop before running):

| v1 trade | v1 MFE | v2 outcome |
|---|---|---|
| ORDI L3 2026-04-13 (#1 v1) | +316.4 % | **Stopped at −2 %** before launching |
| APE L1 2026-04-19 (#3 v1)  | +176.0 % | **Stopped at −2 %** |
| YGG L1 2025-10-11 (#4 v1)  | +139.3 % | **Stopped at −2 %** |
| ID L3 2024-03-08 (#7 v1)   | +115.8 % | **Stopped at −2 %** |
| TRB L1 2023-09-11 (#12 v1) | +96.1 %  | **Stopped at −2 %** |
| AUCTION L1 cluster (×4 v1) | +72–83 % | All **stopped at −2 %** |

This is the most important finding from v2: **moonshots that took
their time to break out got killed by the tight stop**. The top-30
v2 winners are the cleanest, most-V-shaped breakouts only.

## 5. Top 30 losing trades

Every loss is exactly −2 % (the fixed stop). There are too many to
rank by magnitude — they're all identical. Instead, the list shows
**symbols that produced the highest loss frequency**:

| Symbol | Losses in dataset | Note |
|---|---|---|
| 1INCH   | 14 losses | Highest stop-out symbol; choppy with frequent triggers |
| ACE     | 7 losses  | Mid-cap chop |
| ACH     | 9+ losses | Similar pattern |
| (many more) | — | Long tail of single-stop-out trades |

**Diagnostic:** the worst-performing symbols on −2 % stops are the
*choppy* names where price wiggles ±2–4 % daily without trending.
The fixed stop converts every false start into a loss. Larger
setup-specific stops in v1 absorbed this noise.

## 6. Best symbol-setup pairings (≥ 5 fills)

| Sym | Best setup | Fills | Win % | Mean MFE % |
|---|---|---|---|---|
| TAO    | L1 | 6  | 83.3 % | +25.5 % |
| ADA    | L3 | 6  | 66.7 % | +26.0 % |
| STG    | L3 | 5  | 80.0 % | +18.6 % |
| YGG    | L3 | 6  | 33.3 % | +43.9 % |
| LDO    | L1 | 12 | 66.7 % | +20.4 % |
| UNI    | L1 | 5  | **100 %** | +12.2 % |
| XLM    | L3 | 12 | 50.0 % | +24.1 % |
| ID     | L3 | 8  | 75.0 % | +12.6 % |
| CKB    | L1 | 9  | 55.6 % | +14.8 % |
| TRB    | L3 | 10 | 40.0 % | +20.4 % |
| HIGH   | L1 | 7  | 71.4 % | +11.1 % |
| CAKE   | L3 | 8  | 50.0 % | +14.6 % |
| FET    | L3 | 6  | 50.0 % | +13.5 % |
| DUSK   | L3 | 7  | 42.9 % | +15.6 % |
| STORJ  | L1 | 14 | 71.4 % | +9.1 % |
| AUCTION| S3 | 6  | 50.0 % | +13.0 % |
| EDU    | L3 | 10 | 50.0 % | +12.9 % |
| BONK   | L3 | 8  | 37.5 % | +17.2 % |
| SSV    | L3 | 5  | 60.0 % | +10.7 % |
| IMX    | L3 | 6  | 66.7 % | +8.9 % |
| ARKM   | L3 | 8  | 37.5 % | +14.6 % |
| DOGE   | L3 | 7  | 57.1 % | +9.5 % |
| DOT    | L1 | 14 | 64.3 % | +8.4 % |
| PENDLE | L1 | 7  | 71.4 % | +7.5 % |
| LSK    | L1 | 11 | 54.5 % | +9.3 % |

**Notable rotation vs v1:**

- **AUCTION** dropped from #20 in v1 (L1 best, 64 % WR, +22.6 % MFE)
  to #16 in v2 (best setup is now **S3**, not L1). Its L1 trades
  shake out at −2 % much more often than they ran in v1.
- **YGG** stays top-tier but win rate craters (83 % → 33 %) — the
  one mega-winner still drags the mean MFE up.
- **STORJ** moves into the top 25 — quieter symbol where the −2 %
  stop survives normal daily noise.

## 7. Pattern observations

1. **Setups where the original stop was TIGHT in v1 (L1's was often
   2–5 %) suffer least.** Setups where the original stop was WIDE
   (S3's "above blow-off high" was sometimes 20–40 %, S4's was
   "+15 % mean loss") suffer most under v2's tight rule — except S4,
   which actually *improves* its R-multiple from 1.26 to 7.46 R.
2. **L3 has the biggest R-multiple gain** (2.12 → 8.06, **3.80×**)
   because its v1 stop was based on day's mid-range — wide on
   high-volatility breakout candles. The −2 % cap dramatically
   improves loss size.
3. **The "best winners" don't change much.** YGG, WLD, LDO, XLM,
   FIL, TRB, BOME — same names, same dates. These were clean
   breakouts that never pulled back −2 % even briefly.
4. **The "best losers" do change.** v1's worst losses (SUI −19 %,
   GMT −19 %, DUSK −18 % from giant stops) become uniform −2 %
   losses in v2. Fewer single-trade blowups but many more small
   chops.
5. **L2 win rate drops to 19 %.** Capitulation reversal trades by
   definition enter when price has just dumped — a 2 % bounce
   then re-test of the low is exactly the kind of wiggle that
   triggers a −2 % stop. v1's L2 stop was set 1 % below the
   wick low — much further away.
6. **S4 win rate stays above 50 %** with the tightest R-multiple
   improvement of any short. Its rejection candles tend to fade
   quickly without rallying back through the day's mid-range.

## 8. Practical takeaways

1. **−2 % is too tight for L1 and L3 on choppy assets** (1INCH, ACE,
   ACH all get stopped repeatedly). A liquidity/cleanness filter
   would help — only run v2-style stops on assets with
   reasonable trend behaviour.
2. **−2 % works on clean breakout names.** TAO L1 (83 % WR, +25 %
   MFE), UNI L1 (100 % WR), DOT L1 (64 % WR) — when the asset
   moves cleanly, the tight stop is a feature not a bug.
3. **Use −2 % universally only if you have a per-asset filter.**
   Otherwise v1's setup-specific stops actually fit better — they
   adapt to the day's volatility.
4. **The +3 % success bar is more honest than +1 %.** It separates
   real moves from intra-day wiggles. Combine with this with a
   trailing-stop exit to harvest the upside past +3 %.
5. **System choice depends on temperament.** v1 = high win rate
   (75 %+), small reward per trade. v2 = low win rate (40 %), large
   reward per win. Same gross expectancy in the same ballpark, very
   different drawdown profile.

## 9. Cumulative R-equivalent comparison

If every trade is sized to risk 1 R (= 2 % of equity, since stop is
2 %), the cumulative R-equivalent across all fills is:

| Setup | v2 fills | Wins × MFE/R(=2%) | Losses × −1 R | Net R |
|---|---|---|---|---|
| L1 | 1 108 | 457 × 6.88 = +3 144 R | 647 × −1 R = −647 R | **+2 497 R** |
| L3 | 763   | 298 × 8.06 = +2 402 R | 465 × −1 R = −465 R | **+1 937 R** |
| S3 | 297   | 137 × 6.51 = +892 R  | 160 × −1 R = −160 R | +732 R |
| S2 | 181   | 77 × 5.88 = +452 R   | 104 × −1 R = −104 R | +348 R |
| S4 | 59    | 32 × 7.31 = +234 R   | 27 × −1 R = −27 R   | +207 R |

L1 and L3 together produce roughly **+4 400 R of theoretical
edge** over 5 years if you assume MFE = realised gain (which it
isn't — see caveats in v1 §7). With a realistic 30–50 % capture
rate of MFE via trailing stops, that's still hundreds of R of
realized edge per setup.

## 10. Recommended next experiments

1. **Walk-forward halve.** Train MFE distributions on 2021–2024,
   test on 2025–2026. Confirms v2 parameters aren't overfit to
   the test period.
2. **−3 % stop / +5 % success bar.** Compare to v1 and v2. Looking
   for the sweet spot — is the noise filter at −3 % much better
   than −2 %?
3. **Trailing-stop exit modelling.** Replace time-exit with a
   trailing-stop-to-prior-4h-pivot rule (as TRADE.md actually
   prescribes). Compare realised P&L to current MFE-based score.
4. **Symbol-class filter.** Exclude high-chop assets (1INCH, ACE,
   ACH) by a daily ATR vs ATR-of-BTC ratio test. Re-run v2 to
   see if the L1/L3 win rate recovers.
5. **Risk-of-ruin / equity curve simulation.** With v1's higher
   win rate vs v2's higher R, which has the lower probability of
   30 % drawdown? Important for live trading psychology.

## 11. How to reproduce

```
php common/data/simulate_trades_v2.php
# Output: common/data/_simulation_report_v2.json
```

To compare directly to v1, use the original
`common/data/simulate_trades.php` against the same dataset.
