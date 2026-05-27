<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var string $asOf */
/** @var list<array<string, mixed>> $signals */
/** @var array<string, mixed> $diagnostics */
/** @var bool $intraday */

use yii\helpers\Html;

$intraday = $intraday ?? false;
$this->title = ($intraday ? '4h scan' : 'Scan preview') . " {$asOf}";
$this->render('_kit');

$fmtPx = static function ($v): string {
    if ($v === null) return 'market';
    $v = (float)$v;
    if ($v >= 100)  return number_format($v, 2);
    if ($v >= 1)    return number_format($v, 4);
    if ($v >= 0.01) return number_format($v, 5);
    return rtrim(rtrim(sprintf('%.8f', $v), '0'), '.');
};
$setupBadge = static function (?string $setup): string {
    $setup = (string)$setup;
    if ($setup === '') return '';
    $cls = str_starts_with($setup, 'L') ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis';
    return '<span class="badge setup-badge ' . $cls . '">' . htmlspecialchars($setup) . '</span>';
};

// counts by side
$longs = $shorts = 0;
foreach ($signals as $s) {
    ($s['side'] ?? '') === 'short' ? $shorts++ : $longs++;
}

$navBtn = static function (string $label, array $route, bool $active): string {
    return Html::a($label, $route, ['class' => 'btn btn-sm ' . ($active ? 'btn-primary' : 'btn-outline-secondary')]);
};
?>
<div class="container py-4">

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3 tr-toolbar">
        <div class="me-auto">
            <h1 class="h3 mb-0"><?= $intraday ? '4h scan' : 'Daily scan' ?> <span class="text-secondary fw-normal">preview</span></h1>
            <div class="text-secondary small">as of <?= Html::encode($asOf) ?> UTC · dry-run, nothing sent or opened</div>
        </div>
        <div class="btn-group" role="group">
            <?= $navBtn('Dashboard', ['index'], false) ?>
            <?= $navBtn('Daily scan', ['scan-preview'], !$intraday) ?>
            <?= $navBtn('4h scan', ['scan4h-preview'], $intraday) ?>
        </div>
    </div>

    <!-- summary -->
    <div class="row row-cols-2 row-cols-md-4 g-3 mb-3">
        <div class="col"><div class="card kpi-card h-100"><div class="card-body">
            <div class="kpi-label">Signals</div><div class="kpi-val"><?= count($signals) ?></div>
            <div class="kpi-sub"><span class="text-success"><?= $longs ?> long</span> · <span class="text-danger"><?= $shorts ?> short</span></div>
        </div></div></div>
        <div class="col"><div class="card kpi-card h-100"><div class="card-body">
            <div class="kpi-label">Scanned</div>
            <div class="kpi-val"><?= (int)($diagnostics['symbols_scanned'] ?? 0) ?><span class="fs-6 text-secondary">/<?= (int)($diagnostics['symbols_total'] ?? 0) ?></span></div>
            <div class="kpi-sub">universe</div>
        </div></div></div>
        <?php if ($intraday): ?>
            <div class="col"><div class="card kpi-card h-100"><div class="card-body">
                <div class="kpi-label">S6 relaxed pool</div>
                <div class="kpi-val"><?= (int)($diagnostics['symbols_s6_relaxed'] ?? 0) ?></div>
                <div class="kpi-sub num">≥ $<?= number_format((float)($diagnostics['s6_min_turnover'] ?? 0) / 1e6, 1) ?>M</div>
            </div></div></div>
        <?php else: ?>
            <div class="col"><div class="card kpi-card h-100"><div class="card-body">
                <div class="kpi-label">Cluster dumps (yday)</div>
                <div class="kpi-val"><?= (int)($diagnostics['cluster_dumps_yday'] ?? 0) ?></div>
                <div class="kpi-sub"><?= ($diagnostics['cluster_pause_active'] ?? false) ? 'non-L1 paused' : 'no pause' ?></div>
            </div></div></div>
        <?php endif; ?>
        <div class="col"><div class="card kpi-card h-100"><div class="card-body">
            <div class="kpi-label">Exit</div><div class="kpi-val">T+<?= (int)($diagnostics['time_exit_days'] ?? 20) ?></div>
            <div class="kpi-sub"><?= $intraday ? 'MTF setups (L5/S6)' : Html::encode((string)($diagnostics['funding_mode'] ?? 'mean')) . ' funding' ?></div>
        </div></div></div>
    </div>

    <?php if (!empty($diagnostics['skip_reasons'])): ?>
        <div class="text-secondary small mb-3">skip reasons: <?= Html::encode(json_encode($diagnostics['skip_reasons'])) ?></div>
    <?php endif; ?>

    <?php if (empty($signals)): ?>
        <div class="card card-section"><div class="card-body text-secondary">No triggers fired for this <?= $intraday ? '4h bar' : 'day' ?>.</div></div>
    <?php else: ?>
        <div class="card card-section"><div class="table-responsive">
            <table class="table tr-table align-middle">
                <thead><tr>
                    <th>Setup</th><th>Symbol</th><th>Side</th><th>Method</th>
                    <th class="text-end">Entry</th><th class="text-end">Stop</th><th class="text-end">Risk%</th>
                    <th class="text-end">Notional</th><th class="text-end">Beta</th><th></th><th>Reasons</th>
                </tr></thead>
                <tbody>
                <?php foreach ($signals as $s): $side = $s['side'] ?? ''; ?>
                    <tr class="row-<?= $side === 'short' ? 'short' : 'long' ?>">
                        <td><?= $setupBadge($s['setup'] ?? null) ?>
                            <?= !empty($s['aggressive']) ? '<span class="badge text-bg-warning">AGG</span>' : '' ?></td>
                        <td><strong><?= Html::encode($s['symbol'] ?? '') ?></strong></td>
                        <td><?= $side === 'long' ? '<span class="text-success fw-semibold">LONG</span>' : '<span class="text-danger fw-semibold">SHORT</span>' ?></td>
                        <td class="small text-secondary"><?= Html::encode($s['method'] ?? '') ?></td>
                        <td class="text-end num"><?= $fmtPx($s['entry_px'] ?? null) ?></td>
                        <td class="text-end num"><?= $fmtPx($s['stop_px'] ?? null) ?></td>
                        <td class="text-end num"><?= number_format((float)($s['size']['risk_per_unit_pct'] ?? 0) * 100, 2) ?>%</td>
                        <td class="text-end num">$<?= number_format((float)($s['size']['notional_usd'] ?? 0), 0) ?></td>
                        <td class="text-end num"><?= number_format((float)($s['beta'] ?? 1), 3) ?></td>
                        <td class="text-end"><?= Html::a('📈', ['chart',
                                'symbol' => $s['symbol'] ?? '',
                                'entry'  => $s['entry_px'] ?? $s['entry_estimate'] ?? null,
                                'stop'   => $s['stop_px'] ?? null,
                                'side'   => $side, 'setup' => $s['setup'] ?? null,
                                'asOf'   => $s['as_of_day'] ?? $asOf,
                            ], ['class' => 'text-decoration-none', 'target' => '_blank', 'title' => 'Open chart']) ?></td>
                        <td class="small text-secondary"><?php
                            $bits = [];
                            foreach (($s['reasons'] ?? []) as $k => $v) {
                                $bits[] = htmlspecialchars($k) . '=' . (is_float($v) ? sprintf('%.4f', $v) : htmlspecialchars((string)$v));
                            }
                            echo implode(', ', $bits);
                        ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
    <?php endif; ?>
</div>
