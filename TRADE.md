# Trading Playbook — Entry & Stop Rules

Actionable rules built from the findings in `CRYPTO.md` and **validated
by 12 simulations** documented in `docs/results/` (see § Validation
history below). Every setup has a concrete trigger
(machine-checkable), entry price formula, stop loss formula, position
size formula, and time-based exit. **No fixed take-profit** — exits
are time-based, signal-invalidation, or trailing stops. The system is
designed to take profit only by stopping out at a higher (long) /
lower (short) level than entry, never at a pre-set target.

The "edge" in every setup is statistical (P:D 1.5–2.8 for longs,
0.5–0.7 for shorts). It does NOT promise a win on any individual
trade. Sample sizes ≥ a few hundred days are required before any rule
is trusted; small-sample setups are flagged as such.

## Validation history (read this first)

12 simulations against ~5 years of price + funding + OI + FNG + BTC.D
data for 127 symbols. Findings that changed this playbook:

| Update | Source experiment |
|---|---|
| Default time exit **T+5 → T+10** (free +21–23 % MFE capture) | Exp 7 / 8 |
| **Drop the "pause longs after cluster dump" filter from L1** (loses 4 winners per loser saved) | Exp 9 |
| **Keep daily-mean funding** (live 16:00 UTC funding actually hurts) | Exp 12 |
| **L1, L3, S3, S4 promoted to CORE** — validated across both 2021–24 IS and 2025–26 OOS samples | Exp 11 |
| **S1, S2 marked DORMANT** — trigger conditions stopped occurring in 2025–26 | Exp 11 |
| **Bake 0.15 % round-trip slippage** into expected returns | Exp 10 |

See `docs/results/TRADE_SIMULATION_NEXT_STEPS.md` and
`TRADE_SIMULATION_WFCV_AND_LIVE_FUNDING.md` for full data.

Setup status legend used below:

| Tag | Meaning |
|---|---|
| **[CORE]**       | Large sample, walk-forward validated, safe to use as a strategy pillar |
| **[CONDITIONAL]** | Real edge but specific regime-dependent — fires rarely |
| **[DORMANT]**    | Trigger conditions haven't occurred in 2025–26 OOS data — wait, don't expect |
| **[SMALL-SAMPLE]** | n < 25 in any period — anecdotal, do not size full |

## 0. Notation used in every setup

| Symbol | Meaning |
|---|---|
| `C₀` | Today's daily close (timestamp 00:00 UTC of next day) |
| `H₀` `L₀` `O₀` | Today's daily high / low / open |
| `R₀` | Today's daily return = `C₀ / C₋₁ − 1` |
| `vol_ratio` | Today's daily volume ÷ mean(volume) over prior 20 days |
| `σ₂₀` | Stdev(daily returns) over prior 20 days |
| `sigma` | `R₀ / σ₂₀` |
| `upper_wick` | `(H₀ − max(O₀, C₀)) / C₀` |
| `lower_wick` | `(min(O₀, C₀) − L₀) / C₀` |
| `closed_high` | `(C₀ − L₀) / (H₀ − L₀)`  (0 = closed at low, 1 = closed at high) |
| `funding₀` | **Daily mean** of today's 8h funding events, decimal/8h *(NOT live 16:00 UTC value — that variant tested worse, see Exp 12)* |
| `oi_z20` | OI z-score vs prior 20-day mean |
| `FNG` | Fear & Greed index value for today (0–100) |
| `BTC.D` | BTC dominance today, % |
| `ΔBTC.D₃₀` | BTC.D today − BTC.D 30 days ago |
| `β` | Symbol's 365-day beta to BTC (from `_analysis_combined_report.json`) |
| `EQ` | Account equity in USD |

All conditions are evaluated **at the daily close (00:00 UTC)** unless
otherwise noted. The signal you act on is always from the *just-closed*
daily candle.

## 1. Position sizing — applies to every setup

```
RISK_PCT      = 0.005           # 0.5% of equity at risk per trade — fixed
risk_per_unit = |entry − stop|  # USD distance from entry to stop
size_units    = EQ × RISK_PCT / risk_per_unit
size_units   *= (1 / β)         # beta-adjust vs BTC
size_units    = min(size_units, EQ × 0.20 / entry)   # never > 20% notional
```

Notes:

- **0.5 % per trade**, not 1 %. Win rate on the strong setups is
  75–82 %, but drawdown sequences happen — sizing must allow them.
- **Beta-adjust always.** PEPE (β 1.93) gets ~half the size of a β-1
  position. Without this, memecoins eat all the variance budget.
- **20 % notional cap** prevents over-leverage on tight stops.
- **Realised P&L is ~0.15 % lower than nominal** per trade because
  of taker fees + slippage (Exp 10). Expectancy stays strongly
  positive — L1 averages +10.21 % per trade after slippage, vs
  +10.51 % gross.

## 2. Universe filtering

Only trade symbols that satisfy ALL of:

- Has a Bybit perp pair (so funding + OI are observable).
- ≥ 60 daily candles of history.
- Mean 20-day turnover ≥ $5M USD (rejects illiquid alts).
- Not in the **never-trade blacklist** below.

**Never-trade blacklist** (from data quality + behavioural reasons):

| Symbol | Reason |
|---|---|
| XAU    | Corr to BTC 0.22 — tied to gold, not crypto |
| USTC   | Depegged stable; mean ret 0 but huge tails. Lifetime ret −99.4 % |
| WLFI, LINEA | < 12 months of history — stats not stable yet |
| 1INCH, ACE, ACH | High intra-day chop produces repeated stop-outs across all setups (Exp 2/3 diagnostic) |
| Any symbol with `_failures.json` entry | No funding/OI data, signal model partial |

Memecoin caveat (PEPE, BONK, SHIB, FLOKI, RATS): high beta, no
derivatives data in our dataset. **Trade them with price-only rules
(L3, S3, S4) only**; do NOT apply funding / OI conditions to them.

---

# LONG SETUPS (ranked by edge)

## L1. Short-squeeze long **[CORE]**

**Edge:** `neg_squeeze funding + Fear FNG` → 10.09 % next-day pump
rate vs 3.64 % dump rate (P:D 2.77, n=2,141 days). Strongest cell in
the joint funding × FNG table. **OOS expectancy +11.51 %** (Exp 11)
— actually *improved* in 2025–26 vs 2021–24.

**Trigger (ALL must be true at today's daily close):**

- `funding₀ ≤ −0.0002` (≤ −0.02 %/8h — shorts are paying longs)
- `FNG ∈ [26, 45]` (Fear bucket — NOT Extreme Fear)
- `vol_ratio ≥ 1.0` (market is alive, not dead)
- Symbol passes universe filter

**Entry:**

```
entry_limit  = C₀ × 1.003        # 0.3% above today's close
order_type   = stop-limit, valid 24h
fill_window  = T+1 daily candle (next 24h after C₀)
if entry_limit not filled within 24h: cancel, setup expired
```

**Stop loss:**

```
stop = max(L₀ × 0.995, C₀ × 0.93)
```

The first term is the day's low minus 0.5 % buffer (the natural
swing-low invalidation). The second caps the stop at −7 % from
entry.

**Time exit:** Close at the close of **T+10** unconditionally. Exp 7
showed T+10 captures **+21 % more MFE** than T+5 at zero win-rate
cost. (T+20 captures another +28 % on top, but ties up capital
twice as long — only extend if your portfolio allows.)

**Trailing rule (replaces hard stop after T+1):**

```
after T+1 close, if price > entry × 1.02:
    new_stop = max(stop, prior_4h_pivot_low)
```

Move the stop up to whatever 4h swing-low has formed once the trade is
2 % in profit. Do this only once per day on the daily close.

**Signal-invalidation exit (overrides everything):**

- If `funding₀` flips to `≥ +0.0002` at any close before T+10, exit
  immediately at next-bar open. The setup's premise (short crowding)
  is gone.

**Cluster-day note:** Exp 9 tested pausing L1 after universe-wide
cluster dumps (≥ 50 symbols dumping yesterday). The filter **removed
114 winners vs only 26 losers** — a 4:1 negative ratio. **DO NOT
apply the cluster-day pause to L1**. The L1 trigger (negative funding
+ Fear FNG) is often *generated by* cluster-dump aftermath and the
bounce is the trade.

**Worked example — EDU 2026-04-20:**

- Trigger evaluation at close: funding₀ = −0.000465, FNG = 29 (Fear),
  vol_ratio = 10.3, oi_z20 = −1.54 → setup fires.
- Suppose C₀ = $0.115, L₀ = $0.108, H₀ = $0.120.
- entry_limit = $0.115 × 1.003 = $0.1153
- stop = max($0.108 × 0.995, $0.115 × 0.93) = max($0.1075, $0.1070) = $0.1075
- risk_per_unit = $0.1153 − $0.1075 = $0.0078 (6.8 % of entry)
- For EQ = $10,000, β(EDU) ≈ 1.1:
  size_units = $10,000 × 0.005 / $0.0078 × (1/1.1) = 5,827 units
  notional check: 5,827 × $0.1153 = $672 (well under 20 % cap of $2,000) → OK

## L2. Capitulation reversal long **[SMALL-SAMPLE]**

**Edge:** Extreme Fear FNG regime per-day P:D = 1.97. Adding wick +
volume confirmation filters out the slow grind-down days. Backtest:
**21 fills, 100 % WR, +11.7 % mean MFE** — but the perfect win rate
is small-sample noise; size at 0.25 % risk until you accumulate ≥ 50
real trades.

**Trigger:**

- `FNG ≤ 25` (Extreme Fear)
- `lower_wick ≥ 0.20` (today's candle painted a long lower tail)
- `vol_ratio ≥ 2.0`
- `R₀ ≤ 0` (today closed red — actual capitulation, not a green doji)
- Symbol passes universe filter

**Entry:**

```
entry_market = next-bar open (T+1 daily open)
```

Don't wait for a limit — capitulation reversals start fast.

**Stop loss:**

```
stop = L₀ × 0.99   # 1% below today's wick low
```

The wick low IS the invalidation. If price prints below it, the
capitulation isn't done.

**Time exit:** Close at T+10 close.

**Trailing:** Same as L1.

**Note on co-firing with L1:** If both L1 and L2 trigger same day on
same symbol, take L2's entry but keep L1's stop (it's tighter
because L1 uses 0.5 % below low vs L2's 1 %).

## L3. Momentum continuation long **[CORE]**

**Edge:** Daily candles with `closed_high ≥ 0.85` and `vol_ratio ≥ 2`
were the pump fingerprint. Backtest: **763 fills, 82.4 % WR**,
+13.96 % mean MFE on wins. **OOS WR 79.4 %, expectancy +10.69 %** —
slightly weaker than IS but still strongly profitable.

**Trigger:**

- `closed_high ≥ 0.85`
- `vol_ratio ≥ 2.0`
- `R₀ ∈ [+0.04, +0.15]` (4–15 % up day — not a +30 % blow-off)
- `upper_wick ≤ 0.05` (no rejection)
- `BTC.D regime ∈ {mid_45_55, btc_lead_55_65}` (don't trade alts when
  BTC.D ≥ 65 — there's no liquidity left for them)

**Entry:**

```
entry_stop = H₀ × 1.002          # buy stop above today's high
fill_window = T+1 daily candle
```

This is a stop-buy: only fill if the breakout continues.

**Stop loss:**

```
stop = (H₀ + L₀) / 2 × 0.995    # mid-range minus buffer
```

The "breakout has failed" line is back to the mid-range of the
trigger candle. About a 4–6 % stop from entry typically.

**Time exit:** Close at **T+10** OR move the stop to the prior 1d
low each day (whichever is tighter). Aggressive trail on this setup
because momentum is the entire premise — once it stalls, exit.

**Cluster-day note:** Exp 9 showed the cluster filter is neutral for
L3 (small net positive). **Optional** to apply: skip L3 longs the
day after ≥ 50 symbols dumped if you prefer the more conservative
posture. Doesn't materially help or hurt expectancy.

## L4. Wait-for-extreme-fear cluster bottom **[CONDITIONAL]**

**Edge:** Cluster pump days in Extreme Fear are real and visible —
2024-08-08 (74 sym, FNG=20), 2025-11-07 (74, FNG=24), 2025-04-09
(68, FNG=18), 2026-02-06 (47, FNG=9). The setup fires after a
universe-wide dump, on the day FNG hits ≤ 15.

**Trigger:**

- `FNG ≤ 15` (Extreme Fear, lower half — not just ≤ 25)
- Yesterday's universe had ≥ 30 symbols dumping (≥−10 % / ≥−2.5σ).
- Today's candle is green (`R₀ > 0`).

**Entry:** Buy a basket — top 5 highest-correlation-to-BTC symbols
in the universe at this point (PEPE, WIF, BONK, ETHFI, ENA from the
β list, with funding still negative ideally). Equal-weight.

**Position sizing:** Total basket = 2 × normal trade size, split
across 5 symbols. Beta-adjust each leg.

**Stop loss:**

```
basket_stop = yesterday's_universe_low × 0.97
```

If the basket falls 3 % below the previous day's panic low, the bottom
isn't in. Exit all legs simultaneously.

**Time exit:** Close at T+10. **Do not hold longer** —
capitulation-bottom edge decays beyond 2 weeks.

This is the only long setup that trades a *basket*, not a single
symbol — the edge is in catching the regime turn.

---

# SHORT SETUPS (ranked by edge)

## S1. Crowded-long blowup **[DORMANT]**

**Edge:** `crowded_long funding + Fear FNG` → 15.38 % next-day dump
rate vs 7.69 % pump rate (P:D 0.50, n=91 days). Tiny sample even
in-sample.

⚠ **DORMANT status:** Exp 11 found this setup fired **zero times in
2025–26 OOS data**. The joint condition (crowded-long funding +
Fear FNG, not Extreme Greed) hasn't materialised in the current
regime. Wait for it, don't expect it.

**Trigger (kept for completeness):**

- `funding₀ ≥ +0.0005` (≥ +0.05 %/8h sustained)
- Prior 7-day mean funding ≥ +0.0003 (sustained, not a single spike)
- `FNG ∈ [26, 45]` (Fear)
- `oi_z20 ≥ +1.0` (longs are actually building, not just rolling)

**Entry:**

```
entry_limit = C₀ × 1.010        # sell limit 1% above close (catch the bounce)
fill_window = T+1 daily candle, valid 48h
```

**Stop loss:**

```
stop = max(H₀, C₀ × 1.05) × 1.005    # above the higher of today's high or +5%
```

**Time exit:** Close at T+10 close.

**Trailing:** mirror of L1 (lower stop to most recent 4h pivot once
3 % in profit).

**Signal-invalidation exit:** If `funding₀` falls to `≤ 0` before
T+10, exit immediately.

## S2. Euphoria-trap short **[DORMANT]**

**Edge:** `crowded_long funding + Extreme Greed FNG` → 5.94 % pump
vs 8.03 % dump (P:D 0.74, n=2,054 days). Was the workhorse short in
IS data.

⚠ **DORMANT status:** Exp 11 found this setup also fired **zero times
in 2025–26 OOS data**. Extreme Greed FNG ≥ 76 has not occurred in
the recent regime. Setup is real; the regime that produces its
triggers is currently absent.

**Trigger:**

- `funding₀ ≥ +0.0005`
- Prior 7-day mean funding ≥ +0.0003
- `FNG ≥ 76` (Extreme Greed)
- `oi_z20 ≥ +1.0`
- `BTC.D 30d Δ ≥ +0.5` (BTC is leading — alts are most exposed)

**Entry, stop, exits:** Same as S1.

## S3. Volume-exhaustion short **[CORE]**

**Edge:** `vol_ratio ≥ 10` → 16.44 % next-day dump rate vs 10.51 %
pump rate (P:D 0.64). Pure price signal — no funding/FNG required.
Works on memecoins where derivatives data is absent. Backtest: **297
fills, 84.2 % WR**, +15.04 % mean MFE on wins. **OOS expectancy
+14.22 %** — actually *outperformed* in-sample (Exp 11). Most
robust short in the dataset.

**Trigger:**

- `vol_ratio ≥ 10`
- `R₀ ≥ +0.10` (we're shorting *after* a green blow-off day, not a
  flush day — flush days with 10× volume often bounce)

**Entry:**

```
if upper_wick ≥ 0.20:
    entry_market = next-bar open      # rejection candle, immediate
else:
    entry_limit  = C₀ × 1.005         # wait for a small bounce
```

**Stop loss:**

```
stop = H₀ × 1.005
```

Above today's high — if price breaks today's blow-off high, the
exhaustion read was wrong.

**Time exit:** Close at T+10.

**Worked example — TNSR 2025-11-20 (+220 %, upper_wick 45 %):**

- Trigger: vol_ratio 29×, R₀ +220 %, upper_wick 0.45 → market entry.
- Suppose C₀ = $0.45, H₀ = $0.62, L₀ = $0.18.
- entry_market on T+1 open (assume $0.40 — TNSR fell from close)
- stop = $0.62 × 1.005 = $0.6231 → 55 % away
- risk_per_unit for the short = $0.6231 − $0.40 = $0.2231 (56 %)
- size = EQ × 0.005 / $0.2231 × (1/β) — extremely small position
- Notional check: tiny notional, which is the point — the stop is so
  wide that the dollar size must be small.

Actual outcome: TNSR fell 45 % the next day. Position printed.

**Caveat:** This setup has the widest stops of any. Position sizes
will be small. That's correct behaviour — blow-off days have huge
ranges.

## S4. Rejection short **[CORE]**

**Edge:** Pump days with long upper wicks have a median T+1 fade of
−1.3 % and frequently more (TNSR −45 %, HIGH −17 %). Backtest: **59
fills, 98.3 % WR, +19.93 % mean MFE on wins**. Small sample but
extreme win rate; OOS WR stayed at 97 %.

**Trigger:**

- `R₀ ≥ +0.10` (today was a pump)
- `upper_wick ≥ 0.20`
- `closed_high ≤ 0.50` (closed in bottom half of day's range)
- `vol_ratio ≥ 2.0`

**Entry:**

```
entry_limit = (H₀ + L₀) / 2          # mid-range of today's candle
```

Sell on a re-test of the mid-range. The rejection candle leaves a
"failed breakout" zone overhead.

**Stop loss:**

```
stop = H₀ × 1.005
```

**Time exit:** Close at T+10.

**Special case — co-trigger with S1/S2:** If S1 OR S2 also triggered
today on the same symbol, prefer S4's entry (it gets a better price)
but use S1's tighter stop if narrower.

## S5. Cluster-dump precursor (universe-level reduce-risk) **[CONDITIONAL]**

**Edge:** The 2025-10-10 fingerprint (`OI high/extreme + Greed FNG +
BTC.D rising 30d`) preceded the largest dump day in the dataset (123
of 131 symbols).

**Trigger (evaluated daily on the universe, not per-symbol):**

- Median `oi_z20` across universe ≥ +1.5 (broad OI build)
- `FNG ∈ [56, 75]` (Greed — not Extreme Greed)
- `ΔBTC.D₃₀ ≥ +1.0`

**Action:** This is **not a single trade** — it's a portfolio-level
risk signal.

```
1. Pause L2, L3, L4 longs for 5 trading days
   (DO NOT pause L1 — Exp 9 showed L1 thrives day-after-cluster)
2. Short the basket of top-10 highest-OI-z symbols, equal-weight
3. Basket total notional = EQ × 0.10 (not bigger — concentration risk)
4. Basket stop = +5% from basket-weighted entry
5. Time exit: close at T+10
```

Co-firing with S1/S2 on individual names is allowed and additive — S5
is a context shift, S1/S2 are specific entries.

---

# 3. Session rules (apply to all setups)

### When NOT to enter

- **Between 13:00 and 16:00 UTC unless monitoring live.** Highest
  big-move density (15:00 = 7,087 big moves in dataset, mean drift
  −1.0 bps). Stops get blown around in this window.
- **On the daily close of a day where ≥ 50 symbols dumped the prior
  day** — applies to **L2, L3, L4** and shorts S2/S3/S4 only. **DOES
  NOT apply to L1** (validated by Exp 9: the L1 trigger is generated
  by post-cluster funding and the bounce is the trade). Wait 24h for
  the bounce/continuation to resolve.
- **In `plain Fear FNG` (26–45) WITHOUT a funding signal.** This is
  the worst regime to be long without a specific edge (P:D 0.66
  baseline). Only L1's funding edge overrides this — without it,
  stand aside.

### When TO be aggressive (size up to 0.75 % risk)

- L1 with `funding₀ ≤ −0.001` (≤ −0.10 %/8h — extreme short
  squeeze). This is the right tail of the long edge.
- S2 with `funding₀ ≥ +0.0010` AND `FNG ≥ 80`. (DORMANT regime —
  this hasn't fired in OOS; ready for when it returns.)

### Daily routine (recommended)

```
At 00:00 UTC daily:
1. Refresh price + funding (daily mean of 3×8h events) + OI
   for all universe symbols
2. Refresh FNG and BTC.D
3. Scan: for each universe symbol, evaluate triggers L1-S4
4. Evaluate S5 universe-level trigger
5. For each fired trigger, compute entry / stop / size
6. Place orders with 24h GTC
7. Log all trades + reasons in a journal
```

# 4. Position management cheatsheet

| | Long | Short |
|---|---|---|
| Entry style (most setups) | Stop-limit above close OR market | Sell limit above close OR market on rejection |
| Hard stop (initial) | Below today's low or below today's mid | Above today's high |
| Stop buffer | 0.5–1 % beyond reference level | 0.5–1 % beyond reference level |
| Max risk per trade | 0.5 % of equity (aggressive: 0.75 %) | Same |
| Max stop distance | 7 % from entry | Wider for S3 (15 %+); tight for S1/S2 (~5 %) |
| **Time exit (default)** | **Close of T+10** | **Close of T+10** |
| Trailing after T+1 at 2 % profit | Stop up to 4h pivot low | Stop down to 4h pivot high |
| Hard invalidation | Funding flips sign (L1) | Funding flips negative (S1/S2) |
| Max concurrent positions | 5 single-symbol + 1 basket (L4 or S5) | Same |
| Realised fee/slippage cost | ~0.15 % round trip per trade | Same |

## 5. Hard rules (never break)

1. **Never average down.** If a trade hits stop, take the loss. The
   data shows positions that go against you have a forward
   distribution skewed negative.
2. **Never move stops away from price.** Trailing toward price OK;
   widening never OK.
3. **Never hold past T+10 close.** Edge measurably decays beyond
   T+20 (CRYPTO.md §5). T+10 is the validated optimum (Exp 7).
4. **Apply the cluster-day rule selectively.** If ≥ 50 symbols
   moved with the same sign yesterday, pause **L2, L3, L4 and the
   shorts** for 24h. **Do NOT pause L1** — it benefits from the
   regime (Exp 9).
5. **Never trade through known low-liquidity windows** (your local
   broker outages, holiday weekends, Bybit maintenance) without
   monitoring.
6. **Never trade a symbol you can't price in real-time.** If the
   feed lags, the funding signal lags with it.
7. **Never deploy more than 60 % of equity in open positions.**
   Cluster days flush concentrated portfolios.
8. **Position sizing formula is non-negotiable.** Eyeballing size
   defeats the entire risk framework.
9. **Use daily-mean funding only**, not the live 16:00 UTC value.
   Exp 12 showed the live value dilutes L1 edge by adding noisy
   intraday flips.

## 6. What to log per trade (for review)

Append to `common/data/trade_journal.jsonl` per fill:

```json
{
  "t":          "2026-05-17T00:00:00Z",
  "symbol":     "EDU",
  "setup":      "L1",
  "side":       "long",
  "entry":      0.1153,
  "stop":       0.1075,
  "size_units": 5827,
  "notional":   672.12,
  "risk_usd":   45.66,
  "triggers": {
    "funding_0":   -0.000465,
    "fng":         29,
    "vol_ratio":   10.27,
    "oi_z20":     -1.54,
    "btc_d":       59.28,
    "btc_d_30d_delta": 0.92
  },
  "outcome": null
}
```

After exit:

```json
{
  "outcome": {
    "exit_t":      "2026-05-27T00:00:00Z",
    "exit_px":     0.1284,
    "exit_reason": "time_T+10",
    "pnl_usd":     76.46,
    "fees_usd":    1.01,
    "r_multiple":  1.67
  }
}
```

Review monthly. Setups with realised P:D below 0.8× their backtested
ratio after 50+ trades should be paused for re-evaluation.

## 7. What this playbook deliberately doesn't do

- **No fixed take-profit targets.** All exits are time-, signal-, or
  trailing-stop-based. Fixed TPs cap winners while letting losers
  run to stop — the math of skewed distributions punishes this. The
  post-event drift data shows winners come from the right tail;
  capping them removes the edge.
- **No "predict the bottom" / "predict the top" trades.** Every
  setup requires a confirming candle (volume, wick, close position).
- **No setups requiring less than ~100 observations** in the
  combined report. S1 (n=91) is borderline and now also DORMANT in
  OOS.
- **No multi-week holds.** Median forward edge decays by T+20.
  Holding beyond T+10 is the rough boundary of supported behaviour.
- **No leverage assumptions baked in.** Size formulas are in
  *equity terms*; if trading 3× leveraged on Bybit, scale notional
  by your account's *equity*, not *buying power*.
- **No assumption of zero slippage.** Realised P&L is ~0.15 % per
  trade lower than nominal. Backtests in `docs/results/` quote both
  raw and slippage-adjusted numbers.

## 8. Pre-trade checklist (60-second version)

Print this. Before clicking buy/sell:

```
[ ] Setup name (L1-S5) and its current status (CORE / CONDITIONAL /
    DORMANT / SMALL-SAMPLE)?
[ ] All trigger conditions verified at the correct candle close,
    using daily-mean funding (NOT live 16:00 UTC value)?
[ ] Symbol in universe (passes filters, not blacklisted)?
[ ] Cluster size yesterday < 50? If ≥ 50, defer per §3 — UNLESS
    this is L1 (L1 is allowed; L2/L3/L4/S2/S3/S4 are paused).
[ ] Entry price computed from formula, not eyeballed?
[ ] Stop loss price computed from formula?
[ ] Size = EQ × 0.005 / risk_per_unit × (1/β), capped at 20% notional?
[ ] Time exit scheduled (T+10 close)?
[ ] Open positions notional < 60 % of equity?
[ ] Logged to trade_journal?
```

If any line is unchecked, the trade doesn't fire.

## 9. What's still untested (next experiments to run)

The 12-experiment suite varied risk / exit logic and analytical knobs
but kept entry trigger thresholds constant. The biggest open
question is whether the **thresholds themselves** can be improved
via walk-forward search:

- L1: does `funding ≤ −0.0003` (stricter) work better than
  `−0.0002`?
- L3: does `closed_high ≥ 0.90` (stricter) lift win rate further?
- S3: does `vol_ratio ≥ 8` (looser) expand sample without
  degrading P:D ratio?

Each is a fitted hyperparameter from the original CRYPTO.md analysis.
Walk-forward tests of those parameters would refine the playbook
further. Until then, stick with the documented thresholds — they're
the values that produced the validated edges.

## 10. Changelog

| Date | Change | Source |
|---|---|---|
| 2026-05-17 | Initial playbook | CRYPTO.md analysis |
| 2026-05-17 | Default time exit T+5 → **T+10** | Exp 7 / 8 |
| 2026-05-17 | Cluster filter dropped for L1; kept selective for L2-L4 and shorts | Exp 9 |
| 2026-05-17 | Slippage 0.15 % round-trip baked into sizing notes | Exp 10 |
| 2026-05-17 | S1, S2 reclassified **DORMANT** (zero OOS fills) | Exp 11 |
| 2026-05-17 | L1, L3, S3, S4 promoted to **CORE** (walk-forward validated) | Exp 11 |
| 2026-05-17 | Confirmed daily-mean funding (live 16:00 variant rejected) | Exp 12 |
| 2026-05-17 | Added 1INCH, ACE, ACH to blacklist (high chop) | Exp 2/3 diagnostic |
