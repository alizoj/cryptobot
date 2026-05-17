<?php

declare(strict_types=1);

return [
    'adminEmail' => 'admin@example.com',
    'supportEmail' => 'support@example.com',
    'senderEmail' => 'noreply@example.com',
    'senderName' => 'Example.com mailer',
    'user.passwordResetTokenExpire' => 3600,
    'user.passwordMinLength' => 8,

    // ----- Trading bot config (Experiment 8 setup: v1 setup-specific stops + T+20) -----
    // Override sensitive bits in common/config/params-local.php (gitignored).
    'telegram' => [
        'botToken'  => '',                 // from @BotFather
        'chatId'    => '',                 // channel id (e.g. -1001234567890) or @channelname
        'parseMode' => 'HTML',
        'disableWebPagePreview' => true,
        // optional override of api host for testing
        'apiBase'   => 'https://api.telegram.org',
    ],
    'trading' => [
        // ---- sizing / risk ----
        'equityUsd'             => 10000.0,
        'riskPct'               => 0.005,   // 0.5% per trade
        'aggressiveRiskPct'     => 0.0075,  // 0.75% per TRADE.md §3 (size-up triggers)
        'maxNotionalPct'        => 0.20,    // 20% of equity cap
        'maxPortfolioNotionalPct' => 0.60,  // 60% open notional cap
        'maxConcurrentSingles'  => 5,
        'slippageBpsRoundTrip'  => 15,      // 0.15% expected, baked into PnL estimate

        // ---- exit / lifecycle (Experiment 8) ----
        'timeExitDays'          => 20,      // T+20 close (Exp 8)
        'trailActivateProfitPct'=> 0.02,    // start trailing after +2%

        // ---- filters ----
        'applyL1ClusterFilter'  => false,   // dropped per Exp 9
        'applyClusterFilterOtherSetups' => true,  // L2/L3/L4 + shorts honor 50-symbol cluster pause
        'clusterPauseThreshold' => 50,
        'fundingMode'           => 'mean',  // 'mean' or 'live' (mean per Exp 12)

        // ---- universe ----
        'minDailyCandles'       => 60,
        'minTurnover20dUsd'     => 5_000_000,
        'blacklist'             => ['XAU', 'USTC', 'WLFI', 'LINEA', '1INCH', 'ACE', 'ACH'],
        'memecoinPriceOnly'     => ['PEPE', 'BONK', 'SHIB', 'FLOKI', 'RATS'],

        // ---- DORMANT setups (won't fire even if data matches) ----
        'dormantSetups'         => ['S1', 'S2'],

        // ---- paths ----
        'dataDir'               => '@common/data',
        'historyDir'            => '@common/data/history_prices',
        'macroDir'              => '@common/data/history/macro',
        'stateDir'              => '@common/data/trade_state',
    ],
];
