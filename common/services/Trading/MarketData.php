<?php

declare(strict_types=1);

namespace common\services\Trading;

/**
 * Loaders for the per-symbol JSON files produced by fetch_bybit_data.php
 * and fetch_bybit_derivatives.php, plus the macro JSONL files.
 *
 * Pulled out of the simulator into a class so SignalEngine can hold one
 * instance and avoid re-decoding files between symbols.
 */
final class MarketData
{
    /**
     * Per-symbol JSONs are 5-10 MB each. We keep at most ONE in memory at
     * a time — callers that need the same record back-to-back will benefit
     * from the cache; iterating the universe won't blow the heap.
     */
    private ?string $cachedSymbol = null;
    private ?array $cachedRecord = null;

    /** @var array<string, array<string,int>>|null */
    private ?array $fng = null;

    /** @var array<string, array<string,mixed>>|null */
    private ?array $dominance = null;

    public function loadSymbol(string $symbol): ?array
    {
        if ($this->cachedSymbol === $symbol) {
            return $this->cachedRecord;
        }
        $path = Paths::historyPricesDir() . "/{$symbol}.json";
        if (!is_file($path)) {
            $this->cachedSymbol = $symbol;
            $this->cachedRecord = null;
            return null;
        }
        $rec = json_decode((string)file_get_contents($path), true);
        $this->cachedSymbol = $symbol;
        $this->cachedRecord = is_array($rec) ? $rec : null;
        return $this->cachedRecord;
    }

    /** Drop the in-memory record. Caller can do this after finishing a symbol. */
    public function forgetSymbol(): void
    {
        $this->cachedSymbol = null;
        $this->cachedRecord = null;
    }

    /** @return list<string> symbol tickers excluding leading-underscore index files */
    public function listSymbols(): array
    {
        $files = glob(Paths::historyPricesDir() . '/*.json') ?: [];
        $out = [];
        foreach ($files as $f) {
            $name = pathinfo($f, PATHINFO_FILENAME);
            if (!str_starts_with($name, '_')) {
                $out[] = $name;
            }
        }
        sort($out);
        return $out;
    }

    /** @return list<array{ts:int,o:float,h:float,l:float,c:float,v:float}> */
    public function dailyCandles(string $symbol): array
    {
        $rec = $this->loadSymbol($symbol);
        if (!$rec) {
            return [];
        }
        $rows = $rec['history']['intervals']['D']['rows'] ?? [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'ts' => (int)$r[0],
                'o'  => (float)$r[1],
                'h'  => (float)$r[2],
                'l'  => (float)$r[3],
                'c'  => (float)$r[4],
                'v'  => (float)$r[5],
            ];
        }
        return $out;
    }

    /** @return list<array{ts:int,o:float,h:float,l:float,c:float}> */
    public function hourlyCandles(string $symbol): array
    {
        $rec = $this->loadSymbol($symbol);
        if (!$rec) {
            return [];
        }
        $rows = $rec['history']['intervals']['1h']['rows'] ?? [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'ts' => (int)$r[0],
                'o'  => (float)$r[1],
                'h'  => (float)$r[2],
                'l'  => (float)$r[3],
                'c'  => (float)$r[4],
            ];
        }
        return $out;
    }

    /** @return list<array{ts:int,o:float,h:float,l:float,c:float}> */
    public function fourHourCandles(string $symbol): array
    {
        $rec = $this->loadSymbol($symbol);
        if (!$rec) {
            return [];
        }
        $rows = $rec['history']['intervals']['4h']['rows'] ?? [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'ts' => (int)$r[0],
                'o'  => (float)$r[1],
                'h'  => (float)$r[2],
                'l'  => (float)$r[3],
                'c'  => (float)$r[4],
            ];
        }
        return $out;
    }

    /** @return list<array{ts:int,o:float,h:float,l:float,c:float}> weekly (interval W) */
    public function weeklyCandles(string $symbol): array
    {
        $rec = $this->loadSymbol($symbol);
        if (!$rec) {
            return [];
        }
        $rows = $rec['history']['intervals']['W']['rows'] ?? [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'ts' => (int)$r[0],
                'o'  => (float)$r[1],
                'h'  => (float)$r[2],
                'l'  => (float)$r[3],
                'c'  => (float)$r[4],
            ];
        }
        return $out;
    }

    public function hasDerivatives(string $symbol): bool
    {
        $rec = $this->loadSymbol($symbol);
        return isset($rec['derivatives']['funding']['rows']);
    }

    /** @return list<array{0:string,1:string}> raw [timestamp_ms,rate] tuples */
    public function fundingRaw(string $symbol): array
    {
        $rec = $this->loadSymbol($symbol);
        return $rec['derivatives']['funding']['rows'] ?? [];
    }

    /** @return list<array{0:string,1:string}> raw [timestamp_ms,oi] tuples */
    public function openInterestRaw(string $symbol): array
    {
        $rec = $this->loadSymbol($symbol);
        return $rec['derivatives']['open_interest']['rows'] ?? [];
    }

    /** @return array<string, array{t:int,v:int,date:string}> indexed by 'Y-m-d' */
    public function fng(): array
    {
        if ($this->fng !== null) {
            return $this->fng;
        }
        $path = Paths::fngFile();
        if (!is_file($path)) {
            return $this->fng = [];
        }
        $out = [];
        $fh = fopen($path, 'rb');
        while (($line = fgets($fh)) !== false) {
            $r = json_decode(trim($line), true);
            if (!$r || !isset($r['t'])) {
                continue;
            }
            $day = gmdate('Y-m-d', (int)$r['t']);
            $r['date'] = $day;
            $out[$day] = $r;
        }
        fclose($fh);
        return $this->fng = $out;
    }

    /** @return array<string, array<string,mixed>> indexed by 'Y-m-d' */
    public function dominance(): array
    {
        if ($this->dominance !== null) {
            return $this->dominance;
        }
        $path = Paths::dominanceFile();
        if (!is_file($path)) {
            return $this->dominance = [];
        }
        $out = [];
        $fh = fopen($path, 'rb');
        while (($line = fgets($fh)) !== false) {
            $r = json_decode(trim($line), true);
            if (!$r || !isset($r['date'])) {
                continue;
            }
            $out[$r['date']] = $r;
        }
        fclose($fh);
        return $this->dominance = $out;
    }

    public function btcDelta30d(string $day): ?float
    {
        $dom = $this->dominance();
        if (!isset($dom[$day])) {
            return null;
        }
        $dt = new \DateTimeImmutable($day, new \DateTimeZone('UTC'));
        $prev = $dt->modify('-30 day')->format('Y-m-d');
        if (!isset($dom[$prev])) {
            return null;
        }
        return (float)$dom[$day]['btc_d'] - (float)$dom[$prev]['btc_d'];
    }
}
