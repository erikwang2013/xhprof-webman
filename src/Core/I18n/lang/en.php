<?php

declare(strict_types=1);

/**
 * Report-page strings — English.
 *
 * Key set must match src/Core/I18n/lang/zh_CN.php key for key; the value of a key
 * may hold a literal `<br>` (narrow table headers wrap by hand), everything else
 * is escaped on output by I18n::html() / I18n::plain().
 *
 * Wording for the User/Sys/Samples columns is copied from the literals that were
 * already in XhprofDisplay::$descriptions, so an English user sees what they saw
 * before this layer existed.
 */
return [
    '_meta' => [
        'lang' => 'en',
        'name' => 'English',
        'dir' => 'ltr',
    ],
    'report.title' => 'XHProf Performance Report',
    'nav.brand' => 'XHProf Performance Analysis',
    'nav.home' => 'Home',
    'nav.runs' => 'Run Report',
    'nav.symbol' => 'Method Details',
    'search.placeholder' => 'Find function/method...',
    'search.button' => 'Search',
    'run.col.method' => 'Request Method',
    'run.col.time' => 'Request Time',
    'run.col.ip' => 'Source IP',
    'run.col.totalCalls' => 'Total Function/Method Calls',
    'runs.title' => 'Request Log',
    'runs.col.method' => 'Method',
    'runs.col.url' => 'Request URL',
    'runs.col.time' => 'Request Time',
    'runs.col.wt' => 'Time (s)',
    'runs.col.mu' => 'Memory (Mb)',
    'runs.col.ip' => 'IP',
    'col.fn' => 'Function/Method',
    'col.ct' => 'Calls',
    'col.Calls%' => 'Calls<br>%',
    'col.wt' => 'Incl. Wall<br>(microsec)',
    'col.IWall%' => 'Incl. Wall<br>%',
    'col.excl_wt' => 'Excl. Wall<br>(microsec)',
    'col.EWall%' => 'Excl. Wall<br>%',
    'col.ut' => 'Incl. User<br>(microsecs)',
    'col.IUser%' => 'IUser%',
    'col.excl_ut' => 'Excl. User<br>(microsec)',
    'col.EUser%' => 'EUser%',
    'col.st' => 'Incl. Sys <br>(microsec)',
    'col.ISys%' => 'ISys%',
    'col.excl_st' => 'Excl. Sys <br>(microsec)',
    'col.ESys%' => 'ESys%',
    'col.cpu' => 'Incl.<br>CPU Time<br>(microsec)',
    'col.ICpu%' => 'Incl.<br>CPU Time<br>%',
    'col.excl_cpu' => 'Excl.<br>CPU Time<br>(microsec)',
    'col.ECpu%' => 'Excl.<br>CPU Time<br>%',
    'col.mu' => 'Incl.<br>Memory Use<br>(bytes)',
    'col.IMUse%' => 'Incl.<br>Memory Use<br>%',
    'col.excl_mu' => 'Excl.<br>Memory Use<br>(bytes)',
    'col.EMUse%' => 'Excl.<br>Memory Use<br>%',
    'col.pmu' => 'Incl.<br>Peak Memory<br>(bytes)',
    'col.IPMUse%' => 'Incl.<br>Peak Memory<br>%',
    'col.excl_pmu' => 'Excl.<br>Peak Memory<br>(bytes)',
    'col.EPMUse%' => 'Excl.<br>Peak Memory<br>%',
    'col.samples' => 'Incl. Samples',
    'col.ISamples%' => 'ISamples%',
    'col.excl_samples' => 'Excl. Samples',
    'col.ESamples%' => 'ESamples%',
];
