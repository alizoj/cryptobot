<?php

declare(strict_types=1);

/** @var yii\web\View $this */

use yii\bootstrap5\Nav;
use yii\bootstrap5\NavBar;
use yii\helpers\Html;

$lang = str_starts_with((string)Yii::$app->language, 'ru') ? 'ru' : 'en';

$items = [
    [
        'label' => Yii::t('app', 'Home'),
        'url' => ['/site/index'],
    ],
    [
        'label' => Yii::t('app', 'Trading'),
        'url' => ['/trading/rsi'],
        'visible' => !Yii::$app->user->isGuest,
        'items' => [
            ['label' => Yii::t('app', 'Delta alignment'), 'url' => ['/trading/delta']],
            ['label' => Yii::t('app', 'RSI heatmap'), 'url' => ['/trading/rsi']],
            ['label' => Yii::t('app', 'Symbol chart'), 'url' => ['/trading/chart', 'symbol' => 'BTC']],
        ],
    ],
    [
        'label' => Yii::t('app', 'Login'),
        'url' => ['/site/login'],
        'visible' => Yii::$app->user->isGuest,
    ],
    [
        'label' => Yii::t('app', 'Logout ({username})', ['username' => Html::encode(Yii::$app->user->identity?->username)]),
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
    <div class="navbar-nav flex-row align-items-center me-2" id="lang-switch" role="group" aria-label="Language">
        <?= Html::a('EN', ['/site/language', 'lang' => 'en'], [
            'class' => 'nav-link px-2' . ($lang === 'en' ? ' active fw-semibold' : ' text-secondary'),
            'aria-current' => $lang === 'en' ? 'true' : null,
        ]) ?>
        <span class="text-secondary small">/</span>
        <?= Html::a('RU', ['/site/language', 'lang' => 'ru'], [
            'class' => 'nav-link px-2' . ($lang === 'ru' ? ' active fw-semibold' : ' text-secondary'),
            'aria-current' => $lang === 'ru' ? 'true' : null,
        ]) ?>
    </div>
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
