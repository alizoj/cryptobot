<?php

declare(strict_types=1);

/**
 * Candlestick chart for one coin, or a multi-coin comparison where every
 * close is rebased to its % change since the start of the visible window.
 *
 * @var yii\web\View $this
 * @var string $mode 'single'|'compare'
 * @var string $symbol primary coin
 * @var list<string> $symbols all charted coins (1 in single mode)
 * @var list<string> $allSymbols full universe for the pickers
 * @var list<array{symbol:string,data:list<array{time:int,value:float}>}> $compare
 * @var list<array{time:int,open:float,high:float,low:float,close:float}> $series
 * @var float|null $entry
 * @var float|null $stop
 * @var string|null $side
 * @var string|null $setup
 * @var string|null $asOf
 * @var int $zoom
 * @var int $visibleFrom
 * @var int $visibleTo
 * @var list<float> $support
 * @var list<float> $resistance
 */

use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;

$isCompare = $mode === 'compare';
$palette = ['#2962ff', '#e91e63', '#00bfa5', '#ff6d00', '#8e24aa', '#43a047', '#f9a825', '#795548'];

$this->title = $isCompare
    ? Yii::t('app', 'Comparison') . ': ' . implode(' · ', $symbols)
    : Yii::t('app', 'Chart {symbol}', ['symbol' => $symbol]);

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
    'mode'        => $mode,
    'symbols'     => $symbols,
    'series'      => $series,
    'compare'     => $compare,
    'palette'     => $palette,
    'entry'       => $entry,
    'stop'        => $stop,
    'entryColor'  => $entryColor,
    'side'        => $side,
    'markerTime'  => $markerTime,
    'visibleFrom' => $visibleFrom,
    'visibleTo'   => $visibleTo,
    'support'     => $support,
    'resistance'  => $resistance,
    'baseUrl'     => Url::to(['chart']),
    'zoom'        => $zoom,
]);

$zoomBtn = static function (int $days) use ($isCompare, $symbol, $symbols, $entry, $stop, $side, $setup, $asOf, $zoom): string {
    $cls = 'btn btn-sm ' . ($days === $zoom ? 'btn-primary' : 'btn-outline-secondary');
    $route = $isCompare
        ? ['chart', 'symbols' => implode(',', $symbols), 'zoom' => $days]
        : ['chart', 'symbol' => $symbol, 'entry' => $entry, 'stop' => $stop, 'side' => $side, 'setup' => $setup, 'asOf' => $asOf, 'zoom' => $days];
    return Html::a($days . 'd', $route, ['class' => $cls]);
};

$this->registerCss(<<<CSS
.cmp-chip { display: inline-flex; align-items: center; gap: .4em; font-family: var(--bs-font-monospace);
    font-size: .78rem; font-weight: 600; color: var(--bs-body-color);
    background: var(--bs-tertiary-bg); border: 1px solid var(--bs-border-color);
    border-radius: 2rem; padding: .28em .7em; }
.cmp-dot { width: .62em; height: .62em; border-radius: 50%; background: var(--chip); }
.cmp-x { text-decoration: none; color: var(--bs-secondary-color); font-weight: 700;
    margin-left: .1em; line-height: 1; }
.cmp-x:hover { color: var(--bs-danger); }
#coin-select { font-family: var(--bs-font-monospace); font-weight: 600; }
#coin-add { font-family: var(--bs-font-monospace); max-width: 9.5rem; }
CSS);
?>
<div class="container py-4">
    <p><?= Html::a('&laquo; ' . Yii::t('app', 'back to RSI heatmap'), ['rsi'], ['class' => 'small']) ?></p>

    <?php if ($isCompare): ?>
        <h1 class="mb-1"><?= Yii::t('app', 'Comparison') ?>
            <small class="text-muted fs-6"><?= count($symbols) ?> · <?= Yii::t('app', '% change since the start of the visible window') ?></small>
        </h1>
    <?php else: ?>
        <h1 class="mb-1"><?= Html::encode($symbol) ?>
            <?php if ($setup): ?>
                <small class="text-muted"><?= Html::encode($setup) ?> &middot;
                    <?= $isLong ? '<span class="text-success">LONG</span>' : '<span class="text-danger">SHORT</span>' ?>
                    <?php if ($asOf): ?>&middot; <?= Html::encode($asOf) ?><?php endif; ?>
                </small>
            <?php endif; ?>
        </h1>
        <?php if ($entry !== null || $stop !== null): ?>
            <p class="small text-muted mb-2">
                <?php if ($entry !== null): ?>entry <strong><?= $entry ?></strong>&nbsp;&nbsp;<?php endif; ?>
                <?php if ($stop !== null): ?>stop <strong><?= $stop ?></strong><?php endif; ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>

    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <?php if ($isCompare): ?>
            <span class="d-inline-flex flex-wrap gap-1 align-items-center">
                <?php foreach ($symbols as $i => $s):
                    $rest = array_values(array_diff($symbols, [$s]));
                    $route = count($rest) > 1
                        ? ['chart', 'symbols' => implode(',', $rest), 'zoom' => $zoom]
                        : ['chart', 'symbol' => $rest[0], 'zoom' => $zoom];
                ?>
                    <span class="cmp-chip" style="--chip: <?= $palette[$i % count($palette)] ?>">
                        <span class="cmp-dot"></span><?= Html::encode($s) ?>
                        <?= Html::a('&times;', $route, ['class' => 'cmp-x', 'title' => Yii::t('app', 'Remove {symbol}', ['symbol' => $s])]) ?>
                    </span>
                <?php endforeach ?>
            </span>
        <?php else: ?>
            <select id="coin-select" class="form-select form-select-sm w-auto" aria-label="<?= Html::encode(Yii::t('app', 'Coin')) ?>">
                <?php foreach ($allSymbols as $s): ?>
                    <option value="<?= Html::encode($s) ?>"<?= $s === $symbol ? ' selected' : '' ?>><?= Html::encode($s) ?></option>
                <?php endforeach ?>
            </select>
        <?php endif ?>

        <?php if (count($symbols) < 8): ?>
            <div class="input-group input-group-sm w-auto">
                <input list="coin-list" id="coin-add" class="form-control" autocomplete="off"
                       placeholder="<?= Html::encode(Yii::t('app', 'Compare with…')) ?>">
                <button class="btn btn-outline-secondary" type="button" id="coin-add-btn" title="<?= Html::encode(Yii::t('app', 'Add to comparison')) ?>">+</button>
            </div>
            <datalist id="coin-list">
                <?php foreach ($allSymbols as $s): ?>
                    <?php if (!in_array($s, $symbols, true)): ?><option value="<?= Html::encode($s) ?>"></option><?php endif ?>
                <?php endforeach ?>
            </datalist>
        <?php endif ?>

        <span class="small text-muted ms-2 me-1"><?= Yii::t('app', 'Zoom:') ?></span>
        <div class="btn-group" role="group">
            <?= $zoomBtn(30) ?>
            <?= $zoomBtn(60) ?>
            <?= $zoomBtn(90) ?>
            <?= $zoomBtn(180) ?>
            <?= $zoomBtn(365) ?>
        </div>

        <span class="small text-muted ms-auto">
            <?php if ($isCompare): ?>
                0% = <?= Yii::t('app', 'window start') ?>
            <?php else: ?>
                <span style="color:#10b981">━━</span> <?= Yii::t('app', 'support') ?> &nbsp;
                <span style="color:#ef4444">━━</span> <?= Yii::t('app', 'resistance') ?>
            <?php endif ?>
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

    // Colors follow the Bootstrap color mode and update live on toggle.
    function themeOpts() {
        var dark = (document.documentElement.getAttribute('data-bs-theme') || 'light') === 'dark';
        var grid = dark ? 'rgba(255,255,255,.06)' : '#f0f0f0';
        var border = dark ? '#3a4046' : '#cccccc';
        return {
            layout: { background: { color: 'transparent' }, textColor: dark ? '#cbd2d9' : '#333333' },
            grid: { vertLines: { color: grid }, horzLines: { color: grid } },
            timeScale: { timeVisible: false, borderColor: border, rightOffset: 4 },
            rightPriceScale: { borderColor: border },
        };
    }
    var chart = LightweightCharts.createChart(el, Object.assign({ width: el.clientWidth, height: 560 }, themeOpts()));
    new MutationObserver(function () { chart.applyOptions(themeOpts()); })
        .observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });

    if (cfg.mode === 'compare') {
        chart.applyOptions({ localization: { priceFormatter: function (v) { return v.toFixed(2) + '%'; } } });
        cfg.compare.forEach(function (s, i) {
            var line = chart.addLineSeries({
                color: cfg.palette[i % cfg.palette.length],
                lineWidth: 2,
                title: s.symbol,
                priceLineVisible: false,
                lastValueVisible: true,
            });
            line.setData(s.data);
            if (i === 0) {
                line.createPriceLine({ price: 0, color: 'rgba(128,128,128,.45)', lineWidth: 1, lineStyle: 3, axisLabelVisible: false, title: '' });
            }
        });
    } else {
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
    }

    // Zoom to the last N days (server-resolved); user can scroll left to see more.
    chart.timeScale().setVisibleRange({ from: cfg.visibleFrom, to: cfg.visibleTo });

    window.addEventListener('resize', function () {
        chart.applyOptions({ width: el.clientWidth });
    });

    // --- coin pickers ---
    function go(params) {
        location.href = cfg.baseUrl + (cfg.baseUrl.indexOf('?') >= 0 ? '&' : '?') + params;
    }
    var sel = document.getElementById('coin-select');
    if (sel) {
        sel.addEventListener('change', function () {
            go('symbol=' + encodeURIComponent(sel.value) + '&zoom=' + cfg.zoom);
        });
    }
    var add = document.getElementById('coin-add');
    if (add) {
        var addCoin = function () {
            var v = (add.value || '').trim().toUpperCase();
            if (!v || cfg.symbols.indexOf(v) >= 0) return;
            go('symbols=' + encodeURIComponent(cfg.symbols.concat([v]).join(',')) + '&zoom=' + cfg.zoom);
        };
        add.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); addCoin(); }
        });
        add.addEventListener('change', addCoin);   // datalist click
        document.getElementById('coin-add-btn').addEventListener('click', addCoin);
    }
})();
JS;
$this->registerJs($js, \yii\web\View::POS_END);
?>
