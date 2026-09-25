<?php

declare(strict_types=1);

/**
 * 报告页文案 —— 简体中文（源语言）。
 *
 * 键集是契约：src/Core/I18n/lang/ 下 12 份词表的键必须**逐键相同**
 * （tests/Unit/Core/I18nTest.php 比对，缺一个就红）。要给报告页加一句文案，
 * 先在这里加 key，再让 12 份词表补齐。
 *
 * 值是**原始文本**：允许内嵌 `<br>` 用于手动折行（列头很窄），其余字符在输出
 * 时统一转义（I18n::html()）。zh_CN 的 col.* 必须与 XhprofDisplay::$descriptions
 * 的字面量逐字相同，改了一边不改另一边测试会红。
 */
return [
    '_meta' => [
        'lang' => 'zh-CN',   // <html lang="…">
        'name' => '中文',    // 语言自称（语言切换器用的那种写法）
        'dir' => 'ltr',
    ],
    'report.title' => 'XHProf 性能分析报告',
    'nav.brand' => 'XHProf 性能分析',
    'nav.home' => '首页',
    'nav.runs' => '运行报告',
    'nav.symbol' => '方法详情',
    'search.placeholder' => '查找 函数/方法名...',
    'search.button' => '搜索',
    'run.col.method' => '请求方法',
    'run.col.time' => '请求时间',
    'run.col.ip' => '来源IP',
    'run.col.totalCalls' => '函数/方法调用总次数',
    'runs.title' => '请求记录',
    'runs.col.method' => '方法',
    'runs.col.url' => '请求地址',
    'runs.col.time' => '请求时间',
    'runs.col.wt' => '耗时(s)',
    'runs.col.mu' => '内存(Mb)',
    'runs.col.ip' => 'IP',
    'col.fn' => '函数/方法名',
    'col.ct' => '调用<br>次数',
    'col.Calls%' => '调用<br>次数<br>占比',
    'col.wt' => '总耗时<br>(微秒)',
    'col.IWall%' => '总耗时<br>占比',
    'col.excl_wt' => '自身耗时<br>(微秒)',
    'col.EWall%' => '自身耗时<br>占比',
    'col.ut' => 'Incl. User<br>(microsecs)',
    'col.IUser%' => 'IUser%',
    'col.excl_ut' => 'Excl. User<br>(microsec)',
    'col.EUser%' => 'EUser%',
    'col.st' => 'Incl. Sys <br>(microsec)',
    'col.ISys%' => 'ISys%',
    'col.excl_st' => 'Excl. Sys <br>(microsec)',
    'col.ESys%' => 'ESys%',
    'col.cpu' => '总<br>CPU时间<br>(微秒)',
    'col.ICpu%' => '总<br>CPU时间<br>占比',
    'col.excl_cpu' => '自身<br>CPU时间<br>(微秒)',
    'col.ECpu%' => '自身<br>CPU时间<br>占比',
    'col.mu' => '总<br>内存占用<br>(bytes)',
    'col.IMUse%' => '总<br>内存占用<br>占比',
    'col.excl_mu' => '自身<br>内存占用<br>(bytes)',
    'col.EMUse%' => '自身<br>内存占用<br>占比',
    'col.pmu' => '总<br>内存峰值<br>(bytes)',
    'col.IPMUse%' => '总<br>内存峰值<br>占比',
    'col.excl_pmu' => '自身<br>内存峰值<br>(bytes)',
    'col.EPMUse%' => '自身<br>内存峰值<br>占比',
    'col.samples' => 'Incl. Samples',
    'col.ISamples%' => 'ISamples%',
    'col.excl_samples' => 'Excl. Samples',
    'col.ESamples%' => 'ESamples%',
];
