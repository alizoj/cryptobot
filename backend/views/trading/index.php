<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var list<array<string, mixed>> $open */
/** @var list<array<string, mixed>> $closed */
/** @var list<array<string, mixed>> $signals */
/** @var bool $telegramConfigured */
/** @var array<string, mixed> $params */
/** @var float $unrealisedUsd */
/** @var array<string, mixed> $uiSettings */
/** @var array<string, array<string, mixed>> $uiTemplates */

use yii\helpers\Html;

$this->title = 'Trading Bot';
$this->render('_kit');

// ---- formatting helpers ----
$fmtPx = static function ($v): string {
    if ($v === null) return '—';
    $v = (float)$v;
    if ($v >= 100)  return number_format($v, 2);
    if ($v >= 1)    return number_format($v, 4);
    if ($v >= 0.01) return number_format($v, 5);
    return rtrim(rtrim(sprintf('%.8f', $v), '0'), '.');
};
$setupBadge = static function (?string $setup): string {
    $setup = (string)$setup;
    if ($setup === '' || $setup === '-') return '';
    $cls = str_starts_with($setup, 'L') ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis';
    return '<span class="badge setup-badge ' . $cls . '">' . htmlspecialchars($setup) . '</span>';
};
$sidePill = static function (?string $side): string {
    return ($side ?? '') === 'long'
        ? '<span class="text-success fw-semibold">LONG</span>'
        : (($side ?? '') === 'short' ? '<span class="text-danger fw-semibold">SHORT</span>' : '<span class="text-muted">—</span>');
};
$pnlCell = static function (?float $f): string {
    if ($f === null) return '<span class="text-muted">—</span>';
    $cls = $f >= 0 ? 'pnl-pos' : 'pnl-neg';
    return '<span class="' . $cls . ' num">' . ($f >= 0 ? '+' : '') . number_format($f * 100, 2) . '%</span>';
};
$pnlUsdCell = static function (?float $usd): string {
    if ($usd === null) return '<span class="text-muted">—</span>';
    $cls = $usd >= 0 ? 'pnl-pos' : 'pnl-neg';
    return '<span class="' . $cls . ' num">' . ($usd >= 0 ? '+$' : '-$') . number_format(abs($usd), 2) . '</span>';
};
$pnlOf = static function (array $p): ?float {
    $e = (float)($p['entry_px'] ?? 0);
    $x = (float)($p['outcome']['exit_px'] ?? 0);
    if ($e <= 0 || $x <= 0) return null;
    return ($p['side'] ?? '') === 'long' ? ($x / $e - 1) : ($e / $x - 1);
};
$chartLink = static function (?string $symbol, $entry, $stop, ?string $side, ?string $setup, ?string $asOf): string {
    if (!$symbol || $symbol === '-') return '';
    return Html::a('📈', ['chart', 'symbol' => $symbol, 'entry' => $entry, 'stop' => $stop,
        'side' => $side, 'setup' => $setup, 'asOf' => $asOf],
        ['class' => 'text-decoration-none', 'target' => '_blank', 'title' => 'Open chart']);
};

// ---- KPIs ----
$equity = (float)($params['equityUsd'] ?? 0);
$openNotional = 0.0;
$openRisk = 0.0;
foreach ($open as $p) {
    $openNotional += (float)($p['size']['notional_usd'] ?? 0);
    $openRisk     += (float)($p['size']['risk_usd'] ?? 0);
}
$closedWins = 0;
$closedScored = 0;
$pnlSum = 0.0;
foreach ($closed as $p) {
    $pnl = $pnlOf($p);
    if ($pnl === null) continue;
    $closedScored++;
    $pnlSum += $pnl;
    if ($pnl > 0) $closedWins++;
}
$winRate = $closedScored > 0 ? $closedWins / $closedScored * 100 : null;
$avgPnl  = $closedScored > 0 ? $pnlSum / $closedScored : null;
$sentCount = 0;
foreach ($signals as $s) {
    if ($s['sent_ok'] ?? false) $sentCount++;
}
$sentRate = count($signals) > 0 ? $sentCount / count($signals) * 100 : null;

$navBtn = static function (string $label, array $route, bool $active): string {
    $cls = 'btn btn-sm ' . ($active ? 'btn-primary' : 'btn-outline-secondary');
    return Html::a($label, $route, ['class' => $cls]);
};
?>
<div class="container py-4">

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3 tr-toolbar">
        <div class="me-auto">
            <h1 class="h3 mb-0">Trading bot</h1>
            <div class="text-secondary small">Experiment 8 · v1 stops · T+<?= (int)($params['timeExitDays'] ?? 20) ?></div>
        </div>
        <div class="btn-group" role="group" aria-label="views">
            <?= $navBtn('Dashboard', ['index'], true) ?>
            <?= $navBtn('Daily scan', ['scan-preview'], false) ?>
            <?= $navBtn('4h scan', ['scan4h-preview'], false) ?>
        </div>
        <?= Html::beginForm(['scan-now'], 'post', ['class' => 'd-inline']) ?>
            <?= Html::submitButton('⚡ Scan now', [
                'class' => 'btn btn-sm btn-success',
                'title' => 'Очередь: обновить цены и просканировать сигналы (daily + 4h)',
            ]) ?>
        <?= Html::endForm() ?>
        <?= Html::beginForm(['test-telegram'], 'post', ['class' => 'd-inline']) ?>
            <?= Html::submitButton('Test Telegram', ['class' => 'btn btn-sm btn-outline-primary']) ?>
        <?= Html::endForm() ?>
        <?= Html::a('↻', ['index'], ['class' => 'btn btn-sm btn-outline-secondary', 'title' => 'Refresh']) ?>
    </div>

    <?php foreach (['success', 'error'] as $type): ?>
        <?php if ($msg = Yii::$app->session->getFlash($type)): ?>
            <div class="alert alert-<?= $type === 'success' ? 'success' : 'danger' ?> py-2"><?= Html::encode($msg) ?></div>
        <?php endif; ?>
    <?php endforeach; ?>

    <!-- Account size (only editable knob) -->
    <?php
        $curEquity   = (float)($params['equityUsd']            ?? 10000);
        $curRiskPct  = (float)($params['riskPct']              ?? 0.005);
        $curMaxNotPc = (float)($params['maxNotionalPct']       ?? 0.20);
        $curMaxConc  = (int)  ($params['maxConcurrentSingles'] ?? 5);
        $savedAt     = (string)($uiSettings['updated_at']      ?? '');
    ?>
    <div class="card card-section mb-3"><div class="card-body py-3">
        <?= Html::beginForm(['save-settings'], 'post', ['class' => 'row g-2 align-items-end']) ?>
            <div class="col-12 col-md-auto">
                <div class="text-secondary small">Account</div>
                <div class="fw-semibold">Equity</div>
                <?php if ($savedAt !== ''): ?>
                    <div class="text-secondary small">saved <?= Html::encode($savedAt) ?> UTC</div>
                <?php endif; ?>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1">Equity (USD)</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text">$</span>
                    <?= Html::input('number', 'equityUsd', number_format($curEquity, 2, '.', ''),
                        ['class' => 'form-control num', 'step' => '1', 'min' => '1', 'autofocus' => true]) ?>
                </div>
            </div>
            <div class="col-12 col-md-auto">
                <?= Html::submitButton('Apply', ['class' => 'btn btn-sm btn-primary']) ?>
            </div>
            <div class="col-12">
                <div class="text-secondary small d-flex flex-wrap gap-3 align-items-center pt-1">
                    <span class="fw-semibold text-body">Locked to best setup:</span>
                    <span>Risk/trade <span class="text-body num"><?= rtrim(rtrim(sprintf('%.3f', $curRiskPct * 100), '0'), '.') ?>%</span></span>
                    <span>Max notional/trade <span class="text-body num"><?= rtrim(rtrim(sprintf('%.2f', $curMaxNotPc * 100), '0'), '.') ?>%</span></span>
                    <span>Max positions <span class="text-body num"><?= $curMaxConc ?></span></span>
                    <span>Exit <span class="text-body">T+<?= (int)($params['timeExitDays'] ?? 20) ?></span></span>
                    <span>Funding <span class="text-body"><?= Html::encode((string)($params['fundingMode'] ?? 'mean')) ?></span></span>
                </div>
                <div class="text-secondary small">
                    Only Equity is adjustable — every other knob stays at the tuned Experiment-8 defaults (best setup for finding signals). Applies globally: the next scan sizes signals at this equity; existing open positions keep their original sizing.
                </div>
            </div>
        <?= Html::endForm() ?>
    </div></div>

    <!-- KPI cards -->
    <div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-3 mb-2">
        <div class="col">
            <div class="card kpi-card h-100"><div class="card-body">
                <div class="kpi-label">Open positions</div>
                <div class="kpi-val"><?= count($open) ?></div>
                <div class="kpi-sub num">
                    $<?= number_format($openNotional, 0) ?> notional
                    <?php if (!empty($open)): ?>
                        · <?= $pnlUsdCell($unrealisedUsd) ?>
                    <?php endif; ?>
                </div>
            </div></div>
        </div>
        <div class="col">
            <div class="card kpi-card h-100"><div class="card-body">
                <div class="kpi-label">Open risk</div>
                <div class="kpi-val num">$<?= number_format($openRisk, 0) ?></div>
                <div class="kpi-sub"><?= $equity > 0 ? number_format($openRisk / $equity * 100, 2) . '% of equity' : '—' ?></div>
            </div></div>
        </div>
        <div class="col">
            <div class="card kpi-card h-100"><div class="card-body">
                <div class="kpi-label">Win rate</div>
                <div class="kpi-val"><?= $winRate === null ? '—' : number_format($winRate, 0) . '%' ?></div>
                <div class="kpi-sub"><?= $closedScored ?> closed</div>
            </div></div>
        </div>
        <div class="col">
            <div class="card kpi-card h-100"><div class="card-body">
                <div class="kpi-label">Avg P&amp;L / trade</div>
                <div class="kpi-val"><?= $avgPnl === null ? '<span class="text-muted">—</span>' : $pnlCell($avgPnl) ?></div>
                <div class="kpi-sub">realised, closed</div>
            </div></div>
        </div>
        <div class="col">
            <div class="card kpi-card h-100"><div class="card-body">
                <div class="kpi-label">Telegram</div>
                <div class="kpi-val">
                    <?php if ($telegramConfigured): ?>
                        <span class="badge text-bg-success">on</span>
                    <?php else: ?>
                        <span class="badge text-bg-danger">off</span>
                    <?php endif; ?>
                </div>
                <div class="kpi-sub"><?= $sentRate === null ? 'no signals' : number_format($sentRate, 0) . '% sent (' . count($signals) . ')' ?></div>
            </div></div>
        </div>
    </div>

    <!-- config strip -->
    <div class="card card-section mb-4"><div class="card-body py-2">
        <div class="d-flex flex-wrap gap-3 small text-secondary align-items-center">
            <span>Equity <span class="text-body num">$<?= number_format($equity, 0) ?></span></span>
            <span>Risk/trade <span class="text-body num"><?= number_format($curRiskPct * 100, 2) ?>%</span></span>
            <span>Exit <span class="text-body">T+<?= (int)($params['timeExitDays'] ?? 20) ?></span></span>
            <span>Funding <span class="text-body"><?= Html::encode((string)($params['fundingMode'] ?? 'mean')) ?></span></span>
            <span>S6 floor <span class="text-body num">$<?= number_format((float)($params['s6MinTurnover20dUsd'] ?? 1000000) / 1e6, 1) ?>M</span></span>
        </div>
    </div></div>

    <!-- open positions -->
    <div class="section-h">Open positions <span class="count">(<?= count($open) ?>)</span></div>
    <?php if (empty($open)): ?>
        <div class="card card-section"><div class="card-body text-secondary">No open positions.</div></div>
    <?php else: ?>
        <?= Html::beginForm(['close-bulk'], 'post', ['id' => 'bulkForm', 'class' => 'd-inline']) ?><?= Html::endForm() ?>
        <div class="d-flex align-items-center gap-2 mb-2">
            <button type="submit" form="bulkForm" class="btn btn-sm btn-outline-danger" id="bulk-close-btn" disabled
                    onclick="return confirm('Close ' + (document.getElementById('bulk-count').textContent || '0') + ' selected position(s) and re-scan?')">
                Close selected (<span id="bulk-count">0</span>)
            </button>
            <?= Html::beginForm(['close-all'], 'post', ['class' => 'd-inline']) ?>
                <?= Html::submitButton('Close all (' . count($open) . ')', [
                    'class' => 'btn btn-sm btn-danger',
                    'data-confirm' => sprintf(
                        'Close ALL %d open position(s) at the latest close, then re-scan for fresh entries?',
                        count($open),
                    ),
                ]) ?>
            <?= Html::endForm() ?>
            <span class="small text-secondary">— or use the header checkbox to select some.</span>
        </div>
        <div class="card card-section"><div class="table-responsive">
            <table class="table tr-table align-middle" id="open-positions-table">
                <thead><tr>
                    <th style="width:32px"><input type="checkbox" class="form-check-input" id="bulk-master" title="Select all"></th>
                    <th>Opened</th><th>Symbol</th><th>Setup</th><th>Side</th>
                    <th class="text-end">Entry</th><th class="text-end">Stop</th>
                    <th class="text-end">Last</th>
                    <th class="text-end">Units</th><th class="text-end">Notional</th><th class="text-end">Risk</th>
                    <th class="text-end">P&amp;L %</th><th class="text-end">P&amp;L $</th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($open as $p): $side = $p['side'] ?? ''; ?>
                    <tr class="row-<?= $side === 'short' ? 'short' : 'long' ?>">
                        <td><input type="checkbox" class="form-check-input bulk-check" name="ids[]" value="<?= Html::encode((string)($p['id'] ?? '')) ?>" form="bulkForm"></td>
                        <td class="small text-secondary"><?= Html::encode($p['entry_day'] ?? '') ?></td>
                        <td><strong><?= Html::encode($p['symbol'] ?? '') ?></strong></td>
                        <td><?= $setupBadge($p['setup'] ?? null) ?></td>
                        <td><?= $sidePill($side) ?></td>
                        <td class="text-end num"><?= $fmtPx($p['entry_px'] ?? null) ?></td>
                        <td class="text-end num"><?= $fmtPx($p['stop_px'] ?? null) ?></td>
                        <td class="text-end num" title="<?= $p['last_ts'] ? gmdate('Y-m-d', (int)$p['last_ts']) . ' close' : '' ?>"><?= $fmtPx($p['last_px'] ?? null) ?></td>
                        <td class="text-end num"><?= number_format((float)($p['size']['size_units'] ?? 0), 4) ?></td>
                        <td class="text-end num">$<?= number_format((float)($p['size']['notional_usd'] ?? 0), 0) ?></td>
                        <td class="text-end num">$<?= number_format((float)($p['size']['risk_usd'] ?? 0), 0) ?></td>
                        <td class="text-end"><?= $pnlCell($p['pnl_pct'] ?? null) ?></td>
                        <td class="text-end"><?= $pnlUsdCell($p['pnl_usd'] ?? null) ?></td>
                        <td class="text-end">
                            <?= $chartLink($p['symbol'] ?? null, $p['entry_px'] ?? null, $p['stop_px'] ?? null, $side, $p['setup'] ?? null, $p['entry_day'] ?? null) ?>
                            <?= Html::beginForm(['close-position'], 'post', ['style' => 'display:inline; margin-left:6px']) ?>
                                <?= Html::hiddenInput('id', (string)($p['id'] ?? '')) ?>
                                <?= Html::submitButton('Close', [
                                    'class' => 'btn btn-sm btn-outline-danger py-0',
                                    'data-confirm' => sprintf(
                                        'Close %s %s at the latest close? Re-scan will look for new entries to fill the slot.',
                                        $p['symbol'] ?? '?',
                                        $p['setup'] ?? '?',
                                    ),
                                ]) ?>
                            <?= Html::endForm() ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
        <?php $this->registerJs(<<<'JS'
        (function () {
            var master = document.getElementById('bulk-master');
            var btn    = document.getElementById('bulk-close-btn');
            var count  = document.getElementById('bulk-count');
            var checks = document.querySelectorAll('#open-positions-table .bulk-check');
            if (!master || !btn || !count) return;
            function refresh() {
                var n = 0;
                checks.forEach(function (c) { if (c.checked) n++; });
                count.textContent = String(n);
                btn.disabled = (n === 0);
                master.checked = (n > 0 && n === checks.length);
                master.indeterminate = (n > 0 && n < checks.length);
            }
            master.addEventListener('change', function () {
                checks.forEach(function (c) { c.checked = master.checked; });
                refresh();
            });
            checks.forEach(function (c) { c.addEventListener('change', refresh); });
            refresh();
        })();
        JS); ?>
    <?php endif; ?>

    <!-- recent signals -->
    <div class="section-h">Recent signals <span class="count">(<?= count($signals) ?>)</span></div>
    <?php if (empty($signals)): ?>
        <div class="card card-section"><div class="card-body text-secondary">No signal history yet. Run <code>./yii trading/scan</code> or <code>scan-4h</code>.</div></div>
    <?php else: ?>
        <div class="card card-section"><div class="table-responsive">
            <table class="table tr-table align-middle">
                <thead><tr>
                    <th>When (UTC)</th><th>Kind</th><th>Symbol</th><th>Setup</th><th>Side</th><th>Sent</th><th>Detail</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach (array_reverse($signals) as $s): ?>
                    <?php
                        $sig   = $s['signal'] ?? $s['event'] ?? [];
                        $sym   = $sig['symbol'] ?? '-';
                        $setup = $sig['setup'] ?? '-';
                        $side  = $sig['side'] ?? '';
                        $detail = $s['event']['kind'] ?? ($sig['method'] ?? '');
                    ?>
                    <tr class="row-<?= $side === 'short' ? 'short' : 'long' ?>">
                        <td class="small text-secondary num"><?= gmdate('Y-m-d H:i', (int)($s['ts'] ?? 0)) ?></td>
                        <td><span class="badge text-bg-light text-secondary"><?= Html::encode($s['kind'] ?? '') ?></span></td>
                        <td><strong><?= Html::encode($sym) ?></strong></td>
                        <td><?= $setupBadge($setup) ?></td>
                        <td><?= $sidePill($side) ?></td>
                        <td><?= ($s['sent_ok'] ?? false) ? '<span class="badge text-bg-success">yes</span>' : '<span class="badge text-bg-secondary">no</span>' ?></td>
                        <td class="small text-secondary"><?= Html::encode((string)$detail) ?></td>
                        <td class="text-end"><?= $chartLink($sym !== '-' ? $sym : null, $sig['entry_px'] ?? null, $sig['stop_px'] ?? null, $side, $setup !== '-' ? $setup : null, $sig['as_of'] ?? null) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
    <?php endif; ?>

    <!-- closed positions -->
    <?php if (!empty($closed)): ?>
        <div class="section-h">Recently closed <span class="count">(<?= count($closed) ?>)</span></div>
        <div class="card card-section"><div class="table-responsive">
            <table class="table tr-table align-middle">
                <thead><tr>
                    <th>Opened</th><th>Closed</th><th>Symbol</th><th>Setup</th><th>Side</th>
                    <th class="text-end">Entry</th><th class="text-end">Exit</th><th class="text-end">P&amp;L</th><th>Reason</th>
                </tr></thead>
                <tbody>
                <?php foreach (array_reverse($closed) as $p): $side = $p['side'] ?? ''; ?>
                    <tr class="row-<?= $side === 'short' ? 'short' : 'long' ?>">
                        <td class="small text-secondary"><?= Html::encode($p['entry_day'] ?? '') ?></td>
                        <td class="small text-secondary"><?= Html::encode($p['outcome']['exit_day'] ?? '') ?></td>
                        <td><strong><?= Html::encode($p['symbol'] ?? '') ?></strong></td>
                        <td><?= $setupBadge($p['setup'] ?? null) ?></td>
                        <td><?= $sidePill($side) ?></td>
                        <td class="text-end num"><?= $fmtPx($p['entry_px'] ?? null) ?></td>
                        <td class="text-end num"><?= $fmtPx($p['outcome']['exit_px'] ?? null) ?></td>
                        <td class="text-end"><?= $pnlCell($pnlOf($p)) ?></td>
                        <td class="small text-secondary"><?= Html::encode((string)($p['outcome']['exit_reason'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
    <?php endif; ?>

</div>
