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
        'equityUsd' => 25000.0,
    ],
];
```

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

That re-uses the existing fetchers under `common/data/`:

- `fetch_bybit_data.php` — daily, 4h, 1h candles for every symbol
- `fetch_bybit_derivatives.php` — funding rate + OI history
- `fetch_macro_dominance.php` — CMC BTC.D and total mcap
- `fetch_fng.php` — alternative.me Fear & Greed index

Incremental re-runs are cheap (each fetcher only pulls deltas).

## 3. Manual scan / preview

```bash
./yii trading/scan --asOf=2026-05-16 --dryRun=1
```

Outputs each fired setup with entry/stop/size and notes which would be
sent. `--dryRun=1` prevents both sending and persisting open positions.

The same dry-run is available in the admin UI at `/trading/scan-preview`.

## 4. Cron schedule

Daily entry scan at 00:05 UTC (5-min buffer for the daily close to land in
Bybit), plus hourly intra-day lifecycle checks.

### Linux / macOS (`crontab -e`)

```cron
# Trading bot — UTC. crontab(5) supports TZ= prefix on most systems;
# omit it if your distro doesn't and convert to local time.
TZ=UTC
5 0  * * * cd /path/to/project && ./yii trading/run-daily   >> runtime/logs/trading.log 2>&1
5 *  * * * cd /path/to/project && ./yii trading/run-hourly  >> runtime/logs/trading.log 2>&1
```

`run-daily` does: fetch all sources → manage open positions → fresh scan
→ send entry signals.

`run-hourly` does: refresh prices + derivatives only → manage open
positions (catches funding-flip invalidation and stop-hits between
daily closes).

### Windows Task Scheduler

Create two tasks pointing at `C:\OSPanel\home\cryptobot\crypto\yii.bat`:

| Task name | Trigger | Action | Arguments |
|---|---|---|---|
| Crypto bot — daily | Daily at 00:05 UTC | `C:\OSPanel\home\cryptobot\crypto\yii.bat` | `trading/run-daily` |
| Crypto bot — hourly | Hourly at :05 (24x daily) | `C:\OSPanel\home\cryptobot\crypto\yii.bat` | `trading/run-hourly` |

In **Conditions** tab uncheck "Start only on AC power" if this is a desktop.
Set **Start in** to `C:\OSPanel\home\cryptobot\crypto`.

## 5. Operational commands

```bash
./yii trading/status                       # open positions + last 20 signals
./yii trading/scan --dryRun=1              # preview today's scan
./yii trading/scan --asOf=2026-04-20       # rerun a specific past UTC day
./yii trading/manage-positions             # one-off lifecycle pass
./yii trading/fetch-data                   # refresh all sources
./yii trading/test-telegram                # verify channel creds
```

## 6. State files

All write-state lives under `common/data/trade_state/` (gitignored):

| File | Purpose |
|---|---|
| `open_positions.jsonl` | one record per active position; rewritten on each manage pass |
| `closed_positions.jsonl` | append-only journal of exited trades |
| `signal_log.jsonl` | every signal we *attempted* to send (entry + exit) |
| `fetch_state.json` | last fetch run + per-step status/tail |

Backups: copy `common/data/trade_state/*` periodically. No DB schema.

## 7. Why daily-at-00:05 and not faster

TRADE.md §3 and the simulation work both evaluate triggers on closed
daily candles — that's the unit the edges were measured on. Intra-day
scans would fire on an *open* candle, producing premature signals that
later disappear when the candle closes differently. The hourly job
intentionally only manages open positions; it does **not** re-scan for
new entries.

## 8. Admin UI

Visit `/trading` in the backend app to see:

- Open positions with current stop / size / beta
- Last 40 signals (entry + exit events) with sent/unsent status
- Recently closed trades with exit reason
- One-click Telegram test send
- Link to dry-run scan preview for any date
