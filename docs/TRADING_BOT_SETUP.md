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

Prod checklist beyond credentials: run a **queue worker** and start the hourly
loop (§4 — scanning no longer uses cron); ensure the host can reach
`api.bybit.com`, `api.telegram.org` and `quickchart.io`; make `runtime/logs/`
and `common/data/trade_state/` writable; **Redis is now required** — both the
admin-UI session and the signal queue use it; and for a public admin UI set
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

### 2.1. Incremental fetch

Subsequent runs only fetch what's missing. For each interval (`D` / `W` / `4h`
/ `1h` for prices; `funding` / `OI` for derivatives), the fetcher reads the
last stored bar's timestamp, compares it to "now", and **skips entirely** if
less than one bar period has elapsed. Otherwise it requests bars from the
last stored timestamp forward to "now" and merges them in (newer rows
overwrite the previous still-forming bar at the same timestamp).

Practical consequence: every `run-*` cron job below brings data current. A
run when nothing has ticked over is a few hundred ms of disk reads, no HTTP.
A run after a one-day gap pulls ~24 1h bars + ~6 4h bars + 1 daily bar per
symbol — one Bybit request per interval.

> If you ever need to **force** a full re-pull for a symbol (e.g. suspected
> corruption), delete its file under `common/data/history_prices/<SYMBOL>.json`
> and re-run `./yii trading/fetch-data` — the missing file is treated as a
> from-launch fetch.

## 3. Manual scan / preview

```bash
./yii trading/scan --asOf=2026-05-16 --dryRun=1
```

Outputs each fired setup with entry/stop/size and notes which would be
sent. `--dryRun=1` prevents both sending and persisting open positions.

The same dry-run is available in the admin UI at `/trading/scan-preview`.

## 4. Signal scanning — the queue (no cron)

Scanning runs through a **queue job** (`common\jobs\ScanSignalsJob`), not cron.
One job does the whole pipeline:

1. refresh prices + derivatives + macro to "now" (incremental — cheap when
   nothing ticked, see §2.1)
2. manage open positions (stop hits, funding flips, T+20, trailing stops)
3. scan the latest **closed** daily bar (L1–S4 + L5) **and** the latest closed
   **4h** bar (L5/S6), then send Telegram alerts and open positions up to
   `maxConcurrentSingles`.

Two ways the job gets enqueued:

- **Hourly, automatically** — a self-rescheduling job. When it finishes it
  re-enqueues itself with a 1-hour delay (in a `finally`, so a failed scan
  never breaks the chain). Start it **once**; the worker keeps it going.
- **On demand** — the **⚡ Scan now** button on the `/trading` dashboard (or
  `./yii trading/queue-scan`) pushes a one-off job. It does not touch the
  hourly schedule.

> **UTC is no longer a scheduling concern.** The engine always evaluates the
> latest *closed* UTC daily / 4h candle, and `openFromSignal()` dedups by
> `(as_of, symbol, setup)`. So an hourly job firing at any minute simply
> re-prices the most recent closed bars and fills freed slots — it never acts
> on an open candle and never opens a duplicate. Exact :00 alignment only
> affects latency (≤ 1 h), which is irrelevant for swing entries.

### 4.1. Queue component (already wired)

`common/config/main.php` registers the Redis-backed queue and bootstraps it:

```php
'bootstrap' => ['queue', /* … */],
'components' => [
    'queue' => [
        'class'   => \yii\queue\redis\Queue::class,
        'redis'   => 'redis',           // the redis connection from main-local.php
        'channel' => 'crypto_signals',
        'as log'  => \yii\queue\LogBehavior::class,   // job lifecycle -> runtime/logs
    ],
],
```

The `redis` connection itself lives in `common/config/main-local.php` (host /
port / db). Nothing else to configure — Redis already backs the admin session.

### 4.2. Start it (run once)

```bash
./yii trading/schedule-hourly      # enqueue the recurring hourly scan (idempotent only if run once!)
```

Each run pushes one recurring job, so **run this command exactly once**. If you
ever suspect duplicate chains (e.g. you ran it twice), clear and restart:

```bash
./yii queue/clear --interactive=0
./yii trading/schedule-hourly
```

### 4.3. The worker (the only long-lived process)

```bash
./yii queue/listen --verbose=1
```

`queue/listen` blocks, polls Redis (including delayed jobs), and runs each job.
Keep one worker alive in production.

**Linux — supervisor** (`/etc/supervisor/conf.d/crypto-queue.conf`):

```ini
[program:crypto-queue]
command=/usr/bin/php /path/to/project/yii queue/listen --verbose=1
directory=/path/to/project
user=www-data
autostart=true
autorestart=true
startsecs=3
stopwaitsecs=60                ; let an in-flight scan finish before SIGKILL
stdout_logfile=/path/to/project/runtime/logs/queue.log
redirect_stderr=true
environment=YII_ENV="prod",YII_DEBUG="0"
```

```bash
supervisorctl reread && supervisorctl update && supervisorctl start crypto-queue
```

Delayed jobs live in Redis, so a worker restart (deploy, crash) does **not**
break the hourly chain — the pending delayed scan is picked up when the worker
comes back.

**Windows — keep the worker alive.** Either run it as a service with
[NSSM](https://nssm.cc/):

```bat
nssm install CryptoQueue "C:\OSPanel\modules\PHP-8.5\php.exe" "C:\OSPanel\home\cryptobot\crypto\yii" queue/listen --verbose=1
nssm set CryptoQueue AppDirectory C:\OSPanel\home\cryptobot\crypto
nssm set CryptoQueue AppStdout C:\OSPanel\home\cryptobot\crypto\runtime\logs\queue.log
nssm set CryptoQueue AppStderr C:\OSPanel\home\cryptobot\crypto\runtime\logs\queue.log
nssm set CryptoQueue Start SERVICE_AUTO_START
nssm start CryptoQueue
```

…or a Task Scheduler task: Action `…\yii.bat queue/listen --verbose=1`,
**Start in** `C:\OSPanel\home\cryptobot\crypto`, trigger **At startup**, and in
**Settings** enable "If the task fails, restart every 1 minute" and "Do not
stop a running task" (so the long-running worker is never auto-killed).

### 4.4. Reliability knobs

The job is `RetryableJobInterface`: `ttr = 1800 s` (a scan, even a first full
pull, finishes well inside that) and `canRetry = false` (the recurring chain
already retries hourly, and a one-off button press should not silently fire
twice). If you want crashed/timed-out jobs retried automatically, raise the
queue's `attempts`:

```php
'queue' => [ /* … */ 'attempts' => 1 ],   // 1 = no retry (matches canRetry=false)
```

Inspect / operate the queue:

```bash
./yii queue/info                  # waiting / delayed / reserved / done counts
./yii queue/clear --interactive=0 # drop everything (use before re-running schedule-hourly)
```

### 4.5. Migrating off cron

Delete the old crontab lines / Windows Task Scheduler tasks that called
`trading/run-daily`, `trading/run-hourly`, `trading/run-4h`. They still work as
manual one-shots, but the queue now owns the cadence. (You do **not** need a
separate `trading/fetch-data` step — the queue job fetches first, incrementally.)

## 5. Operational commands

```bash
./yii trading/schedule-hourly                   # START the hourly scan loop (run once; see §4)
./yii queue/listen --verbose=1                  # the worker that runs queued scans (keep alive in prod)
./yii trading/queue-scan                        # push a one-off scan (same as the dashboard ⚡ Scan now button)
./yii queue/info                                # queue depth: waiting / delayed / reserved / done

./yii trading/status                            # open positions + last 20 signals
./yii trading/scan --dryRun=1                   # preview today's daily scan (L1–S4 + L5/S6), no send/open
./yii trading/scan --asOf=2026-04-20            # rerun a specific past UTC day
./yii trading/scan-4h --dryRun=1                # preview the 4h MTF scan (L5/S6) at the latest 4h bar
./yii trading/scan-4h --asOf="2026-05-14 16:00" # 4h scan at a specific UTC 4h close
./yii trading/manage-positions                  # one-off lifecycle pass
./yii trading/fetch-data                        # ad-hoc refresh (incremental); the queue job already covers this
./yii trading/rsi-board [--force=1]             # rebuild the /trading/rsi board from stored data (no API calls)
```

> The universe is `common/data/hashhedge_symbols.json` — all HashHedge
> instruments (crypto + TradFi). Prices come from Bybit (spot, then linear
> perp); tickers Bybit doesn't list fall back to Binance USD-M futures
> (`fapi.binance.com` must be reachable from the server). Newly added tickers
> are backfilled by the refresh loop at most 8 per run, so a big list change
> fills in over a few runs instead of one job outliving its TTR.

```bash
./yii trading/test-telegram                     # verify channel creds
./yii trading/test-chart BTC                    # verify the QuickChart -> Telegram image pipeline
```

> The RSI heatmap (`/trading/rsi`) never computes in the web request: it reads
> `common/data/trade_state/rsi_board.json`, which every refresh (queue job,
> `fetch-data`, `run-hourly`, `run-4h`) rebuilds for the symbols whose history
> changed. Each rebuild also pulls Bybit's live tickers and prices the forming
> candle of every timeframe at them — the stored daily candle alone would keep
> the close of the first fetch after 00:00 UTC all day. So prices are as fresh
> as the loop interval. The chart page (`/trading/chart`) takes today's candle
> from the same board (range from the 15m bars + the live price), so both pages
> agree. If the RSI page shows "prices/data … old" in red, the worker/loop
> isn't running.

> `trading/scan`, `trading/scan-4h` and `trading/manage-positions` remain the
> raw building blocks (handy for `--dryRun`/`--asOf`). The queue job runs the
> same pipeline end-to-end on the hourly cadence — you don't need to call them
> manually in production.

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

The hourly queue job (§4) evaluates **both** timeframes every run, each on its
own *closed* candle:

- **L1–S4 + L5** are measured on closed **daily** candles. The scan reads the
  most recent closed daily bar, so re-running hourly within the same UTC day
  re-evaluates the *same* closed bar — `(as_of, symbol, setup)` dedup means no
  duplicate opens, and an *open* daily candle can never produce a premature
  signal.
- **S6** (and L5's lower-timeframe timing) are measured on closed **4h**
  candles. The scan reads the just-closed 4h bar (never an open one); dedup is
  keyed to that 4h bar, so a setup won't re-open within the same bar.

Net effect: the hourly cadence brings prices current and fills any freed slots
on both timeframes, while the closed-candle rule keeps every signal honest.

## 8. Admin UI

Visit `/trading` in the backend app to see:

- KPI cards: open positions + notional, open risk, win rate, realised avg P&L, Telegram status
- **⚡ Scan now** — one click enqueues a full scan (refresh prices → manage →
  daily + 4h scan); the worker runs it and new signals appear within ~a minute
- **Equity (USD)** is the only editable knob; every other setting is locked to
  the tuned Experiment-8 defaults (shown read-only beside the field)
- Open positions with stop / size / beta and a per-row chart link
- Recent signals (entry + exit) with sent/unsent status
- Recently closed trades with **color-coded P&L** and exit reason
- One-click Telegram test send
- Dry-run previews: `/trading/scan-preview` (daily) and `/trading/scan4h-preview` (4h MTF)
- Interactive candlestick charts at `/trading/chart?symbol=BTC` (entry/stop/marker drawn in)
