<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Lib;

require_once __DIR__ . '/../../Fixtures/Fakes.php';

use ErikWang2013\Xhprof\Core\Analysis\Finding;
use ErikWang2013\Xhprof\Core\XhprofLib\Display\XhprofDisplay;
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
        usort($arr, [XhprofDisplay::class, 'sort_cbk']);
        self::assertSame('A()', $arr[0]['fn']);
    }

    #[Test]
    public function sortCbkSortsByMetricDescending(): void
    {
        XhprofDisplay::$sort_col = 'wt';
        $arr = [['fn' => 'a', 'wt' => 5], ['fn' => 'b', 'wt' => 10], ['fn' => 'c', 'wt' => 5]];
        usort($arr, [XhprofDisplay::class, 'sort_cbk']);
        self::assertSame('b', $arr[0]['fn']);
        self::assertSame(10, $arr[0]['wt']);
    }

    #[Test]
    public function sortCbkUsesAbsoluteValuesInDiffMode(): void
    {
        XhprofDisplay::$sort_col = 'wt';
        XhprofDisplay::$diff_mode = true;
        $arr = [['fn' => 'a', 'wt' => 5], ['fn' => 'b', 'wt' => -10]];
        usort($arr, [XhprofDisplay::class, 'sort_cbk']);
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

        // pc_info 必须把单元格拼进行内（type='Parent' 仅由它产出）。
        // 若它丢弃 print_td_* 的返回值，父/子行会只剩函数名一列，表格错位。
        self::assertStringContainsString("type='Parent' metric='wt'", $html);
        self::assertStringContainsString("type='Parent' metric='ct'", $html);
        // main()==>foo()：父行 main() 的 wt 单元格应含 40,000
        self::assertMatchesRegularExpression(
            "/type='Parent' metric='wt'[^>]*>40,000<\/td>/",
            $html
        );
    }

    /**
     * 曾经的崩溃：$rep_symbol 不在 run 里时，"not found" 分支缺少 return，
     * 继续把 null 传进 symbol_report()，在 round() 处抛 TypeError → 整页 500。
     * 触发极日常：收藏的旧链接、从别的 run 复制的函数名。
     */
    #[Test]
    public function symbolNotInRunShowsNotFoundWithoutCrashing(): void
    {
        $runId = 'a1a1a1a1a1a1a1a1';
        $this->useRequest(new FakeRequest(
            ['run' => $runId, 'all' => 1, 'symbol' => 'does_not_exist()'],
            ['uri' => '/xhprof']
        ));

        $html = XhprofDisplay::profiler_single_run_report(
            ['run' => $runId, 'all' => 1, 'symbol' => 'does_not_exist()'],
            $this->sampleRunData(),
            'desc',
            'does_not_exist()',
            'wt',
            $runId
        );

        self::assertStringContainsString('not found in XHProf run', $html);
    }

    /**
     * 曾经的崩溃：symbol 只存在于其中一个 run（新增/删除的函数，正是 diff 模式的目标场景）时，
     * $avg_info1/$avg_info2 会保持字符串 'N/A'，float - 'N/A' 在 PHP 8 抛 TypeError。
     */
    #[Test]
    public function diffReportHandlesSymbolPresentInOnlyOneRun(): void
    {
        $run1 = [
            'main()' => ['ct' => 1, 'wt' => 100000, 'mu' => 100],
        ];
        $run2 = [
            'main()' => ['ct' => 1, 'wt' => 100000, 'mu' => 100],
            'main()==>foo()' => ['ct' => 2, 'wt' => 40000, 'mu' => 200],
        ];
        $this->useRequest(new FakeRequest(
            ['run1' => 'r1', 'run2' => 'r2', 'symbol' => 'foo()', 'all' => 1],
            ['uri' => '/xhprof']
        ));

        $html = XhprofDisplay::profiler_diff_report(
            ['run1' => 'r1', 'run2' => 'r2', 'symbol' => 'foo()', 'all' => 1],
            $run1,
            'd1',
            $run2,
            'd2',
            'foo()',
            'wt',
            'r1',
            'r2'
        );

        self::assertStringContainsString('foo()', $html);
    }

    /**
     * 曾经的崩溃：?sort=ut 通过静态白名单校验，但本扩展的 flags 永不采集 ut，
     * sort_cbk 里 abs(null) 抛 TypeError（diff 模式）。
     */
    #[Test]
    public function diffReportIgnoresSortByMetricNotCollected(): void
    {
        $data = [
            'main()' => ['ct' => 1, 'wt' => 100000, 'mu' => 100],
            'main()==>foo()' => ['ct' => 1, 'wt' => 40000, 'mu' => 50],
        ];
        $this->useRequest(new FakeRequest(
            ['run1' => 'r1', 'run2' => 'r2', 'sort' => 'ut', 'all' => 1],
            ['uri' => '/xhprof']
        ));

        $html = XhprofDisplay::profiler_diff_report(
            ['run1' => 'r1', 'run2' => 'r2', 'sort' => 'ut', 'all' => 1],
            $data,
            'd1',
            $data,
            'd2',
            null,
            'ut',
            'r1',
            'r2'
        );

        self::assertStringContainsString('Overall Diff Summary', $html);
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
        // onmouseover 是 xhprof_report.js 里 ChildRowToolTip 的唯一触发点，必须存在
        self::assertSame(
            "type='Child' metric='wt' onmouseover=\"return ChildRowToolTip(this, 'wt');\"",
            XhprofDisplay::get_tooltip_attributes('Child', 'wt')
        );
        self::assertStringContainsString(
            'ParentRowToolTip',
            XhprofDisplay::get_tooltip_attributes('Parent', 'mu')
        );
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

        // 钉住归属关系，而不只是「这些串都出现了」：主结论必须出现在「其他发现」之前
        self::assertLessThan(
            strpos($html, '其他发现'),
            strpos($html, 'foo() 自身耗时'),
            '主区结论必须在补充区标题之前'
        );
    }

    /** R4 的 symbol 是空串（见 Analyzer::ruleR4），此时不该给出指向"未找到"详情页的死链 */
    #[Test]
    public function renderDiagnosisOmitsLinkWhenSymbolIsEmpty(): void
    {
        $html = XhprofDisplay::render_diagnosis(
            [new Finding('R4', Finding::SEVERITY_SUPPLEMENT, '', '检测到 fib() 递归，最大深度 6', '递归深度过大', 6.0)],
            ['run' => 'a1a1a1a1a1a1a1a1']
        );

        self::assertStringContainsString('检测到 fib() 递归', $html);
        self::assertStringNotContainsString('symbol=', $html);
        self::assertStringNotContainsString('<a href', $html);
    }

    /** 空结果必须显式说明，否则用户会以为功能坏了 */
    #[Test]
    public function renderDiagnosisShowsEmptyStateWithThresholds(): void
    {
        $html = XhprofDisplay::render_diagnosis([], []);

        self::assertStringContainsString('未发现明显瓶颈', $html);
        // 必须带 % 号：'10' 会被紧随其后的 '1000' 满足，
        // 删掉「自身耗时」子句断言依然成立——空转
        self::assertStringContainsString('10%', $html);
        // 空态是**另一条 return**，EmitsCardWrapper 只走非空分支，故此处单独钉闭合
        self::assertStringEndsWith('</div></div>', $html, '空态分支的卡片也必须闭合');
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

        // rule 同样走 htmlspecialchars。Analyzer 只会产出 R1..R6，所以真实数据里
        // 触发不了——但去掉这处转义目前 341 条测试全绿，故显式钉住。
        $html = XhprofDisplay::render_diagnosis(
            [new Finding('R<1&"x"', Finding::SEVERITY_MAIN, 'foo()', '标题', '细节', 1.0)],
            []
        );
        self::assertStringContainsString('[R&lt;1&amp;&quot;x&quot;]', $html);
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

        // 只有一个链接，且 run 与 symbol 必须在同一个 URL 里——
        // 分成两个 href 也能满足「两者都存在」，但用户点到的那个会落到运行列表
        self::assertSame(1, substr_count($html, 'href="'), '只应有一个链接');
        preg_match('/href="([^"]*)"/', $html, $m);
        self::assertStringContainsString('run=a1a1a1a1a1a1a1a1', $m[1]);
        self::assertStringContainsString('symbol=foo%28%29', $m[1]);
    }

    /** 主区必须渲染在补充区之前——「为什么慢」是头部结论，顺序反转是真实的 UX 回归 */
    #[Test]
    public function renderDiagnosisRendersMainSectionFirst(): void
    {
        $html = XhprofDisplay::render_diagnosis(
            [
                new Finding('R6', Finding::SEVERITY_SUPPLEMENT, 'a()', '补充项', '细节', 1.0),
                new Finding('R1', Finding::SEVERITY_MAIN, 'b()', '主结论', '细节', 1.0),
            ],
            []
        );

        self::assertLessThan(
            strpos($html, '其他发现'),
            strpos($html, '为什么慢'),
            '主区必须在前'
        );
    }

    /** 只有补充项时不得渲染「为什么慢」——否则会出现一个空的主区标题 */
    #[Test]
    public function renderDiagnosisOmitsMainHeadingWhenOnlySupplements(): void
    {
        $html = XhprofDisplay::render_diagnosis(
            [new Finding('R4', Finding::SEVERITY_SUPPLEMENT, 'fib', '补充项', '细节', 6.0)],
            []
        );

        self::assertStringNotContainsString('为什么慢', $html);
        self::assertStringContainsString('其他发现', $html);
    }

    #[Test]
    public function renderDiagnosisSkipsNonFindingValues(): void
    {
        $html = XhprofDisplay::render_diagnosis(
            ['x', 42, null, [], new Finding('R1', Finding::SEVERITY_MAIN, 'ok()', '有效项', '细节', 1.0)],
            []
        );

        self::assertStringContainsString('有效项', $html);
        self::assertSame(1, substr_count($html, '<li'), '非 Finding 值应被跳过，只渲染有效项');
    }

    /** 不得按 score 重排：score 是各规则自己的量纲，跨规则不可比 */
    #[Test]
    public function renderDiagnosisPreservesInputOrderWithoutSortingByScore(): void
    {
        $html = XhprofDisplay::render_diagnosis(
            [
                new Finding('R1', Finding::SEVERITY_MAIN, 'low()', '低分在前', '细节', 1.0),
                new Finding('R2', Finding::SEVERITY_MAIN, 'high()', '高分在后', '细节', 999.0),
            ],
            []
        );

        self::assertLessThan(strpos($html, '高分在后'), strpos($html, '低分在前'), '必须保持输入序');

        // 补充区同样不得重排：上一条夹具只有主区结论，故只守住了主区
        $html = XhprofDisplay::render_diagnosis(
            [
                new Finding('R4', Finding::SEVERITY_SUPPLEMENT, '', '补充低分在前', '细节', 1.0),
                new Finding('R5', Finding::SEVERITY_SUPPLEMENT, 'b()', '补充高分在后', '细节', 999.0),
            ],
            []
        );
        self::assertLessThan(
            strpos($html, '补充高分在后'),
            strpos($html, '补充低分在前'),
            '补充区必须保持输入序'
        );
    }

    /** 空态必须列全五个阈值——逐个断言，任何一个被删掉都要能变红 */
    #[Test]
    public function renderDiagnosisEmptyStateListsEveryThreshold(): void
    {
        $html = XhprofDisplay::render_diagnosis([], []);

        foreach (['10%', '1000', '500', '5%', '30%'] as $token) {
            self::assertStringContainsString($token, $html, "空态缺少阈值 $token");
        }
    }

    #[Test]
    public function renderDiagnosisEmitsCardWrapper(): void
    {
        $html = XhprofDisplay::render_diagnosis(
            [new Finding('R1', Finding::SEVERITY_MAIN, 'foo()', '标题', '细节', 1.0)],
            []
        );

        self::assertStringContainsString('<div class="xp-main"><div class="xp-card">', $html);
        self::assertStringContainsString('诊断结论', $html);
        self::assertStringEndsWith('</div></div>', $html, '卡片必须闭合，否则报告体会被嵌进 .xp-card（其 overflow:hidden 会截断宽表格）');
    }
}
