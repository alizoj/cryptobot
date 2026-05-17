# Trade Simulation — Experiment 3

**Config:** stop loss **−1 %**, success bar **+1 %** (symmetric tight).
Same entry triggers and entry methods as v1; only the stop / success
thresholds change. T+5 time exit. 1h bar resolution. Conservative
"stop-first" rule.

Re-run: `php common/data/simulate_trades_param.php --stop-pct=0.01 --success-pct=0.01 --label=3`
Raw report: `common/data/_simulation_report_3.json` (11.3 KB).

## 1. Coverage

127 symbols simulated, 2 781 triggers, 2 438 fills (87.67 % fill rate)
— identical to v1/v2.

## 2. Results by setup

| Setup | Fills | Wins | Losses | Win % | Mean MFE on wins | Mean loss | R-multiple\* | Expectancy\** |
|---|---|---|---|---|---|---|---|---|
| **L1** short-squeeze long      | 1 108 | 496 | 612 | 44.77 % | +9.43 %  | −1.00 % | **9.43 R** | +4.56 % |
| **L3** momentum continuation    | 763   | 335 | 428 | 43.91 % | +10.39 % | −1.00 % | **10.39 R** | +4.89 % |
| S3 volume-exhaustion short      | 297   | 128 | 169 | 43.10 % | +11.69 % | −0.99 % | 11.81 R | +6.00 % |
| S2 euphoria-trap short          | 181   | 94  | 87  | 51.93 % | +7.51 %  | −0.99 % | 7.59 R  | +4.05 % |
| S4 rejection short              | 59    | 30  | 29  | 50.85 % | +14.08 % | −0.99 % | 14.22 R | +8.73 % |
| L2 capitulation reversal (small)| 21    | 4   | 17  | 19.05 % | +27.01 % | −1.00 % | n/a | +5.61 % |
| S1 crowded-long blowup (tiny)   | 9     | 6   | 3   | 66.67 % | +24.19 % | −0.99 % | 24.4 R | +16.13 % |

\* R-multiple = mean MFE on winning trades ÷ |mean loss|
\** Expectancy = mean MFE across all fills

**Key observations:**

- **L1 and L3 win rates drop into the mid-40s.** A 1 % stop is too
  tight even when the success bar matches it — normal 1h volatility
  is enough to stop out before +1 % is reached.
- **R-multiple per win is in the 9–14 range** for all large-sample
  setups. The asymmetry between winners (running to +9–14 % MFE
  even though they only needed +1 %) and losers (capped at −1 %) is
  the entire edge.
- **Per-trade expectancy +4.5–6 %** for the major setups. This is
  ~30 % lower than v2's expectancy because the −1 % stop shakes out
  many trades that would have run to +13 % MFE under v2's −2 %.

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

These same names appear in v1, v2, and Exp 4–6. The biggest moves
"survive" any reasonable stop because they're V-shaped breakouts
that never retraced. The trade-off between configs is whether the
shakier setups also survive.

## 4. Best symbol-setup pairings (≥ 5 fills, sorted by score)

| Sym | Best setup | Fills | Win % | Mean MFE % |
|---|---|---|---|---|
| ADA    | L3 | 6  | 66.7 % | +25.1 % |
| TAO    | L1 | 6  | 66.7 % | +22.8 % |
| YGG    | L3 | 6  | 33.3 % | +42.9 % |
| LDO    | L1 | 12 | 50.0 % | +18.4 % |
| CAKE   | L3 | 8  | 62.5 % | +14.5 % |

## 5. What this config is good for

- **Scalper-style fast-cycle trading.** A 1 % target and 1 % stop is
  fast in/fast out. Each completed trade resolves quickly.
- **Capital efficiency.** Tight stops mean small position-sizing
  for the same 0.5 % equity risk per trade → smaller notional
  exposure per opportunity.

## 6. What this config is bad for

- **Catching big moves.** Most top winners are L3 longs that ran
  to +50–250 % MFE — far past the +1 % target. Without a trailing
  exit, this system gives back the difference between target and
  MFE.
- **Choppy alts.** 1INCH and ACE produce repeated −1 % stops
  whenever L1/L3 triggers fire on them (13 stops on 1INCH alone).
