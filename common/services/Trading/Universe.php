<?php

declare(strict_types=1);

namespace common\services\Trading;

use Yii;

/**
 * Universe filter — wraps the TRADE.md §2 rules so SignalEngine doesn't
 * need to know about blacklist semantics or the memecoin special case.
 */
final class Universe
{
    /** @var list<string> */
    private array $blacklist;

    /** @var list<string> */
    private array $memecoinPriceOnly;

    private int $minCandles;

    private float $minTurnover;

    public function __construct(?array $config = null)
    {
        $cfg = $config ?? (Yii::$app->params['trading'] ?? []);
        $this->blacklist         = $cfg['blacklist'] ?? [];
        $this->memecoinPriceOnly = $cfg['memecoinPriceOnly'] ?? [];
        $this->minCandles        = (int)($cfg['minDailyCandles'] ?? 60);
        $this->minTurnover       = (float)($cfg['minTurnover20dUsd'] ?? 5_000_000);
    }

    public function isBlacklisted(string $symbol): bool
    {
        return in_array($symbol, $this->blacklist, true);
    }

    public function isMemecoinPriceOnly(string $symbol): bool
    {
        return in_array($symbol, $this->memecoinPriceOnly, true);
    }

    /**
     * Returns null on pass, otherwise a short rejection reason for logging.
     *
     * @param list<array<string, mixed>> $features daily feature rows
     */
    public function rejectReason(string $symbol, array $features): ?string
    {
        if ($this->isBlacklisted($symbol)) {
            return 'blacklisted';
        }
        if (count($features) < $this->minCandles) {
            return 'insufficient_candles';
        }
        $last = end($features) ?: null;
        if ($last === null || $last['turnover20'] === null) {
            return 'no_turnover20';
        }
        if ($last['turnover20'] < $this->minTurnover) {
            return 'turnover_below_threshold';
        }
        return null;
    }
}
