<?php

declare(strict_types=1);

/**
 * Russian translations for the `app` category (PhpMessageSource).
 *
 * Keys are the English source strings used in Yii::t('app', ...); any key
 * missing here falls back to its English source. The how-it-works /
 * signal-pipeline strings were removed together with the signal engine.
 */
return [
    // --- layout / navigation ---
    'Home' => 'Главная',
    'Trading' => 'Трейдинг',
    'RSI heatmap' => 'RSI-карта',
    'Symbol chart' => 'График символа',
    'Login' => 'Войти',
    'Logout ({username})' => 'Выйти ({username})',

    // --- dashboard (/site/index) ---
    'Dashboard' => 'Панель',
    'Welcome back, {username}' => 'С возвращением, {username}',
    'Crypto market monitor — the RSI heatmap and coin charts. A new signal engine is in the works.'
        => 'Монитор крипторынка — RSI-карта и графики монет. Новый сигнальный движок в разработке.',
    'Open RSI heatmap →' => 'Открыть RSI-карту →',
    'Quick links' => 'Быстрые ссылки',
    'Multi-timeframe Wilder RSI(14) across the whole coin universe.'
        => 'Мультитаймфреймовый Wilder RSI(14) по всему юниверсу монет.',
    'Interactive candlestick chart for any coin, with multi-coin compare.'
        => 'Интерактивный свечной график любой монеты со сравнением нескольких монет.',

    // --- symbol chart / comparison (/trading/chart) ---
    'Chart {symbol}' => 'График {symbol}',
    'Coin' => 'Монета',
    'Comparison' => 'Сравнение',
    'back to RSI heatmap' => 'назад к RSI-карте',
    '% change since the start of the visible window' => '% изменения от начала видимого окна',
    'window start' => 'начало окна',
    'Compare with…' => 'Сравнить с…',
    'Add to comparison' => 'Добавить к сравнению',
    'Remove {symbol}' => 'Убрать {symbol}',
    'Zoom:' => 'Масштаб:',
    'support' => 'поддержка',
    'resistance' => 'сопротивление',
    'price {time} UTC' => 'цена {time} UTC',
    'Today\'s candle is priced as of {time} UTC' => 'Цена сегодняшней свечи на {time} UTC',
];
