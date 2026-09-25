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
    /** 入口守卫保证：symbol_tab 不可用时返回空数组 */
    #[Test]
    #[DataProvider('unusableSymbolTabProvider')]
    public function analyzeReturnsEmptyWhenSymbolTabUnusable(mixed $symbolTab): void
    {
        $this->assertSame([], Analyzer::analyze($symbolTab, [], []));
    }

    public static function unusableSymbolTabProvider(): array
    {
        return ['全空数组' => [[]], 'null 入参' => [null], '字符串入参' => ['x']];
    }

    /**
     * 契约是"永不抛异常 / 返回值是数组"，**不是**"返回空数组"。
     *
     * 这三行的 symbol_tab 是合法的，只有 raw_data/totals 畸形。断言"空"就等于在断言
     * "没有其他规则命中"——Task 3 的 R4/R5/R6 只要有一条能从 symbol_tab 单独出结论，
     * 这里就会无故变红，而最顺手的应对是把断言改弱，等于废掉整条契约测试。
     */
    #[Test]
    #[DataProvider('malformedOtherInputProvider')]
    public function analyzeNeverThrowsWhenOtherInputMalformed(mixed $rawData, mixed $totals): void
    {
        $tab = ['main()' => ['ct' => 1, 'wt' => 100, 'excl_wt' => 0]];
        $this->assertIsArray(Analyzer::analyze($tab, $rawData, $totals));
    }

    public static function malformedOtherInputProvider(): array
    {
        return [
            // 人工构造：真实路径不会产生这个组合 —— get_run 失败（false）时
            // flat_info 返回的是**空** symbol_tab，analyze() 会在入口守卫处就返回。
            // 保留它是因为它守护"归一化 + safe() 这层兜底仍然存在"：
            // 单独去掉归一化测试**仍是绿的**（TypeError 被 safe() 吞掉），
            // 两者同时去掉才会红 —— 所以不要为它写"只去归一化"的回退验证。
            '人工构造：symbol_tab 非空 + raw_data 为 false' => [false, ['wt' => 100]],
            'totals 缺 wt'    => [[], []],
            'totals wt 为 0'  => [[], ['wt' => 0]],
        ];
    }

    /**
     * analyze() 的公共契约是返回 **Finding 列表**。规则体里一句 `$out[] = $h;`
     * 就能满足自身的 `: array` 返回类型，而 analyze() 只有 docblock 声明 Finding[]，
     * 于是错误要等到渲染层去读属性时才爆——必须在这里钉住。
     */
    #[Test]
    public function analyzeReturnsOnlyFindings(): void
    {
        $tab = [
            'main()' => self::sym(1000, 1000, 900),   // R1(90%) + R2(1000 次)
            'foo()'  => self::sym(600, 600, 900),     // R3 的被调方(90%)
        ];
        $raw = [
            'main()'         => ['ct' => 1, 'wt' => 1000],
            'main()==>foo()' => ['ct' => 600, 'wt' => 400],
        ];
        $found = Analyzer::analyze($tab, $raw, ['wt' => 1000, 'pmu' => 500]);

        // 没有这一句，空结果会让本测试静默通过、什么也没钉住
        $this->assertNotEmpty($found, '夹具必须让现有规则都产出，否则本测试形同虚设');
        foreach ($found as $f) {
            $this->assertInstanceOf(Finding::class, $f);
        }
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

    /** 规则内部抛异常必须被隔离成空结果，不能冒泡到报告页 */
    #[Test]
    public function safeIsolatesRuleExceptions(): void
    {
        $m = new \ReflectionMethod(Analyzer::class, 'safe');
        $m->setAccessible(true);

        $result = $m->invoke(null, static function (): array {
            throw new \RuntimeException('rule blew up');
        });

        $this->assertSame([], $result);
    }

    /** 正常返回的规则不受影响 */
    #[Test]
    public function safePassesThroughNormalResult(): void
    {
        $m = new \ReflectionMethod(Analyzer::class, 'safe');
        $m->setAccessible(true);

        $f = new Finding('R1', Finding::SEVERITY_MAIN, 'foo()', 't', 'd', 1.0);
        $this->assertSame([$f], $m->invoke(null, static fn(): array => [$f]));
    }
}
```

> 这两条用反射调私有方法，脆弱但改名时会显式抛 `ReflectionException`（不是静默通过），
> 且在规则尚未存在时是唯一能验证 `safe()` 的手段。保留。

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

    /**
     * 单条规则的异常隔离。
     *
     * 输入守卫只能覆盖预想到的数据形态；规则内部的 bug（拼错数组键、
     * 意外的数值类型等）仍会逃逸。诊断是旁路，它的失败模式不该是
     * 整个报告页 500 —— 所以让规则各自失败，坏掉的那条产出空结果，
     * 其余规则照常。
     */
    private static function safe(callable $rule): array
    {
        try {
            return $rule();
        } catch (\Throwable $e) {
            return array();
        }
    }
}
```

> `safe()` 的日志版本见 Task 2 的 Step 3b——Task 1 先建立隔离本身。

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

在 `AnalyzerTest` 中追加（`use ErikWang2013\Xhprof\Core\Xhprof;` 与 `use ErikWang2013\Xhprof\Core\XhprofLib\Utils\XhprofLib;` 两条都要追加到 `Analyzer.php` 的 import 区）：

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

    /** 精确断言整条标题：能一次杀掉「格式化乘错系数」「单位错」两类变异 */
    #[Test]
    public function r1TitleIsExact(): void
    {
        $tab = ['main()' => self::sym(1, 1000, 100)];
        $hits = self::rule(Analyzer::analyze($tab, [], ['wt' => 1000]), 'R1');
        $this->assertSame('main() 自身耗时 0.1ms，占本次请求 10.0%', $hits[0]->title);
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

    /** 按规则过滤：只断言自己这条规则，新增规则不会波及本测试 */
    private static function rule(array $findings, string $rule): array
    {
        return array_values(array_filter($findings, fn($f) => $f->rule === $rule));
    }

    /** 边调用次数恰好 500 → 触发（语义是 >=） */
    #[Test]
    public function r3FiresAtExactlyEdgeCountThreshold(): void
    {
        $tab = ['main()' => self::sym(1, 1000, 100), 'foo()' => self::sym(500, 600, 100)];
        $raw = ['main()==>foo()' => ['ct' => 500, 'wt' => 400]];
        $this->assertCount(1, self::rule(Analyzer::analyze($tab, $raw, ['wt' => 1000]), 'R3'));
    }

    #[Test]
    public function r3DoesNotFireAt499Calls(): void
    {
        $tab = ['main()' => self::sym(1, 1000, 100), 'foo()' => self::sym(499, 600, 100)];
        $raw = ['main()==>foo()' => ['ct' => 499, 'wt' => 400]];
        $this->assertSame([], self::rule(Analyzer::analyze($tab, $raw, ['wt' => 1000]), 'R3'));
    }

    /** 被调方自身耗时恰好占 5% → 触发（50/1000 与字面量 0.05 是同一个 double，边界精确） */
    #[Test]
    public function r3FiresAtExactlyShareThreshold(): void
    {
        $tab = ['main()' => self::sym(1, 1000, 100), 'foo()' => self::sym(600, 600, 50)];
        $raw = ['main()==>foo()' => ['ct' => 600, 'wt' => 400]];
        $this->assertCount(1, self::rule(Analyzer::analyze($tab, $raw, ['wt' => 1000]), 'R3'));
    }

    /** 子自身耗时 49/1000 = 4.9% < 5%：即使边调用 600 次也不触发 */
    #[Test]
    public function r3DoesNotFireWhenChildShareBelowThreshold(): void
    {
        $tab = ['main()' => self::sym(1, 1000, 100), 'foo()' => self::sym(600, 600, 49)];
        $raw = ['main()==>foo()' => ['ct' => 600, 'wt' => 400]];
        $this->assertSame([], self::rule(Analyzer::analyze($tab, $raw, ['wt' => 1000]), 'R3'));
    }

    /** 多条边按边耗时降序——R3 队首决定 Task 4 截断后谁留下，是用户可见行为 */
    #[Test]
    public function r3SortsByEdgeWallTimeDescending(): void
    {
        $tab = ['main()' => self::sym(1, 1000, 100),
                'a()'    => self::sym(600, 600, 100),
                'b()'    => self::sym(600, 600, 100)];
        $raw = ['main()==>a()' => ['ct' => 600, 'wt' => 100],
                'main()==>b()' => ['ct' => 600, 'wt' => 900]];
        $hits = self::rule(Analyzer::analyze($tab, $raw, ['wt' => 1000]), 'R3');

        $this->assertCount(2, $hits);
        $this->assertStringContainsString('main() → b()', $hits[0]->title);
        $this->assertSame(900.0, $hits[0]->score);
        $this->assertSame(100.0, $hits[1]->score);
    }

    /** 裸 main() 键没有父；"==>main()" 形式同样没有父（XhprofLib 会归一化成这个形态） */
    #[Test]
    #[DataProvider('parentlessKeyProvider')]
    public function r3SkipsParentlessKeys(string $key): void
    {
        $tab = ['main()' => self::sym(1, 1000, 100)];
        $raw = [$key => ['ct' => 9999, 'wt' => 900]];
        $this->assertSame([], self::rule(Analyzer::analyze($tab, $raw, ['wt' => 1000]), 'R3'));
    }

    public static function parentlessKeyProvider(): array
    {
        return ['裸 main() 键' => ['main()'], '==>main() 形式' => ['==>main()']];
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

        // 顺序是**刻意**的：R2 排在最后，会被主区组装按 MAIN_LIMIT 从尾部截断丢掉
        // （R1 是头部归因，R3 给出可操作调用关系，R2 只是兜底信号）。不要"顺手"排成 R1/R2/R3。
        //
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
        // is_finite 必须有：is_numeric(NAN) 为真、NAN <= 0 为假，会让每个占比都成 NAN，
        // 而 NAN < 阈值 恒为假 —— 最终渲染出"占本次请求 nan%"。
        if (!is_finite($total) || $total <= 0) {
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
                sprintf('%s 自身耗时 %s，占本次请求 %s', $h[1], self::ms($h[0]), self::pct($h[2])),
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
        // is_finite 必须有：is_numeric(NAN) 为真、NAN <= 0 为假，会让每个占比都成 NAN，
        // 而 NAN < 阈值 恒为假 —— 最终渲染出"占本次请求 nan%"。
        if (!is_finite($total) || $total <= 0) {
            return array();
        }

        $hits = array();
        foreach ($raw_data as $edge => $info) {
            if (!is_array($info)) {
                continue;
            }
            // 先过闸门再解析：被闸门滤掉的边就不必解析键。两个判断相互独立，无语义变化。
            //
            // 收益来自闸门**有选择性**，不是来自它"便宜"——实测闸门
            // (isset+is_numeric+(float)) 与 explode 花费相当（0.84-0.96x）。
            // 记每条边闸门 g、解析 p、被滤比例 f，比值 = (p+g) / (g + p·(1-f))：
            //   f→1（边基本都被滤掉）时上限 1 + p/g ≈ 2x
            //   f→0（边大多能过闸门）时趋近 1x，等于白重排
            // 所以不要为这条优化预算 2x 以上的收益。
            $ct = isset($info['ct']) && is_numeric($info['ct']) ? (float) $info['ct'] : 0.0;
            if ($ct < self::EDGE_COUNT_THRESHOLD) {
                continue;
            }
            // 必须 (string)：PHP 会把 "123" 这类数组键转成 int，整型传给
            // xhprof_parse_parent_child 内部的 explode() 会抛 TypeError。
            // safe() 的粒度是整条规则，一个坏键会连带丢掉 R3 的全部有效结论。
            list($parent, $child) = XhprofLib::xhprof_parse_parent_child((string) $edge);
            if ($parent === null || $parent === '') {
                continue;   // 裸 main() 键没有父，不是边
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

    /** 从 totals 取数值：缺失/非数值一律当 0，其余原样返回（含负数） */
    private static function num(array $totals, string $key): float
    {
        return isset($totals[$key]) && is_numeric($totals[$key]) ? (float) $totals[$key] : 0.0;
    }

    /** 微秒 → 毫秒，保留 1 位小数 */
    private static function ms(float $us): string
    {
        return number_format($us / 1000, 1) . 'ms';
    }

    /** 比率 → 百分数串（含 % 号，与 ms() 一样自带单位） */
    private static function pct(float $ratio): string
    {
        return number_format($ratio * 100, 1) . '%';
    }
```

- [ ] **Step 4: 运行测试确认通过**

Run: `vendor/bin/phpunit --filter AnalyzerTest`
Expected: PASS

- [ ] **Step 3b: 给 `safe()` 加上错误日志**

`safe()` 目前静默吞掉异常：规则坏了却永远无人知晓，报告页只会一直显示"没发现问题"。
**旁路不等于无信号**——代码库对同类情况的既有写法是
`src/Core/XhprofProfiler.php:28`：`Xhprof::getLogger()?->error('Xhprof save_run failed: ' . $e->getMessage());`。
沿用同一写法（logger 未配置时 `?->` 使其成为 no-op）：

```php
    private static function safe(callable $rule): array
    {
        try {
            return $rule();
        } catch (\Throwable $e) {
            // 静默失败 = 规则坏了却永远无人知晓，报告页只会永远显示"没发现问题"。
            // 旁路不等于无信号：沿用 XhprofProfiler::stop() 对同类情况的既有写法，
            // logger 未配置时 ?-> 使其成为 no-op。
            Xhprof::getLogger()?->error(
                'Analyzer rule failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
            );
            return array();
        }
    }
```

验证它不是死代码：用 `FakeLogger` 之外的一个"间谍 logger"（或直接断言 `FakeLogger::$errors`
非空）喂一条必定抛异常的 callable，确认日志里出现了 `Analyzer rule failed`，而返回值仍是 `[]`。
`safeIsolatesRuleExceptions` / `safePassesThroughNormalResult` 应当照常通过（无 logger 时是 no-op）。

**关于入口归一化的可验证性（结论：单独去掉不会红，别再试这一种）：** 只去掉
`$raw_data = is_array($raw_data) ? $raw_data : array();` 时测试**仍然是绿的** ——
规则签名是 `array $raw_data`，strict_types 下传 false 在调用边界抛 TypeError，
被 `safe()` 兜成空结果。**归一化与 safe() 是同一层兜底的两半，冗余但不重复**：
只有把两者**同时**去掉才会红（`Tests: 3, Errors: 1`，
`TypeError: ruleR3(): Argument #2 ($raw_data) must be of type array, false given`）。
保留这两行是因为它们把输入契约定在入口、而不是寄托于异常吞没；
但不要写"只去归一化"的回退验证，也不要声称有测试单独守护它。

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
        $hits = self::rule(Analyzer::analyze($tab, $raw, ['wt' => 100]), 'R4');

        $this->assertCount(1, $hits);
        // 精确断言，不用 containsString：'3' 这种短串到处都是，弱断言放过错误实现
        $this->assertSame('检测到 fib() 递归，最大深度 3', $hits[0]->title);
        $this->assertSame(3.0, $hits[0]->score, 'score 应是最大深度');
        $this->assertSame('', $hits[0]->symbol, 'R4 的符号必须为空，否则详情页链接必然死链');
    }

    /** 同名只出现在一个深度 → 不是递归 */
    #[Test]
    public function r4IgnoresSingleDepth(): void
    {
        $raw = ['main()==>foo@1' => ['ct' => 1, 'wt' => 10]];
        $tab = ['main()' => self::sym(1, 100, 10)];
        $this->assertSame([], self::rule(Analyzer::analyze($tab, $raw, ['wt' => 100]), 'R4'));
    }

    /** a@1==>b@2 是两个不同名字，不得判为递归 */
    #[Test]
    public function r4DoesNotConfuseDifferentNames(): void
    {
        $raw = ['a@1==>b@2' => ['ct' => 1, 'wt' => 10]];
        $tab = ['main()' => self::sym(1, 100, 10)];
        $this->assertSame([], self::rule(Analyzer::analyze($tab, $raw, ['wt' => 100]), 'R4'));
    }

    #[Test]
    public function r5FiresAtPmuShare(): void
    {
        // 30/100 = 30%，恰好等于 PMU_SHARE_THRESHOLD 的边界
        $tab = ['hog()' => self::sym(1, 10, 1, 30)];
        $hits = self::rule(Analyzer::analyze($tab, [], ['wt' => 100, 'pmu' => 100]), 'R5');

        $this->assertCount(1, $hits);
        $this->assertSame('hog()', $hits[0]->symbol);
        $this->assertSame('hog() 内存峰值 30B，占全局 30.0%', $hits[0]->title);
        $this->assertSame(30.0, $hits[0]->score);
    }

    #[Test]
    public function r5SkippedWhenPmuTotalIsZero(): void
    {
        $tab = ['hog()' => self::sym(1, 10, 1, 30)];
        $this->assertSame([], self::rule(Analyzer::analyze($tab, [], ['wt' => 100, 'pmu' => 0]), 'R5'));
    }

    /** R6 探针：excl_wt > wt 逻辑上不可能，健康数据下永不触发 */
    #[Test]
    public function r6FiresWhenExclusiveExceedsInclusive(): void
    {
        $tab = ['bad()' => ['ct' => 1, 'wt' => 180, 'excl_wt' => 210, 'pmu' => 0, 'excl_pmu' => 0]];
        $hits = self::rule(Analyzer::analyze($tab, [], ['wt' => 1000]), 'R6');

        $this->assertCount(1, $hits);
        $this->assertSame('bad()', $hits[0]->symbol);
        $this->assertSame('bad() 自身耗时 210μs 大于其总耗时 180μs，差 30μs', $hits[0]->title);
        $this->assertSame(30.0, $hits[0]->score, 'score 应是差值');
    }

    #[Test]
    public function r6DoesNotFireWhenEqual(): void
    {
        $tab = ['ok()' => ['ct' => 1, 'wt' => 200, 'excl_wt' => 200, 'pmu' => 0, 'excl_pmu' => 0]];
        $this->assertSame([], self::rule(Analyzer::analyze($tab, [], ['wt' => 1000]), 'R6'));
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
        if (!is_finite($total) || $total <= 0) {
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
                sprintf('%s 内存峰值 %s，占全局 %s', $h[1], self::bytes($h[0]), self::pct($h[2])),
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
                // 必须用微秒而不是 ms()：R6 抓的是亚毫秒级倒挂，
                // 而 ms() 保留 1 位小数会把 210µs 与 180µs 都变成 0.2ms，
                // 标题就成了自相矛盾的「0.2ms 大于 0.2ms」。
                sprintf(
                    '%s 自身耗时 %s 大于其总耗时 %s，差 %s',
                    $h[1],
                    number_format($h[2]) . 'μs',
                    number_format($h[3]) . 'μs',
                    number_format($h[0]) . 'μs'
                ),
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


> **已知且**有意保留**的不对称**：`totals` 的 `wt`/`pmu` 用 `!is_finite($total) || $total <= 0`，
> 而**逐项**指标（`symbol_tab`/`raw_data` 里的 `excl_wt`/`ct`/`wt`）只用 `is_numeric`。
> 因此 NAN 若出现在逐项数据里（`is_numeric(NAN)` 为真）会渲染出 `nanms`/`nan%`。
> 不修的理由：xhprof 的逐项指标来自 `microtime` 差值与 `memory_get_usage()` 计数，
> **NAN 不可达**；而 spec 的逐项守卫字面就是 `is_numeric`。给 6 条规则每条都加 `is_finite`
> 是为不可达输入付真实复杂度。若将来接入聚合（第二步）产生了除法，再统一收紧。

> **IR-2：新规则的两条硬性纪律。** 已实测（不是推测）：**已记录但不修的性能项（#9）**：R4 是唯一对每条边键都做 `preg_match` 而无前置闸门的规则。
实测 2 万条边 / 2 万个符号时 R4 单独 63–87ms（同机 R3 有闸门，39ms）；加一个
`strpos($edge, '@') === false` 前置闸门约 3.1x（68 → 22ms）。
**不修**：典型 profile 只有几千条边，对应单位数毫秒，不构成瓶颈；而收益同样随
"含 @ 的边占比"变化。将来若确实要加，两行、可证等价（能匹配该正则的符号必然含 `@`），
顺带还能去掉一次重复的 `(string)` 转换。

**Task 3 质量审查的变异结果：37 个变异中 25 个被杀死**（Task 2 基线是 18 中 10 存活）。
存活项里两条已确认为**真实数据可达、用户可见**的夹具缺口：

1. **R4 的正则在下限之外没有夹具**：`/^(.+)@(\d+)$/` 改成 `/^(.+)@(\d)$/` 能存活整套测试，
   因为没有任何夹具用到**两位数深度**。用真实扩展实测 `fib(16)` → 深度 **1..15**，
   即两位数深度是真实数据；变异后 `fib@10`/`fib@15` 完全不匹配，真实递归超过 9 层
   会被静默报成「最大深度 9」。修法：`r4DetectsRecursionAndReportsMaxDepth` 的夹具
   加一条 `fib@9==>fib@10`，断言最大深度 10。
2. **R4 的 `(string) $edge` 转换没有夹具，而 R3 的同一防护有**：删掉它同样存活。
   整型边键（`json_decode` 会把 `"0"` 变成 int）会让 `explode()` 抛 TypeError，
   被 `safe()` 吞掉后 **R4 返回 0 条**（原本 1 条）外加一条日志。修法：给 R4 夹具加一个
   `0 => [...]` 坏键，断言有效结论不丢——与 R3 的 `r3SurvivesIntegerKeyAmongValidEdges` 对称。

> **更正：早先对 F6 的"已加固"结论只对了一半（本会话自查发现）。**
>
> 早先我把 `xhprof_compute_inclusive_times` 的 `return;` 改成 `return array();` 记为
> "一个字符的防御性加固，当前不可达"。**实测证明它没有达成表面目的**：
> 该函数确实返回 `array()` 了，但下游 `xhprof_compute_flat_info` 立刻在
> `$symbol_tab["main()"][$metric]` 上取到 null，于是 `totals['wt'] = NULL`，
> `full_report()` 死于：
> ```
> TypeError: number_format(): Argument #1 ($num) must be of type int|float, null given
> ```
> **同一个输入仍然 500，只是晚了一行。** 也就是说那处改动改变了失败形态、没有消除失败。
>
> **输入仍不可达**（xhprof 2.3.10 把递归编码为 `fib@1==>fib@2`，永不产生 `foo()==>foo()`，
> 已用真实扩展验证），故**不修**：这是 Task 6 之前的既有行为、与本特性无关、
> 且要真修需在 `xhprof_compute_flat_info` 里加一行 `?? []` 并单独验证一遍。
> 记录在此以免后来人以为"已经加固过了"。

**Task 3 实测补充（实现者验证，非推测）：**
- **IR-2 的守卫纪律已用探针证实**：6 条规则 × 恶意输入（缺键、null、bool、数组当值、
  `'x'`、整型键、字符串项）→ **0 warning / 2 findings**。即"框架把 warning 提升为异常
  后 `safe()` 静默吞掉整条规则"这条路是关死的。
- **R5（及 R1/R3）的 `is_finite` 守卫并非"行为冗余"——此前这里写错了。**
  有人只测了 `totals['pmu'] = 0` 就断言删掉守卫结果相同（`DivisionByZeroError` 被
  `safe()` 吞掉 → 同样返回 `[]`），我未核对即采信。逐分支实测后**结论相反**：

  | `totals['pmu']` | 无守卫时的结果 | 与有守卫相同？ |
  |---|---|---|
  | `0` | `DivisionByZeroError` → `safe()` → `[]` | 相同 |
  | 负数 | `share < 0`，不触发 → `[]` | 相同 |
  | `INF` | `share = 0`，不触发 → `[]` | 相同 |
  | **`NAN`** | **`NAN < 阈值` 为假 → 触发 → 标题渲染 `占全局 nan%`** | **不同** |

  `is_finite` 正是为 NAN 分支而存在的。**必须为 `totals = NAN` 写测试**（R1/R3/R5 各一条），
  否则删掉守卫后全套仍绿，而报告页会出现 `nan%`。
  教训：**"行为冗余"这类论断必须在行为真正不同的那个分支上验证**，不能在最容易
  推理的分支上验证后推广。
- **`r1SortsByExclusiveTimeDescending` 的夹具已被修正**：原夹具 `sym(1, 100, 200/300/500)`
  是 `excl_wt > wt` 的**不可能数据**，R6 命中它是正确行为——夹具本身有 bug。
  现改为 `sym(1, 1000, ...)`（数据合理），断言一字未动。**该测试断言的是全部结论**，
  所以将来若有新规则命中这个夹具，同样要**修夹具让它更真实**，不要把断言收窄成
  `self::rule(...)`。

> **IR-2：新规则的两条硬性纪律。** 已实测（不是推测）：
> - **每个指标读都必须走 `isset()` + `is_numeric()`**。漏掉不会得到"错误的结论"——
>   `(float) null === 0.0` 过不了任何**正向**闸门，所以结果是"该规则静默产出空"。
>   但在 Laravel 这类把 `E_WARNING` 提升为 `ErrorException` 的框架里，`safe()` 会捕获它，
>   于是**整条规则的结论全部消失且无迹可寻**。`safe()` 的日志（见 Task 2 Step 3b）正是
>   让这种情况可被发现的东西。
> - **每条规则的闸门必须是"正向阈值"**（要求值大于某个正数）。这是"缺指标 → 无结论"
>   而非"错结论"的保证；一条无条件产出的规则没有这层保护。


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
        // wt 必须 >= excl_wt：写成 sym(1, 100, 400) 会（正确地）触发 R6，
        // 审查者实测输出为 [R1],[R1],[R1],[R2],[R6],[R6],[R6] —— 断言虽仍通过
        // （它们按 severity 过滤），但与本测试文件自己确立的约定矛盾。
        $tab = [
            'a()' => self::sym(1, 1000, 400),
            'b()' => self::sym(1, 1000, 300),
            'c()' => self::sym(1, 1000, 200),
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

    /**
     * 主区按 symbol 去重。R3 是**逐边**产出的：同一个热点函数被多个父函数调用时
     * 会产生多条同 symbol 结论，不去重时 MAIN_LIMIT=3 会被同一个函数占满，
     * 「前三条」退化成「一个函数三遍」，把 R1/R2 的结论全部挤掉。
     */
    #[Test]
    public function mainSectionDedupesRepeatedSymbols(): void
    {
        $tab = [
            'main()' => self::sym(1, 1000, 100),
            'hot()'  => self::sym(3000, 900, 900),
        ];
        $raw = [
            'main()' => ['ct' => 1, 'wt' => 1000],
            'a()==>hot()' => ['ct' => 1200, 'wt' => 500],
            'b()==>hot()' => ['ct' => 1000, 'wt' => 300],
            'c()==>hot()' => ['ct' => 800, 'wt' => 200],
        ];
        $found = Analyzer::analyze($tab, $raw, ['wt' => 1000]);
        $main = array_values(array_filter($found, fn($f) => $f->severity === Finding::SEVERITY_MAIN));

        $symbols = array_map(fn($f) => $f->symbol, $main);
        $this->assertSame(array_unique($symbols), $symbols, '主区不得出现重复 symbol');
        $this->assertCount(1, array_filter($symbols, fn($s) => $s === 'hot()'), '同 symbol 只保留最严重的一条');
    }
```

- [ ] **Step 2: 运行测试确认失败**

Run: `vendor/bin/phpunit --filter AnalyzerTest`
Expected: FAIL —— 封顶与顺序断言不成立

- [ ] **Step 3: 加封顶**

`analyze()` 里组装后切片：

```php
        // 先按 symbol 去重再切片：R3 逐边产出，同一个热点函数被多个父函数调用时
        // 会有多条同 symbol 结论；先切片的话 MAIN_LIMIT 会被一个函数占满。
        // 各规则内已按 score 降序，故每组保留第一条即最严重的那条。
        $seen = array();
        $deduped = array();
        foreach ($main as $f) {
            if (isset($seen[$f->symbol])) {
                continue;
            }
            $seen[$f->symbol] = true;
            $deduped[] = $f;
        }

        $main       = array_slice($deduped, 0, self::MAIN_LIMIT);
        $supplement = array_slice($supplement, 0, self::SUPPLEMENT_LIMIT);

        return array_merge($main, $supplement);
```

**为什么去重必须先于切片**：R3 对每条符合条件的边各产出一条结论，热点函数若被 N 个
父函数调用就有 N 条同 symbol 结论。`MAIN_LIMIT = 3` 时，不去重会让"前三条"变成
"同一个函数三遍"，同时把 R1/R2 全部挤掉（已用三条指向同一 `hot()` 的边实测：
R3 单独产出 3 条，主区被它占满）。去重后主区语义是**前 3 个问题函数**。

代价：同一函数若同时命中 R1 与 R3，只保留先到的 R1（"自身耗时占比"信息量更大），
R3 的具体调用方细节不再展示。这是刻意的取舍。

**另注（刻意设计，不是疏漏）**：合并顺序是 R1 → R3 → R2 且切片从头取，所以
**R2 是结构上最先被切掉的**。R1 是头部归因，R3 给出可操作的调用关系，
R2 只是"调用次数偏高"的兜底信号。

- [ ] **Step 4: 运行测试确认通过**

Run: `vendor/bin/phpunit --filter AnalyzerTest`
Expected: PASS

- [ ] **Step 5: 提交**

```bash
git add src/Core/Analysis/Analyzer.php tests/Unit/Core/Analysis/AnalyzerTest.php
git commit -m "feat(analysis): 主区 R1→R3→R2 组装与两区封顶"
```

> **关于"要不要重跑早先几轮的变异结论"——不需要，理由是失效方向单向。**
>
> Task 5 的 spec 审查发现它自己第一版 harness 把一条变异**空转**了（两次链式 `replace`
> 互相抵消，文件字节不变），并把结果谎报成"存活"。它据此建议重跑本特性此前的存活清单。
>
> **结论是不必**：变异空转 ⇒ 文件与原文件字节相同 ⇒ 测试结果与原始完全一致。
> 所以空转**只能把"其实被杀死"谎报成"存活"**，不可能反向。
> - 早先的存活清单可能有**假阳性**（多算）→ 代价是多补几条测试，无害；
> - 不可能有**假阴性** → 不会因此漏掉真正无人守的行为。
>
> 而且每轮补的测试随后都做了**逐条回退验证**（改回错误实现 → 确认变红 → 还原 → md5 比对），
> "补的测试确实能抓住东西"是独立于存活清单验证过的。
>
> **已确认的失效模式（第三次出现，建议 Task 6/7 换方法）：断言所在的测试只覆盖了
> `if`/`else` 多路径中的**一条**。**三次实例：
> - 补充区 `usort` 无人守 —— 排序测试的夹具只有主区结论；
> - 空态阈值 `'10'` 空转 —— 由相邻的 `'1000'` 满足；
> - 卡片收尾 `</div></div>` —— `renderDiagnosisEmitsCardWrapper` 只用非空列表调用渲染器，
>   **永远到不了空态那个 `return`**。
>
> **建议 Task 6/7 的审查换用"枚举决策点"而非"枚举行为"**：
> 对新方法逐行列出每个 `if`/`else`、每个 `return`、每个守卫，
> 然后问**"哪个变异能证明这一行有存在必要？"**。
> 理由很直接：**行为是测试命名所依据的东西，而命名恰恰是掩盖缺口的地方**——
> 一条测试叫 `…PreservesInputOrderWithoutSortingByScore` 读起来像覆盖了整个渲染器，
> 实际只覆盖了主区。廉价做法：`grep -n 'return\|if ('` 扫一遍新方法。
>
> **harness 判定应自报**：渲染差异为 0 行的变异报 **`PROBE BLIND`**（探针夹具没覆盖该路径），
> 而不是 `KILLED`——否则要靠人去读差异行数才能分辨，而那正是出错的环节。

> **但要沿用的守卫是三条**（写进 Task 6/7；第三条由 Task 5 实现者补出）：
> 1. 变异前断言搜索模式在原始文件中**恰好出现一次**；
> 2. 变异后断言源文件**确实与 HEAD 不同**（挡链式 `replace` 互相抵消）；
> 3. **渲染差异非空**——但注意 0 行差异有**两种**成因：变异是空转（说明 2 能挡），
>    **或者探针夹具没有覆盖被变异的那条路径**（说明 2 挡不住）。
>    后者是 Task 5 实测到的：探针夹具原本只用 `R1`/`R2`/`R4` 这种纯 ASCII 规则名，
>    所以去掉 `$rule` 的 `htmlspecialchars` 会产生 **0 行差异**——变异是活的、测试会红，
>    但差异证据什么都不显示，看起来与"存活"无异。
>    **因此探针夹具必须覆盖每条被变异的路径**，且 harness 应**断言**差异行数非零。

> **Task 6 的两条布局事项（Task 5 实现者提出，我已核实）。**
>
> 1. **`.xp-main` 不会嵌套——无需处理。** 实现者担心卡片自带 `<div class="xp-main">`
>    而报告体也有一个（`full_report` 在 `XhprofDisplay.php:766` 开、末尾闭），
>    叠起来会让内边距翻倍。**但我核实后不成立**：本计划的拼接点在 `profiler_report`
>    内部（run 描述之后），而 `full_report` 是在 `:466` 被 **追加**到同一个 `$echo_page`——
>    两者的 `.xp-main` 是**兄弟关系**，各拿各自的 24px。
>    （`.xp-main` 本身是 `padding:24px; max-width:1280px; margin:0 auto`，
>    `xhprof.css:129`。）**不要为此改渲染结构**；但 Task 6 完成后值得目视一次间距。
> 2. **一个卡片里有三条 `.xp-card-title` 会看起来像三张卡的页眉。**
>    `.xp-card-title` 带 `border-bottom` + 条纹底色（`xhprof.css:143-149`），
>    所以「诊断结论」「为什么慢」「其他发现」会渲染成三条相同的横条。
>    可以接受，但值得**刻意决定**，而不是让它就这样发生。
>
> **另注**：Task 5 的顺序测试只钉住**分区之间**的顺序（主区在前），
> **分区内**的顺序仍无人守——不过渲染层不重排，所以分区内顺序就是 `Analyzer` 给序，
> 而那个顺序由 Task 4 的测试守着。

> **Task 5/6 的集成约束（Task 4 复审）。**
> - **截断与"该规则没触发"不可区分**：`analyze()` 只返回截断后的列表，
>   不携带"R2 还有 4 条被省略"。所以「为什么慢」卡片完全可能只有三条 R1 行，
>   渲染层无从得知 R2/R3 存在过。**刻意如此**，但两条后果要记：
>   Task 5/6 的文案不得暗示"六条规则总会呈现"；**不要加"本条规则有 N 条结论"这类计数器**
>   ——它只会低报。若将来要做「还有 N 条被省略」，**Task 4 是最后还持有该信息的地方**。
> - **同一 symbol 可以同时出现在两个分区**（无主风险 #2），Task 5 会渲染两条指向同一详情页
>   但标题不同的链接。观感上像 bug，实为正确——渲染层代码里要写注释说明。
> - **Task 5 不得在分区内重新排序**：`score` 是各规则自己的量纲（微秒 / 次数 / 深度），
>   重排没有意义。
> - **Task 6 的链接参数**：计划传的是 `$url_params`，而页面上其他链接用的是
>   `$base_url_params`（`all`/`symbol` 已 unset）。诊断区的链接会因此带上 `all=1`——
>   无害但不必，且现有测试抓不到。改用 `$base_url_params` 的形状。

> **两条"无主风险"（Task 4 复审发现，均已处理）。**
>
> 1. **"补充区不去重"这条 spec 规则此前只是顺带被守住。** 对已提交测试跑
>    "补充区也去重"的变异，56 条里**只有 1 条失败**：`r4SortsByDepthDescending`——
>    而那条测试的命名意图是**排序方向**，不去重只是它夹具（两个递归函数 symbol 均为空串）
>    的副作用。改写它就会静默丢掉这条规则，而后果是**所有递归结论被折叠成一条**。
>    已补 `supplementIsNotDeduped` 显式收编。
> 2. **`bad()` 曾同时横跨两个分区**（`R1:bad()` 在主区、`R6:bad()` 在补充区），
>    改动后这套形态在测试集里再无覆盖。没有人断言过它，所以无回归——
>    但"同一 symbol 出现在两个分区"这个形态如今无人守着。
>    它成立的前提正是"补充区不去重"，所以（1）的补测部分覆盖了它。
>
> 这两条的共同教训与 `r3NeverClaimsLoop` 一致：**一条规则若只被"命名意图是别的东西"的
> 测试顺带守住，那它实际上没有守**。

> **R3 否定断言被掩盖的精确条件（实现者从机制推出，比"子函数也命中 R1"更可用）。**
>
> 一条 R3 **否定**断言（`assertSame([], self::rule($found,'R3'))`）被掩盖，
> **当且仅当**其夹具的子函数占比跨过了 **R1 的 10% 闸门**。因为 R3 自己的子函数闸门是
> **5%**，严格低于 R1 的 10%，所以：
>
> - **正当测试 share 闸门**的夹具（子函数占比 < 10%）天然安全
>   —— `r3DoesNotFireWhenChildShareBelowThreshold` 用 4.9%，从来不在风险里；
> - 只有**测 count 闸门或父守卫**的夹具会中招，因为那两类用例里子函数的占比是自由变量，
>   而它们恰好都漂到了 10%。
>
> **写 R3 否定型夹具的判据：子函数占比必须落在 `[5%, 10%)`** ——
> 高到能过 R3 自己的闸门（否则断言由一个无关的过滤器满足），
> 低到进不了 R1（否则进不了去重）。
>
> **边界**：R3 的**肯定**型断言（`assertCount(1, ...)`）不可能被这样掩盖——
> 去重若吃掉那行，计数会变成 0 而失败。该失效类别**恰好限于否定断言**。

> **`r3NeverClaimsLoop` 曾被改空转（已修）。**
> 它的夹具子函数停在 10%，于是 R1 也在该子函数上命中，R1 胜出的去重把 R3 行移除了。
> 实测该夹具现在只产出 **2 条 R1 行、0 条 R3 行**——`foreach` 只在 R1 行上跑，
> "标题/细节不得含『循环』"这个断言**从未检视过任何 R3 标题**。Task 4 之前它确实会
> 产出 R3 行（无去重的变异可恢复），所以这是一次静默的覆盖丢失。
>
> 修法：夹具子函数 `excl_wt` 100 → 60，**并**补一条 R3 专属守卫
> `assertNotEmpty(self::rule($found, 'R3'), '夹具必须让 R3 命中')`。
>
> **这里有个值得记的教训**：当初为防空转加的 `assertNotEmpty($found, '夹具必须产出…')`
> **被那 2 条 R1 行满足了**，所以没能守住。"夹具产出了东西"不等于
> "夹具产出了这个测试所关心的东西"。写这类守卫时要断言**具体那条规则**，而不是"有产出"。

> **待修：`isFiniteGuardRejectsNanTotals` 的夹具正好卡在封顶线上。**
> 该夹具现状：`main()` 10%、`hog()` 10%（两者都命中 R1）、`foo()` 6%（R3 被调方），
> 实测主区为 `["main()","hog()","foo()"]` —— **3/3 正好卡满 `MAIN_LIMIT`**。
> 后果：将来谁给这个夹具再加一条 R1 命中，R3 就会被挤出，而失败信息会指向
> `assertNotEmpty(self::rule($normal,'R3'))`，**原因却与 is_finite 守卫毫无关系**。
> 修法（实测）：把 `hog()` 的 `excl_wt` 从 10 降到 6 → 主区变 2/3，
> 三条 `assertNotEmpty` 仍全部满足（R1 由 `main()` 提供、R3 由 `foo()`、R5 由 `hog()` 的 pmu）。
>
> **Task 5/6 需要注意的语义**：因为 R2 结构上最先被截断，一个有三条 R1 命中的 run
> **完全不会渲染调用次数的结论**；而碰撞规则意味着"既是最大自身耗时、又是热点边目标"的
> 函数只显示自身耗时那一行、丢失调用方细节。两者都是刻意的、现在都有测试守着——
> 但若 Task 5 的渲染或 Task 6 的文案暗示"六条规则的结果总是都能呈现"，那个假设是错的。

> **"先去重再切片"这个顺序本身需要专测（否则变异可存活）。**
> 计划原有的去重用例**无法区分两种顺序**：它的夹具产出
> `[hot(R1), main(R1), hot(R3), hot(R3), hot(R3), hot(R2)]`，先切片的 `[hot, main, hot]`
> 再去重仍得到 `[hot, main]`——symbol 依然唯一、`hot()` 依然只有一条，两条断言都成立。
> 要区分，夹具必须**头部有重复且第 4 位是另一个 symbol**：
> ```
> 夹具：x()=R1兼R3被调方, y()=仅R3, z()=R2
> 去重→切片 : ["x()","y()","z()"]            ← 正确
> 切片→去重 : ["x()","y()"]  ← z() 被静默丢弃  ← 变异
> ```
> 用例名 `mainSectionDedupesBeforeSlicingSoLaterSymbolsSurvive`。它同时把 IR-4 的论据
> （"可能把一个排在第 4 位、不同 symbol 的热点永久藏起来"）从文字变成了断言。
>
> 另：对补充区去重的变异**已被既有用例杀掉**（`r4SortsByDepthDescending`，
> "actual size 1 matches expected size 2"），故"补充区不去重"这条约束无需新增测试。

> **R1/R3 同 symbol 碰撞：R1 胜出，且必须显式钉住。**
> 去重保留合并顺序里的第一条，而顺序是 R1 → R3 → R2，所以一个函数若同时命中
> R1（自身耗时占比）与 R3（热点边），只留 R1，R3 的调用方细节被丢弃。
>
> **这不是夹具假象而是真实场景**——被热点调用的子函数自身耗时超过 10% 恰恰是最该报的情况。
> 实测（未修改的 Task 2/3 夹具）：
> ```
> r3FiresOnHotEdge       : R1=[main(), foo()]    R3=[foo()]      → R3 被去重丢弃
> r3SortsByEdgeWallTime  : R1=[main(), a(), b()] R3=[b(), a()]   → R1 占满 MAIN_LIMIT=3
> ```
> 因此有 6 个既有用例在封顶落地后转红。处理方式：
> 1. **把这 6 个夹具里被调方的自身耗时从 10% 压到 6%** —— 仍过 R3 的 5% 闸门，
>    但不再触碰 R1 的 10% 闸门。每个测试本应隔离自己的关注点，"测 R3 是否触发"
>    不该被 R1 的闸门纠缠。
> 2. **另加一条用例专测碰撞本身**（`mainKeepsR1OverR3ForSameSymbol`）：用**原始**夹具
>    （被调方 10%），断言主区为 `['main()','foo()']` 且 R3 残留为空。
>    没有它，"谁在碰撞中胜出"就成了去重的未测副作用。

> **去重只作用于主区。** 补充区**不要**去重：R4 的 `symbol` 刻意是空串，一旦对补充区按
> symbol 去重，所有递归结论会被折叠成一条。主区去重的前提是 R1/R2/R3 的 symbol 都非空。

> **`MAIN_LIMIT` / `SUPPLEMENT_LIMIT` 目前声明但未被使用**（`analyze()` 当前不封顶）。
> Task 6 的接入**必须排在 Task 4 之后**——否则报告页会一次刷出几百条结论。

> **R5 的命中条件（Task 4 若调试"补充区为空"需知）**：需 `$totals['pmu']` 与逐项
> `excl_pmu` **同时**存在。`xhprof_compute_flat_info` 恒会初始化 totals 的 `pmu`，
> 但未采集内存指标时逐项没有 `excl_pmu` → R5 静默无结论。这是 IR-2「正向闸门」
> 设计的预期行为，不是 bug。

> **IR-4：不要再给 R3 加内部上限。** 实测 R3 的产出量受**被调方 5% 自身耗时闸门**约束，
> 而非边数量——真实分布下最多约 20 个不同子函数能过这道闸门，`analyze()` 的分配是
> 输入规模线性且瞬时的，没有二次型模式。上限就该留在 Task 4 这一层：
> R3 内部自限会是**行为改变**而非性能微调，且可能把一个排在第 4 位、与前者**不同 symbol**
> 的热点永久藏起来。
>
> **#7（可选，别在 Task 2 做）**：`'R1'`/`'R2'`/`'R3'` 是三处裸字符串字面量，而阈值都是具名常量。
> 目前没有任何地方 `switch ($f->rule)`（本任务的去重按 `symbol`、截断按顺序），所以暂无风险。
> 若将来出现按 rule 分支的逻辑，再加 `Finding::RULE_*` 常量，让拼错不至于静默漏掉一条规则。

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
        // 必须带 % 号：`'10'` 会被紧随其后的 `'1000'` 满足——删掉「自身耗时」子句后
        // 断言依然成立，即空转（Task 5 实现者用变异证明，我复核确认）。
        // `'10%'` 在整个串里恰好出现 1 次，无歧义。
        self::assertStringContainsString('10%', $html);
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
      // 列全所有规则的阈值：空态的意义就是让用户区分"没超阈值"与"没分析"
      $echo_page .= '<p style="padding:12px 20px;color:#666">未发现明显瓶颈'
        . '（阈值：自身耗时 ≥ ' . (Analyzer::SHARE_THRESHOLD * 100) . '%'
        . '、调用次数 ≥ ' . Analyzer::CALL_COUNT_THRESHOLD
        . '、边调用 ≥ ' . Analyzer::EDGE_COUNT_THRESHOLD
        . '、被调方自身耗时 ≥ ' . (Analyzer::EDGE_SHARE_THRESHOLD * 100) . '%'
        . '、峰值内存 ≥ ' . (Analyzer::PMU_SHARE_THRESHOLD * 100) . '%）</p>'
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

    /**
     * 多 run 聚合视图（?run=a,b）走的是 profiler_single_run_report，形态与单 run 一致，
     * 所以诊断区**会**在那里出现（IR-1 的第三条渲染路径）。这是**可接受**的——占比是
     * "平均值的占比"，仍有意义——但必须显式接受而非碰巧发生，故钉住它。
     */
    #[Test]
    public function aggregateRunReportContainsDiagnosisSection(): void
    {
        $rid1 = 'a1a1a1a1a1a1a1a1';
        $rid2 = 'b2b2b2b2b2b2b2b2';
        $this->cache->set('xhprof:xhprof_log:' . $rid1, serialize($this->sampleRunData()));
        $this->cache->set('xhprof:xhprof_log:' . $rid2, serialize($this->sampleRunData()));
        $this->useRequest(new FakeRequest(['run' => "$rid1,$rid2", 'all' => 1], ['uri' => '/xhprof']));

        $html = XhprofDisplay::displayXHProfReport(
            ['run' => "$rid1,$rid2", 'all' => 1],
            'xhprof_foo',
            "$rid1,$rid2",
            null,
            null,
            null,
            null,
            null
        );

        self::assertStringContainsString('诊断结论', $html);
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
    // 传 $base_url_params（:363 定义，已 unset symbol/all），不要传 $url_params：
    // 1) 它是本页既有的"跳回本报告"标准形状——show_nav():1420 与 full_report():773
    //    用的都是它，传它让诊断链接与页面上其他链接结构一致，而不是特例；
    // 2) 它不含 symbol，故 xhprof_array_set(...) 结果恰好一个 symbol 键；传原始
    //    $url_params 则要靠该助手的**覆盖**语义来保证正确，等于依赖助手行为而非入参形状；
    // 3) 非 diff 模式下它带 run —— 这正是 render_diagnosis 第二个参数存在的理由。
    // （传 $url_params 也只是 URL 多一个无用的 all=1：全仓库唯一读 all 的地方是
    //   full_report() 里那句 `if (!empty($url_params['all']))`，符号详情页不读它。
    //   故属"不必"而非"错误"。）
    // 注：此处刻意不写行号——本特性里同一个位置被三个 agent 在三个时刻读成 866/867/882，
    //     引用代码片段比引用行号稳。
    if (!$diff_mode && empty($rep_symbol)) {
      $findings = Analyzer::analyze($symbol_tab, $run1_data, $totals);
      $echo_page .= XhprofDisplay::render_diagnosis($findings, $base_url_params);
    }
```

- [ ] **Step 3b: 改 `Analyzer` 的类 docblock（IR-1 的另一半）**

`src/Core/Analysis/Analyzer.php:12-13` 现在写的是"入参都是
`XhprofDisplay::profiler_report()` 里已有的局部变量"——**这句话本身就在为 IR-1 的接线 bug 背书**：
diff 模式下 `$symbol_tab`/`$totals` 已被改写成增量，而 `$run1_data` 仍是原始边表。
调用点的 `!$diff_mode` 守卫是唯一防线，docblock 必须改成明确警告：

```
 * 三份入参必须来自**同一次**运行。注意 diff 模式下 profiler_report() 会改写自己的
 * 局部变量（$symbol_tab/$totals 变成 run2-run1 的增量，而 $run1_data 仍是单 run 边表），
 * 那组混合值不可直接传入——调用点必须以 !$diff_mode 守卫。
```

- [ ] **Step 4: 运行测试确认通过**

Run: `vendor/bin/phpunit`
Expected: PASS（全量）

- [ ] **Step 5: 提交**

```bash
git add src/Core/XhprofLib/Display/XhprofDisplay.php tests/Unit/Lib/XhprofDisplayTest.php
git commit -m "feat(analysis): 在单 run 报告页接入诊断区（diff/详情页不显示）"
```

> **IR-1：diff 模式会把增量喂给 `analyze()`，两条防线都要有。**
> `profiler_report()` 在 diff 模式下**改写了自己的局部变量**：`$symbol_tab` 与 `$totals`
> 被替换成 run2−run1 的**增量**，而 `$run1_data`（单 run 的原始边表）保持不动。
> 一个"顺手取那三个局部变量"的实现会拿到「增量总值 + 单 run 边表」的混合，实测输出：
>
> ```
> [R1] a() 自身耗时 0.2ms，占本次请求 20.0%      ← 拿"差值"当分母，任何符号下都无意义
> [R3] main() → a() 调用 600 次，累计 -0.4ms     ← UI 里出现负耗时
> ```
>
> 全负的情况下 `$total <= 0` 会恰好拦住全部规则，所以这是个**接线陷阱**而不是规则缺陷。
>
> **还有第三条渲染路径（质量审查发现，`!$diff_mode && empty($rep_symbol)` 拦不住）：**
> 多 run 聚合视图 `?run=a,b` 走的是 `XhprofDisplay.php:1284,1299` —— 它把
> `xhprof_aggregate_runs(...)['raw']` 的结果喂给 `profiler_single_run_report()`，
> 形态与单 run 完全一致（`$diff_mode` 为假、`$rep_symbol` 为空），所以诊断**会**在那里运行。
> 实测聚合后的指标是小数 double（`wt=139.5`），`analyze()` 确实会产出结论。
>
> 这是**可接受的**：占比是"平均值的占比"，仍然有意义；R2 的平均次数会被 `number_format`
> 四舍五入；R4 不受影响。但必须**显式接受**而不是碰巧发生 —— 本任务的测试里要加一条
> `?run=<id1>,<id2>` 聚合视图的用例，断言诊断区出现且不报错。
>
> 两道防线（都要做，第 2 条目前只写在备注里，要落到实现）：
> 1. 调用点守 `!$diff_mode && empty($rep_symbol)`（本任务步骤里已有）；
> 2. **改 `Analyzer` 的类 docblock**：现在写的是"入参都是 `profiler_report()`
>    里已有的局部变量"，这句正是 IR-1 说要纠正的措辞。改成明确警告：三份入参必须来自
>    **同一次**运行；diff 模式下 `profiler_report` 的局部变量已被改写为增量，不可直接传入。

---

## Task 7: 回退验证与全量回归

这一步是本项目的既定要求。前几轮出现过"判据写错导致四条测试全假报 PASS"，所以**只写测试不做回退验证不算完成**。

**Files:**
- 只读验证，不改源码（改完必须还原）

- [ ] **Step 1: 逐条规则做回退验证**

对每条规则，把阈值改成"永不触发"（例如 `SHARE_THRESHOLD = 999`），运行对应测试确认**失败**，然后还原。命令模板：

**前置：先提交，再回退验证。** `git show HEAD:<path> > <path>` 只在改动**已进 HEAD** 之后
才抗竞态；在提交之前执行会直接抹掉被测改动。故：确认工作树干净（`git status` 为空）再开始。

**还原一律用 `git show HEAD:<path> > <path>`，不要用 `cp /tmp/xxx.bak`** ——
`/tmp` 备份**本身可能抓在变异中途**（本特性里发生过两次，留下过 `. ''` 与 `$hrefA/$hrefB`
这类在任何已提交版本里都不存在的状态）。用已提交 blob 覆盖则天然免疫。

**每次变异前后各查三件事**：（a）锚点在原始文件中**恰好出现一次**；
（b）变异后源文件**确实与 HEAD 不同**（挡链式 replace 互相抵消）；
（c）`php -l` 通过，且**渲染/可观测输出确实不同**——0 行差异意味着探针没覆盖该路径
（报 `PROBE BLIND`），不是变异等价。

```bash
git status --porcelain                       # 必须为空
python3 -c "
p='src/Core/Analysis/Analyzer.php'; s=open(p).read()
assert s.count('const SHARE_THRESHOLD = 0.10;') == 1
open(p,'w').write(s.replace('const SHARE_THRESHOLD = 0.10;','const SHARE_THRESHOLD = 999.0;',1))"
php -l src/Core/Analysis/Analyzer.php
diff <(git show HEAD:src/Core/Analysis/Analyzer.php) src/Core/Analysis/Analyzer.php >/dev/null && echo 'PROBLEM: 未生效' || echo 'ok: 确已变异'
vendor/bin/phpunit --filter r1 2>&1 | grep -qE '^(FAILURES|ERRORS)!' && echo 'FAIL (good)' || echo 'PASS <<< PROBLEM'
git show HEAD:src/Core/Analysis/Analyzer.php > src/Core/Analysis/Analyzer.php
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
s.replace('if ($ex <= $in) {', 'if (true) {')   # 变量名以当前代码为准
```

每条改完都要看到 `FAIL (good)`，然后 `git show HEAD:src/Core/Analysis/Analyzer.php > src/Core/Analysis/Analyzer.php`
还原（并 `md5sum` 比对）后再改下一条。

**每条都必须看到 `FAIL (good)`。** 任何一条报 `PASS <<< PROBLEM` 就说明该测试没有真正守住这条规则，要修测试。

- [ ] **Step 2: 确认源码已完全还原**

```bash
md5sum src/Core/Analysis/Analyzer.php
git show HEAD:src/Core/Analysis/Analyzer.php | md5sum
git status --porcelain    # 必须为空
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

> **已知测试隔离缺陷（不是本特性引入的，但会影响写测试的人）**：`Xhprof::markHyperfContext()`
> 把 `private static bool $_hyperf` 置为 true，该静态**进程级且无重置入口**。任何 Hyperf 测试
> 跑过之后，`Xhprof::getLogger()` 等取用器都会走协程 `Context` 分支而忽略 `Xhprof::$logger`，
> 于是"只设 `Xhprof::$logger`"的测试会失败——且**只在全量套件里失败**，单独 `--filter` 跑是绿的。
> 沿用代码库既有写法（`XhprofLibTest.php:35`）：同时设 `Xhprof::$logger` 与
> `Context::set('xhprof.logger', ...)`，并在 `finally` 里两者都还原。
> 生产环境不受影响（Hyperf 进程本来就是 Hyperf），故不为此新增 public 重置接口。

> **先确认 `xdebug.mode` 是 off。** 本开发环境默认 `xdebug.mode=profile`，实测同一段循环
开剖析 58.3ms、关掉 6.5ms（**9x**）；审查者另测到相同循环 14x、`preg_match` 4.3x。
**膨胀系数随操作而异**，所以不仅绝对值失真，**跨操作的比值也会被扭曲**。
本特性此前那个"逐字拷贝的循环与真实方法相差 1.85x、原因未查明"的悬案，很可能就是它。
本地计时一律用 `php -d xdebug.mode=off`。

**性能结论只能同进程 A/B，不能跨仪器取绝对值。** 本特性开发中出现过一次错误结论：
把 `explode` 放进一个没有其他逐边工作的裸循环计时，得出 4.5x；同进程 A/B 复测只有
约 2.3x。同一台机器上，同一段循环写成"逐字拷贝"与"真实方法"都能差 1.85x（原因未查明，
但它在比值里约掉了）。因此**任何性能论断都必须是同进程内的 A/B 比值**，绝不用不同脚本、
不同负载下的绝对耗时相比。

**Step 7: 提交（若 Step 1 过程中修了测试，或在 Step 5 动了 ci.yml）**

```bash
git add -A
git commit -m "test(analysis): 回退验证补齐"
```

### Task 7 执行结果（本会话实测，非推测）

**变异 8 条，全部 KILLED。** 计划 Step 1 只列了六条规则阈值，另补测 Task 6 的两道接线守卫：

| 变异 | 探针证据 | 变红用例数 |
|---|---|---|
| R1 `SHARE_THRESHOLD` → `999.0` | 探针少 1 条 | 14 |
| R2 `CALL_COUNT_THRESHOLD` → `99999999` | 探针少 1 条 | 6 |
| R3 `EDGE_COUNT_THRESHOLD` → `99999999` | 探针少 1 条 | 13 |
| R4 `count($ds) < 2` → `< 999` | 探针少 1 条 | 6 |
| R5 `PMU_SHARE_THRESHOLD` → `999.0` | 探针少 1 条 | 7 |
| R6 `$ex <= $in` → `true` | 探针少 1 条 | 4 |
| 去掉 `empty($rep_symbol)` | 详情页 无→**有** | 1（`symbolReportHasNoDiagnosisSection`） |
| 去掉 `!$diff_mode` | diff 报告 无→**有** | 1（`diffReportHasNoDiagnosisSection`） |

每次均确认 `Tests: 345` —— 这是守卫 D：harness 断言**确实执行了用例**，
挡「`--filter` 打不中 ⇒ 跑 0 条 ⇒ 报绿」这条本仓库栽过的跟头。
探针夹具 `Analyzer` 侧同时命中六条规则各 1 条（先验证基线为 6 条），
所以任何一条阈值被改成"永不触发"都必然少一行——这是守卫 C 能成立的前提。

**后两条变异不是冗余，是必要的。** 它们把「详情页 / diff 页不显示诊断」从
"**没显示**"变成"**删掉守卫就会显示**"。只写单元测试而不断言守卫可删，
等于用一个可能空转的断言背书——而"判据恰好没覆盖到那条路径"正是本特性的空转史形态。

> **⚠️ 手动验证（Step 6）的两个坑。**
>
> 1. **run id 必须匹配 `/^[a-f0-9]{13,32}$/`，否则整页只剩约 441 字节的导航栏。**
>    `XHProfRunsDefault::get_run()` 的入口白名单直接返回 `false`，
>    `displayXHProfReport` 于是**完全不渲染报告体**。用自造 id
>    （如 `realdata12345678`，含 `r`/`l`/`t` 等非十六进制字符）实测页面长度 441，
>    **任何"不含 XXX"的否定断言都会静默通过**。
>    本次执行时就先撞上了它：一度得到"详情页无诊断区 ✅"，而那一版是**空转的**
>    （页面根本没渲染，当然没有诊断区）。换成 `a1b2c3d4e5f60718` 后报告页 18667 字节、
>    诊断区正常出现，同一条否定断言才有意义。
>    **做否定断言前，先断言页面确实渲染了**（长度，或 run 描述那一行）。
> 2. 计划 Step 6 给的脚本传 `$run = null`，那只会渲染**运行列表**，诊断区本就不该出现——
>    它验证的只是"报告页不 500"。要看诊断区，必须把真实数据写进
>    `xhprof:xhprof_log:<rid>` 再按该 id 取报告。

**真实数据实测**（`php -d xdebug.mode=off`；负载 = 递归 + 2000 次循环调用 + 5000×256B 分配）：

```
[R1/main] fib@13 自身耗时 9.3ms，占本次请求 18.6%    ← 递归展开后的单帧自身耗时
[R4/补充] 检测到 fib() 递归，最大深度 19
[R5/补充] churn 内存峰值 1.0MB，占全局 100.0%
```

共 5 条结论，无空表、无噪声。链接形如
`/xhprof?run=a1b2c3d4e5f60718&symbol=fib%4013` —— **run 参数在**，
即 Task 5 第二个参数的存在理由已端到端验证。

**Step 3 的期望值需修正**：`--fail-on-warning --fail-on-notice --fail-on-deprecation`
实测输出是 `OK, but there were issues!` + 1 条 **PHPUnit test runner warning**
（`XDEBUG_MODE=coverage ... has to be set`），不是裸 `OK`。
**该警告与本特性无关且先于本特性存在**：在未改动的 `main` 上跑同一配置得到同一条警告
（临时 worktree 实测；该次运行另有 1 条失败，是我用 `vendor` 软链导致的路径断言假阳性，
不是 `main` 的问题）。成因是 `phpunit.xml` 声明了 `<coverage>` 而本机 `xdebug.mode=profile`。
失败 / 错误 / notice / deprecation 均为 0。

**已知瑕疵（未修，与判据无关）**：若某条规则被变异成不产出，`AnalyzerTest` 里有若干用例
直接取 `$hits[0]->title`（无 empty 守卫），于是报 `Undefined array key 0` +
`Attempt to read property on null`，而不是干净的断言失败。**检查照样变红**
（`failOnWarning=true` 下警告本身也是红），只是失败信息不如 `assertCount(1, ...)` 直白。
按 YAGNI 不改；但将来若有人看到变异输出里的 `Warnings: 4` 以为引入了新问题，根源在此。

**PHP 8.0 底线**：`src/` 全 51 个文件通过 php-parser 按 8.0 语法解析；
`php -l` 对 `src/` + `tests/` 全量无输出。

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
