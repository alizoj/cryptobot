<?php

declare(strict_types=1);

namespace common\services\Trading\Fetcher;

/**
 * Tiny callable-friendly log buffer. Captures every line emitted by a
 * fetcher and optionally forwards it to a downstream sink (e.g. the
 * console controller's $this->stdout).
 *
 * The captured tail (last N lines) is persisted into fetch_state.json
 * by DataFetcher.
 */
final class LogTap
{
    /** @var list<string> */
    private array $buffer = [];

    /** @var callable|null fn(string $line): void */
    private $forward;

    public function __construct(?callable $forward = null, private readonly int $maxLines = 200)
    {
        $this->forward = $forward;
    }

    public function __invoke(string $line): void
    {
        $line = rtrim($line, "\r\n");
        $this->buffer[] = $line;
        if (count($this->buffer) > $this->maxLines) {
            array_shift($this->buffer);
        }
        if ($this->forward !== null) {
            ($this->forward)($line);
        }
    }

    public function tail(int $lines = 20): string
    {
        return implode("\n", array_slice($this->buffer, -$lines));
    }
}
