# Crypto Pump/Dump Pattern Analysis

Unified analysis of OHLCV + derivatives + macro data for **131
HashHedge-listed symbols** on Bybit V5 (`common/data/history_prices/`).
Event window covers **Aug 2021 → May 2026** (~4.75 years), with macro
series back to 2013 (BTC dominance) and 2018 (Fear & Greed).

**Refresh the underlying data:**
```
./yii trading/fetch-data
```
Runs the full pipeline in-process (prices → derivatives → dominance → FNG).
Findings below were originally generated from `_analysis_combined_report.json`
and `_analysis_report.json`; those reports — and the `analyze_*.php` scripts
that produced them — have been removed from the repo. Restore from git
history (`git show <commit>:common/data/analyze_combined.php`) if you need
to re-run the analysis.

## 0. Data scope

| Source | Coverage | Storage |
|---|---|---|
| Price OHLCV (W, D, 4h, 1h) | 131 symbols | `history_prices/<SYM>.json` → `history.intervals` |
| Funding rate history (8h) | 123 symbols (linear perp pair) | `history_prices/<SYM>.json` → `derivatives.funding` |
| Open interest history (1d) | 123 symbols | `history_prices/<SYM>.json` → `derivatives.open_interest` |
| Fear & Greed index (1d) | 3,021 days since 2018-02 | `history/macro/fng.jsonl` |
| BTC dominance, total mcap, alt mcap, 24h vol (1d) | 4,766 days since 2013-04 | `history/macro/dominance.jsonl` |

8 symbols (SHIB, PEPE, BONK, FLOKI, RATS, FET, PUMP, SPELL) have
derivatives gaps because Bybit lists their perps under `1000{SYM}USDT`
which the resolver doesn't try.

## 1. Headline findings — the 5 things that matter most

1. **Funding × FNG is the strongest combined signal.** `neg_squeeze
   funding + Fear FNG` → **10.1 % next-day pump rate, 3.6 % dump rate,
   P:D ratio 2.77**. The opposite, `crowded_long funding + Fear FNG`,
   gives **15.4 % next-day dump rate, P:D 0.50**. Either side is a
   2–3 σ shift from the 3.1 % baseline pump rate.
2. **Volume is the single most reliable forward predictor.** Pump
   probability rises monotonically from 0.69 % (vol_ratio < 1) to
   10.51 % (vol_ratio ≥ 10). At vol_ratio ≥ 10 dump probability
   (16.44 %) **exceeds** pump probability — extreme volume rallies
   are distribution events, not breakouts.
3. **Median pump fades.** T+1 = −1.3 %, T+5 = −2.1 %, T+20 = −3.8 %.
   Buying the day *after* a sharp pump is on average a losing trade.
4. **Universe-wide dumps are ~2× as common as universe-wide pumps.**
   28 dump-days had ≥20 symbols moving together vs. 14 pump-days. The
   biggest cluster: **2025-10-10 dumped 123 of 131 symbols** (FNG=64
   Greed, BTC.D=58.6 — classic complacent-top flush).
5. **"Wait for alt season to long alts" is wrong in 2024–26.** Per-day
   pump density peaks at BTC.D 55–65 (1.31 pumps/day, P:D 1.49), not
   in classic alt season (0.62/day at BTC.D 35–45). Alts pump *more*
   when BTC.D is rising, not falling.

## 2. Methodology

**Event definition.** A daily candle is a *pump* if return ≥ +10 %
**and** ≥ +2.5 σ versus the prior 20-day realised volatility of that
same symbol. *Dump* is the symmetric −10 % / −2.5 σ rule. Sigma
scaling matters because a 12 % day on BTC is huge while a 12 % day on
FLOKI is a Tuesday.

**Event totals (Aug 2021 → May 2026):**

| | Pumps | Dumps | Total |
|---|---|---|---|
| All symbols | 2,874 | 2,106 | 4,980 |
| Ratio | 1.36 | 1.00 | — |

There are 36 % more pumps than dumps over the period because crypto
spends most of its time in expansion phases punctuated by short
sell-offs.

## 3. The volume signal

Probability of a ±10 % move on day T+1 conditional on today's
vol_ratio (today's volume ÷ prior 20-day average volume):

| vol_ratio bucket | days | P(next-day pump) | P(next-day dump) | P:D ratio |
|---|---|---|---|---|
| < 1.0   | 130,068 | 3.04 % | 2.36 % | 1.29 |
| 1.0–2.0 |  60,107 | 4.13 % | 2.93 % | 1.41 |
| 2.0–5.0 |  18,082 | 7.10 % | 4.53 % | 1.57 |
| 5.0–10.0|   2,217 | 9.63 % | 9.40 % | 1.02 |
| **≥ 10**|     778 | 10.51 % | **16.44 %** | **0.64** |

Two non-obvious findings:

- **Monotonic and smooth.** Each volume jump doubles the chance of a
  big move. This is the strongest single predictor in the dataset.
- **The top bucket flips.** At vol_ratio ≥ 10 dumps overtake pumps.
  Extreme volume = distribution, not accumulation. A "10× volume
  rally" is more dangerous than a "10× volume sell-off."

Median vol_ratio at pump events = **2.4×**; at dumps = **2.0×**. Pumps
demand more volume confirmation to fire than dumps do.

## 4. Candle anatomy — pump vs dump fingerprints

| Feature (median, daily) | Pump | Dump |
|---|---|---|
| Return                  | +15.9 % | −14.4 % |
| Sigma vs prior 20d      | 3.4σ    | −3.4σ |
| Body as % of close      | 13.7 %  | 16.8 % |
| Upper wick              | **2.5 %** | 1.0 % |
| Lower wick              | 1.0 %   | **3.6 %** |
| Closing position in day's range (0–1) | **0.87** | **0.17** |
| Vol_ratio               | 2.4×    | 2.0× |
| Preceded by 5-day vol compression | 28 % | 33 % |

Read this as:

- **Strong pump candles close in the top 13 % of the day's range** with
  a small upper wick — almost no rejection. Dump candles mirror this
  (close in bottom 17 %, small lower wick). Mid-range closes after a
  large move signal a contested move and tend to fade.
- **Long upper wicks on green days are bearish.** TNSR (2025-11-20,
  +220 %) printed a 45 % upper wick after a +130 % prior day → next
  day fell 45 %. HIGH (2026-04-18, +173 %) printed an **87 % upper
  wick** → fell 17 % the next day, 28 % over 20 days.
- **Long lower wicks on red days are bullish.** USTC −37 % on
  2022-05-13 had a 69 % lower wick → bounced +43 % next day before the
  Terra collapse resumed.
- **Volatility compression alone is weak.** Only ≈30 % of events were
  preceded by a compressed prior window. Across all 29,331 5-day
  compression setups: 8.7 % produced a pump within 5 days, 7.2 %
  a dump — base rate is ~16 %, barely above any random 5-day window's
  ~14 %. Compression needs volume to confirm.

## 5. Post-event drift — pumps fade, dumps bounce-then-bleed

Median forward returns after a ±10 % event:

| Horizon | After pump | After dump |
|---|---|---|
| T+1   | **−1.3 %** | +0.2 % |
| T+5   | −2.1 %     | +0.5 % |
| T+20  | −3.8 %     | −2.8 % |

Means are slightly positive (right-skewed by occasional follow-through),
but the **median is consistently negative for pumps**. Dumps show a
small bounce inside 5 days, then continued weakness out to T+20.
Stdev at T+20 for pumps is 47 % — outcomes are bimodal, not random.

## 6. Cross-asset clustering with macro overlay

Events do not happen at random across the universe. They cluster into
the same UTC days. Each cluster has a macro context.

**Top pump days (symbols pumping together):**

| Date | Sym | BTC.D | FNG | Bucket |
|---|---|---|---|---|
| 2025-05-08 | **83** | 64.4 | 65 | Greed |
| 2024-08-08 | 74     | 56.0 | **20** | **Extreme Fear** |
| 2025-11-07 | 74     | 59.9 | **24** | **Extreme Fear** |
| 2024-11-06 | 71     | 60.5 | 70 | Greed (US election) |
| 2025-04-09 | 68     | 62.5 | **18** | **Extreme Fear** |
| 2025-06-23 | 58     | 64.9 | 47 | Neutral |
| 2026-02-06 | 47     | 57.8 | **9**  | **Extreme Fear** |
| 2022-11-10 | 39     | 38.4 | **22** | Extreme Fear (FTX bounce) |

**Top dump days:**

| Date | Sym | BTC.D | FNG | Bucket |
|---|---|---|---|---|
| **2025-10-10** | **123** | 58.6 | 64 | Greed (canonical flush) |
| 2026-02-05 | 105 | 58.9 | **12** | Extreme Fear (continuation) |
| 2024-12-09 | 95  | 54.0 | 78 | **Extreme Greed** |
| 2025-03-03 | 95  | 59.5 | 33 | Fear |
| 2025-11-03 | 86  | 59.3 | 42 | Fear |
| 2024-04-12 | 84  | 52.9 | 79 | **Extreme Greed** |
| 2022-11-09 | 57  | 38.7 | 29 | Fear (FTX) |
| 2022-05-11 | 54  | 41.7 | 12 | Extreme Fear (Terra) |

Two patterns visible in the macro context:

- **Cluster pumps happen in two distinct regimes**: Greed/Neutral
  (trend continuation) and **Extreme Fear** (capitulation reversal).
  The middle (Fear) is the worst regime to be long.
- **Cluster dumps happen in two distinct regimes**: Extreme Greed
  (textbook tops) and Extreme Fear (continuation flushes). 2022's
  catastrophic dumps (FTX, Terra) were at BTC.D 38–42. Modern era
  flushes (2024–26) sit at BTC.D 55–60 — leverage moved from
  alt-dominated to BTC-dominated, but the broken alts still bleed
  with it.

**Asymmetry:** 28 dump-days with ≥20 symbols moving together vs only
14 pump-days. Systemic dumps are roughly twice as common as systemic
pumps. Crypto sells off in unison and rallies in rotation.

When ≥20 symbols pump or dump on the same UTC day, the cause is not
asset-specific — count the cluster first before searching for the news.

## 7. BTC is the dominant single factor

For the 130 alts with ≥100 overlapping daily returns:

- Mean Pearson correlation with BTC = **0.57**
- Mean beta to BTC = **1.27**

| Highest-beta names | Beta | Corr |
|---|---|---|
| PEPE   | 1.93 | 0.65 |
| BONK   | 1.90 | 0.53 |
| WIF    | 1.87 | 0.66 |
| ENA    | 1.78 | 0.61 |
| PUMP   | 1.76 | 0.56 |
| ORDI   | 1.75 | 0.56 |
| ETHFI  | 1.72 | 0.61 |

Memecoins dominate. They are essentially leveraged BTC.

| Lowest-correlation names | Corr | Beta | Note |
|---|---|---|---|
| XAU    | 0.22 | 0.14 | Tether Gold, tied to gold |
| USTC   | 0.30 | 0.94 | Depegged after Terra |
| TRX    | 0.35 | 0.46 | Historically low-beta |
| AUCTION| 0.37 | 1.03 | — |
| CATI   | 0.38 | 1.00 | — |

For risk management: an alt with beta 1.5 has expected −7.5 % move
when BTC drops 5 %. Cluster dump days are this in action — when BTC
sneezes, ≥100 alts catch it.

## 8. BTC dominance regime — pumps prefer BTC-leading phases

| Regime | Days | Pumps/day | Dumps/day | P:D ratio |
|---|---|---|---|---|
| Alt season (BTC.D 35–45) | 799   | 0.62 | 0.57 | 1.09 |
| Mid (45–55)              | 1,035 | 0.84 | 0.61 | 1.36 |
| **BTC-leading (55–65)**  | 1,148 | **1.31** | 0.88 | **1.49** |

**Per-day pump rate is 53 % higher in BTC-rising 30-day phases**
(P:D 1.53) than in BTC-falling phases (P:D 1.28). The standard
narrative "BTC rallies first then capital rotates to alts" is
incomplete — most alt pumps fire in the *spillover* phase of a BTC
rally, not the rotation phase after.

**Recent regime turning points (60-day rolling):**

| Date | BTC.D | Direction |
|---|---|---|
| 2024-12-09 | 53.96 | Falling (alt rotation start) |
| 2025-01-22 | 57.68 | Rising (BTC retake) |
| 2025-07-27 | 60.53 | Falling (mini alt season) |
| 2025-10-19 | 58.76 | Rising |
| 2025-12-10 | 58.45 | Falling |
| 2026-04-28 | 60.03 | Rising (current) |

2025–26 has been a 55–65 oscillator, not a directional move.
Strategies needing a clean alt season have not had one.

## 9. Funding rate signature

Funding paid every 8h on Bybit linear perps. Negative funding means
shorts pay longs (short crowding); positive means longs pay shorts
(long crowding).

| Funding bucket (% / 8h) | days | P(next-day pump) | P(next-day dump) | P:D ratio |
|---|---|---|---|---|
| < −0.02 % (neg squeeze)    | 7,422   | **6.95 %** | 4.59 % | **1.51** |
| −0.02 % → 0                | 21,343  | 3.37 %     | 2.36 % | 1.43 |
| 0 → +0.02 % (baseline)     | 113,290 | 3.20 %     | 2.70 % | 1.19 |
| +0.02 % → +0.05 %          | 6,601   | 5.56 %     | 4.12 % | 1.35 |
| +0.05 % → +0.10 % (crowded)| 3,097   | 5.07 %     | **6.52 %** | **0.78** |
| > +0.10 %                  | 590     | 7.80 %     | 5.25 % | 1.49 |

Two real regimes:

- **Negative funding ≤ −0.02 %/8h** = short squeeze fuel. **2.2×**
  baseline pump probability and the highest asymmetric long edge in
  the dataset.
- **Funding +0.05 → +0.10 %/8h** (annualised 55–110 % paid by longs)
  = unsustainable long crowding. The only single-signal bucket where
  next-day dump > pump.

7-day prior funding mean is higher before dumps (0.85 bps/8h) than
before pumps (0.66 bps/8h) — sustained positive funding raises the
dump probability of the next event.

## 10. Open interest — magnitude amplifier, not direction

OI alone is a weak directional signal. At pump events median OI Δ vs
prior day = +0.16 %; at dump events +0.03 %. Both event types fire on
modestly-rising-OI days. The distribution of OI z-score at pumps and
dumps is nearly identical.

| OI z-score vs prior 20d | days | P(next-day pump) | P(next-day dump) |
|---|---|---|---|
| < −1     | 33,618 | 3.63 % | 2.53 % |
| −1 to 0  | 40,947 | 3.21 % | 2.56 % |
| 0 to +1  | 35,699 | 3.30 % | 2.74 % |
| +1 to +2 | 24,996 | 3.51 % | 3.21 % |
| +2 to +3 | 10,274 | 3.84 % | 3.47 % |
| **≥ +3** | 5,951  | **5.71 %** | 4.52 % |

Probabilities rise modestly with OI z but the spread between pump and
dump probability barely changes. **Use OI as a magnitude amplifier**
("big move coming") and let funding decide direction.

## 11. Fear & Greed regime

| FNG regime | Days in regime | Pumps/day | Dumps/day | P:D |
|---|---|---|---|---|
| Extreme Fear (0–25) | 687 | **1.02** | 0.52 | **1.97** |
| Fear (26–45)        | 836 | 0.51     | 0.77 | 0.66 |
| Neutral (46–55)     | 468 | 0.82     | 0.58 | 1.41 |
| **Greed (56–75)**   | 749 | **1.22** | 0.66 | 1.85 |
| Extreme Greed (76+) | 281 | 0.80     | **0.85** | 0.94 |

Findings:

- **Greed (not Extreme Greed)** is the highest pump-density regime —
  the trend-following sweet spot.
- **Extreme Fear** is second-best for longs (P:D 1.97). Capitulation
  reversals are statistically real.
- **Plain Fear is the worst regime to be long.** P:D 0.66 — only
  regime where dumps outnumber pumps.
- **Extreme Greed** has the highest dump density per day. The
  classic "be fearful when others are greedy" has statistical
  backing — at the extreme.

## 12. The combined signal — joint funding × FNG

This is the strongest table in the entire dataset. P(next-day move)
conditional on both today's funding sign and today's F&G regime,
universe-wide. Cells with <100 days dropped for noise.

| funding ↓ \ FNG → | Extreme Fear | Fear | Neutral | Greed | Extreme Greed |
|---|---|---|---|---|---|
| **neg_squeeze (≤ −0.02 %)** | 5.74 / 5.37  P:D 1.07  (n=2,998) | **10.09 / 3.64  P:D 2.77  (n=2,141)** | 5.17 / 3.78  P:D 1.37 (n=1,006) | 5.72 / 4.35  P:D 1.31 (n=1,172) | 9.38 / 13.54 (n=96) |
| **neutral (−0.02 → +0.02 %)** | 3.00 / 2.39  P:D 1.26 (n=28,167) | 3.59 / 2.94 P:D 1.22 (n=30,332) | 2.09 / 2.10 P:D 1.00 (n=26,526) | 3.45 / 2.19 P:D 1.58 (n=43,097) | 5.78 / 7.85 **P:D 0.74** (n=6,281) |
| **mild_pos (+0.02 → +0.05 %)** | 6.20 / 3.88 P:D 1.60 (n=129) | 4.84 / 9.27 P:D 0.52 (n=248) | 5.52 / 3.49 (n=344) | 5.10 / 2.61  P:D 1.95 (n=3,333) | 6.20 / 5.69 (n=2,547) |
| **crowded_long (≥ +0.05 %)** | — (n=51) | 7.69 / **15.38  P:D 0.50** (n=91) | — (n=88) | 4.70 / 3.49 (n=1,403) | 5.94 / **8.03  P:D 0.74** (n=2,054) |

Cells are formatted `pump% / dump%`. Stand-out cells:

- **`neg_squeeze | Fear` — best long setup in the entire dataset.**
  10.09 % pump rate vs 3.64 % dump rate. P:D 2.77 over n=2,141 days.
  Negative funding during a Fear sentiment is the cleanest
  contrarian-long signal in the data.
- **`crowded_long | Fear` — best short setup**. 7.69 % pump vs
  **15.38 % dump**. P:D 0.50. Small sample (n=91) but the largest
  P:D divergence to the short side.
- **`mild_pos | Greed` — the boring "trend" regime.** Largest cell
  in the table (3,333 days). 5.10 % pump vs 2.61 % dump, P:D 1.95.
  This is the regime where most alts pump in a healthy bull phase.
- **`crowded_long | Extreme Greed`** — n=2,054 days, dump rate 8.03 %
  exceeds pump 5.94 %. The textbook euphoria trap.
- **`neutral | Extreme Greed`** — even without crowded funding,
  Extreme Greed alone flips P:D to 0.74. Sentiment carries weight.

The same cross-table for OI × dominance (`joint_oi_dom_next_day` in
the report) is much flatter — no cell beats the funding × FNG
extremes. **Funding × FNG carries the joint signal**, OI and
dominance are conditioning context.

## 13. Signals-aligned events — the cleanest historical setups

Scoring rule: count signals as long (negative funding +2; low OI +1;
Extreme Fear FNG +2; Fear +1; falling BTC.D Δ +1) and short
(crowded_long funding +2; high/extreme OI +1; Extreme Greed FNG +2;
Greed +1; rising BTC.D Δ +1). An event is "aligned" if its directional
score ≥ 3 with the opposite score = 0.

- **Pump-aligned events: 238** in the dataset (8.3 % of all pumps)
- **Dump-aligned events: 156** (7.4 % of all dumps)

**The 2025-10-10 universe-wide dump caught 8+ of the top-10 dump-aligned
events** — every dump-aligned event in the top 10 was either 2025-10-10
or sat in the same `OI high/extreme + Greed FNG + BTC.D 58.6 + rising
30d delta` configuration. The setup was textbook in retrospect:

| Sym | OI z | FNG | BTC.D Δ 30d | Outcome |
|---|---|---|---|---|
| ACE     | +1.83 | 64 Greed | +1.21 | −37 % |
| MANTA   | +2.53 | 64 Greed | +1.21 | −37 % |
| SAGA    | +2.32 | 64 Greed | +1.21 | −37 % |
| SSV     | +2.65 | 64 Greed | +1.21 | −36 % |
| ARKM    | +2.03 | 64 Greed | +1.21 | −36 % |

All five share identical macro inputs (it's the same UTC day) and
internal long-positioning indicators. The market was telling you what
was coming for weeks — OI was building, BTC.D was rising into the
event, FNG sat at Greed. The day itself simply executed.

Notable pump-aligned (long-side) events — all caught by `neg_squeeze
funding + Extreme Fear / Fear FNG`:

| Date | Sym | Funding (%/8h) | OI z | FNG | Move |
|---|---|---|---|---|---|
| 2025-11-20 | DYM | −0.349 | +0.51 | 11 (EFear) | +78 % |
| 2025-12-06 | USTC | −0.031 | +0.83 | 23 (EFear) | +72 % |
| 2026-04-20 | EDU | −0.047 | −1.54 | 29 (Fear) | +71 % |
| 2025-11-22 | MAV | −0.215 | +0.48 | 11 (EFear) | +54 % |
| 2026-01-25 | AUCTION | −0.458 | −0.65 | 25 (EFear) | +56 % |

Caveat — these were single-day spikes; **most also faded** within 5
days (median pump T+5 = −2.1 %). The setup gives you the *day* of the
move; it does not guarantee follow-through.

## 14. Time patterns

**Hour-of-day (UTC), computed on ~3.95M 1h returns:**

| Hour | Big moves (|1h|≥3 %) | Mean ret (bps) |
|---|---|---|
| 15:00 | **7,087** | −1.0 |
| 14:00 | 6,993 | −0.6 |
| 00:00 | 6,318 | −1.7 |
| 16:00 | 5,910 | −0.8 |
| 13:00 | 5,410 | **−5.0** |
| ... | ... | ... |
| 23:00 | 2,788 | +0.2 |
| 22:00 | 4,041 | **+6.0** |
| 21:00 | 4,004 | +3.9 |
| 03:00 | 3,359 | +2.6 |

- **13:00–16:00 UTC** is the most violent window (US cash open / EU-US
  overlap). 13:00 has the day's worst mean drift (−5 bps/hour) —
  consistent selling at US open.
- **22:00 UTC** (NY close) has the day's best mean drift (+6 bps).
- Quietest hours by big-move count: 23:00 and 03:00–05:00 UTC.

**Day-of-week:**

| | Mean daily return |
|---|---|
| Sat | +0.31 % |
| Fri | +0.30 % |
| Wed | +0.27 % |
| Mon | −0.04 % |
| Tue | −0.21 % |
| Sun | −0.27 % |
| Thu | **−0.46 %** |

Effect is small (~0.8 % range across the week). Not exploitable on
its own; useful as a tie-breaker for entry timing.

## 15. Top historical events with full multi-signal annotation

**Largest single-day pumps:**

| Date | Sym | Ret | Vol_r | Funding | FNG | BTC.D | Note |
|---|---|---|---|---|---|---|---|
| 2025-11-20 | TNSR | +220 % | 29× | n/a | 11 EFear | 58.6 | Followed by −45 % |
| 2021-10-30 | MANA | +157 % | 12× | — | 53 Neutral | 44.3 | Metaverse hype peak |
| 2024-12-03 | RSR | +156 % | 53× | n/a | 71 Greed | 56.4 | Squeeze, compressed prior |
| 2023-11-26 | USTC | +153 % | 21× | — | 65 Greed | 53.5 | Reflex bounce |
| 2026-04-16 | ORDI | +149 % | 80× | n/a | 52 Neutral | 60.1 | Compressed prior; -35 % T+20 |
| 2026-04-18 | HIGH | +173 % | **384×** | n/a | 47 Neutral | 60.1 | 87 % upper wick → rejected |
| 2025-05-30 | LPT | +113 % | 222× | n/a | 56 Greed | 64.5 | Followed by −47 % T+20 |

**Largest single-day dumps:**

| Date | Sym | Ret | Vol_r | Funding | FNG | BTC.D | Note |
|---|---|---|---|---|---|---|---|
| 2022-05-13 | USTC | **−65 %** | 2.8× | — | 8 EFear | 41.7 | Terra collapse day-2 |
| 2022-05-12 | USTC | −51 % | 3.5× | — | 11 EFear | 41.7 | Terra collapse day-1 |
| 2025-10-10 | many | −38 to −47 % | 5–10× | neutral | 64 Greed | 58.6 | Universe-wide flush (123 sym) |
| 2022-11-09 | SOL  | −43 % | 6.5× | — | 29 Fear | 38.7 | FTX collapse |
| 2024-06-08 | HIGH | −39 % | 8.4× | crowded | 71 Greed | 53.7 | Crowded-long blowup |
| 2025-03-22 | AUCTION | −39 % | 4.5× | n/a | 28 Fear | 60.7 | — |

Note the **2022 catastrophic dumps were at BTC.D ~40 with EFear FNG**,
while **modern flushes (2024–26) sit at BTC.D ~55–60 with Greed FNG**.
These are fundamentally different regimes; price-action pattern-matching
them is misleading.

## 16. Symbol-level summary

Most volatile names where the methodology fires often. Lifetime returns
are brutal:

| Symbol | Lifetime ret | Daily vol | Max DD | Pumps | Dumps |
|---|---|---|---|---|---|
| YGG    | −99.6 % | 7.0 % | −99.6 % | 39 | 31 |
| JASMY  | −96.8 % | 7.0 % | −98.7 % | 43 | 21 |
| LPT    | −93.8 % | 7.0 % | −95.5 % | 42 | 22 |
| SPELL  | −99.5 % | 6.4 % | −99.5 % | 37 | 24 |
| **FLOKI** | **+217 %** | 7.5 % | −91.7 % | 41 | 19 |
| LDO    | −88.5 % | 6.7 % | −94.3 % | 33 | 27 |
| TRB    | +8.5 %  | 7.3 % | −94.7 % | 31 | 28 |
| USTC   | −99.4 % | 9.5 % | −99.6 % | 33 | 23 |

**Survivorship reality**: of the 25 most-event-rich symbols, only
**FLOKI and TRB** have positive lifetime returns. Volatility is real
but **non-directional** — holding these names is a losing strategy.

## 17. Heuristics — what to actually do

Ranked from strongest to weakest single-signal edge.

### Long setups

1. **Negative funding (≤ −0.02 %/8h) + Fear FNG.** Best long edge in
   the dataset. P:D 2.77, 10.1 % next-day pump rate over n=2,141 days.
2. **vol_ratio ≥ 2 today.** Lifts next-day pump probability 2.5–4×.
   Above 5×, the symbol is in some kind of news/listing/event regime
   — both directions become more likely.
3. **Extreme Fear FNG + vol_ratio ≥ 2.** Capitulation reversal day.
   P:D 1.97 baseline lifts further with volume confirmation.
4. **Daily candle closes in top 13 % of its range** (`closed_high
   > 0.87`) with vol_ratio ≥ 2 = directional momentum trade.
5. **Long lower wick (≥ 20 % of close) on a dump day** = small
   reflex-bounce edge, ~+0.2 % mean T+1.
6. **BTC-leading regime (BTC.D 55–65) + rising 30d Δ** = best
   per-day pump density. Bias longs here, not in alt season.

### Short setups

7. **Crowded-long funding (≥ +0.05 %/8h) + Fear FNG.** Best short
   edge: P:D 0.50, 15.4 % next-day dump rate. Small sample (n=91).
8. **Crowded-long funding + Extreme Greed FNG.** Larger sample
   (n=2,054), P:D 0.74. The textbook euphoria trap.
9. **vol_ratio ≥ 10.** Dump probability (16.4 %) exceeds pump
   (10.5 %) — distribution event.
10. **Long upper wick (≥ 20 % of close) on a pump day.** Rejection.
    Almost every top-pump event with a heavy upper wick faded inside
    5 days (TNSR, HIGH, LPT in §15).
11. **OI z-score ≥ +2 with rising 30d BTC.D** sets up cluster-dump
    risk. The 2025-10-10 fingerprint.

### Risk management

12. **Don't buy the day after a sharp pump.** Median T+1 = −1.3 %,
    T+20 = −3.8 %.
13. **Size by BTC beta.** Position size × (1/beta). PEPE (β 1.93)
    deserves half the dollar size of a β-1.0 position for equal risk.
14. **Count the cluster before assigning a single-asset reason.**
    If ≥20 symbols moved together that day, the cause is macro, not
    asset-specific.
15. **Don't pattern-match 2022 catastrophes to 2024–26 setups.**
    Different BTC.D regime, different leverage source. The price
    action can look similar but the macro is materially different.
16. **Avoid running new positions through 13:00–16:00 UTC** unless
    you can monitor — highest big-move density and worst mean drift.
17. **Holding random alts is a losing strategy.** Of the 25 most
    volatile names only 2 had positive lifetime returns. The
    volatility is non-directional.

## 18. What's still missing — and where to get it cheaply

Now included: price, funding, OI, F&G, BTC dominance. Still gap-shaped:

- **Liquidations** — Bybit removed its public V5 endpoint. Coinglass /
  Coinalyze require paid keys ($30–80/mo). **Free proxy:** OI drop on
  a down-price bar — already computable from the OI data, just not
  surfaced as a separate metric yet.
- **ETF flows** (BTC/ETH spot ETFs) — Farside Investors publishes
  daily HTML tables. Scrape (~2 hrs of work, ~600 daily records since
  Jan 2024). Would directly explain the 2024–26 BTC-leading regime.
- **Bybit listing announcements** — free public API. Would resolve
  the single biggest remaining class of unexplained single-asset
  pumps (Bybit-listing pumps).
- **News / social sentiment** — Cryptopanic free tier exists but
  signal-to-noise is poor without NLP.
- **On-chain** — Glassnode / Nansen / Dune all paid. Blockchair has
  free basic stats (limited).

Ranked by edge vs effort:

1. **Bybit announcements + listing dates** — ~1 hr work, recovers
   the single-asset pump class.
2. **OI-drop-during-down-bar liquidation proxy** — ~30 min analyzer
   work, no fetch needed, recovers the liquidation signal Bybit
   removed.
3. **Farside ETF flows scrape** — ~2 hrs, explains the 2024–26
   macro regime directly.

## Re-running the pipeline

The data refresh runs in-process through the Yii service classes:

```
./yii trading/fetch-data    # prices + derivatives + dominance + FNG (incremental)
```

The analysis layer (`analyze_combined.php` and friends) that produced the
`_analysis_*.json` reports cited above was removed from the repo during
cleanup; restore from git history if you need to regenerate the reports.
