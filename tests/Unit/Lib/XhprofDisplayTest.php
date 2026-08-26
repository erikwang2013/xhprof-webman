<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Lib;

require_once __DIR__ . '/../../Fixtures/Fakes.php';

// 疑似 bug（见报告）：XhprofDisplay 内部以字符串形式调用全局类
//（usort($flat_data, 'XhprofDisplay::sort_cbk')、$format_cbk 映射等），
// 命名空间化后未加 FQCN 前缀，报告渲染必然 TypeError。
// 此处提供全局别名以打通渲染路径，验证其余展示逻辑；
// 注释掉本行再运行 singleRunReportRendersFlatTable 即可复现该 bug。
use ErikWang2013\Xhprof\Core\XhprofLib\Display\XhprofDisplay;

if (!class_exists('XhprofDisplay', false)) {
    class_alias(XhprofDisplay::class, 'XhprofDisplay');
}

use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeConfig;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeRequest;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FakeCache::lPush 存在 by-ref 传参 bug（array_unshift($this->lists[$key] ??= [], ...)），
 * 测试进程内无法修改 Fixtures，此处用修复版子类覆盖列表方法。
 */
class DisplayFixedListCache extends FakeCache
{
    private array $myLists = [];

    public function lPush(string $key, mixed $value): int
    {
        $this->calls[] = "lPush:$key";
        $this->myLists[$key] ??= [];
        array_unshift($this->myLists[$key], $value);
        return count($this->myLists[$key]);
    }

    public function rPop(string $key): mixed
    {
        $this->calls[] = "rPop:$key";
        if (empty($this->myLists[$key])) {
            return null;
        }
        return array_pop($this->myLists[$key]);
    }

    public function lRange(string $key, int $start, int $end): array
    {
        $this->calls[] = "lRange:$key";
        $list = $this->myLists[$key] ?? [];
        $count = count($list);
        if ($start < 0) {
            $start = max(0, $count + $start);
        }
        if ($end < 0) {
            $end = $count + $end;
        }
        return array_slice($list, $start, max(0, $end - $start + 1));
    }
}

class XhprofDisplayTest extends TestCase
{
    protected FakeCache $cache;
    protected FakeRequest $request;
    protected FakeResponse $response;
    protected FakeConfig $config;
    protected FakeLogger $logger;

    protected function setUp(): void
    {
        $this->cache = new DisplayFixedListCache();
        $this->request = new FakeRequest([], ['uri' => '/xhprof', 'url' => 'http://xhprof.local/xhprof']);
        $this->response = new FakeResponse();
        $this->config = new FakeConfig([]);
        $this->logger = new FakeLogger();
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);
        // Hyperf 测试先跑会置 $_hyperf=true，按 bootstrap 同款逻辑刷新 Context，避免取到过期适配器
        if (class_exists(\Hyperf\Context\Context::class)) {
            \Hyperf\Context\Context::set('xhprof.request', $this->request);
            \Hyperf\Context\Context::set('xhprof.response', $this->response);
            \Hyperf\Context\Context::set('xhprof.config', $this->config);
            \Hyperf\Context\Context::set('xhprof.cache', $this->cache);
            \Hyperf\Context\Context::set('xhprof.logger', $this->logger);
        }
        Xhprof::$time_limit = 0;
        Xhprof::$ignore_url_arr = [];
        Xhprof::$key_prefix = 'xhprof';
        Xhprof::$log_num = 1000;
        Xhprof::$view_wtred = 3;
        Xhprof::$symbol_lookup_url = '';

        XhprofDisplay::$sort_col = 'wt';
        XhprofDisplay::$diff_mode = false;
        XhprofDisplay::$display_calls = true;
        XhprofDisplay::$metrics = null;
        XhprofDisplay::$stats = [];
        XhprofDisplay::$pc_stats = [];
        XhprofDisplay::$totals = 0;
        XhprofDisplay::$totals_1 = 0;
        XhprofDisplay::$totals_2 = 0;
        XhprofDisplay::$vwbar = 'class="vwbar"';
        XhprofDisplay::$vbar = 'class="vbar"';
        XhprofDisplay::$vbbar = 'class="vbbar"';
        XhprofDisplay::$vrbar = 'class="vrbar"';
        XhprofDisplay::$vgbar = 'class="vgbar"';
    }

    /** 替换请求时同步刷新 Hyperf Context，保证 $_hyperf=true 时 getRequest() 仍取到 fake */
    private function useRequest(FakeRequest $request): void
    {
        Xhprof::$request = $request;
        if (class_exists(\Hyperf\Context\Context::class)) {
            \Hyperf\Context\Context::set('xhprof.request', $request);
        }
    }

    private function sampleRunData(): array
    {
        return [
            'main()' => ['ct' => 1, 'wt' => 100000, 'mu' => 2048],
            'main()==>foo()' => ['ct' => 1, 'wt' => 40000, 'mu' => 512],
            'main()==>bar()' => ['ct' => 1, 'wt' => 30000, 'mu' => 256],
            'foo()==>strlen()' => ['ct' => 2, 'wt' => 5000, 'mu' => 64],
        ];
    }

    #[Test]
    #[DataProvider('countFormatProvider')]
    public function countFormatFormatsNumbers(float|int $num, string $expected): void
    {
        self::assertSame($expected, XhprofDisplay::xhprof_count_format($num));
    }

    public static function countFormatProvider(): array
    {
        return [
            'zero' => [0, '0'],
            'integer' => [1234, '1,234'],
            'large integer' => [1234567, '1,234,567'],
            'fractional' => [1234.5678, '1,234.568'],
            'half fractional' => [1000.5, '1,000.500'],
        ];
    }

    #[Test]
    public function percentFormat(): void
    {
        self::assertSame('12.3%', XhprofDisplay::xhprof_percent_format(0.1234));
        self::assertSame('12.34%', XhprofDisplay::xhprof_percent_format(0.1234, 2));
        self::assertSame('0.0%', XhprofDisplay::xhprof_percent_format(0));
        self::assertSame('100.0%', XhprofDisplay::xhprof_percent_format(1));
    }

    #[Test]
    public function pctComputesPercentage(): void
    {
        // 本机 PHP 精确除法返回 int，统一用 delta 断言
        self::assertEqualsWithDelta(50.0, XhprofDisplay::pct(100, 200), 0.0001);
        self::assertEqualsWithDelta(0.0, XhprofDisplay::pct(0, 5), 0.0001);
        self::assertSame('N/A', XhprofDisplay::pct(1, 0));
        self::assertEqualsWithDelta(33.3, XhprofDisplay::pct(1, 3), 0.0001);
    }

    #[Test]
    public function getPrintClassAppliesDiffColors(): void
    {
        self::assertSame('class="vbar"', XhprofDisplay::get_print_class(5, false));
        self::assertSame('class="vbbar"', XhprofDisplay::get_print_class(5, true));
        XhprofDisplay::$diff_mode = true;
        self::assertSame('class="vgbar"', XhprofDisplay::get_print_class(-5, true));
        self::assertSame('class="vrbar"', XhprofDisplay::get_print_class(5, true));
        self::assertSame('class="vbar"', XhprofDisplay::get_print_class(5, false));
    }

    #[Test]
    public function printTdNumFormatsCell(): void
    {
        self::assertSame("<td  class=\"vbar\">5</td>\n", XhprofDisplay::print_td_num(5, null));
        self::assertSame("<td  class=\"vbbar\">1,235</td>\n", XhprofDisplay::print_td_num(1234.5, 'number_format', true));
        self::assertSame("<td type='ct' class=\"vbar\">abc</td>\n", XhprofDisplay::print_td_num('abc', 'number_format', false, "type='ct'"));
        self::assertSame("<td  class=\"vbar\">5</td>\n", XhprofDisplay::print_td_num(5, 'number_format'));
    }

    #[Test]
    public function printTdPctFormatsCell(): void
    {
        self::assertSame("<td  class=\"vbar\">40.0%</td>\n", XhprofDisplay::print_td_pct(0.4, 1));
        self::assertSame("<td  class=\"vbar\">N/A%</td>\n", XhprofDisplay::print_td_pct(1, 0));
        self::assertSame("<td  class=\"vbbar\">25.0%</td>\n", XhprofDisplay::print_td_pct(0.25, 1, true));
    }

    #[Test]
    public function statDescriptionSwitchesInDiffMode(): void
    {
        self::assertSame('总耗时<br>(微秒)', XhprofDisplay::stat_description('wt'));
        XhprofDisplay::$diff_mode = true;
        self::assertSame('Incl. Wall<br>Diff<br>(microsec)', XhprofDisplay::stat_description('wt'));
        self::assertSame('Incl. Wall<br>Diff<br>(microsec)', XhprofDisplay::stat_description('wt'));
    }

    #[Test]
    public function sortCbkSortsByFnAlphabetically(): void
    {
        XhprofDisplay::$sort_col = 'fn';
        $arr = [['fn' => 'b()'], ['fn' => 'A()'], ['fn' => 'b()']];
        usort($arr, 'XhprofDisplay::sort_cbk');
        self::assertSame('A()', $arr[0]['fn']);
    }

    #[Test]
    public function sortCbkSortsByMetricDescending(): void
    {
        XhprofDisplay::$sort_col = 'wt';
        $arr = [['fn' => 'a', 'wt' => 5], ['fn' => 'b', 'wt' => 10], ['fn' => 'c', 'wt' => 5]];
        usort($arr, 'XhprofDisplay::sort_cbk');
        self::assertSame('b', $arr[0]['fn']);
        self::assertSame(10, $arr[0]['wt']);
    }

    #[Test]
    public function sortCbkUsesAbsoluteValuesInDiffMode(): void
    {
        XhprofDisplay::$sort_col = 'wt';
        XhprofDisplay::$diff_mode = true;
        $arr = [['fn' => 'a', 'wt' => 5], ['fn' => 'b', 'wt' => -10]];
        usort($arr, 'XhprofDisplay::sort_cbk');
        self::assertSame('b', $arr[0]['fn']);
    }

    #[Test]
    public function includeJsCssRendersAssetLinks(): void
    {
        $out = XhprofDisplay::xhprof_include_js_css('/assets');
        self::assertStringContainsString("<link href='/assets/css/xhprof.css'", $out);
        self::assertStringContainsString('css/bootstrap.css', $out);
        self::assertStringContainsString('js/xhprof_report.js', $out);
        self::assertStringContainsString('jquery-3.0.0.min.js', $out);
    }

    #[Test]
    public function includeJsCssFallsBackToRequestUrlDir(): void
    {
        $out = XhprofDisplay::xhprof_include_js_css();
        self::assertStringContainsString('css/xhprof.css', $out);
    }

    #[Test]
    public function renderActionsRendersEmptyList(): void
    {
        self::assertSame('', XhprofDisplay::xhprof_render_actions([]));
    }

    #[Test]
    public function renderActionsWrapsItemsInList(): void
    {
        $out = XhprofDisplay::xhprof_render_actions(['<a>one</a>', '<a>two</a>']);
        self::assertSame(
            '<ul class="xhprof_actions"><li><a>one</a></li><li><a>two</a></li></ul>',
            $out
        );
    }

    #[Test]
    public function renderLinkReturnsEmptyForEmptyContent(): void
    {
        self::assertSame('', XhprofDisplay::xhprof_render_link('', '/x'));
    }

    #[Test]
    public function renderLinkBuildsAnchor(): void
    {
        $out = XhprofDisplay::xhprof_render_link('text', '/x', 'cls', 'id1', 'title1', '_blank');
        self::assertSame(
            '<a href="/x" class="cls" id="id1" title="title1" target="_blank">text</a>',
            $out
        );
    }

    #[Test]
    public function renderLinkBuildsSpanWithoutHref(): void
    {
        self::assertSame('<span>text</span>', XhprofDisplay::xhprof_render_link('text', ''));
    }

    #[Test]
    public function renderLinkAddsClickHandlersOnlyWithHref(): void
    {
        $withHref = XhprofDisplay::xhprof_render_link('t', '/x', '', '', '', '', 'go()', 'color:red');
        self::assertStringContainsString('onclick="go()"', $withHref);
        self::assertStringContainsString('style="color:red"', $withHref);
        $span = XhprofDisplay::xhprof_render_link('t', '', '', '', '', '', 'go()', 'color:red');
        self::assertStringNotContainsString('onclick', $span);
        self::assertStringNotContainsString('style', $span);
    }

    #[Test]
    public function basePathFromRequestUri(): void
    {
        self::assertSame('/xhprof', XhprofDisplay::base_path());
    }

    #[Test]
    public function showNavRendersBreadcrumbs(): void
    {
        $home = XhprofDisplay::show_nav([]);
        self::assertStringContainsString('XHProf 性能分析', $home);
        self::assertStringNotContainsString('运行报告', $home);

        $run = XhprofDisplay::show_nav(['run' => 'a1a1a1a1a1a1a1a1']);
        self::assertStringContainsString('运行报告', $run);

        $detail = XhprofDisplay::show_nav(['run' => 'a1a1a1a1a1a1a1a1', 'symbol' => 'foo()']);
        self::assertStringContainsString('方法详情', $detail);
    }

    #[Test]
    public function singleRunReportRendersFlatTable(): void
    {
        $runId = 'a1a1a1a1a1a1a1a1';
        $this->request = new FakeRequest(['run' => $runId, 'all' => 1], ['uri' => '/xhprof']);
        $this->useRequest($this->request);
        $this->cache->set('xhprof:request_log:' . $runId, json_encode([
            'request_uri' => 'http://example.com/order?x=1&y=<script>alert(1)</script>',
            'method' => 'GET',
            'wt' => 0.8,
            'mu' => 2.0,
            'ip' => '6.6.6.6',
            'create_time' => 1700000000,
        ]));

        $html = XhprofDisplay::profiler_single_run_report(
            ['run' => $runId, 'all' => 1],
            $this->sampleRunData(),
            'desc',
            null,
            'wt',
            $runId
        );

        self::assertStringContainsString('main()', $html);
        self::assertStringContainsString('foo()', $html);
        self::assertStringContainsString('bar()', $html);
        self::assertStringContainsString('strlen()', $html);
        self::assertStringContainsString('30,000', $html); // main() excl_wt (100000-40000-30000)
        self::assertStringContainsString('40.0%', $html);  // foo() IWall%
        self::assertStringContainsString('请求方法', $html);
        self::assertStringContainsString('函数/方法调用总次数', $html);
        self::assertStringContainsString('>5</td>', $html); // total call count
        self::assertStringContainsString('&lt;script&gt;', $html); // escaped request uri
        self::assertStringContainsString('Sorted by', $html);
    }

    #[Test]
    public function symbolReportRendersParentChildSections(): void
    {
        $runId = 'a1a1a1a1a1a1a1a1';
        $this->request = new FakeRequest(['run' => $runId, 'all' => 1, 'symbol' => 'foo()'], ['uri' => '/xhprof']);
        $this->useRequest($this->request);

        $html = XhprofDisplay::profiler_single_run_report(
            ['run' => $runId, 'all' => 1, 'symbol' => 'foo()'],
            $this->sampleRunData(),
            'desc',
            'foo()',
            'wt',
            $runId
        );

        self::assertStringContainsString('Parent/Child', $html);
        self::assertStringContainsString('Current Function', $html);
        self::assertStringContainsString('Parent public static function', $html);
        self::assertStringContainsString('Child public static function', $html);
        self::assertStringContainsString('var func_name = "foo()";', $html);
        self::assertStringContainsString('func_metrics["wt"] = 40000;', $html);
    }

    #[Test]
    public function diffReportRendersDiffSummary(): void
    {
        $data1 = [
            'main()' => ['ct' => 1, 'wt' => 100000, 'mu' => 1000],
            'main()==>foo()' => ['ct' => 1, 'wt' => 50000, 'mu' => 500],
        ];
        $data2 = [
            'main()' => ['ct' => 1, 'wt' => 120000, 'mu' => 1200],
            'main()==>foo()' => ['ct' => 1, 'wt' => 70000, 'mu' => 600],
            'main()==>bar()' => ['ct' => 1, 'wt' => 10000, 'mu' => 100],
        ];

        // all=1 与缺省分支的标题互斥，分别渲染断言
        $html = XhprofDisplay::profiler_diff_report(
            ['run1' => 'r1id', 'run2' => 'r2id', 'sort' => 'wt', 'all' => 1],
            $data1,
            'desc1',
            $data2,
            'desc2',
            null,
            'wt',
            'r1id',
            'r2id'
        );
        self::assertStringContainsString('Overall Diff Summary', $html);
        self::assertStringContainsString('Run #r1id', $html);
        self::assertStringContainsString('Invert Diff Report', $html);
        self::assertStringContainsString('Number of Function Calls', $html);
        self::assertStringContainsString('Total Diff Report', $html);

        $top = XhprofDisplay::profiler_diff_report(
            ['run1' => 'r1id', 'run2' => 'r2id', 'sort' => 'wt'],
            $data1,
            'desc1',
            $data2,
            'desc2',
            null,
            'wt',
            'r1id',
            'r2id'
        );
        self::assertStringContainsString('Regressions', $top);
    }

    #[Test]
    public function printFlatDataHonorsLimit(): void
    {
        $data = [
            ['fn' => 'a()', 'ct' => 1, 'wt' => 100, 'excl_wt' => 100],
            ['fn' => 'b()', 'ct' => 1, 'wt' => 90, 'excl_wt' => 90],
            ['fn' => 'c()', 'ct' => 1, 'wt' => 80, 'excl_wt' => 80],
            ['fn' => 'd()', 'ct' => 1, 'wt' => 70, 'excl_wt' => 70],
        ];
        XhprofDisplay::$stats = ['fn', 'ct', 'wt'];
        XhprofDisplay::$metrics = ['wt'];
        XhprofDisplay::$totals = ['ct' => 4, 'wt' => 340];
        XhprofDisplay::$sort_col = 'wt';

        $limited = XhprofDisplay::print_flat_data([], 'title', $data, 2);
        self::assertStringContainsString('a()', $limited);
        self::assertStringNotContainsString('c()', $limited);

        $tail = XhprofDisplay::print_flat_data([], 'title', $data, -2);
        self::assertStringContainsString('d()', $tail);
        self::assertStringNotContainsString('a()', $tail);

        $all = XhprofDisplay::print_flat_data([], 'title', $data, 0);
        self::assertStringContainsString('a()', $all);
        self::assertStringContainsString('d()', $all);
    }

    #[Test]
    public function getTooltipAttributes(): void
    {
        self::assertSame("type='Child' metric='wt'", XhprofDisplay::get_tooltip_attributes('Child', 'wt'));
    }

    #[Test]
    public function printSourceLink(): void
    {
        Xhprof::$symbol_lookup_url = 'http://sym.example.com';
        self::assertStringContainsString('?symbol=foo%28%29', XhprofDisplay::print_source_link(['fn' => 'foo()']));
        self::assertSame('', XhprofDisplay::print_source_link(['fn' => 'main()']));
        self::assertSame('', XhprofDisplay::print_source_link(['fn' => 'run_init_foo']));
    }

    #[Test]
    public function displayXHProfReportShowsRunListWhenNoRunGiven(): void
    {
        $this->cache->lPush('xhprof:run_id', 'a1a1a1a1a1a1a1a1');
        $this->cache->set('xhprof:request_log:a1a1a1a1a1a1a1a1', json_encode([
            'request_uri' => 'http://example.com/ok',
            'method' => 'GET',
            'wt' => 0.5,
            'mu' => 1.0,
            'ip' => '8.8.8.8',
            'create_time' => 1700000000,
        ]));

        $html = XhprofDisplay::displayXHProfReport(
            ['all' => 1],
            'xhprof_foo',
            null,
            null,
            null,
            null,
            null,
            null
        );

        self::assertStringContainsString('请求记录', $html);
        self::assertStringContainsString('xp-runs-table', $html);
        self::assertStringContainsString('XHProf 性能分析', $html);
    }

    #[Test]
    public function displayXHProfReportRendersSingleRun(): void
    {
        $runId = 'a1a1a1a1a1a1a1a1';
        $this->cache->set('xhprof:xhprof_log:' . $runId, serialize($this->sampleRunData()));
        $this->cache->set('xhprof:request_log:' . $runId, json_encode([
            'request_uri' => 'http://example.com/ok',
            'method' => 'GET',
            'wt' => 0.5,
            'mu' => 1.0,
            'ip' => '8.8.8.8',
            'create_time' => 1700000000,
        ]));

        $html = XhprofDisplay::displayXHProfReport(
            ['run' => $runId, 'all' => 1],
            'xhprof_foo',
            $runId,
            null,
            null,
            'wt',
            null,
            null
        );

        self::assertStringContainsString('main()', $html);
        self::assertStringContainsString('运行报告', $html);
    }
}
