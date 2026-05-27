<?php

declare(strict_types=1);

namespace common\services\Trading;

use Yii;

/**
 * Builds QuickChart (https://quickchart.io) candlestick charts for a signal.
 *
 * Used by the Telegram path: we POST a Chart.js financial config to
 * QuickChart's /chart/create endpoint, get back a short render URL, and
 * hand that URL to TelegramNotifier::sendPhoto(). Telegram then fetches the
 * PNG itself — so we never have to upload binary data or build a giant GET URL.
 *
 * Config comes from Yii::$app->params['chart']:
 *   enabled, quickChartBase, width, height, lookbackDays
 *
 * Stateless apart from the cached config array — trivially testable.
 */
final class ChartRenderer
{
    /** @var array<string, mixed> */
    private array $cfg;

    public function __construct(?array $config = null)
    {
        $this->cfg = $config ?? (Yii::$app->params['chart'] ?? []);
    }

    public function isEnabled(): bool
    {
        return (bool)($this->cfg['enabled'] ?? false);
    }

    private function base(): string
    {
        return rtrim((string)($this->cfg['quickChartBase'] ?? 'https://quickchart.io'), '/');
    }

    /**
     * High-level helper: build a chart for one SignalEngine signal and return
     * a short, Telegram-friendly render URL (or null if it couldn't be made).
     *
     * @param array<string, mixed> $signal one element from SignalEngine::scan()
     * @param list<array{ts:int,o:float,h:float,l:float,c:float,v:float}> $candles full daily series for the symbol
     */
    public function urlForSignal(array $signal, array $candles): ?string
    {
        if ($candles === []) {
            return null;
        }
        $config = $this->buildConfig(
            symbol:  (string)$signal['symbol'],
            setup:   (string)$signal['setup'],
            side:    (string)$signal['side'],
            asOf:    (string)$signal['as_of'],
            entryPx: $signal['entry_px'] ?? ($signal['entry_estimate'] ?? null),
            stopPx:  isset($signal['stop_px']) ? (float)$signal['stop_px'] : null,
            candles: $candles,
        );
        return $this->createShortUrl($config);
    }

    /**
     * Build a Chart.js v3 "candlestick" config with entry/stop annotation lines.
     *
     * @param list<array{ts:int,o:float,h:float,l:float,c:float,v:float}> $candles
     * @return array<string, mixed>
     */
    public function buildConfig(
        string $symbol,
        string $setup,
        string $side,
        string $asOf,
        ?float $entryPx,
        ?float $stopPx,
        array $candles,
    ): array {
        $lookback = (int)($this->cfg['lookbackDays'] ?? 60);
        if ($lookback > 0 && count($candles) > $lookback) {
            $candles = array_slice($candles, -$lookback);
        }

        $data = [];
        foreach ($candles as $c) {
            $data[] = [
                'x' => (int)$c['ts'],           // epoch ms — Chart.js time scale
                'o' => (float)$c['o'],
                'h' => (float)$c['h'],
                'l' => (float)$c['l'],
                'c' => (float)$c['c'],
            ];
        }

        $isLong  = $side === 'long';
        $upColor = $isLong ? '#16a34a' : '#16a34a'; // candles always green-up/red-down
        $downColor = '#dc2626';

        $annotations = [];
        if ($entryPx !== null) {
            $annotations['entry'] = $this->lineAnnotation(
                (float)$entryPx,
                $isLong ? '#16a34a' : '#dc2626',
                'entry ' . self::fmtPx((float)$entryPx),
            );
        }
        if ($stopPx !== null) {
            $annotations['stop'] = $this->lineAnnotation(
                (float)$stopPx,
                '#f59e0b',
                'stop ' . self::fmtPx($stopPx),
            );
        }

        return [
            'type' => 'candlestick',
            'data' => [
                'datasets' => [[
                    'label' => $symbol,
                    'data'  => $data,
                    'color' => [
                        'up'         => $upColor,
                        'down'       => $downColor,
                        'unchanged'  => '#9ca3af',
                    ],
                ]],
            ],
            'options' => [
                'plugins' => [
                    'title' => [
                        'display' => true,
                        'text'    => sprintf('%s · %s · %s · %s', $symbol, $setup, strtoupper($side), $asOf),
                    ],
                    'legend' => ['display' => false],
                    'annotation' => ['annotations' => $annotations],
                ],
                'scales' => [
                    'x' => [
                        'type' => 'time',
                        'time' => ['unit' => 'day', 'tooltipFormat' => 'yyyy-MM-dd'],
                    ],
                ],
            ],
        ];
    }

    /** A horizontal price line annotation (chartjs-plugin-annotation v1, Chart.js 3). */
    private function lineAnnotation(float $y, string $color, string $label): array
    {
        return [
            'type'        => 'line',
            'yMin'        => $y,
            'yMax'        => $y,
            'borderColor' => $color,
            'borderWidth' => 1,
            'borderDash'  => [4, 4],
            'label'       => [
                'display'         => true,
                'content'         => $label,
                'position'        => 'start',
                'backgroundColor' => $color,
                'color'           => '#ffffff',
                'font'            => ['size' => 10],
            ],
        ];
    }

    /**
     * POST the config to QuickChart's short-URL service and return the render URL.
     * Returns null on any failure (network, non-200, malformed response).
     *
     * @param array<string, mixed> $config
     */
    public function createShortUrl(array $config): ?string
    {
        $payload = json_encode([
            'chart'           => $config,
            'width'           => (int)($this->cfg['width'] ?? 800),
            'height'          => (int)($this->cfg['height'] ?? 450),
            'format'          => 'png',
            'backgroundColor' => 'white',
            'version'         => '3',
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return null;
        }

        $ch = curl_init($this->base() . '/chart/create');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        if ($body === false) {
            return null;
        }
        $resp = json_decode((string)$body, true);
        if (!is_array($resp) || !($resp['success'] ?? false) || !is_string($resp['url'] ?? null)) {
            return null;
        }
        return $resp['url'];
    }

    /** Compact price formatting matching TelegramNotifier::px(). */
    private static function fmtPx(float $v): string
    {
        if ($v >= 100)  return number_format($v, 2);
        if ($v >= 1)    return number_format($v, 4);
        if ($v >= 0.01) return number_format($v, 5);
        return rtrim(rtrim(number_format($v, 8), '0'), '.');
    }
}
