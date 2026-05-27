# Trading bot setup — Experiment 8 (v1 stops + T+20)

Live trading-signal bot that implements the playbook in `TRADE.md` with the
**Experiment 8** parameters (TRADE_SIMULATION_COMPARISON.md §6):

| Knob | Value | Source |
|---|---|---|
| Stop loss | setup-specific (v1 baseline) | TRADE.md §L1-S4 |
| Success bar | +1% (used only for labelling) | n/a in live mode |
| Time exit | **T+20 daily close** | Exp 8 |
| Cluster filter (L1) | **OFF** | Exp 9 |
| Cluster filter (others) | ON (pause L2/L3/L4 + shorts day-after ≥50 dumps) | TRADE.md §3 |
| Funding | **daily mean** of 3×8h events | Exp 12 |
| Slippage estimate | 15 bps round-trip baked into sizing notes | Exp 10 |
| Dormant setups | S1, S2 suppressed | Exp 11 OOS |

### Multi-timeframe setups (L5 / S6)

Added from the multi-timeframe entry study (`docs/results/MTF_ENTRY_ANALYSIS*.md`,
backtest in `MTF_SETUPS_BACKTEST.md`):

| Setup | Trigger | Cadence |
|---|---|---|
| **L5** pullback dip-buy long | daily pullback (`d_down`) + 4h or 1h RSI < 40 | daily scan **and** 4h scan |
| **S6** 4h blow-off rejection short | daily uptrend + a 4h bar that pumped ≥ 4% with a ≥ 15% upper wick | **4h scan only** (almost never aligns with the daily close) |

S6 trades a looser universe than L1–L5 — `trading.s6MinTurnover20dUsd` (default
**$1M** vs the standard $5M) — because its edge lives on thinner alts. Both
setups use risk-based sizing and the time/trailing exit; no fixed TP.

> **These are why the 4h cron job below exists.** S6's edge is a 4h-timeframe
> phenomenon, so it must be scanned at each 4h close — the daily scan can't see it.

## 1. Configure Telegram credentials

Edit `common/config/params-local.php` (gitignored):

```php
return [
    'telegram' => [
        'botToken' => '1234567890:AAH...',         // from @BotFather
        'chatId'   => '-1001234567890',            // channel id, or '@your_channel'
    ],
    'trading' => [
        // optional overrides — defaults live in common/config/params.php
        'equityUsd'           => 25000.0,
        // 's6MinTurnover20dUsd' => 1_000_000,   // S6's looser liquidity floor (default $1M)
    ],
    // 'chart' => ['enabled' => true],            // attach a QuickChart PNG to each signal (default on)
];
```

Prod checklist beyond credentials: run cron on **UTC** (§4); ensure the host can
reach `api.bybit.com`, `api.telegram.org` and `quickchart.io`; make
`runtime/logs/` and `common/data/trade_state/` writable; Redis is needed only by
the backend admin UI (session), not the cron jobs; and for a public admin UI set
`YII_ENV=prod` / `YII_DEBUG=false` in `backend/web/index.php`.

**Find the channel id:** add the bot as an admin (with Post Messages
permission), send any message to the channel, then visit
`https://api.telegram.org/bot<TOKEN>/getUpdates` — look for `chat.id`.
Channels begin with `-100…`.

**Smoke test:**

```bash
./yii trading/test-telegram
```

You should see the test message appear in your channel.

## 2. Initial data pull

The first run pulls ~5 years of OHLC/funding/OI history (~1.2 GB on disk):

```bash
./yii trading/fetch-data
```

Each step is now a direct in-process service call (no shelling out):

| Step | Service class | Source |
|---|---|---|
| prices | `common\services\Trading\Fetcher\BybitPriceFetcher` | daily, 4h, 1h candles per symbol |
| derivatives | `common\services\Trading\Fetcher\BybitDerivativesFetcher` | funding rate + OI history |
| dominance | `common\services\Trading\Fetcher\DominanceFetcher` | CMC BTC.D + total/alt mcap |
| fng | `common\services\Trading\Fetcher\FngFetcher` | alternative.me Fear & Greed |

Each is idempotent and pulls only deltas. The on-disk format is
byte-compatible with the standalone scripts under `common/data/fetch_*.php`,
which are kept in place as a manual-CLI fallback.

## 3. Manual scan / preview

```bash
./yii trading/scan --asOf=2026-05-16 --dryRun=1
```

Outputs each fired setup with entry/stop/size and notes which would be
sent. `--dryRun=1` prevents both sending and persisting open positions.

The same dry-run is available in the admin UI at `/trading/scan-preview`.

## 4. Cron schedule

> **Run cron on UTC.** Every edge is measured on closed **UTC** daily and 4h
> candles (daily close = 00:00 UTC; 4h bars close at 00/04/08/12/16/20 UTC). If
> cron fires in local time the bars are misaligned and signals are wrong. Use
> `CRON_TZ=UTC` (or set the whole host to UTC).

Three jobs: a daily entry scan, hourly lifecycle management, and a 4h MTF scan
for L5/S6.

### Linux / macOS (`crontab -e`)

```cron
# Crypto bot — MUST run on UTC boundaries.
CRON_TZ=UTC

# daily 00:05 : fetch-all -> manage -> daily scan (L1–S4 + L5/S6 at the daily close)
5  0              * * * cd /path/to/project && ./yii trading/run-daily  >> runtime/logs/trading.log 2>&1

# hourly :05 (skip hour 0; run-daily covers it) : refresh prices/derivs -> manage open positions
5  1-23           * * * cd /path/to/project && ./yii trading/run-hourly >> runtime/logs/trading.log 2>&1

# every 4h :20 : refresh prices -> scan the 4h MTF setups (L5/S6)
20 0,4,8,12,16,20 * * * cd /path/to/project && ./yii trading/run-4h     >> runtime/logs/trading.log 2>&1
```

- `run-daily` — fetch all sources → manage open positions → fresh daily scan → send entry signals.
- `run-hourly` — refresh prices + derivatives → manage open positions (funding-flip invalidation, stop-hits between daily closes). **Does not scan for new entries.**
- `run-4h` — refresh prices + derivatives → scan the **4h** setups (L5/S6) at the just-closed 4h bar. Self-contained, so it doesn't depend on `run-hourly` ordering. Dedup is per-4h-bar, so it's also safe to run hourly if you prefer.

> Why `:20` for the 4h job: it must run **after** the 4h bar has been fetched
> and the universe walk has finished. `run-4h` fetches first, so the `:20` is
> just margin; signals are swing entries, so ~20 min latency is irrelevant.

### Windows Task Scheduler

Create three tasks (Action `…\yii.bat`, **Start in** `C:\OSPanel\home\cryptobot\crypto`,
triggers in **UTC**):

| Task name | Trigger | Arguments |
|---|---|---|
| Crypto bot — daily | Daily at 00:05 UTC | `trading/run-daily` |
| Crypto bot — hourly | Hourly at :05 | `trading/run-hourly` |
| Crypto bot — 4h | Daily 00:20, repeat every 4 h | `trading/run-4h` |

In **Conditions** uncheck "Start only on AC power" if this is a desktop.

## 5. Operational commands

```bash
./yii trading/status                            # open positions + last 20 signals
./yii trading/scan --dryRun=1                   # preview today's daily scan (L1–S4 + L5/S6)
./yii trading/scan --asOf=2026-04-20            # rerun a specific past UTC day
./yii trading/scan-4h --dryRun=1                # preview the 4h MTF scan (L5/S6) at the latest 4h bar
./yii trading/scan-4h --asOf="2026-05-14 16:00" # 4h scan at a specific UTC 4h close
./yii trading/manage-positions                  # one-off lifecycle pass
./yii trading/fetch-data                        # refresh all sources
./yii trading/test-telegram                     # verify channel creds
./yii trading/test-chart BTC                    # verify the QuickChart -> Telegram image pipeline
```

The dry-run previews are also in the admin UI: `/trading/scan-preview` (daily)
and `/trading/scan4h-preview` (4h).

## 6. State files

All write-state lives under `common/data/trade_state/` (gitignored):

| File | Purpose |
|---|---|
| `open_positions.jsonl` | one record per active position; rewritten on each manage pass |
| `closed_positions.jsonl` | append-only journal of exited trades |
| `signal_log.jsonl` | every signal we *attempted* to send (entry + exit) |
| `fetch_state.json` | last fetch run + per-step status/tail |

Backups: copy `common/data/trade_state/*` periodically. No DB schema.

## 7. Scan cadence — daily vs 4h

The classic setups (L1–S4) and L5 are evaluated on closed **daily** candles —
the unit those edges were measured on — by `run-daily` at 00:05 UTC. The hourly
job never scans for daily entries; it only manages open positions, so an *open*
daily candle can't produce a premature signal.

The exception is **S6** (and L5's lower-timeframe timing), whose edge was
measured on closed **4h** candles. `run-4h` evaluates those at each 4h close —
it reads the just-closed 4h bar (never an open one), so the same
"only-act-on-closed-candles" rule holds, just at 4h resolution. Dedup is keyed
to the 4h bar, so a setup won't re-open within the same bar.

## 8. Admin UI

Visit `/trading` in the backend app to see:

- KPI cards: open positions + notional, open risk, win rate, realised avg P&L, Telegram status
- Open positions with stop / size / beta and a per-row chart link
- Recent signals (entry + exit) with sent/unsent status
- Recently closed trades with **color-coded P&L** and exit reason
- One-click Telegram test send
- Dry-run previews: `/trading/scan-preview` (daily) and `/trading/scan4h-preview` (4h MTF)
- Interactive candlestick charts at `/trading/chart?symbol=BTC` (entry/stop/marker drawn in)
