<?php

declare(strict_types=1);

namespace common\services\Trading\Fetcher;

use RuntimeException;

/**
 * Binance USD-M futures (fapi) — the fallback price source for HashHedge
 * tickers Bybit doesn't list: a few alts Bybit delisted (JOE, SPELL, DODOX)
 * and the TradFi perps it doesn't carry (platinum, palladium, copper, natural
 * gas, some equities). Every HashHedge instrument trades here, so a ticker
 * that resolves on neither exchange isn't on HashHedge either.
 *
 * Only what the price fetcher and the RSI board need: the instruments list
 * (exchangeInfo, disk-cached and refreshed daily like {@see InstrumentsIndex}),
 * forward-paginated klines in Bybit's row shape, and 24h tickers.
 *
 * Tickers resolve as {SYMBOL}USDT only — enough for every current fallback;
 * a 1000x-style listing would need an override like Bybit's.
 */
final class BinanceFutures
{
    /** Stored in the history record's bybit_category so readers know the row source. */
    public const CATEGORY = 'binance_um';

    public const BASE = 'https://fapi.binance.com';
    public const KLINE_LIMIT = 1500;
    public const MAX_PAGES = 50;
    public const INDEX_MAX_AGE_SEC = 86_400;
    private const SLEEP_BETWEEN_CALLS_US = 100_000;
    private const RETRY_BACKOFF_SECS = [2, 5, 15];

    /** Fetcher interval label => Binance kline interval. */
    public const INTERVALS = [
        'M'   => '1M',
        'W'   => '1w',
        'D'   => '1d',
        '12h' => '12h',
        '6h'  => '6h',
        '4h'  => '4h',
        '2h'  => '2h',
        '1h'  => '1h',
        '30m' => '30m',
        '15m' => '15m',
        '5m'  => '5m',
        '3m'  => '3m',
        '1m'  => '1m',
    ];

    private string $base;
    private ?\CurlHandle $ch = null;

    /** @var array<string, array<string,mixed>>|null symbol => instrument */
    private ?array $index = null;

    public function __construct(private readonly ?string $indexPath = null, ?string $base = null)
    {
        $base ??= \Yii::$app?->params['trading']['binanceFuturesBase'] ?? null;
        $this->base = rtrim($base ?? self::BASE, '/');
    }

    /**
     * [binanceSymbol, CATEGORY, instrument] for a HashHedge ticker listed as a
     * trading USDT perpetual (crypto or TradFi), or null. The instrument
     * carries 'launchTime' (= onboardDate) like a Bybit one.
     *
     * @return array{0:string, 1:string, 2:array<string,mixed>}|null
     */
    public function resolve(string $hashhedgeSymbol): ?array
    {
        $symbol = strtoupper($hashhedgeSymbol) . 'USDT';
        $inst = $this->index()[$symbol] ?? null;
        if ($inst === null || ($inst['status'] ?? '') !== 'TRADING') {
            return null;
        }
        return [$symbol, self::CATEGORY, $inst];
    }

    /**
     * Klines of $label from $startMs forward to now, ascending, as Bybit-shaped
     * rows [startTime_ms, open, high, low, close, volume, turnover] (strings).
     *
     * @return list<list<string>>
     */
    public function klines(string $symbol, string $label, int $startMs): array
    {
        $interval = self::INTERVALS[$label] ?? throw new RuntimeException("Unknown interval '$label'");
        $nowMs  = (int)(microtime(true) * 1000);
        $cursor = $startMs;
        $out    = [];
        for ($page = 0; $cursor < $nowMs && $page < self::MAX_PAGES; $page++) {
            if ($page > 0) {
                usleep(self::SLEEP_BETWEEN_CALLS_US);
            }
            $rows = $this->get('/fapi/v1/klines', [
                'symbol'    => $symbol,
                'interval'  => $interval,
                'startTime' => $cursor,
                'limit'     => self::KLINE_LIMIT,
            ]);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $r) {
                $out[] = self::toRow($r);
            }
            $lastTs = (int)$rows[count($rows) - 1][0];
            if (count($rows) < self::KLINE_LIMIT || $lastTs < $cursor) {
                break;   // a short page is the last one — no trailing empty request
            }
            $cursor = $lastTs + 1;
        }
        return $out;
    }

    /**
     * One Binance kline [openTime, o, h, l, c, volume, closeTime, quoteVolume, ...]
     * as the stored row [startTime_ms, open, high, low, close, volume, turnover].
     *
     * @param list<mixed> $k
     * @return list<string>
     */
    public static function toRow(array $k): array
    {
        return [(string)$k[0], (string)$k[1], (string)$k[2], (string)$k[3], (string)$k[4], (string)$k[5], (string)$k[7]];
    }

    /**
     * Last price + rolling 24h change (%) for every USD-M symbol — one call.
     *
     * @return array<string, array{price:float, chg24h:?float}> symbol => quote
     */
    public function tickers(): array
    {
        $out = [];
        foreach ($this->get('/fapi/v1/ticker/24hr') as $t) {
            if (!isset($t['symbol'], $t['lastPrice']) || (float)$t['lastPrice'] <= 0) {
                continue;
            }
            $out[(string)$t['symbol']] = [
                'price'  => (float)$t['lastPrice'],
                'chg24h' => is_numeric($t['priceChangePercent'] ?? null) ? (float)$t['priceChangePercent'] : null,
            ];
        }
        return $out;
    }

    /**
     * USDT perpetuals from exchangeInfo, memoized and disk-cached for a day;
     * a failed refresh falls back to the stale cache.
     *
     * @return array<string, array<string,mixed>>
     */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        $path = $this->indexPath;
        $cached = $path !== null && is_file($path);
        if ($cached && time() - (int)filemtime($path) < self::INDEX_MAX_AGE_SEC) {
            return $this->index = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        }
        try {
            $index = [];
            foreach ($this->get('/fapi/v1/exchangeInfo')['symbols'] ?? [] as $s) {
                if (($s['quoteAsset'] ?? '') !== 'USDT' || !str_contains((string)($s['contractType'] ?? ''), 'PERPETUAL')) {
                    continue;   // PERPETUAL and TRADIFI_PERPETUAL; no dated quarterlies
                }
                $index[$s['symbol']] = [
                    'symbol'         => $s['symbol'],
                    'status'         => $s['status'] ?? '',
                    'contractType'   => $s['contractType'],
                    'underlyingType' => $s['underlyingType'] ?? null,
                    'launchTime'     => (string)($s['onboardDate'] ?? '0'),
                ];
            }
            if ($index === []) {
                throw new RuntimeException('Binance exchangeInfo returned no USDT perpetuals');
            }
            if ($path !== null) {
                @mkdir(dirname($path), 0775, recursive: true);
                file_put_contents($path, JsonFormatter::encode2Space($index));
            }
            return $this->index = $index;
        } catch (\Throwable $e) {
            if (!$cached) {
                throw $e;
            }
            return $this->index = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        }
    }

    /** GET a public fapi endpoint; retries transport errors, 429/418 and 5xx. */
    private function get(string $path, array $params = []): array
    {
        $url = $this->base . $path . ($params !== [] ? '?' . http_build_query($params) : '');
        $lastErr = '';
        for ($attempt = 0; $attempt <= count(self::RETRY_BACKOFF_SECS); $attempt++) {
            if ($attempt > 0) {
                sleep(self::RETRY_BACKOFF_SECS[$attempt - 1]);
            }
            $ch = $this->ch ??= curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT      => 'crypto-bot-fetcher-php/1.0',
                CURLOPT_HTTPHEADER     => ['accept: application/json'],
            ]);
            $body   = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($body === false) {
                $lastErr = curl_error($ch);
                curl_close($ch);
                $this->ch = null;
                continue;
            }
            if ($status === 200) {
                return json_decode((string)$body, true, flags: JSON_THROW_ON_ERROR);
            }
            $lastErr = "HTTP $status: " . substr((string)$body, 0, 200);
            if ($status !== 429 && $status !== 418 && $status < 500) {
                break;   // 4xx (bad symbol, geo-block 451, ...) won't fix itself on retry
            }
        }
        throw new RuntimeException("Binance $path failed: $lastErr");
    }
}
