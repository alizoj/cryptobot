# Multi-Timeframe Entry Analysis — 1d / 4h / 1h (Last 3 Months)

**Generated:** 2026-05-27 · **Window:** last 90 days · **Universe:** same 22
liquid symbols as the 1w study · **Entries measured:** 10,340 (6× the daily
study — one decision per **4h close**).

Produced by `common/data/analyze_mtf_1h4h1d.php` →
`common/data/_mtf_1h4h1d_report.json`. Companion to
[`MTF_ENTRY_ANALYSIS.md`](MTF_ENTRY_ANALYSIS.md) (which used 1w/1d/1h/15m).
No 15m and no network — 1h/4h are already stored for every symbol.

**Timeframe roles in this study:**

| TF | Role |
|---|---|
| **1d** | trend **bias** — last fully-closed daily candle vs its SMA20 + 5-day momentum |
| **4h** | **setup / structure** — the just-closed 4h bar and its features |
| **1h** | entry **timing** + the forward MFE/MAE walk resolution (T+10 = 240 bars) |

Metrics are identical to the companion study: **MFE** (max favourable
excursion = the take-profit upside), **MAE** (max adverse excursion = risk),
**edge** = `meanMFE/|meanMAE|` (>2 = asymmetric upside), **f5d** (mean +5-day
close return), **win5** (% positive at +5d).

---

## Headline findings

**1. Same regime read — longs favoured, shorts lose on average.** Consistent
with the weekly study, confirming it isn't a sampling artefact.

| Baseline (any entry) | n | MFE | MAE | edge | f5d | win5 |
|---|--:|--:|--:|--:|--:|--:|
| **long** | 10,340 | 13.19% | −6.03% | **2.19** | +1.48% | 53.6% |
| **short** | 10,340 | 6.03% | −13.19% | 0.46 | −0.63% | 46.2% |

**2. The key reversal: at the *daily* timeframe, MEAN-REVERSION beat
MOMENTUM.** This is the opposite of the weekly result, and it's the most
important finding here:

| Single condition | n | MFE | MAE | edge | f5d | win5 |
|---|--:|--:|--:|--:|--:|--:|
| **long `d_down`** (buy a daily pullback: close < SMA20 & falling) | 3,125 | 14.53% | −5.08% | **2.86** | **+2.40%** | **63.1%** |
| long `d_up` (buy daily momentum: close > SMA20 & rising) | 4,757 | 11.94% | −6.89% | 1.73 | +0.60% | 46.6% |

Buying *into daily weakness* (a dip) won decisively over buying daily strength:
**4× the forward return, far higher win rate (63% vs 47%), and the best
edge ratio of any large bucket (2.86).**

> **This is not a contradiction of the weekly study — it's the timeframe
> hierarchy.** See *Reconciling the two studies* below.

**3. 1h timing adds a sliver for longs; still no real alpha.** Replacing a
market-at-4h-close fill with a 1h breakout-timed fill nudged forward return
+0.25% and win rate +2.3pts — small, and the opposite (neutral) was true for
15m. Use 1h to execute, not to find edge.

| 1h timing (daily-aligned) | n | edge | f5d | win5 |
|---|--:|--:|--:|--:|
| long — market at 4h close | 4,757 | 1.73 | +0.60% | 46.6% |
| long — 1h breakout-timed | 3,846 | 1.76 | **+0.85%** | 48.9% |
| short — market at 4h close | 3,125 | 0.35 | −1.75% | 36.8% |
| short — 1h breakdown-timed | 2,467 | 0.36 | −1.58% | 37.2% |

---

## Best LONG patterns (ranked by forward +5d, n ≥ 30)

Every top long pattern is **a daily pullback (`d_down`) confirmed by a
lower-timeframe oversold reading** — textbook buy-the-dip:

| Pattern | n | MFE | MAE | edge | **f5d** | win5 |
|---|--:|--:|--:|--:|--:|--:|
| `d_down` + `h4_rsi<40` (daily dip + 4h oversold) | 1,213 | 14.08% | −4.86% | **2.90** | **+2.89%** | **66.2%** |
| `d_down` + `h1_rsi<40` (daily dip + 1h oversold) | 988 | 14.05% | −4.88% | 2.88 | +2.84% | 66.5% |
| `d_down` + `fng_fear` (daily dip + fearful sentiment) | 2,987 | 14.01% | −5.21% | 2.69 | +2.23% | 62.0% |
| `h4_closed_high` + `h1_rsi<40` (4h closing strong off a 1h dip) | 436 | 14.26% | −5.30% | 2.69 | +2.03% | 58.9% |
| `d_down` + `h1_up` (daily dip, 1h already turning up) | 1,126 | 14.53% | −5.37% | 2.70 | +1.96% | 59.1% |
| `d_down` + `h4_mom_up` (daily dip, 4h momentum turning) | 1,376 | 14.14% | −5.25% | 2.69 | +1.90% | 61.0% |

**Most dependable:** `d_down` + `h4_rsi<40` / `h1_rsi<40` — edge ~2.9, win 66%,
1,000+ samples each. Wait for the daily to pull back, then enter when the 4h or
1h RSI is oversold and turning. Tight MAE (≈ −4.9%) → tight stops are viable.

## The 4h momentum breakout — big MFE, low hit-rate

The 4h "blow-off continuation" setup (`vol_spike` + `closed_high` + 4h pump)
has the largest MFE of anything but a low win rate — a right-tail lottery, not
a bread-and-butter trade:

| Confluence | n | MFE | edge | f5d | win5 |
|---|--:|--:|--:|--:|--:|
| 4h setup only | 112 | 28.38% | 2.87 | +1.76% | 37.5% |
| 4h setup + daily-aligned | 75 | 28.59% | 2.74 | +1.53% | 36.0% |
| 4h setup + daily + 1h-timed | 60 | 29.47% | 2.28 | +0.24% | 40.0% |

Note adding 1h timing *lowered* f5d (+1.76 → +0.24) — again, lower-TF timing
isn't where the edge is. With a 37% hit rate this is only viable with strict
risk sizing and a willingness to let the rare winner run (it printed 28% mean
MFE).

## Best SHORT patterns (shorts were hard)

Only **counter-trend fades** were positive; shorting *with* the trend lost
money (`short d_down` f5d −1.75%, `short d_below_sma20` −2.08%):

| Pattern | n | edge | f5d | win5 |
|---|--:|--:|--:|--:|
| `d_up` + `h4_pump` (fade a 4h pump while daily trend is up) | 175 | 0.50 | **+3.91%** | 61.1% |
| `d_up` + `h4_dump` (fade into an up-trend's 4h flush) | 130 | 0.60 | +3.05% | 57.7% |
| `h4_dump` + `h4_rsi>60` (exhaustion fade) | 46 | 0.76 | +5.46% | 56.5% |

All have edge < 1 (the adverse move is larger than the favourable one), so
these only work with **active, tight risk management** — they are scalp fades,
not swing shorts.

## Right tail — where the max take-profit came from

Same as the weekly study: **SAGA, early May** dominates. Because we now sample
every 4h bar, *every* 4h candle during SAGA's run shows ~285–291% MFE — the
move was so strong that entry timing barely mattered once it started.

```
/trading/chart?symbol=SAGA&side=long&asOf=2026-05-05
```

---

## Full-universe results (`--all` — 116 symbols, 109,040 entries)

Re-running across the whole universe (not just the liquid 22) keeps the long
story and **uncovers a strong short edge the majors were hiding.**

**Baseline is more balanced** than the liquid majors — the broad alt tape was
less one-sidedly bullish:

| Baseline | n | MFE | edge | f5d | win5 |
|---|--:|--:|--:|--:|--:|
| long | 54,520 | 14.84% | 2.03 | +0.92% | 50.0% |
| short | 54,520 | 7.29% | 0.49 | −0.03% | 49.8% |

**The dip-buy long holds — and produces the biggest winners in the dataset.**
Every one of the top-12 entries by MFE is the same pattern firing right before a
bounce:

| Symbol | When | MFE | f5d | Conditions |
|---|---|--:|--:|---|
| ENJ | 2026-04-07 | ~445% | +102% | `d_down` + `h4_rsi<40` + `h1_rsi<40` + `fng_fear` |
| HIGH | 2026-04-14→16 | ~430% | +163% to +238% | `d_down` + `h4_rsi<40` + `fng_fear` |

`long d_down` stays the best large-n long (n=19,446, edge 2.40, f5d +1.73%,
54% win) — milder than on the liquid 22 (which had more parabolic alts) but
robust at scale. `long d_up` momentum is **dead** universe-wide (f5d −0.02%).

**NEW — rejection / blow-off shorts are a real edge across the alt universe**
(they were too rare to see on the liquid 22). Fade 4h candles that pumped and
left an upper wick:

| Short pattern | n | f5d | win5 | edge |
|---|--:|--:|--:|--:|
| `d_up` + `h4_upper_wick` | 79 | **+15.15%** | **73.4%** | 0.69 |
| `h4_upper_wick` + `h4_rsi>60` | 81 | +12.87% | 67.9% | 0.58 |
| `h4_pump` + `h4_upper_wick` | 49 | +11.94% | 73.5% | 0.59 |
| `h4_pump` + `h4_rsi>60` | **1,360** | +3.80% | 63.5% | 0.58 |
| `d_up` + `h4_pump` (fade a pump in an up-day) | **1,115** | +4.43% | 64.0% | 0.61 |

The 4h blow-off **rejection-short setup** confirms it cleanly: n=40, f5d
**+12.13%, 77.5% win**. And the mirror image is the warning — the *long* 4h
breakout setup is **negative** universe-wide (f5d −0.96%, 37% win). **Don't
chase 4h pumps long; fade them short.**

> **Critical caveat on these shorts:** edge ratio is < 1 (MAE −20% to −33%) —
> blow-off candles have enormous ranges, so they spike hard against you before
> resolving. The forward return and win rate are strongly positive, but this
> demands **wide stops and small size** — exactly what `TRADE.md` S3 prescribes.

**1h timing: now flat-out neutral** universe-wide (long open f5d −0.02% vs
1h-timed +0.00%). The sliver of help seen on the liquid 22 disappears at scale —
final confirmation that lower-timeframe timing is execution, not edge.

## Reconciling the studies (important)

The two analyses look contradictory but are not — they describe **different
rungs of the timeframe ladder**:

| Study | Trend TF | What won | Interpretation |
|---|---|---|---|
| 1w/1d/1h/15m | **weekly** | trend-**following** (`w_up`) | Follow the *regime* |
| 1d/4h/1h | **daily** | mean-**reversion** (`d_down`) | Buy *pullbacks* within it |

Put together they form **one coherent strategy**:

> **In a weekly uptrend, buy daily/4h/1h oversold pullbacks.**
> The high timeframe (weekly) sets the directional bias; the lower timeframes
> (daily dip + 4h/1h oversold RSI) time the entry *against* the short-term move.

That is the classic "buy the dip in a bull market," and both studies
independently point to it from opposite directions. The weekly study found the
regime filter; this study found the pullback-entry trigger.

**Combined recipe (current regime — re-check when the weekly trend flips):**

1. **Regime (1w):** only long while the weekly is up (`w_up`).
2. **Pullback (1d):** wait for a daily dip — close back below SMA20 / a red
   stretch (`d_down`).
3. **Trigger (4h/1h):** enter when 4h or 1h RSI < 40 and turning, ideally with
   fearful FNG. (`d_down` + `h4_rsi<40` was edge 2.90, 66% win.)
4. **Execute (1h):** a 1h breakout of the prior 6h high gives a marginally
   better fill — use it for the stop placement, not as the edge.
5. **No fixed TP:** time/trailing exit per `TRADE.md`; the right tail (SAGA) is
   the profit.
6. **Shorts:** fade 4h blow-offs — a pump that prints an upper wick
   (`h4_pump` + `h4_upper_wick` / `h4_rsi>60`). Universe-wide this is the
   standout short (f5d +4% to +15%, 64–77% win). Use **wide stops + small
   size** (edge < 1: blow-off ranges are huge). Never trend-short.

## Limitations

- One regime (90 days, long-favourable). The `d_down` buy-the-dip edge depends
  on the weekly uptrend holding; in a real downtrend, dip-buying bleeds.
- 4h decision points inflate sample counts but adjacent 4h bars are
  autocorrelated (the SAGA run is ~40 correlated entries, not 40 independent
  ones) — treat n as effective-smaller for the parabolic names.
- MFE ignores the ~0.15% round-trip slippage `TRADE.md` bakes in.
- Both scopes covered: the liquid-22 tables above and the full 116-symbol
  `--all` run (see *Full-universe results*). The rejection-short edge is an
  alt-universe phenomenon — it does not show on the majors.

## Reproduce

```powershell
# 1d/4h/1h study (no fetch needed — 1h/4h already stored; ~10s)
php common/data/analyze_mtf_1h4h1d.php           # liquid 22 (this report)
php common/data/analyze_mtf_1h4h1d.php --all     # full 124-symbol universe
#   -> common/data/_mtf_1h4h1d_report.json
```
