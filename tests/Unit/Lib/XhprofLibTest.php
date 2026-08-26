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

        self::assertSame(['wt', 'mu'], XhprofDisplay::$metrics);
        self::assertSame(
            ['fn', 'ct', 'Calls%', 'wt', 'IWall%', 'excl_wt', 'EWall%', 'mu', 'IMUse%', 'excl_mu', 'EMUse%'],
            XhprofDisplay::$stats
        );
        self::assertSame(['fn', 'ct', 'Calls%', 'wt', 'IWall%', 'mu', 'IMUse%'], XhprofDisplay::$pc_stats);
        self::assertSame('wt', XhprofDisplay::$sort_col);
        self::assertTrue(XhprofDisplay::$display_calls);
        self::assertTrue(XhprofDisplay::$diff_mode === false);
    }

    #[Test]
    public function initMetricsWithValidSortOverridesSortCol(): void
    {
        XhprofLib::init_metrics(['main()' => ['wt' => 100]], null, 'mu', false);
        self::assertSame('mu', XhprofDisplay::$sort_col);
    }

    #[Test]
    public function initMetricsWithInvalidSortLogsErrorAndKeepsDefault(): void
    {
        XhprofLib::init_metrics(['main()' => ['wt' => 100]], null, 'bogus', false);
        self::assertSame('wt', XhprofDisplay::$sort_col);
        self::assertStringContainsString('Invalid Sort Key bogus specified in URL', $this->logger->errors[0]);
    }

    #[Test]
    public function initMetricsWithoutWtFallsBackToSamplesAndHidesCalls(): void
    {
        XhprofLib::init_metrics(['main()' => ['samples' => 5]], null, null, false);
        self::assertSame('samples', XhprofDisplay::$sort_col);
        self::assertFalse(XhprofDisplay::$display_calls);
        // 无 wt 时仍会为存在的 samples 指标追加统计列
        self::assertSame(
            ['fn', 'samples', 'ISamples%', 'excl_samples', 'ESamples%'],
            XhprofDisplay::$stats
        );
    }

    #[Test]
    public function initMetricsWithRepSymbolStripsExclPrefixFromSortCol(): void
    {
        XhprofLib::init_metrics(['main()' => ['wt' => 100]], 'foo()', 'excl_wt', false);
        self::assertSame('wt', XhprofDisplay::$sort_col);
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
        self::assertSame('Invalid input..', $res['description']);
        self::assertNull($res['raw']);
    }

    #[Test]
    public function aggregateRunsRejectsWeightCountMismatch(): void
    {
        $res = XhprofLib::xhprof_aggregate_runs(['a1a1a1a1a1a1a1a1'], [1, 2]);
        self::assertSame('Invalid input..', $res['description']);
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
            'Aggregated Report for 2 runs: a1a1a1a1a1a1a1a1,b2b2b2b2b2b2b2b2 in the ratio (1:3)',
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
        self::assertStringContainsString('Aggregated Report for 1 runs', $res['description']);
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
        $ret = XhprofLib::xhprof_compute_inclusive_times(['a()==>a()' => ['ct' => 1, 'wt' => 5]]);
        self::assertNull($ret);
        self::assertStringContainsString('parent & child are both: a()', $this->logger->errors[0]);
    }

    #[Test]
    public function pruneRunDropsEdgesBelowThresholdAndReparents(): void
    {
        $data = [
            'main()' => ['ct' => 1, 'wt' => 1000, 'mu' => 10],
            'main()==>a()' => ['ct' => 1, 'wt' => 50, 'mu' => 1],
            'main()==>b()' => ['ct' => 1, 'wt' => 1000, 'mu' => 9],
            'a()==>b()' => ['ct' => 1, 'wt' => 50, 'mu' => 1],
        ];
        $res = XhprofLib::xhprof_prune_run($data, 20);

        self::assertArrayHasKey('main()', $res);
        self::assertArrayHasKey('main()==>b()', $res);
        self::assertArrayNotHasKey('main()==>a()', $res);
        self::assertArrayNotHasKey('a()==>b()', $res);
        self::assertSame(50, $res['__pruned__()==>b()']['wt']);
    }

    #[Test]
    public function pruneRunRejectsMissingMain(): void
    {
        $this->silence();
        $ret = XhprofLib::xhprof_prune_run(['foo()' => ['wt' => 1]], 10);
        $this->unsilence();
        self::assertFalse($ret);
        self::assertStringContainsString('main() missing in raw data', $this->logger->errors[0]);
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
    public function getUintParamAcceptsDigits(): void
    {
        $this->request = new FakeRequest(['n' => '42']);
        $this->useRequest($this->request);
        self::assertSame('42', XhprofLib::xhprof_get_uint_param('n'));
    }

    #[Test]
    public function getUintParamTrimsWhitespace(): void
    {
        $this->request = new FakeRequest(['n' => ' 7 ']);
        $this->useRequest($this->request);
        self::assertSame('7', XhprofLib::xhprof_get_uint_param('n'));
    }

    #[Test]
    public function getUintParamRejectsNonDigits(): void
    {
        $this->request = new FakeRequest(['n' => 'abc']);
        $this->useRequest($this->request);
        self::assertNull(XhprofLib::xhprof_get_uint_param('n'));
        self::assertStringContainsString('must be an unsigned integer', $this->logger->errors[0]);
    }

    #[Test]
    public function getUintParamReturnsDefaultWhenMissing(): void
    {
        // 缺失参数直接返回默认值（int 0），不再对默认值 trim 崩溃
        self::assertSame(0, XhprofLib::xhprof_get_uint_param('n'));
    }

    #[Test]
    public function getFloatParamCastsToFloat(): void
    {
        $this->request = new FakeRequest(['f' => '3.14']);
        $this->useRequest($this->request);
        self::assertSame(3.14, XhprofLib::xhprof_get_float_param('f'));
    }

    #[Test]
    public function getFloatParamReturnsDefaultWhenMissing(): void
    {
        // 缺失参数直接返回默认值，不再对默认值 trim 崩溃
        self::assertSame(2.5, XhprofLib::xhprof_get_float_param('f', 2.5));
    }

    #[Test]
    #[DataProvider('boolParamProvider')]
    public function getBoolParamParsesValidValues(string $value, bool $expected): void
    {
        $this->request = new FakeRequest(['b' => $value]);
        $this->useRequest($this->request);
        self::assertSame($expected, XhprofLib::xhprof_get_bool_param('b'));
    }

    public static function boolParamProvider(): array
    {
        return [
            'true' => ['true', true],
            'on' => ['on', true],
            'yes' => ['yes', true],
            '1' => ['1', true],
            'false' => ['false', false],
            'off' => ['off', false],
            'no' => ['no', false],
            '0' => ['0', false],
            'uppercase' => ['TRUE', true],
        ];
    }

    #[Test]
    public function getBoolParamRejectsInvalidValue(): void
    {
        $this->request = new FakeRequest(['b' => 'banana']);
        $this->useRequest($this->request);
        self::assertNull(XhprofLib::xhprof_get_bool_param('b'));
        self::assertStringContainsString('must be a valid boolean string', $this->logger->errors[0]);
    }

    #[Test]
    public function getBoolParamReturnsDefaultWhenMissing(): void
    {
        // 缺失参数直接返回默认值，不再对默认值 trim 崩溃
        self::assertTrue(XhprofLib::xhprof_get_bool_param('b', true));
    }

    #[Test]
    public function getStringParamReturnsDefaultWhenMissing(): void
    {
        self::assertSame('default-sym', XhprofLib::xhprof_get_string_param('sym', 'default-sym'));
    }

    #[Test]
    public function getStringParamReturnsValueWhenPresent(): void
    {
        $this->request = new FakeRequest(['sym' => 'foo()']);
        $this->useRequest($this->request);
        self::assertSame('foo()', XhprofLib::xhprof_get_string_param('sym'));
    }

    #[Test]
    public function getMatchingFunctionsFindsSubstringCaseInsensitive(): void
    {
        $data = [
            'main()==>foo()' => ['wt' => 40],
            'main()==>bar()' => ['wt' => 30],
            'FOO()==>strlen()' => ['wt' => 5],
        ];
        // asort 保留键名，故用 array_values 归一化后断言
        self::assertSame(
            ['FOO()', 'foo()'],
            array_values(XhprofLib::xhprof_get_matching_functions('fo', $data))
        );
        self::assertSame(['main()'], XhprofLib::xhprof_get_matching_functions('MAIN', $data));
    }

    #[Test]
    public function getMatchingFunctionsSkipsBareMainKey(): void
    {
        // 裸 main() 键（无父函数）不再崩溃；main() 作为 child 仍可匹配
        self::assertSame(
            ['main()'],
            XhprofLib::xhprof_get_matching_functions('main', ['main()' => ['wt' => 1]])
        );
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
