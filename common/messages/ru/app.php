<?php

declare(strict_types=1);

/**
 * Russian translations for the `app` category (PhpMessageSource).
 *
 * Keys are the English source strings used in Yii::t('app', ...); any key
 * missing here falls back to its English source. Generated/maintained
 * alongside backend/views/trading/how-it-works.php and the layout header.
 */
return [
    // --- layout / navigation ---
    'Home' => 'Главная',
    'Trading' => 'Трейдинг',
    'Dashboard' => 'Дашборд',
    'RSI heatmap' => 'RSI-карта',
    'Daily scan' => 'Дневной скан',
    '4h scan' => '4h скан',
    'Symbol chart' => 'График символа',
    'Login' => 'Войти',
    'Logout ({username})' => 'Выйти ({username})',

    // --- page chrome ---
    'How it works' => 'Как это работает',
    'the signal algorithm' => 'алгоритм сигналов',
    'Contents' => 'Содержание',

    // --- section titles ---
    'Pipeline overview' => 'Общая структура конвейера',
    'Daily-candle features' => 'Признаки дневной свечи',
    'RSI indicator (Wilder)' => 'Индикатор RSI (Уайлдер)',
    'Volatility regime and adaptive RSI bands' => 'Режим волатильности и адаптивные полосы RSI',
    'Multi-timeframe RSI bias' => 'Мультитаймфреймовый bias RSI',
    'Confidence' => 'Уверенность',
    'The five primary structures' => 'Пять «первичных» структур',
    'Auxiliary detectors' => 'Вспомогательные детекторы',
    'Setup detector (hard rules)' => 'Детектор сетапов (жёсткие правила)',
    'From signal to position: risk and parameters' => 'От сигнала к позиции: риск и параметры',
    'Wait-for-the-turn veto (TrapGate)' => 'Вето-слой «дождись разворота» (TrapGate)',
    'DELTA_ALIGNMENT_SHORT_V1 screener' => 'Скринер DELTA_ALIGNMENT_SHORT_V1',
    'The full pipeline' => 'Сводный конвейер',

    // --- intro ---
    'The system scans the whole coin universe and produces trade signals as a composition of several independent layers: hard boolean setup rules, a continuous RSI-confluence confidence model, a "wait-for-the-turn" veto, and a separate multi-timeframe DELTA_ALIGNMENT_SHORT_V1 screener. Below is the entire pipeline, layer by layer.'
        => 'Система сканирует всю вселенную монет и порождает торговые сигналы как композицию нескольких независимых слоёв: жёсткие булевы правила-сетапы, непрерывную RSI-конфлюэнс-модель уверенности, вето «дождись разворота» и отдельный мультитаймфреймовый скринер DELTA_ALIGNMENT_SHORT_V1. Ниже — весь конвейер по слоям.',

    // --- §0 pipeline overview ---
    'A signal is born as a composition of three independent layers:'
        => 'Сигнал рождается как композиция трёх независимых слоёв:',
    'Setup detector' => 'Детектор сетапов',
    'Hard boolean rules over the daily candle features and macro context. Each satisfied rule emits a "fire" of one of the types L1…L5 (long) or S1…S6 (short). It is a binary trigger: the condition is either true or not.'
        => 'Жёсткие булевы правила над признаками дневной свечи и макро-контекстом. Каждое выполненное правило порождает «срабатывание» одного из типов L1…L5 (лонг) или S1…S6 (шорт). Это бинарный триггер: условие либо истинно, либо нет.',
    'Confluence grading' => 'Оценка конфлюэнции',
    'A continuous scoring model. For an already-fired setup it measures how much an independent multi-timeframe RSI model agrees with the trade direction. Returns a confidence in [0, 95] and a count of confirming structures.'
        => 'Непрерывная скоринговая модель. Для уже сработавшего сетапа считается, насколько независимая мультитаймфреймовая RSI-модель согласна с направлением сделки. Возвращает уверенность в [0, 95] и число подтверждающих структур.',
    'Wait-for-the-turn veto' => 'Вето-слой «дождись разворота»',
    'A post-filter. Rejects counter-trend setups when the reversal they bet on has not statistically begun. Off by default.'
        => 'Пост-фильтр. Отклоняет контр-трендовые сетапы, если разворот, на который они ставят, ещё статистически не начался. По умолчанию выключен.',
    'The final set of signals:' => 'Итоговое множество сигналов:',
    'Each f carries a score Score(f) = (confidence, primaries); position size is computed separately by the risk model (§9). SignalEngine orchestrates everything.'
        => 'Каждому f приписывается скоринг Score(f) = (confidence, primaries); размер позиции вычисляется отдельно по риск-модели (§9). Оркестрацию выполняет SignalEngine.',

    // --- §1 features ---
    'Each daily candle yields dimensionless features; wick quantities are normalised by the close so they are comparable across assets of different price. Candle: O open, H high, L low, C close, V volume. Index 0 is the last closed candle.'
        => 'Для каждой дневной свечи считаются безразмерные признаки; «фитильные» величины нормированы на цену закрытия, что делает их сравнимыми между активами разной цены. Свеча: O открытие, H максимум, L минимум, C закрытие, V объём. Индекс 0 — последняя завершённая свеча.',
    'Rolling statistics over a W = 20-day window: vol20 (realised volatility, also reused as the volatility-regime variable volStd in §3), vol_ratio (relative volume) and turnover20 (the liquidity gate).'
        => 'Скользящие статистики по окну W = 20 дней: vol20 (реализованная волатильность, она же переменная режима волатильности volStd в §3), vol_ratio (относительный объём) и turnover20 (порог ликвидности).',

    // --- §2 RSI ---
    'Classic RSI(14), Wilder smoothing. With Δ = C_i − C_{i-1}, g = max(Δ,0), l = max(−Δ,0), period p = 14:'
        => 'Классический RSI(14), сглаживание Уайлдера. При Δ = C_i − C_{i-1}, g = max(Δ,0), l = max(−Δ,0), период p = 14:',
    'RSI is computed on 1h, 4h, 1d (plus the full series, for divergences and slope). In the MTF context the 4h/1h RSI uses a simple average — a deliberate simplification for the intraday scanner.'
        => 'RSI считается на 1h, 4h, 1d (плюс вся серия — для дивергенций и наклона). В контексте MTF RSI 4h/1h считается по простому среднему — сознательное упрощение для внутридневного сканера.',

    // --- §3 volatility bands ---
    'Overbought/oversold thresholds depend on realised volatility volStd: the higher the volatility, the wider the bands.'
        => 'Пороги перекупленности/перепроданности зависят от реализованной волатильности volStd: чем выше волатильность, тем шире полосы.',
    'Regime' => 'Режим',
    'High vol.' => 'Высокая вол.',
    'Medium' => 'Средняя',
    'Low' => 'Низкая',
    'Default' => 'Дефолт',
    'unset / ≤ 0' => 'не задан / ≤ 0',
    'os/ob are the soft bands (oversold/overbought), xLow/xHigh the extreme levels.'
        => 'os/ob — мягкие полосы (перепродан/перекуплен), xLow/xHigh — экстремальные уровни.',

    // --- §4 MTF bias ---
    'The core of the scoring model: a continuous signed score bias ∈ [−1, +1] aggregating RSI across three timeframes with weights (higher TF = more weight).'
        => 'Ядро скоринговой модели: непрерывная знаковая оценка bias ∈ [−1, +1], агрегирующая RSI по трём таймфреймам с весами (старший ТФ важнее).',

    // --- §5 confidence ---
    'After the side is picked, a scalar confidence is built as a base plus bonuses/penalties, then clipped by a cap that depends on the number of confirmed structures.'
        => 'После выбора стороны строится скалярная уверенность как база плюс бонусы/штрафы, затем результат обрезается потолком, зависящим от числа подтверждённых структур.',
    'Cap by the number of matched primary structures k ∈ {0..5}:'
        => 'Потолок по числу совпавших первичных структур k ∈ {0..5}:',
    'Meaning: with no structural confirmation (k=0) confidence cannot exceed 30; the full 95 is reachable only with all five confirmations.'
        => 'Смысл: без структурного подтверждения (k=0) уверенность не превышает 30; полные 95 достижимы только при всех пяти подтверждениях.',

    // --- §6 primaries ---
    'Each returns match ∈ {0,1} and score ∈ [0,100]; only the count of match=true feeds the cap (§5).'
        => 'Каждая возвращает match ∈ {0,1} и score ∈ [0,100]; в потолок (§5) входит только число match=true.',
    'wave context: trend on the trade side + phase (pullback ⇒ 95, impulse ⇒ 60); trend against the side ⇒ no match.'
        => 'волновой контекст: тренд по стороне сделки + фаза (pullback ⇒ 95, impulse ⇒ 60); тренд против стороны ⇒ нет совпадения.',
    'rejection candle of the right direction on 1d/4h/1h (base scores 100/85/55).'
        => 'свеча отвержения нужного направления на 1d/4h/1h (базовые баллы 100/85/55).',
    'RSI alignment' => 'RSI-выравнивание',
    'S/R confluence' => 'S/R-конфлюэнция',
    'proximity to a level in ATR units: d_atr ≤ 0.5 ⇒ 100; ≤ 1.5 ⇒ 82; ≤ 3.0 ⇒ 55; a zone of ≥3 levels ⇒ +8.'
        => 'близость к уровню в единицах ATR: d_atr ≤ 0.5 ⇒ 100; ≤ 1.5 ⇒ 82; ≤ 3.0 ⇒ 55; зона из ≥3 уровней ⇒ +8.',
    'trendline touch (least-squares over pivots): d_atr ≤ 0.5 ⇒ 100; ≤ 1.5 ⇒ 80; ≤ 3.0 ⇒ 55; else 30.'
        => 'касание трендовой линии (МНК по пивотам): d_atr ≤ 0.5 ⇒ 100; ≤ 1.5 ⇒ 80; ≤ 3.0 ⇒ 55; иначе 30.',

    // --- §7 auxiliary detectors ---
    'RSI↔price divergence' => 'Дивергенция RSI↔цена',
    'price makes a new extreme, RSI does not (lookback 30, 3-bar frame).'
        => 'цена обновляет экстремум, а RSI — нет (lookback 30, рамка 3 бара).',
    'Pinbar' => 'Пинбар',
    'small body (body/R ≤ 0.35), long rejection wick (≥ 2·body), short opposite wick, sweeps the 10-bar extreme.'
        => 'малое тело (body/R ≤ 0.35), длинный фитиль отвержения (≥ 2·body), короткий противоположный фитиль, прокол 10-барного экстремума.',
    'Wave' => 'Волна',
    'over pivots (1.5% swing filter): higher highs / higher lows ⇒ up/down; impulse/pullback phase by distance and retrace.'
        => 'по пивотам (фильтр размаха 1.5%): higher highs / higher lows ⇒ up/down; фаза impulse/pullback по расстоянию и откату.',
    'Pre-move patterns' => 'Пред-движенческие паттерны',
    'Squeeze, Accumulation, Higher-lows, Parabolic, Distribution/Climax.'
        => 'Squeeze, Accumulation, Higher-lows, Parabolic, Distribution/Climax.',

    // --- §8 setups ---
    'Each rule is a conjunction of conditions over the features (§1) and macro context: fund (funding), fund7d, oiz (open-interest z-score), fng (fear & greed), btc_d (BTC dominance).'
        => 'Каждое правило — конъюнкция условий над признаками (§1) и макро-контекстом: fund (фандинг), fund7d, oiz (z-score открытого интереса), fng (индекс страха и жадности), btc_d (доминация BTC).',
    'Longs' => 'Лонги',
    'Setup' => 'Сетап',
    'Condition' => 'Условие',
    'Entry / Stop' => 'Вход / Стоп',
    'Short-squeeze' => 'Шорт-сквиз',
    'Capitulation' => 'Капитуляция',
    'Momentum' => 'Импульс',
    'Cluster bottom' => 'Кластерное дно',
    'extreme fear after a cluster dump' => 'экстремальный страх после кластерного слива',
    'Pullback dip-buy (MTF)' => 'Откупка отката (MTF)',
    'Shorts' => 'Шорты',
    'Crowded-long blowup' => 'Взрыв переполненного лонга',
    'Euphoria trap' => 'Эйфория-ловушка',
    'Volume exhaustion' => 'Истощение объёма',
    'Rejection' => 'Отвержение',
    '4h blow-off (MTF)' => 'Blow-off на 4h (MTF)',
    '⚠️ S3 is deliberately excluded from the veto (§10): it shorts a still-accelerating blow-off.'
        => '⚠️ S3 намеренно исключён из вето (§10): он шортит ещё ускоряющийся blow-off.',
    'Multi-timeframe context (MtfContext)' => 'Мультитаймфреймовый контекст (MtfContext)',

    // --- §9 risk & params ---
    'A fired, un-vetoed signal becomes a position via a fixed-fractional risk model with a beta adjustment:'
        => 'Сработавший и не отвергнутый сигнал превращается в позицию по риск-модели с фиксированной долей риска и поправкой на бету:',
    'Parameter' => 'Параметр',
    'Value' => 'Значение',
    'Description' => 'Описание',
    '0.5% risk per trade' => '0.5% риска на сделку',
    '≤20% notional per trade' => '≤20% номинала на сделку',
    '≤60% total open notional' => '≤60% суммарного открытого номинала',
    '≤10 concurrent trades' => '≤10 одновременных сделок',
    'forced exit at T+20' => 'принудительный выход T+20',
    'enable the veto layer (§10)' => 'включение вето-слоя (§10)',
    'liquidity gate ($5M)' => 'фильтр ликвидности ($5M)',

    // --- §10 TrapGate ---
    'Hard rules fire on the most extreme candle without reversal confirmation, so counter-trend setups (L2, L5, S4, S6) are vulnerable to catching a falling knife. The veto adds that confirmation as a post-filter — it does not change the set of setups, only blocks entry. Active only when requireTurnConfirmation = true; it uses the 4h/1h RSI slopes.'
        => 'Жёсткие правила срабатывают на самой экстремальной свече без подтверждения разворота, поэтому контр-трендовые сетапы (L2, L5, S4, S6) уязвимы к «ловле падающего ножа». Вето добавляет это подтверждение как пост-фильтр — оно не меняет множество сетапов, только блокирует вход. Активно только при requireTurnConfirmation = true; использует наклоны RSI 4h/1h.',
    'If both slopes are unavailable the layer opens (never blocks on missing data). S3 is excluded: the backtest showed reversal confirmation cuts exactly the best exhaustion fades (kept −0.05R vs rejected +2.58R).'
        => 'Если оба наклона недоступны — слой «открывается» (никогда не блокирует из-за нехватки данных). S3 исключён: бэктест показал, что подтверждение разворота отсекает именно лучшие фейды истощения (оставленные −0.05R против отвергнутых +2.58R).',

    // --- §11 DELTA_ALIGNMENT ---
    'A separate multi-timeframe indicator of SMA15 vs SMA50. A pure indicator — it produces no entry/stop/size, it flags a statistically significant tilt toward the short side. For each instrument it reads 5m, 30m, 1h, 2h candles.'
        => 'Отдельный мультитаймфреймовый индикатор положения SMA15 относительно SMA50. Чистый индикатор — не даёт входа/стопа/размера, а помечает статистически значимое смещение в сторону шорта. Для каждого инструмента берутся свечи 5m, 30m, 1h, 2h.',
    'Meaning: SMA15 sits just below SMA50 — a mild, freshly established bearish alignment on every timeframe at once. The historical sample (n = 769) gave a tilt of SHORT 61.9% / LONG 38.1%.'
        => 'Смысл: SMA15 чуть ниже SMA50 — мягкое, недавно сложившееся медвежье выравнивание сразу на всех таймфреймах. Историческая выборка (n = 769) дала смещение SHORT 61.9% / LONG 38.1%.',
    'If any TF is out of band or data is insufficient (fewer than 50 closes), the result is active:false (fail-safe). It is computed at the daily close on each TF last completed bar, so historical/replay scans are reproducible.'
        => 'Если хотя бы один ТФ вне полосы или данных недостаточно (менее 50 закрытий), результат active:false (отказоустойчиво). Считается на дневном закрытии по последней завершённой свече каждого ТФ, поэтому исторические/реплейные сканы воспроизводимы.',
    'Candle fetch' => 'Загрузка свечей',
    '5m/30m/2h were added to the fetcher intervals. Sub-hourly intervals backfill from a recent window (SHORT_BACKFILL_BARS = 6000 bars) rather than from listing — otherwise the KLINE page cap would keep history from reaching the present. One fetch run makes the signal live.'
        => '5m/30m/2h добавлены в интервалы фетчера. Внутридневные интервалы докачиваются недавним окном (SHORT_BACKFILL_BARS = 6000 баров), а не с момента листинга — иначе из-за лимита страниц KLINE история не дошла бы до настоящего. Один прогон фетчера делает сигнал «живым».',
    'Tail read' => 'Tail-чтение',
    'The screener reads only the tail of each series (≈55 bars: SMA50 + margin) via a binary search for the as-of bar — not the whole dense 5m series — and the read is as-of-aware, so historical scans stay correct.'
        => 'Скринер читает только хвост каждого ряда (≈55 баров: SMA50 + запас) с бинарным поиском бара на момент сканирования — а не весь плотный 5m-ряд — и чтение зависит от времени сканирования, поэтому исторические сканы остаются корректными.',
    'Active hits are collected into the scan result (delta_alignment) and the diagnostics (delta_alignment_active).'
        => 'Активные срабатывания собираются в результат скана (delta_alignment) и в диагностику (delta_alignment_active).',

    // --- §12 pipeline summary ---
    'Daily scan:' => 'Дневное сканирование:',
    'Iterate the universe; liquidity gate and blacklist.' => 'Перебор вселенной; фильтр ликвидности и чёрный список.',
    'Compute daily features (§1) and the current row.' => 'Расчёт дневных признаков (§1) и текущей строки.',
    'Gather macro context: funding, OI, FNG, BTC dominance.' => 'Сбор макро-контекста: фандинг, OI, FNG, доминация BTC.',
    'Gather the MTF context (§8.1) and the DELTA_ALIGNMENT screener (§11).' => 'Сбор MTF-контекста (§8.1) и скринера DELTA_ALIGNMENT (§11).',
    'SetupEvaluator.evaluate() ⇒ list of fires.' => 'SetupEvaluator.evaluate() ⇒ список срабатываний.',
    'For each fire: confluence scoring → TrapGate veto → cluster pause → beta-adjusted sizing → signal record.'
        => 'Для каждого срабатывания: скоринг конфлюэнции → вето TrapGate → кластер-пауза → сайзинг с поправкой на бету → запись сигнала.',
    'Sort by setup priority; return the list + diagnostics + active delta_alignment.'
        => 'Сортировка по приоритету сетапа; возврат списка + диагностики + активного delta_alignment.',
    'Intraday scan' => 'Внутридневное сканирование',
    'for L5/S6 at each 4h close: as-of MTF context at the 4h close, the same gates and sizing; veto rule 3 is skipped.'
        => 'для L5/S6 на закрытии каждой 4h-свечи: MTF-контекст на момент закрытия 4h, те же ворота и сайзинг; правило 3 вето пропускается.',
    'Bottom line: signal = (hard setup trigger) ∧ (RSI-confluence agreement under the structure cap) ∧ (passes the wait-for-the-turn veto) ∧ (passes the cluster filter). Position size comes from the fixed-fractional risk model with a beta adjustment. The DELTA_ALIGNMENT screener runs in parallel as an independent bias indicator.'
        => 'Итог: сигнал = (жёсткий триггер сетапа) ∧ (согласие RSI-конфлюэнс-модели под потолком структур) ∧ (прохождение вето «дождись разворота») ∧ (прохождение кластер-фильтра). Размер позиции — из риск-модели с фиксированной долей риска и поправкой на бету. Скринер DELTA_ALIGNMENT работает параллельно как независимый индикатор смещения.',
];
