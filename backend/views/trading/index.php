<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var list<array<string, mixed>> $open */
/** @var list<array<string, mixed>> $closed */
/** @var list<array<string, mixed>> $signals */
/** @var bool $telegramConfigured */
/** @var array<string, mixed> $params */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Trading Bot';

$fmtPx = static function ($v): string {
    if ($v === null) return '-';
    if ($v >= 100)   return number_format((float)$v, 2);
    if ($v >= 1)     return number_format((float)$v, 4);
    if ($v >= 0.01)  return number_format((float)$v, 5);
    return rtrim(rtrim(sprintf('%.8f', (float)$v), '0'), '.');
};

$badge = static function (bool $ok, string $text): string {
    $cls = $ok ? 'text-bg-success' : 'text-bg-danger';
    return '<span class="badge ' . $cls . '">' . htmlspecialchars($text) . '</span>';
};
?>
<div class="container py-4">
    <h1 class="mb-3">Trading bot &mdash; Experiment 8 (v1 stops + T+20)</h1>

    <?php foreach (['success', 'error'] as $type): ?>
        <?php if ($msg = Yii::$app->session->getFlash($type)): ?>
            <div class="alert alert-<?= $type === 'success' ? 'success' : 'danger' ?>"><?= Html::encode($msg) ?></div>
        <?php endif; ?>
    <?php endforeach; ?>

    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-3 align-items-center">
                <div>Telegram: <?= $badge($telegramConfigured, $telegramConfigured ? 'configured' : 'missing creds') ?></div>
                <div>Equity: $<?= number_format((float)($params['equityUsd'] ?? 0), 2) ?></div>
                <div>Risk/trade: <?= number_format((float)($params['riskPct'] ?? 0) * 100, 2) ?>%</div>
                <div>Exit: T+<?= (int)($params['timeExitDays'] ?? 20) ?></div>
                <div>L1 cluster filter: <?= ($params['applyL1ClusterFilter'] ?? false) ? 'ON' : 'OFF' ?></div>
                <div>Funding: <?= htmlspecialchars((string)($params['fundingMode'] ?? 'mean')) ?></div>
                <div class="ms-auto">
                    <?= Html::a('Dry-run scan', ['scan-preview'], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
                    <?= Html::beginForm(['test-telegram'], 'post', ['style' => 'display:inline']) ?>
                        <?= Html::submitButton('Test Telegram', ['class' => 'btn btn-sm btn-outline-primary']) ?>
                    <?= Html::endForm() ?>
                </div>
            </div>
        </div>
    </div>

    <h3>Open positions (<?= count($open) ?>)</h3>
    <?php if (empty($open)): ?>
        <p class="text-muted">None open.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead><tr>
                    <th>Opened</th><th>Symbol</th><th>Setup</th><th>Side</th>
                    <th>Entry</th><th>Stop</th><th>Units</th><th>Notional</th><th>Beta</th><th>Method</th>
                </tr></thead>
                <tbody>
                <?php foreach ($open as $p): ?>
                    <tr>
                        <td><?= Html::encode($p['entry_day'] ?? '') ?></td>
                        <td><strong><?= Html::encode($p['symbol'] ?? '') ?></strong></td>
                        <td><?= Html::encode($p['setup'] ?? '') ?></td>
                        <td><?= ($p['side'] ?? '') === 'long' ? '<span class="text-success">LONG</span>' : '<span class="text-danger">SHORT</span>' ?></td>
                        <td><?= $fmtPx($p['entry_px'] ?? null) ?></td>
                        <td><?= $fmtPx($p['stop_px'] ?? null) ?></td>
                        <td><?= number_format((float)($p['size']['size_units'] ?? 0), 4) ?></td>
                        <td>$<?= number_format((float)($p['size']['notional_usd'] ?? 0), 2) ?></td>
                        <td><?= number_format((float)($p['size']['beta_used'] ?? $p['beta'] ?? 1), 3) ?></td>
                        <td class="text-muted small"><?= Html::encode($p['method'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h3 class="mt-4">Recent signals (last <?= count($signals) ?>)</h3>
    <?php if (empty($signals)): ?>
        <p class="text-muted">No signal history yet. Run <code>./yii trading/scan</code>.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead><tr>
                    <th>When (UTC)</th><th>Kind</th><th>Symbol</th><th>Setup</th><th>Side</th><th>Sent</th><th>Detail</th>
                </tr></thead>
                <tbody>
                <?php foreach ($signals as $s): ?>
                    <?php
                        $sym  = $s['signal']['symbol'] ?? $s['event']['symbol'] ?? '-';
                        $setup = $s['signal']['setup'] ?? $s['event']['setup'] ?? '-';
                        $side  = $s['signal']['side']  ?? $s['event']['side']  ?? '-';
                        $detail = $s['event']['kind'] ?? ($s['signal']['method'] ?? '');
                    ?>
                    <tr>
                        <td><?= gmdate('Y-m-d H:i', (int)($s['ts'] ?? 0)) ?></td>
                        <td><?= Html::encode($s['kind'] ?? '') ?></td>
                        <td><strong><?= Html::encode($sym) ?></strong></td>
                        <td><?= Html::encode($setup) ?></td>
                        <td><?= Html::encode(strtoupper((string)$side)) ?></td>
                        <td><?= ($s['sent_ok'] ?? false) ? $badge(true, 'yes') : $badge(false, 'no') ?></td>
                        <td class="small text-muted"><?= Html::encode((string)$detail) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (!empty($closed)): ?>
        <h3 class="mt-4">Recently closed (last <?= count($closed) ?>)</h3>
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead><tr><th>Opened</th><th>Closed</th><th>Symbol</th><th>Setup</th><th>Side</th><th>Entry</th><th>Exit</th><th>Reason</th></tr></thead>
                <tbody>
                <?php foreach (array_reverse($closed) as $p): ?>
                    <tr>
                        <td><?= Html::encode($p['entry_day'] ?? '') ?></td>
                        <td><?= Html::encode($p['outcome']['exit_day'] ?? '') ?></td>
                        <td><strong><?= Html::encode($p['symbol'] ?? '') ?></strong></td>
                        <td><?= Html::encode($p['setup'] ?? '') ?></td>
                        <td><?= Html::encode(strtoupper((string)($p['side'] ?? ''))) ?></td>
                        <td><?= $fmtPx($p['entry_px'] ?? null) ?></td>
                        <td><?= $fmtPx($p['outcome']['exit_px'] ?? null) ?></td>
                        <td class="small text-muted"><?= Html::encode((string)($p['outcome']['exit_reason'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
