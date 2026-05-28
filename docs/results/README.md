# Strategy research archive

These markdown files are the historical research that backed the trading
rules in `TRADE.md` and the Experiment-8 parameters (T+20, v1 stops,
mean funding, S6 looser universe, etc.) used by the live bot.

> **About the references in these docs.** Each result file points at a
> script that produced it (`simulate_trades*.php`, `analyze_*.php`,
> `backtest_mtf_setups.php`, `fetch_15m_recent.php`) and a raw JSON
> report (`_simulation_report_*.json`, `_analysis_*.json`,
> `_backtest_mtf_report.json`, `_mtf_*.json`). Those scripts and reports
> were removed from the repo during the prod-cleanup pass — they're
> research artifacts that the live bot doesn't depend on, and keeping
> them in the deploy tree was noise.
>
> They're still in git history. To re-run any experiment:
>
> ```bash
> git log -- common/data/simulate_trades_v4.php       # find a commit that had it
> git show <commit>:common/data/simulate_trades_v4.php > simulate.php
> php simulate.php --label=<X> [flags]
> ```
>
> Same for `analyze_*.php` and `_*.json` reports.

## What's still live

The findings these docs describe are encoded into the running bot:

- **Setups L1–S4** — `common/services/Trading/SetupEvaluator.php`
- **MTF setups L5 / S6** — same file, evaluated at 4h boundaries
- **Sizing** (`equityUsd × riskPct / risk_per_unit × 1/beta`,
  capped by `maxNotionalPct`) — `common/services/Trading/PositionSizer.php`
- **Exits** (T+20, funding-flip, trailing on 4h pivots) —
  `common/services/Trading/PositionManager.php`
- **Strategy params** — `common/config/params.php` under the `trading`
  block, with UI overrides at `/trading` (equity, risk %, max-notional %).

If a quoted statistic in any of these result docs disagrees with what the
running bot does, the **code wins**.

## Reading order

For the strategy narrative, read in this order:

1. `TRADE_SIMULATION_RESULT_1.md` … `_6.md` — the v1 → v2 → param-sweep arc.
2. `TRADE_SIMULATION_NEXT_STEPS.md` — exps 7–10 (T+N, cluster filter, slippage).
3. `TRADE_SIMULATION_WFCV_AND_LIVE_FUNDING.md` — exps 11–12 (walk-forward CV,
   live funding).
4. `TRADE_SIMULATION_COMPARISON.md` — side-by-side of all 12 experiments,
   ending in the Experiment-8 choice.
5. `MTF_ENTRY_ANALYSIS.md`, `MTF_ENTRY_ANALYSIS_1H4H1D.md`,
   `MTF_SETUPS_BACKTEST.md` — the multi-timeframe L5/S6 additions.
