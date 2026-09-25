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
use ErikWang2013\Xhprof\Drupal\XhprofMiddleware as DrupalXhprofMiddleware;
use ErikWang2013\Xhprof\Joomla\Extension\Xhprof as JoomlaXhprof;
use ErikWang2013\Xhprof\Laravel\Middleware as LaravelMiddleware;
use ErikWang2013\Xhprof\Laravel\XhprofServiceProvider;
use ErikWang2013\Xhprof\Slim\Adapter\LogAdapter as SlimLogAdapter;
use ErikWang2013\Xhprof\Slim\XhprofMiddleware as SlimXhprofMiddleware;
use ErikWang2013\Xhprof\Symfony\XhprofListener;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\Drupal\FakeConfigFactory;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\Drupal\FakeLoggerFactory;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\FakePsrResponse;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\FakeResponseFactory;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\FakeServerRequest;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\JoomlaFakeApplication;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\JoomlaNoopDispatcher;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\WordpressHooks;
use ErikWang2013\Xhprof\Tests\Stubs\Registry;
use ErikWang2013\Xhprof\Thinkphp\Middleware as ThinkphpMiddleware;
use ErikWang2013\Xhprof\Webman\Xhprof as WebmanXhprof;
use ErikWang2013\Xhprof\Webman\XhprofMiddleware;
use ErikWang2013\Xhprof\Hyperf\Middleware as HyperfMiddleware;
use ErikWang2013\Xhprof\Hyperf\ConfigProvider;
use ErikWang2013\Xhprof\Wordpress\XhprofPlugin;
use ErikWang2013\Xhprof\Yii3\XhprofMiddleware as Yii3XhprofMiddleware;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface as PsrServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * 跨框架接线冒烟：门面类存在性 + 10 框架入口类的三条接线
 * （enable=true 记录 / enable=false 不记录 / handler 抛异常时止点仍执行）。
 *
 * 命名统一用 `{framework}Middleware*`，这里的「Middleware」是**入口类**的代称，
 * 各框架的实际形态见下表（命名统一是为了让六框架的接线事实在一处可平铺对照）：
 *   slimMiddleware*     → Slim\XhprofMiddleware（PSR-15）
 *   yii3Middleware*     → Yii3\XhprofMiddleware（PSR-15，工厂构造函数）
 *   symfonyMiddleware*  → Symfony\XhprofListener（事件订阅者，无 try/finally，止点靠 shutdown 兜底）
 *   wordpressMiddleware*→ Wordpress\XhprofPlugin（钩子：plugins_loaded → shutdown）
 *   joomlaMiddleware*   → Joomla\Extension\Xhprof（事件：onAfterInitialise → onAfterRespond + shutdown 兜底）
 *   drupalMiddleware*   → Drupal\XhprofMiddleware（HttpKernel 装饰器，有 try/finally）
 *
 * 驱动方式一律照搬各自 tests/Unit/Adapter/<Fw>Test.php 里既有那套（各自的假件/钩子注册表/
 * 缓存实现不同），不另造一套。
 */
class WiringTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $saved = [];

    /** @var array<string, mixed> */
    private array $savedServer = [];

    /** @var array<string, mixed> */
    private array $savedGet = [];

    /** @var array<string, mixed> */
    private array $savedPost = [];

    /** @var array<string, mixed> */
    private array $savedRequest = [];

    protected function setUp(): void
    {
        // WordPress / Joomla 的适配器读超全局。这里只快照不赋值：不改变既有 6 个框架用例
        // 看到的环境，用例自己摆（摆过的东西 tearDown 一定会还原）。
        $this->savedServer = $_SERVER;
        $this->savedGet = $_GET;
        $this->savedPost = $_POST;
        $this->savedRequest = $_REQUEST;
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
        // Joomla 的 Xhprof::$stopped 是跨用例存活的静态量。用例若停在「采样中」（断言失败时
        // 就会这样），残留状态会污染下一个用例。补一次收尾，且放在 restore 之前 ——
        // 此刻落库打的是本用例注入的 FakeCache，不会碰真 Redis。
        JoomlaXhprof::stopSampling();

        $_SERVER = $this->savedServer;
        $_GET = $this->savedGet;
        $_POST = $this->savedPost;
        $_REQUEST = $this->savedRequest;
        WordpressHooks::reset();
        $this->removeJoomlaSiteConfig();

        $this->restoreXhprofStatics($this->saved);
        xhprof_disable();
    }

    /** 站点根里的覆盖文件是本类写进去的（见 writeJoomlaSiteConfig），用完全相对路径级的方式删干净 */
    public static function tearDownAfterClass(): void
    {
        if (!defined('JPATH_ROOT')) {
            return;
        }
        $file = (string) JPATH_ROOT . '/xhprof.php';
        if (is_file($file)) {
            unlink($file);
        }
    }

    private function snapshotXhprofStatics(): array
    {
        $hyperf = new \ReflectionProperty(CoreXhprof::class, '_hyperf');
        $hyperf->setAccessible(true);   // PHP 8.0 需要；8.1+ 是 no-op

        return [
            // 私有静态、无 setter（生产上刻意不可逆）。漏了它，本进程其后所有测试都会
            // 走 Hyperf 分支——观察点是反射探针量到的 false→true，不是推断。
            '_hyperf' => $hyperf->getValue(),
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
        $hyperf = new \ReflectionProperty(CoreXhprof::class, '_hyperf');
        $hyperf->setAccessible(true);
        // 双参（null 打头）：静态属性的单参形式在 PHP 8.3 起已废弃。
        $hyperf->setValue(null, $s['_hyperf']);

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
        $this->assertCount(1, $store['xhprof:run_id']);
        $rid = $store['xhprof:run_id'][0];
        $this->assertIsString($rid);
        $this->assertNotEmpty($rid);
        $this->assertArrayHasKey("xhprof:request_log:$rid", $store);
        $this->assertArrayHasKey("xhprof:xhprof_log:$rid", $store);

        // 只断言"键存在"等于没测：把 stop() 改成 save_run([])（采样结果丢弃）
        // 整个套件照样全绿。必须验证落库的确实是本次采样数据。
        $data = unserialize($store["xhprof:xhprof_log:$rid"]);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('main()', $data, '采样数据缺少 main() 帧 → 报告页会是空的');
        return $rid;
    }

    /**
     * FakeCache 版的同一件事（Slim / Yii3 / Symfony / WordPress / Joomla / Drupal 六家用它）。
     *
     * FakeCache 的 $store/$lists 是 private，只能走 CacheInterface 的公开方法读回来 ——
     * 读回来的仍是本次真实采样数据，所以「只断言键存在」那种空转不会发生。
     */
    private function assertRunSavedInCache(FakeCache $cache): string
    {
        $rids = $cache->lRange('xhprof:run_id', 0, -1);
        $this->assertCount(1, $rids, 'run_id 列表里应当恰有本次采样的一条记录');
        $rid = $rids[0];
        $this->assertIsString($rid);
        $this->assertNotEmpty($rid);
        $this->assertIsString($cache->get("xhprof:request_log:$rid"));
        $data = unserialize((string) $cache->get("xhprof:xhprof_log:$rid"));
        $this->assertIsArray($data);
        $this->assertArrayHasKey('main()', $data, '采样数据缺少 main() 帧 → 报告页会是空的');
        return $rid;
    }

    /** PSR-15 风格的下游 handler（Slim / Yii3 用） */
    private function psrHandler(?PsrResponseInterface $response = null): RequestHandlerInterface
    {
        return new class($response ?? new FakePsrResponse(200, [], 'ok')) implements RequestHandlerInterface {
            public bool $called = false;

            private PsrResponseInterface $response;

            public function __construct(PsrResponseInterface $response)
            {
                $this->response = $response;
            }

            public function handle(PsrServerRequestInterface $request): PsrResponseInterface
            {
                $this->called = true;
                return $this->response;
            }
        };
    }

    /**
     * send() 会 echo（WordPress / Joomla 的响应没有可 return 的对象）：必须 ob_start() 包住，
     * 否则 phpunit.xml 的 failOnRisky 直接判失败。
     */
    private function capture(callable $fn): string
    {
        ob_start();
        try {
            $fn();
        } finally {
            $out = (string) ob_get_clean();
        }
        return $out;
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
        // webman 的 $body 是 protected（workerman 5.2.2 :270），读正文走 rawBody()
        $this->assertSame('ok', $captured->rawBody());
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

        $this->assertSame('ok', $res->rawBody());
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
        $this->assertSame('ok', $captured->getContent());
        $this->assertRunSaved(IlluminateRedis::$store);
    }

    #[Test]
    public function laravelMiddlewareSkipsWhenDisabled(): void
    {
        Registry::$laravelConfig = ['xhprof' => ['enable' => false]];
        $handler = fn (IlluminateRequest $r): IlluminateResponse => new IlluminateResponse('ok');
        $res = (new LaravelMiddleware())->handle(new IlluminateRequest([], ['uri' => '/index']), $handler);

        $this->assertSame('ok', $res->getContent());
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
        // think 的 $content 是 protected（topthink/framework 8.1.4 :69），读内容走 getContent()
        $this->assertSame('ok', $captured->getContent());
        $store = ThinkCache::store('redis');
        $this->assertRunSaved($store->data + $store->handler()->data);
    }

    #[Test]
    public function thinkphpMiddlewareSkipsWhenDisabled(): void
    {
        ThinkConfig::$data = ['xhprof' => ['enable' => false]];
        $handler = fn (ThinkRequest $r): ThinkResponse => new ThinkResponse('ok');
        $res = (new ThinkphpMiddleware())->handle(new ThinkRequest([], ['uri' => '/index']), $handler);

        $this->assertSame('ok', $res->getContent());
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
        return new FakeServerRequest();
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
                return new FakePsrResponse();
            }
        };
    }

    // ---------- Slim 全流程 ----------

    private function slimMiddleware(FakeCache $cache, array $config): SlimXhprofMiddleware
    {
        return new SlimXhprofMiddleware(new FakeResponseFactory(), $config, $cache, new SlimLogAdapter());
    }

    #[Test]
    public function slimMiddlewareRecordsWhenEnabled(): void
    {
        $cache = new FakeCache();
        $handler = $this->psrHandler(new FakePsrResponse(200, [], 'ok'));
        $result = $this->slimMiddleware($cache, ['enable' => true])
            ->process(new FakeServerRequest('GET', 'http://example.com/index?x=1'), $handler);

        $this->assertTrue($handler->called);
        $this->assertSame('ok', (string) $result->getBody(), '正常路径必须原样返回下游响应');
        $this->assertRunSavedInCache($cache);
    }

    #[Test]
    public function slimMiddlewareSkipsWhenDisabled(): void
    {
        $cache = new FakeCache();
        $handler = $this->psrHandler();
        $result = $this->slimMiddleware($cache, ['enable' => false])
            ->process(new FakeServerRequest('GET', 'http://example.com/index'), $handler);

        $this->assertTrue($handler->called);
        $this->assertSame(200, $result->getStatusCode());
        $this->assertNull(xhprof_disable(), 'enable=false 不应启动采样');
        $this->assertSame([], $cache->calls, 'disable 时一次缓存调用都不该有');
    }

    #[Test]
    public function slimMiddlewareFinallyRunsWhenHandlerThrows(): void
    {
        $cache = new FakeCache();
        $handler = new class implements RequestHandlerInterface {
            public bool $ran = false;

            public function handle(PsrServerRequestInterface $request): PsrResponseInterface
            {
                $this->ran = true;
                throw new \RuntimeException('handler boom');
            }
        };
        $e = $this->captureError(
            fn () => $this->slimMiddleware($cache, ['enable' => true])
                ->process(new FakeServerRequest('GET', 'http://example.com/index'), $handler)
        );

        $this->assertTrue($handler->ran);
        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertSame('handler boom', $e->getMessage(), '业务异常必须继续上抛，不能被吞掉');
        $this->assertRunSavedInCache($cache);
        $this->assertEmpty(xhprof_disable(), 'finally 里 stop 过之后不该还有可导出的采样状态');
    }

    // ---------- Yii3 全流程 ----------

    private function yii3Middleware(FakeCache $cache, array $config): Yii3XhprofMiddleware
    {
        return new Yii3XhprofMiddleware(new FakeResponseFactory(), $config, $cache);
    }

    #[Test]
    public function yii3MiddlewareRecordsWhenEnabled(): void
    {
        $cache = new FakeCache();
        $handler = $this->psrHandler(new FakePsrResponse(200, [], 'ok'));
        $result = $this->yii3Middleware($cache, ['enable' => true])
            ->process(new FakeServerRequest('GET', 'http://example.com/index?x=1'), $handler);

        $this->assertTrue($handler->called);
        $this->assertSame('ok', (string) $result->getBody());
        $this->assertRunSavedInCache($cache);
    }

    #[Test]
    public function yii3MiddlewareSkipsWhenDisabled(): void
    {
        $cache = new FakeCache();
        $handler = $this->psrHandler();
        $result = $this->yii3Middleware($cache, ['enable' => false])
            ->process(new FakeServerRequest('GET', 'http://example.com/index'), $handler);

        $this->assertTrue($handler->called);
        $this->assertSame(200, $result->getStatusCode());
        $this->assertNull(xhprof_disable(), 'enable=false 不应启动采样');
        $this->assertSame([], $cache->calls, 'disable 时一次缓存调用都不该有');
    }

    #[Test]
    public function yii3MiddlewareFinallyRunsWhenHandlerThrows(): void
    {
        $cache = new FakeCache();
        $handler = new class implements RequestHandlerInterface {
            public bool $ran = false;

            public function handle(PsrServerRequestInterface $request): PsrResponseInterface
            {
                $this->ran = true;
                throw new \RuntimeException('handler boom');
            }
        };
        $e = $this->captureError(
            fn () => $this->yii3Middleware($cache, ['enable' => true])
                ->process(new FakeServerRequest('GET', 'http://example.com/index'), $handler)
        );

        $this->assertTrue($handler->ran);
        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertSame('handler boom', $e->getMessage());
        $this->assertRunSavedInCache($cache);
        $this->assertEmpty(xhprof_disable(), 'finally 里 stop 过之后不该还有可导出的采样状态');
    }

    // ---------- Symfony 全流程 ----------

    private function symfonyKernel(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            public function handle(
                SymfonyRequest $request,
                int $type = self::MAIN_REQUEST,
                bool $catch = true
            ): SymfonyResponse {
                return new SymfonyResponse('business');
            }
        };
    }

    private function symfonyRequestEvent(string $uri): RequestEvent
    {
        return new RequestEvent(
            $this->symfonyKernel(),
            SymfonyRequest::create($uri),
            HttpKernelInterface::MAIN_REQUEST
        );
    }

    private function symfonyResponseEvent(RequestEvent $requestEvent): ResponseEvent
    {
        return new ResponseEvent(
            $this->symfonyKernel(),
            $requestEvent->getRequest(),
            $requestEvent->getRequestType(),
            new SymfonyResponse('business')
        );
    }

    #[Test]
    public function symfonyMiddlewareRecordsWhenEnabled(): void
    {
        $cache = new FakeCache();
        $listener = new XhprofListener(['enable' => true], $cache);
        $event = $this->symfonyRequestEvent('http://example.com/index?x=1');

        $listener->onRequest($event);
        $this->assertFalse($event->hasResponse(), '普通请求不应短路');
        $listener->onResponse($this->symfonyResponseEvent($event));

        $this->assertRunSavedInCache($cache);
    }

    #[Test]
    public function symfonyMiddlewareSkipsWhenDisabled(): void
    {
        $cache = new FakeCache();
        $listener = new XhprofListener(['enable' => false], $cache);
        $event = $this->symfonyRequestEvent('http://example.com/index');

        $listener->onRequest($event);
        $listener->onResponse($this->symfonyResponseEvent($event));

        $this->assertNull(xhprof_disable(), 'enable=false 不应启动采样');
        $this->assertSame([], $cache->calls, 'disable 时一次缓存调用都不该有');
    }

    /**
     * 起采样 → kernel 重抛（无人产出响应）→ 进程结束，只可能由 shutdown 兜底落库。
     *
     * **必须真子进程**：register_shutdown_function 的时机在进程内无法观测（SymfonyTest
     * 的 shutdownFallbackSavesWhenKernelRethrows 同此做法，这里只是把同一条接线放进
     * 六框架对照表）。闭包在监听器的 shutdown 回调**之后**注册，读到的是兜底跑完的状态。
     *
     * @return array{runs:int, hasMain:bool}
     */
    private function symfonyRethrowScenario(): array
    {
        $script = sys_get_temp_dir() . '/xhprof-wiring-symfony-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, <<<'PHP'
<?php

use ErikWang2013\Xhprof\Symfony\XhprofListener;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

require $argv[1] . '/vendor/autoload.php';
require $argv[1] . '/tests/Fixtures/Fakes.php';
require $argv[1] . '/tests/Stubs/Framework/Symfony.php';

$cache = new FakeCache();
$listener = new XhprofListener(['enable' => true], $cache);

$kernel = new class implements HttpKernelInterface {
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        throw new \RuntimeException('handler boom');
    }
};

$request = Request::create('/index');
$listener->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

// kernel.request 已经跑过（采样在跑）；kernel 重抛 → kernel.response 永不触发
try {
    $kernel->handle($request);
} catch (\RuntimeException $e) {
    // 无人产出响应：不调 onResponse，直接结束进程
}

register_shutdown_function(static function () use ($cache): void {
    $runs = $cache->lRange('xhprof:run_id', 0, -1);
    $data = $runs === [] ? null : unserialize((string) $cache->get('xhprof:xhprof_log:' . $runs[0]));
    echo json_encode([
        'runs' => count($runs),
        'hasMain' => is_array($data) && array_key_exists('main()', $data),
    ]), "\n";
});
PHP
        );

        try {
            $proc = proc_open(
                [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL', $script, dirname(__DIR__, 3)],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            $this->assertIsResource($proc, 'proc_open 失败');
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
        } finally {
            unlink($script);
        }

        $decoded = json_decode(trim($stdout), true);
        $this->assertIsArray($decoded, "子进程未输出合法 JSON（exit {$code}）：" . trim($stderr !== '' ? $stderr : $stdout));
        return $decoded;
    }

    #[Test]
    public function symfonyMiddlewareFinallyRunsWhenHandlerThrows(): void
    {
        // Symfony 的入口是事件监听器，没有 try/finally：「业务抛异常 → 止点仍执行」靠的是
        // kernel.request 时注册的 shutdown 兜底（XhprofListener::registerShutdownStop）。
        $this->assertSame(
            ['runs' => 1, 'hasMain' => true],
            $this->symfonyRethrowScenario(),
            'kernel 重抛时 shutdown 兜底必须把采样落库，且恰好一条（不是空数据、不是两条）'
        );
    }

    // ---------- WordPress 全流程 ----------

    /** 与 WordpressTest::setUp 同一组环境（WP 适配器全走超全局） */
    private function wordpressEnv(): void
    {
        $_SERVER = [
            'REQUEST_URI' => '/index.php?p=1',
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'example.com',
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        $_GET = [];
        $_POST = [];
    }

    #[Test]
    public function wordpressMiddlewareRecordsWhenEnabled(): void
    {
        $this->wordpressEnv();
        $cache = new FakeCache();
        $logger = new FakeLogger();
        $plugin = new XhprofPlugin(['enable' => true], $cache, $logger);
        $plugin->register();

        // send() 会 echo：不包住的话 failOnRisky 判失败（报告页短路那条路径由 Wave 1 覆盖）
        $this->capture(fn () => WordpressHooks::do('plugins_loaded'));
        $this->assertSame(1, WordpressHooks::count('shutdown'), '开始采样后必须挂上止点');
        $this->assertSame([], $cache->calls, '此时还没落库');

        $this->capture(fn () => WordpressHooks::do('shutdown'));

        $this->assertRunSavedInCache($cache);
        $this->assertSame([], $logger->errors);
    }

    #[Test]
    public function wordpressMiddlewareSkipsWhenDisabled(): void
    {
        $this->wordpressEnv();
        $cache = new FakeCache();
        $plugin = new XhprofPlugin(['enable' => false], $cache, new FakeLogger());
        $plugin->register();

        $this->capture(fn () => WordpressHooks::do('plugins_loaded'));
        $this->capture(fn () => WordpressHooks::do('shutdown'));

        $this->assertNull(xhprof_disable(), 'enable=false 不应启动采样');
        $this->assertSame(0, WordpressHooks::count('shutdown'), 'enable=false 时不该挂止点');
        $this->assertSame([], $cache->calls);
    }

    #[Test]
    public function wordpressMiddlewareFinallyRunsWhenHandlerThrows(): void
    {
        $this->wordpressEnv();
        $cache = new FakeCache();
        $plugin = new XhprofPlugin(['enable' => true], $cache, new FakeLogger());
        $plugin->register();
        $this->capture(fn () => WordpressHooks::do('plugins_loaded'));

        // WordPress 没有 try/finally：止点挂在 shutdown 动作上，业务抛异常时它照样触发
        $e = $this->captureError(static function (): void {
            throw new \RuntimeException('handler boom');
        });
        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertSame([], $cache->calls, '异常路径下不该在止点之前落库');

        $this->capture(fn () => WordpressHooks::do('shutdown'));

        $this->assertRunSavedInCache($cache);
    }

    // ---------- Joomla 全流程 ----------

    /**
     * 站点根。JPATH_ROOT 全进程只能定义一次，且 JoomlaTest 也用它 —— 两边的 writeSiteConfig
     * 必须落在同一个目录，否则谁先跑谁就把对方写的覆盖文件变成写进空气
     * （JoomlaTest::$root 是 private，故此处沿用它在 setUpBeforeClass 里的同一路径约定）。
     */
    private function joomlaRoot(): string
    {
        if (!defined('JPATH_ROOT')) {
            define('JPATH_ROOT', sys_get_temp_dir() . '/xhprof-joomla-root-' . getmypid());
        }
        $root = (string) JPATH_ROOT;
        if (!is_dir($root)) {
            mkdir($root, 0777, true);
        }
        return $root;
    }

    /** 站点根覆盖文件（入口类读的两处配置来源之一：包内 config + 站点根 xhprof.php） */
    private function writeJoomlaSiteConfig(array $config): void
    {
        file_put_contents(
            $this->joomlaRoot() . '/xhprof.php',
            "<?php\n\nreturn " . var_export($config, true) . ";\n"
        );
    }

    private function removeJoomlaSiteConfig(): void
    {
        if (!defined('JPATH_ROOT')) {
            return;
        }
        $file = (string) JPATH_ROOT . '/xhprof.php';
        if (is_file($file)) {
            unlink($file);
        }
    }

    /** @param array<string, mixed> $server */
    private function joomlaApp(array $server): JoomlaFakeApplication
    {
        $_REQUEST = [];
        $_SERVER = $server;
        return new JoomlaFakeApplication();
    }

    private function joomlaPlugin(JoomlaFakeApplication $app, FakeCache $cache): JoomlaXhprof
    {
        $plugin = new JoomlaXhprof(new JoomlaNoopDispatcher(), [], $cache, new FakeLogger());
        $plugin->setApplication($app);
        return $plugin;
    }

    #[Test]
    public function joomlaMiddlewareRecordsWhenEnabled(): void
    {
        $cache = new FakeCache();
        $app = $this->joomlaApp([
            'REQUEST_URI' => '/index.php?x=1',
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'example.com',
            'REMOTE_ADDR' => '10.0.0.1',
        ]);
        $plugin = $this->joomlaPlugin($app, $cache);

        $this->capture(fn () => $plugin->onAfterInitialise());
        $this->assertSame(0, $app->closeCalls, '普通请求不短路、不输出');
        $this->assertSame([], $cache->calls, '此时还没落库');

        $plugin->onAfterRespond();

        $this->assertRunSavedInCache($cache);
    }

    #[Test]
    public function joomlaMiddlewareSkipsWhenDisabled(): void
    {
        // 配置只来自包内 config/xhprof.php + 站点根 xhprof.php（入口类刻意不读插件参数），
        // 所以 enable=false 只能在站点根落一个覆盖文件 —— 与 JoomlaTest 的 writeSiteConfig() 同一做法。
        $this->writeJoomlaSiteConfig(['enable' => false]);
        $cache = new FakeCache();
        $app = $this->joomlaApp(['REQUEST_URI' => '/index.php', 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com']);
        $plugin = $this->joomlaPlugin($app, $cache);

        $this->capture(fn () => $plugin->onAfterInitialise());
        $plugin->onAfterRespond();

        $this->assertNull(xhprof_disable(), 'enable=false 不应启动采样');
        $this->assertSame(0, $app->closeCalls);
        $this->assertSame([], $cache->calls);
    }

    #[Test]
    public function joomlaMiddlewareFinallyRunsWhenHandlerThrows(): void
    {
        $cache = new FakeCache();
        $app = $this->joomlaApp(['REQUEST_URI' => '/index.php', 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com']);
        $plugin = $this->joomlaPlugin($app, $cache);
        $this->capture(fn () => $plugin->onAfterInitialise());

        // 业务抛异常时 onAfterRespond（Joomla 的常规止点）不会到达，兜底是唯一出路：
        // stopSampling() 正是注册给 register_shutdown_function 的那同一个方法。
        $e = $this->captureError(static function (): void {
            throw new \RuntimeException('handler boom');
        });
        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertSame([], $cache->calls, '异常路径下不该在止点之前落库');

        JoomlaXhprof::stopSampling();

        $this->assertRunSavedInCache($cache);
    }

    // ---------- Drupal 全流程 ----------

    private function drupalKernel(?callable $handler = null): HttpKernelInterface
    {
        return new class($handler) implements HttpKernelInterface {
            /** @var callable|null */
            private $handler;

            public function __construct(?callable $handler)
            {
                $this->handler = $handler;
            }

            public function handle(
                SymfonyRequest $request,
                int $type = self::MAIN_REQUEST,
                bool $catch = true
            ): SymfonyResponse {
                if ($this->handler !== null) {
                    return ($this->handler)();
                }
                return new SymfonyResponse('business');
            }
        };
    }

    private function drupalMiddleware(FakeCache $cache, array $config, ?callable $handler = null): DrupalXhprofMiddleware
    {
        return new DrupalXhprofMiddleware(
            $this->drupalKernel($handler),
            new FakeConfigFactory(['xhprof.settings' => $config]),
            new FakeLoggerFactory(),
            $cache
        );
    }

    #[Test]
    public function drupalMiddlewareRecordsWhenEnabled(): void
    {
        $cache = new FakeCache();
        $response = $this->drupalMiddleware($cache, ['enable' => true])->handle(SymfonyRequest::create('/index?x=1'));

        $this->assertSame('business', $response->getContent(), '业务响应必须原样返回');
        $this->assertRunSavedInCache($cache);
    }

    #[Test]
    public function drupalMiddlewareSkipsWhenDisabled(): void
    {
        $cache = new FakeCache();
        $this->drupalMiddleware($cache, ['enable' => false])->handle(SymfonyRequest::create('/index'));

        $this->assertNull(xhprof_disable(), 'enable=false 不应启动采样');
        $this->assertSame([], $cache->calls, '不采样就不该碰缓存');
    }

    #[Test]
    public function drupalMiddlewareFinallyRunsWhenHandlerThrows(): void
    {
        $cache = new FakeCache();
        $middleware = $this->drupalMiddleware($cache, ['enable' => true], static function (): SymfonyResponse {
            throw new \RuntimeException('handler boom');
        });
        $e = $this->captureError(fn () => $middleware->handle(SymfonyRequest::create('/index')));

        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertSame('handler boom', $e->getMessage(), '业务异常必须继续上抛，不能被中间件吞掉');
        $this->assertRunSavedInCache($cache);
        $this->assertNull(xhprof_disable(), '异常路径也必须停掉采样');
    }
}
