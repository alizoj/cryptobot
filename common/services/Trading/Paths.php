<?php

declare(strict_types=1);

namespace common\services\Trading;

use Yii;

/**
 * Resolves the bot's filesystem paths from Yii::$app->params['trading'].
 *
 * All paths are absolute and have their parent directory created on first
 * access (only the state dir needs that — history/macro dirs are produced
 * by the fetcher scripts).
 */
final class Paths
{
    public static function dataDir(): string
    {
        return self::resolve('dataDir', '@common/data');
    }

    public static function historyPricesDir(): string
    {
        return self::resolve('historyDir', '@common/data/history_prices');
    }

    public static function macroDir(): string
    {
        return self::resolve('macroDir', '@common/data/history/macro');
    }

    public static function stateDir(): string
    {
        $p = self::resolve('stateDir', '@common/data/trade_state');
        if (!is_dir($p)) {
            mkdir($p, 0775, recursive: true);
        }
        return $p;
    }

    public static function fngFile(): string
    {
        return self::macroDir() . '/fng.jsonl';
    }

    public static function dominanceFile(): string
    {
        return self::macroDir() . '/dominance.jsonl';
    }

    public static function instrumentsIndex(): string
    {
        return self::historyPricesDir() . '/_instruments_index.json';
    }

    public static function openPositions(): string
    {
        return self::stateDir() . '/open_positions.jsonl';
    }

    public static function closedPositions(): string
    {
        return self::stateDir() . '/closed_positions.jsonl';
    }

    public static function signalLog(): string
    {
        return self::stateDir() . '/signal_log.jsonl';
    }

    public static function fetchState(): string
    {
        return self::stateDir() . '/fetch_state.json';
    }

    public static function uiSettings(): string
    {
        return self::stateDir() . '/ui_settings.json';
    }

    public static function lockFile(string $name): string
    {
        return self::stateDir() . "/{$name}.lock";
    }

    private static function resolve(string $key, string $fallback): string
    {
        $alias = Yii::$app->params['trading'][$key] ?? $fallback;
        return Yii::getAlias($alias);
    }
}
