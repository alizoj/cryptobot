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
    'Delta alignment' => 'Дельта-сигнал',
    'RSI heatmap' => 'RSI-карта',
    'Symbol chart' => 'График символа',
    'Login' => 'Войти',
    'Logout ({username})' => 'Выйти ({username})',

    // --- dashboard (/site/index) ---
    'Dashboard' => 'Панель',
    'Welcome back, {username}' => 'С возвращением, {username}',
    'Crypto market monitor — the delta screener, coin charts and the RSI heatmap. A new signal engine is in the works.'
        => 'Монитор крипторынка — дельта-скринер, графики монет и RSI-карта. Новый сигнальный движок в разработке.',
    'Open delta screener →' => 'Открыть дельта-скринер →',
    'Quick links' => 'Быстрые ссылки',
    'Live SMA15/SMA50 delta screener on 5m/30m/1h/2h — DELTA_ALIGNMENT_SHORT_V1.'
        => 'Живой скринер дельты SMA15/SMA50 на 5m/30m/1h/2h — DELTA_ALIGNMENT_SHORT_V1.',
    'Multi-timeframe Wilder RSI(14) across the whole coin universe.'
        => 'Мультитаймфреймовый Wilder RSI(14) по всему юниверсу монет.',
    'Interactive candlestick chart for any coin, with multi-coin compare.'
        => 'Интерактивный свечной график любой монеты со сравнением нескольких монет.',

    // --- symbol chart / comparison (/trading/chart) ---
    'Chart {symbol}' => 'График {symbol}',
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

    // --- DELTA_ALIGNMENT screener (/trading/delta) ---
    'Delta Alignment' => 'Дельта-сигнал',
    'Signal identifier' => 'Идентификатор сигнала',
    'SHORT bias' => 'Смещение в SHORT',
    'Historically observed bias over {n} alignment events' => 'Историческое смещение по {n} событиям выравнивания',
    'Live SMA15 vs SMA50 distance on 5m · 30m · 1h · 2h. Each SMA blends the live price with the last {fast} / {slow} closed candles, so the deltas move every second; the signal fires when all four sit inside (−2%, 0%).'
        => 'Живое расстояние SMA15 от SMA50 на 5m · 30m · 1h · 2h. В каждую SMA входит текущая цена плюс последние {fast} / {slow} закрытых свечей, поэтому дельты меняются каждую секунду; сигнал срабатывает, когда все четыре в интервале (−2%, 0%).',
    'Closed-candle data age · ring counts down to the next 5m candle' => 'Возраст свечных данных · кольцо — отсчёт до закрытия следующей 5m свечи',
    'Active signals' => 'Активные сигналы',
    'all four timeframes aligned' => 'все четыре таймфрейма в окне',
    'Near misses' => 'Почти готовы',
    '3 of 4 timeframes in window' => '3 из 4 таймфреймов в окне',
    'Coins tracked' => 'Монет в обзоре',
    'HashHedge universe on Bybit' => 'юниверс HashHedge на Bybit',
    'Historical bias' => 'Историческое смещение',
    'short / long over {n} events' => 'short / long на {n} событиях',
    'Search coin…' => 'Поиск монеты…',
    'Filter' => 'Фильтр',
    'All' => 'Все',
    'Active' => 'Активные',
    '≥3 TFs' => '≥3 ТФ',
    'Coin' => 'Монета',
    'Price' => 'Цена',
    'Alignment' => 'Выравнивание',
    'LIVE' => 'LIVE',
    'PAUSED' => 'ПАУЗА',
    'STALE' => 'НЕТ СВЯЗИ',
    'CONNECTING' => 'СОЕДИНЕНИЕ',
    'No live ticker — showing the last 5m close' => 'Нет живого тикера — показано закрытие последней 5m-свечи',
    'Inside the signal window (−2 … 0)' => 'Внутри сигнального окна (−2 … 0)',
    'No coins match the current filter.' => 'Нет монет, подходящих под фильтр.',
    'Candle data is {age} old — is the 5-minute board cron running? ({cmd})' => 'Свечные данные устарели на {age} — запущен ли 5-минутный крон? ({cmd})',
    '{age} ago' => '{age} назад',
    'The delta board has not been built yet.' => 'Дельта-борд ещё не построен.',
    'Run it once, then keep it on a 5-minute schedule (or start the queue loop):' => 'Запустите один раз и поставьте на 5-минутное расписание (или запустите цикл очереди):',
    'The signal reports a historically observed probability skew only — across {n} alignment events, {short}% resolved SHORT and {long}% LONG. It is not a direction guarantee.'
        => 'Сигнал отражает только историческое смещение вероятности: из {n} событий выравнивания {short}% разрешились в SHORT и {long}% — в LONG. Это не гарантия направления.',
];
