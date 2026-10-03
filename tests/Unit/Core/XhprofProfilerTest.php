<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Core;

require_once __DIR__ . '/../../Fixtures/Fakes.php';

use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeConfig;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeRequest;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeResponse;
use ErikWang2013\Xhprof\Tests\Support\XhprofStaticsSnapshot;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FakeCache::lPush 存在 by-ref 传参 bug（array_unshift($this->lists[$key] ??= [], ...)），
 * 测试进程内无法修改 Fixtures，此处用修复版子类覆盖列表方法。
 */
class ProfilerFixedListCache extends FakeCache
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
        return array_slice($this->myLists[$key] ?? [], $start, $end - $start + 1);
    }
}

class XhprofProfilerTest extends TestCase
{
    use XhprofStaticsSnapshot;

    /**
     * 本类是唯一改了 `Xhprof::$*` 却不还原的类（setUp 里设了 time_limit/ignore_url_arr/
     * key_prefix 等），而这些都是**进程级**静态量：`--order-by=random` 下它跑在
     * `WiringTest` 前面时，后者的 12 条接线用例会读到 `key_prefix='myxp'`、`time_limit=5`
     * 而变红（实测 seed 1/8/10/11/12/14）。快照 + 还原是与其它测试类同一条口径。
     *
     * @var array<string, mixed>
     */
    private array $savedStatics = [];

    /** setUp 时 XhprofProfiler::$running 的原值，tearDown 还原 */
    private bool $savedProfilerRunning = false;

    /** setUp 时 XhprofProfiler::$config 的原值，tearDown 还原（新增的 sample_rate 测试会改它） */
    private ?array $savedProfilerConfig = null;

    private FakeCache $cache;
    private FakeConfig $config;
    private FakeRequest $request;
    private FakeResponse $response;
    private FakeLogger $logger;

    protected function setUp(): void
    {
        // 必须在自己改静态量**之前**快照
        $this->savedStatics = $this->snapshotXhprofStatics();
        $this->savedProfilerConfig = self::profilerConfig();

        $this->cache = new FakeCache();
        $this->request = new FakeRequest();
        $this->response = new FakeResponse();
        $this->logger = new FakeLogger();
        $this->config = new FakeConfig(['xhprof' => []]);

        Xhprof::$time_limit = 0;
        Xhprof::$ignore_url_arr = ['/test'];
        Xhprof::$key_prefix = 'xhprof';
        Xhprof::$log_num = 1000;
        Xhprof::$view_wtred = 3;
        Xhprof::$ui_html = '';
        Xhprof::$symbol_lookup_url = '';

        Context::reset();
        ApplicationContext::reset();

        Xhprof::bootstrap(
            $this->request,
            $this->response,
            $this->config,
            $this->cache,
            $this->logger
        );

        // 幂等标记是 XhprofProfiler 的进程级静态量（快照 trait 只管 Xhprof::$*），
        // 本类自己负责让它不跨用例泄漏：进来先存、置 false，出去还原。
        $this->savedProfilerRunning = self::profilerRunning();
        self::setProfilerRunning(false);
    }

    protected function tearDown(): void
    {
        self::setProfilerRunning($this->savedProfilerRunning);
        self::setProfilerConfig($this->savedProfilerConfig);
        Context::reset();
        ApplicationContext::reset();
        $this->restoreXhprofStatics($this->savedStatics);
        Xhprof::$request = null;
        Xhprof::$response = null;
        Xhprof::$config = null;
        Xhprof::$cache = null;
        Xhprof::$logger = null;
    }

    /** 读 XhprofProfiler 缓存的整块配置（private static，只能反射）。 */
    private static function profilerConfig(): ?array
    {
        $value = (new \ReflectionProperty(XhprofProfiler::class, 'config'))->getValue();

        return is_array($value) ? $value : null;
    }

    private static function setProfilerConfig(?array $config): void
    {
        (new \ReflectionProperty(XhprofProfiler::class, 'config'))->setValue(null, $config);
    }

    /**
     * 用给定 xhprof 配置重新 bootstrap：Xhprof::bootstrap() 末尾会调
     * XhprofProfiler::bootstrap()，把整块配置钉进 XhprofProfiler 的静态缓存，
     * start() 读 sample_rate 就走这条路径。
     */
    private function bootstrapWith(array $xhprofConfig, ?FakeCache $cache = null): void
    {
        $config = new FakeConfig(['xhprof' => $xhprofConfig]);
        Xhprof::bootstrap($this->request, $this->response, $config, $cache ?? $this->cache, $this->logger);
    }

    /** 读 XhprofProfiler 的幂等标记（private static，只能反射）。 */
    private static function profilerRunning(): bool
    {
        return (bool) (new \ReflectionProperty(XhprofProfiler::class, 'running'))->getValue();
    }

    /** 写幂等标记。static 属性传 null 作 object（PHP 8.1 起无需 setAccessible）。 */
    private static function setProfilerRunning(bool $running): void
    {
        (new \ReflectionProperty(XhprofProfiler::class, 'running'))->setValue(null, $running);
    }

    #[Test]
    public function startStopRoundTripSavesRunToCache(): void
    {
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('ext-xhprof 未加载');
        }

        // FakeCache::lPush 有 by-ref bug，此处用修复版子类重新 bootstrap
        $cache = new ProfilerFixedListCache();
        Xhprof::bootstrap($this->request, $this->response, $this->config, $cache, $this->logger);

        XhprofProfiler::start();
        $runId = XhprofProfiler::stop();

        $this->assertNull($runId); // stop() 无返回值，run_id 由 save_run 内部写入缓存
        $this->assertContains('lPush:xhprof:run_id', $cache->calls, '应把 run_id 推入列表');
        $this->assertNotEmpty(
            array_filter($cache->calls, fn (string $c) => str_starts_with($c, 'set:xhprof:request_log:')),
            '应写入 request_log'
        );
        $this->assertNotEmpty(
            array_filter($cache->calls, fn (string $c) => str_starts_with($c, 'set:xhprof:xhprof_log:')),
            '应写入 xhprof_log'
        );
    }

    /**
     * 没有 start() 就 stop()（或 stop 了两次）：一个字节都不该写。
     *
     * 旧实现无条件 `xhprof_disable()`——未采样时它返回 null，而写路径照样
     * 把一条「0 耗时 / 0 内存」的空 run 写进列表。危害不只是脏数据：
     * `log_num` 上限是**硬裁剪**（rPop 掉最老的），多出来的空 run 会把真实记录挤掉。
     * 入口类里只有 5 家自持 `$stopped` 守卫，其余靠"记得别多调"的约定——幂等下沉到 Core。
     */
    #[Test]
    public function stopWithoutStartWritesNothing(): void
    {
        self::assertFalse(self::profilerRunning(), '前提：本用例从「没 start 过」开始');

        XhprofProfiler::stop();   // 不 start 直接停

        $this->assertSame([], $this->cache->calls, '未采样就 stop() 不该碰缓存');
        $this->assertSame([], $this->logger->errors, '未采样就 stop() 不该记错误日志（它是正常时序，不是故障）');
    }

    // ---------- sample_rate：按比例采样（0,1]，默认 1.0 = 全采） ----------

    /** rate=1.0（默认值）：每次都开采样、照常落库，与加本键之前的唯一行为逐字一致。 */
    #[Test]
    public function sampleRateOneAlwaysSamples(): void
    {
        $cache = new ProfilerFixedListCache();
        $this->bootstrapWith(['enable' => true, 'sample_rate' => 1.0], $cache);

        XhprofProfiler::start();
        $this->assertTrue(self::profilerRunning(), 'rate=1.0 必须无条件 enable（快路径不打随机）');
        XhprofProfiler::stop();

        $this->assertContains('lPush:xhprof:run_id', $cache->calls, 'rate=1.0 与旧行为一致：照常落库');
    }

    /**
     * rate<=0：确定性跳过，一次都不开采样；随后配对的 stop() 因幂等守卫不落库（端到端）。
     * 循环三次是为了让「从不」不只是「这次没有」——这条路径不打随机，失败是必然的。
     */
    #[Test]
    public function sampleRateZeroNeverSamplesAndStopWritesNothing(): void
    {
        $cache = new ProfilerFixedListCache();
        $this->bootstrapWith(['enable' => true, 'sample_rate' => 0.0], $cache);

        for ($i = 1; $i <= 3; $i++) {
            XhprofProfiler::start();
            $this->assertFalse(self::profilerRunning(), "第 {$i} 次 start() 也不该开采样（rate=0 是确定性跳过）");
            XhprofProfiler::stop();
        }

        $this->assertSame([], $cache->calls, 'rate=0：三对 start/stop 一个字节都不该写');
        $this->assertSame([], $this->logger->errors, '跳过是正常时序，不是故障');
    }

    /** 负数 clamp 到 0（=不采）：显式写负数与写 0 同义，不取绝对值、不倒退成默认 1.0。 */
    #[Test]
    public function negativeSampleRateIsClampedToZero(): void
    {
        $this->bootstrapWith(['enable' => true, 'sample_rate' => -0.5]);

        XhprofProfiler::start();

        $this->assertFalse(self::profilerRunning());
    }

    /** 数字字符串（环境变量注入常见形态）按数值解析；>1 的数值 clamp 到 1（全采）。 */
    #[Test]
    public function numericStringsAreParsedAndRatesAboveOneClampToFullSampling(): void
    {
        $this->bootstrapWith(['enable' => true, 'sample_rate' => '1']);

        XhprofProfiler::start();
        $this->assertTrue(self::profilerRunning(), "字符串 '1' 应按数值 1.0 处理");

        self::setProfilerRunning(false);
        $this->bootstrapWith(['enable' => true, 'sample_rate' => '2.5']);

        XhprofProfiler::start();
        $this->assertTrue(self::profilerRunning(), '>1 当 1 处理：既不能超采也不该抛错');
    }

    /**
     * 非数值（写错的字符串）退化为默认 1.0。
     *
     * 口径理由：错成全采只是回到旧行为（ignore_url_arr 仍是兜底），错成全不采会让
     * 报告页静默空白、看起来像插件坏了——两个方向的代价不对称，往「采」的方向退。
     *
     * 判别性例子必须用强转成 0.0 的垃圾值：裸 (float) 强转的实现会把 'disabled'
     * 变成 0.0 = 全不采（正是要避免的失败模式）。'5%' 这类前导数字的串强转成 5.0，
     * 恰好也落到全采，区分不出两种实现，故不作断言。
     */
    #[Test]
    public function nonNumericSampleRateFallsBackToFullSampling(): void
    {
        $this->bootstrapWith(['enable' => true, 'sample_rate' => 'disabled']);

        XhprofProfiler::start();

        $this->assertTrue(self::profilerRunning());
    }

    /**
     * 布尔按开关处理：false = 不采，true = 全采。
     *
     * 不是洁癖：Drupal 的 install yml 由 Symfony YAML 解析，`sample_rate: off`/`no`
     * 解析出来就是 PHP false；PHP 配置里直接写 false 也很自然。若按"非数值→退默认
     * 1.0"处理，这两种写法会变成**全采**，与用户意图正好相反。
     */
    #[Test]
    public function booleanSampleRateMeansOnOff(): void
    {
        $this->bootstrapWith(['enable' => true, 'sample_rate' => false]);

        XhprofProfiler::start();
        $this->assertFalse(self::profilerRunning(), 'false = 明确关闭，不能退化成默认全采');

        $this->bootstrapWith(['enable' => true, 'sample_rate' => true]);

        XhprofProfiler::start();
        $this->assertTrue(self::profilerRunning(), 'true = 全采');
    }

    /**
     * 中段概率（0<rate<1）走 random_int 抽签。random_int 不可播种，所以这里**不**做
     * 「大约一半」的统计断言（会 flaky），只钉一个布尔事实：64 次里两种结果都出现过。
     * rate=0.5 下 64 次全采或全不采的概率是 2*0.5^64 ≈ 1e-19（比硬件出错率低几个数量级，
     * 不会 flaky）；而抽签分支被写死成恒真/恒假、或 rate 被当成 0/1 时，它必红。
     * 顺带端到端钉住：落库条数 == 实际抽中的次数（没抽中的不写空 run）。
     */
    #[Test]
    public function midRangeSampleRateTakesBothBranches(): void
    {
        // 用数字字符串 '0.5' 而非 float 0.5：顺带钉住「数字字符串按数值解析」——
        // 若实现只认 is_float/is_int，'0.5' 会退化成默认 1.0（恒采），下面必红。
        $cache = new ProfilerFixedListCache();
        $this->bootstrapWith(['enable' => true, 'sample_rate' => '0.5'], $cache);

        $taken = 0;
        for ($i = 0; $i < 64; $i++) {
            XhprofProfiler::start();
            if (self::profilerRunning()) {
                $taken++;
            }
            XhprofProfiler::stop();
        }

        $this->assertGreaterThan(0, $taken, '64 次一次都没抽中：抽签分支恒假（或 rate 被当成了 0）');
        $this->assertLessThan(64, $taken, '64 次全都抽中：抽签分支恒真（或 rate 被当成了 1）');
        $runs = array_filter($cache->calls, fn (string $c) => $c === 'lPush:xhprof:run_id');
        $this->assertCount($taken, $runs, '落库条数必须等于实际抽中的次数');
    }

    #[Test]
    public function twoConsecutiveStopsSaveOnlyOneRun(): void
    {
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('ext-xhprof 未加载');
        }

        $cache = new ProfilerFixedListCache();
        Xhprof::bootstrap($this->request, $this->response, $this->config, $cache, $this->logger);

        XhprofProfiler::start();
        XhprofProfiler::stop();
        XhprofProfiler::stop();   // 第二次调用：不得再写一条空 run

        $pushes = array_filter($cache->calls, fn (string $c) => $c === 'lPush:xhprof:run_id');
        $this->assertCount(1, $pushes, '两次 stop() 只允许落一条 run');
        $this->assertFalse(self::profilerRunning(), 'stop() 之后标记必须复位');
    }

    /** 复位不是"一次性闩"：停过之后下一次 start/stop 仍然照常落库 */
    #[Test]
    public function startAfterStopSamplesAgain(): void
    {
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('ext-xhprof 未加载');
        }

        $cache = new ProfilerFixedListCache();
        Xhprof::bootstrap($this->request, $this->response, $this->config, $cache, $this->logger);

        XhprofProfiler::start();
        XhprofProfiler::stop();
        XhprofProfiler::start();
        XhprofProfiler::stop();

        $pushes = array_filter($cache->calls, fn (string $c) => $c === 'lPush:xhprof:run_id');
        $this->assertCount(2, $pushes, '两对 start/stop 应落两条 run');
    }

    /**
     * 四个框架的中间件都在 finally 里调用 stop()。phpredis 在连接中断/认证失败/超时时
     * 会抛 RedisException——不在这里拦住，一个本来健康的请求会变成 500，
     * 而且原来的业务异常会被这个 Redis 异常顶替掉。
     */
    #[Test]
    public function stopSwallowsStorageFailures(): void
    {
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('ext-xhprof 未加载');
        }

        $throwing = new class extends FakeCache {
            public function lPush(string $key, mixed $value): int
            {
                throw new \RedisException('read error on connection');
            }
        };
        Xhprof::bootstrap($this->request, $this->response, $this->config, $throwing, $this->logger);

        XhprofProfiler::start();
        XhprofProfiler::stop();   // 不得向外抛出

        $this->assertStringContainsString(
            'save_run failed',
            $this->logger->errors[0] ?? '',
            '落库失败应记日志而不是把异常抛给调用方'
        );
    }

    #[Test]
    public function startStopWhenTimeLimitExceededSkipsSave(): void
    {
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('ext-xhprof 未加载');
        }

        // time_limit=100 秒，任何真实请求的 wt 都不可能超过，保存被跳过
        Xhprof::$time_limit = 100;

        XhprofProfiler::start();
        XhprofProfiler::stop();

        $this->assertNotContains('lPush:xhprof:run_id', $this->cache->calls);
    }

    #[Test]
    public function bootstrapMapsConfigToStatics(): void
    {
        $config = new FakeConfig([
            'xhprof' => [
                'ignore_url_arr' => ['/api', '/admin'],
                'time_limit' => 5,
                'log_num' => 123,
                'view_wtred' => 9,
                'key_prefix' => 'myxp',
            ],
        ]);
        Xhprof::bootstrap($this->request, $this->response, $config, $this->cache, $this->logger);

        $this->assertSame(['/api', '/admin'], Xhprof::$ignore_url_arr);
        $this->assertSame(5, Xhprof::$time_limit);
        $this->assertSame(123, Xhprof::$log_num);
        $this->assertSame(9, Xhprof::$view_wtred);
        $this->assertSame('myxp', Xhprof::$key_prefix);
    }

    #[Test]
    public function bootstrapAppliesDefaultsForMissingConfigKeys(): void
    {
        Xhprof::$ignore_url_arr = ['/custom'];
        Xhprof::$key_prefix = 'custom';
        Xhprof::$time_limit = 42;

        $config = new FakeConfig(['xhprof' => ['log_num' => 77]]);
        Xhprof::bootstrap($this->request, $this->response, $config, $this->cache, $this->logger);

        $this->assertSame(['/xhprof'], Xhprof::$ignore_url_arr);
        $this->assertSame(0, Xhprof::$time_limit);
        $this->assertSame(77, Xhprof::$log_num);
        $this->assertSame(3, Xhprof::$view_wtred);
        $this->assertSame('xhprof', Xhprof::$key_prefix);
    }

    #[Test]
    public function bootstrapReadsNumericStringsAsInts(): void
    {
        $config = new FakeConfig([
            'xhprof' => [
                'time_limit' => '2',
                'log_num' => '55',
                'view_wtred' => '7',
                'key_prefix' => 12345,
            ],
        ]);
        Xhprof::bootstrap($this->request, $this->response, $config, $this->cache, $this->logger);

        $this->assertSame(2, Xhprof::$time_limit);
        $this->assertSame(55, Xhprof::$log_num);
        $this->assertSame(7, Xhprof::$view_wtred);
        $this->assertSame('12345', Xhprof::$key_prefix);
    }

    #[Test]
    public function bootstrapWithNullConfigLeavesStaticsUntouched(): void
    {
        Xhprof::$time_limit = 42;
        Xhprof::$ignore_url_arr = ['/keep'];

        Xhprof::bootstrap($this->request, $this->response, null, $this->cache, $this->logger);

        // config 参数为 null 时不覆盖 Xhprof::$config（仍为 setUp 注入的 FakeConfig），
        // 但 XhprofProfiler::bootstrap() 会读取当前 config 并覆盖静态值 —— 属于 API 语义
        $this->assertSame($this->config, Xhprof::$config);
    }

    #[Test]
    public function profilerBootstrapWithNullConfigReturnsEarly(): void
    {
        Context::reset();
        Xhprof::$config = null;
        Xhprof::$time_limit = 42;
        Xhprof::$ignore_url_arr = ['/keep'];

        XhprofProfiler::bootstrap();

        $this->assertSame(42, Xhprof::$time_limit);
        $this->assertSame(['/keep'], Xhprof::$ignore_url_arr);
    }

    #[Test]
    public function isEnabledReturnsTrueWhenEnableSet(): void
    {
        $config = new FakeConfig(['xhprof' => ['enable' => true]]);
        Xhprof::bootstrap($this->request, $this->response, $config, $this->cache, $this->logger);

        $this->assertTrue(XhprofProfiler::isEnabled());
    }

    #[Test]
    public function isEnabledReturnsFalseWhenEnableMissing(): void
    {
        $this->assertFalse(XhprofProfiler::isEnabled());
    }

    #[Test]
    public function isEnabledReturnsFalseWhenEnableFalse(): void
    {
        $config = new FakeConfig(['xhprof' => ['enable' => false]]);
        Xhprof::bootstrap($this->request, $this->response, $config, $this->cache, $this->logger);

        $this->assertFalse(XhprofProfiler::isEnabled());
    }

    #[Test]
    public function isEnabledReturnsFalseWhenEnableIsNonBooleanTruthy(): void
    {
        // (bool) 强转语义：字符串 "0" 为 false
        $config = new FakeConfig(['xhprof' => ['enable' => '0']]);
        Xhprof::bootstrap($this->request, $this->response, $config, $this->cache, $this->logger);

        $this->assertFalse(XhprofProfiler::isEnabled());
    }

    #[Test]
    public function isEnabledReturnsFalseWhenNoConfigBound(): void
    {
        Context::reset();
        Xhprof::$config = null;

        $this->assertFalse(XhprofProfiler::isEnabled());
    }
}
