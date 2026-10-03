<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Lib;

use ErikWang2013\Xhprof\Core\XhprofLib\Display\FlameGraph;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FlameGraph 单测。夹具是手工小边表，与 XHProfRunsDefault::get_run() 返回同形：
 * 一条无父键 'main()'（总量）+ 若干 '父==>子' 边。
 *
 * 抽取帧几何的锚是"<title>名字</title> 紧跟本帧 <rect>"——emit() 的输出形状，
 * 改形状这类断言会先红，这是刻意的（形状在接线说明里是契约）。
 */
class FlameGraphTest extends TestCase
{
    /** 基础夹具：main(100000) → a(60000)、b(30000)；a → a1(40000)。根有 10% 自身耗时。 */
    private function sampleRun(): array
    {
        return [
            'main()' => ['ct' => 1, 'wt' => 100000],
            'main()==>a()' => ['ct' => 1, 'wt' => 60000],
            'main()==>b()' => ['ct' => 1, 'wt' => 30000],
            'a()==>a1()' => ['ct' => 1, 'wt' => 40000],
        ];
    }

    /**
     * @return array<string, array{x: float, y: float, w: float}> 帧名（原始，未反转义）=> 几何
     */
    private static function frames(string $svg): array
    {
        preg_match_all(
            '/<title>(.*?)<\/title><rect x="(-?[0-9.]+)" y="(-?[0-9.]+)" width="(-?[0-9.]+)"/',
            $svg,
            $m,
            PREG_SET_ORDER
        );
        $out = [];
        foreach ($m as $row) {
            $out[$row[1]] = ['x' => (float) $row[2], 'y' => (float) $row[3], 'w' => (float) $row[4]];
        }
        return $out;
    }

    #[Test]
    public function widthsAreProportionalToMetricValues(): void
    {
        // 子值之和恰等于根值（无自身耗时尾巴）的夹具：孩子的槽宽之和应≈整幅画布
        $run = [
            'main()' => ['wt' => 100000],
            'main()==>a()' => ['wt' => 60000],
            'main()==>b()' => ['wt' => 40000],
            'a()==>a1()' => ['wt' => 40000],
        ];
        $r = FlameGraph::renderWithStats($run, 'wt');
        $f = self::frames($r['svg']);

        self::assertSame(4, $r['frames']);
        self::assertCount(4, $f);
        // 画布 1200：a = 60000/100000×1200 = 720，b = 480；矩形宽度各减 1 的间隙（GAP）
        self::assertEqualsWithDelta(719.0, $f['a()']['w'], 0.01);
        self::assertEqualsWithDelta(0.0, $f['a()']['x'], 0.01);
        self::assertEqualsWithDelta(480.0 - 1.0, $f['b()']['w'], 0.01);
        self::assertEqualsWithDelta(720.0, $f['b()']['x'], 0.01);
        // "宽度和 ≈ 根帧宽度"：两兄弟的槽宽（含 1 的间隙）正好铺满 1200
        self::assertEqualsWithDelta(1200.0, ($f['a()']['w'] + 1.0) + ($f['b()']['w'] + 1.0), 0.01);
        // 比例正确：a 的槽宽 / 画布 = 60000/100000
        self::assertEqualsWithDelta(0.60, ($f['a()']['w'] + 1.0) / 1200.0, 0.001);
        // 子树按同一比例：a1 = 40000/100000×1200 = 480，从 a 的左缘起
        self::assertEqualsWithDelta(479.0, $f['a1()']['w'], 0.01);
        self::assertEqualsWithDelta(0.0, $f['a1()']['x'], 0.01);
        // 根铺满整幅
        self::assertEqualsWithDelta(1199.0, $f['main()']['w'], 0.01);
    }

    #[Test]
    public function parentKeepsItsSelfTimeAsUncoveredTail(): void
    {
        // 子和 90000 < 根值 100000：差额 10% 是 main() 自身耗时，表现为根帧右侧没被子帧盖住的尾巴
        $f = self::frames(FlameGraph::render($this->sampleRun(), 'wt'));

        self::assertEqualsWithDelta(1199.0, $f['main()']['w'], 0.01);      // 根仍全宽
        // 子帧只铺到 1080（90000/100000×1200），根右侧 120 是没被盖住的自身耗时
        self::assertEqualsWithDelta(1080.0, $f['b()']['x'] + $f['b()']['w'] + 1.0, 0.01);
        self::assertEqualsWithDelta(120.0, ($f['main()']['x'] + $f['main()']['w'] + 1.0) - 1080.0, 0.01);
    }

    #[Test]
    public function depthMapsToRowAndViewBoxHeight(): void
    {
        $run = [
            'main()' => ['wt' => 100],
            'main()==>a()' => ['wt' => 80],
            'a()==>b()' => ['wt' => 60],
            'b()==>c()' => ['wt' => 40],
        ];
        $r = FlameGraph::renderWithStats($run, 'wt');
        $f = self::frames($r['svg']);

        self::assertSame(3, $r['max_depth']);
        // 深度 → 行：根在最下（y 最大），每层 20 单位，4 层 = 80 高
        self::assertStringContainsString('viewBox="0 0 1200.00 80.00"', $r['svg']);
        self::assertEqualsWithDelta(60.0, $f['main()']['y'], 0.01);
        self::assertEqualsWithDelta(40.0, $f['a()']['y'], 0.01);
        self::assertEqualsWithDelta(20.0, $f['b()']['y'], 0.01);
        self::assertEqualsWithDelta(0.0, $f['c()']['y'], 0.01);
    }

    #[Test]
    public function topNPruningKeepsLargestSubtreesAndReportsRatio(): void
    {
        // 预算 3（含根）：贪心取最大子树 → main、a(60000)、a1(40000)；b(30000) 该被剪
        $r = FlameGraph::renderWithStats($this->sampleRun(), 'wt', 3);
        $f = self::frames($r['svg']);

        self::assertSame(3, $r['frames']);
        self::assertSame(3, $r['max_frames']);
        self::assertSame(['main()', 'a()', 'a1()'], array_keys($f));
        self::assertStringNotContainsString('b()', $r['svg']);   // 剪掉的小帧整棵不出现
        // 被剪掉的比例 = 剪枝时仍在队列里的值 / 根值
        self::assertEqualsWithDelta(30000.0, $r['pruned_value'], 0.01);
        self::assertEqualsWithDelta(0.30, $r['pruned_pct'], 0.001);

        // 预算够大时一个不剪
        $full = FlameGraph::renderWithStats($this->sampleRun(), 'wt', 60);
        self::assertSame(4, $full['frames']);
        self::assertSame(0.0, $full['pruned_pct']);
    }

    #[Test]
    public function recursionExpandedNamesKeepFramesDistinctAndBackEdgesAreDropped(): void
    {
        $run = [
            'main()' => ['wt' => 1000],
            'main()==>fib' => ['wt' => 900],
            'fib==>fib@1' => ['wt' => 600],
            'fib@1==>fib@2' => ['wt' => 400],
            'fib@2==>fib' => ['wt' => 100],      // 成环：fib 已在祖先链上 → 丢边
            'fib@2==>fib@2' => ['wt' => 50],     // 自环 → 丢边
        ];
        $r = FlameGraph::renderWithStats($run, 'wt');
        $f = self::frames($r['svg']);

        // 递归展开名各成一帧、逐层可区分（名字就带 @n），且两条坏边没有把树撑大/撑爆
        self::assertSame(['main()', 'fib', 'fib@1', 'fib@2'], array_keys($f));
        self::assertSame(3, $r['max_depth']);
        self::assertCount(1, array_intersect(['fib@1'], array_keys($f)));
        // 丢掉的权重记进"未显示"，不静默吞
        self::assertEqualsWithDelta(150.0, $r['pruned_value'], 0.01);
        self::assertEqualsWithDelta(0.15, $r['pruned_pct'], 0.001);
    }

    #[Test]
    public function emptyOrMainlessDataRendersNothing(): void
    {
        self::assertSame('', FlameGraph::render([]));
        // 有边但没有 main()，主调不在场
        self::assertSame('', FlameGraph::render(['a()==>b()' => ['wt' => 10]]));
        // 无父键但不是 main()：不构成"有 main()"
        self::assertSame('', FlameGraph::render(['foo()' => ['wt' => 5]]));
        // 该指标本次没采集（全 0）
        self::assertSame('', FlameGraph::render($this->sampleRun(), 'cpu'));

        $r = FlameGraph::renderWithStats([], 'wt');
        self::assertSame('', $r['svg']);
        self::assertSame(0, $r['frames']);
        self::assertSame(0.0, $r['pruned_value']);
        self::assertSame(0.0, $r['pruned_pct']);
    }

    #[Test]
    public function functionNamesAreEscapedInTitleAndText(): void
    {
        $evil = '<script>alert(1)</script>" onmouseover="x';
        $run = [
            'main()' => ['wt' => 1000000],
            'main()==>' . $evil => ['wt' => 500000],  // 宽帧：title 与 text 都要过
            'main()==>tiny<x>' => ['wt' => 1000],     // 窄帧：只剩 title
        ];
        $svg = FlameGraph::render($run, 'wt');

        self::assertStringNotContainsString('<script', $svg);
        self::assertStringContainsString('&lt;script&gt;', $svg);
        // 引号在属性上下文同样要转义（名字进的是 <title> 文本节点，但双保险按 ENT_QUOTES 走）
        self::assertStringNotContainsString('" onmouseover="', $svg);
        self::assertStringContainsString('&quot; onmouseover=&quot;', $svg);
        self::assertStringContainsString('<title>tiny&lt;x&gt;</title>', $svg);
    }

    #[Test]
    public function frameUrlCallbackIsCalledPerDrawnFrameOnly(): void
    {
        $calls = [];
        $url = static function (string $fn) use (&$calls): string {
            $calls[] = $fn;
            // 与 report_url() 同形：属性安全（查询串已转义），本类原样进 href、不再转义
            return $fn === 'main()' ? '' : '?symbol=' . rawurlencode($fn) . '&amp;lang=zh';
        };
        $r = FlameGraph::renderWithStats($this->sampleRun(), 'wt', 3, $url);

        // 只对真正画出的帧回调（b() 被剪掉 → 没回调）；每次帧一次
        self::assertSame(['main()', 'a()', 'a1()'], $calls);
        self::assertStringContainsString('<a href="?symbol=a%28%29&amp;lang=zh">', $r['svg']);
        self::assertStringNotContainsString('b()', $r['svg']);
        // 回调返回空串 → 该帧不带链接，退回 <g> 容器
        self::assertStringNotContainsString('<a href=""', $r['svg']);
        self::assertStringContainsString('<g><title>main()</title>', $r['svg']);
        // 不重复转义：&amp; 必须原样，不能变成 &amp;amp;（report_url 的契约）
        self::assertStringNotContainsString('&amp;amp;', $r['svg']);
        // 不传回调 → 一个链接都不产
        self::assertStringNotContainsString('<a ', FlameGraph::render($this->sampleRun(), 'wt'));
    }

    #[Test]
    public function frameColorsTierByValueShare(): void
    {
        $run = [
            'main()' => ['wt' => 100000],
            'main()==>hot()' => ['wt' => 50000],   // 50% ≥ SHARE_HOT
            'main()==>warm()' => ['wt' => 20000],  // 20% ≥ SHARE_WARM
            'main()==>cold()' => ['wt' => 5000],   // 5% → 最冷档
        ];
        $svg = FlameGraph::render($run, 'wt');

        self::assertSame(2, substr_count($svg, 'fill="var(--xp-orange, #bc4c00)"'));   // main() + hot()
        self::assertSame(1, substr_count($svg, 'fill="var(--xp-accent, #0969da)"'));   // warm()
        self::assertSame(1, substr_count($svg, 'fill="var(--xp-text-muted, #57606a)"')); // cold()
    }

    #[Test]
    public function narrowFramesDropTextButKeepTitle(): void
    {
        $run = [
            'main()' => ['wt' => 1000000],
            'main()==>wideFunctionName()' => ['wt' => 400000],  // 480 宽：文本放得下
            'main()==>tinyFunctionName()' => ['wt' => 2000],    // 2.4 宽：放不下就不画文本
        ];
        $svg = FlameGraph::render($run, 'wt');

        self::assertStringContainsString('>wideFunctionName()</text>', $svg);
        self::assertStringContainsString('<title>tinyFunctionName()</title>', $svg);
        self::assertStringNotContainsString('>tinyFunctionName()</text>', $svg);
        self::assertSame(2, substr_count($svg, '<text'));   // 只有 main() 与宽帧
    }

    #[Test]
    public function equalValuesTieBreakBySymbolAndOutputIsDeterministic(): void
    {
        $run = [
            'main()' => ['wt' => 100000],
            'main()==>zeta()' => ['wt' => 30000],   // 先入数组，但平手按符号升序排
            'main()==>alpha()' => ['wt' => 30000],
        ];
        $svg = FlameGraph::render($run, 'wt');
        $f = self::frames($svg);

        self::assertEqualsWithDelta(0.0, $f['alpha()']['x'], 0.01);
        self::assertEqualsWithDelta(360.0, $f['zeta()']['x'], 0.01);
        self::assertSame($svg, FlameGraph::render($run, 'wt')); // 同输入必得同输出
    }

    #[Test]
    public function negativeAndMissingMetricValuesAreClampedToZeroWidth(): void
    {
        $run = [
            'main()' => ['wt' => 100],
            'main()==>neg()' => ['wt' => -5],        // 负值：钳到 0，不产负宽矩形
            'main()==>ok()' => ['wt' => 50],
        ];
        $svg = FlameGraph::render($run, 'wt');

        self::assertStringNotContainsString('width="-', $svg);
        self::assertStringNotContainsString('neg()', $svg);   // 0 宽画不出来，也不占帧预算
        self::assertStringContainsString('ok()', $svg);
    }
}
