# Trade Simulation — Walk-Forward CV + Live Funding

The remaining two items from §9 of `TRADE_SIMULATION_RESULT_1.md` (and
§8 of `TRADE_SIMULATION_NEXT_STEPS.md`):

| # | Experiment | Question answered |
|---|---|---|
| **Exp 11** | Walk-forward CV — split aggregates at **2025-01-01** | Does the v1 edge hold out-of-sample, or is it a 2024-bull-market artifact? |
| **Exp 12** | Live-rate funding — use the **last 8h funding event of the day** instead of the daily mean | Does the more-recent funding value at decision time improve the L1/S1/S2 triggers? |

Both runs use the v1 baseline (setup-specific stops, +1 % success bar,
T+5 time exit, no cluster filter, no slippage) so results are directly
comparable to `TRADE_SIMULATION_RESULT_1.md`.

Re-run: `php common/data/simulate_trades_v4.php --label=<X> --funding-mode=mean|live`
Reports: `_simulation_report_11_wfcv.json` (12 KB), `_simulation_report_12_livefunding.json` (12 KB).

## 1. Experiment 11 — Walk-forward CV

The v1 baseline run, with aggregates split between events with
`date < 2025-01-01` (in-sample, IS) and `date >= 2025-01-01`
(out-of-sample, OOS). No retraining is needed — TRADE.md triggers
are hard rules, not fitted parameters; the test is consistency.

### Sample sizes per period

| Setup | All fills | In-sample fills | Out-of-sample fills | OOS share |
|---|---|---|---|---|
| L1 | 1 108 | 595 | 513 | 46 % |
| L3 | 763   | 472 | 291 | 38 % |
| S3 | 297   | 148 | 149 | **50 %** |
| S2 | 181   | 181 | **0** | **0 %** |
| S4 | 59    | 25  | 34  | 58 % |
| L2 | 21    | 20  | 1   | 5 % |
| S1 | 9     | 9   | 0   | 0 % |

**Three observations from the sample split:**

- **S2 stopped firing in 2025–2026.** Its trigger requires Extreme
  Greed FNG ≥ 76 **and** BTC.D 30-day Δ ≥ +0.5. That joint condition
  hasn't been hit since Jan 2025 — Extreme Greed regimes have been
  rare in the 2025–2026 sample.
- **S1 also stopped firing.** Same family of conditions; even smaller
  sample. The "crowded-long blowup short" setup is effectively
  dormant in the new regime.
- **L2 fires almost never anywhere** (1 OOS), confirming small-sample
  unreliability flagged in result_1.

### Per-setup performance, in-sample vs out-of-sample

| Setup | Period | Fills | Win % | Mean MFE on wins | Mean loss | Expectancy |
|---|---|---|---|---|---|---|
| **L1** | IS  | 595 | 77.8 % | +12.27 % | −4.06 % | +9.65 % |
| **L1** | OOS | 513 | **73.1 %** | **+15.62 %** | −5.00 % | **+11.51 %** |
| L1 | Δ | — | **−4.7 pp** | **+3.35 pp** | −0.94 pp | **+1.86 pp** |
| **L3** | IS  | 472 | 84.3 % | +14.29 % | −6.34 % | +12.09 % |
| **L3** | OOS | 291 | **79.4 %** | +13.41 % | −6.94 % | +10.69 % |
| L3 | Δ | — | **−4.9 pp** | −0.88 pp | −0.60 pp | −1.40 pp |
| **S3** | IS  | 148 | 85.8 % | +13.07 % | −6.41 % | +11.28 % |
| **S3** | OOS | 149 | **82.5 %** | **+17.07 %** | −6.56 % | **+14.22 %** |
| S3 | Δ | — | **−3.3 pp** | **+4.00 pp** | −0.15 pp | **+2.94 pp** |
| S4 | IS  | 25  | 100 %    | +18.33 % | —        | +18.33 % |
| S4 | OOS | 34  | 97.1 %   | +21.14 % | −15.87 % | +20.52 % |

**The headline finding: the system did NOT degrade out-of-sample.**

- **L1 win rate dropped 4.7 pp** (77.8 → 73.1 %), **but mean MFE on
  wins increased 3.4 pp** (+12.27 → +15.62 %). The winning trades got
  bigger; the losers got slightly worse. Net expectancy *improved*
  from +9.65 % to +11.51 %.
- **L3 had the only meaningful degradation**: win rate down 4.9 pp,
  expectancy down 1.4 pp. Still strongly profitable but the OOS
  period is harsher for momentum-continuation setups (consistent
  with the cluster-dump regime in 2025-10-10, 2026-02-05 captured
  by CRYPTO.md §6).
- **S3 was actually stronger OOS** than IS, in both win rate and MFE.
  The volume-exhaustion pattern transfers cleanly.
- **S4 stayed elite** at 97 % WR with +20.5 % mean MFE OOS — small
  sample but consistent.

### What this implies

The v1 framework is **not a 2021–2024 bull-market artifact**. The
expectancies, win rates, and per-setup orderings hold up in the
2025–2026 sample. The one *real* warning is that two setups (S1, S2)
have effectively gone dormant — their triggering conditions just
don't occur often enough in the recent FNG / BTC.D regime to be
relied on.

**Recommendations for TRADE.md:**

- Treat **L1, L3, S3, S4** as battle-tested across two distinct
  regimes (2021–2024 bull/bear cycle and 2025–2026 BTC-led grind).
- Treat **S1, S2** as conditional setups that only activate during
  Extreme Greed periods. Don't expect them to fire monthly; expect
  them at sentiment tops only.
- Treat **L2** as small-sample throughout — keep it in the playbook
  but don't bank on it.

## 2. Experiment 12 — Live-rate funding vs daily-mean

The v1 baseline `funding₀` is the mean of the day's 3 × 8h funding
events. Live-rate uses the **latest** event of the day instead (i.e.
the 16:00 UTC rate, which is what's "live" at the daily close at
00:00 UTC of next day).

Hypothesis: the live value should be more responsive to intraday
funding flips and improve trigger quality.

### Trigger count comparison

| Setup | Mean-funding fills | Live-funding fills | Δ |
|---|---|---|---|
| L1 | 1 108 | **1 135** | **+27 (+2.4 %)** |
| S1 | 9     | 9         | — |
| S2 | 181   | 185       | +4 |
| L3 | 763   | 763       | — (no funding dep) |
| S3 | 297   | 297       | — |
| S4 | 59    | 59        | — |

Live funding **catches 27 additional L1 triggers** — days where the
16:00 UTC rate was negative (≤ −0.02 %) even though the daily mean
wasn't. So the hypothesis is mechanically correct: live funding does
expose more candidate trades.

### Did the new triggers add edge?

| Setup | Mean-funding mean_mfe | Live-funding mean_mfe | Δ |
|---|---|---|---|
| L1 | +10.51 % | +10.05 % | **−0.46 pp** |
| S2 | +9.26 %  | +9.41 %  | +0.15 pp |
| S1 | +25.19 % | +27.25 % | +2.06 pp (n=9) |

**L1 expectancy went DOWN** when using live funding, even though more
trades fired. The 27 extra trades the live-rate filter picked up are
on average *worse* than the daily-mean filter's trades — by enough
to drag the mean down 0.46 pp.

Mechanistically this makes sense. The 16:00 UTC funding rate
reflects positioning at one specific moment; the daily mean
integrates across the trading day. A transient negative funding
event at 16:00 UTC can be reverted by 00:00 UTC, leaving you to
trigger on a phantom squeeze that wasn't sustained.

### Per-period split (live funding)

| Setup | Period | Fills | WR | Mean MFE on wins | Expectancy |
|---|---|---|---|---|---|
| L1 | IS  | 625 | 78.1 % | +12.97 % | +10.22 % |
| L1 | OOS | 510 | 72.5 % | +13.42 % | +9.83 % |
| L3 | IS  | 472 | 84.3 % | +14.29 % | +12.09 % |
| L3 | OOS | 291 | 79.4 % | +13.41 % | +10.69 % |

OOS pattern holds: live funding produces slightly worse numbers OOS
too. Live funding is not the right modification.

### What this implies

**Stick with daily-mean funding.** The intuition that "more recent
data = better signal" is wrong here. Crypto funding is noisy at the
8h-event level; averaging the day smooths the noise and tracks the
*regime* of funding, which is what the L1 setup is designed to
exploit.

If you wanted to test live funding further, the right next move
would be to filter by *consistency* — only fire L1 if **all 3 of
today's funding events are ≤ −0.02 %**, not just the mean. That's
strictly stricter than the mean rule and would test whether the
issue is "any negative funding triggers spurious" or "we need
persistent negative funding."

## 3. Combined view across all 12 experiments

| # | Variable | L1 Win % | L1 Mean MFE | L1 Expectancy |
|---|---|---|---|---|
| 1 (v1) | baseline | 75.6 % | +13.77 % | +10.51 % |
| 2 (v2) | −2 % stop / +3 % success | 41.3 % | +13.75 % | +6.19 % |
| 3 | −1 % stop / +1 % success | 44.8 % | +9.43 %  | +4.56 % |
| 4 | −3 % stop / +1 % success | 69.1 % | +11.68 % | +8.21 % |
| 5 | −1 % stop / +3 % success | 30.2 % | +13.06 % | +4.56 % |
| 6 | −3 % stop / +3 % success | 51.1 % | +15.15 % | +8.21 % |
| **7**  | **T+10 time exit**                | 75.6 %      | **+16.67 %**    | **+12.71 %** |
| **8**  | **T+20 time exit**                | 75.7 %      | **+20.50 %**    | **+15.61 %** |
| 9   | Cluster-day filter        | 74.8 %      | +12.55 %    | +9.49 %     |
| 10  | 0.15 % slippage           | 75.6 %      | +13.47 %    | +10.21 %    |
| **11 IS**  | v1 baseline 2021–2024    | 77.8 %      | +12.27 %    | +9.65 %     |
| **11 OOS** | v1 baseline 2025–2026    | 73.1 %      | **+15.62 %**| **+11.51 %**|
| 12  | Live-rate funding         | 75.6 %      | +13.16 %    | +10.05 %    |

The **strongest single improvement** in the whole suite of 12 is
still **extending the time exit from T+5 to T+10 (Exp 7)**: +21 %
expectancy gain at zero cost.

The **strongest negative result** is that the **cluster-day filter
hurts L1** (Exp 9): the heuristic from TRADE.md §3 is wrong for L1
specifically.

The **most reassuring result** is **walk-forward stability**: the
2025–2026 OOS data actually outperforms the 2021–2024 in-sample
data for L1 (+11.51 % vs +9.65 %).

## 4. Final recommendations for TRADE.md

After all 12 experiments:

1. **Change default time exit from T+5 to T+10.** Confirmed by
   Exps 7 + 8. +21–49 % expectancy gain at zero win-rate cost.
2. **Drop the cluster-day filter from L1.** Confirmed by Exp 9.
   Filter loses 4 winners for every loser saved.
3. **Keep daily-mean funding, NOT live funding.** Confirmed by Exp 12.
   Live funding adds noisy triggers that dilute edge.
4. **Bake in 0.15 % slippage** as the honest realised number
   (Exp 10).
5. **Mark S1 and S2 as conditional setups** that only fire in
   Extreme Greed regimes. Confirmed by Exp 11 — both setups have
   zero or near-zero out-of-sample fills.
6. **L1, L3, S3, S4 are validated across two distinct regimes**
   (Exp 11). Promote these from "candidate" to "core" setups.

## 5. The single biggest remaining unknown

All 12 experiments hold **entry triggers constant** at the TRADE.md
specs and only vary risk/exit logic and analytical knobs. The
biggest open question — never tested — is whether **trigger
thresholds themselves can be improved**: e.g., does L1 fire better
with `funding ≤ −0.0003` instead of `−0.0002`? Does L3 need
`closed_high ≥ 0.90` for higher hit-rate? Each threshold is a fitted
hyperparameter from the original analysis; walk-forward testing
*those* parameters would be the next-next experiment beyond what
this set covered.

## 6. How to reproduce

```
# Walk-forward CV (v1 baseline, split at 2025-01-01)
php common/data/simulate_trades_v4.php --funding-mode=mean --label=11_wfcv

# Live-rate funding
php common/data/simulate_trades_v4.php --funding-mode=live --label=12_livefunding

# Both also support all v3 flags:
php common/data/simulate_trades_v4.php \
  --funding-mode=live --time-exit-hours=240 --slippage-bps=15 \
  --wfcv-cutoff=2025-06-01 --label=custom
```
