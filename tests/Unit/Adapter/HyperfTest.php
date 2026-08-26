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
use ErikWang2013\Xhprof\Core\Xhprof as CoreXhprof;
use ErikWang2013\Xhprof\Hyperf\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Hyperf\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Hyperf\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Hyperf\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Hyperf\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Hyperf\ConfigProvider;
use ErikWang2013\Xhprof\Hyperf\Middleware;

class HyperfTest extends TestCase
{
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

        $this->assertSame('v', $adapter->set('k', 'v', 10));
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
        $this->assertSame('hello', (string) $res->body);
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
        $adapter = new ResponseAdapter(new Response());
        $adapter->file($path);
        $res = $adapter->send();
        $this->assertSame(200, $res->status);
        $this->assertSame($expectedType, $res->headers['Content-Type']);
        $this->assertSame('content', (string) $res->body);
    }

    #[Test]
    public function responseAdapterFileMissingReturns404(): void
    {
        $adapter = new ResponseAdapter(new Response());
        $adapter->file('/no/such/file.css');
        $this->assertSame(404, $adapter->send()->status);
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
        $request = new class implements \Psr\Http\Message\ServerRequestInterface {
        };
        $response = new class implements \Psr\Http\Message\ResponseInterface {
        };
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
