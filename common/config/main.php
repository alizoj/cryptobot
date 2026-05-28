<?php

declare(strict_types=1);

return [
    'bootstrap' => [
        \common\bootstrap\MailerBootstrap::class,
        \common\bootstrap\TradingSettingsBootstrap::class,
    ],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'vendorPath' => dirname(dirname(__DIR__)) . '/vendor',
    'components' => [
        'cache' => [
            'class' => \yii\redis\Cache::class,
            'keyPrefix' => 'crypto_',
            'defaultDuration' => 300,
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
            ],
        ],
    ],
];
