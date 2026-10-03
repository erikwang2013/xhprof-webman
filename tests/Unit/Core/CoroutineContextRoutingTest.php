<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Core;

require_once __DIR__ . '/../../Fixtures/Fakes.php';

use ErikWang2013\Xhprof\Core\I18n\I18n;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofLib\Display\XhprofDisplay;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeConfig;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeRequest;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeResponse;
use ErikWang2013\Xhprof\Tests\Support\XhprofStaticsSnapshot;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 协程上下文后端的三态路由与「每请求一份」的隔离，跑在**真 `\Fiber`** 上。
 *
 * 与两条既有协程用例的分工：
 *   - `RenderStateCoroutineTest` / `I18nTest` 里的让出用例：用反射整块换掉
 *     `\Hyperf\Context\Context::$data` 来**模拟**协程切换 —— 证的是 Hyperf 那条后端
 *     在「已经切进协程存储」之后的读写语义。
 *   - 本文件：不碰 Hyperf 的存储，用 PHP 原生的 `\Fiber` 起真协程，走
 *     `Workerman\Coroutine\Context`（`tests/Stubs/framework-stubs.php` 里的桩，保真度与
 *     四处刻意差异见那份桩的注释）这条后端 —— 证的是**路由本身**：
 *     `Xhprof::coroutineContextClass()` 在哪个调用位置返回哪个后端，以及同一个后端下
 *     两条真协程互不可见（适配器 / 9 个渲染状态 / 语言 / 整页渲染四条面）。
 *
 * 「协程内」与「协程外」在每条用例里都断言到：workerman 的实测形状是
 * `onWorkerStart` 在协程里、`onMessage` 不在（Select 事件循环），后端必须**每次现问**，
 * 置闩会得到反的答案（`Xhprof::coroutineContextClass()` 的注释里记的就是这一条）。
 *
 * 真 `\Fiber` 要 PHP 8.1，而 workerman/coroutine 自己的 composer 约束也是 >= 8.1：
 * 单测只在 8.2+ 跑（PHPUnit 11 的下限，见 ci.yml），所以这里不需要版本开关。
 */
class CoroutineContextRoutingTest extends TestCase
{
    use XhprofStaticsSnapshot;

    /** @var array<string, mixed> */
    private array $snapshot = [];

    /** @var array<string, array<string, mixed>> 词表缓存：htmlLang 回落那条用例会注入改过的 en 词表 */
    private array $savedCatalogs = [];

    private string $savedLocale = I18n::FALLBACK;

    protected function setUp(): void
    {
        $this->snapshot = $this->snapshotXhprofStatics();
        // 语言与词表都是进程级静态量，本 trait 不管它们：与 I18nTest 一条口径，自己存还原。
        $this->savedCatalogs = self::rawStatic(I18n::class, 'catalogs');
        $this->savedLocale = self::rawStatic(I18n::class, 'locale');
    }

    protected function tearDown(): void
    {
        self::writeStatic(I18n::class, 'catalogs', $this->savedCatalogs);
        self::writeStatic(I18n::class, 'locale', $this->savedLocale);
        $this->restoreXhprofStatics($this->snapshot);
    }

    /**
     * 在一条**真 Fiber** 里跑一段「跑完就结束」的代码，返回它 `return` 的值。
     *
     * `start()` 的返回值**不是**协程的返回值：闭包正常 return 时 fiber 终止，`start()`
     * 给的是 null（PHP 的语义是「start/resume 返回下一次 `suspend()` 传入的值」），
     * 要另取 `getReturn()`。这一条踩过一次，别改回 `return ->start()`。
     * 中途要让出的用例自己 `new \Fiber` + `start()/resume()`（本文件里那两条 A）。
     */
    private function inFiber(callable $fn): mixed
    {
        $fiber = new \Fiber($fn);
        $fiber->start();

        return $fiber->getReturn();
    }

    /** 读写私有静态量（本文件只打语言那两处：`I18n::$catalogs` / `I18n::$locale`）。 */
    private static function rawStatic(string $class, string $property): mixed
    {
        return (new \ReflectionProperty($class, $property))->getValue();
    }

    private static function writeStatic(string $class, string $property, mixed $value): void
    {
        (new \ReflectionProperty($class, $property))->setValue(null, $value);
    }

    /** @param array<string, object> $expected 五个适配器：request/response/config/cache/logger */
    private function assertAdaptersAre(array $expected, array $actual, string $where): void
    {
        foreach (['request', 'response', 'config', 'cache', 'logger'] as $i => $name) {
            self::assertSame($expected[$name], $actual[$i], "{$where}的 {$name} 适配器不是自己的");
        }
    }

    // ---------------- 1. 三态路由 ----------------

    #[Test]
    public function theBackendFollowsTheCallSiteAndIsAskedAgainOnEveryCall(): void
    {
        // 桩要是没了，下面「协程里 = workerman」那条会静默退化成「也是 null」，所以先钉住夹具
        self::assertTrue(
            class_exists(\Workerman\Coroutine::class),
            'tests/Stubs/framework-stubs.php 里的 workerman/coroutine 桩没装：本文件的协程档位测的是空气'
        );

        self::assertNull(
            Xhprof::coroutineContextClass(),
            '不在协程里必须留在静态属性上（= 重构前的行为；Select 事件循环下非协程请求走的就是这条）'
        );

        self::assertSame(
            \Workerman\Coroutine\Context::class,
            $this->inFiber(static fn (): ?string => Xhprof::coroutineContextClass()),
            '协程里必须切到 workerman 那条按协程分桶的 Context'
        );

        self::assertNull(
            Xhprof::coroutineContextClass(),
            'Fiber 结束后必须回到静态属性：后端是每次现问的，不是进程闩（onWorkerStart 在协程里、onMessage 不在）'
        );
    }

    #[Test]
    public function theHyperfLatchWinsInsideAndOutsideAWorkermanFiber(): void
    {
        Xhprof::markHyperfContext();   // 生产里由 Hyperf 中间件在 bootstrap() 之前置位

        self::assertSame(\Hyperf\Context\Context::class, Xhprof::coroutineContextClass(), '闩置位后协程外也必须读 Hyperf 的 Context');
        self::assertSame(
            \Hyperf\Context\Context::class,
            $this->inFiber(static fn (): ?string => Xhprof::coroutineContextClass()),
            '闩一旦置位就全程读 Hyperf 的 Context：workerman 那条判定不该把它顶掉'
        );
    }

    // ---------------- 2. 五个适配器：按 Fiber 一份 ----------------

    #[Test]
    public function adaptersArePerFiberAndTheStaticFallbackStillWorks(): void
    {
        $a = [
            'request' => new FakeRequest(['run' => 'a'], ['uri' => '/a']),
            'response' => new FakeResponse(),
            'config' => new FakeConfig(['xhprof' => ['auth_token' => 'A']]),
            'cache' => new FakeCache(),
            'logger' => new FakeLogger(),
        ];
        $b = [
            'request' => new FakeRequest(['run' => 'b'], ['uri' => '/b']),
            'response' => new FakeResponse(),
            'config' => new FakeConfig(['xhprof' => ['auth_token' => 'B']]),
            'cache' => new FakeCache(),
            'logger' => new FakeLogger(),
        ];

        $seen = [];
        $fiberA = new \Fiber(function () use ($a, &$seen): void {
            Xhprof::bootstrap($a['request'], $a['response'], $a['config'], $a['cache'], $a['logger']);
            \Fiber::suspend();   // 一次真让出
            $seen['a'] = [Xhprof::getRequest(), Xhprof::getResponse(), Xhprof::getConfig(), Xhprof::getCache(), Xhprof::getLogger()];
        });
        $fiberA->start();

        $seen['b'] = $this->inFiber(static function () use ($b): array {
            Xhprof::bootstrap($b['request'], $b['response'], $b['config'], $b['cache'], $b['logger']);

            return [Xhprof::getRequest(), Xhprof::getResponse(), Xhprof::getConfig(), Xhprof::getCache(), Xhprof::getLogger()];
        });

        $fiberA->resume();

        $this->assertAdaptersAre($b, $seen['b'], '协程 B');
        $this->assertAdaptersAre($a, $seen['a'], '让出后的协程 A');

        // 协程外（= Select 循环下 onMessage 那种「不在协程里」的调用点）仍走静态属性：
        // 最近一次 bootstrap() 写进去的那份，逐字等于重构前的行为。
        $this->assertAdaptersAre($b, [Xhprof::getRequest(), Xhprof::getResponse(), Xhprof::getConfig(), Xhprof::getCache(), Xhprof::getLogger()], '协程外的静态回落');
    }

    // ---------------- 3. 九个渲染状态量 ----------------

    #[Test]
    public function renderStateIsPerFiberAndAFreshFiberSeesTheDefaults(): void
    {
        $mine = [
            'stats' => ['fn', 'ct'],
            'pc_stats' => ['fn', 'ct'],
            'totals' => ['ct' => 7],
            'totals_1' => ['ct' => 8],
            'totals_2' => ['ct' => 9],
            'sort_col' => 'mu',
            'metrics' => ['mu'],
            'diff_mode' => true,
            'display_calls' => false,
        ];
        $after = [];
        $fiberA = new \Fiber(function () use ($mine, &$after): void {
            XhprofDisplay::set_render_state($mine);
            \Fiber::suspend();
            $after = [XhprofDisplay::stats(), XhprofDisplay::pc_stats(), XhprofDisplay::totals(), XhprofDisplay::totals_1(), XhprofDisplay::totals_2(), XhprofDisplay::sort_col(), XhprofDisplay::metrics(), XhprofDisplay::diff_mode(), XhprofDisplay::display_calls()];
            XhprofDisplay::set_render_state(['sort_col' => 'wt']);   // 只更新传入的键
            $after[] = XhprofDisplay::sort_col();
            $after[] = XhprofDisplay::totals();
        });
        $fiberA->start();

        self::assertSame(
            [[], [], 0, 0, 0, 'wt', null, false, true],
            $this->inFiber(static fn (): array => [XhprofDisplay::stats(), XhprofDisplay::pc_stats(), XhprofDisplay::totals(), XhprofDisplay::totals_1(), XhprofDisplay::totals_2(), XhprofDisplay::sort_col(), XhprofDisplay::metrics(), XhprofDisplay::diff_mode(), XhprofDisplay::display_calls()]),
            '新协程看到的是类里的默认值：既没继承别的协程的，也不是「没写进协程存储」'
        );

        $fiberA->resume();

        self::assertSame(
            [
                $mine['stats'], $mine['pc_stats'], $mine['totals'], $mine['totals_1'], $mine['totals_2'],
                $mine['sort_col'], $mine['metrics'], $mine['diff_mode'], $mine['display_calls'],
                'wt', ['ct' => 7],
            ],
            $after,
            '协程 A 让出后读到的渲染状态被协程 B 覆盖了（或第二次写入把前一次的键抹掉了）'
        );
    }

    // ---------------- 4. 语言与 <html lang> ----------------

    #[Test]
    public function localeAndHtmlLangFollowTheFiber(): void
    {
        $after = [];
        $fiberA = new \Fiber(function () use (&$after): void {
            I18n::setLocale('zh-CN');   // 归一化到 zh_CN
            \Fiber::suspend();
            $after = [I18n::locale(), I18n::htmlLang()];
        });
        $fiberA->start();

        $b = $this->inFiber(static function (): array {
            I18n::setLocale('en');

            return [I18n::locale(), I18n::htmlLang()];
        });
        self::assertSame(['en', 'en'], $b, '协程 B 的语言不是自己的');

        $fiberA->resume();

        self::assertSame(
            ['zh_CN', 'zh-CN'],
            $after,
            '协程 A 让出后语言 / <html lang> 被协程 B 覆盖了：同一页会印出两种语言的正文'
        );
    }

    /**
     * `I18n::htmlLang()` 的**兜底分支**（词表没有 `_meta.lang` 时）也必须由上下文定，
     * 不能直读 `self::$locale` —— 后端生效时那个静态量不承载真值。
     *
     * 今天 13 份词表都写了 `_meta.lang`，兜底走不到，正是那种「今天不可达、重构后语义面
     * 变宽」的站点；所以夹具里把 en 的 `_meta.lang` 摘掉，让这一支可达。
     */
    #[Test]
    public function htmlLangsFallbackBranchIsAlsoDecidedByTheContext(): void
    {
        $catalogs = $this->savedCatalogs;
        $en = I18n::catalogOf('en');            // 先确保 en 已载入（载入即缓存）
        unset($en['_meta']['lang']);
        $catalogs['en'] = $en;
        self::writeStatic(I18n::class, 'catalogs', $catalogs);

        $after = [];
        $fiberA = new \Fiber(function () use (&$after): void {
            I18n::setLocale('en');
            \Fiber::suspend();
            $after = [I18n::locale(), I18n::htmlLang()];
        });
        $fiberA->start();

        $this->inFiber(static function (): void {
            I18n::setLocale('zh_CN');
        });
        $fiberA->resume();

        self::assertSame(
            ['en', 'en'],
            $after,
            '兜底分支读的是进程静态 $locale（= 别的请求的语言），不是本协程的：这正是 htmlLang() 收编掉的那个绕过站点'
        );
    }

    // ---------------- 5. 端到端：真让出点上的整页渲染 ----------------

    /**
     * 让出点钉在渲染中途那次**真 I/O** 上（`XhprofLib::getRequestLog()` 的缓存读，真 Hyperf
     * 里就是一次会让出协程的 Redis GET）：隔壁请求把整个报告页也渲染一遍，A 的页面、列头、
     * 行序与让出后的渲染状态仍全是 A 的。
     *
     * 与 `RenderStateCoroutineTest` 同构，但后端是 workerman 那条（真 Fiber，不动 Hyperf 的
     * 存储）—— 也就顺带把「让出后被覆盖」的**正文语言**这一面钉住：列头是 `I18n::t()`
     * 在让出之后才取的，A 是 en、B 是 zh_CN，两边列头因此逐字不同。
     */
    #[Test]
    public function aFullPageRenderedInAFiberSurvivesAnotherRequestsRender(): void
    {
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

        $yielded = 0;
        $htmlA = '';
        $stateA = [];
        $fiberA = new \Fiber(function () use ($cache, $runA, &$yielded, &$htmlA, &$stateA): void {
            $cache->onGet = function () use (&$yielded): void {
                $yielded++;
                \Fiber::suspend();   // 真让出：控制权回到测试主栈
            };
            $this->bootstrapFor($runA, 'en', null, $cache);
            $htmlA = (string) Xhprof::index();
            $stateA = [XhprofDisplay::stats(), XhprofDisplay::totals(), XhprofDisplay::sort_col()];
        });
        $fiberA->start();

        $this->assertSame(1, $yielded, '夹具没在渲染中途让出协程（请求日志没被读），这条用例形同虚设');

        // 隔壁请求：整页渲染，语言 / 排序列 / 指标都与 A 不同
        $htmlB = $this->inFiber(function () use ($runB, $cache): string {
            $this->bootstrapFor($runB, 'zh-CN', 'fn', $cache);

            return (string) Xhprof::index();
        });

        $fiberA->resume();

        // B 的页面：自己多出来的 pmu 列 + 按函数名升序的行序（A 若漏进 B，这两条都变）
        $colsB = $this->flatTableColumns($htmlB);
        self::assertGreaterThan(
            count($this->flatTableColumns($htmlA)),
            count($colsB),
            '协程 B 的页面没有多出自己采集到的 pmu 列：B 渲染时读到的 $stats 不是自己的'
        );
        self::assertSame(['main()', 'other()'], $this->flatTableFunctions($htmlB), '协程 B 的行序不是自己的 $sort_col');

        // A 的页面：列头逐字是英文 11 列（$stats 没被覆盖 + 让出后的正文语言仍是 en），
        // 行序是 wt 降序，且页面上不出现 B 的 run
        self::assertSame(
            ['Function/Method', 'Calls', 'Calls %', 'Incl. Wall (microsec)', 'Incl. Wall %',
             'Excl. Wall (microsec)', 'Excl. Wall %', 'Incl. Memory Use (bytes)', 'Incl. Memory Use %',
             'Excl. Memory Use (bytes)', 'Excl. Memory Use %'],
            $this->flatTableColumns($htmlA),
            '协程 A 的列头被协程 B 的 $stats / 语言覆盖了'
        );
        self::assertSame(['main()', 'zzz()', 'aaa()'], $this->flatTableFunctions($htmlA), '协程 A 的行序被协程 B 的 $sort_col 覆盖了');
        self::assertStringStartsWith('<html lang="en"', $htmlA, '协程 A 的页首语言不是自己的');

        // A 让出之后读到的三个量仍逐项是自己的
        self::assertSame(
            [
                ['fn', 'ct', 'Calls%', 'wt', 'IWall%', 'excl_wt', 'EWall%', 'mu', 'IMUse%', 'excl_mu', 'EMUse%'],
                ['ct' => 3, 'wt' => 100000, 'ut' => 0, 'st' => 0, 'cpu' => 0, 'mu' => 2048, 'pmu' => 0, 'samples' => 0],
                'wt',
            ],
            $stateA,
            '协程 A 让出后读到的 $stats/$totals/$sort_col 不是自己的'
        );
    }

    // ---------------- 端到端用例的夹具 ----------------

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
     * run A：排序列是默认的 `wt`（耗时降序），指标只有 wt/mu。
     * run B：`?sort=fn`（按函数名升序），指标多一个 pmu —— 两边逐项不同，串扰一眼可辨。
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
     * 与 `RenderStateCoroutineTest` 里那两个抽取器同形（那边断言列头/行序用的就是这两条口径）。
     *
     * @return list<string>
     */
    private function flatTableColumns(string $html): array
    {
        $this->assertSame(
            1,
            preg_match(
                '#<div class="xp-table-wrap"[^>]*><table class="xp-table"><thead><tr>(.*?)</tr></thead>#s',
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
}
