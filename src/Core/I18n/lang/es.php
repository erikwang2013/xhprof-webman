<?php

declare(strict_types=1);

/**
 * Report-page strings — Spanish (Español).
 *
 * Key set must match src/Core/I18n/lang/zh_CN.php key for key; the value of a key
 * may hold a literal `<br>` (narrow table headers wrap by hand), everything else
 * is escaped on output by I18n::html() / I18n::plain().
 *
 * Terminology follows the Spanish documentation locale (tools/i18n/glossary/es.json)
 * where the two overlap: report → informe, request → petición, method → método,
 * profiling → perfilado, run (a saved profile) → run.
 *
 * Wall / User / Sys / Samples are kept in English on purpose: zh_CN.php keeps them
 * in English too, they echo the `IWall%` / `IUser%` / `ISys%` / `ISamples%` column
 * identifiers that sit next to them, and `Incl.` / `Excl.` are ordinary Spanish
 * abbreviations. Everything else in the headers is translated.
 */
return [
    '_meta' => [
        'lang' => 'es',
        'name' => 'Español',
        'dir' => 'ltr',
    ],
    'report.title' => 'Informe de rendimiento XHProf',
    'nav.brand' => 'Análisis de rendimiento XHProf',
    'nav.home' => 'Inicio',
    'nav.runs' => 'Informe de run',
    'nav.symbol' => 'Detalles del método',
    'search.placeholder' => 'Buscar función/método...',
    'search.button' => 'Buscar',
    'run.col.method' => 'Método de la petición',
    'run.col.time' => 'Hora de la petición',
    'run.col.ip' => 'IP de origen',
    'run.col.totalCalls' => 'Total de llamadas a funciones/métodos',
    'runs.title' => 'Registro de peticiones',
    'runs.col.method' => 'Método',
    'runs.col.url' => 'URL de la petición',
    'runs.col.time' => 'Hora de la petición',
    'runs.col.wt' => 'Tiempo (s)',
    'runs.col.mu' => 'Memoria (Mb)',
    'runs.col.ip' => 'IP',
    'col.fn' => 'Función/método',
    'col.ct' => 'Llamadas',
    'col.Calls%' => 'Llamadas<br>%',
    'col.wt' => 'Incl. Wall<br>(microseg)',
    'col.IWall%' => 'Incl. Wall<br>%',
    'col.excl_wt' => 'Excl. Wall<br>(microseg)',
    'col.EWall%' => 'Excl. Wall<br>%',
    'col.ut' => 'Incl. User<br>(microseg)',
    'col.IUser%' => 'IUser%',
    'col.excl_ut' => 'Excl. User<br>(microseg)',
    'col.EUser%' => 'EUser%',
    'col.st' => 'Incl. Sys<br>(microseg)',
    'col.ISys%' => 'ISys%',
    'col.excl_st' => 'Excl. Sys<br>(microseg)',
    'col.ESys%' => 'ESys%',
    'col.cpu' => 'Incl.<br>tiempo de CPU<br>(microseg)',
    'col.ICpu%' => 'Incl.<br>tiempo de CPU<br>%',
    'col.excl_cpu' => 'Excl.<br>tiempo de CPU<br>(microseg)',
    'col.ECpu%' => 'Excl.<br>tiempo de CPU<br>%',
    'col.mu' => 'Incl.<br>uso de memoria<br>(bytes)',
    'col.IMUse%' => 'Incl.<br>uso de memoria<br>%',
    'col.excl_mu' => 'Excl.<br>uso de memoria<br>(bytes)',
    'col.EMUse%' => 'Excl.<br>uso de memoria<br>%',
    'col.pmu' => 'Incl.<br>memoria máxima<br>(bytes)',
    'col.IPMUse%' => 'Incl.<br>memoria máxima<br>%',
    'col.excl_pmu' => 'Excl.<br>memoria máxima<br>(bytes)',
    'col.EPMUse%' => 'Excl.<br>memoria máxima<br>%',
    'col.samples' => 'Incl. Samples',
    'col.ISamples%' => 'ISamples%',
    'col.excl_samples' => 'Excl. Samples',
    'col.ESamples%' => 'ESamples%',
];
