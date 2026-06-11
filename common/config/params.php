<?php

declare(strict_types=1);

return [
    'adminEmail' => 'admin@example.com',
    'supportEmail' => 'support@example.com',
    'senderEmail' => 'noreply@example.com',
    'senderName' => 'Example.com mailer',
    'user.passwordResetTokenExpire' => 3600,
    'user.passwordMinLength' => 8,

    // ----- Trading config -----
    // The signal engine has been removed (new strategy pending); what's left
    // is the market-data fetch + the chart/RSI views, so only Telegram, chart
    // rendering and the data paths remain configurable.
    // Override sensitive bits in common/config/params-local.php (gitignored).
    'telegram' => [
        'botToken'  => '',                 // from @BotFather
        'chatId'    => '',                 // channel id (e.g. -1001234567890) or @channelname
        'parseMode' => 'HTML',
        'disableWebPagePreview' => true,
        // optional override of api host for testing
        'apiBase'   => 'https://api.telegram.org',
    ],
    // ----- Chart rendering (QuickChart for Telegram images) -----
    'chart' => [
        'enabled'       => true,                      // attach a candlestick image to each entry signal
        'quickChartBase'=> 'https://quickchart.io',   // override to a self-hosted instance if desired
        'width'         => 800,
        'height'        => 450,
        'lookbackDays'  => 60,                         // how many trailing daily candles to plot
    ],
    'trading' => [
        // ---- paths (see common\services\Trading\Paths) ----
        'historyDir'            => '@common/data/history_prices',
        'macroDir'              => '@common/data/history/macro',
        'stateDir'              => '@common/data/trade_state',
    ],
];
