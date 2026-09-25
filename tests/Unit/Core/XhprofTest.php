<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Core;

require_once __DIR__ . '/../../Fixtures/Fakes.php';

use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\I18n\I18n;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Hyperf\Adapter\RequestAdapter as HyperfRequestAdapter;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeConfig;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeRequest;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeResponse;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class XhprofTest extends TestCase
{
    private FakeCache $cache;
    private FakeConfig $config;
    private FakeRequest $request;
    private FakeResponse $response;
    private FakeLogger $logger;

    protected function setUp(): void
    {
        $this->cache = new FakeCache();
        $this->config = new FakeConfig(['xhprof' => []]);
        $this->request = new FakeRequest();
        $this->response = new FakeResponse();
        $this->logger = new FakeLogger();

        Xhprof::$time_limit = 0;
        Xhprof::$ignore_url_arr = ['/test'];
        Xhprof::$key_prefix = 'xhprof';
        Xhprof::$log_num = 1000;
        Xhprof::$view_wtred = 3;
        Xhprof::$ui_html = '';
        Xhprof::$symbol_lookup_url = '';

        Context::reset();
        ApplicationContext::reset();

        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);
    }

    protected function tearDown(): void
    {
        Context::reset();
        ApplicationContext::reset();
        Xhprof::$request = null;
        Xhprof::$response = null;
        Xhprof::$config = null;
        Xhprof::$cache = null;
        Xhprof::$logger = null;
    }

    private function seedRun(string $runId, array $data = []): void
    {
        $data = $data ?: [
            'main()' => ['wt' => 1000000, 'mu' => 1048576, 'ct' => 1, 'cpu' => 500],
            'foo()' => ['wt' => 500000, 'mu' => 524288, 'ct' => 2, 'cpu' => 250],
        ];
        $this->cache->set(Xhprof::$key_prefix . ':xhprof_log:' . $runId, serialize($data));
    }

    #[Test]
    public function bootstrapInjectsAdaptersIntoStatics(): void
    {
        $this->assertSame($this->request, Xhprof::$request);
        $this->assertSame($this->response, Xhprof::$response);
        $this->assertSame($this->config, Xhprof::$config);
        $this->assertSame($this->cache, Xhprof::$cache);
        $this->assertSame($this->logger, Xhprof::$logger);
    }

    #[Test]
    public function gettersReturnInjectedAdapters(): void
    {
        $this->assertInstanceOf(RequestInterface::class, Xhprof::getRequest());
        $this->assertInstanceOf(ResponseInterface::class, Xhprof::getResponse());
        $this->assertInstanceOf(ConfigInterface::class, Xhprof::getConfig());
        $this->assertInstanceOf(CacheInterface::class, Xhprof::getCache());
        $this->assertInstanceOf(LoggerInterface::class, Xhprof::getLogger());
        $this->assertSame($this->request, Xhprof::getRequest());
        $this->assertSame($this->config, Xhprof::getConfig());
    }

    #[Test]
    public function gettersReturnNullWhenNotBootstrapped(): void
    {
        // 未绑定适配器时全部 getter 返回 null（nullable 返回类型），而非 TypeError
        Context::reset();
        Xhprof::$request = null;
        Xhprof::$response = null;
        Xhprof::$config = null;
        Xhprof::$cache = null;
        Xhprof::$logger = null;

        $this->assertNull(Xhprof::getRequest());
        $this->assertNull(Xhprof::getResponse());
        $this->assertNull(Xhprof::getConfig());
        $this->assertNull(Xhprof::getCache());
        $this->assertNull(Xhprof::getLogger());
    }

    private function bindHyperfContainer(array $requestParams = []): void
    {
        $container = ApplicationContext::getContainer();
        $container->set(\Hyperf\HttpServer\Request::class, new \Hyperf\HttpServer\Request($requestParams));
        $container->set(\Hyperf\HttpServer\Response::class, new \Hyperf\HttpServer\Response());
        $container->set(\Hyperf\Contract\ConfigInterface::class, new \Hyperf\Config(['xhprof' => ['auth_token' => 'hyperf-secret']]));
        $container->set(\Hyperf\Redis\Redis::class, new \Hyperf\Redis\Redis());
        $container->set(\Psr\Log\LoggerInterface::class, new class () implements \Psr\Log\LoggerInterface {
            public function error(string $message, array $context = []): void
            {
            }
        });
    }

    #[Test]
    public function bootstrapWithoutArgumentsAutoDetectsHyperf(): void
    {
        $this->bindHyperfContainer();

        Xhprof::bootstrap();

        $this->assertInstanceOf(RequestInterface::class, Xhprof::getRequest());
        $this->assertInstanceOf(ResponseInterface::class, Xhprof::getResponse());
        $this->assertInstanceOf(ConfigInterface::class, Xhprof::getConfig());
        $this->assertInstanceOf(CacheInterface::class, Xhprof::getCache());
        $this->assertInstanceOf(LoggerInterface::class, Xhprof::getLogger());
        $this->assertInstanceOf(HyperfRequestAdapter::class, Xhprof::getRequest());
    }

    #[Test]
    public function bootstrapWithoutArgumentsAndMissingBindingsThrows(): void
    {
        // 容器为空（无任何绑定），autoDetect 命中 hyperf 分支后 get() 抛 RuntimeException
        $this->expectException(\RuntimeException::class);
        Xhprof::bootstrap();
    }

    #[Test]
    public function hyperfModeStoresAdaptersInCoroutineContext(): void
    {
        $this->bindHyperfContainer(['run' => 'abc123def456789']);

        Xhprof::bootstrap();

        $this->assertInstanceOf(RequestInterface::class, Context::get('xhprof.request'));
        $this->assertInstanceOf(ResponseInterface::class, Context::get('xhprof.response'));
        $this->assertInstanceOf(ConfigInterface::class, Context::get('xhprof.config'));
        $this->assertInstanceOf(CacheInterface::class, Context::get('xhprof.cache'));
        $this->assertInstanceOf(LoggerInterface::class, Context::get('xhprof.logger'));

        // hyperf 模式下 getters 走 Context
        Context::set('xhprof.request', $this->request);
        $this->assertSame($this->request, Xhprof::getRequest());
    }

    /**
     * Hyperf 模式下 Context 里**没有** `xhprof.config` 时不回落 `Xhprof::$config`，就是 null。
     *
     * 这是刻意的（见 Xhprof::getConfig() 的注释）：`Xhprof::$config` 在常驻 worker 里是
     * 跨协程共享量，谁最后 bootstrap 就写谁的。若缺键时回落，一个没走 bootstrap() 的
     * 执行路径会静默用上**另一个请求**的配置——assets_url、auth_token、log_ttl、
     * view_wtred 全是按请求来的，而报告页不会报错，只会照别人的配置渲染。缺键 = 未
     * bootstrap，调用方（Xhprof::index() / StaticController::uriPrefix()）都按 null 走默认。
     *
     * 反向钉：把 `?? self::$config` 加回 getConfig()，本用例立刻红。
     */
    #[Test]
    public function hyperfModeWithoutContextConfigReturnsNullInsteadOfTheSharedConfig(): void
    {
        // 闩是进程级、不可逆的（本文件前面的用例已经把它置真过），这里显式置真，
        // 让本用例单跑/全量跑一个结果——否则单跑时走静态分支、断言不同源。
        (new \ReflectionProperty(Xhprof::class, '_hyperf'))->setValue(null, true);
        Context::reset(); // 模拟「这条执行路径没走 bootstrap()」
        Xhprof::$config = new FakeConfig(['xhprof' => ['assets_url' => '/static/xhprof']]);

        $this->assertNull(Xhprof::getConfig(), 'Context 缺键必须返回 null，不得回落到跨协程共享的 Xhprof::$config');
    }

    #[Test]
    public function hyperfModeIndexEnforcesAuthToken(): void
    {
        $this->bindHyperfContainer();

        Xhprof::bootstrap();

        $result = Xhprof::index();
        $this->assertInstanceOf(\Hyperf\HttpServer\Response::class, $result);
        // 走 PSR-7 的 getStatusCode()：Hyperf 响应的 $status 不是 public
        // （桩按真包改成 protected 之后，`$result->status` 是 Error，不是断言失败）
        $this->assertSame(403, $result->getStatusCode());
    }

    /**
     * 闩开着、但当前协程没 bootstrap()：index() 必须**明确拒绝**，不能以一个 fatal 收场。
     *
     * 这种状态下 Context 里既没有 `xhprof.request` 也没有 `xhprof.config`：旧代码会静默跳过
     * 403 判定，再在 `$req->get('run')` 处 `Call to a member function get() on null`——
     * 同样是 500、同样不吐数据，但「读不出来」与「不需要读」在日志里长得一模一样。
     * 响应也不在 Context 里，`deny()` 走 `http_response_code()` 分支返回纯文本
     * （CLI 下状态码本身观测不到，所以断言钉在文案上：必须点明成因与「拒绝」）。
     */
    #[Test]
    public function hyperfModeIndexRefusesToRenderWhenTheCoroutineNeverBootstrapped(): void
    {
        (new \ReflectionProperty(Xhprof::class, '_hyperf'))->setValue(null, true);
        Context::reset();

        $result = Xhprof::index();

        $this->assertIsString($result, '没有 request 时必须走 deny()，不能是 fatal，也不能是报告页');
        $this->assertStringContainsString('no request adapter', $result);
        $this->assertStringNotContainsString('<html', $result, '拒绝渲染时不得输出报告页');

        // 状态码本身：上面那种状态下响应也不在 Context 里，deny() 走 http_response_code()
        // （CLI 下读不到）。绑一个响应再跑一次，读真实的 500——拒绝必须是 500，不是 200。
        Context::set('xhprof.response', $this->response);
        Xhprof::index();
        $this->assertSame(500, $this->response->status, '没有 request 时必须明确 500 拒绝，而不是渲染报告页');
    }

    /**
     * 守卫只看 request、**不看 config**：Hyperf 下 config 缺失是「这个协程没配 auth_token」的
     * 正常形态（`bootstrap()` 只给 request 不给 config 也一样合法），报告页照常出、不做鉴权。
     * 「读不到配置」与「读不到请求」是两件事——防止后来人把守卫顺手扩成 `$req === null || $cfg === null`。
     */
    #[Test]
    public function hyperfModeIndexStillRendersWhenOnlyTheConfigIsMissing(): void
    {
        (new \ReflectionProperty(Xhprof::class, '_hyperf'))->setValue(null, true);
        Context::reset();
        Context::set('xhprof.request', $this->request);
        Context::set('xhprof.response', $this->response);
        Context::set('xhprof.cache', $this->cache);
        Context::set('xhprof.logger', $this->logger);

        $result = Xhprof::index();

        $this->assertIsString($result);
        $this->assertStringContainsString('<html', $result, '缺 config 只是「没配 auth_token」，不该 500');
    }

    #[Test]
    public function indexReturns403ForbiddenWithoutToken(): void
    {
        $this->config = new FakeConfig(['xhprof' => ['auth_token' => 'secret']]);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        $result = Xhprof::index();

        $this->assertSame(403, $this->response->status);
        $this->assertSame('403 Forbidden', $this->response->body);
        $this->assertSame($this->response, $result);
    }

    #[Test]
    public function indexReturns403ForbiddenWithWrongToken(): void
    {
        $this->config = new FakeConfig(['xhprof' => ['auth_token' => 'secret']]);
        $this->request = new FakeRequest(['token' => 'wrong-token']);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        $result = Xhprof::index();

        $this->assertSame(403, $this->response->status);
        $this->assertSame('403 Forbidden', $this->response->body);
    }

    #[Test]
    public function indexAllowsRequestWithCorrectToken(): void
    {
        $runId = 'abc123def456789';
        $this->seedRun($runId);
        $this->config = new FakeConfig(['xhprof' => ['auth_token' => 'secret']]);
        $this->request = new FakeRequest(['run' => $runId, 'source' => 'xhprof_foo', 'token' => 'secret']);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        $result = Xhprof::index();

        $this->assertIsString($result);
        $this->assertStringContainsString('<html', $result);
        $this->assertStringContainsString($runId, $result);
        $this->assertNotSame(403, $this->response->status);
    }

    #[Test]
    public function indexReturns400ForInvalidRunId(): void
    {
        $this->request = new FakeRequest(['run' => '../../etc/passwd']);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        $result = Xhprof::index();

        $this->assertSame(400, $this->response->status);
        $this->assertSame('400 Bad Request', $this->response->body);
        $this->assertSame($this->response, $result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRunIdProvider(): iterable
    {
        yield 'path traversal' => ['../../etc/passwd'];
        yield 'too short' => ['abc123def456'];
        yield 'non-hex char' => ['abc123def45678g'];
        yield 'uppercase non-hex' => ['ABC123def456789'];
        yield 'too long' => [str_repeat('a', 33)];
        yield 'empty-ish whitespace' => ['abc123def456 7'];
        yield 'slash inside' => ['abc123def4/5678'];
    }

    #[Test]
    #[DataProvider('invalidRunIdProvider')]
    public function indexReturns400ForInvalidRunIdVariants(string $runId): void
    {
        $this->request = new FakeRequest(['run' => $runId]);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        $result = Xhprof::index();

        $this->assertSame(400, $this->response->status);
        $this->assertSame('400 Bad Request', $this->response->body);
    }

    #[Test]
    #[DataProvider('invalidRunIdProvider')]
    public function indexReturns400ForInvalidRun1Variant(string $runId): void
    {
        $this->request = new FakeRequest(['run1' => $runId]);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        Xhprof::index();

        $this->assertSame(400, $this->response->status);
    }

    #[Test]
    public function indexReturns400WhenAnyIdInCommaSeparatedRunIsInvalid(): void
    {
        $this->request = new FakeRequest(['run' => 'abc123def456789,zzz_not_hex']);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        Xhprof::index();

        $this->assertSame(400, $this->response->status);
        $this->assertSame('400 Bad Request', $this->response->body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSourceProvider(): iterable
    {
        yield 'path traversal' => ['../..'];
        yield 'uppercase' => ['XhprofFoo'];
        yield 'space' => ['xhprof foo'];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'empty string' => [''];
    }

    #[Test]
    #[DataProvider('invalidSourceProvider')]
    public function indexReturns400ForInvalidSource(string $source): void
    {
        $this->request = new FakeRequest(['source' => $source]);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        $result = Xhprof::index();

        $this->assertSame(400, $this->response->status);
        $this->assertSame('400 Bad Request', $this->response->body);
    }

    #[Test]
    public function indexReturnsHtmlForValidRunWithSeededData(): void
    {
        $runId = 'abc123def456789';
        $this->seedRun($runId);
        $this->request = new FakeRequest(['run' => $runId, 'source' => 'xhprof_foo']);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        $result = Xhprof::index();

        $this->assertIsString($result);
        $this->assertStringContainsString('<html', $result);
        $this->assertStringContainsString('</html>', $result);
        $this->assertStringContainsString('xhprof_foo', $result);
        $this->assertStringContainsString($runId, $result);
        $this->assertSame(200, $this->response->status);
        $this->assertContains('get:xhprof:xhprof_log:' . $runId, $this->cache->calls);
    }

    #[Test]
    public function indexListsRunsWhenNoRunParamGiven(): void
    {
        $result = Xhprof::index();

        $this->assertIsString($result);
        $this->assertStringContainsString('<html', $result);
        $this->assertStringContainsString('xhprof-assets', $result);
    }

    #[Test]
    public function indexUsesConfiguredAssetsUrl(): void
    {
        $this->config = new FakeConfig(['xhprof' => ['assets_url' => '/custom-assets']]);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        $result = Xhprof::index();

        $this->assertStringContainsString('/custom-assets', $result);
        $this->assertStringNotContainsString('/xhprof-assets', $result);
    }

    #[Test]
    public function indexFallsBackToUiHtmlStaticWhenNoAssetsUrl(): void
    {
        Xhprof::$ui_html = '/my-assets';
        $result = Xhprof::index();

        $this->assertStringContainsString('/my-assets', $result);
        $this->assertStringNotContainsString('/xhprof-assets', $result);
    }

    #[Test]
    public function indexUsesCustomKeyPrefixForCacheLookup(): void
    {
        $runId = 'abc123def456789';
        // key_prefix 必须经 bootstrap 从配置写入（直接改静态会被 bootstrap 覆盖）
        $this->config = new FakeConfig(['xhprof' => ['key_prefix' => 'myxhprof']]);
        $this->cache->set('myxhprof:xhprof_log:' . $runId, serialize([
            'main()' => ['wt' => 1000000, 'mu' => 1048576, 'ct' => 1, 'cpu' => 500],
            'foo()' => ['wt' => 500000, 'mu' => 524288, 'ct' => 2, 'cpu' => 250],
        ]));
        $this->request = new FakeRequest(['run' => $runId, 'source' => 'xhprof_foo']);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        Xhprof::index();

        $this->assertContains('get:myxhprof:xhprof_log:' . $runId, $this->cache->calls);
    }

    #[Test]
    public function indexWithSymbolParamRendersHtml(): void
    {
        $runId = 'abc123def456789';
        $this->seedRun($runId);
        $this->request = new FakeRequest(['run' => $runId, 'source' => 'xhprof_foo', 'symbol' => 'foo()']);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        $result = Xhprof::index();

        $this->assertIsString($result);
        $this->assertStringContainsString('<html', $result);
    }

    #[Test]
    public function denyWithoutResponseBoundReturnsBody(): void
    {
        // 无 Response 绑定时 deny() 走 http_response_code 分支，返回 body 字符串
        $this->config = new FakeConfig(['xhprof' => ['auth_token' => 'secret']]);
        Xhprof::$response = null; // bootstrap 仅在参数非 null 时覆盖，手动清空以模拟无 Response 绑定
        Xhprof::bootstrap($this->request, null, $this->config, $this->cache, $this->logger);

        $result = Xhprof::index();
        $this->assertSame('403 Forbidden', $result);
        $this->assertSame(403, http_response_code());
    }

    #[Test]
    public function denyForBadRequestWithoutResponseBoundReturnsBody(): void
    {
        Xhprof::$response = null;
        $this->request = new FakeRequest(['run' => '../../etc/passwd']);
        Xhprof::bootstrap($this->request, null, $this->config, $this->cache, $this->logger);

        $result = Xhprof::index();
        $this->assertSame('400 Bad Request', $result);
        $this->assertSame(400, http_response_code());
    }

    #[Test]
    public function indexWithArrayRunValueDenied(): void
    {
        // run 为数组（?run[]=x）时直接 400 拒绝，而非进入 explode 崩溃
        $this->request = new FakeRequest(['run' => ['abc123def456789']]);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        $result = Xhprof::index();
        $this->assertSame('400 Bad Request', $result->body);
        $this->assertSame(400, $result->status);
    }

    #[Test]
    public function indexWithTwoRunsAndNoWtsRendersHtml(): void
    {
        // run 含逗号（多 run 聚合）且未传 wts：count(null) 守卫后正常渲染聚合报告
        $this->seedRun('abc123def456789');
        $this->seedRun('abc123def45678a');
        $this->request = new FakeRequest(['run' => 'abc123def456789,abc123def45678a', 'source' => 'xhprof_foo']);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        $result = Xhprof::index();
        $this->assertIsString($result);
        $this->assertStringContainsString('<html', $result);
    }

    #[Test]
    public function indexWithUnknownRunIdRendersGracefully(): void
    {
        // 合法 run_id 但缓存无数据：get_run 返回 false，报告页优雅降级而非崩溃
        $this->request = new FakeRequest(['run' => 'abc123def456789', 'source' => 'xhprof_foo']);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        $result = Xhprof::index();
        $this->assertIsString($result);
        $this->assertStringContainsString('<html', $result);
    }

    #[Test]
    public function xhprofStartStopRunsWithoutError(): void
    {
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('ext-xhprof 未加载');
        }

        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        Xhprof::xhprofStart();
        $runId = Xhprof::xhprofStop();

        $this->assertNull($runId); // stop() 无返回值
        $this->assertNotEmpty($this->cache->calls);
        $this->assertStringContainsString('xhprof:run_id', implode(',', $this->cache->calls));
    }

    // ---------------- 报告页内部链接：参数必须随链接传播 ----------------

    /** 造一条 run 的日志与列表项，使列表页与单 run 页都渲染得出来 */
    private function seedRunAndLog(string $runId): void
    {
        $this->seedRun($runId);
        $this->cache->lPush(Xhprof::$key_prefix . ':run_id', $runId);
        $this->cache->set(Xhprof::$key_prefix . ':request_log:' . $runId, json_encode([
            'request_uri' => '/order?x=1', 'method' => 'GET', 'wt' => 0.8, 'mu' => 2.0,
            'ip' => '6.6.6.6', 'create_time' => 1700000000,
        ]));
    }

    /**
     * 页面里指向报告页自身的链接（跳过外链与空串）。
     *
     * 空表要当失败处理：一旦选择器失灵，下面的「每条都带 token」会平凡成立。
     *
     * @return list<string>
     */
    private function internalHrefs(string $html): array
    {
        preg_match_all('/href="([^"]*)"/', $html, $m);
        $hrefs = array_values(array_filter(
            $m[1],
            static fn (string $h): bool => $h !== '' && !str_starts_with($h, 'http')
        ));
        $this->assertNotEmpty($hrefs, '页面里一个内部链接都没解析到，夹具或正则已失效');
        return $hrefs;
    }

    /**
     * 配了 `auth_token` 时，页面里的每个内部链接都必须带着 token。
     *
     * 这是一条**可用性**断言，不是风格问题：`index()` 用 `hash_equals` 校验 token，
     * 而 run 列表的链接此前把查询串写死了、首页/品牌链接干脆不带 —— 结果是
     * 「带 token 打开首页 200，点其中任何一个 run 都是 403」。修复前本用例会红。
     */
    #[Test]
    public function everyInternalLinkCarriesTheToken(): void
    {
        $runId = 'abc123def456789';
        $this->seedRunAndLog($runId);
        $this->config = new FakeConfig(['xhprof' => ['auth_token' => 'tok']]);
        $this->request = new FakeRequest(['token' => 'tok'], ['uri' => '/xhprof']);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        // 列表页：里面有那条 run 的链接，也有首页/品牌链接
        $list = (string) Xhprof::index();
        $this->assertStringContainsString('run=' . $runId, $list, '列表页没渲染出 run 链接，夹具失效');

        foreach ($this->internalHrefs($list) as $href) {
            $this->assertStringContainsString('token=tok', $href, "列表页的链接 {$href} 丢了 token → 点进去就是 403");
        }

        // 单 run 页：导航（首页/品牌）与页内链接同样都要带 token
        $this->request = new FakeRequest(['run' => $runId, 'all' => 1, 'token' => 'tok'], ['uri' => '/xhprof']);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);
        $this->assertStringContainsString('token=tok', (string) Xhprof::index());
    }

    /** 语言参数 `?lang=` 也要随链接传播，否则点一下导航就退回浏览器语言 */
    #[Test]
    public function everyInternalLinkCarriesTheLanguage(): void
    {
        $runId = 'abc123def456789';
        $this->seedRunAndLog($runId);
        $this->request = new FakeRequest(['run' => $runId, 'all' => 1, 'lang' => 'ko'], ['uri' => '/xhprof']);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        $html = (string) Xhprof::index();
        $this->assertStringContainsString('<html lang="ko">', $html);

        foreach ($this->internalHrefs($html) as $href) {
            $this->assertStringContainsString('lang=ko', $href, "链接 {$href} 丢了 lang → 点进去语言就变了");
        }
    }

    /**
     * 内部链接是**相对** URL：这样端口不会丢，也不再读 `X-Forwarded-Proto`。
     *
     * 旧实现拼的是 `$http . '//' . host() . uri()`：`host()` 契约不含端口，
     * 非 80/443 部署会指到错 origin；而 `$http` 来自一个**未校验**的请求头
     * （实测 `X-Forwarded-Proto: javascript:alert(1)` 能整段落进 href）。
     */
    #[Test]
    public function internalLinksAreRelativeAndIgnoreTheForwardedProtoHeader(): void
    {
        $runId = 'abc123def456789';
        $this->seedRunAndLog($runId);
        $this->request = new FakeRequest([], [
            'uri' => '/xhprof',
            'headers' => ['x-forwarded-proto' => 'javascript:alert(1)'],
        ]);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        $hrefs = $this->internalHrefs((string) Xhprof::index());
        $this->assertStringContainsString('run=' . $runId, implode(' ', $hrefs), '列表页没渲染出 run 链接，夹具失效');

        foreach ($hrefs as $href) {
            $this->assertStringStartsWith('/xhprof', $href, "链接 {$href} 不是相对 URL");
            $this->assertStringNotContainsString('javascript:', $href, "未校验的 X-Forwarded-Proto 又进 href 了");
        }
    }

    // ---------------- 查询参数的类型校验 ----------------

    /** @return iterable<string, array{0:string}> */
    public static function arrayValuedQueryParamProvider(): iterable
    {
        yield 'sort[]'   => ['sort'];
        yield 'symbol[]' => ['symbol'];
        yield 'wts[]'    => ['wts'];
    }

    /**
     * `?sort[]=wt` 这类数组形态必须是 400，而不是 500。
     *
     * 三个参数以前被原样透传，最后在 `isset($arr[$array])` / `explode(",", $array)`
     * 抛 TypeError：`sort` 传非法**字符串**会被优雅处理（回落 wt + 记日志），
     * 数组形态却是未捕获的 500 —— 同一个入口两种失败形态，没有理由。
     */
    #[Test]
    #[DataProvider('arrayValuedQueryParamProvider')]
    public function indexReturns400WhenAQueryParamArrivesAsAnArray(string $param): void
    {
        $runId = 'abc123def456789';
        $this->seedRun($runId);
        $this->request = new FakeRequest([
            'run' => $runId, 'source' => 'xhprof_foo', $param => ['wt'],
        ]);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        $result = Xhprof::index();

        $this->assertSame(400, $this->response->status, "{$param} 传数组应当是 400");
        $this->assertSame('400 Bad Request', $this->response->body);
        $this->assertNotSame(500, $this->response->status);
        $this->assertTrue($result !== null);
    }

    // ---------------- 空态：过期的 run 要说人话 ----------------

    /**
     * run 记录过期（默认 TTL 7 天）时，页面给一句说明，而不是只剩一条导航条。
     *
     * 旧行为是 `return $data;`（$data 里只有导航），页面看起来像「报告坏了」。
     */
    #[Test]
    public function expiredRunRendersAnExplanationInsteadOfABlankPage(): void
    {
        // 语言是静态状态，可能被别的测试类留下过——这条要断言**中文**文案，先钉死
        I18n::setLocale('zh_CN');
        // run_id 格式合法，但缓存里没有（模拟过期）
        $this->request = new FakeRequest(['run' => 'abc123def456789', 'source' => 'xhprof_foo']);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        $html = (string) Xhprof::index();

        $this->assertStringContainsString('<html lang="zh-CN">', $html, '前提：这一页是中文');
        $this->assertStringContainsString('性能数据已不存在', $html, '过期页必须有说明，而不是只剩导航条');
        $this->assertStringContainsString('xp-card-note', $html);
        // 说明之外还得有导航（页面结构不退化）
        $this->assertStringContainsString('xp-nav', $html);
        // 「什么都不说」与「说了」的区别就是这条：修复前这里只有导航
        $this->assertGreaterThan(80, mb_strlen(strip_tags($html)), '页面不能只剩一条导航条');
    }

    /**
     * 请求记录表的两个 class 在**同一个** <table> 上。
     *
     * 这条钉的是一个 CSS 缺陷的根因：`xp-runs-table` 与 `xp-table` 同元素，
     * 所以样式表里不能写后代选择器 `.xp-runs-table .xp-table th`（页面里不存在
     * 祖孙关系，列宽规则会全部空转，而且看不出错）。
     */
    #[Test]
    public function runsTableCarriesBothClassesOnTheSameElement(): void
    {
        $runId = 'abc123def456789';
        $this->seedRunAndLog($runId);

        $html = (string) Xhprof::index();

        $this->assertStringContainsString('class="xp-table xp-runs-table"', $html);
        // 样式表里不许再出现后代形态（那是修掉的那条死规则）
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/src/html/css/xhprof.css');
        $this->assertStringNotContainsString('.xp-runs-table .xp-table', $css);
    }
}
