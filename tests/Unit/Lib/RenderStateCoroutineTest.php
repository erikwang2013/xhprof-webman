<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Lib;

require_once __DIR__ . '/../../Fixtures/Fakes.php';

use ErikWang2013\Xhprof\Core\XhprofLib\Display\XhprofDisplay;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeConfig;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeRequest;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 报告页的**渲染静态量**在 Hyperf 常驻 worker 里的协程隔离。
 *
 * 症状与 I18nTest::aYieldInTheMiddleOfThePageCannotChangeThePageLanguage 同源
 * （同一类「页首/前半页用一套数据、I/O 之后的下半页用另一套」），只是换成了
 * `$stats`/`$pc_stats`/`$totals`/`$sort_col` 这批**表格数据**。
 *
 * 让出点不是造出来的：单 run 报告的渲染链里，`full_report()` 在**算完 $stats 之后**
 * 还要读一次请求日志（`XhprofLib::getRequestLog()` → `Xhprof::getCache()->get()`，
 * 真 Hyperf 里就是一次会让出协程的 Redis GET），随后的 `usort(sort_cbk)`、
 * `print_flat_data()`、`print_function_info()` 才去读那些静态量。
 */
class RenderStateCoroutineTest extends TestCase
{
    /** @var array<int, array<string, mixed>> 「协程」编号 => 它自己的 Context 存储 */
    private array $coroutineStores = [];

    private int $currentCoroutine = 0;

    private bool $savedHyperfFlag = false;

    /** @var array<string, mixed> */
    private array $savedContextStore = [];

    /** @var array<string, mixed> 本文件会改到的渲染静态量，结束时还原 */
    private array $savedRenderStatics = [];

    private const RENDER_STATICS = [
        'stats', 'pc_stats', 'totals', 'totals_1', 'totals_2',
        'sort_col', 'metrics', 'diff_mode', 'display_calls',
    ];

    protected function setUp(): void
    {
        // Hyperf 标志一旦置位就不可逆：本文件要把进程切进去，结束必须原样还回去，
        // 否则后面每个测试类的 Xhprof::getRequest() 都会改读协程 Context（那里是空的）。
        $this->savedHyperfFlag = Xhprof::isHyperfContext();
        $this->savedContextStore = self::rawStatic(\Hyperf\Context\Context::class, 'data');
        $this->coroutineStores = [$this->currentCoroutine => $this->savedContextStore];
        foreach (self::RENDER_STATICS as $name) {
            $this->savedRenderStatics[$name] = self::rawStatic(XhprofDisplay::class, $name);
        }
    }

    protected function tearDown(): void
    {
        self::writeStatic(\Hyperf\Context\Context::class, 'data', $this->savedContextStore);
        self::writeStatic(Xhprof::class, '_hyperf', $this->savedHyperfFlag);
        foreach ($this->savedRenderStatics as $name => $value) {
            self::writeStatic(XhprofDisplay::class, $name, $value);
        }
        Xhprof::$request = null;
        Xhprof::$response = null;
        Xhprof::$config = null;
        Xhprof::$cache = null;
        Xhprof::$logger = null;
    }

    private static function rawStatic(string $class, string $property): mixed
    {
        return (new \ReflectionProperty($class, $property))->getValue();
    }

    private static function writeStatic(string $class, string $property, mixed $value): void
    {
        (new \ReflectionProperty($class, $property))->setValue(null, $value);
    }

    /** 模拟一次协程切换：换掉 `\Hyperf\Context\Context` 的整块存储（每个协程一份）。 */
    private function switchCoroutine(int $id): void
    {
        $this->coroutineStores[$this->currentCoroutine] = self::rawStatic(\Hyperf\Context\Context::class, 'data');
        $this->currentCoroutine = $id;
        self::writeStatic(\Hyperf\Context\Context::class, 'data', $this->coroutineStores[$id] ?? []);
    }

    private function bootstrapFor(string $runId, string $lang, ?string $sort, FakeCache $cache): void
    {
        $params = ['run' => $runId, 'source' => 'xhprof_foo', 'all' => '1', 'lang' => $lang];
        if ($sort !== null) {
            $params['sort'] = $sort;
        }

        Xhprof::$time_limit = 0;
        Xhprof::$ignore_url_arr = ['/xhprof'];
        Xhprof::$key_prefix = 'xhprof';
        Xhprof::$view_wtred = 3;
        Xhprof::$ui_html = '';
        Xhprof::bootstrap(
            new FakeRequest($params, ['uri' => '/xhprof']),
            new FakeResponse(),
            new FakeConfig(['xhprof' => []]),
            $cache,
            new FakeLogger()
        );
    }

    /**
     * 协程 1 的 run：排序列是默认的 `wt`（耗时降序），指标只有 wt/mu。
     * 协程 2 的 run：`?sort=fn`（按函数名升序），指标多一个 pmu。
     * 两边的 $stats/$pc_stats/$totals/$sort_col 因此**逐项不同**，串扰一眼可辨。
     *
     * @return array<string, array<string, mixed>>
     */
    private function runA(): array
    {
        return [
            'main()' => ['ct' => 1, 'wt' => 100000, 'mu' => 2048],
            'main()==>zzz()' => ['ct' => 1, 'wt' => 90000, 'mu' => 512],
            'main()==>aaa()' => ['ct' => 1, 'wt' => 10000, 'mu' => 256],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function runB(): array
    {
        return [
            'main()' => ['ct' => 1, 'wt' => 500000, 'mu' => 9999, 'pmu' => 8888],
            'main()==>other()' => ['ct' => 3, 'wt' => 400000, 'mu' => 777, 'pmu' => 666],
        ];
    }

    /**
     * 扁平淡表的列标签（`<br>` 折成空格、实体解码），按出现顺序。
     *
     * @return list<string>
     */
    private function flatTableColumns(string $html): array
    {
        $this->assertSame(
            1,
            preg_match(
                '#<div class="xp-table-wrap"><table class="xp-table"><thead><tr>(.*?)</tr></thead>#s',
                $html,
                $m
            ),
            '页面里找不到扁平淡表（夹具没走到 full_report/print_flat_data）'
        );
        preg_match_all('#<th[^>]*>(.*?)</th>#s', $m[1], $cells);

        return array_map([$this, 'labelOf'], $cells[1]);
    }

    /** @return list<string> 扁平淡表里各行第一个单元格的函数名，按渲染顺序 */
    private function flatTableFunctions(string $html): array
    {
        $this->assertSame(1, preg_match('#<tbody>(.*)</tbody>#s', $html, $m), '页面里找不到扁平淡表的 tbody');
        preg_match_all('#<td><a href="[^"]*">([^<]+)</a>#', $m[1], $rows);

        return array_map([$this, 'labelOf'], $rows[1]);
    }

    private function labelOf(string $fragment): string
    {
        $text = (string) preg_replace('#<br\s*/?>#i', ' ', $fragment);

        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /**
     * 让出协程的那一刻，隔壁协程把**整个报告页**也渲染了一遍。
     *
     * 让出点钉在渲染中途那次真 I/O 上（`request_log` 缓存读，即
     * `XhprofLib::getRequestLog()`），而不是随手的 `get_run()`：只有它才在
     * `init_metrics()` 写完、`print_flat_data()` 读之前，是这条串扰存在的**唯一**理由。
     */
    #[Test]
    public function aYieldInTheMiddleOfTheReportCannotSwapAnotherCoroutinesRenderState(): void
    {
        Xhprof::markHyperfContext();   // 生产里由 Hyperf 中间件在 bootstrap() 之前置位
        $this->switchCoroutine(1);

        $yielded = 0;
        $cache = new class extends FakeCache {
            /** @var null|\Closure(): void */
            public $onGet = null;

            public function get(string $key): mixed
            {
                $cb = $this->onGet;
                if ($cb !== null && str_contains($key, ':request_log:')) {
                    $this->onGet = null;   // 只让出一次，别把每条 get() 都变成切换点
                    $cb();
                }
                return parent::get($key);
            }
        };

        $runA = 'a1a1a1a1a1a1a1a1';
        $runB = 'b2b2b2b2b2b2b2b2';
        $cache->set('xhprof:xhprof_log:' . $runA, serialize($this->runA()));
        $cache->set('xhprof:xhprof_log:' . $runB, serialize($this->runB()));
        $cache->set('xhprof:request_log:' . $runA, json_encode(['request_uri' => '/a', 'method' => 'GET']));
        $cache->set('xhprof:request_log:' . $runB, json_encode(['request_uri' => '/b', 'method' => 'GET']));

        $cache->onGet = function () use (&$yielded, $cache, $runB): void {
            $yielded++;
            // 隔壁协程：另一个请求，完整地把报告页渲染一遍
            $this->switchCoroutine(2);
            $this->bootstrapFor($runB, 'en', 'fn', $cache);
            $this->assertIsString(Xhprof::index());
            $this->switchCoroutine(1);   // 让出结束，本协程接着渲染
        };

        $this->bootstrapFor($runA, 'en', null, $cache);
        $html = Xhprof::index();

        $this->assertIsString($html);
        $this->assertSame(1, $yielded, '夹具没在渲染中途让出协程（请求日志没被读），这条用例形同虚设');

        // 让出之后本协程的四个量仍是自己的。$pc_stats 在单 run 视图里不上屏（只有符号
        // 详情页拿它当 colspan），页面级断言够不着它，所以四个量逐项直接读一遍；
        // $stats/$totals/$sort_col 另有下面那几条页面级断言。
        $this->assertSame(
            ['fn', 'ct', 'Calls%', 'wt', 'IWall%', 'excl_wt', 'EWall%', 'mu', 'IMUse%', 'excl_mu', 'EMUse%'],
            XhprofDisplay::stats(),
            '协程 1 的 $stats 被协程 2 覆盖了'
        );
        $this->assertSame(
            ['fn', 'ct', 'Calls%', 'wt', 'IWall%', 'mu', 'IMUse%'],
            XhprofDisplay::pc_stats(),
            '协程 1 的 $pc_stats 被协程 2 覆盖了'
        );
        $this->assertSame(
            ['ct' => 3, 'wt' => 100000, 'ut' => 0, 'st' => 0, 'cpu' => 0, 'mu' => 2048, 'pmu' => 0, 'samples' => 0],
            XhprofDisplay::totals(),
            '协程 1 的 $totals 被协程 2 覆盖了'
        );
        $this->assertSame('wt', XhprofDisplay::sort_col(), '协程 1 的 $sort_col 被协程 2 覆盖了');

        // 列头：只能有 run A 的 11 列，不许出现 run B 多出来的 pmu 三列
        $this->assertSame(
            ['Function/Method', 'Calls', 'Calls %', 'Incl. Wall (microsec)', 'Incl. Wall %',
             'Excl. Wall (microsec)', 'Excl. Wall %', 'Incl. Memory Use (bytes)', 'Incl. Memory Use %',
             'Excl. Memory Use (bytes)', 'Excl. Memory Use %'],
            $this->flatTableColumns($html),
            '协程 1 的列头被协程 2 的 $stats 覆盖了'
        );

        // 行序：run A 按 wt 降序（main 100000 → zzz 90000 → aaa 10000），
        // 被 $sort_col='fn' 覆盖后会变成按函数名升序
        $this->assertSame(
            ['main()', 'zzz()', 'aaa()'],
            $this->flatTableFunctions($html),
            '协程 1 的 $sort_col 被协程 2 覆盖了'
        );
    }

    /**
     * 没写过渲染状态的协程看到的是**类里的默认值**，不是「上一个用这个进程的协程」的值。
     *
     * 单值用例（上面那条）证的是「A 的数据不会被 B 盖掉」，这条证另一半：新协程不继承
     * 任何东西——退回进程级静态属性时它必然读到 A 的值，于是两个协程会互相「借」数据。
     */
    #[Test]
    public function aFreshCoroutineSeesTheDefaultsNotTheOtherCoroutinesState(): void
    {
        Xhprof::markHyperfContext();
        $this->switchCoroutine(1);
        XhprofDisplay::set_render_state([
            'stats' => ['fn', 'ct'],
            'pc_stats' => ['fn', 'ct'],
            'totals' => ['ct' => 7],
            'totals_1' => ['ct' => 8],
            'totals_2' => ['ct' => 9],
            'sort_col' => 'mu',
            'metrics' => ['mu'],
            'diff_mode' => true,
            'display_calls' => false,
        ]);

        $this->switchCoroutine(2);
        $this->assertSame([], XhprofDisplay::stats(), '新协程继承到了别的协程的 $stats');
        $this->assertSame([], XhprofDisplay::pc_stats(), '新协程继承到了别的协程的 $pc_stats');
        $this->assertSame(0, XhprofDisplay::totals(), '新协程继承到了别的协程的 $totals');
        $this->assertSame(0, XhprofDisplay::totals_1());
        $this->assertSame(0, XhprofDisplay::totals_2());
        $this->assertSame('wt', XhprofDisplay::sort_col(), '新协程继承到了别的协程的 $sort_col');
        $this->assertNull(XhprofDisplay::metrics());
        $this->assertFalse(XhprofDisplay::diff_mode());
        $this->assertTrue(XhprofDisplay::display_calls());

        // 切回去仍是自己的，且**只更新传入的键**（第二次调用不动前九个里的其他键）
        $this->switchCoroutine(1);
        XhprofDisplay::set_render_state(['sort_col' => 'wt']);
        $this->assertSame(['fn', 'ct'], XhprofDisplay::stats());
        $this->assertSame(['ct' => 7], XhprofDisplay::totals());
        $this->assertSame('wt', XhprofDisplay::sort_col());
    }

    /**
     * 拼错的键当场炸，不静默写进一个没人读的位置——Hyperf 分支写的是 Context 里的一张
     * map，「写进去没人读」正是这次要消灭的那类 bug，不能让 set_render_state() 自己再造一个。
     */
    #[Test]
    public function anUnknownRenderStateKeyIsRejected(): void
    {
        Xhprof::markHyperfContext();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("unknown render state key 'statz'");
        XhprofDisplay::set_render_state(['statz' => ['fn']]);
    }
}
