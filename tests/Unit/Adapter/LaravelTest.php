<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Adapter;

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
use ErikWang2013\Xhprof\Core\Xhprof as CoreXhprof;
use ErikWang2013\Xhprof\Laravel\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Laravel\Middleware;
use ErikWang2013\Xhprof\Laravel\XhprofServiceProvider;
use ErikWang2013\Xhprof\Tests\Stubs\Registry;
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
