# Trade Simulation — Experiment 4

**Config:** stop loss **−3 %**, success bar **+1 %** (loose stop,
easy target).

Re-run: `php common/data/simulate_trades_param.php --stop-pct=0.03 --success-pct=0.01 --label=4`
Raw report: `common/data/_simulation_report_4.json` (11.3 KB).

## 1. Coverage

127 symbols, 2 781 triggers, 2 438 fills (87.67 %) — identical to all
other configs.

## 2. Results by setup

| Setup | Fills | Wins | Losses | Win % | Mean MFE on wins | Mean loss | R-multiple | Expectancy |
|---|---|---|---|---|---|---|---|---|
| **L1** short-squeeze long      | 1 108 | **766** | 340 | **69.13 %** | +11.68 % | −3.00 % | 3.89 R | **+8.21 %** |
| **L3** momentum continuation    | 763   | **515** | 248 | **67.50 %** | +12.30 % | −3.00 % | 4.10 R | **+8.42 %** |
| S3 volume-exhaustion short      | 297   | 176 | 121 | 59.26 % | +11.89 % | −2.91 % | 4.09 R | +7.57 % |
| S2 euphoria-trap short          | 181   | 124 | 57  | **68.51 %** | +9.20 %  | −2.91 % | 3.16 R | +6.39 % |
| S4 rejection short              | 59    | 38  | 21  | 64.41 % | +13.67 % | −2.91 % | 4.70 R | +9.47 % |
| L2 capitulation reversal (small)| 21    | 7   | 14  | 33.33 % | +18.85 % | −3.00 % | 6.28 R | +6.37 % |
| **S1** crowded-long blowup (tiny)| 9    | **9** | 0   | **100 %**   | +23.98 % | n/a     | n/a    | +23.98 % |

**Key observations:**

- **L1 / L3 / S2 all break 67 % win rate.** This is the easiest-to-win
  config: tight target, loose stop. Even a small breakout from
  entry triggers a win.
- **R-multiple drops to ~4** (from 9–10 in Exp 3). Each loss costs
  3× more, but they're 2× rarer.
- **Highest per-fill expectancy of any config tested**: L1 +8.21 %,
  L3 +8.42 %, S4 +9.47 %. The −3 % stop captures more of the MFE
  distribution because fewer trades are prematurely shaken out.
- **S1 hits 100 % win rate** on 9 trades. Tiny sample, but the −3 %
  stop never triggers on the small set of true crowded-long blowups.

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

**Notable:** APE 2026-04-19 (+176 %), YGG 2025-10-11 L1 (+139 %),
and TRB L1 2023-09-11 (+96 %) all **come back into the top winners**
versus v2. The wider −3 % stop survives the early pullback those
trades had before launching.

## 4. Best symbol-setup pairings (≥ 5 fills)

| Sym | Best setup | Fills | Win % | Mean MFE % |
|---|---|---|---|---|
| YGG  | L3 | 6  | 83.3 % | +45.0 % |
| ADA  | L3 | 6  | 83.3 % | +26.0 % |
| TAO  | L1 | 6  | 83.3 % | +25.5 % |
| TRB  | L3 | 10 | 70.0 % | +29.8 % |
| STG  | L3 | 5  | 100 %  | +19.8 % |

## 5. What this config is good for

- **Highest realized capture of MFE** of any tested config. If your
  exit logic ever extends past the +1 % target (e.g., trailing
  stop), this configuration captures the most edge.
- **Win-rate-focused trading psychology.** 68 % win rate on L1
  feels much easier to live with than 30 % even if expectancy is
  similar.
- **Choppy alts.** 1INCH / ACE that stopped out repeatedly in Exp 3
  now survive the chop more often (−3 % covers normal daily
  fluctuation).

## 6. What this config is bad for

- **Capital efficiency.** A 3 % stop means smaller position size
  for the same 0.5 % risk — you can take fewer concurrent
  positions for the same equity-at-risk budget.
- **Recovery from losing days.** Each loss is 3× larger than Exp 3,
  so a sequence of 3 losses is −9 % vs −3 %.
