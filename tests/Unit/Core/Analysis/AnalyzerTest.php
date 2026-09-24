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
            // 人工构造：真实路径不会产生这个组合 —— get_run 失败（false）时
            // flat_info 返回的是**空** symbol_tab，analyze() 会在入口守卫处就返回。
            // 保留它是因为它守护 analyze() 入口的归一化：没有归一化时
            // foreach(false) 会发 warning，在 failOnWarning 下即为红。
            '人工构造：symbol_tab 非空 + raw_data 为 false（守护入口归一化）' => [
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

    /** 裸 main() 键没有父，不能当成边来处理 */
    #[Test]
    public function r3SkipsBareMainKey(): void
    {
        $tab = ['main()' => self::sym(1, 1000, 100)];
        $raw = ['main()' => ['ct' => 9999, 'wt' => 900]];
        $found = Analyzer::analyze($tab, $raw, ['wt' => 1000]);
        $this->assertSame([], array_filter($found, fn($f) => $f->rule === 'R3'));
    }
}
