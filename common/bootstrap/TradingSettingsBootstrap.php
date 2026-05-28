<?php

declare(strict_types=1);

namespace common\bootstrap;

use common\services\Trading\UiSettings;
use yii\base\Application;
use yii\base\BootstrapInterface;

/**
 * Merges persisted UI sizing overrides from
 * {@see \common\services\Trading\Paths::uiSettings()} into
 * `Yii::$app->params['trading']`.
 *
 * Runs before any service constructor, so PositionSizer / PositionManager /
 * the dashboard view all see the override uniformly.
 */
final class TradingSettingsBootstrap implements BootstrapInterface
{
    public function bootstrap($app): void
    {
        $saved = UiSettings::load();
        if ($saved === []) {
            return;
        }
        $trading = $app->params['trading'] ?? [];
        foreach (['equityUsd', 'riskPct', 'maxNotionalPct'] as $k) {
            if (array_key_exists($k, $saved)) {
                $trading[$k] = (float)$saved[$k];
            }
        }
        if (array_key_exists('maxConcurrentSingles', $saved)) {
            $trading['maxConcurrentSingles'] = (int)$saved['maxConcurrentSingles'];
        }
        if (isset($saved['template'])) {
            $trading['template'] = (string)$saved['template'];
        }
        $app->params['trading'] = $trading;
    }
}
