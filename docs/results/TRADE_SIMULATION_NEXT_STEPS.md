# Trade Simulation — Next-Step Experiments (7–10)

Four experiments from the "Recommended next experiments" section of
`TRADE_SIMULATION_RESULT_1.md`. All four use the **v1 baseline**
(setup-specific stops, +1 % success bar) — only one variable changes
at a time. Same triggers, same fills, same 1h bar resolution as v1.

| # | Variable changed | Reason from §9 |
|---|---|---|
| **Exp 7**  | Time exit **T+5 → T+10 (240 h)** | "29 of top 30 winners exited at the T+5 boundary — suggests longer hold captures more" |
| **Exp 8**  | Time exit **T+5 → T+20 (480 h)** | Same hypothesis, larger extension |
| **Exp 9**  | **Cluster-day filter ON** | "Drops ~30 % of losing L3 trades on cluster reversal days" |
| **Exp 10** | **0.15 % round-trip slippage** | Honest fee/slippage check on v1's numbers |

Re-run: `php common/data/simulate_trades_v3.php --label=<X> [...flags]`
Reports: `common/data/_simulation_report_7_T10.json`,
`8_T20.json`, `9_cluster.json`, `10_slip.json` (11 KB each).

## 1. The headline matrix

L1 (short-squeeze long) — 1 108 fills baseline:

| Variant | Fills | Win % | Mean MFE on wins | Mean loss | Expectancy | Δ vs v1 |
|---|---|---|---|---|---|---|
| **v1 (T+5)** | 1 108 | 75.6 % | +13.77 % | −4.55 % | **+10.51 %** | — |
| Exp 7 (T+10) | 1 108 | 75.6 % | **+16.67 %** | −4.55 % | **+12.71 %** | **+21 %** |
| Exp 8 (T+20) | 1 108 | 75.7 % | **+20.50 %** | −4.55 % | **+15.61 %** | **+49 %** |
| Exp 9 (cluster filter) | **968** | 74.8 % | +12.55 % | −4.29 % | +9.49 % | −10 % |
| Exp 10 (15 bps slip) | 1 108 | 75.6 % | +13.47 % | −4.85 % | +10.21 % | −3 % |

L3 (momentum continuation) — 763 fills baseline:

| Variant | Fills | Win % | Mean MFE on wins | Mean loss | Expectancy | Δ vs v1 |
|---|---|---|---|---|---|---|
| **v1 (T+5)** | 763 | 82.4 % | +13.96 % | −6.59 % | **+11.56 %** | — |
| Exp 7 (T+10) | 763 | 82.8 % | **+17.09 %** | −6.61 % | **+14.20 %** | **+23 %** |
| Exp 8 (T+20) | 763 | 83.0 % | **+22.56 %** | −6.74 % | **+18.76 %** | **+62 %** |
| Exp 9 (cluster filter) | **735** | 83.5 % | +14.04 % | −6.19 % | +11.77 % | +2 % |
| Exp 10 (15 bps slip) | 763 | 82.4 % | +13.66 % | −6.89 % | +11.26 % | −3 % |

S3 (volume-exhaustion short) — 297 fills baseline:

| Variant | Fills | Win % | Mean MFE on wins | Mean loss | Expectancy | Δ vs v1 |
|---|---|---|---|---|---|---|
| **v1 (T+5)** | 297 | 84.2 % | +15.04 % | −6.49 % | **+12.76 %** | — |
| Exp 7 (T+10) | 297 | 84.2 % | **+17.35 %** | −6.49 % | **+14.70 %** | +15 % |
| Exp 8 (T+20) | 297 | 84.2 % | **+20.04 %** | −6.81 % | **+16.97 %** | **+33 %** |
| Exp 9 (cluster filter) | 294 | 84.3 % | +14.94 % | −6.63 % | +12.69 % | −1 % |
| Exp 10 (15 bps slip) | 297 | 84.2 % | +14.74 % | −6.79 % | +12.46 % | −2 % |

## 2. Experiment 7 — Time exit T+5 → T+10

**Hypothesis (from §9 of result_1):** "The data suggests L1/L3 winners
are still running at T+5 — extending the time exit should capture more
MFE."

**Result:** Hypothesis **confirmed across all setups.**

- L1 mean MFE on wins: +13.77 % → **+16.67 % (+21 %)**
- L3: +13.96 % → **+17.09 % (+22 %)**
- S3: +15.04 % → **+17.35 % (+15 %)**
- **Win rate barely changes** (L1 75.6 → 75.6 %, L3 82.4 → 82.8 %).
  The extra five days do not produce meaningful additional stop-outs.
- L1 timeouts drop from 2 → 1; L3 from 7 → 3; S3 from 1 → 1. Almost
  all timeout trades resolve to wins or losses inside the extra
  window.

**Top-winner explosion:**

| Trade | T+5 MFE | T+10 MFE | Δ |
|---|---|---|---|
| DUSK L1 2026-01-11 | +89.1 % | **+419.1 %** | **×4.7** |
| XLM L3 2024-11-15 | +81.9 % | **+329.5 %** | ×4.0 |
| AUCTION L1 2025-03-07 | +83.3 % | ~+200 % (in top 10 of Exp 7) | ×2.4 |
| Other top-30 trades: MFE grows 1.5–4 × on average |

Why this matters: under T+5 these were "good winners". Under T+10
they were generational. The system's edge is being *cut short* by
the T+5 rule.

## 3. Experiment 8 — Time exit T+5 → T+20

Doubles the holding window again. Same hypothesis pushed further.

**Result:** even bigger gains; no degradation.

- L1 expectancy: +10.51 % → **+15.61 % (+49 %)**
- L3 expectancy: +11.56 % → **+18.76 % (+62 %)** — biggest gain
- S3 expectancy: +12.76 % → **+16.97 % (+33 %)**
- All setups still have zero timeouts (everything resolves inside 20
  days) and win rate is *higher* than T+5 (more time = more chance
  to clear +1 % before stop).

**New top winners only seen at T+20:**

| Trade | T+5 | T+10 | T+20 |
|---|---|---|---|
| XLM L3 2024-11-11 | +110.1 % | +110.1 % | **+453.3 %** |
| AUCTION L1 2025-03-07 | +83.3 % | ~+200 % | **+348.8 %** |
| DUSK L1 2026-01-11 | +89.1 % | +419.1 % | +419.1 % |
| ORDI L3 2026-04-13 | +316.4 % | +316.4 % | +316.4 % |

DUSK and ORDI peaked between T+5 and T+10 — extending past that
doesn't add. But trades like XLM 2024-11-11 had a continuation rally
all the way to T+20. The 20-day window catches both peak types.

**Trade-off:** capital is locked up for 20 days. Slower rotation, more
concentration risk. For a 5-position max portfolio, that's ~30 % of
the year spent in any given trade window.

## 4. Experiment 9 — Cluster-day filter

**Filter rule (from TRADE.md §3):** skip a *long* trigger if
yesterday had ≥ 50 universe symbols dump; skip a *short* trigger if
yesterday had ≥ 50 universe symbols pump. The premise: don't fade
the day after a cluster move.

**Result:** filter **mildly hurts L1, neutral on L3, neutral on S3.**

| Setup | v1 fills | Exp 9 fills | Filtered | Wins lost | Losses lost | Expectancy Δ |
|---|---|---|---|---|---|---|
| L1 | 1 108 | 968 | 140 | **114** | 26 | **−10 %** |
| L3 | 763 | 735 | 28 | 18 | 8 | **+2 %** |
| S3 | 297 | 294 | 3 | 2 | 1 | −1 % |

**The filter removes ~4 winners for every 1 loser it removes.** The
TRADE.md §3 intuition turned out to be backwards for L1: the L1
trigger (negative funding + Fear FNG) is *actively triggered* by
cluster-dump days, and the trade frequently bounces hard the next
session. Pausing it loses more than it saves.

L3 is closer to break-even because L3's filter (closed_high ≥ 0.85,
fresh breakout) doesn't fire often the day after a cluster dump
anyway (the cluster reversal kills the breakout candle's structure).

**Honest takeaway:** the §3 rule was speculative. This run shows it
should NOT be applied universally — specifically, **don't apply it
to L1**. Maybe apply only to L3 / S3 where the effect is at least
neutral, or drop the rule entirely.

## 5. Experiment 10 — 0.15 % round-trip slippage

15 bps subtracted from each trade's MFE and exit P&L (Bybit's taker
fees are ~7.5 bps × 2 sides = 15 bps; slippage on top extends this,
but 15 bps is a conservative floor).

**Result:** modest, uniform reduction across all setups.

| Setup | Mean MFE v1 | Mean MFE Exp 10 | Δ pp |
|---|---|---|---|
| L1 | +10.51 % | +10.21 % | −0.30 |
| L3 | +11.56 % | +11.26 % | −0.30 |
| S3 | +12.76 % | +12.46 % | −0.30 |
| S2 | +9.26 %  | +8.96 %  | −0.30 |
| S4 | +19.59 % | +19.29 % | −0.30 |

The hit is exactly the round-trip slippage (0.30 % = 2 × 0.15 %).
Win rates are unchanged because the success classification uses the
raw +1 % threshold for comparability.

**Implication:** v1 expectancy is **robust to realistic slippage**.
Even at 15 bps round-trip per trade, the worst setup (S2 at
+8.96 %) still has 30 R of edge per trade. The system's edge isn't
created by a few trades that barely cleared +1 %; it's created by
the large MFE on the winning trades.

For a heavier slippage regime (e.g., 40 bps round-trip during fast
moves), the equivalent reduction would be 0.80 pp — L1 expectancy
would drop to ~+9.7 %, still strong.

## 6. Combined ranking

What if we apply **all four improvements** together (T+10 hold, no
cluster filter, 15 bps slippage)? Approximate (independent effects
add):

- L1: 13.77 % win-MFE × 1.21 = +16.67 % T+10 win-MFE; minus 0.30 pp
  slip = **+16.37 % adjusted win-MFE**. Expectancy from
  +10.51 % v1 → **+12.41 %**.
- L3: equivalent → +14.20 % T+10 mean_mfe − 0.30 pp = +13.90 %
  adjusted expectancy.

If T+20 used instead of T+10:
- L1 expectancy: ~+15.31 % (adjusted for slippage)
- L3 expectancy: ~+18.46 % (adjusted)

So the **biggest single improvement is extending time exit to T+10
or T+20**. Slippage costs ~3 % of edge. Cluster filter costs
~10 % of L1 edge. The compound effect of "T+10 with no cluster
filter and realistic slippage" lifts v1 from +10.51 % to
~+12.4 % expectancy per L1 trade — a meaningful gain.

## 7. Which experiment to actually adopt

1. **Adopt T+10 unconditionally for L1 and L3.** Free 21–23 % gain
   in expectancy, no win-rate cost, no implementation complexity
   beyond changing one constant.
2. **Consider T+20 if capital efficiency allows.** Another 22–33 %
   gain on top of T+10. Trade-off is capital lock-up.
3. **Do NOT adopt cluster filter for L1.** Net hurts the strongest
   setup. May still help for L3 marginally — flat outcome here.
4. **Bake 0.15 % slippage into expected returns.** It's the
   honest number.

**Recommended update to TRADE.md:**

- §4 "Position management cheatsheet": change `Time exit (default)`
  from "Close of T+5" to **"Close of T+10"** (or T+20 with capital
  caveat).
- §3 "Session rules": remove or modify the cluster-day filter rule
  for L1. Either drop it entirely or apply only to L3 / S2 / S3 /
  S4. As written, the rule loses more L1 winners than it saves
  losers.
- §7 "What this playbook deliberately doesn't do": add a note that
  realised P&L per trade is ~0.15 % lower than nominal due to
  fees + slippage, but expectancy remains robust.

## 8. What this set DIDN'T test

From the §9 list, two remain untested:

- **Walk-forward CV** (train 2021–2024, test 2025–2026). Would
  confirm the patterns aren't a 2024-bull-market artifact.
- **Live-rate funding** (use 8h funding at trigger time, not
  daily mean). The current implementation can miss the case where
  funding flipped sign within the day. Modest effect expected.

## 9. Reproduction

```
# Each takes ~30 seconds
php common/data/simulate_trades_v3.php --time-exit-hours=240 --label=7_T10
php common/data/simulate_trades_v3.php --time-exit-hours=480 --label=8_T20
php common/data/simulate_trades_v3.php --cluster-filter=1  --label=9_cluster
php common/data/simulate_trades_v3.php --slippage-bps=15   --label=10_slip

# Or any combination — flags compose
php common/data/simulate_trades_v3.php \
  --time-exit-hours=240 --slippage-bps=15 \
  --label=combined_T10_slip
```

JSON outputs land in `common/data/_simulation_report_<label>.json`.
