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
            'raw_data 为 false（get_run 失败的形态）' => [
                ['main()' => ['ct' => 1, 'wt' => 100, 'excl_wt' => 100]],
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
}
