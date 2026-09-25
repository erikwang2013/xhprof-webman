<?php

declare(strict_types=1);

/**
 * Report-page strings — German (Deutsch).
 *
 * 已填（2026-09-25）：由 i18n agent 从 en.php 机翻，**未经母语者审校**（unreviewed
 * machine translation）。术语与德语文档译文（tools/i18n/glossary/de.json、
 * docs/i18n/de/）同一口径：report=Report、request=Request、profiling=Profiling、
 * method=Methode、page=Seite、run=Run、entry/entry class=Eintragsklasse。
 *
 * 列头上的取舍：Incl./Excl. → Inkl./Exkl.；microsec → (µs)、bytes → (Bytes)；
 * Wall / User / Sys / Samples 保留英文——它们紧邻 IWall% / IUser% / ISys% /
 * ISamples% 这些原样保留的列标识（en 与 zh 亦如此），改了就与同一指标的名字对不上。
 * `<br>` 的断行位置按德语词长自行决定（长复合词在连字符处断开），
 * **未**照抄中文的两字一行。
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
        'lang' => 'de',
        'name' => 'Deutsch',
        'dir' => 'ltr',
    ],
    'report.title' => 'XHProf Performance-Report',
    'report.noData' => 'Die Profildaten dieses Laufs existieren nicht mehr (möglicherweise abgelaufen oder gelöscht); der Bericht kann nicht erzeugt werden.',
    'nav.brand' => 'XHProf Performance-Analyse',
    'nav.home' => 'Startseite',
    'nav.runs' => 'Run-Report',
    'nav.symbol' => 'Methodendetails',
    'nav.language' => 'Sprache',
    'search.placeholder' => 'Funktion/Methode suchen...',
    'search.button' => 'Suchen',
    'run.col.method' => 'Request-Methode',
    'run.col.time' => 'Request-Zeit',
    'run.col.ip' => 'Quell-IP',
    'run.col.totalCalls' => 'Funktions-/Methodenaufrufe gesamt',
    'runs.title' => 'Request-Protokoll',
    'runs.col.method' => 'Methode',
    'runs.col.url' => 'Request-URL',
    'runs.col.time' => 'Request-Zeit',
    'runs.col.wt' => 'Zeit (s)',
    'runs.col.mu' => 'Speicher (Mb)',
    'runs.col.ip' => 'IP',
    'flat.title.top' => 'Top %s Funktionen werden angezeigt: Sortiert nach %s',
    'flat.title.sorted' => 'Sortiert nach %s',
    'flat.title.diff' => 'Top 100 Regressionen/Verbesserungen: Sortiert nach Diff in %s',
    'flat.title.diffAll' => 'Vollständiger Diff-Report: Sortiert nach Absolutwert der Regression/Verbesserung in %s',
    'flat.displayAll' => 'Alle anzeigen',
    'symbol.notFound' => 'Symbol %s wurde im XHProf-Run nicht gefunden.',
    'pc.current' => 'Aktuelle Funktion',
    'pc.exclusive' => 'Exklusive Metriken%s für die aktuelle Funktion',
    'pc.report' => 'Eltern-/Kind-Report für %s',
    'pc.child' => 'Kindfunktion',
    'pc.childMany' => 'Kindfunktionen',
    'pc.parent' => 'Elternfunktion',
    'pc.parentMany' => 'Elternfunktionen',
    'diff.run' => 'Run #%s: %s',
    'agg.title' => 'Aggregierter Report für %s Runs: %s %s',
    'agg.titleOne' => 'Aggregierter Report für 1 Run: %s %s',
    'agg.ratio' => 'im Verhältnis (%s)',
    'common.diff' => 'Diff',
    'diff.summary' => 'Diff-Übersicht',
    'diff.runShort' => 'Run #%s',
    'diff.diffPct' => 'Diff %',
    'diff.callCount' => 'Anzahl der Funktionsaufrufe',
    'pc.reportDiff' => 'Eltern-/Kind-Report (%s) für %s',
    'diag.title' => 'Diagnosis',
    'diag.whySlow' => 'Why it is slow',
    'diag.otherFindings' => 'Other findings',
    'diag.view' => 'view',
    'diag.empty' => 'No obvious bottleneck found (thresholds: exclusive time ≥ %s%%, call count ≥ %s, edge calls ≥ %s, callee exclusive time ≥ %s%%, peak memory ≥ %s%%)',
    'diag.r1.title' => '%s exclusive time %s, %s of this request',
    'diag.r1.detail' => 'Exclusive time excludes child calls — this is the function body alone',
    'diag.r2.title' => '%s called %s times',
    'diag.r2.detail' => 'A high call count — worth confirming it is expected',
    'diag.r3.title' => '%s → %s called %s times, %s in total',
    'diag.r3.detail' => 'This call edge accounts for a notable share of the time',
    'diag.r4.detail' => 'Recursion detected in %s(), maximum depth %d',
    'diag.r5.title' => '%s peak memory %s, %s of the total',
    'diag.r5.detail' => 'Peak memory is concentrated in one function — check its data structures first',
    'diag.r6.title' => '%s exclusive time %s exceeds its inclusive time %s by %s',
    'diag.r6.detail' => 'Exclusive time should never exceed inclusive time — the sampled data or the computation may be wrong',
    'unit.microsecs' => 'µs',
    'unit.bytes' => 'Bytes',
    'unit.samples' => 'Samples',
    'agg.invalidInput' => 'Ungültige Eingabe..',
    'common.run' => 'Run',
    'diff.invert' => '%s-Report invertieren',
    'diff.viewRun' => 'Run #%s ansehen',
    'common.regression' => 'Regression',
    'common.improvement' => 'Verbesserung',
    'pc.summary' => 'Zusammenfassung (%s) für %s',
    'runs.dt.processing' => 'Wird verarbeitet...',
    'runs.dt.loadingRecords' => 'Wird geladen...',
    'runs.dt.lengthMenu' => '_MENU_ Einträge anzeigen',
    'runs.dt.zeroRecords' => 'Keine passenden Einträge gefunden',
    'runs.dt.emptyTable' => 'Keine Daten in der Tabelle verfügbar',
    'runs.dt.info' => '_START_ bis _END_ von _TOTAL_ Einträgen werden angezeigt',
    'runs.dt.infoEmpty' => '0 bis 0 von 0 Einträgen werden angezeigt',
    'runs.dt.infoFiltered' => '(gefiltert von _MAX_ Einträgen)',
    'runs.dt.search' => 'Suchen:',
    'runs.dt.first' => 'Erste',
    'runs.dt.previous' => 'Zurück',
    'runs.dt.next' => 'Weiter',
    'runs.dt.last' => 'Letzte',
    'runs.dt.sortAsc' => ': aktivieren, um die Spalte aufsteigend zu sortieren',
    'runs.dt.sortDesc' => ': aktivieren, um die Spalte absteigend zu sortieren',
    'col.fn' => 'Funktion/Methode',
    'col.ct' => 'Aufrufe',
    'col.Calls%' => 'Aufrufe<br>%',
    'col.wt' => 'Inkl. Wall<br>(µs)',
    'col.IWall%' => 'Inkl. Wall<br>%',
    'col.excl_wt' => 'Exkl. Wall<br>(µs)',
    'col.EWall%' => 'Exkl. Wall<br>%',
    'col.ut' => 'Inkl. User<br>(µs)',
    'col.IUser%' => 'IUser%',
    'col.excl_ut' => 'Exkl. User<br>(µs)',
    'col.EUser%' => 'EUser%',
    'col.st' => 'Inkl. Sys<br>(µs)',
    'col.ISys%' => 'ISys%',
    'col.excl_st' => 'Exkl. Sys<br>(µs)',
    'col.ESys%' => 'ESys%',
    'col.cpu' => 'Inkl.<br>CPU-Zeit<br>(µs)',
    'col.ICpu%' => 'Inkl.<br>CPU-Zeit<br>%',
    'col.excl_cpu' => 'Exkl.<br>CPU-Zeit<br>(µs)',
    'col.ECpu%' => 'Exkl.<br>CPU-Zeit<br>%',
    'col.mu' => 'Inkl.<br>Speicher-<br>verbrauch<br>(Bytes)',
    'col.IMUse%' => 'Inkl.<br>Speicher-<br>verbrauch<br>%',
    'col.excl_mu' => 'Exkl.<br>Speicher-<br>verbrauch<br>(Bytes)',
    'col.EMUse%' => 'Exkl.<br>Speicher-<br>verbrauch<br>%',
    'col.pmu' => 'Inkl.<br>Speicher-<br>spitze<br>(Bytes)',
    'col.IPMUse%' => 'Inkl.<br>Speicher-<br>spitze<br>%',
    'col.excl_pmu' => 'Exkl.<br>Speicher-<br>spitze<br>(Bytes)',
    'col.EPMUse%' => 'Exkl.<br>Speicher-<br>spitze<br>%',
    'col.samples' => 'Inkl. Samples',
    'col.ISamples%' => 'ISamples%',
    'col.excl_samples' => 'Exkl. Samples',
    'col.ESamples%' => 'ESamples%',
];
