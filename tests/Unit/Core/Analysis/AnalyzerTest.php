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
        } finally {
            // 不把 logger 泄漏给其他测试
            Xhprof::$logger = $prev;
            if (class_exists(\Hyperf\Context\Context::class)) {
                \Hyperf\Context\Context::set('xhprof.logger', null);
            }
        }

        $this->assertSame([], $result);
        $this->assertCount(1, $logger->errors, '规则异常必须写日志，否则坏了没人知道');
        $this->assertStringContainsString('Analyzer rule failed', $logger->errors[0]);
        $this->assertStringContainsString('rule blew up', $logger->errors[0]);
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
}
