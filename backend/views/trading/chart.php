<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var string $symbol */
/** @var list<array{time:int,open:float,high:float,low:float,close:float}> $series */
/** @var float|null $entry */
/** @var float|null $stop */
/** @var string|null $side */
/** @var string|null $setup */
/** @var string|null $asOf */
/** @var int $zoom */
/** @var int $visibleFrom */
/** @var int $visibleTo */
/** @var list<float> $support */
/** @var list<float> $resistance */

use yii\helpers\Html;
use yii\helpers\Json;

$this->title = "Chart {$symbol}";

$isLong = $side === 'long';
$entryColor = $entry === null ? null : ($isLong ? '#16a34a' : '#dc2626');

// Build a marker on the signal day (asOf) if we have one.
$markerTime = null;
if ($asOf !== null && $asOf !== '') {
    $ts = strtotime($asOf . ' UTC');
    if ($ts !== false) {
        $markerTime = $ts;
    }
}

$cfg = Json::encode([
    'series'      => $series,
    'entry'       => $entry,
    'stop'        => $stop,
    'entryColor'  => $entryColor,
    'side'        => $side,
    'markerTime'  => $markerTime,
    'visibleFrom' => $visibleFrom,
    'visibleTo'   => $visibleTo,
    'support'     => $support,
    'resistance'  => $resistance,
]);

$zoomBtn = static function (int $days, int $current) use ($symbol, $entry, $stop, $side, $setup, $asOf): string {
    $cls = 'btn btn-sm ' . ($days === $current ? 'btn-primary' : 'btn-outline-secondary');
    return Html::a($days . 'd', [
        'chart',
        'symbol' => $symbol, 'entry' => $entry, 'stop' => $stop,
        'side'   => $side,   'setup' => $setup, 'asOf' => $asOf, 'zoom' => $days,
    ], ['class' => $cls]);
};
?>
<div class="container py-4">
    <p><?= Html::a('&laquo; back to RSI heatmap', ['rsi'], ['class' => 'small']) ?></p>
    <h1 class="mb-1"><?= Html::encode($symbol) ?>
        <?php if ($setup): ?>
            <small class="text-muted"><?= Html::encode($setup) ?> &middot;
                <?= $isLong ? '<span class="text-success">LONG</span>' : '<span class="text-danger">SHORT</span>' ?>
                <?php if ($asOf): ?>&middot; <?= Html::encode($asOf) ?><?php endif; ?>
            </small>
        <?php endif; ?>
    </h1>
    <p class="small text-muted mb-2">
        <?php if ($entry !== null): ?>entry <strong><?= $entry ?></strong>&nbsp;&nbsp;<?php endif; ?>
        <?php if ($stop !== null): ?>stop <strong><?= $stop ?></strong><?php endif; ?>
    </p>

    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <span class="small text-muted me-1">Zoom:</span>
        <div class="btn-group" role="group">
            <?= $zoomBtn(30, $zoom) ?>
            <?= $zoomBtn(60, $zoom) ?>
            <?= $zoomBtn(90, $zoom) ?>
            <?= $zoomBtn(180, $zoom) ?>
            <?= $zoomBtn(365, $zoom) ?>
        </div>
        <span class="small text-muted ms-auto">
            <span style="color:#10b981">━━</span> support &nbsp;
            <span style="color:#ef4444">━━</span> resistance
        </span>
    </div>

    <div id="tv-chart" style="width:100%;height:560px;"></div>
</div>

<script src="https://unpkg.com/lightweight-charts@4.1.3/dist/lightweight-charts.standalone.production.js"></script>
<?php
$js = <<<JS
(function () {
    var cfg = {$cfg};
    var el = document.getElementById('tv-chart');
    var chart = LightweightCharts.createChart(el, {
        width: el.clientWidth,
        height: 560,
        layout: { background: { color: '#ffffff' }, textColor: '#333' },
        grid: { vertLines: { color: '#f0f0f0' }, horzLines: { color: '#f0f0f0' } },
        timeScale: { timeVisible: false, borderColor: '#ccc', rightOffset: 4 },
        rightPriceScale: { borderColor: '#ccc' },
    });

    var candleSeries = chart.addCandlestickSeries({
        upColor: '#16a34a', downColor: '#dc2626',
        borderUpColor: '#16a34a', borderDownColor: '#dc2626',
        wickUpColor: '#16a34a', wickDownColor: '#dc2626',
    });
    candleSeries.setData(cfg.series);

    // Support / resistance from server-computed pivots.
    (cfg.support || []).forEach(function (px) {
        candleSeries.createPriceLine({
            price: px, color: '#10b981',
            lineWidth: 1, lineStyle: 3 /* Dotted */,
            axisLabelVisible: true, title: 'S',
        });
    });
    (cfg.resistance || []).forEach(function (px) {
        candleSeries.createPriceLine({
            price: px, color: '#ef4444',
            lineWidth: 1, lineStyle: 3 /* Dotted */,
            axisLabelVisible: true, title: 'R',
        });
    });

    if (cfg.entry !== null) {
        candleSeries.createPriceLine({
            price: cfg.entry, color: cfg.entryColor || '#16a34a',
            lineWidth: 2, lineStyle: 2, axisLabelVisible: true, title: 'entry',
        });
    }
    if (cfg.stop !== null) {
        candleSeries.createPriceLine({
            price: cfg.stop, color: '#f59e0b',
            lineWidth: 2, lineStyle: 2, axisLabelVisible: true, title: 'stop',
        });
    }
    if (cfg.markerTime !== null) {
        var long = cfg.side === 'long';
        candleSeries.setMarkers([{
            time: cfg.markerTime,
            position: long ? 'belowBar' : 'aboveBar',
            color: long ? '#16a34a' : '#dc2626',
            shape: long ? 'arrowUp' : 'arrowDown',
            text: cfg.side ? cfg.side.toUpperCase() : '',
        }]);
    }

    // Zoom to the last N days (server-resolved); user can scroll left to see more.
    chart.timeScale().setVisibleRange({ from: cfg.visibleFrom, to: cfg.visibleTo });

    window.addEventListener('resize', function () {
        chart.applyOptions({ width: el.clientWidth });
    });
})();
JS;
$this->registerJs($js, \yii\web\View::POS_END);
?>
