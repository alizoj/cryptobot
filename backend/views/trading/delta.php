<?php

declare(strict_types=1);

/**
 * DELTA_ALIGNMENT_SHORT_V1 live screener.
 *
 * Server renders the shell + bootstraps the current rows; everything after
 * that is the 1 Hz poll against /trading/delta-ticks (live price folded into
 * the closed-candle sums server-side). The closed-candle board itself only
 * changes when the 5-minute cron lands, which the sync ring counts down to.
 *
 * @var yii\web\View $this
 * @var list<array<string,mixed>> $rows
 * @var int|null $boardUpdated
 * @var array<string,mixed> $meta
 */

use common\services\Trading\DeltaAlignment;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;

$this->title = 'Delta Alignment';
$this->render('_kit');
$this->registerCssFile('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&display=swap');

$tfLabels = array_keys(DeltaAlignment::TFS);

$cfg = Json::encode([
    'ticksUrl'     => Url::to(['delta-ticks']),
    'chartUrl'     => Url::to(['chart', 'symbol' => '__SYM__']),
    'tfs'          => $tfLabels,
    'window'       => [DeltaAlignment::WINDOW_LO, DeltaAlignment::WINDOW_HI],
    'meta'         => $meta,
    'rows'         => $rows,
    'boardUpdated' => $boardUpdated,
    'serverNow'    => time(),
    'syncEvery'    => 300,
    'i18n'         => [
        'live'       => Yii::t('app', 'LIVE'),
        'paused'     => Yii::t('app', 'PAUSED'),
        'stale'      => Yii::t('app', 'STALE'),
        'connecting' => Yii::t('app', 'CONNECTING'),
        'lastClose'  => Yii::t('app', 'No live ticker — showing the last 5m close'),
        'inWindow'   => Yii::t('app', 'Inside the signal window (−2 … 0)'),
        'short'      => Yii::t('app', 'SHORT'),
        'noMatch'    => Yii::t('app', 'No coins match the current filter.'),
        'syncWarn'   => Yii::t('app', 'Candle data is {age} old — is the 5-minute board cron running? ({cmd})'),
        'ago'        => Yii::t('app', '{age} ago'),
    ],
]);
?>

<div class="dax">

    <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mt-3 mb-1">
        <div>
            <h1 class="h4 mb-1 d-flex align-items-center gap-2 flex-wrap">
                <?= Yii::t('app', 'Delta Alignment') ?>
                <code class="dax-chip" title="<?= Html::encode(Yii::t('app', 'Signal identifier')) ?>"><?= Html::encode(DeltaAlignment::NAME) ?></code>
                <span class="dax-bias" title="<?= Html::encode(Yii::t('app', 'Historically observed bias over {n} alignment events', ['n' => $meta['sample_size']])) ?>">
                    <?= Yii::t('app', 'SHORT bias') ?> · <?= $meta['short_probability'] ?>%
                </span>
            </h1>
            <p class="text-secondary small mb-0">
                <?= Yii::t('app', 'Live SMA15 vs SMA50 distance on 5m · 30m · 1h · 2h. Each SMA blends the live price with the last {fast} / {slow} closed candles, so the deltas move every second; the signal fires when all four sit inside (−2%, 0%).', ['fast' => DeltaAlignment::SMA_FAST - 1, 'slow' => DeltaAlignment::SMA_SLOW - 1]) ?>
            </p>
        </div>
        <div class="d-flex align-items-center gap-2 ms-auto">
            <span class="dax-pill" id="dax-pill" data-state="connecting">
                <span class="dax-pill-dot"></span><span id="dax-pill-text">…</span>
            </span>
            <span class="dax-sync" id="dax-sync" title="<?= Html::encode(Yii::t('app', 'Closed-candle data age · ring counts down to the next 5m candle')) ?>">
                <svg viewBox="0 0 20 20" class="dax-ring" aria-hidden="true">
                    <circle cx="10" cy="10" r="8" class="dax-ring-bg"></circle>
                    <circle cx="10" cy="10" r="8" class="dax-ring-fg" id="dax-ring-fg"></circle>
                </svg>
                <span id="dax-sync-text">—</span>
            </span>
        </div>
    </div>

    <div class="alert alert-warning py-2 px-3 small d-none my-2" id="dax-warn"></div>

    <div class="row g-2 my-1">
        <div class="col-6 col-md-3">
            <div class="card kpi-card dax-kpi dax-kpi-active h-100"><div class="card-body py-2 px-3">
                <div class="kpi-label"><?= Yii::t('app', 'Active signals') ?></div>
                <div class="kpi-val num" id="kpi-active">—</div>
                <div class="kpi-sub"><?= Yii::t('app', 'all four timeframes aligned') ?></div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card kpi-card dax-kpi h-100"><div class="card-body py-2 px-3">
                <div class="kpi-label"><?= Yii::t('app', 'Near misses') ?></div>
                <div class="kpi-val num" id="kpi-near">—</div>
                <div class="kpi-sub"><?= Yii::t('app', '3 of 4 timeframes in window') ?></div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card kpi-card dax-kpi h-100"><div class="card-body py-2 px-3">
                <div class="kpi-label"><?= Yii::t('app', 'Coins tracked') ?></div>
                <div class="kpi-val num" id="kpi-coins">—</div>
                <div class="kpi-sub"><?= Yii::t('app', 'HashHedge universe on Bybit') ?></div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card kpi-card dax-kpi h-100"><div class="card-body py-2 px-3">
                <div class="kpi-label"><?= Yii::t('app', 'Historical bias') ?></div>
                <div class="kpi-val num"><span class="dax-red"><?= $meta['short_probability'] ?>%</span><span class="dax-kpi-vs">/</span><?= $meta['long_probability'] ?>%</div>
                <div class="kpi-sub"><?= Yii::t('app', 'short / long over {n} events', ['n' => $meta['sample_size']]) ?></div>
            </div></div>
        </div>
    </div>

    <div class="d-flex flex-wrap align-items-center gap-2 my-2 dax-toolbar">
        <div class="dax-search">
            <span class="dax-search-icon" aria-hidden="true">⌕</span>
            <input type="search" id="dax-q" class="form-control form-control-sm" autocomplete="off"
                   placeholder="<?= Html::encode(Yii::t('app', 'Search coin…')) ?>">
        </div>
        <div class="btn-group btn-group-sm" role="group" aria-label="<?= Html::encode(Yii::t('app', 'Filter')) ?>">
            <button type="button" class="btn dax-seg active" data-filter="all"><?= Yii::t('app', 'All') ?></button>
            <button type="button" class="btn dax-seg" data-filter="active"><?= Yii::t('app', 'Active') ?></button>
            <button type="button" class="btn dax-seg" data-filter="near"><?= Yii::t('app', '≥3 TFs') ?></button>
        </div>
        <span class="text-secondary small ms-auto d-none d-sm-inline" id="dax-shown"></span>
    </div>

    <div class="card card-section dax-card">
        <div class="table-responsive">
            <table class="table table-sm tr-table dax-table align-middle mb-0">
                <thead>
                <tr>
                    <th class="text-start dax-th" data-key="symbol"><?= Yii::t('app', 'Coin') ?></th>
                    <th class="text-end dax-th" data-key="price"><?= Yii::t('app', 'Price') ?></th>
                    <?php foreach ($tfLabels as $tf): ?>
                        <th class="text-end dax-th" data-key="d:<?= $tf ?>">&Delta;&thinsp;<?= $tf ?></th>
                    <?php endforeach ?>
                    <th class="text-center dax-th dax-th-active" data-key="align"><?= Yii::t('app', 'Alignment') ?></th>
                </tr>
                </thead>
                <tbody id="dax-body">
                <?php if ($boardUpdated === null): ?>
                    <tr id="dax-setup"><td colspan="7" class="py-5">
                        <div class="text-center dax-empty">
                            <div class="dax-empty-mark">Δ</div>
                            <p class="mb-1 fw-semibold"><?= Yii::t('app', 'The delta board has not been built yet.') ?></p>
                            <p class="text-secondary small mb-2"><?= Yii::t('app', 'Run it once, then keep it on a 5-minute schedule (or start the queue loop):') ?></p>
                            <code class="d-block mb-1">./yii trading/delta-board</code>
                            <code class="d-block text-secondary">./yii trading/schedule-delta &nbsp;+&nbsp; ./yii queue/listen</code>
                        </div>
                    </td></tr>
                <?php endif ?>
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-secondary small mt-2 mb-4 dax-foot">
        <?= Yii::t('app', 'The signal reports a historically observed probability skew only — across {n} alignment events, {short}% resolved SHORT and {long}% LONG. It is not a direction guarantee.', [
            'n' => $meta['sample_size'], 'short' => $meta['short_probability'], 'long' => $meta['long_probability'],
        ]) ?>
        &nbsp;·&nbsp; <code class="dax-foot-api">GET <?= Html::encode(Url::to(['delta-signal', 'symbol' => 'BTC'])) ?></code>
    </p>

</div>

<?php
$css = <<<CSS
.dax { --dax-mono: 'JetBrains Mono', var(--bs-font-monospace);
       --dax-red: hsl(353 72% 47%); --dax-red-ink: hsl(353 72% 40%);
       --dax-met-bg: hsla(353, 72%, 50%, .13); --dax-met-ring: hsla(353, 72%, 50%, .35);
       --dax-green: hsl(152 55% 34%); --dax-deep: hsla(215, 25%, 50%, .85); }
[data-bs-theme="dark"] .dax { --dax-red: hsl(353 82% 60%); --dax-red-ink: hsl(353 85% 66%);
       --dax-met-bg: hsla(353, 75%, 55%, .17); --dax-met-ring: hsla(353, 75%, 60%, .4);
       --dax-green: hsl(152 50% 55%); --dax-deep: hsla(215, 22%, 62%, .85); }

.dax .num, .dax .dax-table tbody td { font-family: var(--dax-mono); }
.dax-red { color: var(--dax-red-ink); }

/* header chips */
.dax-chip { font-family: var(--dax-mono); font-size: .68rem; font-weight: 500; letter-spacing: .02em;
    padding: .22em .55em; border-radius: .35rem; color: var(--bs-secondary-color);
    background: var(--bs-tertiary-bg); border: 1px solid var(--bs-border-color); }
.dax-bias { font-size: .68rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
    padding: .25em .6em; border-radius: 2rem; color: var(--dax-red-ink);
    background: var(--dax-met-bg); border: 1px solid var(--dax-met-ring); }

/* live pill */
.dax-pill { display: inline-flex; align-items: center; gap: .45em; font-family: var(--dax-mono);
    font-size: .7rem; font-weight: 600; letter-spacing: .08em; padding: .3em .75em;
    border-radius: 2rem; border: 1px solid var(--bs-border-color); background: var(--bs-tertiary-bg);
    color: var(--bs-secondary-color); }
.dax-pill-dot { width: .5em; height: .5em; border-radius: 50%; background: currentColor; }
.dax-pill[data-state="live"] { color: var(--dax-green); border-color: color-mix(in srgb, var(--dax-green) 40%, transparent); }
.dax-pill[data-state="live"] .dax-pill-dot { animation: dax-pulse 1.6s ease-out infinite; }
.dax-pill[data-state="stale"] { color: var(--dax-red-ink); border-color: var(--dax-met-ring); }
@keyframes dax-pulse { 0% { box-shadow: 0 0 0 0 color-mix(in srgb, currentColor 55%, transparent); }
                       100% { box-shadow: 0 0 0 .55em transparent; } }

/* candle-sync ring */
.dax-sync { display: inline-flex; align-items: center; gap: .4em; font-family: var(--dax-mono);
    font-size: .7rem; color: var(--bs-secondary-color); padding: .3em .6em;
    border-radius: 2rem; border: 1px solid var(--bs-border-color); background: var(--bs-tertiary-bg); }
.dax-sync.warn { color: var(--dax-red-ink); border-color: var(--dax-met-ring); }
.dax-ring { width: 1.05em; height: 1.05em; transform: rotate(-90deg); }
.dax-ring circle { fill: none; stroke-width: 3; }
.dax-ring-bg { stroke: var(--bs-border-color); }
.dax-ring-fg { stroke: currentColor; stroke-linecap: round; stroke-dasharray: 50.27; transition: stroke-dashoffset .5s linear; }

/* KPI cards */
.dax-kpi .kpi-val { font-family: var(--dax-mono); }
.dax-kpi-vs { color: var(--bs-secondary-color); font-weight: 400; padding: 0 .12em; }
.dax-kpi-active { box-shadow: inset 0 0 0 1px var(--dax-met-ring), 0 1px 3px rgba(0,0,0,.09); }
.dax-kpi-active .kpi-val { color: var(--dax-red-ink); }
.dax-kpi-active.lit { background: linear-gradient(135deg, var(--dax-met-bg), transparent 65%); }

/* toolbar */
.dax-search { position: relative; }
.dax-search .form-control { padding-left: 1.9rem; width: 13rem; font-family: var(--dax-mono); }
.dax-search-icon { position: absolute; left: .6rem; top: 50%; transform: translateY(-54%);
    color: var(--bs-secondary-color); pointer-events: none; font-size: 1rem; }
.dax-seg { border: 1px solid var(--bs-border-color); color: var(--bs-secondary-color); }
.dax-seg.active { background: var(--bs-emphasis-color); border-color: var(--bs-emphasis-color);
    color: var(--bs-body-bg); }

/* table */
.dax-table td, .dax-table th { padding: .42rem .65rem; }
.dax-table thead th { font-family: var(--dax-mono); white-space: nowrap; }
.dax-th { cursor: pointer; user-select: none; }
.dax-th:hover { color: var(--bs-emphasis-color); }
.dax-th .arr { opacity: .9; font-size: .85em; }
.dax-sym-link { font-weight: 600; color: var(--bs-emphasis-color); text-decoration: none; }
.dax-sym-link:hover { color: var(--dax-red-ink); }
.dax-pair { font-size: .68rem; color: var(--bs-secondary-color); margin-left: .5em; }
.dax-px { text-align: right; white-space: nowrap; }
.dax-px.dead { color: var(--bs-secondary-color); font-style: italic; }

.dax-d { text-align: right; white-space: nowrap; font-size: .85rem;
    transition: background-color .35s ease; border-radius: .25rem; }
.dax-d.pos  { color: var(--dax-green); }
.dax-d.deep { color: var(--dax-deep); }
.dax-d.null { color: var(--bs-secondary-color); }
.dax-d.met  { color: var(--dax-red-ink); font-weight: 600;
    background: var(--dax-met-bg); box-shadow: inset 0 0 0 1px var(--dax-met-ring); }

/* per-tf alignment dots + active badge */
.dax-dots { display: inline-flex; gap: .3em; }
.dax-dot { width: .55em; height: .55em; border-radius: 50%;
    background: transparent; box-shadow: inset 0 0 0 1.5px var(--bs-border-color); }
.dax-dot.on { background: var(--dax-red); box-shadow: none; }
.dax-badge { font-family: var(--dax-mono); font-size: .68rem; font-weight: 700; letter-spacing: .05em;
    color: #fff; background: var(--dax-red); border-radius: .35rem; padding: .3em .55em;
    display: inline-block; animation: dax-land .35s ease; }
@keyframes dax-land { from { transform: scale(.7); opacity: 0; } }

/* active row */
.dax-table tbody tr { transition: background-color .35s ease; }
.dax-table tbody tr.dax-on { background: linear-gradient(90deg, var(--dax-met-bg), transparent 55%); }
.dax-table tbody tr.dax-on td:first-child { box-shadow: inset 3px 0 0 var(--dax-red); }

/* price tick flashes — the classic live-quote affordance */
@keyframes dax-flash-up   { from { background-color: hsla(152, 60%, 45%, .28); } }
@keyframes dax-flash-down { from { background-color: hsla(353, 72%, 52%, .28); } }
.dax-px.up   { animation: dax-flash-up .7s ease-out; }
.dax-px.down { animation: dax-flash-down .7s ease-out; }

.dax-empty-mark { font-family: var(--dax-mono); font-size: 2.6rem; line-height: 1;
    color: var(--dax-met-ring); margin-bottom: .35rem; }
.dax-foot-api { font-size: .72rem; color: var(--bs-secondary-color); }
CSS;
$this->registerCss($css);

$js = <<<JS
(function () {
    'use strict';
    var cfg = {$cfg};
    var R = 2 * Math.PI * 8;                      // sync-ring circumference
    var rows = new Map(), els = new Map();
    var sortKey = 'align', sortDir = 1, filter = 'all', q = '';
    var lastOkAt = 0, inFlight = false, boardUpdated = cfg.boardUpdated;
    var clockSkew = cfg.serverNow - Math.floor(Date.now() / 1000);
    var body = document.getElementById('dax-body');

    function now() { return Math.floor(Date.now() / 1000) + clockSkew; }

    function fmtPx(v) {
        if (v === null || v === undefined) return '—';
        if (v >= 100)  return v.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        if (v >= 1)    return v.toFixed(4);
        if (v >= 0.01) return v.toFixed(5);
        return parseFloat(v.toFixed(8)).toString();
    }
    function fmtAge(s) {
        if (s < 60) return s + 's';
        if (s < 3600) return Math.floor(s / 60) + 'm ' + String(s % 60).padStart(2, '0') + 's';
        return Math.floor(s / 3600) + 'h ' + Math.floor((s % 3600) / 60) + 'm';
    }
    function deltaClass(d, met) {
        if (d === null || d === undefined) return 'null';
        if (met) return 'met';
        if (d >= cfg.window[1]) return 'pos';
        return 'deep';
    }

    function buildRow(r) {
        var tr = document.createElement('tr');
        tr.dataset.sym = r.symbol;
        var html = '<td><a class="dax-sym-link" target="_blank" href="'
            + cfg.chartUrl.replace('__SYM__', encodeURIComponent(r.symbol)) + '">' + r.symbol + '</a>'
            + '<span class="dax-pair">' + r.bybit + '</span></td>'
            + '<td class="dax-px num"></td>';
        cfg.tfs.forEach(function (tf) { html += '<td class="dax-d num" data-tf="' + tf + '"></td>'; });
        html += '<td class="text-center dax-cell-align"></td>';
        tr.innerHTML = html;
        var ref = { tr: tr, px: tr.children[1], d: {}, align: tr.children[2 + cfg.tfs.length] };
        cfg.tfs.forEach(function (tf, i) { ref.d[tf] = tr.children[2 + i]; });
        els.set(r.symbol, ref);
        return tr;
    }

    function flash(el, cls) {
        el.classList.remove('up', 'down', 'tick');
        void el.offsetWidth;
        if (cls) el.classList.add(cls);
    }

    function updateRow(r, old) {
        var e = els.get(r.symbol);
        if (!e) return;
        e.px.textContent = fmtPx(r.price);
        e.px.title = r.live ? '' : cfg.i18n.lastClose;
        e.px.classList.toggle('dead', !r.live);
        if (old && old.price !== r.price && old.price !== null && r.price !== null) {
            flash(e.px, r.price > old.price ? 'up' : 'down');
        }
        cfg.tfs.forEach(function (tf) {
            var td = e.d[tf], d = r.deltas[tf], met = r.met[tf];
            td.textContent = (d === null || d === undefined) ? '—' : d.toFixed(4);
            td.className = 'dax-d num ' + deltaClass(d, met);
            td.title = met ? cfg.i18n.inWindow : '';
        });
        if (r.active) {
            e.align.innerHTML = '<span class="dax-badge">' + cfg.i18n.short + ' ' + cfg.meta.short_probability + '%</span>';
        } else {
            e.align.innerHTML = '<span class="dax-dots">' + cfg.tfs.map(function (tf) {
                return '<span class="dax-dot' + (r.met[tf] ? ' on' : '') + '" title="' + tf + '"></span>';
            }).join('') + '</span>';
        }
        e.tr.classList.toggle('dax-on', !!r.active);
    }

    function sortVal(r, key) {
        if (key === 'symbol') return r.symbol;
        if (key === 'price')  return r.price === null ? null : r.price;
        if (key === 'align')  return (r.active ? 1e6 : 0) + r.met_count * 1e3;
        if (key.indexOf('d:') === 0) { var d = r.deltas[key.slice(2)]; return d === null ? null : d; }
        return null;
    }
    function compare(a, b) {
        var va = sortVal(a, sortKey), vb = sortVal(b, sortKey);
        if (va === null && vb === null) return a.symbol < b.symbol ? -1 : 1;
        if (va === null) return 1;
        if (vb === null) return -1;
        if (va !== vb) return (va < vb ? -1 : 1) * sortDir;
        return a.symbol < b.symbol ? -1 : 1;
    }
    function visible(r) {
        if (q && r.symbol.toLowerCase().indexOf(q) < 0 && r.bybit.toLowerCase().indexOf(q) < 0) return false;
        if (filter === 'active') return r.active;
        if (filter === 'near') return r.active || r.met_count >= 3;
        return true;
    }

    function render() {
        var list = Array.from(rows.values()).sort(compare);
        var frag = null, orderChanged = false, prev = null;
        list.forEach(function (r) {
            var tr = els.get(r.symbol).tr;
            if (!orderChanged && tr.previousElementSibling !== prev) orderChanged = true;
            prev = tr;
        });
        if (orderChanged || body.children.length !== list.length) {
            frag = document.createDocumentFragment();
            list.forEach(function (r) { frag.appendChild(els.get(r.symbol).tr); });
            body.replaceChildren(frag);
        }
        var shown = 0, active = 0, near = 0;
        list.forEach(function (r) {
            var on = visible(r);
            els.get(r.symbol).tr.style.display = on ? '' : 'none';
            if (on) shown++;
            if (r.active) active++;
            else if (r.met_count === 3) near++;
        });
        document.getElementById('kpi-active').textContent = active;
        document.getElementById('kpi-near').textContent = near;
        document.getElementById('kpi-coins').textContent = rows.size;
        document.querySelector('.dax-kpi-active').classList.toggle('lit', active > 0);
        document.getElementById('dax-shown').textContent = shown + ' / ' + rows.size;
        var none = document.getElementById('dax-none');
        if (shown === 0 && rows.size > 0) {
            if (!none) {
                none = document.createElement('tr');
                none.id = 'dax-none';
                none.innerHTML = '<td colspan="7" class="text-center text-secondary py-4">' + cfg.i18n.noMatch + '</td>';
            }
            body.appendChild(none);
        } else if (none) none.remove();
    }

    function ingest(list) {
        var setup = document.getElementById('dax-setup');
        if (setup && list.length) setup.remove();
        list.forEach(function (r) {
            var old = rows.get(r.symbol) || null;
            if (!old) body.appendChild(buildRow(r));
            rows.set(r.symbol, r);
            updateRow(r, old);
        });
        render();
    }

    function poll() {
        if (inFlight || document.hidden) return;
        inFlight = true;
        fetch(cfg.ticksUrl, { headers: { 'Accept': 'application/json' } })
            .then(function (resp) { return resp.json(); })
            .then(function (data) {
                if (!data.ok) return;
                lastOkAt = Date.now();
                boardUpdated = data.board_updated;
                clockSkew = data.now - Math.floor(Date.now() / 1000);
                ingest(data.rows);
            })
            .catch(function () { /* pill turns stale via the status loop */ })
            .finally(function () { inFlight = false; });
    }

    function status() {
        var pill = document.getElementById('dax-pill');
        var state = 'stale';
        if (document.hidden) state = 'paused';
        else if (lastOkAt && Date.now() - lastOkAt < 4000) state = 'live';
        else if (!lastOkAt && Date.now() < t0 + 6000) state = 'connecting';
        pill.dataset.state = state;
        document.getElementById('dax-pill-text').textContent = cfg.i18n[state];

        var sync = document.getElementById('dax-sync');
        if (boardUpdated) {
            var age = Math.max(0, now() - boardUpdated);
            document.getElementById('dax-sync-text').textContent = cfg.i18n.ago.replace('{age}', fmtAge(age));
            var toNext = cfg.syncEvery - (now() % cfg.syncEvery);
            document.getElementById('dax-ring-fg').style.strokeDashoffset = (R * toNext / cfg.syncEvery).toFixed(2);
            var late = age > cfg.syncEvery + 150;
            sync.classList.toggle('warn', late);
            var warn = document.getElementById('dax-warn');
            warn.classList.toggle('d-none', !late);
            if (late) warn.textContent = cfg.i18n.syncWarn.replace('{age}', fmtAge(age)).replace('{cmd}', './yii trading/delta-board');
        }
    }

    // --- wire up ---
    var t0 = Date.now();
    function setArrow(th) {
        document.querySelectorAll('.dax-th .arr').forEach(function (a) { a.remove(); });
        var arr = document.createElement('span');
        arr.className = 'arr';
        arr.textContent = sortDir === 1 ? ' ▲' : ' ▼';
        th.appendChild(arr);
    }
    document.querySelectorAll('.dax-th').forEach(function (th) {
        th.addEventListener('click', function () {
            var key = th.dataset.key;
            if (sortKey === key) {
                sortDir = -sortDir;
            } else {
                sortKey = key;
                // best-first defaults: alignment & price read top-down as
                // "most interesting first", everything else ascending
                sortDir = (key === 'align' || key === 'price') ? -1 : 1;
            }
            setArrow(th);
            render();
        });
    });
    document.querySelectorAll('.dax-seg').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.dax-seg').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            filter = btn.dataset.filter;
            render();
        });
    });
    document.getElementById('dax-q').addEventListener('input', function (e) {
        q = e.target.value.trim().toLowerCase();
        render();
    });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });

    // default sort: alignment, best first
    sortKey = 'align'; sortDir = -1;
    setArrow(document.querySelector('.dax-th[data-key="align"]'));

    if (cfg.rows.length) ingest(cfg.rows);
    setInterval(poll, 1000);
    setInterval(status, 500);
    poll();
    status();
})();
JS;
$this->registerJs($js, \yii\web\View::POS_END);
?>
