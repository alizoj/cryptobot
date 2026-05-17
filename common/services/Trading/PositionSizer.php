<?php

declare(strict_types=1);

namespace common\services\Trading;

use Yii;

/**
 * Applies TRADE.md §1 position sizing to a fired setup.
 *
 *   risk_per_unit = |entry - stop|
 *   units         = (equity * risk_pct / risk_per_unit) * (1/beta)
 *   units         = min(units, equity * max_notional_pct / entry)
 */
final class PositionSizer
{
    private float $equityUsd;
    private float $riskPct;
    private float $aggressiveRiskPct;
    private float $maxNotionalPct;

    public function __construct(?array $config = null)
    {
        $cfg = $config ?? (Yii::$app->params['trading'] ?? []);
        $this->equityUsd         = (float)($cfg['equityUsd']         ?? 10000);
        $this->riskPct           = (float)($cfg['riskPct']           ?? 0.005);
        $this->aggressiveRiskPct = (float)($cfg['aggressiveRiskPct'] ?? 0.0075);
        $this->maxNotionalPct    = (float)($cfg['maxNotionalPct']    ?? 0.20);
    }

    /**
     * @return array{
     *   risk_per_unit: float,
     *   risk_per_unit_pct: float,
     *   risk_usd: float,
     *   size_units: float,
     *   notional_usd: float,
     *   beta_used: float,
     *   risk_pct_used: float,
     *   notional_capped: bool
     * }
     */
    public function size(
        float $entryPx,
        float $stopPx,
        float $beta,
        bool  $aggressive = false,
    ): array {
        $beta = max(0.1, $beta); // never divide by zero or negative
        $riskPct = $aggressive ? $this->aggressiveRiskPct : $this->riskPct;
        $riskPerUnit = abs($entryPx - $stopPx);
        $riskUsd = $this->equityUsd * $riskPct;

        if ($riskPerUnit <= 0 || $entryPx <= 0) {
            return [
                'risk_per_unit'     => 0.0,
                'risk_per_unit_pct' => 0.0,
                'risk_usd'          => $riskUsd,
                'size_units'        => 0.0,
                'notional_usd'      => 0.0,
                'beta_used'         => $beta,
                'risk_pct_used'     => $riskPct,
                'notional_capped'   => false,
            ];
        }

        $units = $riskUsd / $riskPerUnit / $beta;
        $maxUnitsByNotional = $this->equityUsd * $this->maxNotionalPct / $entryPx;
        $capped = false;
        if ($units > $maxUnitsByNotional) {
            $units = $maxUnitsByNotional;
            $capped = true;
        }
        $notional = $units * $entryPx;

        return [
            'risk_per_unit'     => round($riskPerUnit, 8),
            'risk_per_unit_pct' => round($riskPerUnit / $entryPx, 6),
            'risk_usd'          => round($riskUsd, 2),
            'size_units'        => round($units, 6),
            'notional_usd'      => round($notional, 2),
            'beta_used'         => round($beta, 4),
            'risk_pct_used'     => $riskPct,
            'notional_capped'   => $capped,
        ];
    }

    public function equityUsd(): float
    {
        return $this->equityUsd;
    }
}
