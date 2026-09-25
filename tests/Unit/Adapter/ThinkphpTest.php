<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use think\CacheStore;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Log;
use think\Request;
use think\Response;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\StaticController as CoreStaticController;
use ErikWang2013\Xhprof\Core\Xhprof as CoreXhprof;
use ErikWang2013\Xhprof\Thinkphp\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Thinkphp\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Thinkphp\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Thinkphp\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Thinkphp\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Thinkphp\Middleware;

/**
 * phpredis 可用时声明内存版 \Redis 子类，用于直连路径测试。
 *
 * 签名必须同时兼容 phpredis 5.x 与 6.x，否则子类声明直接 fatal。规则（已实测）：
 *  - 参数可以放宽（省略类型 = 最宽），但必须补齐父类的**全部**参数
 *  - 父类声明了返回类型时，子类**必须**也声明，且只能是其子集
 * 6.x 的 decr/incr 是 decr(string $key, int $by = 1): Redis|int|false，
 * 5.x 则是 decr($key) 且无返回类型。
 */
if (extension_loaded('redis')) {
    class FakeRedis extends \Redis
    {
        public array $store = [];

        public function get($key): mixed
        {
            return $this->store[$key] ?? null;
        }

        public function set($key, $value, $options = null): bool
        {
            $this->store[$key] = $value;
            return true;
        }

        public function mget(array $keys): array
        {
            $out = [];
            foreach ($keys as $k) {
                $out[] = $this->store[$k] ?? null;
            }
            return $out;
        }

        public function incr($key, $by = 1): int
        {
            $this->store[$key] = (int) ($this->store[$key] ?? 0) + $by;
            return $this->store[$key];
        }

        public function decr($key, $by = 1): int
        {
            $this->store[$key] = (int) ($this->store[$key] ?? 0) - $by;
            return $this->store[$key];
        }

        public function lPush($key, ...$elements): int
        {
            $list = $this->store[$key] ?? [];
            foreach ($elements as $el) {
                array_unshift($list, $el);
            }
            $this->store[$key] = $list;
            return count($list);
        }

        public function rPop($key, $count = 0): string|bool
        {
            // 空列表返回 false —— 与真实 phpredis 一致（不是 null）
            if (empty($this->store[$key])) {
                return false;
            }
            $list = $this->store[$key];
            $value = array_pop($list);
            $this->store[$key] = $list;
            return (string) $value;
        }

        public function lRange($key, $start, $end): array
        {
            return array_slice($this->store[$key] ?? [], $start, $end - $start + 1);
        }

        public function del($key, ...$other_keys): int
        {
            $n = 0;
            foreach ([$key, ...$other_keys] as $k) {
                if (isset($this->store[$k])) {
                    unset($this->store[$k]);
                    $n++;
                }
            }
            return $n;
        }
    }
}

class ThinkphpTest extends TestCase
{
    /** @var array<int, string> */
    private array $tempFiles = [];

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        Config::reset();
        Log::reset();
        Cache::reset();
        $this->saved = $this->snapshotXhprofStatics();
    }

    protected function tearDown(): void
    {
        $this->restoreXhprofStatics($this->saved);
        xhprof_disable();
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function tempFile(string $ext, string $content = 'body'): string
    {
        $path = sys_get_temp_dir() . "/xhprof-think-$ext-" . bin2hex(random_bytes(4)) . ".$ext";
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return $path;
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
    public function configAdapterReadsThinkConfigTree(): void
    {
        Config::$data = ['xhprof' => ['enable' => true, 'log_ttl' => 3600]];
        $adapter = new ConfigAdapter();
        $this->assertInstanceOf(ConfigInterface::class, $adapter);
        $this->assertTrue($adapter->get('xhprof.enable'));
        $this->assertSame(3600, $adapter->get('xhprof.log_ttl'));
        $this->assertSame('d', $adapter->get('xhprof.nope', 'd'));
    }

    #[Test]
    public function logAdapterForwardsWithJsonContext(): void
    {
        (new LogAdapter())->error('boom', ['a' => 1]);
        $this->assertInstanceOf(LoggerInterface::class, new LogAdapter());
        $this->assertSame(['boom {"a":1}'], Log::$errors);
    }

    #[Test]
    public function redisAdapterFallbackViaCacheStore(): void
    {
        $adapter = new RedisAdapter();
        $this->assertInstanceOf(CacheInterface::class, $adapter);

        $store = Cache::store('redis');
        $this->assertNotInstanceOf(\Redis::class, $store->handler()); // 默认 ThinkFakeHandler

        // get/set/incr 走 CacheStore 层
        $this->assertSame('v', $adapter->set('k', 'v', 10));
        $this->assertSame('v', $adapter->get('k'));
        $this->assertSame(1, $adapter->incr('n'));

        // 列表/批量操作走 handler 层
        $store->handler()->data['list'] = ['b', 'a'];
        $this->assertSame(['b', 'a'], $adapter->lRange('list', 0, 1));
        $this->assertSame('a', $adapter->rPop('list'));
        $this->assertSame(2, $adapter->lPush('list', 'x'));
        $this->assertSame(['x', 'b'], $store->handler()->data['list']);
        $this->assertSame([null, null], $adapter->mget(['a', 'zz']));
        $this->assertSame(-1, $adapter->decr('d'));
        $store->handler()->data['delme'] = ['x'];
        $this->assertSame(1, $adapter->del('delme'));
    }

    #[Test]
    public function redisAdapterDirectPathWithRedisHandler(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('phpredis 未安装');
        }
        $fake = new FakeRedis();
        $store = Cache::store('redis');
        $store->setHandler($fake);

        $adapter = new RedisAdapter();
        $this->assertSame($fake, $store->handler());

        // \Redis::set 返回 bool，而非值本身
        $this->assertTrue($adapter->set('k', 'v', 10));
        $this->assertSame('v', $adapter->get('k'));
        $this->assertSame(['v', null], $adapter->mget(['k', 'zz']));

        $this->assertSame(1, $adapter->incr('n'));
        $this->assertSame(0, $adapter->decr('n'));

        // FakeRedis::lPush 无 stub 的 ??= bug，可正常走直连路径
        $this->assertSame(1, $adapter->lPush('list', 'b'));
        $this->assertSame(2, $adapter->lPush('list', 'a'));
        $this->assertSame(['a', 'b'], $adapter->lRange('list', 0, 1));
        $this->assertSame('b', $adapter->rPop('list'));

        $this->assertTrue($adapter->set('delme', 'x'));
        $this->assertSame(1, $adapter->del('delme'));
        $this->assertNull($adapter->get('delme'));
    }

    #[Test]
    public function requestAdapterDelegates(): void
    {
        $request = new Request(['a' => 1], [
            'headers' => ['x-fwd' => 'yes'],
            'method' => 'POST',
            'host' => 'example.com:8080',
            'uri' => '/path',
            'url' => 'http://example.com:8080/path',
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
        $this->assertSame('http://example.com:8080/path', $adapter->url());
        $this->assertSame('10.0.0.1', $adapter->getRealIp());
    }

    /**
     * 契约 R-2：host() 不含端口 —— 而 think 的 `host()` **默认就带端口**。
     *
     * 真包实测（topthink/framework 8.1.4，/tmp 有装好的包）：`host()` 给 'example.com:8080'、
     * `host(true)` 给 'example.com'。桩照抄真包末行（见 framework-stubs.php），所以
     * 「忘了传 true」在这里会红。**夹具必须带端口**：不带端口时两种调用同值，那样的夹具
     * 对这条契约永远绿 —— 这个 bug 之前就是这么漏掉的。
     */
    #[Test]
    public function requestAdapterHostDropsThePortThatTheRealRequestKeeps(): void
    {
        $request = new Request([], ['host' => 'example.com:8080']);

        // 桩的两半（判别力来源，不是产品断言）：真包给什么，桩就得给什么
        $this->assertSame('example.com:8080', $request->host(), '默认原样返回 Host 头，端口还在');
        $this->assertSame('example.com', $request->host(true), 'true 才剥端口');

        $this->assertSame('example.com', (new RequestAdapter($request))->host(), '适配器必须走剥端口的那次调用');
    }

    #[Test]
    public function responseAdapterBodyHeadersStatus(): void
    {
        $adapter = new ResponseAdapter();
        $this->assertInstanceOf(ResponseInterface::class, $adapter);

        $adapter->withBody('hello')->withHeaders(['X-A' => '1']);
        $res = $adapter->send();
        $this->assertInstanceOf(Response::class, $res);
        // 真名是 $data/$content/$code/$header，且全是 protected（topthink/framework 8.1.4
        // src/think/Response.php:27/45/63/69）——`$res->headers['X-A']` 在真包上是
        // Error: Cannot access protected property。取用走 getContent()/getHeader()/getCode()。
        $this->assertSame('hello', $res->getContent());
        $this->assertSame('1', $res->getHeader('X-A'));

        $adapter->withStatus(404);
        $this->assertSame(404, $adapter->send()->getCode());
    }

    /**
     * 调用顺序不是契约的一部分：先设的头不能因为后面设正文就消失。
     *
     * 旧实现 `withBody()` 里是 `response($body, $this->response->getCode())` —— 重建响应，
     * 此前 header() 设的头全丢（状态倒是靠 getCode() 带过去了，所以只有头这条会红）。
     * 触发形态是 Core 的任意一条链被重排（报告页那条就是 status → headers → body）。
     * 现在走 think\Response::content()（就地改）。
     */
    #[Test]
    public function responseAdapterHeadersSurviveLaterBody(): void
    {
        $adapter = new ResponseAdapter();
        $adapter->withStatus(403)
            ->withHeaders(['Cache-Control' => 'no-cache, private'])
            ->withBody('403 Forbidden');

        $res = $adapter->send();
        $this->assertSame('no-cache, private', $res->getHeader('Cache-Control'), '先设的头被 withBody 冲掉了');
        $this->assertSame('403 Forbidden', $res->getContent());
        $this->assertSame(403, $res->getCode());
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
        $adapter = new ResponseAdapter();
        $adapter->file($path);
        $res = $adapter->send();
        $this->assertSame(200, $res->getCode());
        $this->assertSame($expectedType, $res->getHeader('Content-Type'));
        $this->assertSame('content', $res->getContent());
    }

    #[Test]
    public function responseAdapterFileMissingReturns404(): void
    {
        $adapter = new ResponseAdapter();
        $adapter->file('/no/such/file.css');
        $res = $adapter->send();
        $this->assertSame(404, $res->getCode());
        $this->assertSame('', $res->getContent());
    }

    #[Test]
    public function middlewareBootstrapsAndReturnsHandlerResponse(): void
    {
        Config::$data = [
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

    /**
     * 真实 ThinkPHP 在应用未配置 stores.redis 时 Cache::store('redis') 抛
     * InvalidArgumentException（Cache::resolveConfig → getStoreConfig）。
     * 中间件在每个请求上、早于 enable 判断就构造本适配器，
     * 若在构造函数里解析连接，"Redis 扩展可用但没配 think 的 redis store"
     * 会让整个应用每个请求都 500。构造必须是无副作用的。
     */
    #[Test]
    public function constructorDoesNotThrowWhenRedisStoreIsUnconfigured(): void
    {
        Cache::$throwOnStore = true;

        $adapter = new RedisAdapter();

        $this->assertInstanceOf(CacheInterface::class, $adapter);
    }

    // ---------- 报告页 / 资源短路：不需要用户在 route/*.php 里注册任何路由 ----------

    /**
     * 落库观测点：think 的 redis store。适配器优先直连底层 \Redis handler（桩里
     * `Cache::store('redis')->handler()` 给的 ThinkFakeHandler **不是** \Redis，
     * 于是走 fallback 写进 CacheStore 自己），两处都取才与 WiringTest 同一口径。
     *
     * @return array<string, mixed>
     */
    private function storeData(): array
    {
        $store = Cache::store('redis');

        return $store->data + $store->handler()->data;
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
        Config::$data = [
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
        $this->assertSame(200, $res->getCode());
        // think\Response 的 header 是受保护的 $header，取用只能走 getHeader()
        // （topthink/framework 8.1.4 src/think/Response.php:63）。
        $this->assertSame('text/html; charset=UTF-8', $res->getHeader('Content-Type'));
        $this->assertSame('no-cache, private', $res->getHeader('Cache-Control'));
        $body = (string) $res->getContent();
        $this->assertStringContainsString('XHProf 性能分析报告', $body);
        $this->assertStringContainsString('/xhprof-assets', $body, '报告页资源链接走默认前缀');
        $this->assertSame([], $this->storeData(), '报告页本身不该被采样落库');
    }

    /** 默认前缀（配置里不写 assets_url）下的资源请求：入口类接管并真读出包内文件。 */
    #[Test]
    public function assetRequestIsServedUnderTheDefaultPrefix(): void
    {
        Config::$data = [
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
        $this->assertSame(200, $res->getCode());
        $this->assertSame('text/css', $res->getHeader('Content-Type'), 'Content-Type 由 Core 按扩展名钉住');
        // think 的 file() 把内容读进响应对象（Adapter 里 StaticController::readFile()），
        // 所以正文可以直接断言 —— 与 Laravel/Webman 的「正文在磁盘上」不同。
        $this->assertStringContainsString(
            '--xp-bg: #f6f8fa',
            (string) $res->getContent(),
            '必须真的读到 src/html/css/xhprof.css 的内容'
        );
        $this->assertSame([], $this->storeData(), '资源请求不该被采样落库');
    }

    /**
     * 自定义前缀：改配置就够了，**不需要**去动路由文件（改动前这条路必须由用户自己
     * 在 route/app.php 里注册 `/static/xhprof/<path>`，否则请求落不到 serve()）。
     */
    #[Test]
    public function assetRequestIsServedUnderACustomPrefixWithoutTouchingRoutes(): void
    {
        Config::$data = [
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
        $this->assertSame(200, $res->getCode());
        $this->assertSame('text/css', $res->getHeader('Content-Type'));
        $this->assertStringContainsString('--xp-bg: #f6f8fa', (string) $res->getContent());
        $this->assertSame([], $this->storeData());
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
        Config::$data = [
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

        $this->assertTrue($called, '前缀必须带尾斜杠：/xhprof-assets-nope 是业务路径');
        $this->assertSame('business', $res->getContent());

        // 断言「落库的是本次采样数据」而不是只断言 key 存在（后者把 stop() 改成
        // save_run([]) 也照样绿，照 WiringTest::assertRunSaved 的口径）。
        $data = $this->storeData();
        $this->assertCount(1, $data['xhprof:run_id'] ?? []);
        $log = unserialize((string) $data['xhprof:xhprof_log:' . $data['xhprof:run_id'][0]]);
        $this->assertIsArray($log);
        $this->assertArrayHasKey('main()', $log, '普通业务请求照常采样落库（含 main() 帧）');
    }

    /**
     * 与 LaravelTest / Yii3Test / DrupalTest 同一组边界：入口类的短路判定与 Core 的
     * serve() 判定必须同源（两家共用 Core\MiddlewareTrait，这一组两票制覆盖两边）。
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
        Config::$data = ['xhprof' => $config];

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
            str_contains((string) $served->getContent(), '--xp-bg: #f6f8fa'),
            "Core serve() 对 $path 的判定（assets_url = " . var_export($assetsUrl, true) . '）'
        );
    }
}
