<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Lib;

require_once __DIR__ . '/../../Fixtures/Fakes.php';

use ErikWang2013\Xhprof\Core\I18n\I18n;
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
        self::assertStringContainsString('按 ', $html);
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

        self::assertStringContainsString('父/子报告', $html);
        self::assertStringContainsString('当前函数', $html);
        // 分区标题：上游是 'Child function'/'Parent function' + 复数后缀 's'。
        // 移植时一次全局替换把 "public static " 插到了 "function" 前面（连字符串
        // 也没放过），于是每张父/子表都印着 "Child public static functions"，
        // 而 `.'s'` 那句复数拼接的语义也被打断。这条当时**钉住了那个错字符串**。
        self::assertStringContainsString('<b>子函数</b>', $html);
        self::assertStringContainsString('<b>父函数</b>', $html);
        self::assertStringNotContainsString('public static function', $html);
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

        self::assertStringContainsString('运行数据里没有函数', $html);
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

        self::assertStringContainsString('差异总览', $html);
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
        self::assertStringContainsString('差异总览', $html);
        self::assertStringContainsString('运行 #r1id', $html);
        self::assertStringContainsString('反转差异报告', $html);
        self::assertStringContainsString('函数调用次数', $html);
        self::assertStringContainsString('全部差异报告', $html);

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
        self::assertStringContainsString('回归/改善', $top);
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
        // 数据属性保留（将来的悬浮提示实现要的就是 type/metric），但**不许**再有
        // onmouseover：`onmouseover` 的返回值被浏览器丢弃，它从来不是触发器，
        // 留着只会让人以为提示是好的。这条断言钉的是「已经删掉」。
        self::assertSame("type='Child' metric='wt'", XhprofDisplay::get_tooltip_attributes('Child', 'wt'));

        $parent = XhprofDisplay::get_tooltip_attributes('Parent', 'mu');
        self::assertSame("type='Parent' metric='mu'", $parent);
        self::assertStringNotContainsString('onmouseover', $parent);
        self::assertStringNotContainsString('RowToolTip', $parent);
    }

    /**
     * jQuery 必须排在 xhprof_report.js 之前。
     *
     * 两个 `<script>` 都没有 defer/async，浏览器按文档顺序**同步**执行，而后者顶层
     * 就是 `$(document).ready(...)`：顺序反了 → `$` 未定义 → ReferenceError，
     * 该脚本剩余部分（搜索按钮的点击处理器、请求列表的 DataTable 分页/排序）
     * 全部注册不上，页面不报错、只是「点了没反应」。
     */
    #[Test]
    public function jqueryIsLoadedBeforeTheReportScript(): void
    {
        $html = XhprofDisplay::xhprof_include_js_css('/xhprof-assets');
        $jquery = strpos($html, 'jquery-3.0.0.min.js');
        $report = strpos($html, 'xhprof_report.js');

        // 先各自确认找得到：strpos 找不到返回 false，而 `false < 正数` 恒真，
        // 少了这两句，选择器一坏这条断言就变成永远通过的摆设。
        self::assertIsInt($jquery, '输出里没有 jQuery 的 <script>');
        self::assertIsInt($report, '输出里没有 xhprof_report.js 的 <script>');
        self::assertLessThan($report, $jquery, 'jQuery 必须排在 xhprof_report.js 之前');

        // 「按文档顺序同步执行」是上面那条断言能推出结论的前提，一并钉住：
        // 一旦有人加上 defer/async，执行时机就与文档顺序脱钩。
        self::assertStringNotContainsString('defer', $html);
        self::assertStringNotContainsString(' async', $html);
    }

    /**
     * 注入给 JS 的文案块：随语言变化、占位符原样、且**不含**千位分隔符那个键。
     *
     * `window.xpI18n` 是 JS 唯一的数据来源（`xhprof_report.js` 读它），所以这条同时
     * 覆盖三件事：值确实取自当前语言的词表、DataTables 的 `_MENU_`/`_START_` 占位符
     * 没被吃掉、以及分隔符键按设计不在里面（页面数字由 PHP 的 `number_format` 统一
     * 打成英式，见 `XhprofDisplay` 里那段注释）。
     */
    #[Test]
    public function injectedJsCarriesTheLocalizedDataTableStrings(): void
    {
        I18n::setLocale('zh_CN');
        $zh = XhprofDisplay::xhprof_include_js_css('/xhprof-assets');
        self::assertStringContainsString('window.xpI18n = ', $zh);
        self::assertStringContainsString('"search":"搜索："', $zh, '注入的应是当前语言的值');
        self::assertStringContainsString('_MENU_', $zh, 'DataTables 的占位符必须原样传过去');
        self::assertStringContainsString('_TOTAL_', $zh);
        self::assertStringNotContainsString('infoThousands', $zh, '这个键按设计不进词表也不注入');

        I18n::setLocale('en');
        $en = XhprofDisplay::xhprof_include_js_css('/xhprof-assets');
        self::assertStringContainsString('"search":"Search:"', $en);
        self::assertNotSame($zh, $en, '换语言必须换掉注入的文案');

        I18n::setLocale(I18n::FALLBACK);
    }

    /**
     * 导航里的语言切换器：13 种语言各一个 option，选项值带当前查询串、当前语言选中。
     *
     * 两条都要：**13 个**（少一个就是某门语言在报告页上没有入口）与**带参数**
     * （`?token=` 丢了点进去就是 403 —— 页面内链接刚修过这一类，切换器不能重蹈）。
     */
    #[Test]
    public function navOffersALanguageSwitcherForEveryLocale(): void
    {
        $this->useRequest(new FakeRequest(['token' => 'tok', 'lang' => 'ko'], ['uri' => '/xhprof']));
        I18n::setLocale('ko');

        $nav = XhprofDisplay::show_nav(['token' => 'tok', 'lang' => 'ko']);

        self::assertStringContainsString('<select class="xp-lang"', $nav, '导航里没有切换器');
        foreach (I18n::AVAILABLE as $code) {
            self::assertStringContainsString(
                'value="/xhprof?token=tok&lang=' . $code . '"',
                $nav,
                "{$code} 在切换器里没有条目，或该条目丢了 token"
            );
        }
        // 当前语言选中；标签用各语言的自称（词表 _meta.name，不需要翻译）
        self::assertStringContainsString('value="/xhprof?token=tok&lang=ko" selected', $nav);
        self::assertStringContainsString('>한국어</option>', $nav);
        self::assertStringContainsString('>日本語</option>', $nav);

        // 无查询串的请求：仍然是 13 条，只是 URL 更短（不能因为没 token 就不渲染切换器）
        $this->useRequest(new FakeRequest([], ['uri' => '/xhprof']));
        I18n::setLocale(I18n::FALLBACK);
        $plain = XhprofDisplay::show_nav([]);
        self::assertSame(
            count(I18n::AVAILABLE),
            substr_count($plain, '<option '),
            '无查询串时切换器条目数不对'
        );
        self::assertStringContainsString('value="/xhprof?lang=zh_CN" selected', $plain);
    }

    /** 导航里的「首页」/品牌链接必须带上整个查询串（鉴权 token、语言 lang 都靠它传播） */
    #[Test]
    public function navHomeLinksCarryTheQueryStringButDropViewParams(): void
    {
        $this->useRequest(new FakeRequest(
            ['run' => 'a1a1a1a1a1a1a1a1', 'symbol' => 'foo()', 'token' => 'tok', 'lang' => 'ko'],
            ['uri' => '/xhprof']
        ));
        $nav = XhprofDisplay::show_nav([
            'run' => 'a1a1a1a1a1a1a1a1', 'symbol' => 'foo()', 'token' => 'tok', 'lang' => 'ko',
        ]);

        self::assertStringContainsString('href="/xhprof?token=tok', $nav);
        self::assertStringContainsString('lang=ko', $nav);
        self::assertStringNotContainsString('symbol=', $nav);
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
