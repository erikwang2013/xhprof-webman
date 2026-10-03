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
    'report.noData' => '这次运行的性能数据已不存在（可能已过期或被清理），无法生成报告。',
    'nav.brand' => 'XHProf 性能分析',
    'nav.home' => '首页',
    'nav.runs' => '运行报告',
    'nav.symbol' => '方法详情',
    'nav.language' => '界面语言',
    'search.placeholder' => '查找 函数/方法名...',
    'search.button' => '搜索',
    'run.col.method' => '请求方法',
    'run.col.time' => '请求时间',
    'run.col.ip' => '来源IP',
    'run.col.totalCalls' => '函数/方法调用总次数',
    'run.desc' => 'XHProf 运行（命名空间=%s）',
    'runs.title' => '请求记录',
    'runs.col.method' => '方法',
    'runs.col.url' => '请求地址',
    'runs.col.time' => '请求时间',
    'runs.col.wt' => '耗时(s)',
    'runs.col.mu' => '内存(Mb)',
    'runs.col.ip' => 'IP',
    'runs.compare' => '对比选中',
    'runs.selectAll' => '全选',
    'runs.selectRow' => '选择此行',
    'runs.compareHint' => '请勾选两条记录进行对比',
    'flat.title.top' => '显示前 %s 个函数：按 %s 排序',
    'flat.title.sorted' => '按 %s 排序',
    'flat.title.diff' => 'Top 100 回归/改善：按 %s 差异排序',
    'flat.title.diffAll' => '全部差异报告：按 %s 的回归/改善绝对值排序',
    'flat.displayAll' => '显示全部',
    'symbol.notFound' => 'XHProf 运行数据里没有函数 %s。',
    'pc.current' => '当前函数',
    'pc.exclusive' => '当前函数的自身指标%s',
    'pc.report' => '父/子报告：%s',
    'pc.child' => '子函数',
    'pc.childMany' => '子函数',
    'pc.parent' => '父函数',
    'pc.parentMany' => '父函数',
    'diff.run' => '运行 #%s：%s',
    'agg.title' => '%s 次运行的聚合报告：%s %s',
    'agg.titleOne' => '1 次运行的聚合报告：%s %s',
    'agg.ratio' => '权重比 (%s)',
    'common.diff' => '差异',
    'diff.summary' => '差异总览',
    'diff.runShort' => '运行 #%s',
    'diff.diffPct' => '差异%',
    'diff.callCount' => '函数调用次数',
    'pc.reportDiff' => '%s的父/子报告：%s',
    'diag.title' => '诊断结论',
    'diag.whySlow' => '为什么慢',
    'diag.otherFindings' => '其他发现',
    'diag.view' => '查看',
    'diag.empty' => '未发现明显瓶颈（阈值：自身耗时 ≥ %s%%、调用次数 ≥ %s、边调用 ≥ %s、被调方自身耗时 ≥ %s%%、峰值内存 ≥ %s%%）',
    'diag.r1.title' => '%s 自身耗时 %s，占本次请求 %s',
    'diag.r1.detail' => '自身耗时不含子调用，是纯函数体开销',
    'diag.r2.title' => '%s 被调用 %s 次',
    'diag.r2.detail' => '调用次数偏高，值得确认是否符合预期',
    'diag.r3.title' => '%s → %s 调用 %s 次，累计 %s',
    'diag.r3.detail' => '该调用关系占据了可观耗时',
    'diag.r4.title' => '检测到 %s() 递归，最大深度 %d',
    'diag.r4.detail' => '递归深度过大可能导致栈溢出或耗时呈指数增长',
    'diag.r5.title' => '%s 内存峰值 %s，占全局 %s',
    'diag.r5.detail' => '峰值内存集中在单个函数，可优先核查其数据结构',
    'diag.r6.title' => '%s 自身耗时 %s 大于其总耗时 %s，差 %s',
    'diag.r6.detail' => '自身耗时不应大于总耗时，采样数据或统计计算可能异常',
    'unit.microsecs' => '微秒',
    'unit.bytes' => '字节',
    'unit.samples' => '样本',
    'agg.invalidInput' => '输入无效。',
    'common.run' => '运行',
    'diff.invert' => '反转%s报告',
    'diff.viewRun' => '查看第 %s 次运行',
    'common.regression' => '回归',
    'common.improvement' => '改善',
    'pc.summary' => '%s 汇总：%s',
    'runs.dt.processing' => '处理中...',
    'runs.dt.loadingRecords' => '载入中...',
    'runs.dt.lengthMenu' => '显示 _MENU_ 项结果',
    'runs.dt.zeroRecords' => '没有匹配结果',
    'runs.dt.emptyTable' => '表中数据为空',
    'runs.dt.info' => '显示第 _START_ 至 _END_ 项结果，共 _TOTAL_ 项',
    'runs.dt.infoEmpty' => '显示第 0 至 0 项结果，共 0 项',
    'runs.dt.infoFiltered' => '(由 _MAX_ 项结果过滤)',
    'runs.dt.search' => '搜索：',
    'runs.dt.first' => '首页',
    'runs.dt.previous' => '上页',
    'runs.dt.next' => '下页',
    'runs.dt.last' => '末页',
    'runs.dt.sortAsc' => ': 以升序排列此列',
    'runs.dt.sortDesc' => ': 以降序排列此列',
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
    // ——— 2026-10 新增：diff 视图与符号详情页残留的英文（键序是契约，插在 col.ESamples% 之后）———
    // 'common.na' 是分母为 0 / 无调用次数时的占位符：中文技术界面里 N/A 就是公认写法，
    // 而百分比那条会把后面拼上 '%'（N/A%），译成「不适用」再拼 % 只会更别扭。
    'common.na' => 'N/A',
    // 符号详情页 diff 表里「每次调用均值」那一行的行首标签，%s = 指标列头（已折成单行）
    'pc.perCall' => '每次调用：%s',
    'sym.source' => '源码',
    // diff 模式的 31 个列头（键集/顺序与 XhprofDisplay::$diff_descriptions 一一对应，
    // en 词表与那张字面量表逐字相同，两处都有测试钉住）。
    // 中文按 col.* 的既有写法加「差异」：col 里保留英文的（Incl. User/Sys/Samples 族）
    // 这里同样保留英文，只把 Diff 换成「差异」。
    'diffcol.fn' => '函数/方法名',
    'diffcol.ct' => '调用<br>次数<br>差异',
    'diffcol.Calls%' => '调用<br>次数<br>差异占比',
    'diffcol.wt' => '总耗时<br>差异<br>(微秒)',
    'diffcol.IWall%' => '总耗时<br>差异占比',
    'diffcol.excl_wt' => '自身耗时<br>差异<br>(微秒)',
    'diffcol.EWall%' => '自身耗时<br>差异占比',
    'diffcol.ut' => 'Incl. User<br>差异<br>(microsec)',
    'diffcol.IUser%' => 'IUser<br>差异%',
    'diffcol.excl_ut' => 'Excl. User<br>差异<br>(microsec)',
    'diffcol.EUser%' => 'EUser<br>差异%',
    'diffcol.cpu' => '总<br>CPU时间<br>差异<br>(微秒)',
    'diffcol.ICpu%' => '总<br>CPU时间<br>差异占比',
    'diffcol.excl_cpu' => '自身<br>CPU时间<br>差异<br>(微秒)',
    'diffcol.ECpu%' => '自身<br>CPU时间<br>差异占比',
    'diffcol.st' => 'Incl. Sys<br>差异<br>(microsec)',
    'diffcol.ISys%' => 'ISys<br>差异%',
    'diffcol.excl_st' => 'Excl. Sys<br>差异<br>(microsec)',
    'diffcol.ESys%' => 'ESys<br>差异%',
    'diffcol.mu' => '总<br>内存占用<br>差异<br>(bytes)',
    'diffcol.IMUse%' => '总<br>内存占用<br>差异占比',
    'diffcol.excl_mu' => '自身<br>内存占用<br>差异<br>(bytes)',
    'diffcol.EMUse%' => '自身<br>内存占用<br>差异占比',
    'diffcol.pmu' => '总<br>内存峰值<br>差异<br>(bytes)',
    'diffcol.IPMUse%' => '总<br>内存峰值<br>差异占比',
    'diffcol.excl_pmu' => '自身<br>内存峰值<br>差异<br>(bytes)',
    'diffcol.EPMUse%' => '自身<br>内存峰值<br>差异占比',
    'diffcol.samples' => 'Incl. Samples<br>差异',
    'diffcol.ISamples%' => 'ISamples<br>差异%',
    'diffcol.excl_samples' => 'Excl. Samples<br>差异',
    'diffcol.ESamples%' => 'ESamples<br>差异%',
    // ——— 2026-10 新增：多 run 聚合入口 / 关键路径卡 / 搜索子串匹配 ———
    // 键序仍是契约：这四条排在 diffcol.ESamples% 之后，13 份词表同序。
    // runs.aggregate：「对比选中」按钮在选中 >2 条时换上的文案（静态 HTML 在 Utils 层，
    // JS 按选中数替换；经 window.xpI18n 注入）。
    'runs.aggregate' => '聚合选中',
    'path.title' => '关键路径',
    'path.empty' => '没有可展示的调用链（数据里没有从 main() 出发的调用边）。',
    // %s = 搜索串；「30」与 XhprofDisplay 里的截断上限是同一个数，改一处要改两处
    'search.matches' => '包含 “%s” 的函数（最多显示 30 条）：',
    // 火焰图（FlameGraph 模块零文案，卡片标题与说明在 Display 侧渲染）。
    // flame.note 的两个 %s = 实际画出帧数、未绘制占比；字面百分号写 %%
    'flame.title' => '火焰图',
    'flame.note' => '显示 %s 帧；为控制页面体积，另有 %s%% 的总耗时未绘制',
    // 与上次运行对比（run 详情页里指向同 URI 的上一条 run 的 diff 链接）。
    'run.previous' => '与上次运行对比',
    // 火焰图 metric 选中 mu/pmu 时的口径说明，跟在指标名后面。
    'flame.muInclusive' => '（内存为含子调用的口径）',
    // 报告页数字的千位 / 小数点分隔符（只服务 HTML 展示；导出与运行列表页不走这里）：各语言按 CLDR 实测值。
    'num.thousands' => ',',
    'num.decimal' => '.',
    // 该请求地址的其他运行：run 详情页指向列表页并带 `requrl`（列表页 JS 读它预填搜索框）。
    'run.otherRuns' => '该请求地址的其他运行',
    // 页脚版权行。品牌+URL 语言无关：13 份词表**逐字同值**（I18nTest 的逐字守卫只看含汉字的源值，按设计放行）；URL 由 render_footer() 包成链接。
    'footer.credit' => '© erik · https://erik.xyz',

    // 列表页状态条：已存条数 / 上限 / 保留天数 / 最早与最新时间。前两个数刻意不随语言分组（列表页表格也是裸值，见 list_runs 的渲染处注释）。
    'runs.status' => '已存 %s 条 / 上限 %s 条 · 数据保留 %s 天 · 最早 %s · 最新 %s',

];
