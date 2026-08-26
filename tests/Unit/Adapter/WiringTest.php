<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Hyperf\Config;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\HttpServer\Request as HyperfRequest;
use Hyperf\HttpServer\Response as HyperfResponse;
use Hyperf\HttpServer\Contract\RequestInterface as HyperfRequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HyperfResponseInterface;
use Hyperf\Redis\Redis as HyperfRedis;
use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Facades\Redis as IlluminateRedis;
use Illuminate\Support\Facades\Log as IlluminateLog;
use Psr\Log\LoggerInterface as PsrLoggerInterface;
use think\CacheStore;
use think\facade\Cache as ThinkCache;
use think\facade\Config as ThinkConfig;
use think\facade\Log as ThinkLog;
use think\Request as ThinkRequest;
use think\Response as ThinkResponse;
use Webman\Http\Request as WebmanRequest;
use Webman\Http\Response as WebmanResponse;
use support\Redis as SupportRedis;
use support\Log as SupportLog;
use ErikWang2013\Xhprof\Core\Xhprof as CoreXhprof;
use ErikWang2013\Xhprof\Laravel\Middleware as LaravelMiddleware;
use ErikWang2013\Xhprof\Laravel\XhprofServiceProvider;
use ErikWang2013\Xhprof\Tests\Stubs\Registry;
use ErikWang2013\Xhprof\Thinkphp\Middleware as ThinkphpMiddleware;
use ErikWang2013\Xhprof\Webman\Xhprof as WebmanXhprof;
use ErikWang2013\Xhprof\Webman\XhprofMiddleware;
use ErikWang2013\Xhprof\Hyperf\Middleware as HyperfMiddleware;
use ErikWang2013\Xhprof\Hyperf\ConfigProvider;

/**
 * 跨框架接线冒烟：门面类存在性 + 4 框架 Middleware 全流程
 * （enable=true 记录 / enable=false 不记录 / handler 抛异常时 finally 仍执行）。
 */
class WiringTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        SupportRedis::reset();
        SupportLog::reset();
        IlluminateRedis::reset();
        IlluminateLog::reset();
        ThinkConfig::reset();
        ThinkLog::reset();
        ThinkCache::reset();
        Context::reset();
        ApplicationContext::reset();
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

    // ---------- 门面 / 入口类存在性 ----------

    #[Test]
    public function webmanXhprofFacadeExistsAndInherits(): void
    {
        $this->assertTrue(class_exists(WebmanXhprof::class));
        $this->assertTrue(is_subclass_of(WebmanXhprof::class, CoreXhprof::class));
        $this->assertInstanceOf(CoreXhprof::class, new WebmanXhprof());
        $this->assertSame('xhprof', WebmanXhprof::$key_prefix);
    }

    #[Test]
    public function laravelEntryClassesExist(): void
    {
        $this->assertTrue(class_exists(LaravelMiddleware::class));
        $this->assertTrue(class_exists(XhprofServiceProvider::class));
        $this->assertInstanceOf(LaravelMiddleware::class, new LaravelMiddleware());
        $this->assertInstanceOf(XhprofServiceProvider::class, new XhprofServiceProvider());
    }

    #[Test]
    public function thinkphpEntryClassExists(): void
    {
        $this->assertTrue(class_exists(ThinkphpMiddleware::class));
        $this->assertInstanceOf(ThinkphpMiddleware::class, new ThinkphpMiddleware());
    }

    #[Test]
    public function hyperfEntryClassesExist(): void
    {
        $this->assertTrue(class_exists(HyperfMiddleware::class));
        $this->assertTrue(is_subclass_of(HyperfMiddleware::class, \Psr\Http\Server\MiddlewareInterface::class));
        $this->assertInstanceOf(HyperfMiddleware::class, new HyperfMiddleware());
        $this->assertInstanceOf(ConfigProvider::class, new ConfigProvider());
    }

    // ---------- 全流程辅助 ----------

    private function captureError(callable $fn): ?\Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            return $e;
        }
        return null;
    }

    /** 断言一次 save_run 完整落库：计数 +1、run_id 入列、两类日志键存在，返回 run_id */
    private function assertRunSaved(array $store): string
    {
        $this->assertSame(1, $store['xhprof:run_id_num']);
        $this->assertCount(1, $store['xhprof:run_id']);
        $rid = $store['xhprof:run_id'][0];
        $this->assertIsString($rid);
        $this->assertNotEmpty($rid);
        $this->assertArrayHasKey("xhprof:request_log:$rid", $store);
        $this->assertArrayHasKey("xhprof:xhprof_log:$rid", $store);
        return $rid;
    }

    // ---------- Webman 全流程 ----------

    #[Test]
    public function webmanMiddlewareRecordsWhenEnabled(): void
    {
        Registry::$webmanConfig = [
            'plugin' => ['aaron-dev' => ['xhprof' => ['xhprof' => ['enable' => true]]]],
        ];
        $captured = null;
        $handler = function (WebmanRequest $r) use (&$captured): WebmanResponse {
            return $captured = new WebmanResponse(200, [], 'ok');
        };
        $e = $this->captureError(
            fn () => (new XhprofMiddleware())->process(new WebmanRequest([], ['uri' => '/index']), $handler)
        );

        $this->assertNull($e);
        $this->assertSame('ok', $captured->body);
        $this->assertRunSaved(SupportRedis::$store);
        $this->assertSame([], SupportLog::$errors);
    }

    #[Test]
    public function webmanMiddlewareSkipsWhenDisabled(): void
    {
        Registry::$webmanConfig = [
            'plugin' => ['aaron-dev' => ['xhprof' => ['xhprof' => ['enable' => false]]]],
        ];
        $handler = fn (WebmanRequest $r): WebmanResponse => new WebmanResponse(200, [], 'ok');
        $res = (new XhprofMiddleware())->process(new WebmanRequest([], ['uri' => '/index']), $handler);

        $this->assertSame('ok', $res->body);
        $this->assertSame([], SupportRedis::$store);
        $this->assertSame([], SupportLog::$errors);
    }

    #[Test]
    public function webmanMiddlewareFinallyRunsWhenHandlerThrows(): void
    {
        Registry::$webmanConfig = [
            'plugin' => ['aaron-dev' => ['xhprof' => ['xhprof' => ['enable' => true]]]],
        ];
        $handlerRan = false;
        $e = $this->captureError(function () use (&$handlerRan) {
            (new XhprofMiddleware())->process(
                new WebmanRequest([], ['uri' => '/index']),
                function (WebmanRequest $r) use (&$handlerRan): WebmanResponse {
                    $handlerRan = true;
                    throw new \RuntimeException('handler boom');
                }
            );
        });

        $this->assertTrue($handlerRan);
        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertNull($e->getPrevious());
        $this->assertRunSaved(SupportRedis::$store);
    }

    // ---------- Laravel 全流程 ----------

    #[Test]
    public function laravelMiddlewareRecordsWhenEnabled(): void
    {
        Registry::$laravelConfig = ['xhprof' => ['enable' => true]];
        $captured = null;
        $handler = function (IlluminateRequest $r) use (&$captured): IlluminateResponse {
            return $captured = new IlluminateResponse('ok');
        };
        $e = $this->captureError(
            fn () => (new LaravelMiddleware())->handle(new IlluminateRequest([], ['uri' => '/index']), $handler)
        );

        $this->assertNull($e);
        $this->assertSame('ok', $captured->body);
        $this->assertRunSaved(IlluminateRedis::$store);
    }

    #[Test]
    public function laravelMiddlewareSkipsWhenDisabled(): void
    {
        Registry::$laravelConfig = ['xhprof' => ['enable' => false]];
        $handler = fn (IlluminateRequest $r): IlluminateResponse => new IlluminateResponse('ok');
        $res = (new LaravelMiddleware())->handle(new IlluminateRequest([], ['uri' => '/index']), $handler);

        $this->assertSame('ok', $res->body);
        $this->assertSame([], IlluminateRedis::$store);
    }

    #[Test]
    public function laravelMiddlewareFinallyRunsWhenHandlerThrows(): void
    {
        Registry::$laravelConfig = ['xhprof' => ['enable' => true]];
        $handlerRan = false;
        $e = $this->captureError(function () use (&$handlerRan) {
            (new LaravelMiddleware())->handle(
                new IlluminateRequest([], ['uri' => '/index']),
                function (IlluminateRequest $r) use (&$handlerRan): IlluminateResponse {
                    $handlerRan = true;
                    throw new \RuntimeException('handler boom');
                }
            );
        });

        $this->assertTrue($handlerRan);
        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertNull($e->getPrevious());
        $this->assertRunSaved(IlluminateRedis::$store);
    }

    // ---------- ThinkPHP 全流程 ----------

    #[Test]
    public function thinkphpMiddlewareRecordsWhenEnabled(): void
    {
        ThinkConfig::$data = ['xhprof' => ['enable' => true]];
        $captured = null;
        $handler = function (ThinkRequest $r) use (&$captured): ThinkResponse {
            return $captured = new ThinkResponse('ok');
        };
        $e = $this->captureError(
            fn () => (new ThinkphpMiddleware())->handle(new ThinkRequest([], ['uri' => '/index']), $handler)
        );

        $this->assertNull($e);
        $this->assertSame('ok', $captured->body);
        $store = ThinkCache::store('redis');
        $this->assertRunSaved($store->data + $store->handler()->data);
    }

    #[Test]
    public function thinkphpMiddlewareSkipsWhenDisabled(): void
    {
        ThinkConfig::$data = ['xhprof' => ['enable' => false]];
        $handler = fn (ThinkRequest $r): ThinkResponse => new ThinkResponse('ok');
        $res = (new ThinkphpMiddleware())->handle(new ThinkRequest([], ['uri' => '/index']), $handler);

        $this->assertSame('ok', $res->body);
        $store = ThinkCache::store('redis');
        $this->assertSame([], $store->data);
        $this->assertSame([], $store->handler()->data);
    }

    #[Test]
    public function thinkphpMiddlewareFinallyRunsWhenHandlerThrows(): void
    {
        ThinkConfig::$data = ['xhprof' => ['enable' => true]];
        $handlerRan = false;
        $e = $this->captureError(function () use (&$handlerRan) {
            (new ThinkphpMiddleware())->handle(
                new ThinkRequest([], ['uri' => '/index']),
                function (ThinkRequest $r) use (&$handlerRan): ThinkResponse {
                    $handlerRan = true;
                    throw new \RuntimeException('handler boom');
                }
            );
        });

        $this->assertTrue($handlerRan);
        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertNull($e->getPrevious());
        $store = ThinkCache::store('redis');
        $this->assertRunSaved($store->data + $store->handler()->data);
    }

    // ---------- Hyperf 全流程 ----------

    private function bindHyperfContainer(array $config): HyperfRedis
    {
        $container = ApplicationContext::getContainer();
        $redis = new HyperfRedis();
        $container->set(HyperfRequestInterface::class, new HyperfRequest([], ['uri' => '/index']));
        $container->set(HyperfResponseInterface::class, new HyperfResponse());
        $container->set(\Hyperf\Contract\ConfigInterface::class, new Config($config));
        $container->set(HyperfRedis::class, $redis);
        $container->set(PsrLoggerInterface::class, new class implements PsrLoggerInterface {
            public function error(string $message, array $context = []): void
            {
            }
        });
        return $redis;
    }

    private function hyperfPsrRequest(): \Psr\Http\Message\ServerRequestInterface
    {
        return new class implements \Psr\Http\Message\ServerRequestInterface {
        };
    }

    #[Test]
    public function hyperfMiddlewareRecordsWhenEnabled(): void
    {
        $redis = $this->bindHyperfContainer(['xhprof' => ['enable' => true]]);
        $e = $this->captureError(
            fn () => (new HyperfMiddleware())->process($this->hyperfPsrRequest(), $this->hyperfHandler())
        );

        $this->assertNull($e);
        $this->assertRunSaved($redis->store);
    }

    #[Test]
    public function hyperfMiddlewareSkipsWhenDisabled(): void
    {
        $redis = $this->bindHyperfContainer(['xhprof' => ['enable' => false]]);
        $result = (new HyperfMiddleware())->process($this->hyperfPsrRequest(), $this->hyperfHandler());

        $this->assertInstanceOf(\Psr\Http\Message\ResponseInterface::class, $result);
        $this->assertSame([], $redis->store);
    }

    #[Test]
    public function hyperfMiddlewareFinallyRunsWhenHandlerThrows(): void
    {
        $redis = $this->bindHyperfContainer(['xhprof' => ['enable' => true]]);
        $state = ['ran' => false];

        $handler = new class($state) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(private array &$state)
            {
            }

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->state['ran'] = true;
                throw new \RuntimeException('handler boom');
            }
        };
        $e = $this->captureError(
            fn () => (new HyperfMiddleware())->process($this->hyperfPsrRequest(), $handler)
        );

        $this->assertTrue($state['ran']);
        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertNull($e->getPrevious());
        $this->assertRunSaved($redis->store);
    }

    private function hyperfHandler(): \Psr\Http\Server\RequestHandlerInterface
    {
        return new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new class implements \Psr\Http\Message\ResponseInterface {
                };
            }
        };
    }
}
