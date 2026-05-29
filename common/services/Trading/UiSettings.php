<?php

declare(strict_types=1);

namespace common\services\Trading;

/**
 * Account-size override editable from the /trading dashboard.
 *
 * Persisted to {@see Paths::uiSettings()} and merged into
 * `Yii::$app->params['trading']` by {@see \common\bootstrap\TradingSettingsBootstrap}
 * so every sizing/display call site picks it up uniformly.
 *
 * Only **Equity (USD)** is user-editable. Every other knob (per-trade risk,
 * max-notional, max open positions, filters, …) is intentionally locked to the
 * tuned Experiment-8 defaults in `common/config/params.php` — the best setup
 * for finding signals. {@see load()} filters persisted state down to the
 * allowed keys, so even a stale settings file can't override those defaults.
 */
final class UiSettings
{
    /** The only keys we read back / apply. Everything else stays at params.php defaults. */
    private const ALLOWED_KEYS = ['equityUsd', 'updated_at'];

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
        'equityUsd'            => ['min' => 1.0,    'max' => 1.0e9],
        'riskPct'              => ['min' => 0.0001, 'max' => 0.05],
        'maxNotionalPct'       => ['min' => 0.01,   'max' => 1.0],
        'maxConcurrentSingles' => ['min' => 1,      'max' => 50],
    ];

    /** Keys that should be persisted/applied as integers (not floats). */
    private const INT_KEYS = ['maxConcurrentSingles'];

    /** @return array<string,mixed> {equityUsd?,updated_at?} — filtered to {@see ALLOWED_KEYS}. */
    public static function load(): array
    {
        $path = Paths::uiSettings();
        if (!is_file($path)) {
            return [];
        }
        $raw = json_decode((string)file_get_contents($path), true);
        if (!is_array($raw)) {
            return [];
        }
        // Drop any legacy keys (riskPct, maxNotionalPct, maxConcurrentSingles,
        // template) so locked knobs always fall back to params.php defaults.
        return array_intersect_key($raw, array_flip(self::ALLOWED_KEYS));
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
     * Resolve settings from the submitted form. Only Equity (USD) is accepted;
     * every other knob is locked to the params.php defaults, so any extra POST
     * fields are ignored.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function resolve(array $input): array
    {
        $out = [];
        if (array_key_exists('equityUsd', $input) && $input['equityUsd'] !== '' && $input['equityUsd'] !== null) {
            $out['equityUsd'] = (float)$input['equityUsd'];
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
            $clamped = max($b['min'], min($b['max'], $v));
            $out[$key] = in_array($key, self::INT_KEYS, true) ? (int)$clamped : $clamped;
        }
        return $out;
    }
}
