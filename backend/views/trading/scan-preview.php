<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var string $asOf */
/** @var list<array<string, mixed>> $signals */
/** @var array<string, mixed> $diagnostics */

use yii\helpers\Html;

$this->title = "Scan preview {$asOf}";

$fmtPx = static function ($v): string {
    if ($v === null) return 'market';
    if ($v >= 100)   return number_format((float)$v, 2);
    if ($v >= 1)     return number_format((float)$v, 4);
    if ($v >= 0.01)  return number_format((float)$v, 5);
    return rtrim(rtrim(sprintf('%.8f', (float)$v), '0'), '.');
};
?>
<div class="container py-4">
    <p><?= Html::a('&laquo; back to dashboard', ['index'], ['class' => 'small']) ?></p>
    <h1 class="mb-3">Scan preview &mdash; <?= Html::encode($asOf) ?></h1>

    <div class="card mb-3">
        <div class="card-body small">
            <div>scanned: <strong><?= (int)($diagnostics['symbols_scanned'] ?? 0) ?>/<?= (int)($diagnostics['symbols_total'] ?? 0) ?></strong></div>
            <div>cluster_dumps_yday: <strong><?= (int)($diagnostics['cluster_dumps_yday'] ?? 0) ?></strong> <?= ($diagnostics['cluster_pause_active'] ?? false) ? '(non-L1 paused)' : '' ?></div>
            <div>funding mode: <?= Html::encode((string)($diagnostics['funding_mode'] ?? 'mean')) ?></div>
            <div>time exit: T+<?= (int)($diagnostics['time_exit_days'] ?? 20) ?></div>
            <?php if (!empty($diagnostics['skip_reasons'])): ?>
                <div class="mt-2">skip reasons: <?= Html::encode(json_encode($diagnostics['skip_reasons'])) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <h3>Signals (<?= count($signals) ?>)</h3>
    <?php if (empty($signals)): ?>
        <p class="text-muted">No triggers fired.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead><tr>
                    <th>Setup</th><th>Symbol</th><th>Side</th><th>Method</th>
                    <th>Entry</th><th>Stop</th><th>Risk%</th><th>Notional</th><th>Beta</th><th>Chart</th><th>Reasons</th>
                </tr></thead>
                <tbody>
                <?php foreach ($signals as $s): ?>
                    <tr>
                        <td><strong><?= Html::encode($s['setup']) ?></strong>
                            <?= $s['aggressive'] ? '<span class="badge text-bg-warning">AGG</span>' : '' ?></td>
                        <td><strong><?= Html::encode($s['symbol']) ?></strong></td>
                        <td><?= $s['side'] === 'long' ? '<span class="text-success">LONG</span>' : '<span class="text-danger">SHORT</span>' ?></td>
                        <td class="small text-muted"><?= Html::encode($s['method']) ?></td>
                        <td><?= $fmtPx($s['entry_px']) ?></td>
                        <td><?= $fmtPx($s['stop_px']) ?></td>
                        <td><?= number_format((float)$s['size']['risk_per_unit_pct'] * 100, 2) ?>%</td>
                        <td>$<?= number_format((float)$s['size']['notional_usd'], 2) ?></td>
                        <td><?= number_format((float)($s['beta'] ?? 1), 3) ?></td>
                        <td><?= Html::a('view', ['chart',
                            'symbol' => $s['symbol'],
                            'entry'  => $s['entry_px'] ?? $s['entry_estimate'] ?? null,
                            'stop'   => $s['stop_px'],
                            'side'   => $s['side'],
                            'setup'  => $s['setup'],
                            'asOf'   => $asOf,
                        ], ['class' => 'small', 'target' => '_blank']) ?></td>
                        <td class="small text-muted"><?php
                            $bits = [];
                            foreach ($s['reasons'] as $k => $v) {
                                $bits[] = htmlspecialchars($k) . '=' . (is_float($v) ? sprintf('%.4f', $v) : htmlspecialchars((string)$v));
                            }
                            echo implode(', ', $bits);
                        ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <p class="text-muted small">Preview only — no Telegram messages were sent; no positions opened.</p>
</div>
