# Trade Simulation — Experiment 6

**Config:** stop loss **−3 %**, success bar **+3 %** (symmetric, both
loose).

Re-run: `php common/data/simulate_trades_param.php --stop-pct=0.03 --success-pct=0.03 --label=6`
Raw report: `common/data/_simulation_report_6.json` (11.3 KB).

## 1. Coverage

127 symbols, 2 781 triggers, 2 438 fills (87.67 %).

## 2. Results by setup

| Setup | Fills | Wins | Losses | Win % | Mean MFE on wins | Mean loss | R-multiple | Expectancy |
|---|---|---|---|---|---|---|---|---|
| **L1** short-squeeze long      | 1 108 | 566 | 530 | **51.08 %** | +15.15 % | −3.00 % | 5.05 R | **+8.21 %** |
| **L3** momentum continuation    | 763   | 361 | 401 | 47.32 % | +16.69 % | −3.00 % | 5.56 R | **+8.42 %** |
| S3 volume-exhaustion short      | 297   | 153 | 144 | 51.52 % | +13.36 % | −2.91 % | 4.59 R | +7.57 % |
| S2 euphoria-trap short          | 181   | 89  | 92  | 49.17 % | +12.01 % | −2.91 % | 4.13 R | +6.39 % |
| S4 rejection short              | 59    | 36  | 23  | **61.02 %** | +14.29 % | −2.91 % | 4.91 R | +9.47 % |
| L2 capitulation reversal (small)| 21    | 6   | 15  | 28.57 % | +21.76 % | −3.00 % | 7.25 R | +6.37 % |
| **S1** crowded-long blowup (tiny)| 9    | **9** | 0   | **100 %**   | +23.98 % | n/a     | n/a    | +23.98 % |

**Key observations:**

- **All large-sample setups cluster near 50 % win rate.** Symmetric
  thresholds with realistic risk = roughly coin-flip outcome
  distribution.
- **R-multiple ≈ 5** across the board — the cleanest single number
  in any config. Every winner is ~5× the size of every loser.
- **Per-fill expectancy +6.4 % to +9.5 %** — same as Exp 4 (same
  stop). The success bar doesn't change MFE.
- **Realised P&L at target exit** (3 % target, 3 % stop):
  - L1: 0.51 × 3 % + 0.49 × −3 % = **+0.07 % per trade**
  - L3: 0.47 × 3 % + 0.53 × −3 % = **−0.16 % per trade** (slight loser
    at fixed target — barely)
  - S3: 0.52 × 3 % + 0.48 × −3 % = +0.10 %
  - Most setups barely break even at fixed +3 % target exits. The
    edge sits in MFE capture beyond the target.

## 3. Top 10 winning trades by MFE

| # | Date | Sym | Setup | MFE % |
|---|---|---|---|---|
| 1 | 2023-08-03 | YGG    | L3 | **+251.7 %** |
| 2 | 2026-04-19 | APE    | L1 | +176.0 %     |
| 3 | 2025-10-11 | YGG    | L1 | +139.3 %     |
| 4 | 2024-02-15 | WLD    | L3 | +127.9 %     |
| 5 | 2023-01-03 | LDO    | L1 | +118.6 %     |
| 6 | 2024-11-11 | XLM    | L3 | +110.1 %     |
| 7 | 2025-11-06 | FIL    | L1 | +108.9 %     |
| 8 | 2025-05-26 | TRB    | L3 | +101.6 %     |
| 9 | 2026-04-15 | BOME   | L3 | +98.4 %      |
| 10 | 2024-11-09 | TRB   | L3 | +93.4 %      |

Identical to Exp 4 (same stop). The wider −3 % stop catches APE,
YGG L1, and TRB L1 that Exp 5 / v2 lost to early shakeouts.

## 4. Best symbol-setup pairings (≥ 5 fills)

| Sym | Best setup | Fills | Win % | Mean MFE % |
|---|---|---|---|---|
| YGG  | L3 | 6  | 50.0 % | +45.0 % |
| TAO  | L1 | 6  | 83.3 % | +25.5 % |
| STG  | L3 | 5  | 100 %  | +19.8 % |
| ADA  | L3 | 6  | 66.7 % | +26.0 % |
| CKB  | L1 | 9  | 77.8 % | +19.4 % |

## 5. What this config is good for

- **Symmetric expectations.** Roughly 50/50 outcome with a tilted-up
  payoff distribution feels intuitive — most traders manage
  symmetric systems well.
- **Combining with trailing-stop exits.** The 3 % stop survives the
  initial pullback that often precedes the +5–20 % MFE; a trailing
  stop tightening past +3 % captures most of the excess.
- **Best balance between Exp 4 (loose) and Exp 5 (tight).** Same
  MFE capture as Exp 4 but with a higher success bar that filters
  out small intra-day moves.

## 6. What this config is bad for

- **Fixed-target exit traders.** At symmetric ±3 %, you need win
  rate strictly above 50 % to be net positive at target exits.
  L1 squeaks above (51.1 %) but L3 falls below (47.3 %) — too
  close to the line for confidence.
- **High-frequency systems.** Each trade ties up capital with a
  −3 % maximum loss for up to 5 days — slower turnover than Exp 3
  / Exp 5.
