<?php

declare(strict_types=1);

namespace common\services\Trading\Fetcher;

use common\services\Trading\Paths;
use RuntimeException;

/**
 * Pull BTC dominance + total mcap + altcoin mcap from CoinMarketCap's
 * unofficial data-api and write to common/data/history/macro/dominance.jsonl.
 *
 * Endpoint is unofficial; the service accepts a `refresh` flag to re-fetch
 * the full history if the API path changes.
 */
final class DominanceFetcher
{
    public const CMC_URL = 'https://api.coinmarketcap.com/data-api/v3/global-metrics/quotes/historical';
    public const START_TS = 1366848000;           // 2013-04-25
    public const CHUNK_DAYS = 1800;
    public const SLEEP_BETWEEN_US = 200_000;

    /**
     * @param array{full?:bool} $opts  full=true → re-pull the lifetime series
     *                                 false (default) → only fetch from the last
     *                                 stored day onward (incremental).
     */
    public function run(array $opts = [], ?callable $log = null): FetchResult
    {
        $log ??= static function (string $_): void {};
        $started = microtime(true);

        $full = (bool)($opts['full'] ?? false);
        $output = Paths::dominanceFile();
        @mkdir(dirname($output), 0775, recursive: true);

        $existing = $this->loadExisting($output);
        $startTs = $full || empty($existing)
            ? self::START_TS
            : (int)max(array_keys($existing));

        $now = time();
        $cursor = $startTs;
        $added = 0;

        $log("Fetching CMC global metrics in " . self::CHUNK_DAYS . "-day chunks (from " . gmdate('Y-m-d', $cursor) . ")");
        while ($cursor < $now) {
            $end = min($now, $cursor + self::CHUNK_DAYS * 86400);
            $url = self::CMC_URL . '?' . http_build_query([
                'timeStart' => $cursor,
                'timeEnd'   => $end,
                'interval'  => '1d',
                'convert'   => 'USD',
            ]);
            $log("  " . gmdate('Y-m-d', $cursor) . " -> " . gmdate('Y-m-d', $end));
            $data = $this->httpGetJson($url);
            $quotes = $data['data']['quotes'] ?? [];
            $log("  +" . count($quotes) . " records");
            foreach ($quotes as $q) {
                $ts = strtotime($q['timestamp']);
                if (!$ts) {
                    continue;
                }
                $quote = $q['quote'][0] ?? null;
                $row = [
                    't'          => $ts,
                    'date'       => gmdate('Y-m-d', $ts),
                    'btc_d'      => round((float)$q['btcDominance'], 4),
                    'eth_d'      => round((float)$q['ethDominance'], 4),
                    'total_mcap' => $quote ? (float)$quote['totalMarketCap'] : null,
                    'alt_mcap'   => $quote ? (float)$quote['altcoinMarketCap'] : null,
                    'vol_24h'    => $quote ? (float)$quote['totalVolume24H'] : null,
                ];
                if (!isset($existing[$ts])) {
                    $added++;
                }
                $existing[$ts] = $row;
            }
            $cursor = $end + 1;
            usleep(self::SLEEP_BETWEEN_US);
        }

        ksort($existing);
        $fh = fopen($output, 'wb');
        foreach ($existing as $row) {
            fwrite($fh, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n");
        }
        fclose($fh);

        $first = reset($existing);
        $last  = end($existing);
        $log("Wrote " . count($existing) . " daily records to $output");
        $log("  range: " . ($first['date'] ?? '?') . " -> " . ($last['date'] ?? '?'));

        $elapsed = round(microtime(true) - $started, 2);
        $summary = "dominance: added=$added total=" . count($existing) . " last=" . ($last['date'] ?? '?');
        return FetchResult::ok('dominance', $elapsed, $summary, [
            'added' => $added,
            'total' => count($existing),
            'last'  => $last['date'] ?? null,
        ]);
    }

    /** @return array<int, array<string, mixed>>  ts → record */
    private function loadExisting(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $out = [];
        $fh = fopen($path, 'rb');
        while (($line = fgets($fh)) !== false) {
            $r = json_decode(trim($line), true);
            if (is_array($r) && isset($r['t'])) {
                $out[(int)$r['t']] = $r;
            }
        }
        fclose($fh);
        return $out;
    }

    private function httpGetJson(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; crypto-bot-macro-fetcher/1.0)',
            CURLOPT_HTTPHEADER     => ['accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException("curl: $err");
        }
        if ($status !== 200) {
            throw new RuntimeException("HTTP $status: " . substr((string)$body, 0, 200));
        }
        $data = json_decode((string)$body, associative: true, flags: JSON_THROW_ON_ERROR);
        $code = $data['status']['error_code'] ?? '0';
        if ($code !== '0' && $code !== 0) {
            throw new RuntimeException("API error $code: " . ($data['status']['error_message'] ?? '?'));
        }
        return $data;
    }
}
