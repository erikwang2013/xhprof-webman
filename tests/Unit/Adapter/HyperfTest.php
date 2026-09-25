<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Hyperf\Config;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\HttpServer\Request;
use Hyperf\HttpServer\Response;
use Hyperf\HttpServer\Contract\RequestInterface as HyperfRequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HyperfResponseInterface;
use Hyperf\Redis\Redis;
use Psr\Log\LoggerInterface;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface as CoreLoggerInterface;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\StaticController as CoreStaticController;
use ErikWang2013\Xhprof\Core\Xhprof as CoreXhprof;
use ErikWang2013\Xhprof\Hyperf\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Hyperf\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Hyperf\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Hyperf\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Hyperf\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Hyperf\ConfigProvider;
use ErikWang2013\Xhprof\Hyperf\Middleware;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\FakePsrResponse;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\FakeServerRequest;
use ErikWang2013\Xhprof\Tests\Support\XhprofStaticsSnapshot;

class HyperfTest extends TestCase
{
    use XhprofStaticsSnapshot;

    /** @var array<int, string> */
    private array $tempFiles = [];

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        Context::reset();
        ApplicationContext::reset();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', sys_get_temp_dir());
        }
        $this->saved = $this->snapshotXhprofStatics();
    }

    protected function tearDown(): void
    {
        $this->restoreXhprofStatics($this->saved);
        xhprof_disable();
        Context::reset();
        ApplicationContext::reset();
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function tempFile(string $ext, string $content = 'body'): string
    {
        $path = sys_get_temp_dir() . "/xhprof-hyperf-$ext-" . bin2hex(random_bytes(4)) . ".$ext";
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return $path;
    }


    #[Test]
    public function configAdapterDelegatesToHyperfConfig(): void
    {
        $adapter = new ConfigAdapter(new Config(['xhprof' => ['enable' => true]]));
        $this->assertInstanceOf(ConfigInterface::class, $adapter);
        $this->assertTrue($adapter->get('xhprof.enable'));
        $this->assertSame('d', $adapter->get('xhprof.nope', 'd'));
    }

    #[Test]
    public function logAdapterForwardsToPsrLogger(): void
    {
        $logger = new class implements LoggerInterface {
            public array $messages = [];

            public function error(string $message, array $context = []): void
            {
                $this->messages[] = $message . ':' . json_encode($context);
            }
        };
        $adapter = new LogAdapter($logger);
        $this->assertInstanceOf(CoreLoggerInterface::class, $adapter);
        $adapter->error('boom', ['a' => 1]);
        $this->assertSame(['boom:{"a":1}'], $logger->messages);
    }

    #[Test]
    public function redisAdapterPassthrough(): void
    {
        $redis = new Redis();
        $adapter = new RedisAdapter($redis);
        $this->assertInstanceOf(CacheInterface::class, $adapter);

        // phpredis 的 set() 返回 bool（不是原值）；ThinkphpTest 早就按真实语义断言了
        $this->assertTrue($adapter->set('k', 'v', 10));
        $this->assertSame('v', $adapter->get('k'));
        $this->assertSame(['v', null], $adapter->mget(['k', 'nope']));

        $this->assertSame(1, $adapter->incr('n'));
        $this->assertSame(0, $adapter->decr('n'));

        $redis->store['list'] = ['b', 'a'];
        $this->assertSame(['b', 'a'], $adapter->lRange('list', 0, 1));
        $this->assertSame('a', $adapter->rPop('list'));
        $this->assertSame(2, $adapter->lPush('list', 'x'));
        $this->assertSame(['x', 'b'], $redis->store['list']);

        $this->assertSame(1, $adapter->del('k'));
        $this->assertNull($adapter->get('k'));
    }

    #[Test]
    public function requestAdapterDelegates(): void
    {
        $request = new Request(['a' => 1], [
            'headers' => ['x-fwd' => 'yes'],
            'method' => 'POST',
            'host' => 'example.com',
            'uri' => '/path',
            'url' => 'http://example.com/path',
        ]);
        $adapter = new RequestAdapter($request);
        $this->assertInstanceOf(RequestInterface::class, $adapter);

        $this->assertSame(1, $adapter->get('a'));
        $this->assertSame('d', $adapter->get('zz', 'd'));
        $this->assertSame(['a' => 1], $adapter->all());
        $this->assertSame('POST', $adapter->method());
        $this->assertSame('yes', $adapter->header('x-fwd'));
        $this->assertSame('example.com', $adapter->host());
        $this->assertSame('/path', $adapter->uri());
        $this->assertSame('http://example.com/path', $adapter->url());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function realIpProvider(): array
    {
        return [
            'x-forwarded-for 多 IP 取第一个' => [
                ['x-forwarded-for' => '1.2.3.4, 5.6.7.8', 'remote_addr' => '9.9.9.9'],
                '1.2.3.4',
            ],
            'x-real-ip 兜底' => [
                ['x-real-ip' => '8.8.8.8', 'remote_addr' => '9.9.9.9'],
                '8.8.8.8',
            ],
            'remote_addr 默认' => [
                ['remote_addr' => '7.7.7.7'],
                '7.7.7.7',
            ],
            '无 server 数据返回 127.0.0.1' => [
                [],
                '127.0.0.1',
            ],
        ];
    }

    #[Test]
    #[DataProvider('realIpProvider')]
    public function requestAdapterGetRealIp(array $server, string $expected): void
    {
        $adapter = new RequestAdapter(new Request([], ['server' => $server]));
        $this->assertSame($expected, $adapter->getRealIp());
    }

    #[Test]
    public function responseAdapterBodyHeadersStatus(): void
    {
        $adapter = new ResponseAdapter(new Response());
        $this->assertInstanceOf(ResponseInterface::class, $adapter);

        $adapter->withBody('hello')->withHeaders(['X-A' => '1']);
        $res = $adapter->send();
        // 真包没有任何 public 属性（hyperf/http-server v3.2.0 src/Response.php:55），
        // 状态/头/正文都只能走 PSR-7 访问器：getHeader() 给**数组**、getHeaderLine() 给串。
        $this->assertSame('hello', (string) $res->getBody());
        $this->assertSame('1', $res->getHeaderLine('X-A'));
        $this->assertSame(['1'], $res->getHeader('X-A'), 'PSR-7 的头值是数组');
        $this->assertSame(['X-A' => ['1']], $res->getHeaders(), 'PSR-7：名字 → 值数组');

        $adapter->withStatus(404);
        $this->assertSame(404, $adapter->send()->getStatusCode());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function mimeProvider(): array
    {
        return [
            'css' => ['css', 'text/css'],
            'js' => ['js', 'application/javascript'],
            'png' => ['png', 'image/png'],
            'gif' => ['gif', 'image/gif'],
            'jpg' => ['jpg', 'image/jpeg'],
            'jpeg' => ['jpeg', 'image/jpeg'],
            'svg' => ['svg', 'image/svg+xml'],
            'unknown' => ['bin', 'application/octet-stream'],
        ];
    }

    #[Test]
    #[DataProvider('mimeProvider')]
    public function responseAdapterFileMapsMime(string $ext, string $expectedType): void
    {
        $path = $this->tempFile($ext, 'content');
        $adapter = new ResponseAdapter(new Response());
        $adapter->file($path);
        $res = $adapter->send();
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame($expectedType, $res->getHeaderLine('Content-Type'));
        $this->assertSame('content', (string) $res->getBody());
    }

    #[Test]
    public function responseAdapterFileMissingReturns404(): void
    {
        $adapter = new ResponseAdapter(new Response());
        $adapter->file('/no/such/file.css');
        $this->assertSame(404, $adapter->send()->getStatusCode());
    }

    #[Test]
    public function middlewareBootstrapsAndReturnsHandlerResponse(): void
    {
        $container = ApplicationContext::getContainer();
        $redis = new Redis();
        $container->set(HyperfRequestInterface::class, new Request([], ['uri' => '/index']));
        $container->set(HyperfResponseInterface::class, new Response());
        $container->set(\Hyperf\Contract\ConfigInterface::class, new Config([
            'xhprof' => [
                'enable' => true,
                // time_limit 极大 → save_run 提前返回，本测试只验证接线不验证落库
                'time_limit' => PHP_INT_MAX,
            ],
        ]));
        $container->set(Redis::class, $redis);
        $container->set(LoggerInterface::class, new class implements LoggerInterface {
            public function error(string $message, array $context = []): void
            {
            }
        });

        $middleware = new Middleware();
        $request = new FakeServerRequest();
        $response = new FakePsrResponse();
        $handler = new class($response) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(private \Psr\Http\Message\ResponseInterface $response)
            {
            }

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return $this->response;
            }
        };

        $result = $middleware->process($request, $handler);

        $this->assertSame($response, $result);
        $this->assertInstanceOf(CacheInterface::class, CoreXhprof::getCache());
        $this->assertInstanceOf(ConfigAdapter::class, CoreXhprof::getConfig());
        $this->assertTrue(CoreXhprof::getConfig()->get('xhprof.enable'));
    }

    // ---------- 报告页 / 资源短路：不需要用户在 config/routes.php 里注册任何路由 ----------

    /**
     * 绑定容器并把 uri 钉在入口类**真正读**的那份请求上。
     *
     * 注意：Hyperf 入口类的 uri 取自 `$container->get(HyperfRequestInterface::class)`
     * （Hyperf 的请求包装），**不是**传给 process() 的 PSR-7 请求——后者只往下游 handler 递。
     * 真实 Hyperf 里两者是同一请求的两个视图，这里两处都按同一路径摆。
     *
     * @param array<string, mixed> $xhprof
     */
    private function bindHyperf(array $xhprof, string $uri): Redis
    {
        $container = ApplicationContext::getContainer();
        $redis = new Redis();
        $container->set(HyperfRequestInterface::class, new Request([], ['uri' => $uri]));
        $container->set(HyperfResponseInterface::class, new Response());
        $container->set(\Hyperf\Contract\ConfigInterface::class, new Config(['xhprof' => $xhprof]));
        $container->set(Redis::class, $redis);
        $container->set(LoggerInterface::class, new class implements LoggerInterface {
            public function error(string $message, array $context = []): void
            {
            }
        });

        return $redis;
    }

    /** 业务 handler：记下有没有被走到（被短路时它根本不该被调用）。 */
    private function probeHandler(bool &$called): \Psr\Http\Server\RequestHandlerInterface
    {
        return new class($called) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(private bool &$called)
            {
            }

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->called = true;
                return new FakePsrResponse(200, [], 'business');
            }
        };
    }

    /**
     * 报告页：入口类在业务 handler 之前短路，用户注册的路由（= handler）根本不会被走到。
     *
     * `ignore_url_arr` 必须挪开默认的 `['/xhprof']`：XhprofLib::isIgnore() 是 **子串** 匹配，
     * URI 里含 '/xhprof' 就整个不落库，于是"报告页没被采样"这条断言会被默认配置**顺手**
     * 满足——把短路挪到 xhprofStart() 之后也照样绿（判别力为零）。挪开之后，落不落库只由
     * 「有没有跑过 xhprofStart/xhprofStop」决定，这条断言才真的能红。
     */
    #[Test]
    public function reportPageIsServedWithoutAnyUserRoute(): void
    {
        $redis = $this->bindHyperf(['enable' => true, 'ignore_url_arr' => ['/never-matches']], '/xhprof?run=abc');

        $called = false;
        $res = (new Middleware())->process(new FakeServerRequest('GET', '/xhprof?run=abc'), $this->probeHandler($called));

        $this->assertFalse($called, '报告页必须在业务 handler（= 用户注册的路由）之前短路');
        // 返回的必须是**响应对象**：从中间件里 return 字符串会被
        // CoreMiddleware::transferToResponse() 无条件加 content-type: text/plain，
        // 浏览器把报告页按纯文本渲染（README 的 Hyperf 一节记着同一件事）。
        $this->assertInstanceOf(\Psr\Http\Message\ResponseInterface::class, $res);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('text/html; charset=UTF-8', $res->getHeaderLine('Content-Type'));
        $this->assertSame('no-cache, private', $res->getHeaderLine('Cache-Control'));
        $body = (string) $res->getBody();
        $this->assertStringContainsString('XHProf 性能分析报告', $body);
        $this->assertStringContainsString('/xhprof-assets', $body, '报告页资源链接走默认前缀');
        $this->assertSame([], $redis->store, '报告页本身不该被采样落库');
    }

    /** 默认前缀（配置里不写 assets_url）下的资源请求：入口类接管并真读出包内文件。 */
    #[Test]
    public function assetRequestIsServedUnderTheDefaultPrefix(): void
    {
        $redis = $this->bindHyperf(['enable' => true, 'ignore_url_arr' => ['/never-matches']], '/xhprof-assets/css/xhprof.css');

        $called = false;
        $res = (new Middleware())->process(new FakeServerRequest('GET', '/xhprof-assets/css/xhprof.css'), $this->probeHandler($called));

        $this->assertFalse($called, '资源请求必须在业务 handler 之前短路');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('text/css', $res->getHeaderLine('Content-Type'), 'Content-Type 由 Core 按扩展名钉住');
        $this->assertStringContainsString(
            '--xp-bg: #f6f8fa',
            (string) $res->getBody(),
            '必须真的读到 src/html/css/xhprof.css 的内容'
        );
        $this->assertSame([], $redis->store, '资源请求不该被采样落库');
    }

    /**
     * 自定义前缀：改配置就够了，**不需要**去动路由文件（改动前这条路必须由用户自己
     * 在 config/routes.php 里注册 `/static/xhprof/<path>`，否则请求落不到 serve()）。
     */
    #[Test]
    public function assetRequestIsServedUnderACustomPrefixWithoutTouchingRoutes(): void
    {
        $redis = $this->bindHyperf(
            ['enable' => true, 'assets_url' => '/static/xhprof', 'ignore_url_arr' => ['/never-matches']],
            '/static/xhprof/css/xhprof.css'
        );

        $called = false;
        $res = (new Middleware())->process(new FakeServerRequest('GET', '/static/xhprof/css/xhprof.css'), $this->probeHandler($called));

        $this->assertFalse($called, '配置前缀下的请求被入口类接管');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('text/css', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('--xp-bg: #f6f8fa', (string) $res->getBody());
        $this->assertSame([], $redis->store);
    }

    /**
     * 近似路径不被接管，且照常当业务请求采样落库。
     *
     * 同样要挪开 `ignore_url_arr`：`/xhprof-assets-nope` 含子串 '/xhprof'，默认配置下
     * 「没落库」是 isIgnore() 给的，不是短路给的——那份断言同样会被顺手满足。
     */
    #[Test]
    public function nearMissAssetPathIsStillABusinessRequest(): void
    {
        $redis = $this->bindHyperf(['enable' => true, 'ignore_url_arr' => ['/never-matches']], '/xhprof-assets-nope');

        $called = false;
        $res = (new Middleware())->process(new FakeServerRequest('GET', '/xhprof-assets-nope'), $this->probeHandler($called));

        $this->assertTrue($called, '前缀必须带尾斜杠：/xhprof-assets-nope 是业务路径');
        $this->assertSame('business', (string) $res->getBody());

        // 断言「落库的是本次采样数据」而不是只断言 key 存在（后者把 stop() 改成
        // save_run([]) 也照样绿，照 WiringTest::assertRunSaved 的口径）。
        $this->assertCount(1, $redis->store['xhprof:run_id'] ?? []);
        $log = unserialize((string) $redis->store['xhprof:xhprof_log:' . $redis->store['xhprof:run_id'][0]]);
        $this->assertIsArray($log);
        $this->assertArrayHasKey('main()', $log, '普通业务请求照常采样落库（含 main() 帧）');
    }

    /**
     * 与 LaravelTest / ThinkphpTest / WebmanTest / Yii3Test / DrupalTest 同一组边界：
     * 入口类的短路判定与 Core 的 serve() 判定必须同源。
     *
     * 分叉的后果不是报错而是**静默**：报告页 CSS/JS 全空、业务路由也拿不到那些路径。
     * 单跑 serve() 或单跑 process() 都看不出来，只有同一条路径问两次才成立。
     */
    public static function assetsUrlBoundaries(): iterable
    {
        yield '没有配置' => [null, '/xhprof-assets/css/xhprof.css', true];
        yield '配置 = 默认值' => ['/xhprof-assets', '/xhprof-assets/css/xhprof.css', true];
        yield '配置带尾斜杠' => ['/xhprof-assets/', '/xhprof-assets/css/xhprof.css', true];
        yield '自定义前缀' => ['/static/xhprof', '/static/xhprof/css/xhprof.css', true];
        yield '自定义前缀 + 尾斜杠' => ['/static/xhprof/', '/static/xhprof/css/xhprof.css', true];
        // 判别力来源：配了自定义前缀后**老路径不再被认**（否则同一份文件有两个 URL）
        yield '自定义前缀 + 老路径' => ['/static/xhprof', '/xhprof-assets/css/xhprof.css', false];
        yield 'CDN 绝对 URL' => ['https://cdn.test/xhprof-assets', '/xhprof-assets/css/xhprof.css', false];
        // 空串 = 不启用资源短路（两边都一个都不认，不能一边回落成默认前缀）
        yield '配置成空串' => ['', '/xhprof-assets/css/xhprof.css', false];
        yield '配置成 /' => ['/', '/css/xhprof.css', true];
        // 尾斜杠是「近似路径不算资源」的唯一来源
        yield '近似路径' => [null, '/xhprof-assets-nope', false];
        yield '近似路径（自定义前缀）' => ['/static/xhprof', '/static/xhprof-nope', false];
    }

    #[Test]
    #[DataProvider('assetsUrlBoundaries')]
    public function guardAndServeAgreeOnWhichPathsAreAssets(?string $assetsUrl, string $path, bool $isAsset): void
    {
        $config = ['enable' => true];
        if ($assetsUrl !== null) {
            $config['assets_url'] = $assetsUrl;
        }
        $redis = $this->bindHyperf($config, $path);

        // 第 1 票：入口类短路 —— 业务 handler 没被调用 ⇔ 认作资源
        $called = false;
        (new Middleware())->process(new FakeServerRequest('GET', $path), $this->probeHandler($called));
        $this->assertSame(
            $isAsset,
            !$called,
            "入口类对 $path 的判定（assets_url = " . var_export($assetsUrl, true) . '）'
        );

        // 第 2 票：Core 自己的判定 —— 真读出包内 css ⇔ 认作资源。放在 process() 之后，
        // 因为 serve() 读的是 bootstrap（写进协程 Context）的那份配置。
        $served = CoreStaticController::serve(
            new RequestAdapter(new Request([], ['uri' => $path])),
            new ResponseAdapter(new Response())
        )->send();
        $this->assertSame(
            $isAsset,
            str_contains((string) $served->getBody(), '--xp-bg: #f6f8fa'),
            "Core serve() 对 $path 的判定（assets_url = " . var_export($assetsUrl, true) . '）'
        );

        // 顺带钉住「短路过的路径一个字节都没落库」的边界（上面两票都跑完再看）
        if ($isAsset) {
            $this->assertSame([], $redis->store, "$path 被短路了就不该落库");
        }
    }

    #[Test]
    public function configProviderReturnsMiddlewareAndPublish(): void
    {
        $provider = new ConfigProvider();
        $config = $provider();

        $this->assertIsArray($config);
        $this->assertSame(
            [Middleware::class],
            $config['middlewares']['http']
        );
        $this->assertSame('xhprof', $config['publish'][0]['id']);
        $this->assertFileExists($config['publish'][0]['source']);
        $this->assertSame(
            BASE_PATH . '/config/autoload/xhprof.php',
            $config['publish'][0]['destination']
        );
    }
}
