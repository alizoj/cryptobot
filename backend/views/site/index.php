<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var int $openCount */
/** @var int $closedCount */
/** @var int $signalCount */
/** @var bool $telegramOn */
/** @var array<string, mixed> $params */

use yii\helpers\Html;

$this->title = 'Dashboard';
$username = Yii::$app->user->identity?->username;

$this->registerCss(<<<CSS
.dash-kpi { border: 0; border-radius: .7rem; box-shadow: 0 1px 3px rgba(0,0,0,.09); }
.dash-kpi .v { font-size: 1.7rem; font-weight: 600; line-height: 1; }
.dash-kpi .l { font-size: .7rem; text-transform: uppercase; letter-spacing: .05em; color: var(--bs-secondary-color); }
.nav-card { border: 0; border-radius: .8rem; box-shadow: 0 1px 3px rgba(0,0,0,.08); transition: transform .12s ease, box-shadow .12s ease; height: 100%; }
.nav-card:hover { transform: translateY(-3px); box-shadow: 0 6px 18px rgba(0,0,0,.12); }
.nav-card .ic { font-size: 1.8rem; line-height: 1; }
.nav-card .card-title { font-weight: 600; margin-bottom: .15rem; }
.nav-card .card-text { font-size: .85rem; color: var(--bs-secondary-color); }
CSS);

/** @var list<array{icon:string,title:string,desc:string,route:array,accent:string}> $links */
$links = [
    ['icon' => '📊', 'title' => 'Trading dashboard', 'desc' => 'Open positions, realised P&L and recent signals.', 'route' => ['/trading/index'], 'accent' => 'primary'],
    ['icon' => '📅', 'title' => 'Daily scan', 'desc' => 'Dry-run the daily setups (L1–S4) for any UTC day.', 'route' => ['/trading/scan-preview'], 'accent' => 'success'],
    ['icon' => '⏱️', 'title' => '4h scan', 'desc' => 'Dry-run the 4h multi-timeframe setups (L5 / S6).', 'route' => ['/trading/scan4h-preview'], 'accent' => 'info'],
    ['icon' => '🕯️', 'title' => 'Symbol chart', 'desc' => 'Interactive candlestick chart for any symbol.', 'route' => ['/trading/chart', 'symbol' => 'BTC'], 'accent' => 'warning'],
];
?>
<div class="site-index py-3">

    <!-- Welcome banner -->
    <div class="dashboard-banner text-white rounded-4 p-4 p-lg-5 mb-4">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <h1 class="fw-bold mb-2">Welcome back, <?= Html::encode($username) ?></h1>
                <p class="opacity-75 mb-0">Crypto trading-bot admin — scan, monitor positions and chart setups.</p>
            </div>
            <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
                <?= Html::a('Open trading dashboard →', ['/trading/index'], ['class' => 'btn btn-light fw-semibold']) ?>
            </div>
        </div>
    </div>

    <!-- KPIs -->
    <div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
        <div class="col"><div class="card dash-kpi h-100"><div class="card-body">
            <div class="l">Open positions</div><div class="v"><?= (int)$openCount ?></div>
        </div></div></div>
        <div class="col"><div class="card dash-kpi h-100"><div class="card-body">
            <div class="l">Closed trades</div><div class="v"><?= (int)$closedCount ?></div>
        </div></div></div>
        <div class="col"><div class="card dash-kpi h-100"><div class="card-body">
            <div class="l">Signals logged</div><div class="v"><?= (int)$signalCount ?></div>
        </div></div></div>
        <div class="col"><div class="card dash-kpi h-100"><div class="card-body">
            <div class="l">Telegram</div>
            <div class="v"><?= $telegramOn ? '<span class="badge text-bg-success">on</span>' : '<span class="badge text-bg-danger">off</span>' ?></div>
            <div class="small text-secondary mt-1">Risk/trade <?= number_format((float)($params['riskPct'] ?? 0) * 100, 2) ?>% · T+<?= (int)($params['timeExitDays'] ?? 20) ?></div>
        </div></div></div>
    </div>

    <!-- Navigation cards -->
    <h2 class="h5 mb-3">Quick links</h2>
    <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3">
        <?php foreach ($links as $lnk): ?>
            <div class="col">
                <div class="card nav-card position-relative">
                    <div class="card-body">
                        <div class="ic text-<?= $lnk['accent'] ?> mb-2"><?= $lnk['icon'] ?></div>
                        <div class="card-title"><?= Html::encode($lnk['title']) ?></div>
                        <p class="card-text mb-0"><?= Html::encode($lnk['desc']) ?></p>
                        <?= Html::a('', $lnk['route'], ['class' => 'stretched-link', 'aria-label' => $lnk['title']]) ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

</div>
