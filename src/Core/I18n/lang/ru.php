<?php

declare(strict_types=1);

/**
 * Report-page strings — Russian (Русский).
 *
 * 已填（2026-09-25）：由 i18n agent 从 en.php 机翻，**未经母语者审校**；
 * 术语目录见 tools/i18n/glossary/ru.json（报告页与文档共用同一套口径）：
 * report=отчёт、page=страница、method=метод、request=запрос、profiling=профилирование。
 * 列头的取舍：Incl./Excl. → Вкл./Искл.；microsec → (мкс)、bytes → (байт)；
 * IUser%/ISys%/ISamples% 这类列标识原样保留（en 与 zh 亦如此，改了就不是同一个指标名）。
 * `<br>` 的断行位置按俄语词长自行决定，**未**照抄中文的两字一行。
 *
 * 规则：
 *   1. 键集与 zh_CN.php 必须逐键相同：不可增、不可删、不可改名。加了新文案
 *      先改 zh_CN.php，再回来补齐（tests/Unit/Core/I18nTest.php 会红）。
 *   2. 值里**只有** `<br>` 是标记（窄列头手动折行），其余字符输出时统一转义。
 *      想在哪里断行就放在哪里，不要照抄中文的断行位置：中文两字一行，字母语言未必。
 *   3. 变量/标识符（XHProf、Redis、CPU、IP、bytes、microsec、IWall% 这类列标识）
 *      按各语言的惯用写法取舍，但不要改成别的标识符。
 *   4. _meta 的三项别动：lang 决定 <html lang>，dir=rtl 才会让整页从右往左排版。
 */
return [
    '_meta' => [
        'lang' => 'ru',
        'name' => 'Русский',
        'dir' => 'ltr',
    ],
    'report.title' => 'Отчёт о производительности XHProf',
    'report.noData' => 'Данные профилирования этого запуска больше не существуют (возможно, истекли или были удалены), поэтому отчёт построить нельзя.',
    'nav.brand' => 'Анализ производительности XHProf',
    'nav.home' => 'Главная',
    'nav.runs' => 'Отчёт о запусках',
    'nav.symbol' => 'Подробности метода',
    'nav.language' => 'Язык',
    'search.placeholder' => 'Найти функцию/метод...',
    'search.button' => 'Поиск',
    'run.col.method' => 'Метод запроса',
    'run.col.time' => 'Время запроса',
    'run.col.ip' => 'IP источника',
    'run.col.totalCalls' => 'Всего вызовов функций/методов',
    'runs.title' => 'Журнал запросов',
    'runs.col.method' => 'Метод',
    'runs.col.url' => 'URL запроса',
    'runs.col.time' => 'Время запроса',
    'runs.col.wt' => 'Время (с)',
    'runs.col.mu' => 'Память (Мб)',
    'runs.col.ip' => 'IP',
    'flat.title.top' => 'Показаны первые %s функций: сортировка по %s',
    'flat.title.sorted' => 'Сортировка по %s',
    'flat.title.diff' => 'Top 100 ухудшений/улучшений: сортировка по разнице в «%s»',
    'flat.title.diffAll' => 'Полный отчёт о различиях: сортировка по абсолютной величине ухудшения/улучшения в «%s»',
    'flat.displayAll' => 'показать все',
    'symbol.notFound' => 'В данных запуска XHProf нет функции/метода %s.',
    'pc.current' => 'Текущая функция',
    'pc.exclusive' => 'Собственные метрики%s текущей функции',
    'pc.report' => 'Отчёт по родительским/дочерним функциям для %s',
    'pc.child' => 'Дочерняя функция',
    'pc.childMany' => 'Дочерние функции',
    'pc.parent' => 'Родительская функция',
    'pc.parentMany' => 'Родительские функции',
    'diff.run' => 'Запуск #%s: %s',
    'agg.title' => 'Сводный отчёт по запускам (%s): %s %s',
    'agg.titleOne' => 'Сводный отчёт по запускам (1): %s %s',
    'agg.ratio' => 'в пропорции (%s)',
    'common.diff' => 'Разница',
    'diff.summary' => 'Общая сводка различий',
    'diff.runShort' => 'Запуск #%s',
    'diff.diffPct' => 'Разница, %',
    'diff.callCount' => 'Число вызовов функций',
    'pc.reportDiff' => 'Отчёт по родительским/дочерним функциям (%s) для %s',
    'unit.microsecs' => 'мкс',
    'unit.bytes' => 'байт',
    'unit.samples' => 'выборок',
    'agg.invalidInput' => 'Недопустимый ввод..',
    'common.run' => 'Запуск',
    'diff.invert' => 'Инвертировать отчёт «%s»',
    'diff.viewRun' => 'Просмотреть запуск #%s',
    'common.regression' => 'Ухудшение',
    'common.improvement' => 'Улучшение',
    'pc.summary' => '%s: сводка по функции %s',
    'runs.dt.processing' => 'Обработка...',
    'runs.dt.loadingRecords' => 'Загрузка...',
    'runs.dt.lengthMenu' => 'Показать _MENU_ записей',
    'runs.dt.zeroRecords' => 'Ничего не найдено',
    'runs.dt.emptyTable' => 'В таблице нет данных',
    'runs.dt.info' => 'Показано с _START_ по _END_ из _TOTAL_ записей',
    'runs.dt.infoEmpty' => 'Показано с 0 по 0 из 0 записей',
    'runs.dt.infoFiltered' => '(отфильтровано из _MAX_ записей)',
    'runs.dt.search' => 'Поиск:',
    'runs.dt.first' => 'Первая',
    'runs.dt.previous' => 'Предыдущая',
    'runs.dt.next' => 'Следующая',
    'runs.dt.last' => 'Последняя',
    'runs.dt.sortAsc' => ': сортировка столбца по возрастанию',
    'runs.dt.sortDesc' => ': сортировка столбца по убыванию',
    'col.fn' => 'Функция/метод',
    'col.ct' => 'Вызовы',
    'col.Calls%' => 'Вызовы<br>%',
    'col.wt' => 'Вкл.<br>время<br>(мкс)',
    'col.IWall%' => 'Вкл.<br>время, %',
    'col.excl_wt' => 'Искл.<br>время<br>(мкс)',
    'col.EWall%' => 'Искл.<br>время, %',
    'col.ut' => 'Вкл.<br>польз. время<br>(мкс)',
    'col.IUser%' => 'IUser%',
    'col.excl_ut' => 'Искл.<br>польз. время<br>(мкс)',
    'col.EUser%' => 'EUser%',
    'col.st' => 'Вкл.<br>сист. время<br>(мкс)',
    'col.ISys%' => 'ISys%',
    'col.excl_st' => 'Искл.<br>сист. время<br>(мкс)',
    'col.ESys%' => 'ESys%',
    'col.cpu' => 'Вкл.<br>CPU-время<br>(мкс)',
    'col.ICpu%' => 'Вкл.<br>CPU-время, %',
    'col.excl_cpu' => 'Искл.<br>CPU-время<br>(мкс)',
    'col.ECpu%' => 'Искл.<br>CPU-время, %',
    'col.mu' => 'Вкл.<br>память<br>(байт)',
    'col.IMUse%' => 'Вкл.<br>память, %',
    'col.excl_mu' => 'Искл.<br>память<br>(байт)',
    'col.EMUse%' => 'Искл.<br>память, %',
    'col.pmu' => 'Вкл.<br>пик памяти<br>(байт)',
    'col.IPMUse%' => 'Вкл.<br>пик памяти, %',
    'col.excl_pmu' => 'Искл.<br>пик памяти<br>(байт)',
    'col.EPMUse%' => 'Искл.<br>пик памяти, %',
    'col.samples' => 'Вкл. выборки',
    'col.ISamples%' => 'ISamples%',
    'col.excl_samples' => 'Искл. выборки',
    'col.ESamples%' => 'ESamples%',
];
