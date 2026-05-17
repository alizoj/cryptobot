<?php

declare(strict_types=1);

namespace common\services\Trading\Fetcher;

use common\services\Trading\Paths;
use RuntimeException;

/**
 * Pull Crypto Fear & Greed Index history from alternative.me and append
 * to common/data/history/macro/fng.jsonl. Idempotent — only appends days
 * not already in the file.
 *
 * Extracted from common/data/fetch_fng.php.
 */
final class FngFetcher
{
    public const URL = 'https://api.alternative.me/fng/?limit=0&format=json';

    public function run(array $opts = [], ?callable $log = null): FetchResult
    {
        $log ??= static function (string $_): void {};
        $started = microtime(true);
        $output = Paths::fngFile();
        @mkdir(dirname($output), 0775, recursive: true);

        $existing = [];
        if (is_file($output)) {
            $fh = fopen($output, 'rb');
            while (($line = fgets($fh)) !== false) {
                $r = json_decode(trim($line), true);
                if (is_array($r) && isset($r['t'])) {
                    $existing[gmdate('Y-m-d', (int)$r['t'])] = true;
                }
            }
            fclose($fh);
        }
        $log("Existing FNG days: " . count($existing));

        $log("Fetching from alternative.me ...");
        $data = $this->httpGetJson(self::URL);
        $rows = $data['data'] ?? [];
        $log("API returned " . count($rows) . " records");

        $append = [];
        foreach ($rows as $r) {
            $ts = (int)($r['timestamp'] ?? 0);
            if ($ts === 0) {
                continue;
            }
            $day = gmdate('Y-m-d', $ts);
            if (isset($existing[$day])) {
                continue;
            }
            $append[$ts] = ['t' => $ts, 'v' => (int)($r['value'] ?? 0)];
        }
        ksort($append);

        if (empty($append)) {
            $log("No new days.");
            $elapsed = round(microtime(true) - $started, 2);
            return FetchResult::ok('fng', $elapsed, 'fng: no new days', ['added' => 0, 'total' => count($existing)]);
        }

        $fh = fopen($output, 'ab');
        foreach ($append as $row) {
            fwrite($fh, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n");
        }
        fclose($fh);
        $latest = gmdate('Y-m-d', max(array_keys($append)));
        $log("Appended " . count($append) . " days; latest = $latest");

        $elapsed = round(microtime(true) - $started, 2);
        return FetchResult::ok('fng', $elapsed, "fng: added=" . count($append) . " latest=$latest", [
            'added' => count($append),
            'latest' => $latest,
        ]);
    }

    private function httpGetJson(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'crypto-bot-fng-fetcher/1.0',
            CURLOPT_HTTPHEADER     => ['accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException("curl: $err");
        }
        if ($code !== 200) {
            throw new RuntimeException("HTTP $code: " . substr((string)$body, 0, 200));
        }
        return json_decode((string)$body, associative: true, flags: JSON_THROW_ON_ERROR);
    }
}
