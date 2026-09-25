<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Core\Analysis;

use ErikWang2013\Xhprof\Core\Analysis\Analyzer;
use ErikWang2013\Xhprof\Core\Analysis\Finding;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AnalyzerTest extends TestCase
{
    /** 入口守卫保证：symbol_tab 不可用时返回空数组 */
    #[Test]
    #[DataProvider('unusableSymbolTabProvider')]
    public function analyzeReturnsEmptyWhenSymbolTabUnusable(mixed $symbolTab, array $rawData): void
    {
        $this->assertSame([], Analyzer::analyze($symbolTab, $rawData, []));
    }

    public static function unusableSymbolTabProvider(): array
    {
        // 第一行故意给 R4 形状的 raw_data：R4 只读 raw_data，若去掉入口守卫里的
        // `|| $symbol_tab === array()` 子句，它会照常产出结论——这条子句才可被观测。
        return [
            '全空数组'   => [[], ['fib@1==>fib@2' => ['ct' => 1, 'wt' => 10]]],
            'null 入参'  => [null, []],
            '字符串入参' => ['x', []],
        ];
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

    /**
     * 规则内部抛异常必须被隔离成空结果，不能冒泡到报告页。
     * 同时它必须**留痕**：静默失败 = 规则坏了却永远无人知晓，
     * 报告页只会永远显示"没发现问题"。这里顺带证明日志那句不是死代码。
     */
    #[Test]
    public function safeIsolatesRuleExceptions(): void
    {
        $logger = new FakeLogger();
        $prev   = Xhprof::$logger;
        Xhprof::$logger = $logger;
        // Hyperf 测试先跑会置 $_hyperf=true（私有静态、进程内不复位），此后
        // getLogger() 走协程 Context 分支、忽略 self::$logger。按 XhprofLibTest
        // 同款写法两边都设，全量跑与单跑两种顺序下都成立。
        if (class_exists(\Hyperf\Context\Context::class)) {
            \Hyperf\Context\Context::set('xhprof.logger', $logger);
        }
        try {
            $m = new \ReflectionMethod(Analyzer::class, 'safe');
            $m->setAccessible(true);

            $result = $m->invoke(null, static function (): array {
                throw new \RuntimeException('rule blew up');
            });
            // 必须是 \Throwable 而不是 \Exception：本文件 :60-63 / :188-190 的注释都
            // 依赖它兜住 TypeError（\Error 的子类，不继承 \Exception）。
            $typeError = $m->invoke(null, static function (): array {
                throw new \TypeError('bad edge key');
            });
        } finally {
            // 不把 logger 泄漏给其他测试
            Xhprof::$logger = $prev;
            if (class_exists(\Hyperf\Context\Context::class)) {
                \Hyperf\Context\Context::set('xhprof.logger', null);
            }
        }

        $this->assertSame([], $result);
        $this->assertSame([], $typeError, 'Error（如 TypeError）也必须被兜住，否则会冒泡成报告页 500');
        $this->assertCount(2, $logger->errors, '规则异常必须写日志，否则坏了没人知道');
        $this->assertStringContainsString('Analyzer rule failed', $logger->errors[0]);
        $this->assertStringContainsString('rule blew up', $logger->errors[0]);
        $this->assertStringContainsString('bad edge key', $logger->errors[1]);
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
        // wt 必须 >= excl_wt：本测试断言的是**全部**结论，而 excl_wt > wt（逻辑上不可能）
        // 会（正确地）触发 Task 3 的 R6 探针，让这里的期望多出 3 条与排序无关的结论。
        $tab = [
            'small()' => self::sym(1, 1000, 200),
            'big()'   => self::sym(1, 1000, 500),
            'mid()'   => self::sym(1, 1000, 300),
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
            'foo()'  => self::sym(600, 600, 60),    // 自身 6%：过 R3 的 5% 闸门，不触发 R1 的 10%
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
        $tab = ['main()' => self::sym(1, 1000, 100), 'foo()' => self::sym(600, 600, 60)];
        $raw = ['main()==>foo()' => ['ct' => 600, 'wt' => 400]];
        $found = Analyzer::analyze($tab, $raw, ['wt' => 1000]);

        // 夹具必须产出，否则本测试形同虚设
        $this->assertNotEmpty($found, '夹具必须产出，否则本测试形同虚设');
        // 但"产出了东西"不等于"产出的是本测试关心的东西"：foo() 自身耗时若压到 10%（本例原为 100），
        // R1 也会命中它，主区去重会把 R3 那行吃掉 —— foreach 就只在两条 R1 行上空转。
        $this->assertNotEmpty(self::rule($found, 'R3'), '夹具必须让 R3 命中');
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
        $tab = ['main()' => self::sym(1, 1000, 100), 'foo()' => self::sym(600, 600, 60)];
        $raw = [
            0 => ['ct' => 999, 'wt' => 1],              // 坏键：会被转成 int
            'main()==>foo()' => ['ct' => 600, 'wt' => 400],
        ];
        $found = Analyzer::analyze($tab, $raw, ['wt' => 1000]);

        $hits = self::rule($found, 'R3');
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
        $tab = ['main()' => self::sym(1, 1000, 100), 'foo()' => self::sym(500, 600, 60)];
        $raw = ['main()==>foo()' => ['ct' => 500, 'wt' => 400]];
        $this->assertCount(1, self::rule(Analyzer::analyze($tab, $raw, ['wt' => 1000]), 'R3'));
    }

    #[Test]
    public function r3DoesNotFireAt499Calls(): void
    {
        // child 99/1000 = 9.9%，低于 R1 的 10% 闸门：否则阈值一旦被调低，
        // R3 会产出 foo() 行、却被 R1 胜出的去重吃掉，断言就改由去重满足而非由闸门满足
        $tab = ['main()' => self::sym(1, 1000, 100), 'foo()' => self::sym(499, 600, 99)];
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
                'a()'    => self::sym(600, 600, 60),
                'b()'    => self::sym(600, 600, 60)];
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
        // main() 99/1000 = 9.9%：同样是为了不让 R1 命中，否则去掉父守卫后
        // R3 产出的 main() 行会被去重吃掉，本测试就不再检验那个守卫
        $tab = ['main()' => self::sym(1, 1000, 99)];
        $raw = [$key => ['ct' => 9999, 'wt' => 900]];
        $this->assertSame([], self::rule(Analyzer::analyze($tab, $raw, ['wt' => 1000]), 'R3'));
    }

    public static function parentlessKeyProvider(): array
    {
        return ['裸 main() 键' => ['main()'], '==>main() 形式' => ['==>main()']];
    }

    /**
     * R3 内部**不得**加封顶（plan IR-4）。R3 逐边产出，同一热点的多条同 symbol
     * 结论最终会被主区去重折叠，所以内部封顶丢弃的是**本可保留下来的不同 symbol**
     * ——本例中 d() → cold() 这条热点边会整个消失。
     */
    #[Test]
    public function r3IsNotCappedInternally(): void
    {
        $tab = [
            'hot()'  => self::sym(1, 1000, 200),   // R1（20%）且是 R3 的被调方
            'cold()' => self::sym(600, 100, 60),   // R3 的被调方（6%）
            'z()'    => self::sym(2000, 50, 1),    // R2
        ];
        $raw = [
            'a()==>hot()'  => ['ct' => 900, 'wt' => 500],
            'b()==>hot()'  => ['ct' => 900, 'wt' => 400],
            'c()==>hot()'  => ['ct' => 900, 'wt' => 300],
            'd()==>cold()' => ['ct' => 600, 'wt' => 100],   // wt 最低 → 会被内部封顶切掉
        ];
        $found = Analyzer::analyze($tab, $raw, ['wt' => 1000]);
        $main = array_values(array_filter($found, fn($f) => $f->severity === Finding::SEVERITY_MAIN));

        $this->assertSame(['hot()', 'cold()', 'z()'], array_map(fn($f) => $f->symbol, $main));
    }

    #[Test]
    public function r4DetectsRecursionAndReportsMaxDepth(): void
    {
        $raw = [
            'main()==>fib' => ['ct' => 1, 'wt' => 100],
            'fib@1==>fib@2' => ['ct' => 1, 'wt' => 90],
            'fib@2==>fib@3' => ['ct' => 1, 'wt' => 80],
            // 两位数深度是真实数据（fib(16) 会产生深度 1..15），
            // 正则写成 (\d) 时这里会静默退化成「最大深度 9」。
            'fib@9==>fib@10' => ['ct' => 1, 'wt' => 70],
        ];
        $tab = ['main()' => self::sym(1, 100, 10), 'fib@1' => self::sym(1, 90, 5)];
        $hits = self::rule(Analyzer::analyze($tab, $raw, ['wt' => 100]), 'R4');

        $this->assertCount(1, $hits);
        // 精确断言，不用 containsString：'3' 这种短串到处都是，弱断言放过错误实现
        $this->assertSame('检测到 fib() 递归，最大深度 10', $hits[0]->title);
        $this->assertSame(10.0, $hits[0]->score, 'score 应是最大深度');
        // 本夹具的 symbol_tab 里只有 fib@1、没有裸名 fib → 给不出可指向的详情页
        // （有裸名时会给链接，见 r4GivesSymbolOnlyWhenBareNameExists）
        $this->assertSame('', $hits[0]->symbol);
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

    /** 与 R3 对称：R4 的唯一防护同样是键转换，坏键不得吞掉同一条规则的其他结论 */
    #[Test]
    public function r4SurvivesIntegerKeyAmongValidEdges(): void
    {
        $raw = [
            0 => ['ct' => 999, 'wt' => 1],             // 坏键：PHP 会把 "0" 这类键转成 int
            'fib@1==>fib@2' => ['ct' => 1, 'wt' => 90],
        ];
        $tab = ['main()' => self::sym(1, 100, 10)];
        $hits = self::rule(Analyzer::analyze($tab, $raw, ['wt' => 100]), 'R4');

        $this->assertCount(1, $hits, '坏键不得吞掉 R4 的其他有效结论');
    }

    /**
     * 深度量程守卫：`(int)` 对超长数字串**静默饱和**成 PHP_INT_MAX，
     * 审计实测 `q@1==>q@99999999999999999999` 会印出「最大深度 9223372036854775807」。
     * 丢掉那个 token 后 q 只剩一个深度 → 连"递归"都不成立（不产生结论）。
     *
     * 同时钉住它不是"大的就丢"：@999999（仍在量程内）必须照常报出该深度。
     */
    #[Test]
    public function r4RejectsDepthTokensBeyondRange(): void
    {
        $tab = ['main()' => self::sym(1, 100, 10)];

        $inRange = self::rule(Analyzer::analyze($tab, ['q@1==>q@999999' => ['ct' => 1, 'wt' => 10]], ['wt' => 100]), 'R4');
        $this->assertCount(1, $inRange, '量程内的深度必须照常报出');
        $this->assertSame('检测到 q() 递归，最大深度 999999', $inRange[0]->title);

        $tooBig = self::rule(Analyzer::analyze($tab, ['q@1==>q@99999999999999999999' => ['ct' => 1, 'wt' => 10]], ['wt' => 100]), 'R4');
        $this->assertSame([], $tooBig, '越界 token 是坏数据，不得印成 9223372036854775807');
    }

    /**
     * R4 的详情页链接只在 symbol_tab 里**真有裸名**时才给。
     *
     * 旧注释断言"symbol=fib 必然未找到"，已被实测推翻：真实递归数据
     * （main()==>fib, fib==>fib@1, …）的 symbol_tab 里同时有裸名 fib，该详情页正常渲染。
     * 没有裸名时才是死链，那时才置空。判据（要不要算递归）始终只看边表。
     */
    #[Test]
    public function r4GivesSymbolOnlyWhenBareNameExists(): void
    {
        $raw = [
            'main()==>fib'  => ['ct' => 1, 'wt' => 100],
            'fib==>fib@1'   => ['ct' => 1, 'wt' => 90],
            'fib@1==>fib@2' => ['ct' => 1, 'wt' => 80],
            'fib@2==>fib@3' => ['ct' => 1, 'wt' => 70],
        ];
        $tab = ['main()' => self::sym(1, 100, 10), 'fib' => self::sym(4, 90, 5), 'fib@1' => self::sym(1, 90, 5)];

        $hits = self::rule(Analyzer::analyze($tab, $raw, ['wt' => 100]), 'R4');
        $this->assertCount(1, $hits);
        $this->assertSame('fib', $hits[0]->symbol, '裸名在 symbol_tab 里存在 → 该给链接');

        // 同一个边表，只去掉裸名那一项
        unset($tab['fib']);
        $hits = self::rule(Analyzer::analyze($tab, $raw, ['wt' => 100]), 'R4');
        $this->assertCount(1, $hits, '判据只看边表：有没有裸名不影响"是不是递归"');
        $this->assertSame('', $hits[0]->symbol, '裸名不存在 → 置空，渲染层跳过链接');
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

    /** 低于阈值不触发——R1/R3 都有这条，R5 此前缺 */
    #[Test]
    public function r5DoesNotFireBelowThreshold(): void
    {
        $tab = ['hog()' => self::sym(1, 10, 1, 29)];   // 29/100 = 29% < 30%
        $this->assertSame([], self::rule(Analyzer::analyze($tab, [], ['wt' => 100, 'pmu' => 100]), 'R5'));
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

    /**
     * 「最大深度」不等于「深度个数」。夹具用**非连续**深度 {1,3} 把两者分开：
     * 连续深度下 max(array_keys($ds)) 与 count($ds) 数值恰好相同，变异测不出来。
     */
    #[Test]
    public function r4ReportsMaxDepthNotDepthCount(): void
    {
        $raw = ['fib@1==>fib@3' => ['ct' => 1, 'wt' => 10]];   // 深度个数 2，最大值 3
        $tab = ['main()' => self::sym(1, 100, 10)];
        $hits = self::rule(Analyzer::analyze($tab, $raw, ['wt' => 100]), 'R4');

        $this->assertCount(1, $hits);
        $this->assertSame('检测到 fib() 递归，最大深度 3', $hits[0]->title);
    }

    /** R4 按深度降序：队首决定补充区截断后谁留下 */
    #[Test]
    public function r4SortsByDepthDescending(): void
    {
        $raw = [
            'a@1==>a@2' => ['ct' => 1, 'wt' => 10],
            'b@1==>b@2' => ['ct' => 1, 'wt' => 10],
            'b@2==>b@3' => ['ct' => 1, 'wt' => 10],   // b 深度到 3，a 只到 2
        ];
        $tab = ['main()' => self::sym(1, 100, 10)];
        $hits = self::rule(Analyzer::analyze($tab, $raw, ['wt' => 100]), 'R4');

        $this->assertCount(2, $hits);
        $this->assertSame([3.0, 2.0], array_map(fn($f) => $f->score, $hits));
    }

    /** R5 按自身峰值内存降序 */
    #[Test]
    public function r5SortsByExclusivePeakMemoryDescending(): void
    {
        $tab = [
            'hogA()' => self::sym(1, 100, 10, 80),
            'hogB()' => self::sym(1, 100, 10, 50),
        ];
        $hits = self::rule(Analyzer::analyze($tab, [], ['wt' => 100, 'pmu' => 100]), 'R5');

        $this->assertCount(2, $hits);
        $this->assertSame(['hogA()', 'hogB()'], array_map(fn($f) => $f->symbol, $hits));
        $this->assertSame([80.0, 50.0], array_map(fn($f) => $f->score, $hits));
    }

    /** bytes() 的三个分支，含 KB 边界 */
    #[Test]
    #[DataProvider('bytesProvider')]
    public function bytesFormatsUnits(float $bytes, string $expected): void
    {
        $m = new \ReflectionMethod(Analyzer::class, 'bytes');
        $m->setAccessible(true);
        $this->assertSame($expected, $m->invoke(null, $bytes));
    }

    public static function bytesProvider(): array
    {
        return [
            '字节'        => [30.0, '30B'],
            'KB 下界之下' => [1023.0, '1,023B'],
            'KB 边界'     => [1024.0, '1.0KB'],
            'MB 边界'     => [1048576.0, '1.0MB'],
            'MB'          => [3145728.0, '3.0MB'],
        ];
    }

    /**
     * is_finite 守卫是为 **NAN 分支**存在的，用 0 钉不住它。
     * 删掉守卫后：0 抛 DivisionByZeroError（被 safe() 吞成同样的 []）、负数与 INF
     * 自然不触发 —— 只有 NAN 会让「NAN < 阈值」为假而**触发**，把「占全局 nan%」
     * 渲染进报告页，而整套测试仍然全绿。
     */
    #[Test]
    public function isFiniteGuardRejectsNanTotals(): void
    {
        $tab = [
            'main()' => self::sym(1, 100, 100),      // R1 命中（100/100）
            'hog()'  => self::sym(1, 100, 10, 50),   // R5 命中（50/100）
            'foo()'  => self::sym(900, 900, 6),      // R3 的被调方（6%：只过 R3 的 5% 闸门）
        ];
        $raw = ['main()==>foo()' => ['ct' => 900, 'wt' => 90]];

        // 没有这一段，夹具一旦退化本测试就静默变成空转
        $normal = Analyzer::analyze($tab, $raw, ['wt' => 100, 'pmu' => 100]);
        $this->assertNotEmpty(self::rule($normal, 'R1'), '夹具必须让 R1 命中');
        $this->assertNotEmpty(self::rule($normal, 'R3'), '夹具必须让 R3 命中');
        $this->assertNotEmpty(self::rule($normal, 'R5'), '夹具必须让 R5 命中');

        $this->assertSame(
            [],
            Analyzer::analyze($tab, $raw, ['wt' => NAN, 'pmu' => NAN]),
            'NAN 总耗时必须被 is_finite 拦下，否则报告页出现 nan%'
        );
    }

    /** R6 按差值降序：队首决定补充区截断后谁留下（R4/R5 已各有排序用例，R6 此前漏了） */
    #[Test]
    public function r6SortsByDifferenceDescending(): void
    {
        $tab = [
            'big()'   => ['ct' => 1, 'wt' => 100, 'excl_wt' => 400, 'pmu' => 0, 'excl_pmu' => 0],  // 差 300
            'small()' => ['ct' => 1, 'wt' => 200, 'excl_wt' => 250, 'pmu' => 0, 'excl_pmu' => 0],  // 差 50
        ];
        $hits = self::rule(Analyzer::analyze($tab, [], ['wt' => 1000]), 'R6');

        $this->assertCount(2, $hits);
        $this->assertSame(['big()', 'small()'], array_map(fn($f) => $f->symbol, $hits));
        $this->assertSame([300.0, 50.0], array_map(fn($f) => $f->score, $hits));
    }

    /**
     * severity 必须与规则所属分区一致。analyze() 按**构造**分区（$main/$supplement），
     * 而 Task 4 的封顶与 Task 5 的渲染按**字段**分区——两者必须一致，
     * 否则一条结论会以补充项身份被截断、却渲染在「为什么慢」下面。
     */
    #[Test]
    public function severityMatchesRuleSection(): void
    {
        $tab = [
            'main()' => self::sym(1, 1000, 100),                              // R1
            'busy()' => self::sym(1000, 500, 50),                            // R2
            'hot()'  => self::sym(600, 400, 60),                             // R3 的被调方
            'hog()'  => self::sym(1, 100, 10, 80),                           // R5
            'bad()'  => ['ct' => 1, 'wt' => 50, 'excl_wt' => 80, 'pmu' => 0, 'excl_pmu' => 0],  // R6（8%：不触发 R1，否则主区被占满会挤掉 R2）
        ];
        $raw = [
            'main()'         => ['ct' => 1, 'wt' => 1000],
            'main()==>hot()' => ['ct' => 600, 'wt' => 300],                  // R3
            'fib@1==>fib@2'  => ['ct' => 1, 'wt' => 50],                     // R4
        ];
        $found = Analyzer::analyze($tab, $raw, ['wt' => 1000, 'pmu' => 100]);

        $this->assertCount(6, array_unique(array_map(fn($f) => $f->rule, $found)), '夹具必须触发全部六条规则');
        foreach ($found as $f) {
            $expected = in_array($f->rule, ['R1', 'R3', 'R2'], true)
                ? Finding::SEVERITY_MAIN
                : Finding::SEVERITY_SUPPLEMENT;
            $this->assertSame($expected, $f->severity, $f->rule . ' 的 severity 与所属分区不一致');
        }
    }

    /** 逐项 is_numeric 承重：垃圾指标值不得（a）产出结论（b）连累有效项 */
    #[Test]
    public function nonNumericMetricsAreSkippedPerItem(): void
    {
        $tab = [
            'good()' => self::sym(1, 1000, 500),
            'junk()' => ['ct' => 'x', 'wt' => [], 'excl_wt' => '500abc'],
        ];
        $found = Analyzer::analyze($tab, [], ['wt' => 1000]);

        $this->assertSame(['good()'], array_map(fn($f) => $f->symbol, self::rule($found, 'R1')));
    }

    /**
     * 逐项 is_finite 承重（分析侧最要害的一条）：坏值不只"该被跳过"，而是会被**放行**。
     *
     * is_numeric() 对 NAN 与 '1e400' 都返回 true，而 `NAN < 阈值`/`INF < 阈值` 恒为假
     * —— 所有闸门都是"小于阈值就 continue"的形状，于是 NAN 一路通过，最后渲染出
     * 「自身耗时 nanms，占本次请求 nan%」/「called nan times」/「峰值内存 nanB」。
     * 可达性：serialize() 原样存 NAN，xhprof_compute_flat_info() 又用减法算 excl_*。
     *
     * 夹具必须是"正常值下命中"的形态，否则本测试静默空转。
     */
    #[Test]
    #[DataProvider('nonFiniteProvider')]
    public function nonFiniteMetricsAreSkippedPerItem(mixed $bad): void
    {
        $normal = ['good()' => self::sym(1, 1000, 500), 'junk()' => self::sym(1, 1000, 500)];
        $broken = ['good()' => self::sym(1, 1000, 500), 'junk()' => self::withMetric(self::sym(1, 1000, 500), 'excl_wt', $bad)];

        // 前提：同一个夹具在正常值下 R1 必须命中（两条），否则下面断言的是"空集相等"
        $this->assertCount(2, self::rule(Analyzer::analyze($normal, [], ['wt' => 1000]), 'R1'));
        $this->assertSame(
            ['good()'],
            array_map(fn($f) => $f->symbol, self::rule(Analyzer::analyze($broken, [], ['wt' => 1000]), 'R1')),
            'NAN/INF 的 excl_wt 必须让该项整体不产生结论'
        );
    }

    /** R2：ct 是印刷量（number_format），NAN 会印成 "called nan times" */
    #[Test]
    #[DataProvider('nonFiniteProvider')]
    public function nonFiniteCallCountsAreSkippedPerItem(mixed $bad): void
    {
        $entry = self::withMetric(self::sym(1000, 10, 1), 'ct', $bad);
        $tab   = ['good()' => self::sym(1000, 10, 1), 'junk()' => $entry];

        $this->assertCount(2, self::rule(Analyzer::analyze(['a()' => self::sym(1000, 10, 1), 'b()' => self::sym(1000, 10, 1)], [], []), 'R2'));
        $this->assertSame(
            ['good()'],
            array_map(fn($f) => $f->symbol, self::rule(Analyzer::analyze($tab, [], []), 'R2'))
        );
    }

    /** R5：excl_pmu 同上，NAN 会印成「峰值内存 nanB，占全局 nan%」 */
    #[Test]
    #[DataProvider('nonFiniteProvider')]
    public function nonFinitePeakMemoryIsSkippedPerItem(mixed $bad): void
    {
        $totals = ['wt' => 100, 'pmu' => 100];
        $entry  = self::withMetric(self::sym(1, 10, 1, 30), 'excl_pmu', $bad);
        $tab    = ['good()' => self::sym(1, 10, 1, 30), 'junk()' => $entry];

        $this->assertCount(2, self::rule(Analyzer::analyze(['a()' => self::sym(1, 10, 1, 30), 'b()' => self::sym(1, 10, 1, 30)], [], $totals), 'R5'));
        $this->assertSame(
            ['good()'],
            array_map(fn($f) => $f->symbol, self::rule(Analyzer::analyze($tab, [], $totals), 'R5'))
        );
    }

    /** R3 的三个读点（边 ct / 被调方 excl_wt / 边 wt）都要挡 */
    #[Test]
    #[DataProvider('nonFiniteProvider')]
    public function nonFiniteEdgeMetricsAreSkipped(mixed $bad): void
    {
        $tab = ['main()' => self::sym(1, 1000, 100), 'foo()' => self::sym(600, 600, 60)];
        $raw = ['main()==>foo()' => ['ct' => 600, 'wt' => 400]];
        // 前提：三行坏用例逐个对应"只坏一处"，正常夹具必须命中 R3
        $this->assertCount(1, self::rule(Analyzer::analyze($tab, $raw, ['wt' => 1000]), 'R3'));

        $cases = [
            '边 ct'          => [$tab, ['main()==>foo()' => ['ct' => $bad, 'wt' => 400]]],
            '边 wt'          => [$tab, ['main()==>foo()' => ['ct' => 600, 'wt' => $bad]]],
            '被调方 excl_wt' => [
                ['main()' => self::sym(1, 1000, 100), 'foo()' => self::withMetric(self::sym(600, 600, 60), 'excl_wt', $bad)],
                $raw,
            ],
        ];
        foreach ($cases as $label => $case) {
            $this->assertSame([], self::rule(Analyzer::analyze($case[0], $case[1], ['wt' => 1000]), 'R3'), $label . ' 必须让该边不产生结论');
        }
    }

    /** R6：两个操作数都要挡——`NAN > NAN`、`INF > 1` 都不是可印刷的结论 */
    #[Test]
    #[DataProvider('nonFiniteProvider')]
    public function nonFiniteDurationsAreSkippedInR6(mixed $bad): void
    {
        $base = ['ct' => 1, 'wt' => 180, 'excl_wt' => 210, 'pmu' => 0, 'excl_pmu' => 0];
        // 前提：同一形状的好数据必须命中，否则下面断言的是空集
        $this->assertCount(1, self::rule(Analyzer::analyze(['a()' => $base], [], []), 'R6'));

        foreach (['excl_wt', 'wt'] as $key) {
            $broken = $base;
            $broken[$key] = $bad;
            $this->assertSame(
                [],
                self::rule(Analyzer::analyze(['a()' => $broken], [], []), 'R6'),
                $key . ' 非有限时不得产出结论'
            );
        }
    }

    /**
     * 越界（但不是坏类型）的量程守卫：审计实测 excl_wt=1e20 / wt=1e19 时
     * `(int) round()` 是 UB，标题印出「总耗时 -8,446,744,073,709,551,616μs」——负的时间。
     * 不硬转：超出 1e15μs（≈31 年）量程的微秒数当坏数据，R6 不产生结论。
     */
    #[Test]
    public function r6RejectsOutOfRangeDurationsInsteadOfWrappingNegative(): void
    {
        $tab = ['f()' => ['ct' => 1, 'wt' => 1e19, 'excl_wt' => 1e20, 'pmu' => 0, 'excl_pmu' => 0]];
        $found = Analyzer::analyze($tab, [], ['wt' => 1000]);

        $this->assertSame([], self::rule($found, 'R6'));
        foreach ($found as $f) {
            $this->assertStringNotContainsString('-,', $f->title, '不得印出负的时间');
            $this->assertStringNotContainsString('nan', $f->title);
        }
    }

    /**
     * 两个操作数都有限，商仍可能溢出成 INF（1e20 / 1e-300），而 `INF < 阈值` 恒为假。
     * 三个占比闸门（R1/R3/R5）都是这个形状，所以同一个守卫要写三处。
     */
    #[Test]
    public function nonFiniteSharesAreSkippedEvenWhenOperandsAreFinite(): void
    {
        $tab = ['hog()' => self::sym(1, 1000, 1e20, 1e20), 'hot()' => self::sym(600, 600, 1e20)];
        $raw = ['main()==>hot()' => ['ct' => 600, 'wt' => 400]];
        // 荒谬的分母：量纲合法（有限、>0），只有商溢出
        $found = Analyzer::analyze($tab, $raw, ['wt' => 1e-300, 'pmu' => 1e-300]);

        $this->assertSame([], self::rule($found, 'R1'));
        $this->assertSame([], self::rule($found, 'R3'));
        $this->assertSame([], self::rule($found, 'R5'));
    }

    /** 坏类型/缺键的逐项形态：`!is_array($info)` 与所有缺键分支此前零覆盖 */
    #[Test]
    #[DataProvider('malformedMetricEntryProvider')]
    public function analyzeToleratesMalformedMetricEntries(mixed $entry): void
    {
        $tab = ['good()' => self::sym(1, 1000, 500), 'junk()' => $entry];
        $raw = [
            0 => ['ct' => 999, 'wt' => 1],          // 整型键（PHP 会转 int）
            'main()==>x()' => 'not-an-array',       // 非数组的边值
            'y()==>z()' => null,
        ];
        $found = Analyzer::analyze($tab, $raw, ['wt' => 1000]);

        $this->assertSame(
            ['good()'],
            array_map(fn($f) => $f->symbol, self::rule($found, 'R1')),
            '坏条目不得连累同表里的有效项'
        );
        foreach ($found as $f) {
            foreach ([$f->title, $f->detail] as $text) {
                $this->assertStringNotContainsString('nan', $text);
                $this->assertStringNotContainsString('inf', $text);
            }
        }
    }

    public static function malformedMetricEntryProvider(): array
    {
        return [
            '非数组：字符串'   => ['not-an-array'],
            '非数组：null'     => [null],
            '非数组：对象'     => [new \stdClass()],
            '非数组：int'      => [7],
            '空数组（全缺键）' => [[]],
            '只缺 excl_wt'     => [['ct' => 1, 'wt' => 1000]],
            '指标全为非数值'   => [['ct' => 'x', 'wt' => [], 'excl_wt' => '1,000', 'excl_pmu' => false]],
        ];
    }

    public static function nonFiniteProvider(): array
    {
        // '1e400' 是同一族的字符串形态：is_numeric 为真，(float) 得 INF
        return ['NAN' => [NAN], 'INF' => [INF], '-INF' => [-INF], '1e400 字符串' => ['1e400']];
    }

    /** 造一个"只坏一处"的指标项：sym() 的签名是 float，字符串坏值得绕过类型声明 */
    private static function withMetric(array $info, string $key, mixed $value): array
    {
        $info[$key] = $value;
        return $info;
    }

    /**
     * 三个微秒数必须在**同一次舍入之后**自洽：各自取整会让 138.4/137.6 渲染成
     * 「138μs 大于 138μs，差 1μs」——与当初弃用 ms() 是同一类自相矛盾。
     * 取整后 138 不大于 138 → 不产出结论（亚 0.5μs 的倒挂有意忽略）；
     * 真倒挂（139 vs 137）仍照常产出，且标题里的三个数互相自洽。
     */
    #[Test]
    public function r6RoundsBeforeComparingSoTitleStaysCoherent(): void
    {
        $tab = [
            'squash()' => ['ct' => 1, 'wt' => 137.6, 'excl_wt' => 138.4, 'pmu' => 0, 'excl_pmu' => 0],
            'real()'   => ['ct' => 1, 'wt' => 137.4, 'excl_wt' => 138.6, 'pmu' => 0, 'excl_pmu' => 0],
        ];
        $hits = self::rule(Analyzer::analyze($tab, [], ['wt' => 1000]), 'R6');

        $this->assertCount(1, $hits);
        $this->assertSame('real() 自身耗时 139μs 大于其总耗时 137μs，差 2μs', $hits[0]->title);
        $this->assertSame(2.0, $hits[0]->score);
    }

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

        // 断言字面量 3 而不是常量：用同一个常量断言会随它一起漂移而恒真
        $this->assertCount(3, $supp, '补充区上限是 spec 值 3');
        $this->assertSame(
            ['hog0()', 'hog1()', 'hog2()'],
            array_map(fn($f) => $f->symbol, array_values($supp)),
            'usort 在 PHP 8 稳定、五个 hog 同分，故顺序确定'
        );
    }

    /**
     * 补充区**不得**按 symbol 去重：R4 在裸名不在 symbol_tab 时给出空 symbol（本夹具
     * 即此情形），一旦去重，这类递归结论会被折叠成一条。这条 spec 规则此前只被
     * r4SortsByDepthDescending 的夹具顺带守住
     * ——而那条测试的命名意图是排序方向，改写它就会静默丢掉本规则。
     */
    #[Test]
    public function supplementIsNotDeduped(): void
    {
        $raw = [
            'a@1==>a@2' => ['ct' => 1, 'wt' => 10],
            'b@1==>b@2' => ['ct' => 1, 'wt' => 10],
            'b@2==>b@3' => ['ct' => 1, 'wt' => 10],   // b 深度到 3，a 只到 2
        ];
        $tab = ['main()' => self::sym(1, 100, 10)];
        $supp = array_filter(
            Analyzer::analyze($tab, $raw, ['wt' => 100]),
            fn($f) => $f->severity === Finding::SEVERITY_SUPPLEMENT
        );

        $this->assertCount(2, $supp, '补充区不得按 symbol 去重（本夹具的 R4 symbol 是空串）');
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

    /**
     * 同一 symbol 同时命中 R1 与 R3 时只保留先到的 R1——这是合并顺序 R1 → R3 → R2
     * 加主区去重共同决定的**刻意取舍**：R1 是头部归因，R3 的具体调用方细节不再展示。
     *
     * 单独钉住它，是因为其余 R3 用例都把被调方的自身耗时压到 6%（避开 R1 的 10% 闸门）
     * 来隔离各自关注点，于是"碰撞时谁留下"就无人断言了。
     */
    #[Test]
    public function mainKeepsR1OverR3ForSameSymbol(): void
    {
        $tab = [
            'main()' => self::sym(1, 1000, 100),
            'foo()'  => self::sym(600, 600, 100),   // 自身恰好 10% → R1 命中；同时是 R3 的被调方
        ];
        $raw = [
            'main()'         => ['ct' => 1, 'wt' => 1000],
            'main()==>foo()' => ['ct' => 600, 'wt' => 400],
        ];
        $found = Analyzer::analyze($tab, $raw, ['wt' => 1000]);
        $main = array_values(array_filter($found, fn($f) => $f->severity === Finding::SEVERITY_MAIN));

        $this->assertSame(['main()', 'foo()'], array_map(fn($f) => $f->symbol, $main));
        $this->assertSame([], self::rule($found, 'R3'), 'R3 的 foo() 应与 R1 的同 symbol 结论合并，只留 R1');
    }

    /**
     * 去重必须先于切片。R3 逐边产出，同 symbol 结论可能占住前几个位置：
     * 先切片的话 MAIN_LIMIT 会被重复 symbol 吃掉，把一个与它们不同 symbol、
     * 排在后面的结论（这里是 R2 的 z()）永久藏起来。
     *
     * 这条断言的是**顺序**而不是"去了重"：只按 symbol 去重的用例（上面的
     * mainSectionDedupesRepeatedSymbols）先行切片也能通过——切片后剩下的三条
     * 恰好仍无重复 symbol。区别只在这种"队首重复、队尾有别"的形状上可见。
     */
    #[Test]
    public function mainSectionDedupesBeforeSlicingSoLaterSymbolsSurvive(): void
    {
        $tab = [
            'x()' => self::sym(1, 300, 200),      // R1（20%），同时是 R3 的被调方
            'y()' => self::sym(1, 100, 60),       // 只命中 R3（6%）
            'z()' => self::sym(2000, 50, 1),      // R2
        ];
        $raw = [
            'a()==>x()' => ['ct' => 600, 'wt' => 500],
            'a()==>y()' => ['ct' => 600, 'wt' => 300],
        ];
        $found = Analyzer::analyze($tab, $raw, ['wt' => 1000]);
        $main = array_values(array_filter($found, fn($f) => $f->severity === Finding::SEVERITY_MAIN));

        $this->assertSame(['x()', 'y()', 'z()'], array_map(fn($f) => $f->symbol, $main));
    }
}
