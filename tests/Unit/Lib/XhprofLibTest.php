<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Lib;

require_once __DIR__ . '/../../Fixtures/Fakes.php';

use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofLib\Display\XhprofDisplay;
use ErikWang2013\Xhprof\Core\XhprofLib\Utils\XhprofLib;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeConfig;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeRequest;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class XhprofLibTest extends TestCase
{
    protected FakeCache $cache;
    protected FakeRequest $request;
    protected FakeResponse $response;
    protected FakeConfig $config;
    protected FakeLogger $logger;

    protected function setUp(): void
    {
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

        // 渲染状态是**按请求**的：$_hyperf 被 Hyperf 用例置位后（进程级、不可逆），
        // 它存在协程 Context 里，直接写静态属性在那个模式下没人读。布置与断言一律走
        // XhprofDisplay 的存取器，本文件因此在两种进程模式下行为一致。
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

    /** 替换请求时同步刷新 Hyperf Context，保证 $_hyperf=true 时 getRequest() 仍取到 fake */
    private function useRequest(FakeRequest $request): void
    {
        Xhprof::$request = $request;
        if (class_exists(\Hyperf\Context\Context::class)) {
            \Hyperf\Context\Context::set('xhprof.request', $request);
        }
    }

    private function silence(): void
    {
        set_error_handler(static function (int $errno, string $errstr): bool {
            return true;
        });
    }

    private function unsilence(): void
    {
        restore_error_handler();
    }

    #[Test]
    public function possibleMetricsContainsSevenKnownMetrics(): void
    {
        $metrics = XhprofLib::xhprof_get_possible_metrics();
        self::assertCount(7, $metrics);
        self::assertSame(['Wall', 'microsecs', 'walltime'], $metrics['wt']);
        self::assertSame(['MUse', 'bytes', 'memory usage'], $metrics['mu']);
        self::assertSame(['PMUse', 'bytes', 'peak memory usage'], $metrics['pmu']);
        self::assertSame(['Samples', 'samples', 'cpu time'], $metrics['samples']);
    }

    #[Test]
    public function getMetricsReturnsOnlyPresentMetrics(): void
    {
        $data = ['main()' => ['wt' => 100, 'mu' => 10]];
        self::assertSame(['wt', 'mu'], XhprofLib::xhprof_get_metrics($data));
        self::assertSame([], XhprofLib::xhprof_get_metrics(['foo()' => ['wt' => 1]]));
    }

    #[Test]
    public function initMetricsSetsDisplayState(): void
    {
        $data = ['main()' => ['wt' => 100, 'mu' => 10]];
        XhprofLib::init_metrics($data, null, null, false);

        self::assertSame(['wt', 'mu'], XhprofDisplay::metrics());
        self::assertSame(
            ['fn', 'ct', 'Calls%', 'wt', 'IWall%', 'excl_wt', 'EWall%', 'mu', 'IMUse%', 'excl_mu', 'EMUse%'],
            XhprofDisplay::stats()
        );
        self::assertSame(['fn', 'ct', 'Calls%', 'wt', 'IWall%', 'mu', 'IMUse%'], XhprofDisplay::pc_stats());
        self::assertSame('wt', XhprofDisplay::sort_col());
        self::assertTrue(XhprofDisplay::display_calls());
        self::assertTrue(XhprofDisplay::diff_mode() === false);
    }

    #[Test]
    public function initMetricsWithValidSortOverridesSortCol(): void
    {
        // 该 run 必须真的采集过 mu，否则属于"按不存在的指标排序"（旧实现会放行并崩在 sort_cbk）
        XhprofLib::init_metrics(['main()' => ['wt' => 100, 'mu' => 10]], null, 'mu', false);
        self::assertSame('mu', XhprofDisplay::sort_col());
    }

    #[Test]
    public function initMetricsRejectsSortByMetricNotCollected(): void
    {
        // ut/st/samples 在本扩展的 flags 下永不采集，白名单不能放行
        XhprofLib::init_metrics(['main()' => ['wt' => 100, 'mu' => 10]], null, 'ut', false);
        self::assertSame('wt', XhprofDisplay::sort_col());
        self::assertStringContainsString('Invalid Sort Key ut specified in URL', $this->logger->errors[0]);
    }

    #[Test]
    public function initMetricsDoesNotInheritSortColFromPreviousRequest(): void
    {
        // 常驻 worker 下渲染状态跨请求存活：不带 sort 的请求不能被上一请求的排序列污染
        XhprofDisplay::set_render_state(['sort_col' => 'mu']);
        XhprofLib::init_metrics(['main()' => ['wt' => 100, 'mu' => 10]], null, null, false);
        self::assertSame('wt', XhprofDisplay::sort_col());
    }

    #[Test]
    public function initMetricsWithInvalidSortLogsErrorAndKeepsDefault(): void
    {
        XhprofLib::init_metrics(['main()' => ['wt' => 100]], null, 'bogus', false);
        self::assertSame('wt', XhprofDisplay::sort_col());
        self::assertStringContainsString('Invalid Sort Key bogus specified in URL', $this->logger->errors[0]);
    }

    #[Test]
    public function initMetricsWithoutWtFallsBackToSamplesAndHidesCalls(): void
    {
        XhprofLib::init_metrics(['main()' => ['samples' => 5]], null, null, false);
        self::assertSame('samples', XhprofDisplay::sort_col());
        self::assertFalse(XhprofDisplay::display_calls());
        // 无 wt 时仍会为存在的 samples 指标追加统计列
        self::assertSame(
            ['fn', 'samples', 'ISamples%', 'excl_samples', 'ESamples%'],
            XhprofDisplay::stats()
        );
    }

    #[Test]
    public function initMetricsWithRepSymbolStripsExclPrefixFromSortCol(): void
    {
        XhprofLib::init_metrics(['main()' => ['wt' => 100]], 'foo()', 'excl_wt', false);
        self::assertSame('wt', XhprofDisplay::sort_col());
    }

    #[Test]
    #[DataProvider('parseParentChildProvider')]
    public function parseParentChild(string $input, ?string $expectedParent, ?string $expectedChild): void
    {
        $ret = XhprofLib::xhprof_parse_parent_child($input);
        self::assertSame($expectedParent, $ret[0]);
        self::assertSame($expectedChild, $ret[1]);
    }

    public static function parseParentChildProvider(): array
    {
        return [
            'parent and child' => ['main()==>foo()', 'main()', 'foo()'],
            'no parent' => ['foo()', null, 'foo()'],
            'empty segment' => ['main()==>', 'main()', ''],
        ];
    }

    #[Test]
    public function buildParentChildKey(): void
    {
        self::assertSame('main()==>foo()', XhprofLib::xhprof_build_parent_child_key('main()', 'foo()'));
        self::assertSame('foo()', XhprofLib::xhprof_build_parent_child_key(null, 'foo()'));
        self::assertSame('foo()', XhprofLib::xhprof_build_parent_child_key('', 'foo()'));
    }

    #[Test]
    public function validRunAcceptsWallTimeData(): void
    {
        $data = ['main()' => ['wt' => 100], 'main()==>foo()' => ['wt' => 10]];
        self::assertTrue(XhprofLib::xhprof_valid_run('a1a1a1a1a1a1a1a1', $data));
    }

    #[Test]
    public function validRunAcceptsSamplesData(): void
    {
        $data = ['main()' => ['samples' => 5], 'main()==>foo()' => ['samples' => 2]];
        self::assertTrue(XhprofLib::xhprof_valid_run('a1a1a1a1a1a1a1a1', $data));
    }

    #[Test]
    public function validRunRejectsMissingMain(): void
    {
        $this->silence();
        $ok = XhprofLib::xhprof_valid_run('a1a1a1a1a1a1a1a1', ['foo()' => ['wt' => 1]]);
        $this->unsilence();
        self::assertFalse($ok);
        self::assertStringContainsString('main() missing in raw data', $this->logger->errors[0]);
    }

    #[Test]
    public function validRunRejectsMissingWallTime(): void
    {
        $this->silence();
        $ok = XhprofLib::xhprof_valid_run('a1a1a1a1a1a1a1a1', ['main()' => ['mu' => 5]]);
        $this->unsilence();
        self::assertFalse($ok);
        self::assertStringContainsString('Wall Time information missing', $this->logger->errors[0]);
    }

    #[Test]
    public function validRunRejectsNegativeMetric(): void
    {
        $data = ['main()' => ['wt' => -1], 'main()==>foo()' => ['wt' => 10]];
        self::assertFalse(XhprofLib::xhprof_valid_run('a1a1a1a1a1a1a1a1', $data));
        self::assertStringContainsString('should not be negative', $this->logger->errors[0]);
    }

    #[Test]
    public function validRunRejectsMetricOverOneDay(): void
    {
        $data = ['main()' => ['wt' => 86400000001]];
        self::assertFalse(XhprofLib::xhprof_valid_run('a1a1a1a1a1a1a1a1', $data));
        self::assertStringContainsString('> 1 day', $this->logger->errors[0]);
    }

    #[Test]
    public function trimRunKeepsOnlyMatchingFunctionsAndMain(): void
    {
        $data = [
            'main()' => ['wt' => 100],
            'main()==>foo()' => ['wt' => 40],
            'main()==>bar()' => ['wt' => 30],
            'foo()==>helper()' => ['wt' => 20],
            'foo()==>bar()' => ['wt' => 5],
        ];
        $res = XhprofLib::xhprof_trim_run($data, ['bar()']);
        // 保留：main()（恒保留）、parent 为 main() 的边、child 命中 keep 列表的边
        self::assertCount(4, $res);
        self::assertArrayHasKey('main()', $res);
        self::assertArrayHasKey('main()==>foo()', $res);
        self::assertArrayHasKey('main()==>bar()', $res);
        self::assertArrayHasKey('foo()==>bar()', $res);
        self::assertArrayNotHasKey('foo()==>helper()', $res);
    }

    #[Test]
    public function normalizeMetricsDividesByRunCount(): void
    {
        $data = ['main()' => ['wt' => 100], 'main()==>foo()' => ['wt' => 40]];
        $res = XhprofLib::xhprof_normalize_metrics($data, 2);
        self::assertEqualsWithDelta(50.0, $res['main()']['wt'], 1e-9);
        self::assertEqualsWithDelta(20.0, $res['main()==>foo()']['wt'], 1e-9);
    }

    #[Test]
    public function normalizeMetricsSkipsZeroRunCount(): void
    {
        $data = ['main()' => ['wt' => 100]];
        self::assertSame($data, XhprofLib::xhprof_normalize_metrics($data, 0));
    }

    #[Test]
    public function normalizeMetricsHandlesEmptyData(): void
    {
        self::assertSame([], XhprofLib::xhprof_normalize_metrics([], 5));
    }

    #[Test]
    public function aggregateRunsRejectsEmptyRuns(): void
    {
        $res = XhprofLib::xhprof_aggregate_runs([], []);
        self::assertSame('输入无效。', $res['description']);
        self::assertNull($res['raw']);
    }

    #[Test]
    public function aggregateRunsRejectsWeightCountMismatch(): void
    {
        $res = XhprofLib::xhprof_aggregate_runs(['a1a1a1a1a1a1a1a1'], [1, 2]);
        self::assertSame('输入无效。', $res['description']);
    }

    #[Test]
    public function aggregateRunsWeightsAndNormalizes(): void
    {
        $this->cache->set('xhprof:xhprof_log:a1a1a1a1a1a1a1a1', serialize([
            'main()' => ['wt' => 100, 'mu' => 1],
            'main()==>foo()' => ['wt' => 40, 'mu' => 0.5],
        ]));
        $this->cache->set('xhprof:xhprof_log:b2b2b2b2b2b2b2b2', serialize([
            'main()' => ['wt' => 200, 'mu' => 2],
            'main()==>foo()' => ['wt' => 60, 'mu' => 1],
        ]));

        $res = XhprofLib::xhprof_aggregate_runs(
            ['a1a1a1a1a1a1a1a1', 'b2b2b2b2b2b2b2b2'],
            [1, 3],
            'xhprof_foo'
        );
        self::assertSame([], $res['bad_runs']);
        self::assertEqualsWithDelta(175.0, $res['raw']['main()']['wt'], 1e-9);
        self::assertEqualsWithDelta(55.0, $res['raw']['main()==>foo()']['wt'], 1e-9);
        self::assertEqualsWithDelta(1.75, $res['raw']['main()']['mu'], 1e-9);
        self::assertStringContainsString(
            '2 次运行的聚合报告：a1a1a1a1a1a1a1a1,b2b2b2b2b2b2b2b2 权重比 (1:3)',
            $res['description']
        );
    }

    #[Test]
    public function aggregateRunsRecordsBadRuns(): void
    {
        $this->cache->set('xhprof:xhprof_log:a1a1a1a1a1a1a1a1', serialize([
            'main()' => ['wt' => 100, 'mu' => 1],
        ]));
        $this->silence();
        $res = XhprofLib::xhprof_aggregate_runs(['a1a1a1a1a1a1a1a1', 'badid'], [], 'xhprof_foo');
        $this->unsilence();
        self::assertSame(['badid'], $res['bad_runs']);
        self::assertStringContainsString('1 次运行的聚合报告', $res['description']);
        self::assertSame(100, $res['raw']['main()']['wt']);
    }

    private function seedTwoRuns(): void
    {
        foreach (['a1a1a1a1a1a1a1a1', 'b2b2b2b2b2b2b2b2'] as $id) {
            $this->cache->set("xhprof:xhprof_log:$id", serialize([
                'main()' => ['wt' => 100, 'mu' => 1],
            ]));
        }
    }

    /**
     * 第一个 run 过期（默认 TTL 7 天）时，聚合报告必须照样出得来。
     *
     * 曾经的失败：指标集取自 `$idx == 0`，而且取在有效性检查**之前** —— 第一个 run
     * 读不到时 `foreach ($raw_data["main()"])` 只立下一条 "array offset on false" 警告，
     * `$metrics` 留空，于是后面每一层 `foreach ($metrics …)` 都不进，`$raw_data_total`
     * 保持 null，页面只剩一条导航条（第二个 run 明明可读）。
     *
     * 刻意**不** silence()：PHPUnit 开了 failOnWarning，所以只要那条警告回来，
     * 本用例就会红在新跑出来的 warning 上。
     */
    #[Test]
    public function aggregateRunsStillRendersWhenTheFirstRunExpired(): void
    {
        // 只放第二个 run：第一个是格式合法的 run_id，但数据已不在缓存里
        $this->cache->set('xhprof:xhprof_log:b2b2b2b2b2b2b2b2', serialize([
            'main()' => ['wt' => 200, 'mu' => 2],
            'main()==>foo()' => ['wt' => 60, 'mu' => 1],
        ]));

        $res = XhprofLib::xhprof_aggregate_runs(
            ['a1a1a1a1a1a1a1a1', 'b2b2b2b2b2b2b2b2'],
            [],
            'xhprof_foo'
        );

        self::assertSame(['a1a1a1a1a1a1a1a1'], $res['bad_runs']);
        self::assertNotNull($res['raw'], '第一个 run 过期不该让整份聚合报告变成空');
        self::assertEqualsWithDelta(200.0, $res['raw']['main()']['wt'], 1e-9);
        self::assertArrayHasKey('main()==>foo()', $res['raw'], '可读的那个 run 的边表必须留下来');
        self::assertStringContainsString('1 次运行的聚合报告', $res['description']);
    }

    /**
     * 报告页内部链接：当前路径 + 合并后的查询串，且必须是**相对** URL。
     *
     * 三件事一起钉住：鉴权与语言参数要跟着走（否则点一下 403 / 语言复位）、
     * 视图参数要被摘掉（点「首页」不该停在原来的 run 上）、
     * 且不读 `X-Forwarded-Proto`（未校验的请求头以前能整段落进 href）。
     */
    #[Test]
    public function reportUrlMergesParamsAndStaysRelative(): void
    {
        $this->useRequest(new FakeRequest(
            ['run' => 'a1a1a1a1a1a1a1a1', 'symbol' => 'foo()', 'token' => 'tok', 'lang' => 'ko', 'sort' => 'wt'],
            ['uri' => '/xhprof', 'headers' => ['x-forwarded-proto' => 'javascript:alert(1)']]
        ));

        // 首页：摘掉全部视图参数，鉴权与语言留住
        $home = XhprofLib::report_url();
        self::assertStringStartsWith('/xhprof?', $home);
        self::assertStringContainsString('token=tok', $home);
        self::assertStringContainsString('lang=ko', $home);
        foreach (XhprofLib::VIEW_PARAMS as $k) {
            self::assertStringNotContainsString($k . '=', $home, "首页链接不该带视图参数 {$k}");
        }
        self::assertStringNotContainsString('javascript:', $home);

        // run 链接：设上 run 相关的参数，其余照旧传播
        $run = XhprofLib::report_url(['all' => 1, 'run' => 'b2b2b2b2b2b2b2b2', 'requrl' => '/order?x=1&y=2']);
        self::assertStringContainsString('token=tok', $run);
        self::assertStringContainsString('lang=ko', $run);
        self::assertStringContainsString('run=b2b2b2b2b2b2b2b2', $run);
        self::assertStringNotContainsString('a1a1a1a1a1a1a1a1', $run, '旧的 run 参数必须被覆盖掉');
        self::assertStringNotContainsString('symbol=', $run, 'symbol 属于视图参数，该摘掉');
        self::assertStringContainsString('requrl=%2Forder%3Fx%3D1%26y%3D2', $run, '参数值要按查询串编码');

        // 值为 null = 删掉该参数
        self::assertStringNotContainsString('lang=', XhprofLib::report_url(['lang' => null]));
    }

    /** 路径取自当前请求，且已转义（它会落进 href="…" 属性） */
    #[Test]
    public function reportPathIsEscaped(): void
    {
        $this->useRequest(new FakeRequest([], ['uri' => '/xhprof/']));
        self::assertSame('/xhprof', XhprofLib::report_path(), '尾斜杠要归一');

        $this->useRequest(new FakeRequest([], ['uri' => '/x"y/z']));
        self::assertSame('/x&quot;y/z', XhprofLib::report_path(), '引号必须转义，否则能逃出属性');
    }

    /**
     * 曾经的崩溃：wts 直接来自查询串，原先只校验个数、不校验数值，
     * 于是 $wt * $info[$metric] 在 PHP 8 抛 "string * int" TypeError。
     */
    #[Test]
    public function aggregateRunsRejectsNonNumericWts(): void
    {
        $this->seedTwoRuns();

        $res = XhprofLib::xhprof_aggregate_runs(
            ['a1a1a1a1a1a1a1a1', 'b2b2b2b2b2b2b2b2'],
            ['1', 'abc'],
            'xhprof_foo'
        );

        self::assertSame('输入无效。', $res['description']);
        self::assertNull($res['raw']);
    }

    #[Test]
    public function aggregateRunsAcceptsNumericWts(): void
    {
        $this->seedTwoRuns();

        $res = XhprofLib::xhprof_aggregate_runs(
            ['a1a1a1a1a1a1a1a1', 'b2b2b2b2b2b2b2b2'],
            ['1', '3'],
            'xhprof_foo'
        );

        // 权重 1:3，总和 4 → (100*1 + 100*3) / 4 = 100
        self::assertSame(100, $res['raw']['main()']['wt']);
    }

    #[Test]
    public function aggregateRunsWithUseScriptNameRewritesEdges(): void
    {
        $this->cache->set('xhprof:xhprof_log:a1a1a1a1a1a1a1a1', serialize([
            'main()' => ['wt' => 100, 'mu' => 2],
            'main()==>foo()' => ['wt' => 40, 'mu' => 1],
        ]));

        $res = XhprofLib::xhprof_aggregate_runs(['a1a1a1a1a1a1a1a1'], [], 'xhprof_foo', true);
        $raw = $res['raw'];
        self::assertEqualsWithDelta(100.00001, $raw['main()']['wt'], 1e-6);
        self::assertSame(100, $raw['main()==>__script::XHProf Run (Namespace=xhprof_foo)']['wt']);
        self::assertSame(40, $raw['__script::XHProf Run (Namespace=xhprof_foo)==>foo()']['wt']);
    }

    #[Test]
    public function computeFlatInfoComputesExclusiveTimes(): void
    {
        $data = [
            'main()' => ['ct' => 1, 'wt' => 100, 'mu' => 512],
            'main()==>foo()' => ['ct' => 1, 'wt' => 40, 'mu' => 200],
        ];
        $totals = null;
        $tab = XhprofLib::xhprof_compute_flat_info($data, $totals);

        self::assertSame(100, $totals['wt']);
        self::assertSame(512, $totals['mu']);
        self::assertSame(2, $totals['ct']);
        self::assertSame(60, $tab['main()']['excl_wt']);
        self::assertSame(40, $tab['foo()']['excl_wt']); // foo() 无子边，excl 等于 inclusive
        self::assertSame(40, $tab['foo()']['wt']);
    }

    #[Test]
    public function computeDiffSubtractsRun1FromRun2(): void
    {
        $data1 = [
            'main()' => ['ct' => 1, 'wt' => 100],
            'main()==>foo()' => ['ct' => 2, 'wt' => 40],
        ];
        $data2 = [
            'main()' => ['ct' => 1, 'wt' => 150],
            'main()==>foo()' => ['ct' => 3, 'wt' => 50],
            'main()==>bar()' => ['ct' => 1, 'wt' => 10],
        ];
        $delta = XhprofLib::xhprof_compute_diff($data1, $data2);

        self::assertSame(['ct' => 0, 'wt' => 50], $delta['main()']);
        self::assertSame(['ct' => 1, 'wt' => 10], $delta['main()==>foo()']);
        self::assertSame(['ct' => 1, 'wt' => 10], $delta['main()==>bar()']);
    }

    #[Test]
    public function computeInclusiveTimesSumsAcrossEdges(): void
    {
        $data = [
            'main()' => ['ct' => 1, 'wt' => 100],
            'main()==>a()' => ['ct' => 2, 'wt' => 30],
            'main()==>b()' => ['ct' => 1, 'wt' => 50],
            'a()==>b()' => ['ct' => 1, 'wt' => 10],
        ];
        $tab = XhprofLib::xhprof_compute_inclusive_times($data);

        self::assertSame(100, $tab['main()']['wt']);
        self::assertSame(30, $tab['a()']['wt']);
        self::assertSame(2, $tab['a()']['ct']);
        self::assertSame(60, $tab['b()']['wt']);
        self::assertSame(2, $tab['b()']['ct']);
    }

    #[Test]
    public function computeInclusiveTimesRejectsParentEqualsChild(): void
    {
        // 必须返回空数组而非 null：调用方会直接对它取下标并 foreach
        $ret = XhprofLib::xhprof_compute_inclusive_times(['a()==>a()' => ['ct' => 1, 'wt' => 5]]);
        self::assertSame([], $ret);
        self::assertStringContainsString('parent & child are both: a()', $this->logger->errors[0]);
    }

    #[Test]
    public function arraySetAndUnset(): void
    {
        $arr = XhprofLib::xhprof_array_set(['a' => 1], 'b', 2);
        self::assertSame(['a' => 1, 'b' => 2], $arr);
        $arr = XhprofLib::xhprof_array_unset($arr, 'a');
        self::assertSame(['b' => 2], $arr);
    }

    #[Test]
    public function isIgnoreMatchesConfiguredUrl(): void
    {
        Xhprof::$ignore_url_arr = ['/test'];
        $this->request = new FakeRequest([], ['uri' => '/test/foo']);
        $this->useRequest($this->request);
        self::assertFalse(XhprofLib::isIgnore());
    }

    #[Test]
    public function isIgnoreReturnsTrueWhenNoMatch(): void
    {
        Xhprof::$ignore_url_arr = ['/test'];
        $this->request = new FakeRequest([], ['uri' => '/order']);
        $this->useRequest($this->request);
        self::assertTrue(XhprofLib::isIgnore());
    }

    #[Test]
    public function isIgnoreMatchesCaseInsensitive(): void
    {
        Xhprof::$ignore_url_arr = ['/TEST'];
        $this->request = new FakeRequest([], ['uri' => '/test/x']);
        $this->useRequest($this->request);
        self::assertFalse(XhprofLib::isIgnore());
    }

    #[Test]
    public function isIgnoreReturnsFalseForEmptyUri(): void
    {
        Xhprof::$ignore_url_arr = ['/test'];
        $this->request = new FakeRequest([], ['uri' => '']);
        $this->useRequest($this->request);
        self::assertFalse(XhprofLib::isIgnore());
    }

    #[Test]
    public function isIgnoreReturnsTrueWhenConfigNotArray(): void
    {
        Xhprof::$ignore_url_arr = 'not-an-array';
        self::assertTrue(XhprofLib::isIgnore());
    }

    #[Test]
    public function getRequestLogDecodesStoredJson(): void
    {
        $this->cache->set('xhprof:request_log:a1a1a1a1a1a1a1a1', json_encode(['request_uri' => '/x', 'method' => 'GET']));
        $log = XhprofLib::getRequestLog('a1a1a1a1a1a1a1a1');
        self::assertSame('/x', $log['request_uri']);
        self::assertSame('GET', $log['method']);
    }

    #[Test]
    public function getRequestLogReturnsFalseWhenMissing(): void
    {
        self::assertFalse(XhprofLib::getRequestLog('a1a1a1a1a1a1a1a1'));
    }

    #[Test]
    public function getRequestLogReturnsNullOnInvalidJson(): void
    {
        // 行为即实现：json 非法时返回 json_decode 结果 null（而非 false）
        $this->cache->set('xhprof:request_log:a1a1a1a1a1a1a1a1', 'not-json');
        self::assertNull(XhprofLib::getRequestLog('a1a1a1a1a1a1a1a1'));
    }

    #[Test]
    public function xhprofErrorLogsViaLogger(): void
    {
        XhprofLib::xhprof_error('boom');
        self::assertStringContainsString('boom', $this->logger->errors[0]);
        self::assertStringContainsString('Xhprof', $this->logger->errors[0]);
    }
}
