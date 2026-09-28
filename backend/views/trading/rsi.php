<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var list<array<string, mixed>> $rows */
/** @var int|null $updated unix time the board was built, null = never */
/** @var int|null $dataTs open time (ms) of the newest candle on the board */
/** @var int|null $pending unix time a one-off refresh was queued, null = none */
/** @var string $sort */
/** @var string $dir */
/** @var list<string> $tfs */

use yii\helpers\Html;

$this->title = 'RSI Heatmap';
$this->render('_kit');

$this->registerCss(<<<CSS
.rsi-table td, .rsi-table th { padding: .3rem .5rem; }
.rsi-cell { text-align: center; border-radius: .25rem; }
.rsi-table th a { color: inherit; text-decoration: none; }
.rsi-table th a:hover { text-decoration: underline; }
.rsi-legend span { display: inline-block; width: 1.4rem; height: .8rem; vertical-align: middle; border-radius: .15rem; }
CSS);

// Diverging heatmap: neutral RSI (~50) stays clear; tint toward green as it
// gets oversold and red as it gets overbought, with the tint strengthening at
// the extremes. Uses an alpha tint (not a solid fill) and the theme's own text
// colour, so it reads cleanly in both light and dark mode.
$rsiCell = static function ($v, bool $strong = false): string {
    if ($v === null || !is_numeric($v)) {
        return '<td class="text-center text-muted">—</td>';
    }
    $v = (float)$v;
    $dist  = min(1.0, abs($v - 50) / 50);                 // 0 at neutral, 1 at extremes
    $hue   = $v < 50 ? 145 : 2;                           // green oversold / red overbought
    $alpha = round(($strong ? 0.72 : 0.58) * pow($dist, 0.85), 3);
    $style = "background-color:hsla({$hue},64%,45%,{$alpha});";
    $weight = $strong ? ' fw-bold' : ' fw-semibold';
    return '<td class="rsi-cell num' . $weight . '" style="' . $style . '">' . number_format($v, 1) . '</td>';
};

$fmtPx = static function (float $v): string {
    if ($v >= 100)  return number_format($v, 2);
    if ($v >= 1)    return number_format($v, 4);
    if ($v >= 0.01) return number_format($v, 5);
    return rtrim(rtrim(sprintf('%.8f', $v), '0'), '.');
};
$chgCell = static function (?float $c): string {
    if ($c === null) return '<td class="text-center text-muted">—</td>';
    $cls = $c >= 0 ? 'pnl-pos' : 'pnl-neg';
    return '<td class="text-end num ' . $cls . '">' . ($c >= 0 ? '+' : '') . number_format($c, 1) . '%</td>';
};
$turnover = static function (?float $t): string {
    if ($t === null) return '—';
    if ($t >= 1_000_000_000) return '$' . number_format($t / 1_000_000_000, 2) . 'B';
    if ($t >= 1_000_000)     return '$' . number_format($t / 1_000_000, 1) . 'M';
    if ($t >= 1_000)         return '$' . number_format($t / 1_000, 0) . 'K';
    return '$' . number_format($t, 0);
};

// Sortable header link: toggles dir when re-clicking the active column.
$th = static function (string $key, string $label, string $align = 'center') use ($sort, $dir): string {
    $nextDir = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
    $arrow = $sort === $key ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    $url = Html::a(Html::encode($label) . $arrow, ['rsi', 'sort' => $key, 'dir' => $nextDir]);
    return '<th class="text-' . $align . '">' . $url . '</th>';
};

$agoStr = static function (int $sec): string {
    $sec = max(0, $sec);
    if ($sec < 60)    return "{$sec}s";
    if ($sec < 3600)  return floor($sec / 60) . 'm';
    if ($sec < 86400) return floor($sec / 3600) . 'h ' . floor(($sec % 3600) / 60) . 'm';
    return floor($sec / 86400) . 'd ' . floor(($sec % 86400) / 3600) . 'h';
};

// The background loop keeps the newest 15m candle under ~20 min old; well past
// that the worker/refresh loop isn't running and the numbers are out of date.
$staleAfter = 2 * 3600;
$dataAge    = $dataTs !== null ? time() - intdiv($dataTs, 1000) : null;
$dataStale  = $dataAge !== null && $dataAge > $staleAfter;
// A coin whose own candles lag the board's newest by this much stopped updating
// (delisted, fetch failing).
$rowLagMs = $staleAfter * 1000;
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3 mb-2">
    <h1 class="h4 mb-0">RSI Heatmap <span class="text-secondary fs-6 fw-normal"><?= count($rows) ?> coins</span></h1>
    <div class="d-flex flex-wrap align-items-center gap-3">
        <span class="rsi-legend small text-secondary">
            oversold
            <span style="background-color:hsla(145,64%,45%,0.58)"></span>
            <span style="background-color:hsla(145,64%,45%,0.22)"></span>
            <span style="background-color:transparent;border:1px solid var(--bs-border-color)"></span>
            <span style="background-color:hsla(2,64%,45%,0.22)"></span>
            <span style="background-color:hsla(2,64%,45%,0.58)"></span>
            overbought
        </span>
        <?php if ($dataAge !== null): ?>
            <span class="small <?= $dataStale ? 'text-danger fw-semibold' : 'text-secondary' ?>"
                  title="Newest candle opened <?= gmdate('Y-m-d H:i', intdiv($dataTs, 1000)) ?> UTC; board built <?= $agoStr(time() - (int)$updated) ?> ago">
                data <?= $agoStr($dataAge) ?> old
            </span>
        <?php endif ?>
        <?= Html::a('↕ RSI tiers', ['rsi', 'sort' => 'tiered', 'dir' => 'asc'], ['class' => 'btn btn-sm ' . ($sort === 'tiered' ? 'btn-secondary' : 'btn-outline-secondary'), 'title' => 'Default sort: oversold→overbought, tiered 1D→15m']) ?>
        <?php if ($pending !== null): ?>
            <span class="btn btn-sm btn-outline-secondary disabled" title="Queued <?= $agoStr(time() - $pending) ?> ago — reload once the worker finishes it">↻ Refresh queued…</span>
        <?php else: ?>
            <?= Html::a('↻ Refresh', ['rsi-refresh', 'sort' => $sort, 'dir' => $dir], ['class' => 'btn btn-sm btn-outline-secondary', 'data-method' => 'post', 'title' => 'Fetch the latest candles and rebuild the board in the background']) ?>
        <?php endif ?>
    </div>
</div>

<?php if ($dataStale): ?>
    <div class="alert alert-warning small py-2">
        Market data is <strong><?= $agoStr($dataAge) ?> old</strong> — the background refresh isn't running.
        Start the queue worker (<code>yii queue/listen --verbose=1</code>) and the refresh loop
        (<code>yii trading/schedule-refresh</code>), or run <code>yii trading/fetch-data</code> once.
    </div>
<?php endif ?>

<p class="text-secondary small mb-2">
    Wilder RSI(14) per timeframe, Bybit data. Default sort tiers each coin by its
    overbought (&ge;70) / oversold (&le;30) state hierarchically &mdash; 1D first, then
    4H, 1H, 15m &mdash; with <strong>oversold on top</strong>. Click any column to sort by it.
</p>

<div class="card card-section">
    <div class="table-responsive">
        <table class="table table-sm tr-table rsi-table align-middle mb-0">
            <thead>
                <tr>
                    <?= $th('symbol', 'Coin', 'start') ?>
                    <?= $th('price', 'Price', 'end') ?>
                    <?= $th('chg24h', '24h', 'end') ?>
                    <?= $th('1d', '1d') ?>
                    <?= $th('4h', '4h') ?>
                    <?= $th('1h', '1h') ?>
                    <?= $th('15m', '15m') ?>
                    <?= $th('turnover20', 'Turnover', 'end') ?>
                    <th class="text-center"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <?php $lastTs = (int)($r['last_ts'] ?? 0); $rowStale = $dataTs !== null && $lastTs > 0 && $dataTs - $lastTs > $rowLagMs; ?>
                <tr<?= $rowStale ? ' class="opacity-50"' : '' ?>>
                    <td class="fw-semibold">
                        <?= Html::encode($r['symbol']) ?>
                        <?php if (!$r['tradeable']): ?>
                            <span class="badge bg-secondary-subtle text-secondary-emphasis" title="Below the $5M turnover floor — not currently traded">thin</span>
                        <?php endif ?>
                        <?php if ($rowStale): ?>
                            <span class="badge bg-warning-subtle text-warning-emphasis" title="Last candle <?= gmdate('Y-m-d H:i', intdiv($lastTs, 1000)) ?> UTC — this coin stopped updating">stale</span>
                        <?php endif ?>
                    </td>
                    <td class="text-end num"><?= $fmtPx((float)$r['price']) ?></td>
                    <?= $chgCell($r['chg24h'] !== null ? (float)$r['chg24h'] : null) ?>
                    <?= $rsiCell($r['rsi']['1d'], true) ?>
                    <?= $rsiCell($r['rsi']['4h']) ?>
                    <?= $rsiCell($r['rsi']['1h']) ?>
                    <?= $rsiCell($r['rsi']['15m']) ?>
                    <td class="text-end num text-secondary"><?= $turnover($r['turnover20'] !== null ? (float)$r['turnover20'] : null) ?></td>
                    <td class="text-center">
                        <?= Html::a('📈', ['chart', 'symbol' => $r['symbol']], ['class' => 'text-decoration-none', 'target' => '_blank', 'title' => 'Open chart']) ?>
                    </td>
                </tr>
            <?php endforeach ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">
                    <?php if ($updated === null): ?>
                        The RSI board hasn't been built yet. Run <code>yii trading/fetch-data</code>
                        (or <code>yii trading/rsi-board</code> if the history is already fetched).
                    <?php else: ?>
                        No coin data. Run <code>yii trading/fetch-data</code> first.
                    <?php endif ?>
                </td></tr>
            <?php endif ?>
            </tbody>
        </table>
    </div>
</div>
