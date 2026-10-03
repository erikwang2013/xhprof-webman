<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Laravel\Adapter\CliRequestAdapter;
use ErikWang2013\Xhprof\Laravel\XhprofCli;
use ErikWang2013\Xhprof\Laravel\XhprofProfileCommand;
use ErikWang2013\Xhprof\Laravel\XhprofQueueListener;
use ErikWang2013\Xhprof\Laravel\XhprofServiceProvider;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeRequest;
use ErikWang2013\Xhprof\Tests\Stubs\Registry;
use ErikWang2013\Xhprof\Tests\Support\XhprofStaticsSnapshot;

/**
 * Laravel CLI 入口（XhprofCli / XhprofQueueListener）的采样窗口语义。
 *
 * 全部走桩（Registry 的 laravel 配置树 + Illuminate 门面桩），不碰真实 Laravel：
 * 本用例要钉的是「窗口怎么开怎么关、什么条件下不开」，与框架内部行为无关
 * （队列事件的派发顺序是在真实 vendor 上量过之后写进注释的，见 XhprofQueueListener 类注释）。
 *
 * 判别力来自三层：窗口状态（XhprofProfiler::$running 反射读）、落库条数与内容
 * （Redis::$store 里的 run_id 列表与 request_log 行）、`cli:` 合成 URI。把 start/stop
 * 任一侧删掉、把守卫去掉、把空 URI 换成 '/'，都会在这三层里的至少一层变红。
 */
class LaravelCliTest extends TestCase
{
    use XhprofStaticsSnapshot;

    /** @var array<string, mixed> Xhprof 静态属性快照 */
    private array $saved = [];

    private bool $savedProfilerRunning = false;

    private int $savedCliDepth = 0;

    protected function setUp(): void
    {
        Redis::reset();
        Log::reset();
        Registry::reset();
        $this->saved = $this->snapshotXhprofStatics();
        $this->savedProfilerRunning = self::profilerRunning();
        $this->savedCliDepth = self::cliDepth();

        // 采样窗口是进程级静态量，先归位再开测：前一个用例若把窗口留在开着，
        // 本用例的「没开窗口」断言就变成断言别人的状态。
        self::setProfilerRunning(false);
        self::setCliDepth(0);

        // 控制台应用的 bootstrapper 表同样是静态量（真 API 清场）：LaravelTest 里那次
        // `$provider->boot()` 也会往里攒一条 commands() 闭包，不扫掉的话「boot 之前没有」
        // 这类断言就变成取决于用例执行顺序。
        ConsoleApplication::forgetBootstrappers();
    }

    protected function tearDown(): void
    {
        xhprof_disable();   // 兜底：万一某个断言序列把扩展留在 enabled 状态
        ConsoleApplication::forgetBootstrappers();  // 别把注册表留给后面的用例文件
        self::setProfilerRunning($this->savedProfilerRunning);
        self::setCliDepth($this->savedCliDepth);
        $this->restoreXhprofStatics($this->saved);
    }

    // ------------------------------------------------------------------
    // CliRequestAdapter：空 URI 是与 Core 的契约
    // ------------------------------------------------------------------

    /**
     * 这一组字面量不是"随便给的默认值"：`uri() === ''` 正是 Core 判定「没有 HTTP 请求」
     * 的入口（XhprofLib::isIgnore() 的 `empty($request_uri)` 分支），改成 '/' 之类会静默
     * 走进 HTTP 分支——sample_cli=false 也照采、列表页把 CLI 数据显示成一条根路径请求。
     * getRealIp() 的常量同理：RequestAdapter 在 CLI 下会因为 Illuminate 的 ip() 是 null
     * 而抛 TypeError，这正是本适配器存在的理由。
     */
    #[Test]
    public function cliRequestAdapterPinsTheEmptyUriContract(): void
    {
        $req = new CliRequestAdapter();

        $this->assertSame('', $req->uri(), '空 URI 是 Core 的 CLI 判定入口，不能顺手补成 /');
        $this->assertSame('CLI', $req->method());
        $this->assertSame('127.0.0.1', $req->getRealIp(), '与 Native 适配器的 REMOTE_ADDR 缺席口径一致');
        $this->assertNull($req->header('x-xhprof-token'), 'CLI 无请求头：按需触发采样不生效');
        $this->assertSame('', $req->host());
        $this->assertSame('', $req->url());
        $this->assertSame([], $req->all());
        $this->assertSame('d', $req->get('run', 'd'));
    }

    // ------------------------------------------------------------------
    // 队列事件：按消息开停
    // ------------------------------------------------------------------

    /**
     * 一次 JobProcessing → 一次 JobProcessed = 恰好一条 CLI run，且这条 run 是本次采样数据。
     */
    #[Test]
    public function jobEventsOpenAndCloseExactlyOneWindowPerMessage(): void
    {
        $this->useConfig(['enable' => true, 'sample_cli' => true]);
        $listener = new XhprofQueueListener();

        $listener->onJobProcessing();
        $this->assertTrue(self::profilerRunning(), 'JobProcessing 之后窗口应当开着');

        $listener->onJobProcessed();
        $this->assertFalse(self::profilerRunning(), 'JobProcessed 之后窗口应当关掉');

        $rows = $this->savedRows();
        $this->assertCount(1, $rows, '一个任务一条 run');
        $this->assertSame('CLI', $rows[0]['method']);
        $this->assertSame('127.0.0.1', $rows[0]['ip']);
        $this->assertStringStartsWith('cli:', (string) $rows[0]['request_uri'], '落库的 request_uri 要一眼看出是 CLI');
        $this->assertStringNotContainsString('/', (string) $rows[0]['request_uri'], 'cli: 后面只留脚本 basename');

        // 断言「落库的是本次采样数据」而不是只断言 key 存在（否则 stop() 里把 save_run 换掉
        // 也照样绿，照 LaravelTest::nearMissAssetPathIsStillABusinessRequest 的口径）。
        $runId = Redis::$store['xhprof:run_id'][0];
        $data = unserialize((string) Redis::$store['xhprof:xhprof_log:' . $runId]);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('main()', $data);
    }

    /**
     * 第二个任务要拿到**它自己的**一条 run：窗口是每消息一对，不是每进程一次。
     *
     * 这条是常驻 worker 形态的核心不变量。若把止点挂到进程 shutdown（Symfony 入口对 FPM
     * 就是这么做的），`queue:work` 里一天也不会触发；若给 stop() 加个"只落一次"的闩，
     * 第二个任务的数据就没了。
     */
    #[Test]
    public function secondJobGetsItsOwnRunNotOncePerProcess(): void
    {
        $this->useConfig(['enable' => true, 'sample_cli' => true]);
        $listener = new XhprofQueueListener();

        $listener->onJobProcessing();
        $listener->onJobProcessed();
        $listener->onJobProcessing();
        $listener->onJobProcessed();

        $rows = $this->savedRows();
        $this->assertCount(2, $rows, '两个任务两条 run：按消息开停');
        $this->assertFalse(self::profilerRunning());
        $this->assertNotSame(
            Redis::$store['xhprof:run_id'][0],
            Redis::$store['xhprof:run_id'][1],
            '两条 run 各有各的 run_id'
        );
    }

    /**
     * 失败路径也必须关窗口：抛异常（JobExceptionOccurred）与判定失败（JobFailed）各一条。
     *
     * 单靠 JobExceptionOccurred 不够——「上次已超时超限」的任务是先派 JobProcessing、
     * 在 fire() 之前就判失败，只有 JobFailed 会到；漏掉它窗口就泄漏到下一个任务。
     * 失败路径上两个事件常常先后都到（超过最大重试次数时 failJob 与
     * raiseExceptionOccurredJobEvent 都会跑），所以重复的 stop 必须是 no-op、不能多写 run。
     */
    #[Test]
    public function failurePathsAlsoCloseTheWindowExactlyOnce(): void
    {
        $this->useConfig(['enable' => true, 'sample_cli' => true]);
        $listener = new XhprofQueueListener();

        // 路径 A：任务抛异常（随后可能 release 重试；Worker 在 release 之前就派发该事件）
        $listener->onJobProcessing();
        $listener->onJobExceptionOccurred();
        $this->assertFalse(self::profilerRunning(), 'JobExceptionOccurred 之后窗口应当关掉');

        // 路径 B：超过最大重试次数 —— 两个止点都会到，第二个必须是无害的 no-op
        $listener->onJobProcessing();
        $listener->onJobFailed();
        $listener->onJobExceptionOccurred();
        $this->assertFalse(self::profilerRunning());

        // 路径 C：「上次已超限」的任务：JobProcessing 之后直接 JobFailed，没有 JobProcessed
        $listener->onJobProcessing();
        $listener->onJobFailed();
        $this->assertFalse(self::profilerRunning());

        $this->assertCount(3, $this->savedRows(), '每次都恰好一条 run：重复 stop 不能多写');
    }

    /**
     * 同步派发的子任务（dispatchSync → SyncQueue）会嵌套开窗：内层的 stop 不能把外层的窗口关掉。
     *
     * 这是 `$depth` 计数（而非 bool）存在的唯一理由，也是它被真实框架行为钉住的证据：
     * 同一个进程里 SyncQueue 会再派一对 JobProcessing/JobProcessed。bool 形态下外层任务的
     * 后半段静默丢失——报告里那条 run 只覆盖到子任务结束，而**没有任何报错**。
     */
    #[Test]
    public function nestedSyncDispatchKeepsTheOuterWindowOpen(): void
    {
        $this->useConfig(['enable' => true, 'sample_cli' => true]);
        $listener = new XhprofQueueListener();

        $listener->onJobProcessing();   // 外层任务
        $listener->onJobProcessing();   // 被外层同步派发的子任务
        $listener->onJobProcessed();    // 子任务结束

        $this->assertTrue(self::profilerRunning(), '内层结束不能关掉外层还没跑完的窗口');
        $this->assertSame([], Redis::$store, '内层的 stop 不该落库：一个最外层窗口 = 一条 run');

        $listener->onJobProcessed();    // 外层结束
        $this->assertFalse(self::profilerRunning());
        $this->assertCount(1, $this->savedRows(), '嵌套只落最外层那一条');
    }

    /**
     * 没有配对 start 的 stop 是 no-op：不能凭空写一条空 run（XhprofProfiler::stop() 的
     * 幂等守卫与 XhprofCli 的深度计数，两层都不许漏）。
     */
    #[Test]
    public function stopWithoutStartIsANoOp(): void
    {
        $this->useConfig(['enable' => true, 'sample_cli' => true]);
        $listener = new XhprofQueueListener();

        $listener->onJobProcessed();
        $listener->onJobFailed();
        $listener->onJobExceptionOccurred();

        $this->assertSame([], Redis::$store, '无配对 start 的 stop 不能写空 run');
        $this->assertSame([], Log::$errors, '也不该报错');
    }

    // ------------------------------------------------------------------
    // 开关：sample_cli 与 enable
    // ------------------------------------------------------------------

    /**
     * `sample_cli` 关闭（显式 false 或键不存在）时，队列任务一律不采——这是默认行为，
     * 也是加这个开关之前的旧行为，必须逐字保持。
     */
    #[Test]
    public function sampleCliOffKeepsQueueJobsOutOfTheReports(): void
    {
        $listener = new XhprofQueueListener();

        foreach ([['enable' => true], ['enable' => true, 'sample_cli' => false]] as $config) {
            Redis::reset();
            self::setProfilerRunning(false);
            self::setCliDepth(0);
            $this->useConfig($config);

            $listener->onJobProcessing();
            $this->assertFalse(self::profilerRunning(), 'sample_cli 关闭时连窗口都不该开');
            $listener->onJobProcessed();

            $this->assertSame([], Redis::$store, 'sample_cli 关闭时 CLI 请求不落库（连 run_id 列表都不写）');
        }
    }

    /**
     * `enable=false` 总开关优先：sample_cli 打开也不采。
     */
    #[Test]
    public function masterSwitchWinsOverSampleCli(): void
    {
        $this->useConfig(['enable' => false, 'sample_cli' => true]);
        $listener = new XhprofQueueListener();

        $listener->onJobProcessing();
        $this->assertFalse(self::profilerRunning(), 'enable=false 总开关优先，窗口不该开');
        $listener->onJobProcessed();

        $this->assertSame([], Redis::$store);
    }

    // ------------------------------------------------------------------
    // 注入接缝
    // ------------------------------------------------------------------

    /**
     * 构造参数能把 CliRequestAdapter 顶掉（测试与"伪装成某个 URI"的场景用），
     * 且非空 URI 时落库不做 `cli:` 合成——两条分支都走一次，证明接缝真的在生效。
     */
    #[Test]
    public function injectedRequestReplacesTheCliAdapter(): void
    {
        $this->useConfig(['enable' => true, 'sample_cli' => true]);
        $listener = new XhprofQueueListener(new FakeRequest([], ['uri' => '/tasks/nightly']));

        $listener->onJobProcessing();
        $listener->onJobProcessed();

        $rows = $this->savedRows();
        $this->assertCount(1, $rows);
        $this->assertSame('localhost/tasks/nightly', $rows[0]['request_uri'], '注入的适配器顶掉了 CLI 适配器');
        $this->assertSame('GET', $rows[0]['method']);
    }

    // ------------------------------------------------------------------
    // artisan 命令：xhprof:profile
    // ------------------------------------------------------------------

    /**
     * `xhprof:profile "cli-probe:inner --flag --tag=nightly"`：内层命令真跑、选项原样
     * 透传、外层窗口恰好一条 run、退出码原样穿回。
     *
     * 跑在 tests/Stubs 的 Illuminate\Console 桩上（单测里没有真 Symfony Console）：桩
     * 顶替 StringInput 的只有分词那一层（双引号 + `--k=v`），「解析 → 找命令 → 派发」的
     * 形状与真框架一致；同一条形状在真机 Laravel 12/13 上另有契约探针验证（见报告）。
     */
    #[Test]
    public function profileCommandRunsTheInnerCommandInsideOneWindow(): void
    {
        $this->useConfig(['enable' => true, 'sample_cli' => true]);
        LaravelCliInnerProbe::reset();

        $console = new ConsoleApplication();
        $console->add(new LaravelCliInnerProbe());
        $console->add(new XhprofProfileCommand());

        $exit = $console->call('xhprof:profile "cli-probe:inner --flag --tag=nightly"');

        $this->assertSame(7, $exit, '内层命令的退出码原样穿回（7 不是任何路径的默认值）');
        $this->assertTrue(LaravelCliInnerProbe::$ran, '内层命令真的执行了');
        $this->assertTrue(LaravelCliInnerProbe::$flag, '--flag 透传到位');
        $this->assertSame('nightly', LaravelCliInnerProbe::$tag, '--tag=nightly 的值透传到位');
        $this->assertFalse(self::profilerRunning(), '命令结束时窗口关掉');

        $rows = $this->savedRows();
        $this->assertCount(1, $rows, '外层窗口一条 run');
        $this->assertStringStartsWith('cli:', (string) $rows[0]['request_uri']);
    }

    /**
     * 内层命令抛异常：窗口照关、该落的那条 run 照样落，异常原样上抛 —— finally 的另一侧。
     */
    #[Test]
    public function profileCommandClosesTheWindowWhenTheInnerCommandThrows(): void
    {
        $this->useConfig(['enable' => true, 'sample_cli' => true]);

        $console = new ConsoleApplication();
        $console->add(new LaravelCliThrowingProbe());
        $console->add(new XhprofProfileCommand());

        try {
            $console->call('xhprof:profile "cli-probe:boom"');
            $this->fail('内层命令的异常应当原样抛出来');
        } catch (\RuntimeException $e) {
            $this->assertSame('cli probe boom', $e->getMessage(), '抛出的正是内层那个异常');
        }

        $this->assertFalse(self::profilerRunning(), '异常路径也要关窗口（finally）');
        $this->assertCount(1, $this->savedRows(), '异常路径同样落一条 run');
    }

    /**
     * 命令直接 new 出来（没挂到 console 应用上）：不开窗口、不落 run、失败返回。
     * getApplication() 真的可空（Symfony Command/Command.php:201），这条钉的就是那个分支。
     */
    #[Test]
    public function profileCommandRefusesToRunWithoutAConsoleApplication(): void
    {
        $this->useConfig(['enable' => true, 'sample_cli' => true]);

        $exit = (new XhprofProfileCommand())->handle();

        $this->assertSame(1, $exit, '没有可解析命令行的应用：FAILURE 返回');
        $this->assertFalse(self::profilerRunning(), '窗口不该开');
        $this->assertSame([], Redis::$store, '不落 run');
    }

    /**
     * 自动注册：Provider 的 boot() 一跑，下一个建出来的控制台应用里就有 `xhprof:profile`
     * —— 用户不用在自己应用的 AppServiceProvider 里写注册那几行。
     *
     * 走的是真链路：XhprofServiceProvider::boot() → ServiceProvider::commands()（桩照抄真体）
     * → Illuminate\Console\Application::starting() 攒闭包 → 新应用 __construct() → bootstrap()
     * 执行 → resolve() → add()。判别力两处：同一实例上的假查询反例（has() 不是恒真），以及
     * 「注册进来的那个实例真能跑」——只断言 has() 为真，连"注册了个同名空壳"也放过去。
     */
    #[Test]
    public function serviceProviderBootRegistersTheProfileCommand(): void
    {
        $this->useConfig(['enable' => true, 'sample_cli' => true]);
        LaravelCliInnerProbe::reset();

        // 对照组：boot 之前建的应用里没有它（同一条 has() 得能给出 false）
        $before = new ConsoleApplication();
        $this->assertFalse($before->has('xhprof:profile'), 'boot 之前不该有这条命令');

        (new XhprofServiceProvider())->boot();

        $after = new ConsoleApplication();
        $this->assertTrue($after->has('xhprof:profile'), 'boot 之后的新应用里就该有这条命令');
        $this->assertFalse($after->has('cli-probe:inner'), '反例：has() 不是恒真');

        // 注册进来的实例真能跑：跑通才说明它是那个命令类，而不是占位的同名空壳
        $after->add(new LaravelCliInnerProbe());
        $exit = $after->call('xhprof:profile "cli-probe:inner --flag"');

        $this->assertSame(7, $exit, '自动注册的那条命令把内层退出码穿回来了');
        $this->assertTrue(LaravelCliInnerProbe::$ran, '内层命令真的执行了');
        $this->assertFalse(self::profilerRunning(), '命令结束时窗口关掉');

        $rows = $this->savedRows();
        $this->assertCount(1, $rows, '外层窗口恰一条 run');
        $this->assertStringStartsWith('cli:', (string) $rows[0]['request_uri']);
    }

    // ------------------------------------------------------------------
    // 夹具
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $xhprofConfig */
    private function useConfig(array $xhprofConfig): void
    {
        Registry::$laravelConfig = ['xhprof' => $xhprofConfig];
    }

    /**
     * 落库的 request_log 行，按写入先后排列（Redis 的 run_id 列表是 lPush，新的在前）。
     *
     * @return list<array<string, mixed>>
     */
    private function savedRows(): array
    {
        $rows = [];
        foreach (Redis::$store['xhprof:run_id'] ?? [] as $runId) {
            $rows[] = json_decode((string) (Redis::$store['xhprof:request_log:' . $runId] ?? 'null'), true);
        }

        return array_reverse($rows);
    }

    private static function profilerRunning(): bool
    {
        return (bool) (new \ReflectionProperty(XhprofProfiler::class, 'running'))->getValue();
    }

    /** 写幂等标记。静态属性是单参 setValue() 的废弃形态，这里显式传 null 作 object。 */
    private static function setProfilerRunning(bool $running): void
    {
        (new \ReflectionProperty(XhprofProfiler::class, 'running'))->setValue(null, $running);
    }

    private static function cliDepth(): int
    {
        return (int) (new \ReflectionProperty(XhprofCli::class, 'depth'))->getValue();
    }

    private static function setCliDepth(int $depth): void
    {
        (new \ReflectionProperty(XhprofCli::class, 'depth'))->setValue(null, $depth);
    }
}

/** 测试用内层命令：证明「真跑 + 选项透传」而不是只看落库 */
class LaravelCliInnerProbe extends ConsoleCommand
{
    public static bool $ran = false;

    public static bool $flag = false;

    public static ?string $tag = null;

    /** cli-probe 前缀：与 shell 里可能存在的真实命令隔开 */
    protected $signature = 'cli-probe:inner {--flag} {--tag=}';

    protected $description = '测试内层命令';

    public static function reset(): void
    {
        self::$ran = false;
        self::$flag = false;
        self::$tag = null;
    }

    public function handle(): int
    {
        self::$ran = true;
        self::$flag = (bool) $this->option('flag');
        self::$tag = $this->option('tag');

        // 7 不是任何路径的默认值：外层是否把退出码穿回来，这里一断言就分得清。
        return 7;
    }
}

/** 测试用内层命令：抛异常（钉 finally 那一侧） */
class LaravelCliThrowingProbe extends ConsoleCommand
{
    protected $signature = 'cli-probe:boom';

    protected $description = '测试内层命令（抛异常）';

    public function handle(): int
    {
        throw new \RuntimeException('cli probe boom');
    }
}
