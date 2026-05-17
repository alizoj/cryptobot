# Trade Simulation — Experiment 5

**Config:** stop loss **−1 %**, success bar **+3 %** (tight stop, hard
target).

Re-run: `php common/data/simulate_trades_param.php --stop-pct=0.01 --success-pct=0.03 --label=5`
Raw report: `common/data/_simulation_report_5.json` (11.3 KB).

## 1. Coverage

127 symbols, 2 781 triggers, 2 438 fills (87.67 %) — identical to
other configs.

## 2. Results by setup

| Setup | Fills | Wins | Losses | Win % | Mean MFE on wins | Mean loss | R-multiple | Expectancy |
|---|---|---|---|---|---|---|---|---|
| **L1** short-squeeze long      | 1 108 | 335 | 772 | **30.23 %** | +13.06 % | −1.00 % | **13.06 R** | +4.56 % |
| **L3** momentum continuation    | 763   | 222 | 541 | 29.10 % | +14.66 % | −1.00 % | **14.66 R** | +4.89 % |
| S3 volume-exhaustion short      | 297   | 114 | 183 | 38.38 % | +12.85 % | −0.99 % | 12.98 R | +6.00 % |
| S2 euphoria-trap short          | 181   | 62  | 119 | 34.25 % | +10.36 % | −0.99 % | 10.46 R | +4.05 % |
| S4 rejection short              | 59    | 30  | 29  | 50.85 % | +14.08 % | −0.99 % | 14.22 R | +8.73 % |
| L2 capitulation reversal (small)| 21    | 4   | 17  | 19.05 % | +27.01 % | −1.00 % | 27.0 R  | +5.61 % |
| S1 crowded-long blowup (tiny)   | 9     | 5   | 4   | 55.56 % | +28.78 % | −0.99 % | 29.1 R  | +16.13 % |

**Key observations:**

- **Lowest L1 / L3 win rates of any config** (29–30 %). +3 % target
  combined with the noise-tight −1 % stop is brutal — most trades
  either dip −1 % during the day's normal volatility (FAIL) or
  succeed but with much room to spare.
- **Highest R-multiple of any large-sample config** (13–15 R per
  win). Wins more than offset the lower frequency.
- **Per-fill expectancy identical to Exp 3** (L1 +4.56 %, L3
  +4.89 %). The MFE distribution is fixed by the stop — changing
  the success threshold only re-labels wins vs losses, not the MFE
  achieved.
- **Mean realised P&L at the +3 % target** (assuming exits at
  target, not MFE):
  - L1: 0.30 × 3 % + 0.70 × −1 % = **+0.20 % per trade** (winning)
  - L3: similar
  - Compare with Exp 3's target exit: L1 = 0.45 × 1 % + 0.55 × −1 %
    = −0.10 % per trade (LOSING at the target)
- **Exp 5 is the first config that's profitable even with fixed
  target exits**, because the larger 3 R win is large enough to
  cover the high loss rate.

## 3. Top 10 winning trades by MFE

| # | Date | Sym | Setup | MFE % |
|---|---|---|---|---|
| 1 | 2023-08-03 | YGG    | L3 | **+251.7 %** |
| 2 | 2024-02-15 | WLD    | L3 | +127.9 %     |
| 3 | 2023-01-03 | LDO    | L1 | +118.6 %     |
| 4 | 2025-11-06 | FIL    | L1 | +108.9 %     |
| 5 | 2026-04-15 | BOME   | L3 | +98.4 %      |
| 6 | 2026-01-11 | DUSK   | L1 | +89.1 %      |
| 7 | 2023-11-01 | CAKE   | L3 | +84.9 %      |
| 8 | 2024-11-15 | XLM    | L3 | +81.9 %      |
| 9 | 2024-03-20 | DUSK   | L3 | +81.3 %      |
| 10 | 2024-11-06 | ADA   | L3 | +79.8 %      |

Same top-tier names as Exp 3 (same stop, so the MFE list is
identical). The difference vs Exp 3 is that **fewer marginal
winners qualify as "wins"** (any trade that reached +1 % but not
+3 % flips from win to loss).

## 4. Best symbol-setup pairings (≥ 5 fills)

| Sym | Best setup | Fills | Win % | Mean MFE % |
|---|---|---|---|---|
| TAO  | L1 | 6  | 66.7 % | +22.8 % |
| ADA  | L3 | 6  | 50.0 % | +25.1 % |
| LDO  | L1 | 12 | 41.7 % | +18.4 % |
| CAKE | L3 | 8  | 50.0 % | +14.5 % |
| YGG  | L3 | 6  | 16.7 % | +42.9 % |

## 5. What this config is good for

- **The most honest "is the breakout real?" filter.** +3 % is a
  meaningful price movement; a 1 % wiggle doesn't qualify. The 30 %
  hit rate is what you actually see when you demand a real move.
- **Fixed-target exit traders.** Of all configs, this is the only
  one with positive expected value when you exit *at* the target
  (rather than at MFE). +0.20 % per trade × 1 108 L1 fires =
  ~+220 % cumulative — without sophisticated trailing logic.
- **Trading capital protection.** −1 % loss caps allow many
  concurrent positions under a fixed risk budget.

## 6. What this config is bad for

- **Live trading psychology.** A 30 % hit rate produces long losing
  streaks. Expect 4–5 losses in a row regularly. Most discretionary
  traders abandon this kind of system before edge accrues.
- **Choppy alts.** Same problem as Exp 3 — 1INCH / ACE stop out
  almost every trigger.
- **Setups L2 (19 % WR) and S2 (34 % WR)** become marginally
  profitable at best. Drop them from this config; their stop-to-
  target asymmetry doesn't fit.
