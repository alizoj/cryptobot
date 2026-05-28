<?php

declare(strict_types=1);

namespace common\services\Trading;

/**
 * Append-only JSONL store for open and closed positions + signal log.
 *
 * Format is one JSON record per line. Open positions are rewritten on
 * every update (rare, small file), closed positions and signals are
 * append-only.
 */
final class PositionStore
{
    /** @return list<array<string, mixed>> */
    public function openPositions(): array
    {
        return $this->readJsonl(Paths::openPositions());
    }

    /** @return list<array<string, mixed>> */
    public function closedPositions(): array
    {
        return $this->readJsonl(Paths::closedPositions());
    }

    /** @param array<string, mixed> $position must include 'id' (string) */
    public function appendOpen(array $position): void
    {
        $this->appendJsonl(Paths::openPositions(), $position);
    }

    /**
     * Replace the full open-position list (used after mutating updates).
     * @param list<array<string, mixed>> $positions
     */
    public function replaceOpen(array $positions): void
    {
        $this->writeJsonl(Paths::openPositions(), $positions);
    }

    /** @param array<string, mixed> $position */
    public function appendClosed(array $position): void
    {
        $this->appendJsonl(Paths::closedPositions(), $position);
    }

    /** @param array<string, mixed> $signal */
    public function logSignal(array $signal): void
    {
        $this->appendJsonl(Paths::signalLog(), $signal);
    }

    /** @return list<array<string, mixed>> */
    public function recentSignals(int $limit = 100): array
    {
        $all = $this->readJsonl(Paths::signalLog());
        return array_slice($all, -$limit);
    }

    public function hasOpenForSymbolSetup(string $symbol, string $setup, string $asOf): bool
    {
        foreach ($this->openPositions() as $p) {
            if (($p['symbol'] ?? null) === $symbol
                && ($p['setup'] ?? null) === $setup
                && ($p['as_of'] ?? null) === $asOf
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Append an open position derived from a signal payload. Returns one of:
     *   - 'opened'       — appended
     *   - 'dedup'        — same (symbol, setup, as_of) already open
     *   - 'cap_reached'  — would exceed $maxConcurrent
     *
     * @param array<string,mixed> $sig signal payload from SignalEngine::scan*()
     */
    public function openFromSignal(array $sig, int $maxConcurrent): string
    {
        $open = $this->openPositions();
        if (count($open) >= $maxConcurrent) {
            return 'cap_reached';
        }
        $asOf = (string)$sig['as_of'];
        if ($this->hasOpenForSymbolSetup($sig['symbol'], $sig['setup'], $asOf)) {
            return 'dedup';
        }
        $this->appendOpen([
            'id'             => self::signalId($asOf, $sig['symbol'], $sig['setup']),
            'as_of'          => $asOf,
            'symbol'         => $sig['symbol'],
            'setup'          => $sig['setup'],
            'side'           => $sig['side'],
            'method'         => $sig['method'],
            'entry_px'       => $sig['entry_px'] ?? $sig['entry_estimate'],
            'entry_day'      => $sig['as_of_day'] ?? $asOf,
            'stop_px'        => $sig['stop_px'],
            'time_exit_days' => $sig['time_exit_days'],
            'beta'           => $sig['beta'],
            'size'           => $sig['size'],
            'reasons'        => $sig['reasons'],
            'context'        => $sig['context'],
            'opened_ts'      => time(),
            'stop_history'   => [],
        ]);
        return 'opened';
    }

    /** Generate a stable id for a signal so dedup is deterministic. */
    public static function signalId(string $asOf, string $symbol, string $setup): string
    {
        return "{$asOf}-{$symbol}-{$setup}";
    }

    private function readJsonl(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $out = [];
        $fh = fopen($path, 'rb');
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $rec = json_decode($line, true);
            if (is_array($rec)) {
                $out[] = $rec;
            }
        }
        fclose($fh);
        return $out;
    }

    private function appendJsonl(string $path, array $record): void
    {
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            return;
        }
        file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    private function writeJsonl(string $path, array $records): void
    {
        $buf = '';
        foreach ($records as $r) {
            $line = json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($line !== false) {
                $buf .= $line . "\n";
            }
        }
        file_put_contents($path, $buf, LOCK_EX);
    }
}
