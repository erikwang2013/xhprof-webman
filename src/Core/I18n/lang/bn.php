<?php

declare(strict_types=1);

/**
 * রিপোর্ট পেজের টেক্সট — বাংলা (Bengali).
 *
 * কী-সেট src/Core/I18n/lang/zh_CN.php -এর সঙ্গে হুবহু মিলতে হবে: যোগ, বাদ বা
 * নাম পরিবর্তন করা যাবে না। নতুন টেক্সট লাগলে আগে zh_CN.php -এ কী যোগ করুন,
 * তারপর সব ক্যাটালগে ভরুন (tests/Unit/Core/I18nTest.php লাল হবে)।
 *
 * মান হলো কাঁচা টেক্সট: কেবল `<br>` একটি মার্কআপ (সরু কলাম-হেডার হাতে ভাঙার
 * জন্য), বাকি সব আউটপুটে I18n::html() রূপান্তর করে। লাইন ভাঙার জায়গা নিজে
 * বেছে নিন — চীনা সংস্করণের ভাঙার জায়গা কপি করবেন না।
 *
 * `IUser%`, `ISys%`, `ISamples%` প্রভৃতি বিশুদ্ধ ASCII কলাম-নাম ইংরেজিতেই
 * রাখা হয়েছে। `মোট` = Incl., `নিজ` = Excl. — চীনা সংস্করণের 总 / 自身 -এর অনুরূপ।
 */
return [
    '_meta' => [
        'lang' => 'bn',
        'name' => 'বাংলা',
        'dir' => 'ltr',
    ],
    'report.title' => 'XHProf পারফরম্যান্স বিশ্লেষণ রিপোর্ট',
    'nav.brand' => 'XHProf পারফরম্যান্স বিশ্লেষণ',
    'nav.home' => 'হোম',
    'nav.runs' => 'রান রিপোর্ট',
    'nav.symbol' => 'মেথডের বিবরণ',
    'search.placeholder' => 'ফাংশন/মেথড খুঁজুন...',
    'search.button' => 'খুঁজুন',
    'run.col.method' => 'রিকোয়েস্ট মেথড',
    'run.col.time' => 'রিকোয়েস্টের সময়',
    'run.col.ip' => 'সোর্স IP',
    'run.col.totalCalls' => 'ফাংশন/মেথড কলের মোট সংখ্যা',
    'runs.title' => 'রিকোয়েস্ট লগ',
    'runs.col.method' => 'মেথড',
    'runs.col.url' => 'রিকোয়েস্ট URL',
    'runs.col.time' => 'রিকোয়েস্টের সময়',
    'runs.col.wt' => 'সময় (s)',
    'runs.col.mu' => 'মেমরি (Mb)',
    'runs.col.ip' => 'IP',
    'col.fn' => 'ফাংশন/মেথড',
    'col.ct' => 'কল<br>সংখ্যা',
    'col.Calls%' => 'কল<br>সংখ্যা<br>%',
    'col.wt' => 'মোট ওয়াল<br>(মাইক্রোসেক)',
    'col.IWall%' => 'মোট ওয়াল<br>%',
    'col.excl_wt' => 'নিজ ওয়াল<br>(মাইক্রোসেক)',
    'col.EWall%' => 'নিজ ওয়াল<br>%',
    'col.ut' => 'মোট User<br>(মাইক্রোসেক)',
    'col.IUser%' => 'IUser%',
    'col.excl_ut' => 'নিজ User<br>(মাইক্রোসেক)',
    'col.EUser%' => 'EUser%',
    'col.st' => 'মোট Sys<br>(মাইক্রোসেক)',
    'col.ISys%' => 'ISys%',
    'col.excl_st' => 'নিজ Sys<br>(মাইক্রোসেক)',
    'col.ESys%' => 'ESys%',
    'col.cpu' => 'মোট<br>CPU সময়<br>(মাইক্রোসেক)',
    'col.ICpu%' => 'মোট<br>CPU সময়<br>%',
    'col.excl_cpu' => 'নিজ<br>CPU সময়<br>(মাইক্রোসেক)',
    'col.ECpu%' => 'নিজ<br>CPU সময়<br>%',
    'col.mu' => 'মোট<br>মেমরি ব্যবহার<br>(বাইট)',
    'col.IMUse%' => 'মোট<br>মেমরি ব্যবহার<br>%',
    'col.excl_mu' => 'নিজ<br>মেমরি ব্যবহার<br>(বাইট)',
    'col.EMUse%' => 'নিজ<br>মেমরি ব্যবহার<br>%',
    'col.pmu' => 'মোট<br>সর্বোচ্চ মেমরি<br>(বাইট)',
    'col.IPMUse%' => 'মোট<br>সর্বোচ্চ মেমরি<br>%',
    'col.excl_pmu' => 'নিজ<br>সর্বোচ্চ মেমরি<br>(বাইট)',
    'col.EPMUse%' => 'নিজ<br>সর্বোচ্চ মেমরি<br>%',
    'col.samples' => 'মোট স্যাম্পল',
    'col.ISamples%' => 'ISamples%',
    'col.excl_samples' => 'নিজ স্যাম্পল',
    'col.ESamples%' => 'ESamples%',
];
