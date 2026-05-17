<?php

declare(strict_types=1);

namespace common\services\Trading\Fetcher;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Encoding + timestamp helpers shared by all on-disk artifacts. Kept
 * byte-compatible with the Python originals so the simulator's existing
 * snapshots stay valid.
 */
final class JsonFormatter
{
    /**
     * Pretty-print JSON with 2-space indent (Python json.dump(indent=2)
     * default). PHP uses 4-space by default; we halve the leading spaces.
     */
    public static function encode2Space(mixed $data): string
    {
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        return preg_replace_callback(
            '/^( +)/m',
            static fn(array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)),
            $json,
        );
    }

    public static function isoUtcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s.uP');
    }

    public static function isoUtcFromMs(int $ms): string
    {
        return (new DateTimeImmutable('@' . intdiv($ms, 1000)))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:sP');
    }
}
