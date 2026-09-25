# 单次运行诊断设计

给单个 xhprof run 的报告页加一个诊断区：先回答"这次为什么慢"，再补充若干写法问题。
这是"性能分析与 bug 定位"两步走的第一步；第二步（跨请求/跨接口聚合）另立 spec。

## Decisions

| 决策 | 选择 |
|------|------|
| 分析范围 | 单次运行（跨请求聚合留给第二步） |
| 判定依据 | 占比阈值 + 结构特征，双轨 |
| 目标 | 归因优先（为什么慢），体检作为补充 |
| 呈现位置 | 现有运行报告页顶部，不新增路由、不动导航 |
| 实现路径 | 独立分析层（可脱离 HTML 单测），规则不抽接口 |
| 阈值 | 先硬编码为常量，不进 config |

## Scope

**做**：单 run 模式下的诊断区，6 条规则，渲染，测试。

**不做**（明确排除，避免范围蔓延）：

- **diff 模式不显示诊断区**。差值下 `excl_wt` 会变负，"占请求 43%" 这类表述失去意义，
  需要另一套差值语义，留给第二步。
- 跨请求 / 跨接口聚合
- 阈值可配置
- 源码关联、调用图可视化、火焰图

## 目录结构

```
src/Core/Analysis/          # 新增
  Finding.php               # 结论值对象
  Analyzer.php              # 编排 + 6 条规则（私有方法）
```

不引入 `RuleInterface`：这个规模的规则集不值得那层抽象，规则作为 `Analyzer` 的私有方法即可，
需要时再抽。

`XhprofDisplay` 只增加一个 `render_diagnosis(array $findings): string`。

## 数据流

```
displayXHProfReport
  → profiler_single_run_report
    → profiler_report
        $symbol_tab = xhprof_compute_flat_info($run1_data, $totals)
        if (!$diff_mode) {                                                  ← 新增（守卫）
            $findings = Analyzer::analyze($symbol_tab, $run1_data, $totals)
            $echo_page .= XhprofDisplay::render_diagnosis($findings)
        }
        ...原有表格与 JS 不变
```

诊断卡片插在动作栏（搜索框那行）之后、原有表格之前。

`Analyzer::analyze()` 的入参都是 `profiler_report` 里已有的局部变量，不需要新的数据采集。

## Finding 值对象

```php
final class Finding
{
    public const SEVERITY_MAIN = 'main';        // 归因结论
    public const SEVERITY_SUPPLEMENT = 'supplement';  // 体检发现

    public function __construct(
        public string $rule,        // 'R1'..'R6'，测试断言用
        public string $severity,
        public string $symbol,      // 相关函数名，用于生成详情页链接；无则空串
        public string $title,       // 一句话结论
        public string $detail,      // 补充说明
        public float  $score,       // 规则内的排序依据，量纲各不相同，只在规则内比较
    ) {}
}
```

`score` **不跨规则比较**——不同规则的量纲（耗时秒数 / 调用次数）不可比，跨规则排序见"主区组装"。

**不要用 `readonly` 属性**：那是 PHP 8.1 引入的，而本项目声明 `php >= 8.0`。
构造器属性提升本身是 8.0 语法，去掉 `readonly` 即可。

## 规则

阈值集中为 `Analyzer` 常量：

```php
const SHARE_THRESHOLD      = 0.10;  // R1 自身耗时占请求总耗时
const CALL_COUNT_THRESHOLD = 1000;  // R2 调用次数
const EDGE_COUNT_THRESHOLD = 500;   // R3 单条边的调用次数
const EDGE_SHARE_THRESHOLD = 0.05;  // R3 被调方自身耗时占比
const PMU_SHARE_THRESHOLD  = 0.30;  // R5 内存峰值占比
const MAIN_LIMIT           = 3;     // 主区封顶
const SUPPLEMENT_LIMIT     = 3;     // 补充区封顶
```

### R1 自身耗时占比最高

- 数据：`$symbol_tab` 每项的 `excl_wt`
- 条件：`$totals['wt'] > 0` 且 `excl_wt / totals['wt'] >= SHARE_THRESHOLD`
- 文案：「`foo()` 自身耗时 780ms，占本次请求 43%」
- 排序：`excl_wt` 降序

**不排除 `main()`**。`main()` 的自身耗时 = 请求总耗时里没有被采集到的部分（内置函数、C 扩展），
它占比高本身就是一条有效归因——"热点在采样覆盖之外"。排除它反而会给出空的或错误的结论。
不做特殊文案，函数名照常显示，由使用者自行判断。

### R2 调用次数异常

- 数据：`$symbol_tab` 每项的 `ct`
- 条件：`ct >= CALL_COUNT_THRESHOLD`
- 文案：「`strlen()` 被调用 12,431 次」
- 排序：`ct` 降序

### R3 单条边重复调用

- 数据：`$raw_data` 的每条边 `parent==>child`
- 条件：`parent` 非空（排除裸 `main()` 键）且 `edge_ct >= EDGE_COUNT_THRESHOLD`
  且 `child` 的 `excl_wt / totals['wt'] >= EDGE_SHARE_THRESHOLD`
- 文案：「`bar()` → `foo()` 调用 1,200 次，累计 512ms」
- 排序：边的 `wt` 降序

**措辞约束**：只陈述"调用 N 次"这个事实，**不得写"在循环里"**。1200 次同样可能来自
1200 个不同调用点，profiler 数据无法区分，断言是循环会误导。

### R4 递归

- 数据：`$raw_data` 的所有键，把符号按 `^(.+)@(\d+)$` 归一化
- 条件：同名符号出现在 ≥ 2 个不同深度
- 文案：「检测到 `fib()` 递归，最大深度 6」
- 排序：最大深度降序

xhprof 把递归展开为 `fib@1`、`fib@2` …，非递归调用没有 `@` 后缀。
已用真实扩展验证（`fib(7)` → 深度 1..6）。

### R5 内存峰值占比

- 数据：`$symbol_tab` 每项的 `excl_pmu`
- 条件：`$totals['pmu'] > 0` 且 `excl_pmu / totals['pmu'] >= PMU_SHARE_THRESHOLD`
- 文案：「`foo()` 内存峰值 128MB，占全局 61%」
- 排序：`excl_pmu` 降序

### R6 计时倒挂（数据完整性探针）

- 条件：同一符号 `excl_wt > wt`（自身耗时大于总耗时，逻辑上不可能）
- 文案：「`bar()` 自身耗时 210ms 大于其总耗时 180ms，采样数据可能异常」
- 排序：差值降序

**定义为同一符号内部比较，不是"与父函数比较"**。原方案比的是
`child.excl_wt > parent.inclusive_wt`，但一个函数被多个调用点调用时，
父的 inclusive 时间并不覆盖所有来源，会产生大量误报。

当前定义无漏报、无误报，代价是健康数据下**永远不触发**——它是探针，不是常规发现。
保留它是因为一旦触发就说明 xhprof 数据或本插件计算出了问题，值得显式暴露。

## 主区组装

主区（归因）最多 `MAIN_LIMIT` 条，按 **R1 → R3 → R2** 的顺序填充：先取 R1 的命中项，
不足 3 条再由 R3 补，再不足由 R2 补。同规则内按各自 `score` 降序。

顺序理由：R1 是"为什么慢"的头部结论，R3 给出可操作的具体调用关系，R2 是兜底。
不跨规则按 score 排序——量纲不同，混排没有意义。

补充区（体检）取 R4、R5、R6 的命中项，按 R4 → R5 → R6 填充，最多 `SUPPLEMENT_LIMIT` 条。

## 渲染

复用现有 `xp-card` / `xp-table` 样式，不新增 CSS 文件：

- 卡片标题「诊断结论」
- 主区小节「为什么慢」，每条一行：严重度色块 + `title` + `detail`
- 补充区小节「其他发现」
- `symbol` 非空时，函数名渲染为指向已有方法详情页的链接（`?symbol=<name>`）
- 两区都为空时显示一行「未发现明显瓶颈」，并列出当前阈值——
  空白会让人以为功能坏了，列出阈值能让人知道"是没超阈值"还是"没分析"

**转义**：`symbol` 来自 profile 数据，动态调用（`call_user_func`、`$obj->$method()`）
可以让请求影响函数名，因此渲染前必须 `htmlspecialchars($symbol, ENT_QUOTES, 'UTF-8')`。
`title` / `detail` 由本插件拼装，其中内嵌的符号名同样要转义。

## 错误处理与边界

`Analyzer::analyze()` **不得抛异常**，任何输入都要返回 `array`：

| 输入 | 行为 |
|------|------|
| `$symbol_tab` 非数组 / 空 | 返回 `[]` |
| `$raw_data` 为 `false` / null / 非数组 | R3、R4 跳过，其余照常 |
| `$totals` 非数组 / 缺 `wt` / `wt == 0` | R1、R3 跳过（避免除零） |
| `$totals` 缺 `pmu` 或为 0 | R5 跳过 |
| 单项缺 `excl_wt` / `ct` / `excl_pmu` | 该项跳过，不影响其他项 |
| `main()` 缺失 | 不影响（不假设它存在） |

数值一律先判分母 `> 0` 再做除法。

## 测试

### `tests/Unit/Core/Analysis/AnalyzerTest.php`（新增）

表驱动喂合成 `symbol_tab` / `raw_data` / `totals`，断言命中哪条规则、数值、排序、封顶。

必测边界：

- 阈值边界语义是 `>=`：**恰好等于时触发**（原句写成「恰好等于时不触发」，与同句的 `>=` 自相矛盾，已改正）
- 略低于阈值时不触发
- `totals['wt'] == 0` → R1/R3 跳过且不抛
- 空输入 / `raw_data = false` → 返回 `[]`
- R1 不排除 `main()`
- R2 与 R3 同时命中时，主区按 R1 → R3 → R2 组装
- 主区 / 补充区各自的封顶生效
- R4 递归识别：`fib@1`..`fib@6` 判为递归且深度为 6；无 `@` 后缀的符号不误判
- R4 对 `a@1==>a@2`：两边归一化后都是 `a`，判为递归；
  而 `a@1==>b@2` 归一化后是 `a` 与 `b` 两个名字，不得判为递归
- R5 峰值占比边界
- R6 在 `excl_wt > wt` 时触发，`excl_wt == wt` 时不触发

### `tests/Unit/Lib/XhprofDisplayTest.php`（追加）

- 诊断卡片出现在单 run 报告 HTML 中
- diff 模式下**不**出现
- 符号名含 `"` / `<` 时被转义
- 无发现时显示「未发现明显瓶颈」

### 回归验证

每条规则实现完成后，把该规则改成错误实现，确认对应测试**真的失败**，再改回。
这是本项目前几轮的既定做法——只写测试不做回退验证，出现过"判据写错导致四条全假报 PASS"。

## 依赖

不新增任何 Composer 依赖。不新增 config 项。不新增静态资源。

## 后续（不在本 spec 内）

第二步的跨请求聚合需要：按接口/时间分组、百分位数、基线对比。届时再决定是否需要
独立页面与导航项。
