# Crypto Market Monitor

A self-hosted market monitor for the HashHedge instrument list. It keeps a local
archive of price, derivatives and macro data and serves two views on top of it:
a multi-timeframe **RSI heatmap** across the whole universe and an interactive
**candlestick / comparison chart** for any coin.

Built on the [Yii 2 Advanced Project Template](https://www.yiiframework.com/extension/yiisoft/yii2-app-advanced)
(PHP 8.5, PostgreSQL, Redis).

> **Status.** The trade-signal engine (setup scans, position management, the
> signals dashboard) has been removed while a new strategy is being built. What
> runs today is the data pipeline and the read-only market views. The old
> `trading/scan`, `trading/scan-4h` and `trading/manage-positions` commands are
> kept as no-op stubs so existing cron lines and queue chains exit cleanly.

## Purpose

- **Collect.** Pull OHLCV candles (1d / 4h / 1h / 15m), funding-rate and
  open-interest history for 172 tickers (136 crypto + 36 TradFi) from the Bybit
  V5 public API, falling back to Binance USD-M futures for tickers Bybit doesn't
  list. Add BTC dominance / market cap (CoinMarketCap) and the Fear & Greed
  index (alternative.me).
- **Keep it fresh.** A Redis queue job refreshes the data on a self-rescheduling
  loop — no cron. Fetches are incremental, so a run where no candle has closed
  costs a few disk reads and no HTTP calls.
- **Show it.** A login-protected backend renders the RSI heatmap and the charts
  from precomputed files, so pages never wait on an exchange API.
- **Be the base for the next strategy.** The stored history and the research in
  [`CRYPTO.md`](CRYPTO.md) / [`TRADE.md`](TRADE.md) are what the new signal
  engine will be built on.

## How it works

```
Bybit V5 ─┐
Binance ──┼─► DataFetcher ─► common/data/history_prices/<SYMBOL>.json ─┐
CMC ──────┤                  common/data/history/macro/*.jsonl         │
alt.me ───┘                                                            ▼
                                             RsiBoard::build() + live Bybit tickers
                                                                       │
                                                                       ▼
                                        common/data/trade_state/rsi_board.json
                                                                       │
                                             /trading/rsi  ◄───────────┴──► /trading/chart
```

`common\jobs\ScanSignalsJob` runs the whole chain: refresh prices and
derivatives, refresh the macro series when they are more than 12 hours old,
rebuild the RSI board, then re-enqueue itself for the next run.

## Requirements

- PHP **8.5+** with the `curl` and `pdo_pgsql` extensions
- [Composer](https://getcomposer.org/)
- PostgreSQL — holds user accounts only; market data lives in files
- Redis — backs the backend session, the cache and the job queue, so nothing
  works without it
- A web server (Nginx or Apache) with `backend/web` as the document root
- About 2 GB of disk for the price history
- Outbound HTTPS to `api.bytick.com` (Bybit mirror), `fapi.binance.com`,
  `api.coinmarketcap.com` and `api.alternative.me`; plus `api.telegram.org` and
  `quickchart.io` if you use the Telegram commands

Check `php -v` before you start: Composer's platform check stops every command
with a fatal error on anything older than 8.5.

## Installation

```bash
composer install
php init --env=Development --overwrite=All   # creates ./yii, the *-local.php configs and web/index.php
```

`init` writes `common/config/main-local.php` with a PostgreSQL connection
(`crypto` database on `127.0.0.1:5432`) and a Redis connection
(`127.0.0.1:6379`, database 0). Edit that file to match your servers, create
the database, then apply the migrations:

```bash
php yii migrate
```

Point a virtual host at `backend/web`. Pretty URLs are on, so every request
that isn't a real file must be rewritten to `index.php`:

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

`vagrant/nginx/app.conf` has a complete server block to copy from.

### Accounts

Every backend page requires a login. The migrations seed a view-only `guest`
account; its password is the one hashed in
`console/migrations/m260602_120000_add_user_role_and_guest.php` — change it
before the backend is reachable from outside.

To create your own account, point a second virtual host at `frontend/web` (the
stock Yii template site), sign up there and open the verification link. In the
Development environment mail is not sent: the message is written to
`frontend/runtime/mail/`. Accounts have a `role` column (`admin` / `guest`);
new sign-ups get `guest`, and nothing in the backend is restricted by role yet.

## Running it

**1. Pull the history once.** The first run downloads lifetime daily / 4h / 1h
candles and a recent 15m window for every ticker — roughly half a minute per
symbol:

```bash
php yii trading/fetch-data
```

**2. Start the worker.** This is the only long-lived process; it runs queued
jobs, including delayed ones:

```bash
php yii queue/listen --verbose=1
```

**3. Start the refresh loop.** Run one of these once:

```bash
php yii trading/schedule-refresh        # every 60 s
php yii trading/schedule-refresh 300    # every 5 min
php yii trading/schedule-hourly         # every hour
```

Running a schedule command again replaces the previous loop instead of adding a
second one, so it is also how you change the interval. The pending job lives in
Redis, so the loop survives a worker restart.

**4. Open the backend** and log in.

## Using the backend

### RSI heatmap — `/trading/rsi`

One row per coin: last price, rolling 24h change, Wilder RSI(14) on 1d / 4h /
1h / 15m and the 20-day average daily turnover. The candle still forming on
each timeframe is priced at the live Bybit last price.

- Cells tint green toward oversold and red toward overbought.
- The default sort ranks coins by oversold (≤ 30) / neutral / overbought (≥ 70)
  on 1d first, then 4h, 1h and 15m, with oversold on top. Click a column header
  to sort by that column, e.g. `/trading/rsi?sort=1h&dir=desc`.
- **↻ Refresh** queues a one-off refresh; reload once the worker has run it.
- **thin** marks a coin whose 20-day average daily turnover is under $5M;
  **stale** marks a coin whose candles stopped updating.
- A warning banner appears when prices are more than 30 minutes old or the
  newest candle is more than 2 hours old — the worker or the loop isn't running.

### Chart — `/trading/chart`

| URL | Shows |
|---|---|
| `/trading/chart` | BTC daily candles |
| `/trading/chart?symbol=SOL&zoom=60` | SOL, last 60 days visible (`zoom` is 14–365) |
| `/trading/chart?symbols=BTC,ETH,SOL&zoom=90` | Up to 8 coins as % change since the start of the visible window |
| `/trading/chart?symbol=BTC&entry=65000&stop=62000&side=long&setup=L1&asOf=2026-05-26` | Entry and stop lines plus a marker on the signal day |

Single-coin charts also draw the three most recent support and resistance
levels. The chart library loads from `unpkg.com`, so the browser needs internet
access.

The header has an EN / RU language switch and a light / dark theme toggle.

## Console commands

| Command | What it does |
|---|---|
| `php yii trading/fetch-data` | Full refresh: prices, derivatives, dominance, Fear & Greed, then the RSI board |
| `php yii trading/run-hourly` | Incremental prices + derivatives refresh, then the RSI board (`run-4h` is identical) |
| `php yii trading/run-daily` | Same as `fetch-data` |
| `php yii trading/rsi-board` | Rebuild the RSI board from stored data; `--force=1` recomputes every row |
| `php yii trading/schedule-refresh [seconds]` | Start or replace the refresh loop (default 60 s) |
| `php yii trading/schedule-hourly` | Start or replace the refresh loop at 3600 s |
| `php yii trading/queue-scan` | Queue one refresh without touching the loop |
| `php yii queue/listen --verbose=1` | The worker |
| `php yii queue/info` | Waiting / delayed / reserved / done counts |
| `php yii queue/clear --interactive=0` | Drop every queued job, including the loop |
| `php yii trading/test-telegram` | Send a test message to the configured channel |
| `php yii trading/test-chart BTC` | Render a chart with QuickChart and send it to Telegram |
| `php yii trading/scan`, `scan-4h`, `manage-positions` | No-op stubs for the removed signal engine |

`fetch-data` and the `run-*` commands refresh the data directly, without the
queue or a worker, so they suit cron if you prefer it to the refresh loop.

## Configuration

Defaults are in `common/config/params.php`. Override them in
`common/config/params-local.php`, which is not committed:

```php
return [
    'telegram' => [
        'botToken' => '1234567890:AAH...',   // from @BotFather
        'chatId'   => '-1001234567890',      // channel id, or '@your_channel'
    ],
    'trading' => [
        // 'bybitApiBase' => 'https://api.bybit.com',   // default is the api.bytick.com mirror
    ],
];
```

| Key | Default | Purpose |
|---|---|---|
| `telegram.botToken`, `telegram.chatId` | empty | Needed only for the `test-telegram` / `test-chart` commands |
| `chart.quickChartBase` | `https://quickchart.io` | Renderer for Telegram chart images |
| `trading.bybitApiBase` | `https://api.bytick.com` | Bybit V5 host |
| `trading.historyDir` | `@common/data/history_prices` | Per-symbol history files |
| `trading.macroDir` | `@common/data/history/macro` | Dominance and Fear & Greed series |
| `trading.stateDir` | `@common/data/trade_state` | RSI board and fetch checkpoint |

### The coin universe

`common/data/hashhedge_symbols.json` is the master list of tickers. Add a
ticker there and the refresh loop backfills it — at most 8 new symbols per run,
so a large change fills in over several runs. Remove a ticker and it drops off
the heatmap at the next build.

Tickers resolve to `<TICKER>USDT` on Bybit, spot first and then the linear
perpetual. Exceptions go in `common/data/bybit_symbol_overrides.json` (for
example `PEPE` → `1000PEPEUSDT`).

## Data files

Everything under `common/data/` except the two JSON files above is generated
and gitignored. The web server user and the worker user both need write access.

| Path | Contents |
|---|---|
| `history_prices/<SYMBOL>.json` | Candles per interval plus funding and open-interest history |
| `history/macro/dominance.jsonl` | BTC dominance, total / alt market cap, 24h volume |
| `history/macro/fng.jsonl` | Fear & Greed index |
| `trade_state/rsi_board.json` | Precomputed heatmap rows |
| `trade_state/fetch_state.json` | Result of the last fetch, per step |

To force a full re-pull of one symbol, delete its file in `history_prices/` and
run `php yii trading/fetch-data`.

## Production

```bash
php init --env=Production --overwrite=All
```

Set the database password in `common/config/main-local.php`, run the
migrations, and keep exactly one worker alive. With supervisor:

```ini
[program:crypto-queue]
command=/usr/bin/php /path/to/project/yii queue/listen --verbose=1
directory=/path/to/project
user=www-data
autostart=true
autorestart=true
stopwaitsecs=60
stdout_logfile=/path/to/project/console/runtime/logs/queue.log
redirect_stderr=true
```

Then start the loop once with `php yii trading/schedule-refresh`. Errors from
the worker are logged to `console/runtime/logs/app.log`.
[`docs/TRADING_BOT_SETUP.md`](docs/TRADING_BOT_SETUP.md) §4.3 covers keeping
the worker alive on Windows (NSSM or Task Scheduler).

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `Your Composer dependencies require a PHP version ">= 8.5.0"` | The `php` on your PATH is older. Call the 8.5 binary explicitly. |
| Every backend page errors with a Redis connection failure | Redis is down or `common/config/main-local.php` points at the wrong host. |
| Heatmap says the board hasn't been built | Run `php yii trading/fetch-data`. |
| Red "prices … old" on the heatmap | Start the worker and the refresh loop. |
| "Refresh queued…" never clears | No worker is running. The flag expires on its own after 15 minutes. |
| Fetches take several seconds per request | Switch `trading.bybitApiBase` between `api.bytick.com` and `api.bybit.com`. |

## Development

```bash
php vendor/bin/codecept run Unit Services -c common   # fetcher, market-data and RSI board tests
composer static                                       # PHPStan
composer cs                                           # PHP_CodeSniffer
```

| Path | Contents |
|---|---|
| `common/services/Trading/` | Fetchers, `MarketData`, `RsiBoard`, `TelegramNotifier`, `ChartRenderer` |
| `common/jobs/ScanSignalsJob.php` | The queued refresh job |
| `console/controllers/TradingController.php` | The `trading/*` commands |
| `backend/controllers/TradingController.php`, `backend/views/trading/` | Heatmap and chart pages |
| `common/data/` | Symbol lists and all generated data |
| `frontend/` | Unmodified Yii template site, used only for sign-up |

`docker-compose.yml`, the two `Dockerfile`s and the Vagrant setup are leftovers
from the Yii template (PHP 8.4 / 7.4, MySQL, no Redis) and will not run this
project as they are.

## Research and background

These documents describe the removed signal engine and the analysis behind it.
They are reference material for the next strategy, not a description of what
the code does today.

- [`CRYPTO.md`](CRYPTO.md) ([RU](CRYPTO_RU.md)) — pump / dump pattern analysis
  over price, funding, open interest and macro data
- [`TRADE.md`](TRADE.md) ([RU](TRADE_RU.md)) — the entry and stop playbook
  derived from it
- [`TRADE_SIMULATION_RESULT.md`](TRADE_SIMULATION_RESULT.md) and
  [`docs/results/`](docs/results/README.md) — backtests and the experiment log
- [`signal_algorithm_ru.md`](signal_algorithm_ru.md) — mathematical description
  of a signal-detection algorithm (Russian)
- [`docs/TRADING_BOT_SETUP.md`](docs/TRADING_BOT_SETUP.md) — setup guide for the
  old bot; the queue and worker sections still apply, the scan, position and
  dashboard sections do not

## License

BSD-3-Clause, inherited from the Yii template — see [`LICENSE.md`](LICENSE.md).
