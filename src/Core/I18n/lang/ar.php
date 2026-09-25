<?php

declare(strict_types=1);

/**
 * نصوص صفحة التقرير — العربية.
 *
 * ترجمة آلية لم تخضع لمراجعة ناطق أصلي (unreviewed machine translation).
 * المصطلحات موحَّدة مع tools/i18n/glossary/ar.json.
 *
 * القواعد:
 *   1. مجموعة المفاتيح يجب أن تطابق zh_CN.php مفتاحًا بمفتاح وبنفس الترتيب.
 *      لا تُضِف ولا تحذف ولا تُعِد تسمية أي مفتاح؛ لإضافة نص جديد عدّل
 *      zh_CN.php أولًا ثم أكمل 12 قائمة (tests/Unit/Core/I18nTest.php يحمرّ).
 *   2. الترميز الوحيد المسموح في القيم هو `<br>`، وبقية المحارف تُهرَّب عند
 *      الإخراج. مواضع قطع السطر تختلف بين اللغات فلا تنسخ مواضع الصينية.
 *   3. لا تُغيِّر المعرّفات (XHProf, CPU, IP, bytes, Mb, IWall%, IUser% …)
 *      إلى معرّف آخر.
 *   4. لا تلمس عناصر _meta الثلاثة: lang تغذّي <html lang>، و dir=rtl
 *      هي ما يقلب الصفحة كلها.
 *
 * عائلات User/Sys/Samples (ut · st · samples) تبقى بالإنجليزية كما في
 * zh_CN و en: نصوص XhprofDisplay::$descriptions الأصلية من مشروع xhprof.
 */
return [
    '_meta' => [
        'lang' => 'ar',
        'name' => 'العربية',
        'dir' => 'rtl',
    ],
    'report.title' => 'XHProf تقرير تحليل الأداء',
    'report.noData' => 'لم تعد بيانات القياس لهذا التشغيل موجودة (ربما انتهت صلاحيتها أو حُذفت)، لذا لا يمكن إنشاء التقرير.',
    'nav.brand' => 'XHProf تحليل الأداء',
    'nav.home' => 'الرئيسية',
    'nav.runs' => 'تقرير التشغيل',
    'nav.symbol' => 'تفاصيل الدالة',
    'nav.language' => 'اللغة',
    'search.placeholder' => 'ابحث عن اسم الدالة...',
    'search.button' => 'بحث',
    'run.col.method' => 'طريقة الطلب',
    'run.col.time' => 'وقت الطلب',
    'run.col.ip' => 'IP المصدر',
    'run.col.totalCalls' => 'إجمالي عدد استدعاءات الدوال',
    'runs.title' => 'سجل الطلبات',
    'runs.col.method' => 'الطريقة',
    'runs.col.url' => 'عنوان الطلب',
    'runs.col.time' => 'وقت الطلب',
    'runs.col.wt' => 'الزمن(s)',
    'runs.col.mu' => 'الذاكرة(Mb)',
    'runs.col.ip' => 'IP',
    'flat.title.top' => 'عرض أعلى %s دالة: مرتَّبة حسب %s',
    'flat.title.sorted' => 'مرتَّبة حسب %s',
    'flat.title.diff' => 'أعلى 100 تراجع/تحسّن: مرتَّبة حسب فرق %s',
    'flat.title.diffAll' => 'تقرير الفروق الكامل: مرتَّب حسب القيمة المطلقة للتراجع/التحسّن في %s',
    'flat.displayAll' => 'عرض الكل',
    'symbol.notFound' => 'لم يُعثر على الدالة %s في بيانات تشغيل XHProf.',
    'pc.current' => 'الدالة الحالية',
    'pc.exclusive' => 'المقاييس الذاتية%s للدالة الحالية',
    'pc.report' => 'تقرير الأم/الابنة للدالة %s',
    'pc.child' => 'دالة ابنة',
    'pc.childMany' => 'دوال ابنة',
    'pc.parent' => 'دالة أم',
    'pc.parentMany' => 'دوال أم',
    'diff.run' => 'التشغيل #%s: %s',
    'agg.title' => 'تقرير مجمَّع عن %s من عمليات التشغيل: %s %s',
    'agg.titleOne' => 'تقرير مجمَّع عن عملية تشغيل واحدة: %s %s',
    'agg.ratio' => 'بنسبة (%s)',
    'common.diff' => 'الفرق',
    'diff.summary' => 'ملخّص الفروق الإجمالي',
    'diff.runShort' => 'التشغيل #%s',
    'diff.diffPct' => 'الفرق%',
    'diff.callCount' => 'عدد استدعاءات الدوال',
    'pc.reportDiff' => 'تقرير الأم/الابنة (%s) للدالة %s',
    'unit.microsecs' => 'ميكروثانية',
    'unit.bytes' => 'بايت',
    'unit.samples' => 'عيّنة',
    'agg.invalidInput' => 'مدخل غير صالح..',
    'common.run' => 'التشغيل',
    'diff.invert' => 'عكس تقرير %s',
    'diff.viewRun' => 'عرض التشغيل #%s',
    'common.regression' => 'التراجع',
    'common.improvement' => 'التحسّن',
    'pc.summary' => 'ملخّص %s للدالة %s',
    'runs.dt.processing' => 'جارٍ المعالجة...',
    'runs.dt.loadingRecords' => 'جارٍ التحميل...',
    'runs.dt.lengthMenu' => 'عرض _MENU_ مدخلات',
    'runs.dt.zeroRecords' => 'لم يُعثر على سجلات مطابقة',
    'runs.dt.emptyTable' => 'لا توجد بيانات في الجدول',
    'runs.dt.info' => 'عرض _START_ إلى _END_ من _TOTAL_ مدخلات',
    'runs.dt.infoEmpty' => 'عرض 0 إلى 0 من 0 مدخلات',
    'runs.dt.infoFiltered' => '(مُرشَّحة من إجمالي _MAX_ مدخل)',
    'runs.dt.search' => 'بحث:',
    'runs.dt.first' => 'الأولى',
    'runs.dt.previous' => 'السابقة',
    'runs.dt.next' => 'التالية',
    'runs.dt.last' => 'الأخيرة',
    'runs.dt.sortAsc' => ': تفعيل الترتيب التصاعدي للعمود',
    'runs.dt.sortDesc' => ': تفعيل الترتيب التنازلي للعمود',
    'col.fn' => 'اسم الدالة',
    'col.ct' => 'عدد<br>الاستدعاءات',
    'col.Calls%' => 'نسبة<br>عدد<br>الاستدعاءات',
    'col.wt' => 'الزمن الشامل<br>(ميكروثانية)',
    'col.IWall%' => 'نسبة<br>الزمن الشامل',
    'col.excl_wt' => 'الزمن الذاتي<br>(ميكروثانية)',
    'col.EWall%' => 'نسبة<br>الزمن الذاتي',
    'col.ut' => 'Incl. User<br>(microsecs)',
    'col.IUser%' => 'IUser%',
    'col.excl_ut' => 'Excl. User<br>(microsec)',
    'col.EUser%' => 'EUser%',
    'col.st' => 'Incl. Sys <br>(microsec)',
    'col.ISys%' => 'ISys%',
    'col.excl_st' => 'Excl. Sys <br>(microsec)',
    'col.ESys%' => 'ESys%',
    'col.cpu' => 'زمن CPU الشامل<br>(ميكروثانية)',
    'col.ICpu%' => 'نسبة<br>زمن CPU الشامل',
    'col.excl_cpu' => 'زمن CPU الذاتي<br>(ميكروثانية)',
    'col.ECpu%' => 'نسبة<br>زمن CPU الذاتي',
    'col.mu' => 'الذاكرة المستخدمة<br>الشاملة<br>(bytes)',
    'col.IMUse%' => 'نسبة<br>الذاكرة المستخدمة<br>الشاملة',
    'col.excl_mu' => 'الذاكرة المستخدمة<br>الذاتية<br>(bytes)',
    'col.EMUse%' => 'نسبة<br>الذاكرة المستخدمة<br>الذاتية',
    'col.pmu' => 'ذروة الذاكرة<br>الشاملة<br>(bytes)',
    'col.IPMUse%' => 'نسبة<br>ذروة الذاكرة<br>الشاملة',
    'col.excl_pmu' => 'ذروة الذاكرة<br>الذاتية<br>(bytes)',
    'col.EPMUse%' => 'نسبة<br>ذروة الذاكرة<br>الذاتية',
    'col.samples' => 'Incl. Samples',
    'col.ISamples%' => 'ISamples%',
    'col.excl_samples' => 'Excl. Samples',
    'col.ESamples%' => 'ESamples%',
];
