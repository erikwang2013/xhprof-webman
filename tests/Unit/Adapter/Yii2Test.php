<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface as CoreLoggerInterface;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\StaticController;
use ErikWang2013\Xhprof\Core\Xhprof as CoreXhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use ErikWang2013\Xhprof\Tests\Fixtures\FileCache;
use ErikWang2013\Xhprof\Tests\Support\XhprofStaticsSnapshot;
use ErikWang2013\Xhprof\Yii2\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Yii2\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Yii2\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Yii2\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Yii2\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Yii2\XhprofBootstrap;
use yii\base\Application as BaseApplication;
use yii\base\ExitException;
use yii\web\Response as WebResponse;

/**
 * Yii2 入口类（`XhprofBootstrap`）与 5 个适配器。
 *
 * 桩的保真度不靠本文件自证：`tools/contracts/cases/Yii2.php` 用真 yiisoft/yii2 跑同一批语义。
 * 本文件里五条**非空转核心**（去掉被测代码的那一行就变红）：
 *  1. `withBodyKeepsAssetContentTypeDespiteHtmlFormatter` —— 去掉 `format = FORMAT_RAW` 必红；
 *  2. `withBodyNeutralisesPreExistingData` —— 去掉 `data = null` 必红；
 *  3. `servesReportPageAndNeverReachesBusiness` —— 短路位置挪到起表之后必红；
 *  4. `bootstrapAttachesHandlersOnlyOnWebApplications` —— 去掉 Web 守卫必红（控制台请求没有 getUrl）；
 *  5. `shutdownBackstopSavesRunWhenAfterRequestNeverFires` —— 去掉兜底注册必红（子进程读总账）。
 */
class Yii2Test extends TestCase
{
    use XhprofStaticsSnapshot;

    /** @var array<string, mixed> */
    private array $saved = [];

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->saved = $this->snapshotXhprofStatics();
        $this->resetEntryStatics();
    }

    protected function tearDown(): void
    {
        $this->resetEntryStatics();
        $this->restoreXhprofStatics($this->saved);
        xhprof_disable();
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * 入口类的两个私有静态量必须逐用例归零。
     *
     * `$stopped` 不还原的后果是**跨用例的假绿**：某个用例把采样开起来又没走到止点，
     * 下一个用例的 `startSampling()` 会看到 `$stopped === false` 而**不**起表，
     * 于是「enable 时落库」那条断言测的其实是上一个用例留下的采样。
     * `$shutdownRegistered` 不还原则会让第一个用例之后的所有兜底注册全部空转。
     */
    private function resetEntryStatics(): void
    {
        foreach (['stopped' => true, 'shutdownRegistered' => false] as $name => $value) {
            $prop = new \ReflectionProperty(XhprofBootstrap::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    // ================= 构造夹具 =================

    /**
     * @param array<string, mixed> $requestConfig 见 tests/Stubs/Framework/Yii2.php 的 Request
     * @param array<string, mixed> $config        入口类的 `config` 键
     */
    private function adapters(array $requestConfig = [], array $config = []): array
    {
        $request = new \yii\web\Request($requestConfig + [
            'url' => '/business',
            'hostInfo' => 'http://example.com:8080',
            'remoteAddr' => '203.0.113.9',
        ]);

        return [
            new RequestAdapter($request),
            new ResponseAdapter(new WebResponse()),
            new ConfigAdapter($config),
        ];
    }

    /**
     * 造一个 Web 应用并把入口类按文档形状注册进去（`bootstrap` 数组定义 + 属性注入）。
     *
     * @param array<string, mixed> $requestConfig
     * @param array<string, mixed> $config
     */
    private function makeApp(
        array $requestConfig = [],
        array $config = [],
        ?CacheInterface $cache = null,
        bool $businessThrows = false
    ): \yii\web\Application {
        $definition = ['class' => XhprofBootstrap::class, 'config' => $config];
        if ($cache !== null) {
            $definition['cache'] = $cache;
        }

        $request = new \yii\web\Request($requestConfig + [
            'url' => '/business',
            'hostInfo' => 'http://example.com:8080',
            'remoteAddr' => '203.0.113.9',
        ]);

        return new class (['bootstrap' => [$definition], 'request' => $request], $businessThrows) extends \yii\web\Application {
            private bool $businessThrows;

            public function __construct(array $config, bool $businessThrows)
            {
                parent::__construct($config);
                $this->businessThrows = $businessThrows;
            }

            public function handleRequest($request)
            {
                $this->handleRequestCalled = true;
                if ($this->businessThrows) {
                    throw new \RuntimeException('handler boom');
                }
                $response = $this->getResponse();
                $response->content = 'BUSINESS-OK';
                $response->format = WebResponse::FORMAT_RAW;

                return $response;
            }
        };
    }

    /**
     * 跑一次完整请求，捕掉短路路径的 ExitException（真包生产环境下 `end()` 是 `exit`）。
     *
     * `ob_start()` 是必需的：桩的 `sendContent()` 会 echo，而 phpunit.xml 里 failOnRisky=true，
     * 裸输出会被标 risky 判失败。
     *
     * @return array{thrown:?\Throwable, body:string}
     */
    private function runApp(\yii\web\Application $app): array
    {
        ob_start();
        $thrown = null;
        try {
            $app->run();
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        $body = (string) ob_get_clean();

        return ['thrown' => $thrown, 'body' => $body];
    }

    /** 断言一次 save_run 完整落库（只断言「键存在」等于没测）。 */
    private function assertRunSaved(FakeCache $cache): void
    {
        $runIds = $cache->lRange('xhprof:run_id', 0, -1);
        $this->assertCount(1, $runIds, '落库后 run_id 列表应恰好一条');
        $runId = $runIds[0];
        $this->assertIsString($runId);
        $this->assertIsString($cache->get('xhprof:request_log:' . $runId));
        $serialized = $cache->get('xhprof:xhprof_log:' . $runId);
        $this->assertIsString($serialized);
        $data = unserialize($serialized);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('main()', $data, '采样数据缺少 main() 帧 → 报告页会是空的');
    }

    // ================= RequestAdapter =================

    #[Test]
    public function requestAdapterReadsPathAndQueryWithoutHost(): void
    {
        [$request] = $this->adapters(['url' => '/xhprof?run=abc&source=x']);

        // R-1：绝不含 scheme/host（否则 host 叫 xhprof.* 的站点每个请求都被 isIgnore() 误判）
        $this->assertSame('/xhprof?run=abc&source=x', $request->uri());
        $this->assertStringNotContainsString('://', $request->uri());
    }

    #[Test]
    public function requestAdapterDropsPortFromHost(): void
    {
        [$request] = $this->adapters(['hostInfo' => 'http://example.com:8080']);
        // R-2：不含端口
        $this->assertSame('example.com', $request->host());
    }

    #[Test]
    public function requestAdapterReturnsEmptyHostWhenFrameworkCannotDetermineIt(): void
    {
        // `getHostName()` 在 hostInfo 为 null 时返回 null，而契约声明 `: string`（R-3）
        [$request] = $this->adapters(['hostInfo' => null]);
        $this->assertSame('', $request->host());
    }

    #[Test]
    public function requestAdapterUrlKeepsSchemeHostAndPort(): void
    {
        [$request] = $this->adapters(['url' => '/list?page=2', 'hostInfo' => 'https://example.com:8443']);
        $this->assertSame('https://example.com:8443/list?page=2', $request->url());
    }

    #[Test]
    public function requestAdapterUriIsEmptyWhenFrameworkThrowsInsteadOfReturning(): void
    {
        // 真包 resolveRequestUri() 在三个来源都缺时抛 InvalidConfigException，
        // 而 uri() 每个请求上都会被调用（短路判定的第一句）。
        [$request] = $this->adapters(['url' => null]);
        $this->assertSame('', $request->uri());
    }

    #[Test]
    public function requestAdapterHeaderIsCaseInsensitiveAndNullWhenAbsent(): void
    {
        [$request] = $this->adapters(['headers' => ['X-Token' => 'abc']]);

        $this->assertSame('abc', $request->header('X-Token'));
        $this->assertSame('abc', $request->header('x-token'));
        // R-3：缺省给 null，不是 ''
        $this->assertNull($request->header('x-none'));
    }

    #[Test]
    public function requestAdapterPrefersQueryOverBodyInBothAccessors(): void
    {
        [$request] = $this->adapters([
            'queryParams' => ['run' => 'from-query', 'only_query' => 1],
            'bodyParams' => ['run' => 'from-body', 'only_body' => 2],
        ]);

        // 同一个契约不能因为访问方式不同而给不同答案
        $this->assertSame('from-query', $request->get('run', 'DEFAULT'));
        $this->assertSame('from-query', $request->all()['run']);
        $this->assertSame(2, $request->get('only_body'));
        $this->assertSame('DEFAULT', $request->get('missing', 'DEFAULT'));
        // 键序：query 在前（链接参数顺序与 URL 一致）
        $this->assertSame(['run', 'only_query', 'only_body'], array_keys($request->all()));
    }

    #[Test]
    public function requestAdapterIgnoresNonArrayBodyParams(): void
    {
        // JsonParser 配 asArray=false 时给的是对象，而 all() 声明 `: array`
        [$request] = $this->adapters([
            'queryParams' => ['a' => 1],
            'bodyParams' => (object) ['b' => 2],
        ]);

        $this->assertSame(['a' => 1], $request->all());
    }

    #[Test]
    public function requestAdapterFallsBackToQueryWhenBodyParsingThrows(): void
    {
        // 真实触发：Content-Type: application/json + 畸形 body（配了 parsers 时抛 BadRequestHttpException）。
        // 本包在每个被采样请求上都会调 all()，不能把「控制器本来不读 body」的请求打成 500。
        [$request] = $this->adapters([
            'queryParams' => ['a' => 1],
            'bodyParamsThrows' => new \RuntimeException('Invalid JSON data in request body'),
        ]);

        $this->assertSame(['a' => 1], $request->all());
        $this->assertSame(1, $request->get('a'));
    }

    public static function ipCases(): iterable
    {
        yield '无转发头 → REMOTE_ADDR' => [[], [], '203.0.113.9', '203.0.113.9'];
        yield '有转发头但没配 trustedHosts → 转发头被滤掉，仍是 REMOTE_ADDR' => [
            ['X-Forwarded-For' => '1.2.3.4'], [], '203.0.113.9', '203.0.113.9',
        ];
        yield '配了 trustedHosts → 认转发头' => [
            ['X-Forwarded-For' => '1.2.3.4'], ['203.0.113.9'], '203.0.113.9', '1.2.3.4',
        ];
        yield '多段转发头 → 右起第一个不可信地址' => [
            ['X-Forwarded-For' => '1.2.3.4, 5.6.7.8'], ['203.0.113.9'], '203.0.113.9', '5.6.7.8',
        ];
        yield '什么都拿不到 → 127.0.0.1' => [[], [], null, '127.0.0.1'];
    }

    /**
     * R-3：`getRealIp()` 任何分支都返回 string。语义是 Yii2 自己的（未配 trustedHosts 时
     * 转发头被 secureHeaders 滤掉），与其余适配器「无条件取 XFF 首段」刻意不同。
     */
    #[DataProvider('ipCases')]
    public function requestAdapterReturnsStringForIpOnEveryBranch(
        array $headers,
        array $trustedHosts,
        ?string $remoteAddr,
        string $expected
    ): void {
        [$request] = $this->adapters([
            'headers' => $headers,
            'trustedHosts' => $trustedHosts,
            'remoteAddr' => $remoteAddr,
        ]);

        $this->assertSame($expected, $request->getRealIp());
        $this->assertNotSame('', $request->getRealIp());
    }

    #[Test]
    public function requestAdapterDelegatesMethodToFramework(): void
    {
        [$request] = $this->adapters(['method' => 'POST']);
        $this->assertSame('POST', $request->method());
    }

    // ================= ResponseAdapter =================

    #[Test]
    public function responseAdapterIsFluentAndAppliesStateOnSend(): void
    {
        [$req, $response] = $this->adapters();

        $this->assertSame($response, $response->withStatus(200));
        $this->assertSame($response, $response->withBody('x'));

        $response->withStatus(403)
            ->withHeaders(['X-A' => '1', 'Content-Type' => 'text/plain'])
            ->withBody('403 Forbidden');

        ob_start();
        $sent = $response->send();
        ob_end_clean();

        $this->assertInstanceOf(WebResponse::class, $sent);
        $this->assertSame(403, $sent->getStatusCode());
        $this->assertSame('403 Forbidden', $sent->content);
        $this->assertSame('1', $sent->getHeaders()->get('X-A'));
        $this->assertSame('text/plain', $sent->getHeaders()->get('Content-Type'));
    }

    /**
     * 核心 1：`withBody()` 必须置 `FORMAT_RAW`。
     *
     * 对照组是同一次断言里的**另一半**：同一个 `yii\web\Response`，只把 format 换回默认
     * （html），Content-Type 立刻被 `HtmlResponseFormatter` 覆盖成 text/html —— 桩逐行照抄了
     * 真包的 `format()`（`web/HtmlResponseFormatter.php:37` 无条件 set），所以这一对断言
     * 真的有判别力，而不是「照自己的桩自说自话」。
     */
    #[Test]
    public function withBodyKeepsAssetContentTypeDespiteHtmlFormatter(): void
    {
        $file = $this->tempFile('css', 'body{}');

        [$req, $adapter] = $this->adapters();
        $adapter->file($file)->withHeaders(['Cache-Control' => 'public, max-age=86400']);
        $served = $adapter->send();

        ob_start();
        $served->send();
        ob_end_clean();

        $this->assertSame('text/css', $served->getHeaders()->get('Content-Type'), '静态资源的 Content-Type 被格式化器覆盖了');
        $this->assertSame('public, max-age=86400', $served->getHeaders()->get('Cache-Control'));

        // 对照组：同样的内容与头，但 format 保持默认（html）
        $control = new WebResponse();
        $control->content = 'body{}';
        $control->getHeaders()->set('Content-Type', 'text/css');
        ob_start();
        $control->send();
        ob_end_clean();

        $this->assertSame(
            'text/html; charset=UTF-8',
            $control->getHeaders()->get('Content-Type'),
            '对照组没有复现「格式化器覆盖头」——桩或断言已经失去判别力'
        );
    }

    /**
     * 核心 2：`withBody()` 必须把 `data` / `stream` 一起清掉。
     *
     * 应用只要在任何更早的监听器 / 引导代码里设过 `Yii::$app->response->data`，
     * `prepare()` 的 RAW 分支就会把 data 覆盖到 content，随后 `is_array($content)` 抛
     * InvalidArgumentException —— 报告页变 500。对照组同样在断言里跑一遍。
     */
    #[Test]
    public function withBodyNeutralisesPreExistingData(): void
    {
        [$req, $adapter] = $this->adapters();
        /** @var WebResponse $raw */
        $raw = $adapter->send();
        $raw->data = ['not' => 'a string'];
        $raw->stream = null;

        $adapter->withBody('<html>report</html>');
        ob_start();
        $raw->send();
        ob_end_clean();

        $this->assertSame('<html>report</html>', $raw->content);

        // 对照组：设过 data 又不置 FORMAT_RAW 的响应，prepare() 必炸
        $control = new WebResponse();
        $control->data = ['not' => 'a string'];
        // 真实类层级：yii\base\InvalidArgumentException extends InvalidParamException
        // extends \BadMethodCallException —— **不是** PHP 内置的 \InvalidArgumentException。
        // 断言写成内置那个的话，桩也照抄内置的才自洽，而真包上会跑出对不上的类。
        $this->expectException(\yii\base\InvalidArgumentException::class);
        $this->expectExceptionMessage('Response content must not be an array.');
        ob_start();
        try {
            $control->send();
        } finally {
            ob_end_clean();
        }
    }

    public static function fileMimeTypes(): iterable
    {
        yield 'css' => ['css', 'text/css'];
        yield 'js' => ['js', 'application/javascript'];
        yield 'png' => ['png', 'image/png'];
        yield 'svg' => ['svg', 'image/svg+xml'];
        yield '未知扩展名' => ['bin', 'application/octet-stream'];
    }

    #[DataProvider('fileMimeTypes')]
    public function testFileSetsMimeTypeFromTheCoreTable(string $ext, string $expected): void
    {
        $file = $this->tempFile($ext, 'payload');
        [$req, $adapter] = $this->adapters();

        $served = $adapter->file($file)->send();

        $this->assertSame($expected, $served->getHeaders()->get('Content-Type'));
        $this->assertSame('payload', $served->content);
    }

    #[Test]
    public function fileOnMissingPathIsEmpty404(): void
    {
        [$req, $adapter] = $this->adapters();

        $served = $adapter->file(sys_get_temp_dir() . '/xhprof-yii2-does-not-exist.css')->send();

        $this->assertSame(404, $served->getStatusCode());
        $this->assertSame('', $served->content);
    }

    #[Test]
    public function withHeadersAcceptsNonStringValues(): void
    {
        // HeaderCollection::set() 内部是 `(array) $value`；先 (string) 会把数组变成 "Array"
        // 并触发 PHP warning（phpunit failOnWarning 直接判失败）。
        [$req, $adapter] = $this->adapters();

        $served = $adapter->withHeaders(['Content-Length' => 12, 'X-Multi' => ['a', 'b']])->send();

        $this->assertSame('12', $served->getHeaders()->get('Content-Length'));
        $this->assertSame(['a', 'b'], $served->getHeaders()->get('X-Multi', null, false));
    }

    #[Test]
    public function withHeadersAfterFileStillApplies(): void
    {
        // R-5：StaticController::serve() 的调用就是 `file($realFile)->withHeaders([...])`
        $file = $this->tempFile('css', 'body{}');
        [$req, $adapter] = $this->adapters();

        $served = $adapter->file($file)->withHeaders(['Cache-Control' => 'public, max-age=60'])->send();

        $this->assertSame('public, max-age=60', $served->getHeaders()->get('Cache-Control'));
    }

    // ================= ConfigAdapter =================

    #[Test]
    public function configAdapterAnswersBothKeyShapes(): void
    {
        $config = new ConfigAdapter(['assets_url' => '/custom']);

        // R-6：整块（XhprofProfiler::bootstrap() 用）
        $whole = $config->get('xhprof');
        $this->assertIsArray($whole);
        $this->assertSame('/custom', $whole['assets_url']);
        $this->assertSame(['/xhprof'], $whole['ignore_url_arr']);
        // R-6：叶子（Xhprof::index() 用）
        $this->assertSame('/custom', $config->get('xhprof.assets_url'));
        $this->assertSame('/xhprof', $config->get('xhprof.ignore_url_arr')[0]);
        $this->assertNull($config->get('xhprof.nope'));
        $this->assertSame('d', $config->get('nope.nope', 'd'));
    }

    #[Test]
    public function configAdapterReplacesListKeysInsteadOfMergingThem(): void
    {
        // R-7：array_replace 而非 array_replace_recursive —— 用户给更短的列表时
        // 递归合并会让默认值的尾巴活下来（静默失配）
        $config = new ConfigAdapter(['ignore_url_arr' => []]);

        $this->assertSame([], $config->get('xhprof.ignore_url_arr'));
    }

    #[Test]
    public function shippedConfigFileHasNoRedisKey(): void
    {
        // redis 是运行时注入的连接参数，不属于那十个共用键（与 Yii3 同一条口径）
        $defaults = (array) require dirname(__DIR__, 3) . '/src/Yii2/config/xhprof.php';

        $this->assertArrayNotHasKey('redis', $defaults);
        $this->assertSame([
            'enable', 'time_limit', 'log_num', 'view_wtred', 'ignore_url_arr',
            'assets_url', 'auth_token', 'key_prefix', 'log_ttl', 'locale',
        ], array_keys($defaults));
    }

    // ================= LogAdapter =================

    #[Test]
    public function logAdapterForwardsToInjectedLogger(): void
    {
        $logger = new class implements \Psr\Log\LoggerInterface {
            /** @var array<int, string> */
            public array $records = [];

            public function error(string $message, array $context = []): void
            {
                $this->records[] = $message . ':' . json_encode($context);
            }
        };
        $adapter = new LogAdapter($logger);

        $this->assertInstanceOf(CoreLoggerInterface::class, $adapter);
        $adapter->error('boom', ['a' => 1]);

        $this->assertSame('boom:{"a":1}', $logger->records[0]);
    }

    #[Test]
    public function logAdapterWithoutLoggerWritesToErrorLog(): void
    {
        // 刻意不用 `Yii::error()`：log 组件没起来时它会建一个裸 Logger 把消息丢掉，
        // 而调用点恰恰是 XhprofProfiler::stop() 的兜底 catch。
        $path = $this->tempFile('log', '');
        $previous = ini_get('error_log');
        $previousLogErrors = ini_get('log_errors');
        ini_set('error_log', $path);
        ini_set('log_errors', '1');

        try {
            (new LogAdapter())->error('xhprof-yii2-fallback-marker');
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            ini_set('log_errors', $previousLogErrors === false ? '' : $previousLogErrors);
        }

        $this->assertStringContainsString('xhprof-yii2-fallback-marker', (string) file_get_contents($path));
    }

    // ================= RedisAdapter =================

    #[Test]
    public function redisAdapterSatisfiesCacheInterfaceWithoutConnectingOnConstruction(): void
    {
        $adapter = new RedisAdapter(['host' => '127.0.0.1']);

        $this->assertInstanceOf(CacheInterface::class, $adapter);
        $this->assertInstanceOf(RedisAdapter::class, $adapter);

        // 构造不建连：入口类在**每个**被采样请求上都会构造它，连不上不该拖垮业务
        $prop = new \ReflectionProperty(RedisAdapter::class, 'redis');
        $prop->setAccessible(true);
        $this->assertNull($prop->getValue($adapter));
    }

    #[Test]
    public function entryBuildsRedisCacheFromRedisSubConfig(): void
    {
        $app = $this->makeApp([], ['enable' => false, 'redis' => ['host' => '10.0.0.5', 'database' => 3]]);

        $this->runApp($app);

        $cache = CoreXhprof::getCache();
        $this->assertInstanceOf(RedisAdapter::class, $cache);
        $prop = new \ReflectionProperty(RedisAdapter::class, 'options');
        $prop->setAccessible(true);
        $options = $prop->getValue($cache);
        $this->assertSame('10.0.0.5', $options['host']);
        $this->assertSame(3, $options['database']);
    }

    // ================= XhprofBootstrap =================

    /**
     * 核心 4：控制台应用必须一条钩子都不挂。
     *
     * 判别力：`yii\console\Application` 不覆写 `run()`（桩也照抄这一点），所以 CLI 进程照样触发
     * `EVENT_BEFORE_REQUEST`；而它的请求组件**没有 `getUrl()`**。守卫一旦被删，下面的
     * `$app->run()` 会在 `new RequestAdapter($consoleRequest)` 处 TypeError。
     */
    #[Test]
    public function bootstrapAttachesHandlersOnlyOnWebApplications(): void
    {
        $entry = new XhprofBootstrap();
        $console = new \yii\console\Application(['bootstrap' => []]);
        $entry->bootstrap($console);

        $this->assertFalse($console->hasEventHandlers(BaseApplication::EVENT_BEFORE_REQUEST));
        $this->assertFalse($console->hasEventHandlers(BaseApplication::EVENT_AFTER_REQUEST));

        // 真跑一遍：控制台命令要能正常结束（守卫失守时这里是 TypeError，不是 200）
        ob_start();
        $console->run();
        $out = (string) ob_get_clean();
        $this->assertSame('', $out);

        $web = $this->makeApp();
        $this->assertTrue($web->hasEventHandlers(BaseApplication::EVENT_BEFORE_REQUEST));
        $this->assertTrue($web->hasEventHandlers(BaseApplication::EVENT_AFTER_REQUEST));
    }

    #[Test]
    public function bootstrapAcceptsArrayDefinitionWithConfigAndInjectedCache(): void
    {
        // 文档里的注册形状：数组定义里的 `config` / `cache` 由容器在构造之后按公有属性赋值
        //（`di/Container.php:389`）。这里断言的是**那条路真的通**：注入的 cache 收到了数据。
        $cache = new FakeCache();
        $app = $this->makeApp([], ['enable' => true], $cache);

        $this->runApp($app);

        $this->assertRunSaved($cache);
    }

    /**
     * 核心：短路位置。业务处理必须完全没被走到，且报告页/资源的两种响应头是字面量。
     */
    #[Test]
    public function servesReportPageAndNeverReachesBusiness(): void
    {
        $cache = new FakeCache();
        $app = $this->makeApp(['url' => '/xhprof?run=abc'], ['enable' => true], $cache);

        $result = $this->runApp($app);
        $response = $app->getResponse();

        $this->assertInstanceOf(ExitException::class, $result['thrown'], '报告页必须以 end() 终止请求');
        $this->assertFalse($app->handleRequestCalled, '报告页必须在业务处理之前短路');
        $this->assertTrue($response->isSent);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=UTF-8', $response->getHeaders()->get('Content-Type'));
        $this->assertSame('no-cache, private', $response->getHeaders()->get('Cache-Control'));
        $this->assertStringContainsString('<html', (string) $response->content);
        $this->assertSame($result['body'], (string) $response->content, '正文必须只 echo 一次');
        // 报告页**要读**缓存（列表就是读出来的），所以这里断言的是「一条 run 都没落」，
        // 而不是「缓存一次没碰」——后者是资源路径与 deny 路径的断言。
        $this->assertSame([], $cache->lRange('xhprof:run_id', 0, -1), '报告页不该采样/落库');
    }

    #[Test]
    public function deniesReportPageWhenTokenDoesNotMatch(): void
    {
        $cache = new FakeCache();
        $app = $this->makeApp(['url' => '/xhprof?token=wrong'], ['enable' => true, 'auth_token' => 'secret'], $cache);

        $result = $this->runApp($app);
        $response = $app->getResponse();

        $this->assertInstanceOf(ExitException::class, $result['thrown']);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('403 Forbidden', (string) $response->content, 'deny 的正文不能被报告页的 200 覆盖');
        $this->assertFalse($app->handleRequestCalled);
        $this->assertSame([], $cache->calls);
    }

    #[Test]
    public function servesAssetsThroughStaticController(): void
    {
        $cache = new FakeCache();
        $app = $this->makeApp(['url' => '/xhprof-assets/css/xhprof.css'], ['enable' => true], $cache);

        $result = $this->runApp($app);
        $response = $app->getResponse();

        $this->assertInstanceOf(ExitException::class, $result['thrown']);
        $this->assertFalse($app->handleRequestCalled, '资源路径必须在业务处理之前短路');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/css', $response->getHeaders()->get('Content-Type'));
        $this->assertSame('public, max-age=86400', $response->getHeaders()->get('Cache-Control'));
        $this->assertStringContainsString('--xp-bg: #f6f8fa', (string) $response->content);
        $this->assertSame([], $cache->calls);
    }

    public static function nearMissPaths(): iterable
    {
        yield '资源前缀的近邻' => ['/xhprof-assets-nope'];
        yield '报告路径的近邻' => ['/xhprof-other'];
        yield '大小写不同' => ['/XHPROF'];
        yield '只是包含' => ['/business/xhprof'];
    }

    #[DataProvider('nearMissPaths')]
    public function testDoesNotShortCircuitNearMissPaths(string $path): void
    {
        $cache = new FakeCache();
        $app = $this->makeApp(['url' => $path], ['enable' => false], $cache);

        $this->runApp($app);

        $this->assertTrue($app->handleRequestCalled, "$path 不该被短路");
    }

    public static function assetsUrlBoundaries(): iterable
    {
        yield '没有配置' => [null, '/xhprof-assets/css/xhprof.css', true];
        yield '配置 = 默认值' => ['/xhprof-assets', '/xhprof-assets/css/xhprof.css', true];
        yield '配置带尾斜杠' => ['/xhprof-assets/', '/xhprof-assets/css/xhprof.css', true];
        yield '自定义前缀' => ['/static/xhprof', '/static/xhprof/css/xhprof.css', true];
        yield '自定义前缀 + 尾斜杠' => ['/static/xhprof/', '/static/xhprof/css/xhprof.css', true];
        // 判别力来源：配了自定义前缀后**老路径不再被认**（否则同一份文件有两个 URL）
        yield '自定义前缀 + 老路径' => ['/static/xhprof', '/xhprof-assets/css/xhprof.css', false];
        // 判别力来自「前缀必须补上尾斜杠」：不归一化时 '/static/xhprof' 会把
        // '/static/xhprof-nope/x.css' 也当资源（两票都会说 asset），这条就是抓它的
        yield '自定义前缀 + 近邻路径' => ['/static/xhprof', '/static/xhprof-nope/x.css', false];
        yield 'CDN 绝对 URL' => ['https://cdn.test/xhprof-assets', '/xhprof-assets/css/xhprof.css', false];
        // 这一格以前两边分叉：入口类把空串回落成默认前缀（认下 /xhprof-assets/），
        // Core 对空串是「一个都不认」→ serve() 返回空 body 的 200，资源静默消失。
        yield '配置成空串' => ['', '/xhprof-assets/css/xhprof.css', false];
        yield '配置成 /' => ['/', '/css/xhprof.css', true];
    }

    /**
     * 两票制：入口类「认作资源」⇔ Core 的 `serve()` 真读得出文件。
     *
     * 第 2 票必须在第 1 票之后跑：`serve()` 读的是 bootstrap 写进 Core 的那份配置。
     */
    #[DataProvider('assetsUrlBoundaries')]
    public function testGuardAndServeAgreeOnWhichPathsAreAssets(?string $assetsUrl, string $path, bool $isAsset): void
    {
        $config = ['enable' => false];
        if ($assetsUrl !== null) {
            $config['assets_url'] = $assetsUrl;
        }
        $cache = new FakeCache();
        $app = $this->makeApp(['url' => $path], $config, $cache);

        $this->runApp($app);
        $this->assertSame(
            $isAsset,
            !$app->handleRequestCalled,
            "入口类对 $path 的判定（assets_url = " . var_export($assetsUrl, true) . '）'
        );

        $served = StaticController::serve(
            new RequestAdapter($app->getRequest()),
            new ResponseAdapter(new WebResponse())
        )->send();
        $this->assertSame(
            $isAsset,
            str_contains((string) $served->content, '--xp-bg: #f6f8fa'),
            "Core serve() 对 $path 的判定（assets_url = " . var_export($assetsUrl, true) . '）'
        );
    }

    #[Test]
    public function recordsWhenEnabledAndStopsOnAfterRequest(): void
    {
        $cache = new FakeCache();
        $app = $this->makeApp(['url' => '/business?probe=1'], ['enable' => true], $cache);

        $result = $this->runApp($app);

        $this->assertNull($result['thrown']);
        $this->assertTrue($app->handleRequestCalled);
        $this->assertSame('BUSINESS-OK', $result['body'], '业务响应原样发出');
        $this->assertRunSaved($cache);
        // 止点真的跑过了：再导一次采样只能是空
        $this->assertEmpty(xhprof_disable(), 'EVENT_AFTER_REQUEST 之后不该还有可导出的采样状态');
        // 请求日志的 request_uri 用 host()（不含端口）+ uri()；日志是 JSON，斜杠被转义成 \/
        $runIds = $cache->lRange('xhprof:run_id', 0, -1);
        $log = (string) $cache->get('xhprof:request_log:' . $runIds[0]);
        $this->assertStringContainsString('example.com', $log);
        $this->assertStringContainsString('business?probe=1', $log);
        $this->assertStringNotContainsString('example.com:8080', $log, 'host() 不该带端口（R-2）');
        $this->assertStringContainsString('"method":"GET"', $log);
    }

    #[Test]
    public function skipsWhenDisabled(): void
    {
        $cache = new FakeCache();
        $app = $this->makeApp(['url' => '/business'], ['enable' => false], $cache);

        $this->runApp($app);

        $this->assertTrue($app->handleRequestCalled);
        // 先看调用记录：lRange() 自己也会记一条，放到后面断言会把自己算进去
        $this->assertSame([], $cache->calls, 'disable 时不该碰缓存');
        $this->assertSame([], $cache->lRange('xhprof:run_id', 0, -1));
    }

    #[Test]
    public function bootstrapsConfigBeforeDecidingToSample(): void
    {
        $cache = new FakeCache();
        $app = $this->makeApp(['url' => '/business'], ['enable' => true, 'key_prefix' => 'proj', 'ignore_url_arr' => ['/zzz']], $cache);

        $this->runApp($app);

        // bootstrap 必须在 isEnabled() 之前落到 Core 的静态属性上
        $this->assertSame('proj', CoreXhprof::$key_prefix);
        $this->assertSame(['/zzz'], CoreXhprof::$ignore_url_arr);
        $this->assertInstanceOf(ConfigAdapter::class, CoreXhprof::getConfig());
        $this->assertInstanceOf(RequestInterface::class, CoreXhprof::getRequest());
        $this->assertInstanceOf(ResponseInterface::class, CoreXhprof::getResponse());
    }

    #[Test]
    public function repeatedTriggersProduceExactlyOneRunAndNoLeak(): void
    {
        $cache = new FakeCache();
        $app = $this->makeApp(['url' => '/business'], ['enable' => true], $cache);

        ob_start();
        $app->trigger(BaseApplication::EVENT_BEFORE_REQUEST);
        $app->trigger(BaseApplication::EVENT_BEFORE_REQUEST);   // 二次起表必须幂等
        $app->trigger(BaseApplication::EVENT_AFTER_REQUEST);
        $app->trigger(BaseApplication::EVENT_AFTER_REQUEST);    // 二次止点必须幂等
        $app->trigger(BaseApplication::EVENT_AFTER_REQUEST);
        ob_end_clean();

        // 二次 xhprof_disable() 会落一条**空** run（wt/mu 全 0、profile 是 'N;'），
        // 报告列表里凭空多一条——这正是 $stopped 守卫要挡的
        $this->assertRunSaved($cache);
        $this->assertSame([], $cache->lRange('xhprof:run_id', 0, -2), '只该有一条 run');
        $this->assertEmpty(xhprof_disable());
    }

    /**
     * 异常路径：`Application::run()` 只捕获 ExitException，业务抛出的其它异常会让
     * `EVENT_AFTER_REQUEST` **永不触发** —— 采样状态只能靠 shutdown 兜底收。
     *
     * 本用例证明的是「兜底是必需的」：异常穿出去时采样仍开着（`xhprof_disable()` 非空）。
     * 「兜底真的把数据落了库」由子进程用例证明（进程内看不见 shutdown 之后的写入）。
     */
    #[Test]
    public function businessThrowLeavesSamplingOpenUntilTheShutdownBackstop(): void
    {
        $cache = new FakeCache();
        $app = $this->makeApp(['url' => '/business'], ['enable' => true], $cache, true);

        $result = $this->runApp($app);

        $this->assertInstanceOf(\RuntimeException::class, $result['thrown']);
        $this->assertSame('handler boom', $result['thrown']->getMessage(), '异常必须原样穿出去');
        $this->assertSame([], $cache->lRange('xhprof:run_id', 0, -1), 'AFTER_REQUEST 没触发，此刻还没落库');
        $this->assertNotEmpty(xhprof_disable(), '采样必须还开着 —— 兜底是它唯一的止点');
    }

    /**
     * 核心 5：子进程里跑「AFTER_REQUEST 永不触发」的请求，父进程在子进程**完全退出后**
     * 读总账：兜底必须恰好落一条有数据的 run。
     *
     * 进程内测不到它（shutdown 回调要等进程结束），用文件当存储（FileCache）。
     */
    #[Test]
    public function shutdownBackstopSavesRunWhenAfterRequestNeverFires(): void
    {
        $root = dirname(__DIR__, 3);
        $store = (string) tempnam(sys_get_temp_dir(), 'xhprof-yii2-store-');
        $code = <<<'PHP'
require $argv[1] . '/vendor/autoload.php';
require $argv[1] . '/tests/Fixtures/Fakes.php';
require $argv[1] . '/tests/Stubs/Framework/Yii2.php';

use ErikWang2013\Xhprof\Tests\Fixtures\FileCache;
use ErikWang2013\Xhprof\Yii2\XhprofBootstrap;

$cache = new FileCache($argv[2]);

// 业务处理直接抛异常：真包 run() 只捕 ExitException，EVENT_AFTER_REQUEST 不会触发
$app = new class (['bootstrap' => [['class' => XhprofBootstrap::class, 'config' => ['enable' => true], 'cache' => $cache]],
    'request' => new yii\web\Request(['url' => '/business', 'hostInfo' => 'http://example.com', 'remoteAddr' => '203.0.113.9'])]) extends yii\web\Application {
    public function handleRequest($request)
    {
        throw new RuntimeException('handler boom');
    }
};

try {
    $app->run();
} catch (Throwable $e) {
    fwrite(STDERR, 'CAUGHT:' . get_class($e) . "\n");
}
PHP;

        $cmd = array_merge(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL', '-r', $code],
            [$root, $store]
        );
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'proc_open 失败');
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $this->assertSame('', $stdout);
        $this->assertStringContainsString('CAUGHT:RuntimeException', $stderr, "子进程输出异常：{$stderr}");

        // 子进程已完全退出（shutdown 回调都跑完）才读盘 —— 这是总账
        $cache = new FileCache($store);
        $runs = $cache->lRange('xhprof:run_id', 0, -1);
        unlink($store);

        $this->assertCount(1, $runs, 'shutdown 兜底必须恰好落一条 run');
        $serialized = (string) $cache->get('xhprof:xhprof_log:' . $runs[0]);
        $data = unserialize($serialized);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('main()', $data, '兜底落库的必须是有数据的 run');
    }

    private function tempFile(string $ext, string $content = 'body'): string
    {
        $path = sys_get_temp_dir() . "/xhprof-yii2-$ext-" . bin2hex(random_bytes(4)) . ".$ext";
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }
}
