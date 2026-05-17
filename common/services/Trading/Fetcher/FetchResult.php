<?php

declare(strict_types=1);

namespace common\services\Trading\Fetcher;

/**
 * Uniform result returned by every fetcher service so DataFetcher can
 * record + report status with no type juggling.
 */
final class FetchResult
{
    public function __construct(
        public readonly string $step,
        public readonly bool $ok,
        public readonly int $exitCode,
        public readonly float $seconds,
        public readonly string $summary,
        /** @var array<string, mixed> per-fetcher counters (added, updated, failures, …) */
        public readonly array $stats = [],
        /** @var string  short tail of log output (for the fetch_state.json checkpoint) */
        public readonly string $logTail = '',
    ) {
    }

    public static function ok(string $step, float $seconds, string $summary, array $stats = [], string $logTail = ''): self
    {
        return new self($step, true, 0, $seconds, $summary, $stats, $logTail);
    }

    public static function fail(string $step, float $seconds, string $summary, array $stats = [], string $logTail = ''): self
    {
        return new self($step, false, 1, $seconds, $summary, $stats, $logTail);
    }

    public function toArray(): array
    {
        return [
            'step'     => $this->step,
            'ok'       => $this->ok,
            'exitCode' => $this->exitCode,
            'seconds'  => $this->seconds,
            'summary'  => $this->summary,
            'stats'    => $this->stats,
            'output'   => $this->logTail,
        ];
    }
}
