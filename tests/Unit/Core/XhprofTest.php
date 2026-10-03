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
use ErikWang2013\Xhprof\Tests\Support\XhprofStaticsSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class XhprofTest extends TestCase
{
    use XhprofStaticsSnapshot;

    private FakeCache $cache;
    private FakeConfig $config;
    private FakeRequest $request;
    private FakeResponse $response;
    private FakeLogger $logger;

    /** setUp 里会被改写的那 12 个静态量的开工前快照 */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->cache = new FakeCache();
        $this->config = new FakeConfig(['xhprof' => []]);
        $this->request = new FakeRequest();
        $this->response = new FakeResponse();
        $this->logger = new FakeLogger();

        // 先照单全收再改：tearDown 必须**还原**而不是清空，否则本类留下的
        // `$ignore_url_arr` / `$ui_html` 等会漏给后面的用例 —— Adapter 侧
        // （DrupalTest / WebmanTest）的前置条件断言读的就是这些量，落成假红。
        // 之前只清 5 个适配器，同一个进程里 Core 先跑就会打翻它们。
        $this->saved = $this->snapshotXhprofStatics();

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
        $this->restoreXhprofStatics($this->saved);
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

    /**
     * `xhprof.symbol_lookup_url` 由 XhprofProfiler::bootstrap() 写进
     * Xhprof::$symbol_lookup_url（模板由 XhprofDisplay::print_source_link() 消费）。
     *
     * 两个方向都钉：配了非空字符串 → 原样写入（不做 URL 校验，拼 `?symbol=` 是消费方
     * 的事）；没配 → 覆盖成空串，而不是保留上一次的值 / 塞进 null；形态写错（数组）
     * 同样落空串——`(string)` 强转数组会立 "Array to string conversion" warning，
     * failOnWarning 下这条就红了。
     */
    #[Test]
    public function bootstrapMapsSymbolLookupUrlIntoTheStatic(): void
    {
        $config = new FakeConfig(['xhprof' => ['symbol_lookup_url' => 'https://git.example.com/blob/main/{fn}']]);
        Xhprof::bootstrap($this->request, $this->response, $config, $this->cache, $this->logger);
        $this->assertSame(
            'https://git.example.com/blob/main/{fn}',
            Xhprof::$symbol_lookup_url,
            '配了值必须原样写入静态量'
        );

        // 先污染再 bootstrap：未配时必须是「覆盖成空串」，而不是保留旧值
        Xhprof::$symbol_lookup_url = 'https://stale.example.com';
        Xhprof::bootstrap($this->request, $this->response, new FakeConfig(['xhprof' => []]), $this->cache, $this->logger);
        $this->assertSame('', Xhprof::$symbol_lookup_url, '未配 = 空串（print_source_link 对空串不加链接）');

        Xhprof::bootstrap(
            $this->request,
            $this->response,
            new FakeConfig(['xhprof' => ['symbol_lookup_url' => ['not', 'a', 'string']]]),
            $this->cache,
            $this->logger
        );
        $this->assertSame('', Xhprof::$symbol_lookup_url, '形态写错（数组）也落空串，且不得立 warning');
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

    // ---------------- token 形态校验 & 未配鉴权的留痕 ----------------

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function arrayTokenProvider(): iterable
    {
        yield '已配 auth_token' => [['xhprof' => ['auth_token' => 'secret']]];
        yield '未配 auth_token' => [['xhprof' => []]];
    }

    /**
     * `?token[]=x` 必须是 400，不是 500、不是 403。
     *
     * 到达 hash_equals 之前 `(string) ['x']` 立 "Array to string conversion" warning：
     * 升异常的宿主上 403 变 500，不升的宿主上每次请求一条日志噪音。本用例把 warning
     * 当场升成异常复现那条路（与生产宿主的 error_reporting 口径无关——我们要的是
     * **根本不产生这条 warning**）。两种配置（配了/没配 auth_token）都要走形态校验。
     */
    #[Test]
    #[DataProvider('arrayTokenProvider')]
    public function indexReturns400WhenTokenArrivesAsAnArray(array $config): void
    {
        $this->config = new FakeConfig($config);
        $this->request = new FakeRequest(['token' => ['x']]);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        });
        try {
            Xhprof::index();
        } catch (\ErrorException $e) {
            $this->fail('数组 token 不该走到 (string) 强转：' . $e->getMessage());
        } finally {
            restore_error_handler();
        }

        $this->assertSame(400, $this->response->status, '数组 token 是坏请求（400），不是 403/500');
        $this->assertSame('400 Bad Request', $this->response->body);
        $this->assertSame([], $this->logger->errors, '形态拒绝不该顺带写日志');
    }

    /**
     * 未配 auth_token 时默认不鉴权（拍板行为，不改），但每请求留一条说明性日志。
     *
     * 「报告页对任何人可读」是个安全相关的默认值，静默等于没人会去配它；
     * 一条日志既留痕又不至于刷屏（一次 index() 只走到这个分支一次）。
     */
    #[Test]
    public function unconfiguredAuthTokenLeavesExactlyOneWarningInTheLog(): void
    {
        $this->request = new FakeRequest([], ['uri' => '/xhprof']);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);
        $this->assertNull($this->config->get('xhprof.auth_token', null), '前提：这份配置没配 auth_token');

        Xhprof::index();

        $this->assertCount(1, $this->logger->errors, '未配 token 必须恰好留一条日志（每请求一条，不刷屏）');
        $this->assertStringContainsString('auth_token', $this->logger->errors[0]);
        $this->assertStringContainsString('without authentication', $this->logger->errors[0]);
        // 页面本身照常渲染：留痕不是行为改变
        $this->assertSame(200, $this->response->status);
    }

    /** 配了 auth_token（含校验失败那支）都不该出现「未配」的告警 —— 否则等于误导运维去查配置 */
    #[Test]
    public function configuredAuthTokenDoesNotLogTheUnconfiguredWarning(): void
    {
        $this->config = new FakeConfig(['xhprof' => ['auth_token' => 'secret']]);

        // 校验失败：403，且日志里没有「未配」告警
        $this->request = new FakeRequest(['token' => 'wrong-token']);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);
        Xhprof::index();
        $this->assertSame(403, $this->response->status);
        $this->assertSame([], $this->logger->errors);

        // 校验通过：正常渲染，同样没有那条告警
        $this->request = new FakeRequest(['token' => 'secret'], ['uri' => '/xhprof']);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);
        $this->assertIsString(Xhprof::index());
        $this->assertSame([], $this->logger->errors);
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

    /**
     * 页面里每个内部链接的查询串都必须转义（裸 `&` 会被 HTML 解析器当实体起头）。
     *
     * 这不是风格问题：`&copy_x=1` 在浏览器里解出来是 `©_x=1` —— 参数**改名**，
     * 于是相邻参数的值被污染（配了 auth_token 时点一下链接就 403，且现象是
     * 「token 明明在 URL 里却 403」，极难归因）。Display 层原先有十几处手拼
     * `"$base_path?" . http_build_query(...)`，不经过 report_url() 的转义——
     * 本用例把「页面里所有内部链接」当整体钉住，新增链接点漏掉转义同样会红。
     *
     * 反例参数 `copy_x` 是**判别性输入**：没有转义时它必然变形成 `©_x`。
     */
    #[Test]
    public function everyInternalLinkEscapesAmpersandsInTheQueryString(): void
    {
        $runId = 'abc123def456789';
        $this->seedRunAndLog($runId);
        // copy_x 会与 run/source 相邻，制造出「参数之间必须有 & 分隔」的局面
        $this->request = new FakeRequest(['run' => $runId, 'copy_x' => '1'], ['uri' => '/xhprof']);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        $html = (string) Xhprof::index();
        $hrefs = $this->internalHrefs($html);
        $joined = implode(' ', $hrefs);

        // 夹具判别性：页面里必须真的出现「已转义的 &」，否则下面的断言会空转
        $this->assertStringContainsString('&amp;', $joined, '页面里没有需要转义的 & —— 夹具失效，本用例会空转');

        foreach ($hrefs as $href) {
            // 先摘掉合法实体，再看有没有裸 &
            $stripped = preg_replace('/&(?:amp|lt|gt|quot|#\d+|#x[0-9a-fA-F]+);/', '', $href);
            $this->assertStringNotContainsString(
                '&',
                (string) $stripped,
                "链接 {$href} 里有裸 &：浏览器会把后面的参数名当实体吃掉（copy_x → ©_x）"
            );
        }

        // 判别性输入的具体形态：copy_x 必须以 `&amp;copy_x=` 出现，且不得是 `&copy_x=`
        $this->assertStringContainsString('&amp;copy_x=1', $joined, 'copy_x 参数没跟着链接走，夹具失效');
        $this->assertStringNotContainsString('&copy_x=', $joined, '裸 &copy_x 会被浏览器解成 ©_x');
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
     * 缓存里的值**损坏**（写了一半 / 被手工改过）时，与「过期」同一条降级路径：
     * 空态说明 + 一条日志，而不是让 unserialize 的 warning 顺着渲染路径漏给宿主。
     *
     * 漏出去的两种下场都不可接受：warning 升异常的宿主（Laravel/Symfony 的默认行为）
     * 上是一条坏记录把整页打成 500；不升的宿主上则是每次渲染一条无人能归因的噪音。
     * 这是**入口级**验证：单测在 XHProfRunsDefaultTest，这里钉的是报告页真的没崩。
     */
    #[Test]
    public function corruptRunDataRendersEmptyStateWithoutWarningEscape(): void
    {
        I18n::setLocale('zh_CN');
        $runId = 'abc123def456789';
        // 截断的序列化数组：正是「写了半截」的样子
        $this->cache->set(Xhprof::$key_prefix . ':xhprof_log:' . $runId, 'a:1:{i:0;s:3:"trunc');
        $this->request = new FakeRequest(['run' => $runId, 'source' => 'xhprof_foo']);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        // PHPUnit 的 error_reporting 不含 E_WARNING（见 XHProfRunsDefaultTest 同名说明），
        // 显式抬到 E_ALL 才是严格宿主的样子；`@` 抑制期间 error_reporting() 会收窄，按契约放过。
        $previousReporting = error_reporting(E_ALL);
        set_error_handler(static function (int $errno, string $errstr): bool {
            if (!(error_reporting() & $errno)) {
                return false;
            }
            throw new \ErrorException($errstr, 0, $errno);
        });
        try {
            $result = Xhprof::index();
        } catch (\ErrorException $e) {
            $this->fail('unserialize 的 warning 漏到了宿主：' . $e->getMessage());
        } finally {
            restore_error_handler();
            error_reporting($previousReporting);
        }

        $this->assertIsString($result);
        $this->assertStringContainsString('<html', $result);
        $this->assertStringContainsString('性能数据已不存在', $result, '损坏值必须走与过期相同的空态，而不是白屏');
        $this->assertStringContainsString('xp-card-note', $result);
        $this->assertStringContainsString(
            'unserialize failed for Run ID: ' . $runId,
            implode("\n", $this->logger->errors),
            '降级必须留一条可归因的日志（未配 auth_token 的留痕也在同一份日志里，故整份比对）'
        );
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

    // ---------------- HTTP Basic 鉴权（与 ?token= 并行） ----------------

    private static function basicCredentials(string $user, string $pass): string
    {
        return 'Basic ' . base64_encode($user . ':' . $pass);
    }

    /** 重建 request/config 适配器并 bootstrap（每个用例一份全新的适配器，免得互相串味）。 */
    private function bootWith(array $xhprofConfig, array $params = [], array $options = []): void
    {
        $this->config = new FakeConfig(['xhprof' => $xhprofConfig]);
        $this->request = new FakeRequest($params, $options);
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);
    }

    #[Test]
    public function basicAuthAllowsCorrectCredentialsWithoutToken(): void
    {
        $this->bootWith(
            ['auth_basic' => 'alice:s3cret'],
            [],
            ['headers' => ['Authorization' => self::basicCredentials('alice', 's3cret')]]
        );

        $result = Xhprof::index();

        $this->assertIsString($result);
        $this->assertStringContainsString('<html', $result);
        $this->assertSame(200, $this->response->status);
        $this->assertSame([], $this->logger->errors, '配了凭据就不该有「裸奔」告警');
    }

    #[Test]
    public function basicAuthRejectsMissingCredentialsWith401AndChallenge(): void
    {
        $this->bootWith(['auth_basic' => 'alice:s3cret']);

        $result = Xhprof::index();

        $this->assertSame(401, $this->response->status, '配了 Basic 且没凭据时必须 401（不是 403）');
        $this->assertSame('401 Unauthorized', $this->response->body);
        $this->assertSame(
            'Basic realm="xhprof"',
            $this->response->headers['WWW-Authenticate'] ?? null,
            '没有 WWW-Authenticate 浏览器永远不会弹凭据框'
        );
        $this->assertSame($this->response, $result);
    }

    #[Test]
    public function basicAuthRejectsWrongPasswordWith401(): void
    {
        $this->bootWith(
            ['auth_basic' => 'alice:s3cret'],
            [],
            ['headers' => ['Authorization' => self::basicCredentials('alice', 'wrong')]]
        );

        Xhprof::index();

        $this->assertSame(401, $this->response->status);
        $this->assertArrayHasKey('WWW-Authenticate', $this->response->headers);
    }

    /**
     * 凭据按**第一个**冒号切两段（`explode(':', $s, 2)`），密码里的冒号保留。
     *
     * 判别性输入：凭据里有 3 个冒号。若实现按全部冒号切开再断言段数，这条会 401。
     */
    #[Test]
    public function basicAuthKeepsColonsInsideThePassword(): void
    {
        $this->bootWith(
            ['auth_basic' => 'alice:a:b:c'],
            [],
            ['headers' => ['Authorization' => self::basicCredentials('alice', 'a:b:c')]]
        );
        $result = Xhprof::index();
        // 报告页 HTML 是**返回**的字符串（适配器负责 echo），只有 deny()/respond() 才写
        // response。断言先钉类型：鉴权失败时返回的是 response 对象，这条会干净地红，
        // 而不是 (string) 强转抛 TypeError 把断言淹掉。
        $this->assertIsString($result, '应当渲染报告页，而不是被闸门拒绝');
        $this->assertStringContainsString('<html', $result);

        // 密码比配置少一段：必须拒绝（比较的是完整剩余串，不做前缀匹配）
        $this->response = new FakeResponse();
        $this->bootWith(
            ['auth_basic' => 'alice:a:b:c'],
            [],
            ['headers' => ['Authorization' => self::basicCredentials('alice', 'a:b')]]
        );
        Xhprof::index();
        $this->assertSame(401, $this->response->status, '密码少一段必须拒绝');
    }

    #[Test]
    public function basicAuthSchemeIsCaseInsensitiveAndBase64IsStrict(): void
    {
        // scheme 大小写不敏感（RFC 7617）
        $this->bootWith(
            ['auth_basic' => 'alice:s3cret'],
            [],
            ['headers' => ['Authorization' => 'basic ' . base64_encode('alice:s3cret')]]
        );
        $result = Xhprof::index();
        // 报告页 HTML 是**返回**的字符串（适配器负责 echo），只有 deny()/respond() 才写
        // response。断言先钉类型：鉴权失败时返回的是 response 对象，这条会干净地红，
        // 而不是 (string) 强转抛 TypeError 把断言淹掉。
        $this->assertIsString($result, '应当渲染报告页，而不是被闸门拒绝');
        $this->assertStringContainsString('<html', $result);

        // 非法 base64：严格模式直接拒绝，不静默丢字符后再解出一个「凭据」
        $this->response = new FakeResponse();
        $this->bootWith(
            ['auth_basic' => 'alice:s3cret'],
            [],
            ['headers' => ['Authorization' => 'Basic !!!not-base64!!!']]
        );
        Xhprof::index();
        $this->assertSame(401, $this->response->status);

        // base64 合法但没有冒号（无 user:pass 结构）
        $this->response = new FakeResponse();
        $this->bootWith(
            ['auth_basic' => 'alice:s3cret'],
            [],
            ['headers' => ['Authorization' => 'Basic ' . base64_encode('alices3cret')]]
        );
        Xhprof::index();
        $this->assertSame(401, $this->response->status);
    }

    #[Test]
    public function basicAuthRejectsNonBasicScheme(): void
    {
        $this->bootWith(
            ['auth_basic' => 'alice:s3cret'],
            [],
            ['headers' => ['Authorization' => 'Bearer alice:s3cret']]
        );

        Xhprof::index();

        $this->assertSame(401, $this->response->status, 'Bearer 不是 Basic 凭据');

        // 判别性输入：Bearer 后面挂的**是合法 base64** 的 user:pass。只验 base64 不验
        // scheme 的实现会把它当凭据放行，这条断言才会红（上面那条被严格解码兜住了，
        // 单独锁不住 scheme 检查）。
        $this->response = new FakeResponse();
        $this->bootWith(
            ['auth_basic' => 'alice:s3cret'],
            [],
            ['headers' => ['Authorization' => 'Bearer ' . base64_encode('alice:s3cret')]]
        );
        Xhprof::index();
        $this->assertSame(401, $this->response->status, 'scheme 不是 Basic 就不该解析凭据');
    }

    #[Test]
    public function malformedBasicConfigNeverMatchesAndIsLogged(): void
    {
        $this->bootWith(['auth_basic' => 'alice']);   // 没有冒号，永远配不出凭据

        Xhprof::index();

        $this->assertSame(401, $this->response->status);
        $this->assertStringContainsString(
            'auth_basic',
            implode("\n", $this->logger->errors),
            '配置写错必须留一条可归因的日志，否则运维只看到一个没有成因的 401'
        );
    }

    #[Test]
    public function tokenAndBasicAreAlternativesEitherOnePasses(): void
    {
        $cfg = ['auth_token' => 'tok', 'auth_basic' => 'alice:s3cret'];

        // token 对、没带 Basic
        $this->bootWith($cfg, ['token' => 'tok']);
        $result = Xhprof::index();
        // 报告页 HTML 是**返回**的字符串（适配器负责 echo），只有 deny()/respond() 才写
        // response。断言先钉类型：鉴权失败时返回的是 response 对象，这条会干净地红，
        // 而不是 (string) 强转抛 TypeError 把断言淹掉。
        $this->assertIsString($result, '应当渲染报告页，而不是被闸门拒绝');
        $this->assertStringContainsString('<html', $result);

        // Basic 对、token 错
        $this->response = new FakeResponse();
        $this->bootWith(
            $cfg,
            ['token' => 'wrong'],
            ['headers' => ['Authorization' => self::basicCredentials('alice', 's3cret')]]
        );
        $result = Xhprof::index();
        // 报告页 HTML 是**返回**的字符串（适配器负责 echo），只有 deny()/respond() 才写
        // response。断言先钉类型：鉴权失败时返回的是 response 对象，这条会干净地红，
        // 而不是 (string) 强转抛 TypeError 把断言淹掉。
        $this->assertIsString($result, '应当渲染报告页，而不是被闸门拒绝');
        $this->assertStringContainsString('<html', $result);
        $this->assertSame(200, $this->response->status);
    }

    #[Test]
    public function bothConfiguredAndNeitherValidGives401WithChallenge(): void
    {
        $this->bootWith(['auth_token' => 'tok', 'auth_basic' => 'alice:s3cret'], ['token' => 'wrong']);

        Xhprof::index();

        $this->assertSame(401, $this->response->status, '配了 Basic 就应当用 401 提示凭据');
        $this->assertSame('Basic realm="xhprof"', $this->response->headers['WWW-Authenticate'] ?? null);
    }

    #[Test]
    public function tokenOnlyDeploymentStillGets403WithoutChallenge(): void
    {
        // 回归：只配 auth_token 时保持既有 403，且不能冒出凭据框
        $this->bootWith(['auth_token' => 'secret']);

        Xhprof::index();

        $this->assertSame(403, $this->response->status);
        $this->assertArrayNotHasKey('WWW-Authenticate', $this->response->headers);
    }

    // ---------------- ?format=json|csv 只读导出 ----------------

    #[Test]
    public function formatJsonReturnsTheStableContract(): void
    {
        // 判别性：本地化文案若进了契约，下面那条「不得出现」的断言立刻红
        I18n::setLocale('zh_CN');
        $runId = 'abc123def456789';
        $this->seedRun($runId);
        $this->bootWith([], ['run' => $runId, 'source' => 'xhprof_foo', 'format' => 'json']);

        $result = Xhprof::index();

        $this->assertSame(200, $this->response->status);
        $this->assertSame('application/json; charset=UTF-8', $this->response->headers['Content-Type'] ?? null);
        $body = $this->response->body;
        $data = json_decode($body, true);
        $this->assertIsArray($data);
        $this->assertSame('single', $data['mode']);
        $this->assertSame($runId, $data['run']);
        $this->assertSame('xhprof_foo', $data['source']);
        $this->assertSame(1000000, $data['totals']['wt']);
        $this->assertNotEmpty($data['findings'], '夹具应当触发至少一条诊断');
        foreach ($data['findings'] as $f) {
            $this->assertSame(['rule', 'severity', 'symbol', 'score'], array_keys($f), 'findings 只含稳定字段');
        }
        $this->assertStringNotContainsString('自身耗时', $body, '13 语言的诊断文案不得进 JSON 契约');
        $this->assertStringNotContainsString('<html', $body);

        // 平铺行 = 平铺报告一行的列语义（fn + ct + 各指标 + excl_*）
        $byFn = [];
        foreach ($data['functions'] as $row) {
            $byFn[$row['fn']] = $row;
        }
        $this->assertSame([
            'fn' => 'foo()', 'ct' => 2, 'wt' => 500000, 'cpu' => 250, 'mu' => 524288,
            'excl_wt' => 500000, 'excl_cpu' => 250, 'excl_mu' => 524288,
        ], $byFn['foo()']);
    }

    #[Test]
    public function formatCsvReturnsTheFlatTableAsAnAttachment(): void
    {
        $runId = 'abc123def456789';
        $this->seedRun($runId);
        $this->bootWith([], ['run' => $runId, 'source' => 'xhprof_foo', 'format' => 'csv']);

        $result = Xhprof::index();

        $this->assertSame(200, $this->response->status);
        $this->assertSame('text/csv; charset=UTF-8', $this->response->headers['Content-Type'] ?? null);
        $disposition = (string) ($this->response->headers['Content-Disposition'] ?? '');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('filename="xhprof-' . $runId . '.csv"', $disposition);

        $lines = explode("\n", trim($this->response->body));
        // 列为稳定名（非本地化列头），序 = 平铺报告：fn + ct + 各指标 + excl_*
        $this->assertSame('fn,ct,wt,cpu,mu,excl_wt,excl_cpu,excl_mu', $lines[0]);
        $this->assertSame('main(),1,1000000,500,1048576,1000000,500,1048576', $lines[1]);
        $this->assertSame('foo(),2,500000,250,524288,500000,250,524288', $lines[2]);
        $this->assertCount(3, $lines);
    }

    #[Test]
    public function formatCsvQuotesCommasAndQuotesInFunctionNames(): void
    {
        $runId = 'abc123def456789';
        $this->seedRun($runId, [
            'main()' => ['wt' => 1000000, 'mu' => 1, 'ct' => 1, 'cpu' => 1],
            'main()==>weird,"name()' => ['wt' => 100, 'mu' => 1, 'ct' => 1, 'cpu' => 1],
        ]);
        $this->bootWith([], ['run' => $runId, 'source' => 'xhprof_foo', 'format' => 'csv']);

        Xhprof::index();
        $csv = $this->response->body;

        // 判别性输入：函数名里同时有逗号和引号 → 整格加引号、内部引号翻倍（RFC 4180）
        $this->assertStringContainsString('"weird,""name()"', $csv);
    }

    #[Test]
    public function formatCsvDiffModePrefixesDeltaColumns(): void
    {
        $run1 = 'abc123def456789';
        $run2 = 'abc123def45678a';
        $this->seedRun($run1);
        $this->cache->set(Xhprof::$key_prefix . ':xhprof_log:' . $run2, serialize([
            'main()' => ['wt' => 2000000, 'mu' => 2097152, 'ct' => 1, 'cpu' => 1000],
            'foo()' => ['wt' => 1000000, 'mu' => 1048576, 'ct' => 4, 'cpu' => 500],
        ]));
        $this->bootWith([], ['run1' => $run1, 'run2' => $run2, 'source' => 'xhprof_foo', 'format' => 'csv']);

        Xhprof::index();
        $lines = explode("\n", trim($this->response->body));

        $this->assertSame('fn,delta_ct,delta_wt,delta_cpu,delta_mu,delta_excl_wt,delta_excl_cpu,delta_excl_mu', $lines[0]);
        $this->assertSame('main(),0,1000000,500,1048576,1000000,500,1048576', $lines[1]);
        $this->assertSame('foo(),2,500000,250,524288,500000,250,524288', $lines[2]);
        $this->assertStringContainsString('filename="xhprof-' . $run1 . '-vs-' . $run2 . '.csv"', (string) ($this->response->headers['Content-Disposition'] ?? ''));
    }

    #[Test]
    public function formatJsonDiffModeReturnsDeltasAndNoFindings(): void
    {
        $run1 = 'abc123def456789';
        $run2 = 'abc123def45678a';
        $this->seedRun($run1);
        $this->cache->set(Xhprof::$key_prefix . ':xhprof_log:' . $run2, serialize([
            'main()' => ['wt' => 2000000, 'mu' => 2097152, 'ct' => 1, 'cpu' => 1000],
            'foo()' => ['wt' => 1000000, 'mu' => 1048576, 'ct' => 4, 'cpu' => 500],
        ]));
        $this->bootWith([], ['run1' => $run1, 'run2' => $run2, 'source' => 'xhprof_foo', 'format' => 'json']);

        Xhprof::index();
        $data = json_decode($this->response->body, true);

        $this->assertSame('diff', $data['mode']);
        $this->assertSame($run1, $data['run1']);
        $this->assertSame($run2, $data['run2']);
        $this->assertSame(1000000, $data['totals']['wt'], 'diff 的 totals 是 run2 − run1');
        $this->assertSame([], $data['findings'], '诊断只在非 diff 视图产出（与报告页同一条守卫）');
        $byFn = [];
        foreach ($data['functions'] as $row) {
            $byFn[$row['fn']] = $row;
        }
        $this->assertSame(500000, $byFn['foo()']['wt'], 'foo 的 delta = 1000000 − 500000');
        $this->assertSame(2, $byFn['foo()']['ct'], 'ct 的 delta = 4 − 2');
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function invalidFormatProvider(): iterable
    {
        yield 'unknown value' => ['xml'];
        yield 'empty string' => [''];
        yield 'array' => [['json']];
    }

    #[Test]
    #[DataProvider('invalidFormatProvider')]
    public function formatRejectsInvalidValuesWith400(mixed $format): void
    {
        $this->bootWith([], ['run' => 'abc123def456789', 'format' => $format]);

        Xhprof::index();

        $this->assertSame(400, $this->response->status);
        $this->assertSame('400 Bad Request', $this->response->body);
    }

    #[Test]
    public function formatWithoutARunIsABadRequest(): void
    {
        $this->bootWith([], ['format' => 'json']);

        Xhprof::index();

        $this->assertSame(400, $this->response->status);
    }

    #[Test]
    public function formatReturns404WhenTheRunDataIsGone(): void
    {
        // run_id 合法但缓存里没有（过期）：与报告页的空态等价，导出侧给 404
        $this->bootWith([], ['run' => 'abc123def456789', 'source' => 'xhprof_foo', 'format' => 'json']);

        Xhprof::index();

        $this->assertSame(404, $this->response->status);
        $this->assertSame('404 Not Found', $this->response->body);
    }

    #[Test]
    public function formatDoesNotBypassAuthOrTheRunIdWhitelist(): void
    {
        // 鉴权在前：没带 token 的导出同样 403
        $this->bootWith(['auth_token' => 'secret'], ['run' => 'abc123def456789', 'format' => 'json']);
        Xhprof::index();
        $this->assertSame(403, $this->response->status);

        // 白名单在前：非法 run_id 不会被导出路径绕成 404/200
        $this->response = new FakeResponse();
        $this->bootWith([], ['run' => '../../etc/passwd', 'format' => 'json']);
        Xhprof::index();
        $this->assertSame(400, $this->response->status);
    }

    #[Test]
    public function formatJsonAggregatesRunsAndReportsBadOnes(): void
    {
        $run1 = 'abc123def456789';
        $run2 = 'abc123def45678a';   // 缓存里没有：聚合时被跳过
        $this->seedRun($run1);
        $this->bootWith([], ['run' => $run1 . ',' . $run2, 'source' => 'xhprof_foo', 'format' => 'json']);

        Xhprof::index();
        $data = json_decode($this->response->body, true);

        $this->assertSame('aggregate', $data['mode']);
        $this->assertSame([$run1, $run2], $data['runs']);
        $this->assertSame([$run2], $data['bad_runs'], '过期的一条必须显式列出，不能静默少聚合');
        $this->assertNotEmpty($data['functions']);
    }

    // ---------------- IP 白名单（含可信代理） ----------------

    #[Test]
    public function ipAllowlistIsOffByDefault(): void
    {
        $this->bootWith([], [], ['ip' => '198.51.100.7']);

        $result = Xhprof::index();
        // 报告页 HTML 是**返回**的字符串（适配器负责 echo），只有 deny()/respond() 才写
        // response。断言先钉类型：鉴权失败时返回的是 response 对象，这条会干净地红，
        // 而不是 (string) 强转抛 TypeError 把断言淹掉。
        $this->assertIsString($result, '应当渲染报告页，而不是被闸门拒绝');
        $this->assertStringContainsString('<html', $result);
        $this->assertSame(200, $this->response->status);
    }

    #[Test]
    public function ipAllowlistAllowsListedIpAndEmptyArrayIsStillOff(): void
    {
        $this->bootWith(['ip_allowlist' => ['203.0.113.9', '10.0.0.1']], [], ['ip' => '203.0.113.9']);
        $result = Xhprof::index();
        // 报告页 HTML 是**返回**的字符串（适配器负责 echo），只有 deny()/respond() 才写
        // response。断言先钉类型：鉴权失败时返回的是 response 对象，这条会干净地红，
        // 而不是 (string) 强转抛 TypeError 把断言淹掉。
        $this->assertIsString($result, '应当渲染报告页，而不是被闸门拒绝');
        $this->assertStringContainsString('<html', $result);
        $this->assertSame(200, $this->response->status);

        // 空数组 = 没配 = 关闭（代码内默认值）
        $this->response = new FakeResponse();
        $this->bootWith(['ip_allowlist' => []], [], ['ip' => '198.51.100.7']);
        $result = Xhprof::index();
        // 报告页 HTML 是**返回**的字符串（适配器负责 echo），只有 deny()/respond() 才写
        // response。断言先钉类型：鉴权失败时返回的是 response 对象，这条会干净地红，
        // 而不是 (string) 强转抛 TypeError 把断言淹掉。
        $this->assertIsString($result, '应当渲染报告页，而不是被闸门拒绝');
        $this->assertStringContainsString('<html', $result);
    }

    #[Test]
    public function ipAllowlistRejectsUnlistedIp(): void
    {
        $this->bootWith(['ip_allowlist' => ['203.0.113.9']], [], ['ip' => '198.51.100.7']);

        Xhprof::index();

        $this->assertSame(403, $this->response->status);
        $this->assertSame('403 Forbidden', $this->response->body);
        $this->assertStringContainsString('ip_allowlist', implode("\n", $this->logger->errors));
    }

    /**
     * 判别性输入：XFF 首段恰好等于 getRealIp() —— 值可能是客户端自报的，
     * 而 trusted_proxies 为空 ⇒ 拒绝（哪怕这个 IP 就在白名单里）。
     */
    #[Test]
    public function ipAllowlistRefusesAnUnverifiableForwardedIp(): void
    {
        $this->bootWith(
            ['ip_allowlist' => ['203.0.113.9']],
            [],
            ['ip' => '203.0.113.9', 'headers' => ['X-Forwarded-For' => '203.0.113.9, 172.16.0.1']]
        );

        Xhprof::index();

        $this->assertSame(403, $this->response->status, '转发头塑造的 IP 在没声明可信代理前不可验证');
        $this->assertStringContainsString('trusted_proxies', implode("\n", $this->logger->errors));
    }

    #[Test]
    public function ipAllowlistJudgesForwardedIpOnceTrustedProxiesAreDeclared(): void
    {
        $this->bootWith(
            ['ip_allowlist' => ['203.0.113.9'], 'trusted_proxies' => ['172.16.0.1']],
            [],
            ['ip' => '203.0.113.9', 'headers' => ['X-Forwarded-For' => '203.0.113.9, 172.16.0.1']]
        );

        $result = Xhprof::index();
        // 报告页 HTML 是**返回**的字符串（适配器负责 echo），只有 deny()/respond() 才写
        // response。断言先钉类型：鉴权失败时返回的是 response 对象，这条会干净地红，
        // 而不是 (string) 强转抛 TypeError 把断言淹掉。
        $this->assertIsString($result, '应当渲染报告页，而不是被闸门拒绝');
        $this->assertStringContainsString('<html', $result);
        $this->assertSame(200, $this->response->status);
    }

    #[Test]
    public function ipAllowlistIgnoresAForwardedHeaderTheAdapterDidNotUse(): void
    {
        // XFF 与 getRealIp() 不同 ⇒ 适配器没采纳这个头，按真实来源判定，照常比对白名单
        $this->bootWith(
            ['ip_allowlist' => ['203.0.113.9']],
            [],
            ['ip' => '203.0.113.9', 'headers' => ['X-Forwarded-For' => '198.51.100.7']]
        );

        $result = Xhprof::index();
        // 报告页 HTML 是**返回**的字符串（适配器负责 echo），只有 deny()/respond() 才写
        // response。断言先钉类型：鉴权失败时返回的是 response 对象，这条会干净地红，
        // 而不是 (string) 强转抛 TypeError 把断言淹掉。
        $this->assertIsString($result, '应当渲染报告页，而不是被闸门拒绝');
        $this->assertStringContainsString('<html', $result);
    }

    #[Test]
    public function ipAllowlistAlsoChecksXRealIp(): void
    {
        $this->bootWith(
            ['ip_allowlist' => ['203.0.113.9']],
            [],
            ['ip' => '203.0.113.9', 'headers' => ['X-Real-IP' => '203.0.113.9']]
        );

        Xhprof::index();

        $this->assertSame(403, $this->response->status);
    }

    #[Test]
    public function ipAllowlistWithANonArrayConfigFailsClosed(): void
    {
        $this->bootWith(['ip_allowlist' => '203.0.113.9'], [], ['ip' => '203.0.113.9']);

        Xhprof::index();

        $this->assertSame(403, $this->response->status, '配置形态写错必须 fail closed，不能静默关掉一层安全控制');
        $this->assertStringContainsString('ip_allowlist', implode("\n", $this->logger->errors));
    }

    #[Test]
    public function ipAllowlistAlsoGuardsTheExport(): void
    {
        $runId = 'abc123def456789';
        $this->seedRun($runId);
        $this->bootWith(
            ['ip_allowlist' => ['203.0.113.9']],
            ['run' => $runId, 'format' => 'json'],
            ['ip' => '198.51.100.7']
        );

        Xhprof::index();

        $this->assertSame(403, $this->response->status, '导出与报告页受同一套闸门');
    }
}
