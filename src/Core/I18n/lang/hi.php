<?php

declare(strict_types=1);

/**
 * Report-page strings — Hindi (हिन्दी).
 *
 * 已翻译：2026-09-25 由 Claude (AI) 机器翻译，**未经母语者审校**；措辞与术语可随时
 * 被人工修正。值仍为空的键在运行时逐键回落到中文源（src/Core/I18n/lang/zh_CN.php），
 * 所以报告页不会因为这里为空而白屏或 500 —— 翻一条生效一条。
 *
 * 例外：User/Sys/Samples 六条列头按源语言本来的取舍保持英文原样。它们对应 XHProf
 * 自己的 stat 名，且同组的 IUser%/EUser%/ISys%/ESys%/ISamples%/ESamples% 是不可改写的
 * ASCII 标识符，半译会让读者对不上（zh_CN.php 同样保留这六条英文）。
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
        'lang' => 'hi',
        'name' => 'हिन्दी',
        'dir' => 'ltr',
    ],
    'report.title' => 'XHProf प्रदर्शन रिपोर्ट',
    'nav.brand' => 'XHProf प्रदर्शन विश्लेषण',
    'nav.home' => 'होम',
    'nav.runs' => 'रन रिपोर्ट',
    'nav.symbol' => 'मेथड विवरण',
    'search.placeholder' => 'फ़ंक्शन/मेथड नाम खोजें...',
    'search.button' => 'खोजें',
    'run.col.method' => 'रिक्वेस्ट मेथड',
    'run.col.time' => 'रिक्वेस्ट समय',
    'run.col.ip' => 'स्रोत IP',
    'run.col.totalCalls' => 'फ़ंक्शन/मेथड कॉल की कुल संख्या',
    'runs.title' => 'रिक्वेस्ट लॉग',
    'runs.col.method' => 'मेथड',
    'runs.col.url' => 'रिक्वेस्ट URL',
    'runs.col.time' => 'रिक्वेस्ट समय',
    'runs.col.wt' => 'समय (s)',
    'runs.col.mu' => 'मेमोरी (Mb)',
    'runs.col.ip' => 'IP',
    'col.fn' => 'फ़ंक्शन/मेथड',
    'col.ct' => 'कॉल<br>संख्या',
    'col.Calls%' => 'कॉल<br>संख्या<br>%',
    'col.wt' => 'कुल समय<br>(माइक्रोसेक)',
    'col.IWall%' => 'कुल समय<br>%',
    'col.excl_wt' => 'स्वयं समय<br>(माइक्रोसेक)',
    'col.EWall%' => 'स्वयं समय<br>%',
    'col.ut' => 'Incl. User<br>(microsecs)',
    'col.IUser%' => 'IUser%',
    'col.excl_ut' => 'Excl. User<br>(microsec)',
    'col.EUser%' => 'EUser%',
    'col.st' => 'Incl. Sys <br>(microsec)',
    'col.ISys%' => 'ISys%',
    'col.excl_st' => 'Excl. Sys <br>(microsec)',
    'col.ESys%' => 'ESys%',
    'col.cpu' => 'कुल<br>CPU समय<br>(माइक्रोसेक)',
    'col.ICpu%' => 'कुल<br>CPU समय<br>%',
    'col.excl_cpu' => 'स्वयं<br>CPU समय<br>(माइक्रोसेक)',
    'col.ECpu%' => 'स्वयं<br>CPU समय<br>%',
    'col.mu' => 'कुल<br>मेमोरी उपयोग<br>(bytes)',
    'col.IMUse%' => 'कुल<br>मेमोरी उपयोग<br>%',
    'col.excl_mu' => 'स्वयं<br>मेमोरी उपयोग<br>(bytes)',
    'col.EMUse%' => 'स्वयं<br>मेमोरी उपयोग<br>%',
    'col.pmu' => 'कुल<br>पीक मेमोरी<br>(bytes)',
    'col.IPMUse%' => 'कुल<br>पीक मेमोरी<br>%',
    'col.excl_pmu' => 'स्वयं<br>पीक मेमोरी<br>(bytes)',
    'col.EPMUse%' => 'स्वयं<br>पीक मेमोरी<br>%',
    'col.samples' => 'Incl. Samples',
    'col.ISamples%' => 'ISamples%',
    'col.excl_samples' => 'Excl. Samples',
    'col.ESamples%' => 'ESamples%',
];
