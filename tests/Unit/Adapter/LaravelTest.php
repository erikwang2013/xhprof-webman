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

        $this->assertSame('v', $adapter->set('k', 'v', 10));
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

    #[Test]
    public function responseAdapterFile(): void
    {
        // Laravel 适配器将 MIME/404 委托给框架 Response::file()，stub 仅记录路径
        $path = sys_get_temp_dir() . '/xhprof-laravel.css';
        file_put_contents($path, '.a{}');
        try {
            $adapter = new ResponseAdapter();
            $adapter->file($path);
            $res = $adapter->send();
            $this->assertSame($path, $res->filePath);

            $adapter->file('/no/such/file.css');
            $this->assertSame('/no/such/file.css', $adapter->send()->filePath);
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
        $this->assertSame('ok', $res->body);
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
