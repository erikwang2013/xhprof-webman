<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Lib;

require_once __DIR__ . '/../../Fixtures/Fakes.php';

use ErikWang2013\Xhprof\Core\Analysis\Finding;
use ErikWang2013\Xhprof\Core\I18n\I18n;
use ErikWang2013\Xhprof\Core\XhprofLib\Display\XhprofDisplay;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeConfig;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeRequest;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeResponse;
use ErikWang2013\Xhprof\Tests\Support\XhprofStaticsSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;


class XhprofDisplayTest extends TestCase
{
    use XhprofStaticsSnapshot;

    /** setUp 开工前的静态量快照（tearDown 原样放回） */
    private array $saved = [];

    protected FakeCache $cache;
    protected FakeRequest $request;
    protected FakeResponse $response;
    protected FakeConfig $config;
    protected FakeLogger $logger;

    protected function setUp(): void
    {
        // 先照单全收再改：本类下面会改写 `$ignore_url_arr` 等进程级静态量，
        // 不还原就会漏给后面的用例（实测：Core+Lib+Adapter 顺序下 Adapter 侧 3 条假红）。
        $this->saved = $this->snapshotXhprofStatics();

        $this->cache = new FakeCache();
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

        // 渲染状态是按请求的：$_hyperf 被 Hyperf 用例置位后（进程级、不可逆）它存在协程
        // Context 里，直接写静态属性在那个模式下没人读。布置与断言一律走 XhprofDisplay
        // 的存取器，本文件因此在两种进程模式下行为一致。
        XhprofDisplay::set_render_state([
            'sort_col' => 'wt',
            'diff_mode' => false,
            'display_calls' => true,
            'metrics' => null,
            'stats' => [],
            'pc_stats' => [],
            'totals' => 0,
            'totals_1' => 0,
            'totals_2' => 0,
        ]);
        XhprofDisplay::$vwbar = 'class="vwbar"';
        XhprofDisplay::$vbar = 'class="vbar"';
        XhprofDisplay::$vbbar = 'class="vbbar"';
        XhprofDisplay::$vrbar = 'class="vrbar"';
        XhprofDisplay::$vgbar = 'class="vgbar"';
    }

    protected function tearDown(): void
    {
        $this->restoreXhprofStatics($this->saved);
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

    /**
     * 单 run 夹具 + 一条必须产出 R3 结论的边（main()==>hot()）。
     *
     * 不往 sampleRunData() 里加边，是因为它有约十个调用方带着精确数值钉子
     * （如 `>5</td>`、`>30,000`）；加一条边会改动总调用次数与 main() 的自身耗时。
     *
     * 数字不是随手取的，两个窗口都要满足：
     * 1) hot() 自身占比 7%（7000/100000）必须落在 **[5%, 10%)** —— 低于 5% 触发不了
     *    R3 自己的闸门；高于 10% 会被 R1 先认领，同 symbol 时 R1 优先（去重），R3 被吞掉。
     * 2) R1 的命中数必须 ≤ 2 —— analyze() 把 R1++R3++R2 合并后按 MAIN_LIMIT=3 切片，
     *    而 R1 整组排在 R3 之前。本夹具里 R1 只有 main()(53%) 与 foo()(40%) 两条，
     *    故 R3 恰好卡在第三位活下来。若 R1 命中三条，R3 会被切片丢掉，
     *    测 `[R3]` 的断言就成了永远为假的盲探针。
     */
    private function sampleRunDataWithHotEdge(): array
    {
        return [
            'main()' => ['ct' => 1, 'wt' => 100000, 'mu' => 2048],
            'main()==>foo()' => ['ct' => 1, 'wt' => 40000, 'mu' => 512],
            'main()==>hot()' => ['ct' => 600, 'wt' => 7000, 'mu' => 64],
        ];
    }

    /**
     * 断言 $first 在 $second 之前，且**两者都必须存在**。
     *
     * 不能直接写 assertLessThan(strpos(...), strpos(...))：strpos 在缺失时返回 false，
     * 而 PHP 里 `false < 任意正数` 为真，于是锚点消失时位置断言会静默通过。
     * 实测：把 splice 的 `.=` 改成 `=`（卡片覆盖而非追加）会让页面丢掉动作栏、
     * 搜索框与 run 描述，而 strpos($html,'Run #') 变成 false，整套测试仍然全绿。
     */
    private static function assertBefore(string $html, string $first, string $second): void
    {
        $posFirst  = strpos($html, $first);
        $posSecond = strpos($html, $second);

        self::assertNotFalse($posFirst, "锚点缺失：$first");
        self::assertNotFalse($posSecond, "锚点缺失：$second");
        self::assertLessThan($posSecond, $posFirst, "$first 必须在 $second 之前");
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
        XhprofDisplay::set_render_state(['diff_mode' => true]);
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
        XhprofDisplay::set_render_state(['diff_mode' => true]);
        // diff 列头也走词表（diffcol.<统计项>）：中文用户不再看到 $diff_descriptions 的英文
        self::assertSame('总耗时<br>差异<br>(微秒)', XhprofDisplay::stat_description('wt'));
        self::assertSame('调用<br>次数<br>差异', XhprofDisplay::stat_description('ct'));
        // en 词表必须仍是那批英文字面量（XhprofDisplay::$diff_descriptions 逐字）
        I18n::setLocale('en');
        self::assertSame('Incl. Wall<br>Diff<br>(microsec)', XhprofDisplay::stat_description('wt'));
        I18n::setLocale(I18n::FALLBACK);
    }

    #[Test]
    public function sortCbkSortsByFnAlphabetically(): void
    {
        XhprofDisplay::set_render_state(['sort_col' => 'fn']);
        $arr = [['fn' => 'b()'], ['fn' => 'A()'], ['fn' => 'b()']];
        usort($arr, [XhprofDisplay::class, 'sort_cbk']);
        self::assertSame('A()', $arr[0]['fn']);
    }

    #[Test]
    public function sortCbkSortsByMetricDescending(): void
    {
        XhprofDisplay::set_render_state(['sort_col' => 'wt']);
        $arr = [['fn' => 'a', 'wt' => 5], ['fn' => 'b', 'wt' => 10], ['fn' => 'c', 'wt' => 5]];
        usort($arr, [XhprofDisplay::class, 'sort_cbk']);
        self::assertSame('b', $arr[0]['fn']);
        self::assertSame(10, $arr[0]['wt']);
    }

    #[Test]
    public function sortCbkUsesAbsoluteValuesInDiffMode(): void
    {
        XhprofDisplay::set_render_state(['sort_col' => 'wt', 'diff_mode' => true]);
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
     * diff 符号详情页里最后三处写死的英文：`per call` 行标签、`N/A` 占位符、`source` 链接。
     * 夹具沿用上一条（symbol 只在 run2 里）——那正是 avg 一侧 ct=0、会印出 N/A 的角落。
     */
    #[Test]
    public function diffSymbolReportLabelsComeFromTheCatalog(): void
    {
        Xhprof::$symbol_lookup_url = 'http://sym.example.com';
        $this->useRequest(new FakeRequest(
            ['run1' => 'r1', 'run2' => 'r2', 'symbol' => 'foo()'],
            ['uri' => '/xhprof']
        ));

        $run1 = ['main()' => ['ct' => 1, 'wt' => 100000, 'mu' => 100]];
        $run2 = [
            'main()' => ['ct' => 1, 'wt' => 100000, 'mu' => 100],
            'main()==>foo()' => ['ct' => 2, 'wt' => 40000, 'mu' => 200],
        ];

        $html = XhprofDisplay::profiler_diff_report(
            ['run1' => 'r1', 'run2' => 'r2', 'symbol' => 'foo()'],
            $run1,
            'd1',
            $run2,
            'd2',
            'foo()',
            'wt',
            'r1',
            'r2'
        );

        // 断言整条拼好的标签而不是「每次调用：」四个字：模板里的 %s 少写了也不会抛
        // （sprintf 多给参数是允许的），只会静默丢掉指标名——那种改写只有这条能抓住。
        self::assertStringContainsString('每次调用：总耗时 (微秒)', $html, 'per call 行标签走词表（pc.perCall）');
        self::assertStringNotContainsString(' per call ', $html);
        self::assertStringContainsString('>N/A<', $html, 'ct=0 的均值格走词表（common.na）');
        self::assertStringContainsString('>源码</a>', $html, 'source 链接文案走词表（sym.source）');
        self::assertStringNotContainsString('>source</a>', $html);
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
        XhprofDisplay::set_render_state([
            'stats' => ['fn', 'ct', 'wt'],
            'metrics' => ['wt'],
            'totals' => ['ct' => 4, 'wt' => 340],
            'sort_col' => 'wt',
        ]);

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

    /**
     * 取表头里带 `sorted` 标记的 `<th>`（attrs + 文案）。
     *
     * 只认 `<th …><nobr>…</th>` 这一形：两张 PHP 渲染的统计表都是这个形状
     * （`<nobr>` 上游就没闭合，正则别要求 `</nobr>`），页面里别的表（run 列表、
     * diff 摘要）不带 nobr，不会被误收集。
     *
     * @return list<array{attrs:string,label:string}>
     */
    private static function sortedHeaderCells(string $html): array
    {
        preg_match_all('/<th([^>]*)><nobr>(.*?)<\/th>/s', $html, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $row) {
            if (str_contains($row[1], 'sorted')) {
                $out[] = ['attrs' => $row[1], 'label' => strip_tags($row[2])];
            }
        }
        return $out;
    }

    /**
     * 平表（`$stats`）列头必须标出「当前按哪列排、什么方向」。
     *
     * 以前两张表的列头循环都不读 `$sort_col`：样式表里 `td.sorted` 从未被命中，
     * 列头看不出排序列（可点的链接与不可点的表头长得一模一样），读屏器更是什么都不知道。
     */
    #[Test]
    public function flatTableHeaderMarksTheSortedColumn(): void
    {
        $data = [
            ['fn' => 'a()', 'ct' => 1, 'wt' => 100, 'excl_wt' => 100],
            ['fn' => 'b()', 'ct' => 1, 'wt' => 90, 'excl_wt' => 90],
        ];
        XhprofDisplay::set_render_state([
            'stats' => ['fn', 'ct', 'wt'],
            'metrics' => ['wt'],
            'totals' => ['ct' => 2, 'wt' => 190],
            'sort_col' => 'wt',
        ]);

        // wt：非 fn 列一律降序
        $sorted = self::sortedHeaderCells(XhprofDisplay::print_flat_data([], 'title', $data, 0));
        self::assertCount(1, $sorted, '恰好一列带 sorted 标记，多了少了都是错');
        self::assertStringContainsString('aria-sort="descending"', $sorted[0]['attrs']);
        self::assertSame(strip_tags(XhprofDisplay::stat_description('wt')), $sorted[0]['label'], '标记落在了错误的列上');
        self::assertStringContainsString('vwbar', $sorted[0]['attrs'], '排序列原有的 vwbar 不能被新 class 顶掉');

        // fn：升序（sort_cbk 里按名称 strtoupper 比较）
        XhprofDisplay::set_render_state(['sort_col' => 'fn']);
        $sorted = self::sortedHeaderCells(XhprofDisplay::print_flat_data([], 'title', $data, 0));
        self::assertCount(1, $sorted);
        self::assertStringContainsString('aria-sort="ascending"', $sorted[0]['attrs'], 'fn 列是升序');
        self::assertSame(strip_tags(XhprofDisplay::stat_description('fn')), $sorted[0]['label'], '标记落在了错误的列上');
    }

    /** 父/子表（`$pc_stats`）与平表同一套标记（两处循环共用一个渲染器，这条钉住别分叉） */
    #[Test]
    public function pcTableHeaderMarksTheSortedColumn(): void
    {
        $params = ['run' => 'a1a1a1a1a1a1a1a1', 'all' => 1, 'symbol' => 'foo()'];
        $this->useRequest(new FakeRequest($params, ['uri' => '/xhprof']));

        $html = XhprofDisplay::profiler_single_run_report(
            $params,
            $this->sampleRunData(),
            'desc',
            'foo()',
            'wt',
            'a1a1a1a1a1a1a1a1'
        );

        $sorted = self::sortedHeaderCells($html);
        self::assertCount(1, $sorted, '父/子表恰好一列带 sorted 标记');
        self::assertStringContainsString('aria-sort="descending"', $sorted[0]['attrs']);
        self::assertSame(strip_tags(XhprofDisplay::stat_description('wt')), $sorted[0]['label'], '标记落在了错误的列上');
    }

    /**
     * 搜索框：读屏器不把 placeholder 当控件名，且回车必须等于点「搜索」。
     *
     * 两半分别钉：PHP 侧输出 `aria-label`（复用 search.placeholder，不新增文案）；
     * JS 侧（无浏览器可跑，只能查源码结构）回车处理与按钮点击走**同一个** submitSearch，
     * 否则「回车能搜」会随一次手改的复制粘贴而分叉（复制一份逻辑进去，改一处漏一处）。
     */
    #[Test]
    public function searchBoxIsLabelledAndEnterSubmits(): void
    {
        $params = ['run' => 'a1a1a1a1a1a1a1a1', 'symbol' => 'foo()'];
        $this->useRequest(new FakeRequest($params, ['uri' => '/xhprof']));
        $html = XhprofDisplay::profiler_single_run_report(
            $params,
            $this->sampleRunData(),
            'desc',
            'foo()',
            'wt',
            'a1a1a1a1a1a1a1a1'
        );

        preg_match('/<input[^>]*id="xhprofFuncSearch"[^>]*>/', $html, $m);
        self::assertNotEmpty($m, '页面里没有搜索输入框 —— 锚点失效，后面的断言会空转');
        self::assertStringContainsString(
            'aria-label="' . I18n::plain('search.placeholder') . '"',
            $m[0],
            '搜索框没有可及名称（aria-label），读屏里是个无名文本框'
        );
        self::assertStringContainsString('placeholder="' . I18n::plain('search.placeholder') . '"', $m[0]);

        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/src/html/js/xhprof_report.js');
        self::assertStringContainsString('xhprof-search-input', $js, 'JS 里没有搜索框选择器，锚点失效');
        self::assertMatchesRegularExpression(
            '/\$\(["\']#funcSub["\']\)\.click\(submitSearch\)/',
            $js,
            '按钮点击必须走 submitSearch（与回车同一条路径）'
        );
        self::assertMatchesRegularExpression(
            '/\$\(["\']input\.xhprof-search-input["\']\)\.keydown\(function\s*\(e\)\s*\{[^}]*e\.which\s*===?\s*13[^}]*submitSearch\(\)/s',
            $js,
            '回车（keydown 13）必须触发同一个 submitSearch，否则键盘用户敲回车毫无反应'
        );
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

        // JS 侧同样钉「已经删掉」：`ParentRowToolTip`/`ChildRowToolTip` 没有触发点
        // （onmouseover 早已移除）、没有消费端（原版依赖的 jquery.tooltip.js 不在
        // 加载列表里），2026-10 作为死代码删除。这条防止它被顺手加回来。
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/src/html/js/xhprof_report.js');
        self::assertStringNotContainsString('RowToolTip', $js, '死代码又回来了：RowToolTip 没有任何调用点');
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
                'value="/xhprof?token=tok&amp;lang=' . $code . '"',
                $nav,
                "{$code} 在切换器里没有条目，或该条目丢了 token"
            );
        }
        // 当前语言选中；标签用各语言的自称（词表 _meta.name，不需要翻译）
        self::assertStringContainsString('value="/xhprof?token=tok&amp;lang=ko" selected', $nav);
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

        // **切换语言是留在当前视图上换文案，不是导航走人**：run 报告页上换语言必须保住
        // run/symbol/sort/wts/source —— 用 report_url() 的默认 drop 表会把它们摘掉，
        // 于是点一下语言就被弹回 run 列表（本用例就是这么发现该缺陷的）。
        $this->useRequest(new FakeRequest(
            ['run' => 'a1a1a1a1a1a1a1a1', 'symbol' => 'foo()', 'sort' => 'wt', 'wts' => 'wt', 'token' => 'tok'],
            ['uri' => '/xhprof']
        ));
        I18n::setLocale('zh_CN');
        $onRun = XhprofDisplay::show_nav([
            'run' => 'a1a1a1a1a1a1a1a1', 'symbol' => 'foo()', 'sort' => 'wt', 'wts' => 'wt', 'token' => 'tok',
        ]);
        foreach (['run=a1a1a1a1a1a1a1a1', 'symbol=foo%28%29', 'sort=wt', 'wts=wt', 'token=tok'] as $keep) {
            self::assertStringContainsString(
                $keep,
                $onRun,
                "在 run 报告页上换语言时丢了 {$keep} —— 会被弹回列表页/丢掉当前视图"
            );
        }
    }

    /**
     * 注入块的转义：值里塞 `</script>` 也跑不出这个 `<script>`。
     *
     * 走 `xpI18nScript()`（取数组的纯函数）而不是整页 —— 要让**词表**里出现 `</script>`
     * 得改仓库文件，测不了；而这里是同一个编码函数的输入。
     * 两道叠加的防护任缺其一都可能出问题（`json_encode` 默认转义 `/`、HEX 标志转 `<`），
     * 所以这条断言必须**见过红**：删掉四个 HEX 标志 → 本用例失败。
     */
    #[Test]
    public function injectedScriptCannotBeEscapedByACatalogValue(): void
    {
        $hostile = '</script><script>alert(1)</script><!--';
        $script = XhprofDisplay::xpI18nScript(['x' => $hostile, 'y' => 'ok']);

        self::assertSame(
            1,
            substr_count($script, '</script>'),
            '值里的 </script> 提前结束了注入块（页面剩下的部分会被当 HTML 解析）'
        );
        self::assertSame(1, substr_count($script, '<script'), '注入块之外不该多出 <script');
        self::assertStringNotContainsString('<!--', $script);
        self::assertStringContainsString('ok', $script, '正常值仍要原样进去');
    }

    /** 非法 UTF-8 的词表值不能把整条文案变成空串（PHP 8.1+ 的 ENT_SUBSTITUTE 会被显式 ENT_QUOTES 顶掉） */
    #[Test]
    public function invalidUtf8DegradesToAReplacementCharInsteadOfVanishing(): void
    {
        $bad = "Ünicode \xC3\x28 end";   // 常见的「存成 Latin-1/GBK」残留

        self::assertNotSame('', I18n::escapeHtml($bad), '非法 UTF-8 不该让整条文案消失');
        self::assertNotSame('', I18n::escapePlain($bad));
        self::assertStringContainsString("\u{FFFD}", I18n::escapeHtml($bad), '应当替换成 U+FFFD');
        self::assertStringContainsString('Ünicode', I18n::escapeHtml($bad), '合法部分要保留');
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

        // 「首页」链接 = report_url() 的默认 drop 表：视图参数摘掉、token/lang 留住
        self::assertStringContainsString('href="/xhprof?token=tok&amp;lang=ko"', $nav);
        self::assertStringContainsString('href="/xhprof?token=tok&amp;lang=ko" class="xp-brand"', $nav);
        // 只对切换器**之前**那段（首页/品牌/运行报告）断言：切换器里的 option 值是
        // 「留在当前视图换语言」，本来就该带 symbol/run（见上一条用例）。
        $beforeSwitcher = substr($nav, 0, (int) strpos($nav, '<select'));
        self::assertStringNotContainsString('symbol=', $beforeSwitcher, '首页/品牌/运行报告链接不该带 symbol');
    }

    #[Test]
    public function printSourceLink(): void
    {
        Xhprof::$symbol_lookup_url = 'http://sym.example.com';
        $link = XhprofDisplay::print_source_link(['fn' => 'foo()']);
        self::assertStringContainsString('?symbol=foo%28%29', $link);
        self::assertStringContainsString('>源码</a>', $link, '链接文案走词表（sym.source），不再是写死的 source');
        I18n::setLocale('en');
        self::assertStringContainsString('>source</a>', XhprofDisplay::print_source_link(['fn' => 'foo()']), 'en 与改动前逐字相同');
        I18n::setLocale(I18n::FALLBACK);
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
        self::assertBefore($html, 'foo() 自身耗时', '其他发现');
    }

    /** R4 在裸名不在 symbol_tab 时 symbol 是空串（见 Analyzer::ruleR4），此时不该给出指向"未找到"详情页的死链 */
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

    /**
     * 诊断条目必须带上断行 class，且样式表里**针对这个 class** 有 overflow-wrap。
     *
     * 缺陷形态：全限定名/生成式命名（`Illuminate\…\{closure}`）在 CSS 默认断行规则下
     * 没有断点，窄视口里溢出 .xp-card 的 overflow:hidden —— 卡片没有滚动条，函数名被
     * 静默裁掉（实测 485px 视口下越出卡片 413px，见报告）。
     *
     * class 名从**渲染结果**里读出来再去找同名 CSS 规则：写死 '.xp-diag-item' 的话，
     * 改 class 名而漏改样式表（或反过来）两种单边改动都会漏网。
     */
    #[Test]
    public function diagnosisItemCarriesTheWrapClassAndCssWrapsIt(): void
    {
        $sym = 'Illuminate\Database\Eloquent\Builder::Illuminate\Database\Eloquent\{closure}';
        $html = XhprofDisplay::render_diagnosis(
            [new Finding('R1', Finding::SEVERITY_MAIN, $sym, $sym . ' 自身耗时 780.0ms', '细节', 780.0)],
            []
        );

        // 前提：渲染层不截断符号名，裁切完全发生在 CSS 层——否则样式表里做什么都没用
        self::assertStringContainsString($sym, $html);

        preg_match('/<li class="([^"]*)"/', $html, $m);
        self::assertNotEmpty($m, '诊断条目的 <li> 必须带 class（断行规则由样式表提供）');
        $class = trim($m[1]);
        self::assertNotSame('', $class);

        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/src/html/css/xhprof.css');
        preg_match('/\.' . preg_quote($class, '/') . '\s*\{([^}]*)\}/', $css, $rule);
        self::assertNotEmpty($rule, "样式表里缺少 .{$class} 的规则");
        self::assertStringContainsString('overflow-wrap', $rule[1]);
        self::assertStringContainsString('anywhere', $rule[1]);
        // break-all 会连带拆开 CJK 标点与 URL；本条钉住别退化成它
        self::assertStringNotContainsString('word-break', $rule[1]);
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
        self::assertStringStartsWith('/xhprof?', $m[1], 'href 必须由 base_path() 生成，不能用裸查询串');
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

        self::assertBefore($html, '为什么慢', '其他发现');
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

        self::assertBefore($html, '低分在前', '高分在后');

        // 补充区同样不得重排：上一条夹具只有主区结论，故只守住了主区
        $html = XhprofDisplay::render_diagnosis(
            [
                new Finding('R4', Finding::SEVERITY_SUPPLEMENT, '', '补充低分在前', '细节', 1.0),
                new Finding('R5', Finding::SEVERITY_SUPPLEMENT, 'b()', '补充高分在后', '细节', 999.0),
            ],
            []
        );
        self::assertBefore($html, '补充低分在前', '补充高分在后');
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

    #[Test]
    public function singleRunReportContainsDiagnosisSection(): void
    {
        // 下面几条锚点都是中文源文案：语言是静态状态，先钉死，别被别的用例留下的语言影响
        I18n::setLocale('zh_CN');
        $runId = 'a1a1a1a1a1a1a1a1';
        $this->useRequest(new FakeRequest(['run' => $runId, 'all' => 1], ['uri' => '/xhprof']));

        $html = XhprofDisplay::profiler_single_run_report(
            ['run' => $runId, 'all' => 1],
            $this->sampleRunDataWithHotEdge(),
            'desc',
            null,
            'wt',
            $runId
        );

        self::assertStringContainsString('诊断结论', $html);
        // 断言「为什么慢」而不是「诊断结论」：空态同样渲染「诊断结论」，
        // 所以前者才区分得出「渲染出真实结论」与「analyze() 拿到错参数返回空」
        self::assertStringContainsString('为什么慢', $html);
        // $echo_page 是逐段拼接的，拼接顺序即 DOM 顺序：卡片必须在 run 描述之后。
        // 锚点从词表取「run 标签」的前缀（`运行 #` / `Run #`），不写死英文——
        // 那句 run 描述本身已经进词表了，写死会在非中文语言下假红。
        self::assertBefore($html, explode('%s', I18n::t('diff.run'))[0], '诊断结论');
        // 上界同理——只钉下界会放过"把卡片挪到报告末尾（数据表之后）"这种变异
        self::assertBefore($html, '诊断结论', '函数/方法调用总次数');

        // 以下两条必须**限定在卡片内**断言：整页到处都是 run= 链接，
        // 对整页断言会连「卡片自己丢光了 run」都发现不了（盲探针）。
        $cardStart = strpos($html, '诊断结论');
        $cardEnd   = strpos($html, '</div></div>', $cardStart);
        self::assertNotFalse($cardEnd, '卡片必须闭合');
        $card = substr($html, $cardStart, $cardEnd - $cardStart);
        // $run1_data 是 R3 唯一的来源：传进去空数组，[R3] 与整块为什么慢都会消失
        self::assertStringContainsString('[R3]', $card, '$run1_data 必须真的喂进 analyze()');
        // $base_url_params 携带 run：传 array() 则诊断链接退化成 ?symbol=...
        self::assertStringContainsString('run=' . $runId, $card, '$base_url_params 必须真的喂进 render_diagnosis()');
    }

    /**
     * 这条测试的真正职责是**数据完整性**，不是"文案上不想在 diff 里显示卡片"。
     *
     * 单 run 路径上 $symbol_tab/$totals 都由 $run1_data 派生，没有任何东西改写它们——
     * 所以那里的接线错误是**不可达**的。`if ($diff_mode)` 是**唯一**会把这两个局部变量
     * 换成增量的地方。因此这条测试**唯一**要守的是"守卫被摘掉"：去掉 !$diff_mode 只会让
     * 它一条变红，别的测试都抓不住（喂错数据已被其他用例钉住，不再是它的独有能力）。
     *
     * 若把它读成文案问题，最自然的"改进"就是去掉守卫、让卡片也出现在 diff 模式——
     * 而那正是静默损坏路径：增量做分母、原始 $run1_data 做边表，R3 标题里出现负耗时。
     */
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
        // 断言「为什么慢」而不是「诊断结论」：空态同样渲染「诊断结论」，
        // 所以前者才区分得出「渲染出真实结论」与「analyze() 拿到错参数返回空」
        self::assertStringContainsString('为什么慢', $html);
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

    // ============ 2026-10 新增：聚合入口 / 关键路径卡 / 搜索子串匹配 ============

    /** 关键路径：每步取 wt 最大的子边，链 + 每步占比（该边 wt / main() 的 wt） */
    #[Test]
    public function criticalPathWalksTheHeaviestChildWithRatios(): void
    {
        I18n::setLocale('zh_CN');

        $html = XhprofDisplay::render_critical_path($this->sampleRunData());

        self::assertStringContainsString('关键路径', $html);
        // 夹具：main()→foo() 40k、main()→bar() 30k、foo()→strlen() 5k（总 100k）
        self::assertBefore($html, 'main()', 'foo()');
        self::assertBefore($html, 'foo()', 'strlen()');
        self::assertStringNotContainsString(
            'bar()',
            $html,
            'bar() 比 foo() 轻，不该出现在最重链上（出现即选子边的比较反了）'
        );
        self::assertStringContainsString('40.0%', $html, '第一跳应带 40000/100000 的占比');
        self::assertStringContainsString('5.0%', $html, '第二跳应带 5000/100000 的占比');
    }

    /** 没有从 main() 出发的边（或 main() 耗时为 0）→ 空态：卡片在、说明在、链不在 */
    #[Test]
    public function criticalPathShowsEmptyStateWithoutEdges(): void
    {
        I18n::setLocale('zh_CN');

        $html = XhprofDisplay::render_critical_path(['main()' => ['ct' => 1, 'wt' => 100000, 'mu' => 100]]);

        self::assertStringContainsString('关键路径', $html, '空态也要有卡片标题（否则读起来像功能没接）');
        self::assertStringContainsString('没有可展示的调用链', $html);
        self::assertStringNotContainsString('→', $html);
    }

    /**
     * 环：a()→main() 是回边，必须被 visited 挡掉。
     *
     * 两个变异体都量过（不是推的）：
     *  - 只去掉初始种子（`$visited = array()`）→ 链变成 main()→a()→main()，本用例因
     *    "b() 不在链上"变红（Failures: 1）；
     *  - 把 isset 检查与 `$visited[$best] = true` 一起删掉 → 在 main()/a() 之间无限
     *    累积，内存耗尽（实测 "Allowed memory size … exhausted" 后段错误，退出码 139）。
     * 正确链是 main() → a() → b()：回边跳过，a() 的另一条子边 b() 被选中。
     */
    #[Test]
    public function criticalPathSkipsVisitedNodesOnCycles(): void
    {
        $html = XhprofDisplay::render_critical_path([
            'main()' => ['ct' => 1, 'wt' => 100000, 'mu' => 100],
            'main()==>a()' => ['ct' => 1, 'wt' => 40000, 'mu' => 50],
            'a()==>main()' => ['ct' => 1, 'wt' => 30000, 'mu' => 50],
            'a()==>b()' => ['ct' => 1, 'wt' => 10000, 'mu' => 50],
        ]);

        self::assertSame(2, substr_count($html, 'xp-path-step'), '链只该有两跳：main()→a()→b()');
        self::assertStringContainsString('b()', $html);
    }

    /** 位置契约：诊断卡之后、函数表之前（拼接顺序即 DOM 顺序） */
    #[Test]
    public function singleRunReportPlacesTheCriticalPathCardBetweenDiagnosisAndTheTable(): void
    {
        I18n::setLocale('zh_CN');
        $runId = 'a1a1a1a1a1a1a1a1';
        $this->useRequest(new FakeRequest(['run' => $runId, 'all' => 1], ['uri' => '/xhprof']));

        $html = XhprofDisplay::profiler_single_run_report(
            ['run' => $runId, 'all' => 1],
            $this->sampleRunDataWithHotEdge(),
            'desc',
            null,
            'wt',
            $runId
        );

        self::assertStringContainsString('关键路径', $html);
        self::assertBefore($html, '诊断结论', '关键路径');
        self::assertBefore($html, '关键路径', '函数/方法调用总次数');
    }

    /** diff 模式没有关键路径卡：$run1_data 是单 run 边表、totals 是增量（与诊断同因） */
    #[Test]
    public function diffReportHasNoCriticalPathCard(): void
    {
        I18n::setLocale('zh_CN');
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

        self::assertStringNotContainsString('关键路径', $html);
    }

    /** 精确未命中 → 列出「包含该串」的候选并逐条链到详情页（带 run） */
    #[Test]
    public function searchMissListsSubstringMatchesWithLinks(): void
    {
        I18n::setLocale('zh_CN');
        $runId = 'a1a1a1a1a1a1a1a1';
        $params = ['run' => $runId, 'all' => 1, 'symbol' => 'oo'];
        $this->useRequest(new FakeRequest($params, ['uri' => '/xhprof']));

        $html = XhprofDisplay::profiler_single_run_report(
            $params,
            $this->sampleRunData(),
            'desc',
            'oo',
            'wt',
            $runId
        );

        // 原卡片保留：先说明精确未命中，再给候选
        self::assertStringContainsString('运行数据里没有函数', $html);
        self::assertStringContainsString('<b>oo</b>', $html);

        preg_match('#<ul class="xp-search-matches">(.*?)</ul>#s', $html, $m);
        self::assertNotEmpty($m, '没有子串候选列表');
        // 夹具里只有 foo() 含 "oo"
        self::assertSame(1, substr_count($m[1], '<li>'), '候选只该有 foo() 一条');
        self::assertStringContainsString('>foo()</a>', $m[1]);
        self::assertStringContainsString('symbol=foo%28%29', $m[1], '候选必须链到详情页');
        self::assertStringContainsString('run=' . $runId, $m[1], '候选链接必须带上 run，否则点进去是首页');
    }

    /** 候选匹配忽略大小写：PHP 函数名大小写不敏感，别用大小写把用户挡在门外 */
    #[Test]
    public function searchSubstringMatchesIgnoreCase(): void
    {
        I18n::setLocale('zh_CN');
        $runId = 'a1a1a1a1a1a1a1a1';
        $params = ['run' => $runId, 'all' => 1, 'symbol' => 'STR'];
        $this->useRequest(new FakeRequest($params, ['uri' => '/xhprof']));

        $html = XhprofDisplay::profiler_single_run_report(
            $params,
            $this->sampleRunData(),
            'desc',
            'STR',
            'wt',
            $runId
        );

        self::assertStringContainsString('>strlen()</a>', $html);
    }

    /** 候选上限 30 条：35 个含 "x" 的符号只能列出 30 个（整表铺开会把卡片撑爆） */
    #[Test]
    public function searchMissListsAtMostThirtyCandidates(): void
    {
        I18n::setLocale('zh_CN');
        $runId = 'a1a1a1a1a1a1a1a1';
        $data = ['main()' => ['ct' => 1, 'wt' => 100000, 'mu' => 10]];
        for ($i = 0; $i < 35; $i++) {
            $data['main()==>sym' . $i . 'x()'] = ['ct' => 1, 'wt' => 1000, 'mu' => 1];
        }
        $params = ['run' => $runId, 'all' => 1, 'symbol' => 'x'];
        $this->useRequest(new FakeRequest($params, ['uri' => '/xhprof']));

        $html = XhprofDisplay::profiler_single_run_report($params, $data, 'desc', 'x', 'wt', $runId);

        preg_match('#<ul class="xp-search-matches">(.*?)</ul>#s', $html, $m);
        self::assertNotEmpty($m, '没有子串候选列表');
        self::assertSame(30, substr_count($m[1], '<li>'), '候选上限 30 条没生效');
        self::assertStringContainsString('<b>x</b>', $html, '标题里的搜索串必须原样出现');
    }

    /** 符号名来自 profile 数据（动态调用可影响它），候选列表必须转义 */
    #[Test]
    public function searchMatchListEscapesSymbolNames(): void
    {
        I18n::setLocale('zh_CN');
        $runId = 'a1a1a1a1a1a1a1a1';
        $hostile = 'foo<img src=x onerror=alert(1)>()';
        $data = [
            'main()' => ['ct' => 1, 'wt' => 100000, 'mu' => 10],
            'main()==>' . $hostile => ['ct' => 1, 'wt' => 5000, 'mu' => 1],
        ];
        $params = ['run' => $runId, 'all' => 1, 'symbol' => 'img'];
        $this->useRequest(new FakeRequest($params, ['uri' => '/xhprof']));

        $html = XhprofDisplay::profiler_single_run_report($params, $data, 'desc', 'img', 'wt', $runId);

        self::assertStringContainsString('&lt;img', $html);
        self::assertStringNotContainsString('<img', $html, '候选里的符号名没转义就是反射型 XSS');
    }

    /** diff 的符号表是增量表，不适用「有哪些函数」的索引语义——保持原卡片 */
    #[Test]
    public function searchMissKeepsThePlainCardInDiffMode(): void
    {
        // 夹具自检：run1 边表里含 foo()，去掉 !$diff_mode 守卫时 "oo" 会列出它。
        // 换成一个没有候选的搜索串（如 'nope'）这条断言就成了永远通过的摆设——
        // 实测那个变异体是全绿的，就是被这个空转的探针放过去的。
        self::assertArrayHasKey('main()==>foo()', $this->sampleRunData());

        I18n::setLocale('zh_CN');
        $html = XhprofDisplay::profiler_diff_report(
            ['run1' => 'r1', 'run2' => 'r2', 'all' => 1],
            $this->sampleRunData(),
            'd1',
            $this->sampleRunData(),
            'd2',
            'oo',
            'wt',
            'r1',
            'r2'
        );

        self::assertStringContainsString('运行数据里没有函数', $html);
        self::assertStringNotContainsString('xp-search-matches', $html);
    }

    /**
     * 运行列表 JS 的对比/聚合规则（无浏览器可跑，静态锚点）：
     * 恰好 2 条 → 原样 run1/run2（diff）；>2 条 → run= 按 create_time 升序（聚合）；
     * 按钮文案随选中数在「对比/聚合」之间切换。
     */
    #[Test]
    public function runsListJsAggregatesMoreThanTwoSelectedRuns(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/src/html/js/xhprof_report.js');

        // 基准：旧的「只有恰好两条才可点」必须已被替换
        self::assertStringNotContainsString('n !== 2', $js, '按钮仍在用「恰好两条」的禁用规则');
        self::assertMatchesRegularExpression(
            '/\$compareBtn\.prop\(["\']disabled["\'],\s*n\s*<\s*2\)/',
            $js,
            '不足两条才禁用（n < 2）'
        );

        // >2 条走聚合：run= 升序 id 列表
        self::assertMatchesRegularExpression('/picks\.length\s*>\s*2/', $js, '缺少 >2 条走聚合的分支');
        self::assertMatchesRegularExpression(
            '/\.sort\(function \(a, b\) \{ return a\.t - b\.t; \}\)/',
            $js,
            'run= 的 id 必须按 data-create-time 升序'
        );
        self::assertMatchesRegularExpression(
            '/cur_params\[["\']run["\']\]\s*=\s*ids\.join\(["\'],["\']\)/',
            $js,
            '聚合路径必须把 id 用逗号拼进 run='
        );

        // 恰好两条的 run1/run2 路径必须还在（现有 diff 行为不变）
        self::assertStringContainsString("cur_params['run1'] = picks[0].id;", $js);
        self::assertStringContainsString("cur_params['run2'] = picks[1].id;", $js);
        // 且必须先清掉可能残留的 run=：派发时 run 分支优先于 run1/run2，
        // 不清就会把「恰好两条」送进聚合页。位置钉在 run1 赋值之前（= 两条那条分支里）。
        self::assertSame(1, substr_count($js, "delete cur_params['run'];"), 'run= 的清理应恰好出现一次');
        self::assertBefore($js, "delete cur_params['run'];", "cur_params['run1'] = picks[0].id;");

        // 按钮文案来自注入的 xpI18n（按钮静态 HTML 在 Utils 层，Display 换不了）
        self::assertStringContainsString('runsAggregate', $js);
        self::assertStringContainsString('$compareBtn.text(', $js);
    }

    /** 聚合按钮文案经 window.xpI18n 注入，随语言变化 */
    #[Test]
    public function injectedJsCarriesTheAggregateButtonLabel(): void
    {
        I18n::setLocale('zh_CN');
        $zh = XhprofDisplay::xhprof_include_js_css('/xhprof-assets');
        self::assertStringContainsString('"runsAggregate":"聚合选中"', $zh);

        I18n::setLocale('en');
        $en = XhprofDisplay::xhprof_include_js_css('/xhprof-assets');
        self::assertStringContainsString('"runsAggregate":"Aggregate selected"', $en);

        I18n::setLocale(I18n::FALLBACK);
    }

    /**
     * diff 的两侧可以是逗号串：各自聚合后再对比。
     * 缺陷形态：整串进 get_run()（它的 run_id 正则拒逗号）→ false → 只剩导航条的空白页。
     */
    #[Test]
    public function diffWithCommaSeparatedRunListsAggregatesEachSide(): void
    {
        I18n::setLocale('zh_CN');
        $a1 = 'a1a1a1a1a1a1a1a1';
        $a2 = 'a1a1a1a1a1a1a1a2';
        $b1 = 'b2b2b2b2b2b2b2b2';
        $b2 = 'b2b2b2b2b2b2b2b3';
        foreach ([$a1, $a2, $b1, $b2] as $rid) {
            $this->cache->set('xhprof:xhprof_log:' . $rid, serialize($this->sampleRunData()));
        }
        $params = ['run1' => "$a1,$a2", 'run2' => "$b1,$b2", 'all' => 1];
        $this->useRequest(new FakeRequest($params, ['uri' => '/xhprof']));

        $html = XhprofDisplay::displayXHProfReport(
            $params,
            'xhprof_foo',
            null,
            null,
            null,
            null,
            "$a1,$a2",
            "$b1,$b2"
        );

        self::assertStringContainsString('差异总览', $html, '聚合后的 diff 必须真的渲染出报告');
        self::assertStringContainsString('2 次运行的聚合报告', $html, '描述应带聚合口径（agg.title）');
        self::assertStringContainsString('运行 #' . $a1 . ',' . $a2, $html, '聚合串要原样出现在描述里');
    }

    /** diff 任一侧读不到（过期/被清理）→ 空态卡，而不是只剩导航条 */
    #[Test]
    public function diffWithUnreadableRunsShowsTheNoDataCard(): void
    {
        I18n::setLocale('zh_CN');
        $r1 = 'a1a1a1a1a1a1a1a1';
        $r2 = 'b2b2b2b2b2b2b2b2';   // 缓存里都没有
        $html = XhprofDisplay::displayXHProfReport(
            ['run1' => $r1, 'run2' => $r2, 'all' => 1],
            'xhprof_foo',
            null,
            null,
            null,
            null,
            $r1,
            $r2
        );

        self::assertStringContainsString('已不存在', $html, '数据不在了要给空态卡解释，不能只留导航条');
        self::assertStringNotContainsString('差异总览', $html);

        // 逗号串（聚合路径）全部读不到时 raw 是 null 而不是 false：判定必须是 falsy，
        // 只判 === false 会让 null 漏进渲染管线（init_metrics(null) 那一步直接炸）。
        // **两侧都得是聚合串**——任一侧是单 id 时那条路径回的是 false，
        // === false 也能拦住，这个变异就观察不到。
        $html = XhprofDisplay::displayXHProfReport(
            ['run1' => "$r1,$r2", 'run2' => "$r1,$r2", 'all' => 1],
            'xhprof_foo',
            null,
            null,
            null,
            null,
            "$r1,$r2",
            "$r1,$r2"
        );

        self::assertStringContainsString('已不存在', $html, '聚合到 0 个可用 run 也要走同一张空态卡');
    }

    /** 单 run 分支的空态卡（提取成 no_data_page() 后行为不变） */
    #[Test]
    public function runWithUnreadableIdShowsTheNoDataCard(): void
    {
        I18n::setLocale('zh_CN');
        $r1 = 'a1a1a1a1a1a1a1a1';
        $html = XhprofDisplay::displayXHProfReport(
            ['run' => $r1, 'all' => 1],
            'xhprof_foo',
            $r1,
            null,
            null,
            null,
            null,
            null
        );

        self::assertStringContainsString('已不存在', $html);
    }
}
