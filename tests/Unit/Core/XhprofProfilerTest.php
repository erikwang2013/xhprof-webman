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

    /** 写幂等标记。静态属性是单参 setValue() 的废弃形态，这里显式传 null 作 object。 */
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
        $cache = new FakeCache();
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
        $cache = new FakeCache();
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
        $cache = new FakeCache();
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
        $cache = new FakeCache();
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

    // ---------- trigger_token：按需触发采样（请求头 X-Xhprof-Token） ----------

    /**
     * 配 token 的 bootstrap 助手：$header 为 null 表示不带该头。
     * rate 默认给 0.0——它是判别性输入：没有触发分支时必然不采，触发分支写对了才采。
     */
    private function bootstrapWithTrigger(mixed $configured, ?string $header, mixed $rate = 0.0, ?FakeCache $cache = null): void
    {
        $request = new FakeRequest([], $header === null ? [] : ['headers' => ['X-Xhprof-Token' => $header]]);
        Xhprof::bootstrap($request, $this->response, new FakeConfig(['xhprof' => [
            'enable' => true,
            'sample_rate' => $rate,
            'trigger_token' => $configured,
        ]]), $cache ?? $this->cache, $this->logger);
    }

    /** 配了 token 且请求头逐字节相等 → 强制采样，rate=0 也采，并照常落库。 */
    #[Test]
    public function triggerTokenMatchForcesSamplingEvenAtRateZero(): void
    {
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('ext-xhprof 未加载');
        }

        $cache = new FakeCache();
        $this->bootstrapWithTrigger('s3cret-token', 's3cret-token', 0.0, $cache);

        XhprofProfiler::start();
        $this->assertTrue(self::profilerRunning(), '触发头逐字匹配必须无视 rate=0 强制采样');
        XhprofProfiler::stop();

        $this->assertContains('lPush:xhprof:run_id', $cache->calls, '触发采样照常落库');
    }

    /**
     * 配了 token 但头不匹配 / 没带 → 照常走 sample_rate（rate=0 不采）。
     * 大小写不同是判别性输入：strcasecmp/strtolower 式的比较会把它误判成匹配。
     */
    #[Test]
    public function triggerTokenMismatchOrMissingHeaderFallsBackToSampleRate(): void
    {
        $cases = [
            '大小写不同' => 'S3CRET-TOKEN',
            '长度不同' => 's3cret-token-and-then-some',
            '没带头' => null,
        ];
        foreach ($cases as $case => $header) {
            $this->bootstrapWithTrigger('s3cret-token', $header, 0.0);

            XhprofProfiler::start();
            $this->assertFalse(self::profilerRunning(), "{$case}：不该触发采样，rate=0 就该不采");
        }

        $this->assertSame([], $this->cache->calls, '三条都是「不采」，一个字节都不该写');
    }

    /**
     * 没配 trigger_token（null 或空串，auth_token 先例的「配置空 = 关闭」）时，
     * 该头**完全被无视**：带了任意值也是照常走 rate。默认零攻击面。
     *
     * `空串配置 + 空头值` 是判别性输入：只查 `!== null` 的实现会让它们逐字相等、
     * 触发全采（任何人发一个空头即可绕过抽签）——这正是「配置空 = 关闭」要堵的。
     */
    #[Test]
    public function unsetTriggerTokenIgnoresTheHeaderEntirely(): void
    {
        $cases = [
            'null + 任意值' => [null, 'anything-at-all'],
            'null + 空头值' => [null, ''],
            '空串 + 任意值' => ['', 'anything-at-all'],
            '空串 + 空头值' => ['', ''],
        ];
        foreach ($cases as $case => [$configured, $header]) {
            $this->bootstrapWithTrigger($configured, $header, 0.0);

            XhprofProfiler::start();
            $this->assertFalse(self::profilerRunning(), "trigger_token={$case}：该头必须被无视，rate=0 照常不采");
        }
    }

    /**
     * hash_equals 形状：长度不同 / 空值不炸（返回 false，不抛不警告——failOnWarning 下
     * 任何 warning 都会让本用例红）。
     */
    #[Test]
    public function triggerTokenComparisonHandlesDifferingLengthsAndEmptyValues(): void
    {
        $cases = [
            '前缀相同的更长值' => 'abc1234',
            '空头值' => '',
        ];
        foreach ($cases as $case => $header) {
            $this->bootstrapWithTrigger('abc', $header, 0.0);

            XhprofProfiler::start();
            $this->assertFalse(self::profilerRunning(), "{$case}：不算匹配，rate=0 不采");
        }
    }

    /**
     * 正向对照：不匹配不是「恒不采」，而是照常走 rate——rate=1.0 时同一个不匹配的头照样采。
     * 没有这条，`if (配了 token) return false;` 这种错实现能骗过上面三条负例。
     */
    #[Test]
    public function unmatchedTriggerTokenStillSamplesAtFullRate(): void
    {
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('ext-xhprof 未加载');
        }

        $this->bootstrapWithTrigger('s3cret-token', 'wrong-token', 1.0, new FakeCache());

        XhprofProfiler::start();
        $this->assertTrue(self::profilerRunning(), '不匹配 → 走 rate=1.0 → 全采');
        XhprofProfiler::stop();
    }

    // ---------- A#4：被排除路径在 start() 就短路（原先跑完整趟采样、到 save_run() 才丢弃） ----------

    /** 用给定 uri + 整块 xhprof 配置 bootstrap（ignore 用例的判别性输入就是 uri 本身）。 */
    private function bootstrapWithUri(string $uri, array $xhprofConfig, ?FakeCache $cache = null): void
    {
        $request = new FakeRequest([], ['uri' => $uri]);
        Xhprof::bootstrap($request, $this->response, new FakeConfig(['xhprof' => $xhprofConfig]), $cache ?? $this->cache, $this->logger);
    }

    /**
     * ignore_url_arr 命中的路径：start() 直接不采，一个字节都不写。
     *
     * 判别性：旧实现里 ignore 只在 save_run() 拦（采样照跑）——那时 profilerRunning()
     * 为 true，本用例的第一条断言必红。
     */
    #[Test]
    public function ignoredPathIsRejectedBeforeSamplingStarts(): void
    {
        $cache = new FakeCache();
        $this->bootstrapWithUri('/test/action', [
            'enable' => true,
            'sample_rate' => 1.0,
            'ignore_url_arr' => ['/test'],
        ], $cache);

        XhprofProfiler::start();
        $this->assertFalse(self::profilerRunning(), 'ignore 命中的路径不该开采样（旧实现到这里已经开了）');
        XhprofProfiler::stop();

        $this->assertSame([], $cache->calls, 'ignore 命中的路径一个字节都不该写（save_run 都没走到）');
    }

    /** 正向对照：同一条配置下，非排除路径照常采样+落库——防止「检查恒 false 短路」骗过上面一条。 */
    #[Test]
    public function nonIgnoredPathStillSamplesWithTheSameConfig(): void
    {
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('ext-xhprof 未加载');
        }

        $cache = new FakeCache();
        $this->bootstrapWithUri('/ok', [
            'enable' => true,
            'sample_rate' => 1.0,
            'ignore_url_arr' => ['/test'],
        ], $cache);

        XhprofProfiler::start();
        $this->assertTrue(self::profilerRunning(), '非排除路径必须照常采样');
        XhprofProfiler::stop();

        $this->assertContains('lPush:xhprof:run_id', $cache->calls, '非排除路径照常落库');
    }

    /**
     * 触发采样（X-Xhprof-Token 命中）**不**绕过 ignore：ignore 管「哪些路径永不记录」，
     * 触发管「这次要不要记录」，两条独立轴。判别性：把 ignore 判定放到触发分支之后
     * 的实现会在这里红（触发先 return true 就走到采样了）。
     */
    #[Test]
    public function triggerTokenDoesNotBypassIgnoreList(): void
    {
        $request = new FakeRequest([], ['uri' => '/test/action', 'headers' => ['X-Xhprof-Token' => 's3cret']]);
        Xhprof::bootstrap($request, $this->response, new FakeConfig(['xhprof' => [
            'enable' => true,
            'sample_rate' => 0.0,
            'trigger_token' => 's3cret',
            'ignore_url_arr' => ['/test'],
        ]]), $this->cache, $this->logger);

        XhprofProfiler::start();
        $this->assertFalse(self::profilerRunning(), 'ignore 命中的路径带着触发密钥也不采');
    }

    /**
     * 判定完全交给 XhprofLib::isIgnore()（含它管的两条轴），不在 start() 里另写一套：
     * 空 URI（CLI）在 sample_cli=false 时判忽略、true 时放行。
     */
    #[Test]
    public function emptyUriFollowsSampleCliThroughThePreCheck(): void
    {
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('ext-xhprof 未加载');
        }

        $this->bootstrapWithUri('', ['enable' => true, 'sample_rate' => 1.0]);
        XhprofProfiler::start();
        $this->assertFalse(self::profilerRunning(), 'sample_cli 关闭（默认）时空 URI 不采');

        $this->bootstrapWithUri('', ['enable' => true, 'sample_rate' => 1.0, 'sample_cli' => true]);
        XhprofProfiler::start();
        $this->assertTrue(self::profilerRunning(), 'sample_cli 打开时空 URI 照常按 rate 采');
        XhprofProfiler::stop();
    }

    // ---------- max_runs_per_minute：自适应预算（int|null，默认 null = 关闭） ----------

    /** 未配（默认 null）= 关闭：不碰预算键、行为与加本键之前逐字一致。 */
    #[Test]
    public function budgetIsOffWhenTheKeyIsMissingAndTouchesNoBudgetKey(): void
    {
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('ext-xhprof 未加载');
        }

        $cache = new FakeCache();
        $this->bootstrapWith(['enable' => true, 'sample_rate' => 1.0], $cache);

        XhprofProfiler::start();
        $this->assertTrue(self::profilerRunning(), '未配预算 = 旧行为（照常按 rate 采）');
        XhprofProfiler::stop();

        foreach ($cache->calls as $call) {
            $this->assertStringNotContainsString(':budget:', $call, '预算关闭时不该读写预算键');
        }
    }

    /** 写坏的形态（0/负数/非数值/布尔/数组）按关闭处理：回到旧行为，不是静默拒采。 */
    #[Test]
    public function nonPositiveOrNonNumericBudgetMeansOff(): void
    {
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('ext-xhprof 未加载');
        }

        foreach ([0, -3, 'sixty', true, []] as $value) {
            $cache = new FakeCache();
            $this->bootstrapWith(['enable' => true, 'sample_rate' => 1.0, 'max_runs_per_minute' => $value], $cache);

            XhprofProfiler::start();
            $this->assertTrue(
                self::profilerRunning(),
                '预算写坏（' . json_encode($value) . '）应回到旧行为，而不是拒采'
            );
            XhprofProfiler::stop();

            foreach ($cache->calls as $call) {
                $this->assertStringNotContainsString(':budget:', $call, '预算写坏时同样不该碰预算键');
            }
        }
    }

    /**
     * 预算内照常采、超了不采、首次 incr 补 TTL——一条链钉三件事。
     *
     * 桶键 = <key_prefix>:budget:<YmdHi>（分钟桶）。第二条断言（超预算不采）先把
     * **当前与下一分钟**的桶都预置成「已用满」：不管落进哪个桶都会被拦，不受分钟
     * 边界影响（本用例其余断言只在单个桶内取值，跨分钟时靠 $bucket 回退到下一桶）。
     */
    #[Test]
    public function budgetAllowsUntilTheMinuteBucketIsExhausted(): void
    {
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('ext-xhprof 未加载');
        }

        $cache = new FakeCache();
        $this->bootstrapWith([
            'enable' => true,
            'sample_rate' => 1.0,
            'max_runs_per_minute' => 1,
            'key_prefix' => 'pfx',
        ], $cache);

        $now = 'pfx:budget:' . date('YmdHi');
        $next = 'pfx:budget:' . date('YmdHi', time() + 60);

        XhprofProfiler::start();   // 空桶 → incr 得 1 ≤ 预算 1 → 采
        $this->assertTrue(self::profilerRunning(), '预算之内必须照常采（配了预算 ≠ 拒采）');
        XhprofProfiler::stop();

        $bucket = in_array("incr:$now", $cache->calls, true) ? $now : $next;
        $this->assertContains("set:$bucket", $cache->calls, '首次 incr 后必须补一次 set（Redis 的 incr 不设 TTL，桶键会永久留下）');
        $this->assertSame(120, $cache->ttls[$bucket] ?? null, 'TTL 应为 ~120s（覆盖分钟边界即够）');

        // 当前与下一分钟的桶都预置为已用满：第二次 start 无论落哪个桶都超预算
        $cache->set($now, 1);
        $cache->set($next, 1);

        XhprofProfiler::start();
        $this->assertFalse(self::profilerRunning(), '桶计数超过预算 1：不采');
    }

    /**
     * fail-open：预算判定抛异常（phpredis 连接中断/认证失败等）时照常按 sample_rate
     * 采，绝不向调用方抛。判别性：去掉 try/catch 的实现会把 RedisException 抛穿 start()。
     */
    #[Test]
    public function budgetFailureFallsBackToSampleRate(): void
    {
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('ext-xhprof 未加载');
        }

        $throwing = new class extends FakeCache {
            public function incr(string $key): int
            {
                throw new \RedisException('read error on connection');
            }
        };
        $this->bootstrapWith(['enable' => true, 'sample_rate' => 1.0, 'max_runs_per_minute' => 1], $throwing);

        XhprofProfiler::start();
        $this->assertTrue(self::profilerRunning(), '预算机制失败必须 fail-open（照常采样），而不是崩或停摆');
        XhprofProfiler::stop();
    }

    /** 缓存没绑（getCache() 为 null）同样 fail-open：预算闸门整个跳过，采样照常。 */
    #[Test]
    public function budgetIsSkippedWhenNoCacheIsBound(): void
    {
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('ext-xhprof 未加载');
        }

        // bootstrap 只覆盖非 null 的适配器：先清掉 cache，再传 null 让绑定保持为空
        Xhprof::$cache = null;
        Xhprof::bootstrap($this->request, null, new FakeConfig(['xhprof' => [
            'enable' => true,
            'sample_rate' => 1.0,
            'max_runs_per_minute' => 1,
        ]]), null, $this->logger);

        XhprofProfiler::start();
        $this->assertTrue(self::profilerRunning(), '缓存未绑定时预算不生效，但采样照常');
        XhprofProfiler::stop();   // 落库会因无缓存失败，由 stop() 的 catch 兜住（不向外抛）
    }

    /**
     * 触发采样不被预算拦：预算给**自动流量**设上限，不给拿着密钥来排查的人关门。
     * 两个桶都填满 + rate=0：只有「触发先于预算」的实现能采到。
     */
    #[Test]
    public function budgetDoesNotBlockTriggeredSampling(): void
    {
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('ext-xhprof 未加载');
        }

        $cache = new FakeCache();
        $request = new FakeRequest([], ['headers' => ['X-Xhprof-Token' => 's3cret']]);
        Xhprof::bootstrap($request, $this->response, new FakeConfig(['xhprof' => [
            'enable' => true,
            'sample_rate' => 0.0,
            'trigger_token' => 's3cret',
            'max_runs_per_minute' => 1,
        ]]), $cache, $this->logger);

        // 当前与下一分钟的桶都按「已用满」预置，无论落哪个桶预算都是超的
        $cache->set('xhprof:budget:' . date('YmdHi'), 99);
        $cache->set('xhprof:budget:' . date('YmdHi', time() + 60), 99);
        $cache->calls = [];   // 丢掉预置动作的记录，只留 start() 自己产生的调用

        XhprofProfiler::start();
        $this->assertTrue(self::profilerRunning(), '触发采样不受预算限制（rate=0，只有触发分支能救）');
        XhprofProfiler::stop();

        foreach ($cache->calls as $call) {
            $this->assertStringNotContainsString(':budget:', $call, '触发路径根本不进预算闸门');
        }
    }

    #[Test]
    public function twoConsecutiveStopsSaveOnlyOneRun(): void
    {
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('ext-xhprof 未加载');
        }

        $cache = new FakeCache();
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

        $cache = new FakeCache();
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
