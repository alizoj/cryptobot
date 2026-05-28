<?php

declare(strict_types=1);

namespace common\services\Trading;

/**
 * Sizing overrides editable from the /trading dashboard.
 *
 * Persisted to {@see Paths::uiSettings()} and merged into
 * `Yii::$app->params['trading']` by {@see \common\bootstrap\TradingSettingsBootstrap}
 * so every sizing/display call site picks them up uniformly.
 *
 * Only three knobs are exposed: equity, per-trade risk %, max-notional %.
 * Strategy parameters stay in `common/config/params.php`.
 */
final class UiSettings
{
    /** Named presets surfaced as one-click templates in the UI. */
    public const TEMPLATES = [
        'conservative' => [
            'label'          => 'Conservative',
            'riskPct'        => 0.0025,
            'maxNotionalPct' => 0.10,
        ],
        'standard' => [
            'label'          => 'Standard',
            'riskPct'        => 0.005,
            'maxNotionalPct' => 0.20,
        ],
        'aggressive' => [
            'label'          => 'Aggressive',
            'riskPct'        => 0.0075,
            'maxNotionalPct' => 0.30,
        ],
    ];

    /** Hard bounds for sanity (UI validates against these). */
    public const BOUNDS = [
        'equityUsd'      => ['min' => 1.0,    'max' => 1.0e9],
        'riskPct'        => ['min' => 0.0001, 'max' => 0.05],
        'maxNotionalPct' => ['min' => 0.01,   'max' => 1.0],
    ];

    /** @return array<string,mixed> {equityUsd?,riskPct?,maxNotionalPct?,template?,updated_at?} */
    public static function load(): array
    {
        $path = Paths::uiSettings();
        if (!is_file($path)) {
            return [];
        }
        $raw = json_decode((string)file_get_contents($path), true);
        return is_array($raw) ? $raw : [];
    }

    /**
     * Persist a settings patch. Pass only the keys you want to change.
     * Returns the merged settings as written.
     *
     * @param array<string,mixed> $patch
     * @return array<string,mixed>
     */
    public static function save(array $patch): array
    {
        $merged = array_merge(self::load(), self::sanitize($patch));
        $merged['updated_at'] = gmdate('c');
        file_put_contents(
            Paths::uiSettings(),
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );
        return $merged;
    }

    /**
     * Resolve {equityUsd, riskPct, maxNotionalPct} from the submitted form.
     *
     *   - equityUsd is always taken from the input (presets don't dictate equity).
     *   - When a named template is chosen, riskPct and maxNotionalPct come from
     *     the preset (the corresponding form fields are ignored).
     *   - When template = "custom", all three come from the inputs.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function resolve(array $input): array
    {
        $tpl = isset($input['template']) ? (string)$input['template'] : 'custom';
        $named = $tpl !== 'custom' && isset(self::TEMPLATES[$tpl]);

        $out = ['template' => $named ? $tpl : 'custom'];

        if (array_key_exists('equityUsd', $input) && $input['equityUsd'] !== '' && $input['equityUsd'] !== null) {
            $out['equityUsd'] = (float)$input['equityUsd'];
        }

        if ($named) {
            $preset = self::TEMPLATES[$tpl];
            $out['riskPct']        = (float)$preset['riskPct'];
            $out['maxNotionalPct'] = (float)$preset['maxNotionalPct'];
            return $out;
        }
        foreach (['riskPct', 'maxNotionalPct'] as $k) {
            if (array_key_exists($k, $input) && $input[$k] !== '' && $input[$k] !== null) {
                // Form expresses % as percent (e.g. "0.5", "20"); convert to fraction.
                $out[$k] = (float)$input[$k] / 100.0;
            }
        }
        return $out;
    }

    /** Drop unknown keys and clamp known ones to {@see BOUNDS}. */
    private static function sanitize(array $patch): array
    {
        $out = [];
        if (isset($patch['template'])) {
            $t = (string)$patch['template'];
            if ($t === 'custom' || isset(self::TEMPLATES[$t])) {
                $out['template'] = $t;
            }
        }
        foreach (self::BOUNDS as $key => $b) {
            if (!array_key_exists($key, $patch) || $patch[$key] === '' || $patch[$key] === null) {
                continue;
            }
            $v = (float)$patch[$key];
            if (!is_finite($v)) {
                continue;
            }
            $out[$key] = max($b['min'], min($b['max'], $v));
        }
        return $out;
    }
}
