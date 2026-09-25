<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\StaticController as CoreStaticController;
use ErikWang2013\Xhprof\Core\Xhprof as CoreXhprof;
use ErikWang2013\Xhprof\Laravel\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Laravel\Middleware;
use ErikWang2013\Xhprof\Laravel\XhprofServiceProvider;
use ErikWang2013\Xhprof\Tests\Stubs\Registry;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;

class LaravelTest extends TestCase
{
    /** @var array<string, mixed> Xhprof 静态属性快照 */
    private array $saved = [];

    protected function setUp(): void
    {
        Redis::reset();
        Log::reset();
        Registry::reset();
        $this->saved = $this->snapshotXhprofStatics();
    }

    protected function tearDown(): void
    {
        $this->restoreXhprofStatics($this->saved);
        xhprof_disable();
    }

    private function snapshotXhprofStatics(): array
    {
        return [
            'request' => CoreXhprof::$request,
            'response' => CoreXhprof::$response,
            'config' => CoreXhprof::$config,
            'cache' => CoreXhprof::$cache,
            'logger' => CoreXhprof::$logger,
            'time_limit' => CoreXhprof::$time_limit,
            'ignore_url_arr' => CoreXhprof::$ignore_url_arr,
            'log_num' => CoreXhprof::$log_num,
            'view_wtred' => CoreXhprof::$view_wtred,
            'key_prefix' => CoreXhprof::$key_prefix,
            'ui_html' => CoreXhprof::$ui_html,
            'symbol_lookup_url' => CoreXhprof::$symbol_lookup_url,
        ];
    }

    private function restoreXhprofStatics(array $s): void
    {
        CoreXhprof::$request = $s['request'];
        CoreXhprof::$response = $s['response'];
        CoreXhprof::$config = $s['config'];
        CoreXhprof::$cache = $s['cache'];
        CoreXhprof::$logger = $s['logger'];
        CoreXhprof::$time_limit = $s['time_limit'];
        CoreXhprof::$ignore_url_arr = $s['ignore_url_arr'];
        CoreXhprof::$log_num = $s['log_num'];
        CoreXhprof::$view_wtred = $s['view_wtred'];
        CoreXhprof::$key_prefix = $s['key_prefix'];
        CoreXhprof::$ui_html = $s['ui_html'];
        CoreXhprof::$symbol_lookup_url = $s['symbol_lookup_url'];
    }

    #[Test]
    public function configAdapterReadsLaravelConfigTree(): void
    {
        Registry::$laravelConfig = [
            'xhprof' => ['enable' => true, 'log_ttl' => 3600],
        ];
        $adapter = new ConfigAdapter();
        $this->assertInstanceOf(ConfigInterface::class, $adapter);
        $this->assertTrue($adapter->get('xhprof.enable'));
        $this->assertSame(3600, $adapter->get('xhprof.log_ttl'));
        $this->assertSame('d', $adapter->get('xhprof.nope', 'd'));
        $this->assertSame('d', $adapter->get('missing.key', 'd'));
    }

    #[Test]
    public function logAdapterForwardsToFacade(): void
    {
        (new LogAdapter())->error('boom', ['a' => 1]);
        $this->assertInstanceOf(LoggerInterface::class, new LogAdapter());
        $this->assertSame(['error: boom'], Log::$errors);
    }

    #[Test]
    public function redisAdapterPassthrough(): void
    {
        $adapter = new RedisAdapter();
        $this->assertInstanceOf(CacheInterface::class, $adapter);

        // phpredis 的 set() 返回 bool（不是原值）；ThinkphpTest 早就按真实语义断言了
        $this->assertTrue($adapter->set('k', 'v', 10));
        $this->assertSame('v', $adapter->get('k'));
        $this->assertSame(['v', null], $adapter->mget(['k', 'nope']));

        $this->assertSame(1, $adapter->incr('n'));
        $this->assertSame(0, $adapter->decr('n'));

        Redis::$store['list'] = ['b', 'a'];
        $this->assertSame(['b', 'a'], $adapter->lRange('list', 0, 1));
        $this->assertSame('a', $adapter->rPop('list'));
        $this->assertSame(2, $adapter->lPush('list', 'x'));
        $this->assertSame(['x', 'b'], Redis::$store['list']);
        $this->assertContains('lpush', Redis::$log);

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
            'ip' => '10.0.0.1',
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
        $this->assertSame('10.0.0.1', $adapter->getRealIp());
    }

    /**
     * get() 与 all() 必须同源 —— 同一个 key 两种取法给同一个答案。
     *
     * 潜伏项：README 给 Laravel 的报告路由是 Route::get，所以"同名键同时出现在 query 与
     * body"目前到不了 Core。但两个来源在真实框架里**优先级相反**：get() 是
     * Symfony Request::get()（attributes → query → body，query 胜出），all() 是
     * InteractsWithInput::input() = `body + query`（body 胜出）。桩按同一语义拆开
     * （第二参的 'post'），旧实现的裸透传在这条上就是两个答案。
     */
    #[Test]
    public function requestAdapterGetAndAllAgreeOnTheSameKey(): void
    {
        $request = new Request(['both' => 'query'], ['post' => ['both' => 'body', 'only_body' => 'b']]);
        $adapter = new RequestAdapter($request);

        $all = $adapter->all();
        $this->assertSame(['both' => 'query', 'only_body' => 'b'], $all, 'query 胜出，body 只补 query 没有的键');
        foreach (array_keys($all) as $key) {
            $this->assertSame($all[$key], $adapter->get((string) $key), "get({$key}) 与 all()[{$key}] 不同值");
        }
        $this->assertSame('query', $adapter->get('both'), 'query 胜出：body 里的同名键不得覆盖');
        $this->assertSame('b', $adapter->get('only_body'));
    }

    #[Test]
    public function responseAdapterBodyHeadersStatus(): void
    {
        $adapter = new ResponseAdapter();
        $this->assertInstanceOf(ResponseInterface::class, $adapter);

        $adapter->withBody('hello')->withHeaders(['X-A' => '1']);
        $res = $adapter->send();
        $this->assertInstanceOf(Response::class, $res);
        // 正文/状态只能走真访问器：真包的 $content/$statusCode 是 protected
        // （symfony/http-foundation v7.4.19 Response.php:110/:112，声明在父类）；
        // `$res->body` 在真包上是 Undefined property 读成 null，不是什么都能读的公开属性。
        $this->assertSame('hello', $res->getContent());
        // Illuminate\Http\Response::$headers 是 ResponseHeaderBag（对象），真包上
        // `$res->headers['X-A']` 是致命错误；桩此前是数组，把这行写法的错误掩盖了。
        $this->assertSame('1', $res->headers->get('X-A'));

        $adapter->withStatus(404);
        $this->assertSame(404, $adapter->send()->getStatusCode());
    }

    /**
     * 调用顺序不是契约的一部分：先设的头不能因为后面设正文就消失。
     *
     * 旧实现 `withBody()` 里是 `response($body, $this->response->getStatusCode())` —— 重建响应，
     * 此前 withHeaders() 设的头全丢。触发形态是 Core 的任意一条链被重排（报告页那条就是
     * status → headers → body）。现在走 Illuminate\Http\Response::setContent()（就地改，与
     * Drupal/Symfony 两个适配器的 withBody() 是同一个调用）。
     */
    #[Test]
    public function responseAdapterHeadersSurviveLaterBody(): void
    {
        $adapter = new ResponseAdapter();
        $adapter->withStatus(403)
            ->withHeaders(['Cache-Control' => 'no-cache, private'])
            ->withBody('403 Forbidden');

        $res = $adapter->send();
        $this->assertSame('no-cache, private', $res->headers->get('Cache-Control'), '先设的头被 withBody 冲掉了');
        $this->assertSame('403 Forbidden', $res->getContent());
        $this->assertSame(403, $res->getStatusCode());
    }

    /**
     * Laravel 适配器把 MIME/404 委托给框架 `ResponseFactory::file()`：真包返回的是
     * Symfony 的 `BinaryFileResponse`（构造时就校验文件存在，没有 `withHeaders()`），
     * 路径从 `getFile()` 读——不是 `$res->filePath`，那个属性真包上没有。
     * 这条同时钉住 StaticController 那条链 `file($p)->withHeaders([...])`：
     * 适配器改用 HeaderBag 之后它才不炸（真包上原本是
     * `Error: Call to undefined method ...BinaryFileResponse::withHeaders()`）。
     */
    #[Test]
    public function responseAdapterFile(): void
    {
        $path = sys_get_temp_dir() . '/xhprof-laravel.css';
        file_put_contents($path, '.a{}');
        try {
            $adapter = new ResponseAdapter();
            $res = $adapter->file($path)->withHeaders(['Content-Type' => 'text/css'])->send();
            $this->assertSame($path, $res->getFile()->getPathname());
            $this->assertSame('text/css', $res->headers->get('Content-Type'));

            // 文件不存在时真包在**构造** BinaryFileResponse 时就抛，不是延迟到 send()
            $this->expectException(FileNotFoundException::class);
            $adapter->file('/no/such/file.css');
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    #[Test]
    public function middlewareBootstrapsAndReturnsHandlerResponse(): void
    {
        Registry::$laravelConfig = [
            'xhprof' => [
                'enable' => true,
                // time_limit 极大 → save_run 提前返回，本测试只验证接线不验证落库
                'time_limit' => PHP_INT_MAX,
            ],
        ];
        $middleware = new Middleware();
        $request = new Request([], ['uri' => '/index']);

        $res = $middleware->handle($request, function (Request $r): Response {
            return new Response('ok');
        });

        $this->assertInstanceOf(Response::class, $res);
        $this->assertSame('ok', $res->getContent());
        $this->assertInstanceOf(CacheInterface::class, CoreXhprof::getCache());
        $this->assertInstanceOf(ConfigAdapter::class, CoreXhprof::getConfig());
        $this->assertTrue(CoreXhprof::getConfig()->get('xhprof.enable'));
    }

    // ---------- 报告页 / 资源短路：不需要用户在 routes/web.php 里注册任何路由 ----------

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
        Registry::$laravelConfig = [
            'xhprof' => ['enable' => true, 'ignore_url_arr' => ['/never-matches']],
        ];

        $called = false;
        $res = (new Middleware())->handle(
            new Request([], ['uri' => '/xhprof?run=abc']),
            function (Request $r) use (&$called): Response {
                $called = true;
                return new Response('business');
            }
        );

        $this->assertFalse($called, '报告页必须在业务 handler（= 用户注册的路由）之前短路');
        $this->assertSame(200, $res->getStatusCode());
        // 不显式给 Content-Type 的话，Illuminate 的响应类型由渲染层决定；这里钉死 HTML。
        $this->assertSame('text/html; charset=UTF-8', $res->headers->get('Content-Type'));
        $this->assertSame('no-cache, private', $res->headers->get('Cache-Control'));
        $body = (string) $res->getContent();
        $this->assertStringContainsString('XHProf 性能分析报告', $body);
        $this->assertStringContainsString('/xhprof-assets', $body, '报告页资源链接走默认前缀');
        $this->assertSame([], Redis::$store, '报告页本身不该被采样落库');
    }

    /** 默认前缀（配置里不写 assets_url）下的资源请求：入口类接管并真读出包内文件。 */
    #[Test]
    public function assetRequestIsServedUnderTheDefaultPrefix(): void
    {
        Registry::$laravelConfig = [
            'xhprof' => ['enable' => true, 'ignore_url_arr' => ['/never-matches']],
        ];

        $called = false;
        $res = (new Middleware())->handle(
            new Request([], ['uri' => '/xhprof-assets/css/xhprof.css']),
            function (Request $r) use (&$called): Response {
                $called = true;
                return new Response('business');
            }
        );

        $this->assertFalse($called, '资源请求必须在业务 handler 之前短路');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('text/css', $res->headers->get('Content-Type'), 'Content-Type 由 Core 按扩展名钉住');
        $this->assertStringContainsString(
            '--xp-bg: #f6f8fa',
            $this->bodyOf($res),
            '必须真的读到 src/html/css/xhprof.css 的内容'
        );
        $this->assertSame([], Redis::$store, '资源请求不该被采样落库');
    }

    /**
     * 自定义前缀：改配置就够了，**不需要**去动路由文件（改动前这条路必须由用户自己
     * 在 routes/web.php 里注册 `/static/xhprof/{path}`，否则请求落不到 serve()）。
     */
    #[Test]
    public function assetRequestIsServedUnderACustomPrefixWithoutTouchingRoutes(): void
    {
        Registry::$laravelConfig = [
            'xhprof' => ['enable' => true, 'assets_url' => '/static/xhprof', 'ignore_url_arr' => ['/never-matches']],
        ];

        $called = false;
        $res = (new Middleware())->handle(
            new Request([], ['uri' => '/static/xhprof/css/xhprof.css']),
            function (Request $r) use (&$called): Response {
                $called = true;
                return new Response('business');
            }
        );

        $this->assertFalse($called, '配置前缀下的请求被入口类接管');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('text/css', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('--xp-bg: #f6f8fa', $this->bodyOf($res));
        $this->assertSame([], Redis::$store);
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
        Registry::$laravelConfig = [
            'xhprof' => ['enable' => true, 'ignore_url_arr' => ['/never-matches']],
        ];

        $called = false;
        $res = (new Middleware())->handle(
            new Request([], ['uri' => '/xhprof-assets-nope']),
            function (Request $r) use (&$called): Response {
                $called = true;
                return new Response('business');
            }
        );

        $this->assertTrue($called, '前缀必须带斜杠：/xhprof-assets-nope 是业务路径');
        $this->assertSame('business', $res->getContent());

        // 断言「落库的是本次采样数据」而不是只断言 key 存在（后者把 stop() 改成
        // save_run([]) 也照样绿，照 WiringTest::assertRunSaved 的口径）。
        $this->assertCount(1, Redis::$store['xhprof:run_id'] ?? []);
        $data = unserialize((string) Redis::$store['xhprof:xhprof_log:' . Redis::$store['xhprof:run_id'][0]]);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('main()', $data, '普通业务请求照常采样落库（含 main() 帧）');
    }

    /**
     * 与 Yii3Test / DrupalTest 同一组边界：入口类的短路判定与 Core 的 serve() 判定必须同源。
     *
     * 分叉的后果不是报错而是**静默**：报告页 CSS/JS 全空、业务路由也拿不到那些路径。
     * 单跑 serve() 或单跑 handle() 都看不出来，只有同一条路径问两次才成立。
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
        Registry::$laravelConfig = ['xhprof' => $config];

        // 第 1 票：入口类短路 —— 业务 handler 没被调用 ⇔ 认作资源
        $called = false;
        (new Middleware())->handle(
            new Request([], ['uri' => $path]),
            function (Request $r) use (&$called): Response {
                $called = true;
                return new Response('business');
            }
        );
        $this->assertSame(
            $isAsset,
            !$called,
            "入口类对 $path 的判定（assets_url = " . var_export($assetsUrl, true) . '）'
        );

        // 第 2 票：Core 自己的判定 —— 真读出包内 css ⇔ 认作资源。放在 handle() 之后，
        // 因为 serve() 读的是 bootstrap 写进去的那份配置。
        $served = CoreStaticController::serve(
            new RequestAdapter(new Request([], ['uri' => $path])),
            new ResponseAdapter()
        )->send();
        $this->assertSame(
            $isAsset,
            str_contains($this->bodyOf($served), '--xp-bg: #f6f8fa'),
            "Core serve() 对 $path 的判定（assets_url = " . var_export($assetsUrl, true) . '）'
        );
    }

    /**
     * 取响应正文。**Laravel 上不能对资源响应用 `getContent()`**：`response()->file()`
     * 给的是 Symfony 的 `BinaryFileResponse`，它的 `getContent()` 恒为 `false`
     * （symfony/http-foundation v7.4.19 src/BinaryFileResponse.php:380-383）——正文由
     * `sendContent()` 从磁盘流出，从不在对象里。路径走 `getFile()`，内容自己读。
     */
    private function bodyOf(mixed $res): string
    {
        if ($res instanceof BinaryFileResponse) {
            return (string) file_get_contents($res->getFile()->getPathname());
        }

        return (string) $res->getContent();
    }

    #[Test]
    public function serviceProviderRegistersAndPublishes(): void
    {
        $provider = new XhprofServiceProvider();
        $provider->register();

        $mergedRef = new \ReflectionProperty($provider, 'merged');
        $merged = $mergedRef->getValue($provider);
        $this->assertArrayHasKey('xhprof', $merged);
        $this->assertSame(
            dirname(__DIR__, 3) . '/src/Laravel/config/xhprof.php',
            $merged['xhprof']
        );

        $provider->boot();
        $publishedRef = new \ReflectionProperty($provider, 'published');
        $published = $publishedRef->getValue($provider);
        $this->assertCount(1, $published);
        $this->assertArrayHasKey(
            dirname(__DIR__, 3) . '/src/Laravel/config/xhprof.php',
            $published[0]
        );
        $this->assertSame(
            Registry::$basePath . '/config/xhprof.php',
            $published[0][dirname(__DIR__, 3) . '/src/Laravel/config/xhprof.php']
        );
    }
}
