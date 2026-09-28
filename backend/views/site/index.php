<?php

declare(strict_types=1);

/** @var yii\web\View $this */

use yii\helpers\Html;

$this->title = Yii::t('app', 'Dashboard');
$username = Yii::$app->user->identity?->username;

$this->registerCss(<<<CSS
.nav-card { border: 0; border-radius: .8rem; box-shadow: 0 1px 3px rgba(0,0,0,.08); transition: transform .12s ease, box-shadow .12s ease; height: 100%; }
.nav-card:hover { transform: translateY(-3px); box-shadow: 0 6px 18px rgba(0,0,0,.12); }
.nav-card .ic { font-size: 1.8rem; line-height: 1; }
.nav-card .card-title { font-weight: 600; margin-bottom: .15rem; }
.nav-card .card-text { font-size: .85rem; color: var(--bs-secondary-color); }
CSS);

/** @var list<array{icon:string,title:string,desc:string,route:array,accent:string}> $links */
$links = [
    ['icon' => '🌡️', 'title' => Yii::t('app', 'RSI heatmap'), 'desc' => Yii::t('app', 'Multi-timeframe Wilder RSI(14) across the whole coin universe.'), 'route' => ['/trading/rsi'], 'accent' => 'success'],
    ['icon' => '🕯️', 'title' => Yii::t('app', 'Symbol chart'), 'desc' => Yii::t('app', 'Interactive candlestick chart for any coin, with multi-coin compare.'), 'route' => ['/trading/chart'], 'accent' => 'warning'],
];
?>
<div class="site-index py-3">

    <!-- Welcome banner -->
    <div class="dashboard-banner text-white rounded-4 p-4 p-lg-5 mb-4">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <h1 class="fw-bold mb-2"><?= Yii::t('app', 'Welcome back, {username}', ['username' => Html::encode($username)]) ?></h1>
                <p class="opacity-75 mb-0"><?= Yii::t('app', 'Crypto market monitor — the RSI heatmap and coin charts. A new signal engine is in the works.') ?></p>
            </div>
            <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
                <?= Html::a(Yii::t('app', 'Open RSI heatmap →'), ['/trading/rsi'], ['class' => 'btn btn-light fw-semibold']) ?>
            </div>
        </div>
    </div>

    <!-- Navigation cards -->
    <h2 class="h5 mb-3"><?= Yii::t('app', 'Quick links') ?></h2>
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
