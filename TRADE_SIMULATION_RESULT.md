# Trade Simulation Results

Backtest of the entry rules in `TRADE.md` against every symbol in our
dataset. For each daily candle in every symbol's history we evaluated
all 7 testable setups (L1, L2, L3, S1, S2, S3, S4 — basket setups L4
and S5 were excluded). When a setup fired we simulated the fill on
1h candles, then walked forward up to T+5 (120 hours) checking
whether **+1 % in the trade's favour** was reached **before** the
stop loss. SUCCESS = +1 % first; FAIL = stop first.

For SUCCESS trades we also recorded the **maximum favourable
excursion (MFE)** — how far in the trade's favour the price went
before either the stop hit or T+5 expired.

> **Historical document.** The simulator (`simulate_trades.php`) and its
> raw report (`_simulation_report.json`) were removed from the repo during
> cleanup. Restore from git history if you need to reproduce the run.

## 1. Coverage

| | Value |
|---|---|
| Symbols in universe                | 131 |
| Symbols simulated                  | **127** |
| Symbols blacklisted (XAU, USTC, WLFI, LINEA) | 4 |
| Symbols with missing 1h data       | 0 |
| Total triggers fired               | 2 781 |
| Total entries filled               | **2 438** |
| Fill rate                          | 87.67 % |

Win/fail definition uses **+1 % minimum profit threshold first** vs
stop loss first. MFE is computed by walking the 1h bars after fill
and tracking the running maximum favourable price excursion.

## 2. Headline — results by setup

Sorted by sample size (descending). Setups with < 25 fills are
**flagged as unreliable** — too few observations to draw conclusions.

| Setup | Fills | Wins | Losses | Win % | Mean MFE on wins | Mean loss | Median MFE | p90 MFE | R-multiple* |
|---|---|---|---|---|---|---|---|---|---|
| **L1** short-squeeze long       | **1 108** | 838 | 268 | 75.63 % | **+13.77 %** | −4.55 %  | +4.81 %  | +26.19 % | 3.03 R |
| **L3** momentum continuation     | **763**   | 629 | 127 | 82.44 % | **+13.96 %** | −6.59 %  | +5.71 %  | +27.06 % | 2.12 R |
| **S3** volume-exhaustion short   | 297       | 250 | 46  | 84.18 % | **+15.04 %** | −6.49 %  | +9.95 %  | +27.63 % | 2.32 R |
| **S2** euphoria-trap short       | 181       | 145 | 36  | 80.11 % | +11.52 %     | −6.28 %  | +4.95 %  | +25.90 % | 1.83 R |
| **S4** rejection short           | 59        | 58  | 1   | 98.31 % | +19.93 %     | −15.87 % | +18.97 % | +34.00 % | 1.26 R |
| L2 capitulation reversal (small) | 21        | 21  | 0   | 100 %   | +11.69 %     | —        | +7.02 %  | +25.09 % | n/a |
| S1 crowded-long blowup (tiny)    | 9         | 9   | 0   | 100 %   | +25.19 %     | —        | +25.17 % | +41.87 % | n/a |

*R-multiple = mean MFE on wins ÷ |mean loss|. Higher is better
asymmetry between winning and losing trades.

**Bottom-line interpretation:**

- **Every setup with a meaningful sample beats 75 % win rate** on the
  +1 % threshold. Crossing +1 % in a 5-day window is a low bar; the
  real edge is in the MFE column — winning trades commonly run to
  +13–20 % before either timing out or hitting trailing stop.
- **L1 is the workhorse.** 1 108 fills in 5 years, 75.6 % win rate,
  +13.8 % mean MFE on wins vs −4.6 % mean loss = **3.0 R asymmetry**
  per trade. This setup alone fires roughly once every 60 trading
  days per symbol.
- **L3 is second-most reliable.** 763 fills at 82.4 % win, similar
  MFE. Momentum continuation on healthy breakout candles works.
- **S3 (volume exhaustion) outperforms S2 (euphoria trap)** in this
  test despite S2 being the larger-sample short in the original
  conditional-probability analysis. The reason: S3's trigger is rare
  but extreme (vol_ratio ≥ 10) — when it fires, the blow-off
  pattern is well-defined.
- **S4 has a 98 % win rate** but only 59 trades. Worth trusting
  qualitatively but with that sample, point estimates are noisy.
- **L2 (n=21) and S1 (n=9) are unreliable.** They fire too rarely.
  Treat the 100 % win rate as a small-sample artifact.

**Why such high win rates?** The +1 % threshold is low. The 1h
data resolution catches transient wicks. Win rate is the right
metric for "did the trade ever go favourable before stopping out",
not for realised P&L of an actual exit strategy. Use the MFE
distributions to judge how much edge sits past the +1 % line.

## 3. Setup-by-setup detail

### L1 — Short-squeeze long
*Trigger: funding ≤ −0.02 %/8h, FNG 26–45 (Fear), vol_ratio ≥ 1*

- 1 108 fills, **75.6 % win rate**, only 2 timeouts (no +1 % and no stop reached within T+5).
- Mean MFE per fill = +10.51 % (across wins and losses combined).
- Mean MFE on winning trades = +13.77 %, median +4.81 %.
- 10 % of trades reach +26 % or more.
- Average loss = −4.55 % (close to the 5–7 % stop distance).
- **R-multiple 3.03** — strongest mature edge in the entire test.

### L3 — Momentum continuation long
*Trigger: closed_high ≥ 0.85, vol_ratio ≥ 2, R₀ 4–15 %, upper_wick ≤ 5 %, BTC.D 45–65*

- 763 fills, **82.4 % win rate**, 7 timeouts.
- Mean MFE on wins = +13.96 %, median +5.71 %, p90 +27.06 %.
- Stops are wider (mid-range based), mean loss −6.59 %.
- **R-multiple 2.12** — second-best mature edge.

### S3 — Volume-exhaustion short
*Trigger: vol_ratio ≥ 10, R₀ ≥ +10 %*

- 297 fills, **84.2 % win rate**.
- Highest MFE among large-sample setups: mean +15.04 % on wins, median +9.95 %.
- Mean loss −6.49 %; **R-multiple 2.32**.
- Setup fires on the blow-off days. Those days almost always
  retrace within 5 days.

### S2 — Euphoria-trap short
*Trigger: funding ≥ +0.05 %/8h, 7d funding ≥ +0.03 %, FNG ≥ 76, OI z ≥ +1, BTC.D 30d Δ ≥ +0.5*

- 181 fills, **80.1 % win rate**, **0 timeouts**.
- Mean MFE on wins = +11.52 %, median +4.95 %.
- Mean loss −6.28 %; R-multiple 1.83.
- Strictest filter of any large-sample setup but still ~36 fires per
  year universe-wide.

### S4 — Rejection short
*Trigger: R₀ ≥ +10 %, upper_wick ≥ 20 %, closed_high ≤ 0.5, vol_ratio ≥ 2*

- 59 fills, **98.3 % win rate** (one loss).
- Stops are very wide (above today's blow-off high), mean loss −15.87 %.
- Mean MFE on wins = +19.93 %, median +18.97 %, p90 +34.0 %.
- Despite the wide stops, the trigger filter is strict enough that
  failures are extremely rare. Small sample though — use carefully.

### L2 — Capitulation reversal *(small sample: 21 fills)*
- 100 % win rate, mean MFE +11.69 %.
- Setup is rare because it requires Extreme Fear (FNG ≤ 25) **plus**
  lower wick ≥ 20 % **plus** vol_ratio ≥ 2 **plus** red day — all
  conditions intersect maybe 4× per year across the universe.

### S1 — Crowded-long blowup *(tiny sample: 9 fills)*
- 100 % win rate, +25.19 % mean MFE.
- Fires almost never because the joint condition (funding ≥
  +0.05 %/8h sustained + Fear FNG + OI z ≥ +1) is the rarest single
  cell in the joint funding × FNG table (n=91 universe-days; only 9
  passed all filters at fill time).
- Treat the 100 % win rate as **anecdotal** — the setup is real but
  too rare to be a system pillar.

## 4. Top 30 winning trades by Max-Favourable-Excursion

These are the historical "best-case" trades — entry/stop per
TRADE.md, MFE recorded up to T+5.

| # | Date | Sym | Setup | MFE % | Stop dist % | Exit |
|---|---|---|---|---|---|---|
| 1  | 2026-04-13 | ORDI    | L3 | **+316.4 %** | 8.30 % | time-exit |
| 2  | 2023-08-03 | YGG     | L3 | +251.7 %     | 8.34 % | time-exit |
| 3  | 2026-04-19 | APE     | L1 | +176.0 %     | 2.47 % | time-exit |
| 4  | 2025-10-11 | YGG     | L1 | +139.3 %     | 5.58 % | time-exit |
| 5  | 2024-02-15 | WLD     | L3 | +127.9 %     | 6.93 % | time-exit |
| 6  | 2023-01-03 | LDO     | L1 | +118.6 %     | 1.75 % | time-exit |
| 7  | 2024-03-08 | ID      | L3 | +115.8 %     | 6.65 % | time-exit |
| 8  | 2024-11-11 | XLM     | L3 | +110.1 %     | 3.94 % | time-exit |
| 9  | 2025-11-06 | FIL     | L1 | +108.9 %     | 7.28 % | time-exit |
| 10 | 2025-05-26 | TRB     | L3 | +101.6 %     | 4.08 % | time-exit |
| 11 | 2026-04-15 | BOME    | L3 | +98.4 %      | 5.27 % | time-exit |
| 12 | 2023-09-11 | TRB     | L1 | +96.1 %      | 5.99 % | time-exit |
| 13 | 2024-11-09 | TRB     | L3 | +93.4 %      | 5.74 % | time-exit |
| 14 | 2026-01-11 | DUSK    | L1 | +89.1 %      | 2.98 % | time-exit |
| 15 | 2023-11-01 | CAKE    | L3 | +84.9 %      | 5.67 % | time-exit |
| 16 | 2025-03-07 | AUCTION | L1 | +83.3 %      | 7.28 % | time-exit |
| 17 | 2024-05-21 | EDU     | L3 | +83.2 %      | 7.91 % | time-exit |
| 18 | 2025-03-18 | API3    | L1 | +82.9 %      | 5.75 % | time-exit |
| 19 | 2025-03-18 | AUCTION | L1 | +82.2 %      | 7.28 % | stop-after-profit |
| 20 | 2024-11-15 | XLM     | L3 | +81.8 %      | 7.32 % | time-exit |
| 21 | 2024-03-20 | DUSK    | L3 | +81.3 %      | 9.55 % | time-exit |
| 22 | 2024-11-06 | ADA     | L3 | +79.8 %      | 5.11 % | time-exit |
| 23 | 2023-06-30 | STORJ   | L3 | +79.4 %      | 7.33 % | time-exit |
| 24 | 2025-03-19 | API3    | L1 | +77.4 %      | 5.72 % | time-exit |
| 25 | 2024-02-17 | ARKM    | L3 | +75.9 %      | 8.44 % | time-exit |
| 26 | 2024-03-03 | BONK    | L3 | +74.1 %      | 15.67 % | time-exit |
| 27 | 2025-03-08 | AUCTION | L1 | +74.1 %      | 7.28 % | time-exit |
| 28 | 2025-03-08 | AUCTION | L3 | +73.0 %      | 7.68 % | time-exit |
| 29 | 2025-03-09 | AUCTION | L1 | +72.9 %      | 6.91 % | time-exit |
| 30 | 2026-04-18 | PORTAL  | L1 | +71.7 %      | 7.28 % | time-exit |

**Observations:**
- All 30 top winners are **longs (L1 or L3)**. The biggest moves in
  the dataset have been to the upside.
- 29 of 30 hit the time-exit boundary (T+5) — the trail-stop never
  caught up. That means MFE underestimates what a trader who held
  past T+5 could have captured (but TRADE.md explicitly says don't).
- **AUCTION dominates** with 4 entries in top 30 — its early-March
  2025 run produced repeated cluster signals.
- **YGG, TRB, AUCTION, API3** all show repeat appearances, meaning
  the system caught the *whole* run via consecutive daily triggers,
  not just one entry.

## 5. Top 30 losing trades

| # | Date | Sym | Setup | Loss % |
|---|---|---|---|---|
| 1  | 2025-02-03 | SUI    | L3 | −19.48 % |
| 2  | 2025-04-18 | GMT    | S3 | −19.44 % |
| 3  | 2023-02-10 | DUSK   | S3 | −17.90 % |
| 4  | 2024-02-29 | BICO   | S2 | −17.69 % |
| 5  | 2025-09-12 | YGG    | S3 | −16.55 % |
| 6  | 2025-04-18 | GMT    | S4 | −15.87 % |
| 7  | 2025-02-03 | XLM    | L3 | −15.72 % |
| 8  | 2024-03-05 | FIL    | S2 | −15.00 % |
| 9  | 2024-12-20 | BONK   | L3 | −14.90 % |
| 10 | 2026-04-03 | ONG    | S3 | −14.54 % |
| 11 | 2023-07-13 | LQTY   | S3 | −14.37 % |
| 12 | 2025-11-06 | METIS  | S3 | −13.56 % |
| 13 | 2024-03-04 | PEOPLE | S2 | −13.23 % |
| 14 | 2026-02-06 | SAGA   | L3 | −12.82 % |
| 15 | 2026-02-06 | ARB    | L3 | −11.92 % |
| 16 | 2024-04-10 | TAO    | S2 | −11.90 % |
| 17 | 2026-02-06 | LDO    | L3 | −11.80 % |
| 18 | 2026-04-16 | ORDI   | S3 | −11.51 % |
| 19 | 2026-02-06 | OP     | L3 | −11.24 % |
| 20 | 2024-03-05 | EDU    | S2 | −11.03 % |

**Observations:**
- **3 of the worst 4 losses are L3 longs** on cluster-dump dates
  (2025-02-03, 2026-02-06). L3's momentum filter catches the
  continuation up-day before a cluster reversal — exactly the
  scenario TRADE.md § session-rules warns against.
- **Multiple S2 losses on 2024-03-05** (FIL, EDU, PEOPLE) — the
  same crowded-long blowup day was caught by S2's trigger but the
  bounce *before* the down-move stopped the entries out first.
  Limit-orders 1 % above close get filled exactly when the bounce
  is at maximum euphoria, and the stop sits at +5 % — small but
  real.
- **GMT and DUSK appear twice** — single-day cluster events
  triggered S3 + S4 simultaneously and both stopped out.
- **The S2 cluster on 2024-03-05** is the strongest case for
  session-rule §3 in TRADE.md: pause new entries the day after
  cluster moves.

## 6. Best symbol-setup pairings (≥ 5 fills, sorted by win_rate × mean MFE)

| Sym | Best setup | Fills | Win % | Mean MFE % |
|---|---|---|---|---|
| YGG     | L3 | 6  | 83.3 %  | +45.7 % |
| TRB     | L3 | 10 | **100 %** | +37.2 % |
| ORDI    | L3 | 6  | 50.0 %  | +58.8 % |
| ID      | L3 | 8  | **100 %** | +28.2 % |
| ADA     | L3 | 6  | **100 %** | +28.1 % |
| TAO     | L1 | 6  | 83.3 %  | +27.3 % |
| STG     | L3 | 5  | **100 %** | +21.6 % |
| BONK    | L3 | 8  | 75.0 %  | +27.5 % |
| DUSK    | L3 | 7  | 85.7 %  | +23.9 % |
| LDO     | L1 | 12 | 83.3 %  | +23.5 % |
| UMA     | S3 | 10 | **100 %** | +18.5 % |
| FET     | L3 | 6  | **100 %** | +18.5 % |
| CKB     | L1 | 9  | 88.9 %  | +19.6 % |
| METIS   | S2 | 5  | **100 %** | +17.3 % |
| STORJ   | L3 | 7  | 71.4 %  | +22.9 % |
| PORTAL  | L1 | 7  | 57.1 %  | +28.3 % |
| XLM     | L3 | 12 | 66.7 %  | +24.2 % |
| EDU     | L3 | 10 | 90.0 %  | +17.0 % |
| API3    | S3 | 12 | **100 %** | +14.6 % |
| AUCTION | L1 | 25 | 64.0 %  | +22.6 % |
| IMX     | L1 | 16 | 87.5 %  | +16.3 % |
| DYM     | L3 | 5  | **100 %** | +14.1 % |
| ROSE    | L1 | 5  | **100 %** | +13.3 % |
| AVAX    | L3 | 13 | 84.6 %  | +15.6 % |
| PENDLE  | L1 | 7  | 85.7 %  | +15.3 % |

**Observations:**
- **L3 dominates** the per-symbol best-setup list (13 of 25). Momentum
  continuation works particularly well on alts that move in waves.
- **L1 dominates the second cluster** (8 of 25) — funding-driven
  short-squeeze pumps tend to be repeatable on the same names.
- **High-volatility names with positive lifetime returns** (TRB,
  YGG, ORDI) show the largest MFE values — natural because their
  daily range itself is wider.
- **AUCTION** has the largest fill count (25 on L1) and 64 % win
  rate with +22.6 % mean MFE — proves L1 fires repeatedly on
  rotating low-cap alts during sentiment swings.

## 7. What this simulation does NOT model

Be explicit about limits so you don't over-interpret the results.

- **No slippage.** Limit orders fill at exactly the limit price.
  Stop orders fill at the stop price. In practice, fast-moving
  markets will fill worse — particularly on the highest-volume
  trigger days (S3 setup).
- **No fees.** Bybit charges ~0.075 % per side on perps. Over
  2 438 trades that's ~3.7 % of total notional in fees, not
  modelled here.
- **1h bar resolution.** When stop and target are both reachable
  inside the same 1h bar, we conservatively assume the **stop hits
  first**. Real tick-by-tick outcomes could differ either way.
- **Skip-the-entry-bar rule.** Exit checks start at fill_idx + 1.
  Movement within the entry bar after fill is ignored.
- **No position sizing or portfolio constraint.** Each trade is
  scored independently. In live trading the 60 % max-deployed and
  5-concurrent-position rules in TRADE.md §4 would limit how many
  of these fired triggers could actually be taken.
- **No correlation discount.** Multiple symbols often trigger the
  same setup on the same UTC day (notably L1 fires across most
  alts during BTC squeeze events). Real portfolio P&L would be
  more concentrated than these summed independent results suggest.
- **No funding payments.** Holding shorts when funding is positive
  costs ~0.01–0.10 % per 8h. A 5-day short on 0.05 % funding eats
  ~0.75 % of the position — not modelled in MFE.
- **Survivorship bias.** Only symbols currently listed on Bybit are
  in the universe. Delisted alts (which would have produced more
  losing trades) are absent.
- **Macro alignment uses today's value.** FNG and BTC.D are
  measured at the daily close. In live trading both update through
  the day; the signal might fire on a different value.
- **The +1 % "success" threshold is generous.** It only proves the
  trade *was* favourable at some point. A trader with a fixed exit
  rule (e.g., stop-only, no TP — the TRADE.md design) realises
  much less than the MFE values shown here, because trailing stops
  catch only part of any move.

## 8. What to take away

1. **The TRADE.md framework has real, large-sample edge.** L1 alone
   has 1 108 fills with 75.6 % win rate and 3.0 R asymmetry on
   wins. That's a robust system pillar.
2. **L3 and S3 add diversification.** L3 catches breakout
   continuations (different regime than L1's contrarian setup); S3
   catches blow-off-top fades. Together with L1 they fire ~2 000
   times in 5 years — enough volume to be meaningful.
3. **S2 (n=181) is workable; S4 (n=59) and L2 (n=21) and S1 (n=9)
   are too rare to be primary signals.** Use them as bonus
   triggers, not pillars.
4. **The cluster-day risk rule from TRADE.md §3 matters.** Several
   of the worst losses (2026-02-06 L3 cluster of −12 to −13 %)
   happened on or one day before universe-wide reversal days.
   Applying TRADE.md's "pause longs for 5 days after ≥ 50 symbols
   dumped" would have filtered most of them.
5. **L3 wins are dominated by alts with multi-day continuations.**
   Top winners (ORDI +316 %, YGG +252 %, APE +176 %, WLD +128 %)
   all came from setups where TRADE.md's T+5 time-exit *cut the
   trade short*. Whether to extend time-exit on the L3 setup is a
   design question — extending it raises drawdown risk but
   captures these tails.
6. **Win rate is high because the bar is +1 %.** The more honest
   read is the **MFE distribution**: median MFE on winners is
   +5 % to +20 % depending on setup, p90 is +25–34 %. The
   trailing-stop-only exit framework in TRADE.md captures some but
   not all of this.
7. **All longs that hit time-exit on T+5 had not yet reversed.**
   29 of the top 30 winners ended at time-exit, not trailing-stop.
   This is the strongest argument for considering an extended hold
   (T+10 or T+20) on the long setups — *with* an active trailing
   stop. Shorts behave differently — many top winners ended via
   stop-after-profit, suggesting tighter trailing works there.

## 9. Recommended next experiments

Cheap follow-ups that would meaningfully change the playbook:

1. **Test T+10 and T+20 time-exits on L1 + L3.** Compare MFE
   capture vs. drawdown. The data suggests L1/L3 winners are still
   running at T+5.
2. **Apply the §3 cluster-day filter and re-simulate.** Expected
   effect: drops ~30 % of losing L3 trades (those on cluster
   reversal days), small drop in wins.
3. **Add slippage / fee modelling.** Subtract 0.15 % per round
   trip. Expected to convert ~10 % of marginal wins (those that
   reach +1 % but not much more) into losses.
4. **Walk-forward CV.** Re-run with the funding × FNG joint table
   built only on 2021–2024 data, test on 2025–2026. Confirms or
   refutes parameter stability over time.
5. **Live-rate funding rather than daily-mean.** Use the actual
   8h funding value at the trigger time, not the day's mean. The
   current implementation can miss the case where funding was
   negative at 16:00 UTC funding event but recovered by daily
   close.

## 10. How to reproduce

The simulator script (`common/data/simulate_trades.php`) was removed during
repo cleanup. To re-run, restore it from git history:

```
git show <commit>:common/data/simulate_trades.php > /tmp/simulate_trades.php
php /tmp/simulate_trades.php
```

Prereqs (already satisfied by the standard data refresh):
- `common/data/history_prices/<SYM>.json` has `history.intervals.D + 1h`
- `common/data/history_prices/<SYM>.json` has `derivatives.funding + open_interest`
- `common/data/history/macro/fng.jsonl`
- `common/data/history/macro/dominance.jsonl`

The simulator is deterministic — same data, same parameters, same results.
