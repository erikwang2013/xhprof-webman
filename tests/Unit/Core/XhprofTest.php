<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Core;

require_once __DIR__ . '/../../Fixtures/Fakes.php';

use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
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

    #[Test]
    public function hyperfModeIndexEnforcesAuthToken(): void
    {
        $this->bindHyperfContainer();

        Xhprof::bootstrap();

        $result = Xhprof::index();
        $this->assertInstanceOf(\Hyperf\HttpServer\Response::class, $result);
        $this->assertSame(403, $result->status);
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
}
