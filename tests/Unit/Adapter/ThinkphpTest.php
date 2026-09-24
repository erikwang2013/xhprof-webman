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

    #[Test]
    public function responseAdapterBodyHeadersStatus(): void
    {
        $adapter = new ResponseAdapter();
        $this->assertInstanceOf(ResponseInterface::class, $adapter);

        $adapter->withBody('hello')->withHeaders(['X-A' => '1']);
        $res = $adapter->send();
        $this->assertInstanceOf(Response::class, $res);
        $this->assertSame('hello', $res->body);
        $this->assertSame('1', $res->headers['X-A']);

        $adapter->withStatus(404);
        $this->assertSame(404, $adapter->send()->status);
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
        $this->assertSame(200, $res->status);
        $this->assertSame($expectedType, $res->headers['Content-Type']);
        $this->assertSame('content', $res->body);
    }

    #[Test]
    public function responseAdapterFileMissingReturns404(): void
    {
        $adapter = new ResponseAdapter();
        $adapter->file('/no/such/file.css');
        $res = $adapter->send();
        $this->assertSame(404, $res->status);
        $this->assertSame('', $res->body);
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
        $this->assertSame('ok', $res->body);
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
}
