<?php

declare(strict_types=1);

namespace common\services\Trading\Fetcher;

use RuntimeException;

/**
 * Shared Bybit V5 public-API client used by the price + derivatives fetchers.
 *
 *   - Retries up to MAX_RETRIES on transport errors and HTTP 429/403.
 *   - Throws on every other non-200, and on retCode != 0 in the JSON body.
 *   - Returns the `result` map on success.
 */
final class BybitHttp
{
    public const BASE = 'https://api.bybit.com';
    public const SLEEP_BETWEEN_CALLS_US = 120_000;   // 120 ms, ~8 rps
    public const MAX_RETRIES = 4;
    public const RETRY_BACKOFF_SECS = [2, 5, 15, 30];

    private string $userAgent;
    /** @var callable|null fn(string $message): void — optional progress sink */
    private $log;

    public function __construct(string $userAgent = 'crypto-bot-fetcher-php/1.0', ?callable $log = null)
    {
        $this->userAgent = $userAgent;
        $this->log = $log;
    }

    /** @param array<string,mixed> $params */
    public function get(string $path, array $params = []): array
    {
        $url = self::BASE . $path;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $lastErr = null;
        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT      => $this->userAgent,
                CURLOPT_HTTPHEADER     => ['accept: application/json'],
                CURLOPT_FAILONERROR    => false,
            ]);
            $body    = curl_exec($ch);
            $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($body === false) {
                $lastErr = $curlErr;
                $wait = self::RETRY_BACKOFF_SECS[min($attempt, count(self::RETRY_BACKOFF_SECS) - 1)];
                $this->emit("   network error ($curlErr), sleeping {$wait}s ...");
                sleep($wait);
                continue;
            }
            if ($status === 200) {
                $data = json_decode((string)$body, associative: true, flags: JSON_THROW_ON_ERROR);
                if (($data['retCode'] ?? null) === 0) {
                    return $data['result'] ?? [];
                }
                throw new RuntimeException(
                    'Bybit error ' . ($data['retCode'] ?? '?') . ': ' . ($data['retMsg'] ?? '?'),
                );
            }
            if ($status === 429 || $status === 403) {
                $wait = self::RETRY_BACKOFF_SECS[$attempt] ?? 30;
                $this->emit("   throttled ($status), sleeping {$wait}s ...");
                sleep($wait);
                continue;
            }
            throw new RuntimeException("HTTP $status from $url: " . substr((string)$body, 0, 200));
        }
        throw new RuntimeException('giving up after ' . self::MAX_RETRIES . " retries: $lastErr");
    }

    public function throttle(): void
    {
        usleep(self::SLEEP_BETWEEN_CALLS_US);
    }

    private function emit(string $msg): void
    {
        if ($this->log !== null) {
            ($this->log)($msg);
        }
    }
}
