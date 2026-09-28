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

    /** Master list of HashHedge tickers ({"symbols": [...]}) — the universe every fetcher and board works on. */
    public static function symbolsFile(): string
    {
        return self::resolve('symbolsFile', '@common/data/hashhedge_symbols.json');
    }

    public static function instrumentsIndex(): string
    {
        return self::historyPricesDir() . '/_instruments_index.json';
    }

    /** Cached Binance USD-M perpetuals list (fallback price source). */
    public static function binanceIndex(): string
    {
        return self::historyPricesDir() . '/_binance_um_index.json';
    }

    public static function fetchState(): string
    {
        return self::stateDir() . '/fetch_state.json';
    }

    /** Precomputed RSI heatmap rows — built in the background, read by /trading/rsi. */
    public static function rsiBoardFile(): string
    {
        return self::stateDir() . '/rsi_board.json';
    }

    private static function resolve(string $key, string $fallback): string
    {
        $alias = Yii::$app->params['trading'][$key] ?? $fallback;
        return Yii::getAlias($alias);
    }
}
