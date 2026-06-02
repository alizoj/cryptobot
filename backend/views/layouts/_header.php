<?php

declare(strict_types=1);

/** @var yii\web\View $this */

use yii\bootstrap5\Nav;
use yii\bootstrap5\NavBar;
use yii\helpers\Html;

$items = [
    [
        'label' => 'Home',
        'url' => ['/site/index'],
    ],
    [
        'label' => 'Trading',
        'url' => ['/trading/index'],
        'visible' => !Yii::$app->user->isGuest,
        'items' => [
            ['label' => 'Dashboard', 'url' => ['/trading/index']],
            ['label' => 'RSI heatmap', 'url' => ['/trading/rsi']],
            ['label' => 'Daily scan', 'url' => ['/trading/scan-preview']],
            ['label' => '4h scan', 'url' => ['/trading/scan4h-preview']],
            ['label' => 'Symbol chart', 'url' => ['/trading/chart', 'symbol' => 'BTC']],
        ],
    ],
    [
        'label' => 'Login',
        'url' => ['/site/login'],
        'visible' => Yii::$app->user->isGuest,
    ],
    [
        'label' => 'Logout (' . Html::encode(Yii::$app->user->identity?->username) . ')',
        'url' => ['/site/logout'],
        'linkOptions' => [
            'data-method' => 'post',
            'class' => 'logout',
        ],
        'visible' => !Yii::$app->user->isGuest,
    ],
];
?>
<header id="header">
    <?php NavBar::begin(
        [
            'brandLabel' => Html::img('@web/images/codelbk_logo.png', [
                    'alt' => 'CodeLBK', 'height' => 28, 'class' => 'd-inline-block align-text-top me-2',
                ]) . Html::encode(Yii::$app->name),
            'brandUrl' => Yii::$app->homeUrl,
            'options' => ['class' => 'navbar-expand-md navbar-dark bg-dark fixed-top']
        ],
    ) ?>
    <?= Nav::widget(
        [
            'options' => ['class' => 'navbar-nav me-auto'],
            'encodeLabels' => false,
            'items' => $items,
        ],
    ) ?>
    <?= Html::button(
        '&#127769;',
        [
            'id' => 'theme-toggle',
            'class' => 'btn btn-link nav-link fs-5',
            'aria-label' => 'Switch to dark mode',
        ],
    ) ?>
    <?php NavBar::end() ?>
</header>
