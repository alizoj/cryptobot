# Multi-Timeframe Entry Analysis — Last 3 Months

**Generated:** 2026-05-27 · **Window:** last 90 days of daily closes
(~2026-02-16 → 2026-05-16) · **Universe:** 22 most-liquid symbols that have
15m data · **Entries measured:** 3,476.

Produced by `common/data/analyze_mtf_entries.php` from
`common/data/_mtf_entry_report.json`. 15m candles fetched by
`common/data/fetch_15m_recent.php` into `common/data/history_15m/`.

> This study answers: *which multi-timeframe (1w / 1d / 1h / 15m) entry
> patterns maximised take-profit over the last 3 months?* Take-profit is
> measured as **MFE (Maximum Favourable Excursion)** — the furthest price ran
> in the trade's favour within T+10 — consistent with `TRADE.md`'s no-fixed-TP
> philosophy (winners come from the right tail).

## Symbols

`ADA, ASTER, BNB, BTC, DASH, DOGE, ENA, ETH, HYPE, LINK, LTC, ONDO, SAGA, SOL,
STORJ, SUI, TAO, TON, TRX, XMR, XRP, ZEC`

## How to read the metrics

| Metric | Meaning |
|---|---|
| **MFE** | Mean max favourable excursion over T+10 (the upside the entry exposed you to) |
| **MAE** | Mean max adverse excursion (how far underwater it went first — the risk) |
| **edge** | `meanMFE / |meanMAE|` — reward-to-pain. **>2 = strongly asymmetric upside.** Direction-comparable. |
| **f5d** | Mean *close* return at +5 days (what holding actually pays, not just the wick) |
| **win5** | % of entries with a positive +5d return |

> **Why not rank on MFE alone?** Over a 10-day hold with no stop, *every* entry
> shows a large favourable wick — raw MFE mostly measures volatility, not entry
> quality. `edge` and `f5d` are the honest quality signals; MFE is the
> upside-potential headline.

---

## Headline findings

**1. The last 3 months were a long-favourable regime.** A *random* long entry
had edge **2.14** and +1.56% f5d (54.5% win); a random short had edge **0.47**
and **−0.64%** f5d. Shorting the broad tape lost money on average. Any short
edge had to come from fading specific counter-trend events, not from a
directional bias.

| Baseline (no filter) | n | MFE | MAE | edge | f5d | win5 |
|---|--:|--:|--:|--:|--:|--:|
| **long** | 1,738 | 13.54% | −6.32% | **2.14** | +1.56% | 54.5% |
| **short** | 1,738 | 6.32% | −13.54% | 0.47 | −0.64% | 45.3% |

**2. The weekly trend (1w) is the single biggest lever.** Longing only when the
weekly close was above its 10-week SMA *and* rising (`w_up`) lifted forward
return to **+3.16% (2× baseline)** at the **best edge ratio of any large bucket
(2.58)**, over a large sample (n=717). This is the core multi-timeframe result:
**the highest timeframe sets the bias; trade with it.**

**3. 15m timing adds precision, not edge.** Replacing a market-at-close fill
with a 15m momentum-timed fill (break of the prior 2h high) changed forward
return by essentially nothing across a large sample:

| 15m timing (weekly-aligned) | n | MFE | edge | f5d | win5 |
|---|--:|--:|--:|--:|--:|
| long — market at close | 717 | 15.47% | 2.58 | **+3.16%** | 55.9% |
| long — 15m breakout-timed | 716 | 15.45% | 2.55 | **+3.06%** | 56.1% |
| short — market at close | 619 | 6.79% | 0.50 | +0.20% | 46.8% |
| short — 15m breakdown-timed | 618 | 6.73% | 0.49 | +0.07% | 47.1% |

**Verdict:** use 15m/1h to *execute* a level cleanly (avoid chasing, get a
tighter stop), but don't expect it to generate alpha. The decision lives on the
1w + 1d timeframes.

---

## Best LONG patterns (ranked by forward +5d return, n ≥ 20)

| Pattern | n | MFE | MAE | edge | **f5d** | win5 |
|---|--:|--:|--:|--:|--:|--:|
| `w_up` + `d_pump` (4–20% up-day in weekly uptrend) | 80 | 22.34% | −8.02% | **2.79** | **+6.04%** | 48.8% |
| `w_up` + `d_closed_high` (close near day-high, weekly up) | 194 | 15.19% | −5.93% | 2.56 | **+4.22%** | 53.6% |
| `w_up` + `h1_up` (weekly + 1h both up) | 416 | 15.36% | −6.15% | 2.50 | +3.60% | 54.8% |
| `w_up` + `fng_fear` (buy fear *inside* a weekly uptrend) | 549 | 15.78% | −5.51% | **2.86** | +3.59% | 57.9% |
| `w_up` + `h1_rsi<40` (buy a 1h dip in a weekly uptrend) | 179 | 13.06% | −6.18% | 2.11 | +2.87% | 54.2% |
| `w_up` + `d_mom_up` (5-day momentum + weekly up) | 463 | 14.17% | −6.45% | 2.20 | +2.76% | 52.3% |

**Reading it:** every top long pattern is `w_up` **AND** a confirming
lower-timeframe condition. The two most *robust* (large n, edge ~2.6–2.9):

- **`w_up` + `fng_fear`** (n=549) — buy the dip while the weekly trend is up but
  sentiment is still fearful. Highest edge ratio (2.86), highest win rate
  (57.9%), large sample. The most dependable repeatable pattern in the window.
- **`w_up` + `d_closed_high`** (n=194) — momentum continuation: weekly up and
  today closed in the top of its range. Edge 2.56, f5d +4.22%.

The highest-f5d pattern, **`w_up` + `d_pump`** (+6.04%), is rarer (n=80) and
more volatile (lower win rate, fatter MAE) — it catches the explosive
continuations but with more chop.

## Best SHORT pattern (shorts were hard this quarter)

Almost every short condition had negative or zero f5d. The exceptions were
**counter-trend fades**, not trend-shorts:

| Pattern | n | MFE | MAE | edge | f5d | win5 |
|---|--:|--:|--:|--:|--:|--:|
| `w_down` + `d_pump` (fade a pump *against* a weekly downtrend) | 62 | 10.24% | −11.93% | 0.86 | **+5.41%** | **83.9%** |

An 83.9% win rate, but note the edge ratio is still <1 (the average move
against you is larger than the move for you) — so **tight risk control is
mandatory**. Shorting *with* the weekly trend (`w_down` alone) earned only
+0.20% f5d: not worth it this quarter.

> The strict `TRADE.md` volume-exhaustion / rejection shorts (S3/S4) fired only
> ~5 times in 90 days on this set — extremely profitable when they hit
> (f5d +33%, 100% win) but far too rare to be a strategy on their own here.

---

## The right tail — where "maximum take profit" actually came from

The largest MFE outcomes were concentrated in **two parabolic events**, which
is the whole point of not capping winners:

| Symbol | Day | MFE | MAE | f5d | Conditions present |
|---|---|--:|--:|--:|---|
| SAGA | 2026-05-04 → 05-10 | 240–291% | −0.1 to −6% | +7% to +143% | weekly turn + daily momentum + fear FNG |
| TON | 2026-04-27 → 05-01 | 120–123% | −0.7 to −1.6% | up to +85.6% | **`w_up` + `fng_fear`** |

- **TON, late April** is the textbook case of the best repeatable pattern:
  weekly uptrend + fearful sentiment → a clean dip-buy that ran 120%+ with
  almost no adverse excursion (MAE < −1.6%).
- **SAGA, early May** is a right-tail rocket: once it started, *every*
  subsequent day's entry caught continuation (that's why the top of the list is
  all SAGA on consecutive days). It illustrates why the playbook holds winners
  to a time/trailing exit instead of a fixed TP.

**View these interactively** (the dashboard chart renders client-side in your
browser; no external service needed):

```
/trading/chart?symbol=TON&side=long&asOf=2026-05-01
/trading/chart?symbol=SAGA&side=long&asOf=2026-05-07
```

Or push a candlestick image to Telegram: `./yii trading/test-chart TON`.

---

## What to actually do with this

A concrete, evidence-backed entry recipe for the **current regime** (re-check
when the weekly trend flips):

1. **Bias from 1w.** Only take longs when weekly close > 10-week SMA and rising
   (`w_up`). This one filter doubled forward returns. Stand aside or only fade
   pumps when the weekly trend is down.
2. **Setup from 1d.** Prefer `w_up` **+** one of: closed near the day-high
   (`closed_high ≥ 0.7`), a 4–20% pump day, or a fearful-FNG dip. These are the
   edge-2.5+ buckets.
3. **Trigger / execute from 1h–15m.** Use a 1h pullback (`RSI < 40`) or a 15m
   break of the prior 2h high to *place* the entry and set a tight stop — but
   don't wait for it to "confirm" edge; the edge is already in steps 1–2.
4. **No fixed TP.** Hold to a time exit (T+10) or trail a 1h/4h pivot, per
   `TRADE.md`. The right-tail (TON, SAGA) is where the profit is — capping it
   removes the edge.
5. **Shorts:** only as counter-trend fades of pumps in a weekly downtrend, with
   tight stops (edge < 1 — manage risk actively).

This **confirms and sharpens `TRADE.md`** rather than replacing it: the playbook's
statistical, no-fixed-TP, trend-aware approach is exactly what the data
rewards. The new, actionable addition is the **explicit weekly-trend filter**
and the finding that **lower timeframes are for execution, not for edge**.

## Limitations (read before trusting)

- **One regime.** 90 days is a single (long-favourable) regime. The weekly-up
  long edge will weaken or invert in a downtrend — re-run quarterly.
- **MFE has no execution cost.** It ignores the ~0.15% round-trip slippage
  `TRADE.md` bakes in, and assumes you could capture the favourable wick.
- **Right-tail dominated.** Mean MFE is inflated by SAGA/TON; medians and f5d
  are the sober numbers. Treat MFE as *potential*, f5d as *expectation*.
- **Liquid set only.** These 22 names; thinner alts behave differently.
- **Daily data ends 2026-05-16**; 15m extends to 05-27. Entries in the final
  days have a partial forward window (handled, but lighter weight).

## Reproduce

```powershell
# 1. fetch 15m for the liquid set (uses api.bytick.com mirror; ~30s)
php common/data/fetch_15m_recent.php --days=100

# 2. run the analysis (reads W/D/1h + 15m + FNG/dominance; no network; ~5s)
php common/data/analyze_mtf_entries.php
#   -> common/data/_mtf_entry_report.json  (full tables: condition_table,
#      top_combos_by_fwd5d, confluence, entry_timing_15m, top_40_entries_by_mfe)
```
