<?php

declare(strict_types=1);

return [
    'bootstrap' => [
        'queue',
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
        // Signal scanning runs through this queue (not cron). The Redis driver
        // reuses the `redis` connection from main-local.php and supports the
        // delayed jobs the hourly self-reschedule needs. Worker:
        //   ./yii queue/listen --verbose=1
        // See docs/TRADING_BOT_SETUP.md §4 for the production worker setup.
        'queue' => [
            'class'   => \yii\queue\redis\Queue::class,
            'redis'   => 'redis',          // the redis component id
            'channel' => 'crypto_signals', // keep distinct from any other queue
            'as log'  => \yii\queue\LogBehavior::class,
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
            ],
        ],
    ],
];
