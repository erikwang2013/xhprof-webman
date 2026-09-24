# 单次运行诊断 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 给单次 xhprof run 的报告页顶部加一个诊断区，先回答"这次为什么慢"，再补充若干写法问题。

**Architecture:** 新增 `src/Core/Analysis/` 两个文件。`Analyzer` 是纯函数式的——入参是 `profiler_report()` 里已有的 `$symbol_tab` / `$raw_data` / `$totals`，输出 `Finding[]`，不碰 HTML，可脱离渲染单测。`XhprofDisplay` 只加一个渲染方法。6 条规则作为 `Analyzer` 的私有方法，不抽 `RuleInterface`。

**Tech Stack:** PHP 8.0（**不能用 8.1+ 语法，尤其 `readonly`**）、PHPUnit 11、现有 `xp-card` 样式。

**Spec:** `docs/superpowers/specs/2026-09-25-single-run-diagnosis-design.md`

---

## 相对 spec 的三处实现修正（已在设计阶段确认）

1. **`render_diagnosis()` 增加第二个参数 `$url_params`**。spec 里签名只有一个参数，但那样生成的详情页链接会丢掉 `run` 参数，点进去只会看到运行列表。现有代码的链接一律是 `http_build_query(XhprofLib::xhprof_array_set($url_params, 'symbol', $fn))`。
2. **只在顶层报告页显示**：增加 `empty($rep_symbol)` 守卫。函数详情页回答的是"这个函数为什么慢"，不是"这次请求为什么慢"，在那里显示全局诊断会错位。
3. **`Finding::$title` / `$detail` 存纯文本**，不在 `Analyzer` 里做 HTML 转义；转义统一由 `render_diagnosis()` 做一次。这样 Analyzer 的单测可以直接断言可读文本，而转义只有一个地方要做对。

## File Structure

| 文件 | 职责 |
|------|------|
| `src/Core/Analysis/Finding.php`（新建） | 结论值对象。无逻辑，只有常量与字段 |
| `src/Core/Analysis/Analyzer.php`（新建） | 编排 + 6 条规则 + 组装封顶。纯函数，不抛异常 |
| `src/Core/XhprofLib/Display/XhprofDisplay.php`（改） | 加 `render_diagnosis()` + `diagnosis_item()`；在 `profiler_report()` 里接入 |
| `tests/Unit/Core/Analysis/AnalyzerTest.php`（新建） | 规则的表驱动测试 |
| `tests/Unit/Lib/XhprofDisplayTest.php`（改） | 渲染与转义断言 |

---

## Task 1: Finding 值对象与 Analyzer 骨架

先把契约立起来：**`analyze()` 对任何输入都返回数组、绝不抛异常**。这是整个特性里最容易出错、也最致命的一点——诊断是旁路，它抛异常会毁掉整个报告页。

**Files:**
- Create: `src/Core/Analysis/Finding.php`
- Create: `src/Core/Analysis/Analyzer.php`
- Test: `tests/Unit/Core/Analysis/AnalyzerTest.php`

- [ ] **Step 1: 写失败的测试**

创建 `tests/Unit/Core/Analysis/AnalyzerTest.php`：

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Core\Analysis;

use ErikWang2013\Xhprof\Core\Analysis\Analyzer;
use ErikWang2013\Xhprof\Core\Analysis\Finding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AnalyzerTest extends TestCase
{
    /** 诊断是旁路：任何畸形输入都必须返回数组而不是抛异常 */
    #[Test]
    #[DataProvider('malformedInputProvider')]
    public function analyzeNeverThrowsOnMalformedInput(mixed $symbolTab, mixed $rawData, mixed $totals): void
    {
        $this->assertSame([], Analyzer::analyze($symbolTab, $rawData, $totals));
    }

    public static function malformedInputProvider(): array
    {
        return [
            '全空数组'        => [[], [], []],
            'null 入参'       => [null, null, null],
            '字符串入参'      => ['x', 'y', 'z'],
            // 注意 excl_wt 必须是 0：这行要测的是"raw_data 为 false 不会搞崩 R3/R4"，
            // 若给它 100，R1 会在 100/100=100% 处命中，断言就变成 1 条而非空数组。
            'raw_data 为 false（get_run 失败的形态）' => [
                ['main()' => ['ct' => 1, 'wt' => 100, 'excl_wt' => 0]],
                false,
                ['wt' => 100],
            ],
            'totals 缺 wt'    => [
                ['main()' => ['ct' => 1, 'wt' => 100, 'excl_wt' => 100]],
                [],
                [],
            ],
            'totals wt 为 0'  => [
                ['main()' => ['ct' => 1, 'wt' => 0, 'excl_wt' => 0]],
                [],
                ['wt' => 0],
            ],
        ];
    }

    #[Test]
    public function findingHoldsValues(): void
    {
        $f = new Finding('R1', Finding::SEVERITY_MAIN, 'foo()', '标题', '细节', 123.0);

        $this->assertSame('R1', $f->rule);
        $this->assertSame('main', $f->severity);
        $this->assertSame('foo()', $f->symbol);
        $this->assertSame('标题', $f->title);
        $this->assertSame('细节', $f->detail);
        $this->assertSame(123.0, $f->score);
    }
}
```

- [ ] **Step 2: 运行测试确认失败**

Run: `vendor/bin/phpunit --filter AnalyzerTest`
Expected: FAIL —— `Class "ErikWang2013\Xhprof\Core\Analysis\Analyzer" not found`

- [ ] **Step 3: 实现 Finding**

创建 `src/Core/Analysis/Finding.php`：

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core\Analysis;

/**
 * 一条诊断结论。
 *
 * $title / $detail 是**纯文本**，不含 HTML——转义由渲染层统一负责，
 * 这样 Analyzer 的单测可以直接断言可读文本，转义也只需要在一处做对。
 *
 * $score 只在**同一条规则内**用于排序。不同规则的量纲（微秒 / 次数）不可比，
 * 不要跨规则比较。
 */
final class Finding
{
    /** 归因结论：回答"这次为什么慢" */
    public const SEVERITY_MAIN = 'main';

    /** 体检发现：写法/结构上的问题 */
    public const SEVERITY_SUPPLEMENT = 'supplement';

    public function __construct(
        public string $rule,
        public string $severity,
        public string $symbol,
        public string $title,
        public string $detail,
        public float $score,
    ) {
    }
}
```

- [ ] **Step 4: 实现 Analyzer 骨架**

创建 `src/Core/Analysis/Analyzer.php`：

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core\Analysis;

/**
 * 单次运行诊断。
 *
 * 入参都是 XhprofDisplay::profiler_report() 里已有的局部变量，
 * 不新增数据采集、不新增存储、不碰 HTML。
 *
 * 最重要的一条约定：analyze() 不得抛异常。诊断是旁路，
 * 它出问题只会让报告页多一块内容或没有这块内容，绝不能让整页 500。
 */
final class Analyzer
{
    /** R1 自身耗时占请求总耗时 */
    public const SHARE_THRESHOLD = 0.10;

    /** R2 调用次数 */
    public const CALL_COUNT_THRESHOLD = 1000;

    /** R3 单条边的调用次数 */
    public const EDGE_COUNT_THRESHOLD = 500;

    /** R3 被调方自身耗时占比 */
    public const EDGE_SHARE_THRESHOLD = 0.05;

    /** R5 内存峰值占比 */
    public const PMU_SHARE_THRESHOLD = 0.30;

    /** 主区（归因）条数上限 */
    public const MAIN_LIMIT = 3;

    /** 补充区（体检）条数上限 */
    public const SUPPLEMENT_LIMIT = 3;

    /**
     * @param mixed $symbol_tab xhprof_compute_flat_info() 的结果
     * @param mixed $raw_data   原始 xhprof 边表
     * @param mixed $totals     总计（wt / pmu 等）
     * @return Finding[]
     */
    public static function analyze($symbol_tab, $raw_data, $totals): array
    {
        if (!is_array($symbol_tab) || $symbol_tab === array()) {
            return array();
        }

        return array();
    }
}
```

- [ ] **Step 5: 运行测试确认通过**

Run: `vendor/bin/phpunit --filter AnalyzerTest`
Expected: PASS（4 个用例：6 条畸形输入 + 1 条值对象）

- [ ] **Step 6: 提交**

```bash
git add src/Core/Analysis/ tests/Unit/Core/Analysis/
git commit -m "feat(analysis): Finding 值对象与 Analyzer 骨架（永不抛异常契约）"
```

---

## Task 2: 归因规则 R1 / R2 / R3

三条归因规则都用 `symbol_tab`（R3 另需边表）。`symbol_tab` 的键已实测含 `ct` / `wt` / `excl_wt` / `mu` / `pmu` / `excl_mu` / `excl_pmu`。

**Files:**
- Modify: `src/Core/Analysis/Analyzer.php`
- Test: `tests/Unit/Core/Analysis/AnalyzerTest.php`

- [ ] **Step 1: 写失败的测试**

在 `AnalyzerTest` 中追加（`use ErikWang2013\Xhprof\Core\XhprofLib\Utils\XhprofLib;` 需要追加到 import 区）：

```php
    /** 造一个 symbol_tab 项：只给关心的键 */
    private static function sym(float $ct, float $wt, float $exclWt, float $pmu = 0.0): array
    {
        return ['ct' => $ct, 'wt' => $wt, 'excl_wt' => $exclWt, 'mu' => 0, 'pmu' => $pmu, 'excl_mu' => 0, 'excl_pmu' => $pmu];
    }

    #[Test]
    public function r1FiresWhenShareReachesThreshold(): void
    {
        // 10% 恰好等于阈值 → 触发（语义是 >=）
        $tab = ['main()' => self::sym(1, 1000, 100)];
        $found = Analyzer::analyze($tab, [], ['wt' => 1000]);

        $this->assertCount(1, $found);
        $this->assertSame('R1', $found[0]->rule);
        $this->assertSame('main()', $found[0]->symbol);
    }

    #[Test]
    public function r1DoesNotFireJustBelowThreshold(): void
    {
        $tab = ['main()' => self::sym(1, 1000, 99)];
        $this->assertSame([], Analyzer::analyze($tab, [], ['wt' => 1000]));
    }

    /** R1 不排除 main()：它占比高说明热点在采样覆盖之外，排除会给出错误结论 */
    #[Test]
    public function r1DoesNotExcludeMain(): void
    {
        $tab = ['main()' => self::sym(1, 1000, 900)];
        $found = Analyzer::analyze($tab, [], ['wt' => 1000]);
        $this->assertSame('main()', $found[0]->symbol);
    }

    #[Test]
    public function r1SortsByExclusiveTimeDescending(): void
    {
        $tab = [
            'small()' => self::sym(1, 100, 200),
            'big()'   => self::sym(1, 100, 500),
            'mid()'   => self::sym(1, 100, 300),
        ];
        $found = Analyzer::analyze($tab, [], ['wt' => 1000]);
        $this->assertSame(['big()', 'mid()', 'small()'], array_map(fn($f) => $f->symbol, $found));
    }

    #[Test]
    public function r2FiresAtCallCountThreshold(): void
    {
        $tab = ['loop()' => self::sym(1000, 10, 1)];
        $found = Analyzer::analyze($tab, [], ['wt' => 10000]);

        $hits = array_values(array_filter($found, fn($f) => $f->rule === 'R2'));
        $this->assertCount(1, $hits);
        $this->assertSame('loop()', $hits[0]->symbol);
        $this->assertStringContainsString('1,000', $hits[0]->title);
    }

    #[Test]
    public function r2DoesNotFireAt999(): void
    {
        $tab = ['loop()' => self::sym(999, 10, 1)];
        $found = Analyzer::analyze($tab, [], ['wt' => 10000]);
        $this->assertSame([], array_filter($found, fn($f) => $f->rule === 'R2'));
    }

    #[Test]
    public function r3FiresOnHotEdge(): void
    {
        $tab = [
            'main()' => self::sym(1, 1000, 100),
            'foo()'  => self::sym(600, 600, 100),   // 自身占 10% ≥ 5%
        ];
        $raw = [
            'main()' => ['ct' => 1, 'wt' => 1000],
            'main()==>foo()' => ['ct' => 600, 'wt' => 400],
        ];
        $found = Analyzer::analyze($tab, $raw, ['wt' => 1000]);

        $hits = array_values(array_filter($found, fn($f) => $f->rule === 'R3'));
        $this->assertCount(1, $hits);
        $this->assertStringContainsString('main() → foo()', $hits[0]->title);
        $this->assertStringContainsString('600', $hits[0]->title);
    }

    /** R3 不得出现"循环"字样——profiler 数据无法区分循环与多个调用点 */
    #[Test]
    public function r3NeverClaimsLoop(): void
    {
        $tab = ['main()' => self::sym(1, 1000, 100), 'foo()' => self::sym(600, 600, 100)];
        $raw = ['main()==>foo()' => ['ct' => 600, 'wt' => 400]];
        $found = Analyzer::analyze($tab, $raw, ['wt' => 1000]);

        foreach ($found as $f) {
            $this->assertStringNotContainsString('循环', $f->title . $f->detail);
        }
    }

    /**
     * 逐项粒度：一个坏键不能连带丢掉 R3 的其他有效结论。
     * 整型数组键（PHP 会把 "123" 这类键转成 int）会让边解析抛 TypeError，
     * 而 safe() 的兜底粒度是**整条规则**——所以 R3 必须自己把键转成 string。
     */
    #[Test]
    public function r3SurvivesIntegerKeyAmongValidEdges(): void
    {
        $tab = ['main()' => self::sym(1, 1000, 100), 'foo()' => self::sym(600, 600, 100)];
        $raw = [
            0 => ['ct' => 999, 'wt' => 1],              // 坏键：会被转成 int
            'main()==>foo()' => ['ct' => 600, 'wt' => 400],
        ];
        $found = Analyzer::analyze($tab, $raw, ['wt' => 1000]);

        $hits = array_values(array_filter($found, fn($f) => $f->rule === 'R3'));
        $this->assertCount(1, $hits, '坏键不得吞掉同一条规则里其他有效边的结论');
        $this->assertStringContainsString('main() → foo()', $hits[0]->title);
    }

    /** 裸 main() 键没有父，不能当成边来处理 */
    #[Test]
    public function r3SkipsBareMainKey(): void
    {
        $tab = ['main()' => self::sym(1, 1000, 100)];
        $raw = ['main()' => ['ct' => 9999, 'wt' => 900]];
        $found = Analyzer::analyze($tab, $raw, ['wt' => 1000]);
        $this->assertSame([], array_filter($found, fn($f) => $f->rule === 'R3'));
    }
```

- [ ] **Step 2: 运行测试确认失败**

Run: `vendor/bin/phpunit --filter AnalyzerTest`
Expected: FAIL —— 各 R1/R2/R3 用例断言 `count` 时拿到 0

- [ ] **Step 3: 实现三条规则**

在 `Analyzer` 里把 `analyze()` 的 `return array();` 替换为规则调用，并追加私有方法：

```php
    public static function analyze($symbol_tab, $raw_data, $totals): array
    {
        if (!is_array($symbol_tab) || $symbol_tab === array()) {
            return array();
        }

        $totals   = is_array($totals) ? $totals : array();
        $raw_data = is_array($raw_data) ? $raw_data : array();

        // ↑ 这两行归一化**不要**因为有了 safe() 就去掉。规则签名是 `array $raw_data`，
        //   在 strict_types 下传 false 会在调用边界抛 TypeError；safe() 虽然会把它兜成
        //   空结果（所以测试看不出差别），但那等于把输入契约寄托在"异常被吞掉"上面。
        //   归一化到入口一次，规则就能放心假设入参是数组。

        // 每条规则各自过 safe()：某条规则内部出错只让它自己产出空结果，
        // 不会连累其他规则，更不会冒泡成报告页 500。
        return array_merge(
            self::safe(static fn(): array => self::ruleR1($symbol_tab, $totals)),
            self::safe(static fn(): array => self::ruleR3($symbol_tab, $raw_data, $totals)),
            self::safe(static fn(): array => self::ruleR2($symbol_tab))
        );
    }

    /**
     * R1：自身耗时占比。
     * 不排除 main()——它的自身耗时 = 总耗时里没被采集到的部分（内置函数、C 扩展），
     * 占比高本身就是有效归因；排除它反而会给出空白或错误的结论。
     */
    private static function ruleR1(array $symbol_tab, array $totals): array
    {
        $total = self::num($totals, 'wt');
        if ($total <= 0) {
            return array();
        }

        $hits = array();
        foreach ($symbol_tab as $fn => $info) {
            if (!is_array($info) || !isset($info['excl_wt']) || !is_numeric($info['excl_wt'])) {
                continue;
            }
            $excl  = (float) $info['excl_wt'];
            $share = $excl / $total;
            if ($share < self::SHARE_THRESHOLD) {
                continue;
            }
            $hits[] = array($excl, (string) $fn, $share);
        }
        usort($hits, static fn($a, $b) => $b[0] <=> $a[0]);

        $out = array();
        foreach ($hits as $h) {
            $out[] = new Finding(
                'R1',
                Finding::SEVERITY_MAIN,
                $h[1],
                sprintf('%s 自身耗时 %s，占本次请求 %s%%', $h[1], self::ms($h[0]), self::pct($h[2])),
                '自身耗时不含子调用，是纯函数体开销',
                $h[0]
            );
        }
        return $out;
    }

    /** R2：调用次数异常 */
    private static function ruleR2(array $symbol_tab): array
    {
        $hits = array();
        foreach ($symbol_tab as $fn => $info) {
            if (!is_array($info) || !isset($info['ct']) || !is_numeric($info['ct'])) {
                continue;
            }
            $ct = (float) $info['ct'];
            if ($ct < self::CALL_COUNT_THRESHOLD) {
                continue;
            }
            $hits[] = array($ct, (string) $fn);
        }
        usort($hits, static fn($a, $b) => $b[0] <=> $a[0]);

        $out = array();
        foreach ($hits as $h) {
            $out[] = new Finding(
                'R2',
                Finding::SEVERITY_MAIN,
                $h[1],
                sprintf('%s 被调用 %s 次', $h[1], number_format($h[0])),
                '调用次数偏高，值得确认是否符合预期',
                $h[0]
            );
        }
        return $out;
    }

    /**
     * R3：单条边重复调用。
     *
     * 只陈述"调用 N 次"这个事实，不得写成"在循环里"——
     * 1200 次同样可能来自 1200 个不同调用点，profiler 数据无法区分。
     */
    private static function ruleR3(array $symbol_tab, array $raw_data, array $totals): array
    {
        $total = self::num($totals, 'wt');
        if ($total <= 0) {
            return array();
        }

        $hits = array();
        foreach ($raw_data as $edge => $info) {
            if (!is_array($info)) {
                continue;
            }
            // 必须 (string)：PHP 会把 "123" 这类数组键转成 int，整型传给
            // xhprof_parse_parent_child 内部的 explode() 会抛 TypeError。
            // safe() 的粒度是整条规则，一个坏键会连带丢掉 R3 的全部有效结论。
            list($parent, $child) = XhprofLib::xhprof_parse_parent_child((string) $edge);
            if ($parent === null || $parent === '') {
                continue;   // 裸 main() 键没有父，不是边
            }
            $ct = isset($info['ct']) && is_numeric($info['ct']) ? (float) $info['ct'] : 0.0;
            if ($ct < self::EDGE_COUNT_THRESHOLD) {
                continue;
            }
            if (!isset($symbol_tab[$child]['excl_wt']) || !is_numeric($symbol_tab[$child]['excl_wt'])) {
                continue;
            }
            $excl = (float) $symbol_tab[$child]['excl_wt'];
            if (($excl / $total) < self::EDGE_SHARE_THRESHOLD) {
                continue;
            }
            $wt = isset($info['wt']) && is_numeric($info['wt']) ? (float) $info['wt'] : 0.0;
            $hits[] = array($wt, (string) $parent, (string) $child, $ct);
        }
        usort($hits, static fn($a, $b) => $b[0] <=> $a[0]);

        $out = array();
        foreach ($hits as $h) {
            $out[] = new Finding(
                'R3',
                Finding::SEVERITY_MAIN,
                $h[2],
                sprintf('%s → %s 调用 %s 次，累计 %s', $h[1], $h[2], number_format($h[3]), self::ms($h[0])),
                '该调用关系占据了可观耗时',
                $h[0]
            );
        }
        return $out;
    }

    /** 从 totals 取一个非负数值，缺失/非数值一律当 0 */
    private static function num(array $totals, string $key): float
    {
        return isset($totals[$key]) && is_numeric($totals[$key]) ? (float) $totals[$key] : 0.0;
    }

    /** 微秒 → 毫秒，保留 1 位小数 */
    private static function ms(float $us): string
    {
        return number_format($us / 1000, 1) . 'ms';
    }

    /** 比率 → 百分数，保留 1 位小数 */
    private static function pct(float $ratio): string
    {
        return number_format($ratio * 100, 1);
    }
```

- [ ] **Step 4: 运行测试确认通过**

Run: `vendor/bin/phpunit --filter AnalyzerTest`
Expected: PASS

**关于入口归一化的可验证性（结论：观察不到，别再试）：** 去掉
`$raw_data = is_array($raw_data) ? $raw_data : array();` 后测试**仍然是绿的** ——
规则签名是 `array $raw_data`，strict_types 下传 false 会在调用边界抛 TypeError，
被 `safe()` 兜成空结果，于是归一化在 `analyze()` 的公共面上不可观测。
保留这两行是因为它们把输入契约定在入口、而不是寄托于异常吞没；
但不要为它写"回退验证"，也不要声称有测试守护它。

- [ ] **Step 5: 提交**

```bash
git add src/Core/Analysis/Analyzer.php tests/Unit/Core/Analysis/AnalyzerTest.php
git commit -m "feat(analysis): 归因规则 R1 自身耗时 / R2 调用次数 / R3 边重复调用"
```

> **别把 `safe()` 当成逐项守卫的替代品。** `safe()` 的隔离粒度是**整条规则**：规则中途抛异常，
> 该规则已经产出的结论会全部丢掉。spec 承诺的是**逐项**粒度（单项缺 `excl_wt` → 该项跳过，
> 不影响其他项），那靠的是规则内部每个字段前的 `is_array` / `is_numeric` 守卫与 `continue`。
> 两者是不同层面的防线，都要有。

---

## Task 3: 体检规则 R4 递归 / R5 内存峰值 / R6 计时倒挂

**Files:**
- Modify: `src/Core/Analysis/Analyzer.php`
- Test: `tests/Unit/Core/Analysis/AnalyzerTest.php`

- [ ] **Step 1: 写失败的测试**

```php
    #[Test]
    public function r4DetectsRecursionAndReportsMaxDepth(): void
    {
        $raw = [
            'main()==>fib' => ['ct' => 1, 'wt' => 100],
            'fib@1==>fib@2' => ['ct' => 1, 'wt' => 90],
            'fib@2==>fib@3' => ['ct' => 1, 'wt' => 80],
        ];
        $tab = ['main()' => self::sym(1, 100, 10), 'fib@1' => self::sym(1, 90, 5)];
        $found = Analyzer::analyze($tab, $raw, ['wt' => 100]);

        $hits = array_values(array_filter($found, fn($f) => $f->rule === 'R4'));
        $this->assertCount(1, $hits);
        $this->assertSame('fib', $hits[0]->symbol);
        $this->assertStringContainsString('3', $hits[0]->title);
    }

    /** 同名只出现在一个深度 → 不是递归 */
    #[Test]
    public function r4IgnoresSingleDepth(): void
    {
        $raw = ['main()==>foo@1' => ['ct' => 1, 'wt' => 10]];
        $tab = ['main()' => self::sym(1, 100, 10)];
        $found = Analyzer::analyze($tab, $raw, ['wt' => 100]);
        $this->assertSame([], array_filter($found, fn($f) => $f->rule === 'R4'));
    }

    /** a@1==>b@2 是两个不同名字，不得判为递归 */
    #[Test]
    public function r4DoesNotConfuseDifferentNames(): void
    {
        $raw = ['a@1==>b@2' => ['ct' => 1, 'wt' => 10]];
        $tab = ['main()' => self::sym(1, 100, 10)];
        $found = Analyzer::analyze($tab, $raw, ['wt' => 100]);
        $this->assertSame([], array_filter($found, fn($f) => $f->rule === 'R4'));
    }

    #[Test]
    public function r5FiresAtPmuShare(): void
    {
        $tab = ['hog()' => self::sym(1, 10, 1, 30)];
        $found = Analyzer::analyze($tab, [], ['wt' => 100, 'pmu' => 100]);

        $hits = array_values(array_filter($found, fn($f) => $f->rule === 'R5'));
        $this->assertCount(1, $hits);
        $this->assertSame('hog()', $hits[0]->symbol);
    }

    #[Test]
    public function r5SkippedWhenPmuTotalIsZero(): void
    {
        $tab = ['hog()' => self::sym(1, 10, 1, 30)];
        $found = Analyzer::analyze($tab, [], ['wt' => 100, 'pmu' => 0]);
        $this->assertSame([], array_filter($found, fn($f) => $f->rule === 'R5'));
    }

    /** R6 探针：excl_wt > wt 逻辑上不可能，健康数据下永不触发 */
    #[Test]
    public function r6FiresWhenExclusiveExceedsInclusive(): void
    {
        $tab = ['bad()' => ['ct' => 1, 'wt' => 180, 'excl_wt' => 210, 'pmu' => 0, 'excl_pmu' => 0]];
        $found = Analyzer::analyze($tab, [], ['wt' => 1000]);

        $hits = array_values(array_filter($found, fn($f) => $f->rule === 'R6'));
        $this->assertCount(1, $hits);
        $this->assertSame('bad()', $hits[0]->symbol);
    }

    #[Test]
    public function r6DoesNotFireWhenEqual(): void
    {
        $tab = ['ok()' => ['ct' => 1, 'wt' => 200, 'excl_wt' => 200, 'pmu' => 0, 'excl_pmu' => 0]];
        $found = Analyzer::analyze($tab, [], ['wt' => 1000]);
        $this->assertSame([], array_filter($found, fn($f) => $f->rule === 'R6'));
    }
```

- [ ] **Step 2: 运行测试确认失败**

Run: `vendor/bin/phpunit --filter AnalyzerTest`
Expected: FAIL —— R4/R5/R6 用例拿到 0 条

- [ ] **Step 3: 实现三条规则**

`analyze()` 的 `array_merge` 追加体检区；然后加三个私有方法：

```php
        $main = array_merge(
            self::safe(static fn(): array => self::ruleR1($symbol_tab, $totals)),
            self::safe(static fn(): array => self::ruleR3($symbol_tab, $raw_data, $totals)),
            self::safe(static fn(): array => self::ruleR2($symbol_tab))
        );
        $supplement = array_merge(
            self::safe(static fn(): array => self::ruleR4($raw_data)),
            self::safe(static fn(): array => self::ruleR5($symbol_tab, $totals)),
            self::safe(static fn(): array => self::ruleR6($symbol_tab))
        );

        return array_merge($main, $supplement);
```

```php
    /**
     * R4：递归。
     * xhprof 把递归展开成 fib@1、fib@2 …，非递归调用没有 @ 后缀（如 main()==>fib）。
     * 判据是同名符号出现在 >= 2 个不同深度。
     */
    private static function ruleR4(array $raw_data): array
    {
        $depths = array();
        foreach (array_keys($raw_data) as $edge) {
            foreach (explode('==>', (string) $edge) as $sym) {
                if (preg_match('/^(.+)@(\d+)$/', $sym, $m) !== 1) {
                    continue;
                }
                $depths[$m[1]][(int) $m[2]] = true;
            }
        }

        $hits = array();
        foreach ($depths as $name => $ds) {
            if (count($ds) < 2) {
                continue;
            }
            $hits[] = array(max(array_keys($ds)), (string) $name);
        }
        usort($hits, static fn($a, $b) => $b[0] <=> $a[0]);

        $out = array();
        foreach ($hits as $h) {
            $out[] = new Finding(
                'R4',
                Finding::SEVERITY_SUPPLEMENT,
                // 符号置空：递归在 symbol_tab 里的键是 fib@1/fib@2，而详情页按 symbol=fib 查，
                // 必然"未找到"。spec 允许 symbol 为空（渲染层会跳过链接），这里就该为空。
                '',
                sprintf('检测到 %s() 递归，最大深度 %d', $h[1], $h[0]),
                '递归深度过大可能导致栈溢出或耗时呈指数增长',
                (float) $h[0]
            );
        }
        return $out;
    }

    /** R5：内存峰值占比 */
    private static function ruleR5(array $symbol_tab, array $totals): array
    {
        $total = self::num($totals, 'pmu');
        if ($total <= 0) {
            return array();
        }

        $hits = array();
        foreach ($symbol_tab as $fn => $info) {
            if (!is_array($info) || !isset($info['excl_pmu']) || !is_numeric($info['excl_pmu'])) {
                continue;
            }
            $pmu   = (float) $info['excl_pmu'];
            $share = $pmu / $total;
            if ($share < self::PMU_SHARE_THRESHOLD) {
                continue;
            }
            $hits[] = array($pmu, (string) $fn, $share);
        }
        usort($hits, static fn($a, $b) => $b[0] <=> $a[0]);

        $out = array();
        foreach ($hits as $h) {
            $out[] = new Finding(
                'R5',
                Finding::SEVERITY_SUPPLEMENT,
                $h[1],
                sprintf('%s 内存峰值 %s，占全局 %s%%', $h[1], self::bytes($h[0]), self::pct($h[2])),
                '峰值内存集中在单个函数，可优先核查其数据结构',
                $h[0]
            );
        }
        return $out;
    }

    /**
     * R6：计时倒挂探针。
     *
     * 判据是**同一符号内部** excl_wt > wt（自身耗时大于总耗时，逻辑上不可能）。
     * 不要改成"与父函数比较"——一个函数被多处调用时，父的 inclusive 时间
     * 并不覆盖所有来源，会产生大量误报。
     *
     * 健康数据下本规则永远不触发，它是数据完整性探针，不是常规发现。
     */
    private static function ruleR6(array $symbol_tab): array
    {
        $hits = array();
        foreach ($symbol_tab as $fn => $info) {
            if (!is_array($info) || !isset($info['excl_wt'], $info['wt'])) {
                continue;
            }
            if (!is_numeric($info['excl_wt']) || !is_numeric($info['wt'])) {
                continue;
            }
            $excl = (float) $info['excl_wt'];
            $incl = (float) $info['wt'];
            if ($excl <= $incl) {
                continue;
            }
            $hits[] = array($excl - $incl, (string) $fn, $excl, $incl);
        }
        usort($hits, static fn($a, $b) => $b[0] <=> $a[0]);

        $out = array();
        foreach ($hits as $h) {
            $out[] = new Finding(
                'R6',
                Finding::SEVERITY_SUPPLEMENT,
                $h[1],
                sprintf('%s 自身耗时 %s 大于其总耗时 %s', $h[1], self::ms($h[2]), self::ms($h[3])),
                '自身耗时不应大于总耗时，采样数据或统计计算可能异常',
                $h[0]
            );
        }
        return $out;
    }

    /** 字节 → 人类可读 */
    private static function bytes(float $b): string
    {
        if ($b >= 1048576) {
            return number_format($b / 1048576, 1) . 'MB';
        }
        if ($b >= 1024) {
            return number_format($b / 1024, 1) . 'KB';
        }
        return number_format($b) . 'B';
    }
```

- [ ] **Step 4: 运行测试确认通过**

Run: `vendor/bin/phpunit --filter AnalyzerTest`
Expected: PASS

- [ ] **Step 5: 提交**

```bash
git add src/Core/Analysis/Analyzer.php tests/Unit/Core/Analysis/AnalyzerTest.php
git commit -m "feat(analysis): 体检规则 R4 递归 / R5 内存峰值 / R6 计时倒挂探针"
```

---

## Task 4: 主区 / 补充区组装与封顶

**Files:**
- Modify: `src/Core/Analysis/Analyzer.php`
- Test: `tests/Unit/Core/Analysis/AnalyzerTest.php`

- [ ] **Step 1: 写失败的测试**

```php
    /** 主区按 R1 → R3 → R2 填充，不是跨规则按 score 排序（量纲不同不可比） */
    #[Test]
    public function mainSectionFillsR1ThenR3ThenR2(): void
    {
        $tab = [
            'slow()' => self::sym(1, 1000, 500),    // R1，自身 50%
            'loop()' => self::sym(2000, 10, 1),     // R2，调用 2000 次
        ];
        $raw = ['main()==>tee()' => ['ct' => 700, 'wt' => 60]];
        $tab['tee()'] = self::sym(700, 60, 60);     // 自身占 6% ≥ 5% → R3

        $found = Analyzer::analyze($tab, $raw, ['wt' => 1000]);
        $main = array_values(array_filter($found, fn($f) => $f->severity === Finding::SEVERITY_MAIN));

        // R1 命中 1 条，R3 命中 1 条，R2 命中 1 条，共 3 条
        $this->assertSame(['R1', 'R3', 'R2'], array_map(fn($f) => $f->rule, $main));
    }

    /** R1 命中 3 条时就把主区占满，R3/R2 不得挤进来 */
    #[Test]
    public function mainSectionIsCappedByR1First(): void
    {
        $tab = [
            'a()' => self::sym(1, 100, 400),
            'b()' => self::sym(1, 100, 300),
            'c()' => self::sym(1, 100, 200),
            'loop()' => self::sym(5000, 10, 1),
        ];
        $found = Analyzer::analyze($tab, [], ['wt' => 1000]);
        $main = array_values(array_filter($found, fn($f) => $f->severity === Finding::SEVERITY_MAIN));

        $this->assertCount(Analyzer::MAIN_LIMIT, $main);
        $this->assertSame(['a()', 'b()', 'c()'], array_map(fn($f) => $f->symbol, $main));
    }

    #[Test]
    public function supplementSectionIsCapped(): void
    {
        $tab = [];
        for ($i = 0; $i < 5; $i++) {
            $tab["hog$i()"] = ['ct' => 1, 'wt' => 10, 'excl_wt' => 1, 'pmu' => 50, 'excl_pmu' => 50];
        }
        $found = Analyzer::analyze($tab, [], ['wt' => 1000, 'pmu' => 100]);
        $supp = array_filter($found, fn($f) => $f->severity === Finding::SEVERITY_SUPPLEMENT);

        $this->assertCount(Analyzer::SUPPLEMENT_LIMIT, $supp);
    }

    #[Test]
    public function mainFindingsComeBeforeSupplements(): void
    {
        $tab = [
            'slow()' => self::sym(1, 1000, 500),
            'hog()'  => ['ct' => 1, 'wt' => 10, 'excl_wt' => 1, 'pmu' => 90, 'excl_pmu' => 90],
        ];
        $found = Analyzer::analyze($tab, [], ['wt' => 1000, 'pmu' => 100]);

        $this->assertSame(Finding::SEVERITY_MAIN, $found[0]->severity);
        $this->assertSame(Finding::SEVERITY_SUPPLEMENT, $found[count($found) - 1]->severity);
    }
```

- [ ] **Step 2: 运行测试确认失败**

Run: `vendor/bin/phpunit --filter AnalyzerTest`
Expected: FAIL —— 封顶与顺序断言不成立

- [ ] **Step 3: 加封顶**

`analyze()` 里组装后切片：

```php
        $main       = array_slice($main, 0, self::MAIN_LIMIT);
        $supplement = array_slice($supplement, 0, self::SUPPLEMENT_LIMIT);

        return array_merge($main, $supplement);
```

- [ ] **Step 4: 运行测试确认通过**

Run: `vendor/bin/phpunit --filter AnalyzerTest`
Expected: PASS

- [ ] **Step 5: 提交**

```bash
git add src/Core/Analysis/Analyzer.php tests/Unit/Core/Analysis/AnalyzerTest.php
git commit -m "feat(analysis): 主区 R1→R3→R2 组装与两区封顶"
```

---

## Task 5: render_diagnosis 渲染

**Files:**
- Modify: `src/Core/XhprofLib/Display/XhprofDisplay.php`
- Test: `tests/Unit/Lib/XhprofDisplayTest.php`

- [ ] **Step 1: 写失败的测试**

在 `XhprofDisplayTest` 中追加（`use ErikWang2013\Xhprof\Core\Analysis\Finding;` 加到 import 区）：

```php
    #[Test]
    public function renderDiagnosisShowsFindings(): void
    {
        $html = XhprofDisplay::render_diagnosis(
            [
                new Finding('R1', Finding::SEVERITY_MAIN, 'foo()', 'foo() 自身耗时 780.0ms，占本次请求 43.0%', '自身耗时不含子调用', 780.0),
                new Finding('R4', Finding::SEVERITY_SUPPLEMENT, 'fib', '检测到 fib() 递归，最大深度 6', '递归深度过大', 6.0),
            ],
            ['run' => 'a1a1a1a1a1a1a1a1']
        );

        self::assertStringContainsString('诊断结论', $html);
        self::assertStringContainsString('为什么慢', $html);
        self::assertStringContainsString('其他发现', $html);
        self::assertStringContainsString('foo() 自身耗时', $html);
        self::assertStringContainsString('检测到 fib() 递归', $html);
    }

    /** 空结果必须显式说明，否则用户会以为功能坏了 */
    #[Test]
    public function renderDiagnosisShowsEmptyStateWithThresholds(): void
    {
        $html = XhprofDisplay::render_diagnosis([], []);

        self::assertStringContainsString('未发现明显瓶颈', $html);
        self::assertStringContainsString('10', $html);   // 自身耗时阈值
    }

    /**
     * 标题/细节由 Analyzer 以纯文本产出，渲染时必须 htmlspecialchars。
     * 注意：$f->symbol 本身**只用于拼链接**（走 http_build_query 百分号编码），
     * 不会作为文本渲染，所以转义断言要打在 title 上而不是 symbol 上。
     */
    #[Test]
    public function renderDiagnosisEscapesTitleAndDetail(): void
    {
        $html = XhprofDisplay::render_diagnosis(
            [new Finding('R1', Finding::SEVERITY_MAIN, 'foo()', '标题 "<script>"', '细节 & 更多', 1.0)],
            []
        );

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&quot;', $html);
        self::assertStringContainsString('&amp;', $html);
    }

    /** symbol 里的引号经 http_build_query 编码成 %22，逃不出 href 属性 */
    #[Test]
    public function renderDiagnosisEncodesSymbolInLink(): void
    {
        $html = XhprofDisplay::render_diagnosis(
            [new Finding('R1', Finding::SEVERITY_MAIN, 'x"onmouseover="alert(1)', '标题', '细节', 1.0)],
            []
        );

        self::assertStringContainsString('symbol=x%22onmouseover', $html);
        self::assertStringNotContainsString('"onmouseover="', $html);
    }

    /** 链接必须带上当前 run，否则点进去只看到运行列表 */
    #[Test]
    public function renderDiagnosisLinkKeepsRunParam(): void
    {
        $html = XhprofDisplay::render_diagnosis(
            [new Finding('R1', Finding::SEVERITY_MAIN, 'foo()', '标题', '细节', 1.0)],
            ['run' => 'a1a1a1a1a1a1a1a1']
        );

        self::assertStringContainsString('run=a1a1a1a1a1a1a1a1', $html);
        self::assertStringContainsString('symbol=foo%28%29', $html);
    }
```

- [ ] **Step 2: 运行测试确认失败**

Run: `vendor/bin/phpunit --filter renderDiagnosis`
Expected: FAIL —— `Call to undefined method ...::render_diagnosis()`

- [ ] **Step 3: 实现渲染**

在 `XhprofDisplay` 里加（放在 `print_flat_data` 之前即可）：

```php
  /**
   * 渲染诊断结论卡片。
   *
   * $title / $detail 由 Analyzer 以纯文本产出，HTML 转义**只在这里做一次**——
   * 符号名来自 profile 数据，动态调用（call_user_func、$obj->$method()）
   * 可以让请求影响它，不转义就是反射型 XSS。
   *
   * @param Finding[] $findings
   * @param array     $url_params 当前查询参数，用于生成带 run 的详情页链接
   */
  public static function render_diagnosis(array $findings, array $url_params): string
  {
    $main = array();
    $supplement = array();
    foreach ($findings as $f) {
      if (!$f instanceof Finding) continue;
      if ($f->severity === Finding::SEVERITY_MAIN) {
        $main[] = $f;
      } else {
        $supplement[] = $f;
      }
    }

    $echo_page = '<div class="xp-main"><div class="xp-card">'
      . '<div class="xp-card-title">诊断结论</div>';

    if (!$main && !$supplement) {
      // 空白会让人以为功能坏了，所以显式说明并列出阈值
      $echo_page .= '<p style="padding:12px 20px;color:#666">未发现明显瓶颈'
        . '（阈值：自身耗时 ≥ ' . (Analyzer::SHARE_THRESHOLD * 100) . '%'
        . '、调用次数 ≥ ' . Analyzer::CALL_COUNT_THRESHOLD . '）</p>'
        . '</div></div>';
      return $echo_page;
    }

    if ($main) {
      $echo_page .= '<div class="xp-card-title">为什么慢</div>'
        . '<ul style="list-style:none;margin:0;padding:0">';
      foreach ($main as $f) $echo_page .= XhprofDisplay::diagnosis_item($f, $url_params);
      $echo_page .= '</ul>';
    }
    if ($supplement) {
      $echo_page .= '<div class="xp-card-title">其他发现</div>'
        . '<ul style="list-style:none;margin:0;padding:0">';
      foreach ($supplement as $f) $echo_page .= XhprofDisplay::diagnosis_item($f, $url_params);
      $echo_page .= '</ul>';
    }

    return $echo_page . '</div></div>';
  }

  private static function diagnosis_item(Finding $f, array $url_params): string
  {
    $title = htmlspecialchars($f->title, ENT_QUOTES, 'UTF-8');
    $detail = htmlspecialchars($f->detail, ENT_QUOTES, 'UTF-8');
    $rule = htmlspecialchars($f->rule, ENT_QUOTES, 'UTF-8');

    $link = '';
    if ($f->symbol !== '') {
      $href = XhprofDisplay::base_path() . '?'
        . http_build_query(XhprofLib::xhprof_array_set($url_params, 'symbol', $f->symbol));
      $link = ' ' . XhprofDisplay::xhprof_render_link('查看', $href);
    }

    return '<li style="padding:6px 20px"><b>[' . $rule . ']</b> ' . $title . $link
      . '<br><span style="color:#666;font-size:12px">' . $detail . '</span></li>';
  }
```

并在文件顶部 import 区加：

```php
use ErikWang2013\Xhprof\Core\Analysis\Analyzer;
use ErikWang2013\Xhprof\Core\Analysis\Finding;
```

- [ ] **Step 4: 运行测试确认通过**

Run: `vendor/bin/phpunit --filter renderDiagnosis`
Expected: PASS

- [ ] **Step 5: 提交**

```bash
git add src/Core/XhprofLib/Display/XhprofDisplay.php tests/Unit/Lib/XhprofDisplayTest.php
git commit -m "feat(analysis): render_diagnosis 渲染诊断卡片（转义收口在渲染层）"
```

---

## Task 6: 接入 profiler_report

**Files:**
- Modify: `src/Core/XhprofLib/Display/XhprofDisplay.php:419` 之后
- Test: `tests/Unit/Lib/XhprofDisplayTest.php`

- [ ] **Step 1: 写失败的测试**

```php
    #[Test]
    public function singleRunReportContainsDiagnosisSection(): void
    {
        $runId = 'a1a1a1a1a1a1a1a1';
        $this->useRequest(new FakeRequest(['run' => $runId, 'all' => 1], ['uri' => '/xhprof']));

        $html = XhprofDisplay::profiler_single_run_report(
            ['run' => $runId, 'all' => 1],
            $this->sampleRunData(),
            'desc',
            null,
            'wt',
            $runId
        );

        self::assertStringContainsString('诊断结论', $html);
    }

    /** diff 模式下差值为负，占比类表述失去意义 —— 不显示诊断区 */
    #[Test]
    public function diffReportHasNoDiagnosisSection(): void
    {
        $data = ['main()' => ['ct' => 1, 'wt' => 100000, 'mu' => 100]];
        $html = XhprofDisplay::profiler_diff_report(
            ['run1' => 'r1', 'run2' => 'r2', 'all' => 1],
            $data,
            'd1',
            $data,
            'd2',
            null,
            'wt',
            'r1',
            'r2'
        );

        self::assertStringNotContainsString('诊断结论', $html);
    }

    /** 函数详情页回答的是"这个函数为什么慢"，不是"这次请求为什么慢" */
    #[Test]
    public function symbolReportHasNoDiagnosisSection(): void
    {
        $runId = 'a1a1a1a1a1a1a1a1';
        $this->useRequest(new FakeRequest(['run' => $runId, 'all' => 1, 'symbol' => 'foo()'], ['uri' => '/xhprof']));

        $html = XhprofDisplay::profiler_single_run_report(
            ['run' => $runId, 'all' => 1, 'symbol' => 'foo()'],
            $this->sampleRunData(),
            'desc',
            'foo()',
            'wt',
            $runId
        );

        self::assertStringNotContainsString('诊断结论', $html);
    }
```

- [ ] **Step 2: 运行测试确认失败**

Run: `vendor/bin/phpunit --filter 'DiagnosisSection'`
Expected: FAIL —— 单 run 报告里还没有"诊断结论"

- [ ] **Step 3: 接入**

在 `profiler_report()` 中，`$echo_page .= '<div style="padding:10px 20px 0;...' . '</div>';`（run 描述那一段）之后插入：

```php
    // 诊断只在顶层单 run 视图显示：
    // - diff 模式下差值为负，占比类表述失去意义
    // - 函数详情页回答的是"这个函数为什么慢"，不是"这次请求为什么慢"
    if (!$diff_mode && empty($rep_symbol)) {
      $findings = Analyzer::analyze($symbol_tab, $run1_data, $totals);
      $echo_page .= XhprofDisplay::render_diagnosis($findings, $url_params);
    }
```

- [ ] **Step 4: 运行测试确认通过**

Run: `vendor/bin/phpunit`
Expected: PASS（全量）

- [ ] **Step 5: 提交**

```bash
git add src/Core/XhprofLib/Display/XhprofDisplay.php tests/Unit/Lib/XhprofDisplayTest.php
git commit -m "feat(analysis): 在单 run 报告页接入诊断区（diff/详情页不显示）"
```

---

## Task 7: 回退验证与全量回归

这一步是本项目的既定要求。前几轮出现过"判据写错导致四条测试全假报 PASS"，所以**只写测试不做回退验证不算完成**。

**Files:**
- 只读验证，不改源码（改完必须还原）

- [ ] **Step 1: 逐条规则做回退验证**

对每条规则，把阈值改成"永不触发"（例如 `SHARE_THRESHOLD = 999`），运行对应测试确认**失败**，然后还原。命令模板：

```bash
cp src/Core/Analysis/Analyzer.php /tmp/Analyzer.bak
python3 -c "
p='src/Core/Analysis/Analyzer.php'; s=open(p).read()
s=s.replace('const SHARE_THRESHOLD = 0.10;','const SHARE_THRESHOLD = 999.0;')
open(p,'w').write(s)"
vendor/bin/phpunit --filter r1 2>&1 | grep -qE '^(FAILURES|ERRORS)!' && echo 'FAIL (good)' || echo 'PASS <<< PROBLEM'
cp /tmp/Analyzer.bak src/Core/Analysis/Analyzer.php
```

其余五条同样做法，逐条替换并各自确认转红：

```bash
# R2：把阈值抬到不可能达到
s.replace('const CALL_COUNT_THRESHOLD = 1000;', 'const CALL_COUNT_THRESHOLD = 99999999;')
# R3：把边的次数阈值抬到不可能达到
s.replace('const EDGE_COUNT_THRESHOLD = 500;', 'const EDGE_COUNT_THRESHOLD = 99999999;')
# R5：把内存占比阈值抬到不可能达到
s.replace('const PMU_SHARE_THRESHOLD = 0.30;', 'const PMU_SHARE_THRESHOLD = 999.0;')
# R4：把"至少两个深度"放宽成"至少 999 个深度"，等于永不触发
s.replace('if (count($ds) < 2) {', 'if (count($ds) < 999) {')
# R6：把比较改成恒不成立
s.replace('if ($excl <= $incl) {', 'if (true) {')
```

每条改完都要看到 `FAIL (good)`，然后 `cp /tmp/Analyzer.bak src/Core/Analysis/Analyzer.php` 还原后再改下一条。

**每条都必须看到 `FAIL (good)`。** 任何一条报 `PASS <<< PROBLEM` 就说明该测试没有真正守住这条规则，要修测试。

- [ ] **Step 2: 确认源码已完全还原**

```bash
diff /tmp/Analyzer.bak src/Core/Analysis/Analyzer.php && echo "已还原 ✓"
```

- [ ] **Step 3: 全量测试 + 最高诊断级别**

```bash
vendor/bin/phpunit --fail-on-warning --fail-on-notice --fail-on-deprecation
```
Expected: `OK`，无 failure/error/notice/deprecation

- [ ] **Step 4: 语法与 PHP 8.0 底线**

```bash
for f in $(find src tests -name '*.php'); do php -l "$f" | grep -v "No syntax errors" || true; done
```

`php -l` 在开发机上（PHP 8.3）**抓不到 8.1+ 语法**，只有真正跑 8.0 的 CI lint job 才能。
本地要用 php-parser 按 8.0 语法解析来补：

```bash
php -r '
require "vendor/autoload.php";
$parser = (new PhpParser\ParserFactory())->createForVersion(PhpParser\PhpVersion::fromString("8.0"));
$bad = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("src")) as $f) {
    if ($f->getExtension() !== "php") continue;
    try { $parser->parse(file_get_contents($f->getPathname())); }
    catch (PhpParser\Error $e) { $bad++; echo "8.0 语法失败: ", $f->getPathname(), " -> ", $e->getMessage(), "\n"; }
}
echo $bad === 0 ? "全部通过 PHP 8.0 语法检查\n" : "$bad 个文件不符合 8.0\n";
'
```

（`nikic/php-parser` 是 phpunit 的传递依赖，已在 vendor 里，不需要新增声明。）

- [ ] **Step 5: 把新类加进 CI 的 PHP 8.0/8.1 加载检查**

`.github/workflows/ci.yml` 的 smoke job 里有一份硬编码的类清单，用于在 8.0/8.1 上确认
交付的类真的能加载。新增的 `Core\Analysis\Analyzer` 与 `Core\Analysis\Finding` 必须补进去，
否则它们在底线上是否可加载无人验证。

在 smoke job 的 `$classes` 数组里，紧随 `"Core\\StaticController",` 之后插入两行：

```php
            "Core\\Analysis\\Analyzer",
            "Core\\Analysis\\Finding",
```

改完本地跑一遍 smoke 的第三步确认不报 `cannot load`：

```bash
php -r '
require "vendor/autoload.php";
$ns = "ErikWang2013\\Xhprof\\";
foreach (["Core\\Analysis\\Analyzer","Core\\Analysis\\Finding"] as $c) {
  if (!class_exists($ns.$c)) { fwrite(STDERR, "cannot load {$ns}{$c}\n"); exit(1); }
}
echo "analysis classes load ok\n";'
```

- [ ] **Step 6: 真实数据目视验证**

用真实扩展跑一次，确认诊断区在真实 profile 上给出合理结论（不是空表也不是满屏噪声）：

```bash
php -r '
require "vendor/autoload.php";
require "tests/Fixtures/Fakes.php";
require "tests/Stubs/framework-stubs.php";
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofLib\Display\XhprofDisplay as D;
use ErikWang2013\Xhprof\Tests\Fixtures\{FakeCache, FakeConfig, FakeLogger, FakeRequest, FakeResponse};
function slow(int $n): int { $s = 0; for ($i = 0; $i < $n; $i++) { $s += strlen("x$i"); } return $s; }
xhprof_enable(XHPROF_FLAGS_NO_BUILTINS + XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY);
slow(30000);
$data = xhprof_disable();
Xhprof::bootstrap(new FakeRequest([], ["uri" => "/xhprof"]), new FakeResponse(), new FakeConfig([]), new FakeCache(), new FakeLogger());
$html = D::displayXHProfReport(["all" => 1], "xhprof_foo", null, null, null, null, null, null) ;
echo "报告生成成功，长度 ", strlen($html), "\n";
'
```

再手动构造一个 symbol_tab 喂给 `Analyzer::analyze()`，打印 findings，确认输出人类可读。

- [ ] **Step 7: 提交（若 Step 1 过程中修了测试，或在 Step 5 动了 ci.yml）**

```bash
git add -A
git commit -m "test(analysis): 回退验证补齐"
```

---

## Self-Review

**Spec coverage：**

| spec 章节 | 对应任务 |
|---|---|
| 目录结构（Finding / Analyzer） | Task 1 |
| R1 / R2 / R3 | Task 2 |
| R4 / R5 / R6 | Task 3 |
| 主区组装（R1→R3→R2）与封顶 | Task 4 |
| 渲染（卡片、小节、链接、空态、转义） | Task 5 |
| 数据流与 diff 守卫 | Task 6 |
| 错误处理表 | Task 1（骨架）+ 各规则的 `is_array`/`is_numeric` 守卫 |
| 测试与回退验证 | Task 1-6 的测试步骤 + Task 7 |
| 依赖（不新增） | 全部任务均未引入依赖 |

**已知偏离 spec 之处**（均在设计阶段确认，见文首）：`render_diagnosis` 多一个 `$url_params` 参数；增加 `empty($rep_symbol)` 守卫；title/detail 存纯文本。

**命名一致性检查：**`Finding` 的字段 `rule/severity/symbol/title/detail/score`、常量 `SEVERITY_MAIN`/`SEVERITY_SUPPLEMENT`；`Analyzer` 的 `SHARE_THRESHOLD`/`CALL_COUNT_THRESHOLD`/`EDGE_COUNT_THRESHOLD`/`EDGE_SHARE_THRESHOLD`/`PMU_SHARE_THRESHOLD`/`MAIN_LIMIT`/`SUPPLEMENT_LIMIT`；辅助方法 `num()`/`ms()`/`pct()`/`bytes()`。全部在 Task 2、3、4、5 中一致引用，无别名。

**YAGNI 检查：**未引入 `RuleInterface`、未加配置项、未加 CSS 文件、未做跨请求聚合、未做 diff 模式诊断。
