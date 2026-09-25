<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface as CoreLoggerInterface;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\Xhprof as CoreXhprof;
use ErikWang2013\Xhprof\Symfony\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Symfony\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Symfony\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Symfony\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Symfony\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Symfony\XhprofListener;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Symfony 适配器与入口类。桩在 tests/Stubs/Framework/Symfony.php，
 * 同一批语义由 tools/contracts/cases/Symfony.php 用真实 symfony/http-foundation
 * 与 symfony/http-kernel 再验一遍（含真实 EventDispatcher 的优先级顺序）。
 *
 * 三个守卫各自有一条「去掉就变红」的用例：
 *  1. 事件优先级          → subscribedEventsPinPriorities（数值）+ 验证环 L2（真实顺序）
 *  2. 子请求 isMainRequest → subRequestDoesNotStartSampling / subRequestResponseDoesNotStopMainSampling
 *  3. 幂等 shutdown 兜底   → secondStopIsHarmless + shutdownFallbackSavesWhenKernelRethrows
 */
class SymfonyTest extends TestCase
{
    /** @var array<int, string> */
    private array $tempFiles = [];

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->saved = $this->snapshotXhprofStatics();
    }

    protected function tearDown(): void
    {
        // XhprofListener::$stopped 是跨用例存活的静态量。用例若在中途留下「采样中」
        // （那是为了观测子请求没有提前 stop），下一个 enable=false 的用例就会被它污染：
        // onResponse 会把残留采样停掉并落一条空记录。这里补一次主请求周期的收尾。
        // 放在 restore 之后：Xhprof::$cache 已复位为 null，落库会静默失败，不碰任何 FakeCache。
        (new XhprofListener())->onResponse($this->responseEvent($this->requestEvent('/__teardown')));

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
        $path = sys_get_temp_dir() . '/xhprof-symfony-' . bin2hex(random_bytes(4)) . '.' . $ext;
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

    // ---------- 辅助 ----------

    private function kernel(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
    }

    private function requestEvent(
        string $uri,
        int $type = HttpKernelInterface::MAIN_REQUEST,
        string $method = 'GET'
    ): RequestEvent {
        return new RequestEvent($this->kernel(), Request::create($uri, $method), $type);
    }

    private function responseEvent(RequestEvent $requestEvent): ResponseEvent
    {
        return new ResponseEvent(
            $this->kernel(),
            $requestEvent->getRequest(),
            $requestEvent->getRequestType(),
            new Response('business')
        );
    }

    private function listener(FakeCache $cache, array $config = ['enable' => true]): XhprofListener
    {
        return new XhprofListener($config, $cache);
    }

    /** @return array<int, string> run_id 列表（新→旧） */
    private function runs(FakeCache $cache): array
    {
        return $cache->lRange('xhprof:run_id', 0, -1);
    }

    // ---------- RequestAdapter ----------

    #[Test]
    public function requestAdapterDelegates(): void
    {
        $adapter = new RequestAdapter(Request::create(
            'http://example.com:8080/admin?x=1&y=2',
            'POST',
            ['p' => 1],
            [],
            [],
            ['HTTP_X_FOO' => 'bar', 'REMOTE_ADDR' => '10.0.0.9']
        ));

        $this->assertInstanceOf(RequestInterface::class, $adapter);
        $this->assertSame('1', $adapter->get('x'));
        $this->assertSame(1, $adapter->get('p'));
        $this->assertSame('d', $adapter->get('nope', 'd'));
        $this->assertSame(['x' => '1', 'y' => '2', 'p' => 1], $adapter->all());
        $this->assertSame('POST', $adapter->method());
        $this->assertSame('bar', $adapter->header('X-Foo'));
        $this->assertSame('10.0.0.9', $adapter->getRealIp());
    }

    #[Test]
    public function requestAdapterUriIsPathAndQueryOnly(): void
    {
        // R-1：isIgnore() 对 uri() 做子串匹配，返回绝对 URL 会让 host 叫 xhprof.* 的站点全站被忽略
        $adapter = new RequestAdapter(Request::create('http://example.com/admin?x=1'));
        $this->assertSame('/admin?x=1', $adapter->uri());
        $this->assertStringNotContainsString('://', $adapter->uri());
        $this->assertStringNotContainsString('example.com', $adapter->uri());
    }

    #[Test]
    public function requestAdapterHostHasNoPort(): void
    {
        // R-2：用户拍板只返回 host
        $this->assertSame('example.com', (new RequestAdapter(Request::create('http://example.com:8080/a')))->host());
    }

    #[Test]
    public function requestAdapterUrlIsAbsolute(): void
    {
        $this->assertSame(
            'http://example.com:8080/admin?x=1',
            (new RequestAdapter(Request::create('http://example.com:8080/admin?x=1')))->url()
        );
    }

    #[Test]
    public function requestAdapterHeaderMissingReturnsNull(): void
    {
        // R-3：header() 声明 ?string，无值时必须是 null 而不是 ''
        $this->assertNull((new RequestAdapter(Request::create('/a')))->header('X-Nope'));
    }

    #[Test]
    public function requestAdapterGetRealIpIsNeverNull(): void
    {
        // R-3：getClientIp() 声明 ?string，无 REMOTE_ADDR 时返回 null；
        // getRealIp() 声明 : string，strict_types 下直接透传会 TypeError。
        $request = new Request([], [], [], [], [], ['SERVER_NAME' => 'example.com']);
        $this->assertNull($request->getClientIp(), '前置条件：真实 Request 此刻返回 null');
        $this->assertSame('127.0.0.1', (new RequestAdapter(Request::create('/a')))->getRealIp());
        $this->assertSame('', (new RequestAdapter($request))->getRealIp());
    }

    #[Test]
    public function requestAdapterGetReturnsArrayInsteadOfThrowing(): void
    {
        // ?run[]=a：InputBag::get() 会抛 BadRequestException（Symfony 自带的 400），
        // 而契约声明 mixed，调用点 Xhprof::index() 靠 is_string() 判成我们自己的 400。
        $adapter = new RequestAdapter(Request::create('/xhprof?run[]=a'));
        $this->assertSame(['a'], $adapter->get('run'));
        $this->assertSame(['run' => ['a']], $adapter->all());
    }

    #[Test]
    public function requestAdapterMethodIsUppercased(): void
    {
        $this->assertSame('GET', (new RequestAdapter(Request::create('/a', 'get')))->method());
    }

    // ---------- ResponseAdapter ----------

    #[Test]
    public function responseAdapterChainsAndReturnsItself(): void
    {
        // R-4：Xhprof::deny() 的调用链是 withStatus()->withBody()->send()
        $adapter = new ResponseAdapter();
        $this->assertInstanceOf(ResponseInterface::class, $adapter);
        $this->assertSame($adapter, $adapter->withStatus(403));
        $this->assertSame($adapter, $adapter->withBody('403 Forbidden'));
        $this->assertSame($adapter, $adapter->withHeaders(['X-A' => '1']));

        $response = $adapter->send();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('403 Forbidden', $response->getContent());
        $this->assertSame('1', $response->headers->get('X-A'));
    }

    #[Test]
    public function responseAdapterWithHeadersAcceptsNonStringValues(): void
    {
        // HeaderBag::set() 声明 string|array|null，strict_types 下传 int 会 TypeError
        $adapter = new ResponseAdapter();
        $adapter->withHeaders(['Content-Length' => 12, 'X-Multi' => ['a', 'b']]);
        $response = $adapter->send();
        $this->assertSame('12', $response->headers->get('Content-Length'));
        $this->assertSame('a', $response->headers->get('X-Multi'));
    }

    #[Test]
    public function responseAdapterFileThenHeadersStillApplies(): void
    {
        // R-5：StaticController::serve() 的调用是 file($realFile)->withHeaders([...])
        $path = $this->tempFile('css', 'body{}');
        $adapter = new ResponseAdapter();
        $this->assertSame($adapter, $adapter->file($path));
        $this->assertSame($adapter, $adapter->withHeaders(['Cache-Control' => 'public, max-age=86400']));

        // file() 换了整个 Response 对象，头必须落在新对象上而不是被丢掉的那个
        $response = $adapter->send();
        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame($path, $response->getFile()->getPathname());
        $this->assertStringContainsString('max-age=86400', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function responseAdapterFilePinsContentTypeFromCoreMap(): void
    {
        // 不钉类型的话 Symfony 会按**文件内容**猜：实测本包的 css/js 全被猜成 text/plain
        // （见验证环用 symfony/mime 的实测记录）。这里钉的是 Core 的 MIME 表。
        foreach ([
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'gif' => 'image/gif',
        ] as $ext => $expected) {
            $file = $this->tempFile($ext, 'x');
            $response = (new ResponseAdapter())->file($file)->send();
            $this->assertInstanceOf(BinaryFileResponse::class, $response);
            $this->assertSame($expected, $response->headers->get('Content-Type'), "扩展名 {$ext}");
        }
    }

    #[Test]
    public function responseAdapterFileMissingBecomes404(): void
    {
        // 与 Slim/Yii3/Wordpress 同形：读不出文件给 404，而不是抛 FileNotFoundException
        $response = (new ResponseAdapter())->file($this->tempFile('css') . '.missing')->send();
        $this->assertSame(404, $response->getStatusCode());
        $this->assertNotInstanceOf(BinaryFileResponse::class, $response);
    }

    // ---------- ConfigAdapter ----------

    #[Test]
    public function configAdapterSupportsBothKeyShapes(): void
    {
        // R-6：bootstrap() 用 get('xhprof') 取整块，index() 用 get('xhprof.assets_url') 取叶子
        $config = new ConfigAdapter([]);
        $this->assertInstanceOf(\ErikWang2013\Xhprof\Core\Contract\ConfigInterface::class, $config);

        $block = $config->get('xhprof');
        $this->assertIsArray($block);
        $this->assertTrue($block['enable']);
        $this->assertSame(['/xhprof'], $block['ignore_url_arr']);

        $this->assertSame('/xhprof-assets', $config->get('xhprof.assets_url'));
        $this->assertSame('d', $config->get('xhprof.nope', 'd'));
        $this->assertSame('d', $config->get('nope.nope', 'd'));
    }

    #[Test]
    public function configAdapterUserValuesWin(): void
    {
        $config = new ConfigAdapter(['enable' => false, 'auth_token' => 'secret']);
        $this->assertFalse($config->get('xhprof.enable'));
        $this->assertSame('secret', $config->get('xhprof.auth_token'));
        $this->assertSame(1000, $config->get('xhprof.log_num'), '没覆盖的键取包内默认值');
    }

    #[Test]
    public function configAdapterMergeIsNotRecursive(): void
    {
        // R-7：array_replace_recursive 会把用户写的 [] 按下标合并，默认的 ['/xhprof'] 又冒出来
        $config = new ConfigAdapter(['ignore_url_arr' => []]);
        $this->assertSame([], $config->get('xhprof.ignore_url_arr'));
    }

    // ---------- RedisAdapter ----------

    #[Test]
    public function redisAdapterUsesInjectedInstanceAndTraitSemantics(): void
    {
        $redis = new class {
            /** @var array<int, string> */
            public array $log = [];
            /** @var array<string, mixed> */
            public array $store = [];

            public function get(string $key): mixed
            {
                $this->log[] = "get:$key";
                return $this->store[$key] ?? null;
            }

            public function set(string $key, mixed $value): bool
            {
                $this->log[] = "set:$key";
                $this->store[$key] = $value;
                return true;
            }

            public function setex(string $key, int $ttl, mixed $value): bool
            {
                $this->log[] = "setex:$key:$ttl";
                $this->store[$key] = $value;
                return true;
            }

            public function mget(array $keys): array
            {
                $this->log[] = 'mget:' . count($keys);
                $out = [];
                foreach ($keys as $key) {
                    $out[] = $this->store[$key] ?? null;
                }
                return $out;
            }
        };

        $adapter = new RedisAdapter($redis);
        $this->assertInstanceOf(CacheInterface::class, $adapter);

        $adapter->set('k', 'v', 100);
        $this->assertSame('v', $adapter->get('k'));
        // R-8：ttl<=0 退化为不带过期的 set（phpredis 的 setex 会因 TTL<1 直接报错且不发命令）
        $adapter->set('k2', 'v2', 0);
        // R-8：mget([]) 必须返回 [] 而不是 false —— 且在 trait 内就短路，一个命令都不发
        $this->assertSame([], $adapter->mget([]));
        $this->assertSame(['v'], $adapter->mget(['k']), '正常 mget 仍走客户端');

        $this->assertSame(['setex:k:100', 'get:k', 'set:k2', 'mget:1'], $redis->log);
    }

    #[Test]
    public function redisAdapterWithoutInstanceDoesNotConnectOnConstruct(): void
    {
        // 懒连接：构造时不许建连，否则每个请求都要付一次连接开销（且本机无 Redis 时会直接抛）
        $this->assertInstanceOf(CacheInterface::class, new RedisAdapter());
    }

    // ---------- LogAdapter ----------

    #[Test]
    public function logAdapterForwardsToPsrLogger(): void
    {
        $logger = new class implements LoggerInterface {
            /** @var array<int, string> */
            public array $messages = [];

            public function emergency(string|\Stringable $message, array $context = []): void
            {
            }

            public function alert(string|\Stringable $message, array $context = []): void
            {
            }

            public function critical(string|\Stringable $message, array $context = []): void
            {
            }

            public function error(string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = $message . ':' . json_encode($context);
            }

            public function warning(string|\Stringable $message, array $context = []): void
            {
            }

            public function notice(string|\Stringable $message, array $context = []): void
            {
            }

            public function info(string|\Stringable $message, array $context = []): void
            {
            }

            public function debug(string|\Stringable $message, array $context = []): void
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
            }
        };

        $adapter = new LogAdapter($logger);
        $this->assertInstanceOf(CoreLoggerInterface::class, $adapter);
        $adapter->error('boom', ['a' => 1]);
        $this->assertSame(['boom:{"a":1}'], $logger->messages);
    }

    #[Test]
    public function logAdapterWithoutLoggerIsSilent(): void
    {
        $adapter = new LogAdapter();
        $adapter->error('boom');
        $this->assertInstanceOf(CoreLoggerInterface::class, $adapter);
    }

    // ---------- 入口类：守卫 1（事件优先级） ----------

    #[Test]
    public function subscribedEventsPinPriorities(): void
    {
        $this->assertTrue(is_subclass_of(XhprofListener::class, EventSubscriberInterface::class));
        $this->assertSame([
            KernelEvents::REQUEST => ['onRequest', 10000],
            KernelEvents::RESPONSE => ['onResponse', -10000],
        ], XhprofListener::getSubscribedEvents());
    }

    // ---------- 入口类：报告页 / 静态资源短路 ----------

    #[Test]
    public function reportPathRendersReportWithoutSampling(): void
    {
        $cache = new FakeCache();
        $listener = $this->listener($cache);
        $event = $this->requestEvent('http://example.com/xhprof');

        $listener->onRequest($event);

        $this->assertTrue($event->hasResponse(), '报告页应短路 HttpKernel');
        $this->assertSame(200, $event->getResponse()->getStatusCode());
        $this->assertStringContainsString('XHProf', (string) $event->getResponse()->getContent());
        // 显式设，不靠 ResponseListener 的 prepare() 补（单测里根本没有 ResponseListener）
        $this->assertSame('text/html; charset=UTF-8', $event->getResponse()->headers->get('Content-Type'));
        // no-cache：报告是即时数据，且访问 URL 可能带 ?token=xxx，不能让 HttpCache /
        // 反代留副本。字面量与 Drupal 控制器（六框架里唯一有页面缓存可承重的那家）一致。
        //
        // 注意这条不能裸断字面量：真 ResponseHeaderBag 对**没设过** Cache-Control 的响应会自己
        // 算出一个默认值，恰好也是 'no-cache, private' —— 真包上那样断是恒真的，删掉源码里那行
        // 照样绿（环里踩过这个坑）。所以先施加扰动再读：补一个 Last-Modified 后，**计算值**会
        // 翻成 'private, must-revalidate'，而**显式设过**的值纹丝不动。于是桩（不会算）与真包
        // （会算）两种实现下这条都有判别力。
        // **不要**为了"让桩更忠实"给桩补上那段计算逻辑：桩一旦会算，任何没有扰动的同族断言都会
        // 静默退化成恒真，看起来反而像"改进了"。绊线：DrupalTest::stubDoesNotComputeCacheControlDefaults。
        $event->getResponse()->headers->set('Last-Modified', 'Wed, 01 Jan 2025 00:00:00 GMT');
        $this->assertSame('no-cache, private', $event->getResponse()->headers->get('Cache-Control'));
        $this->assertNull(xhprof_disable(), '报告页请求不应启动采样');
        $this->assertSame([], $this->runs($cache));
    }

    #[Test]
    public function reportPathRejectsInvalidRunIdWith400(): void
    {
        $event = $this->requestEvent('http://example.com/xhprof?run=not-a-run-id');
        $this->listener(new FakeCache())->onRequest($event);

        $this->assertSame(400, $event->getResponse()->getStatusCode());
        $this->assertSame('400 Bad Request', $event->getResponse()->getContent());
    }

    #[Test]
    public function reportPathEnforcesAuthToken(): void
    {
        $cache = new FakeCache();
        $listener = $this->listener($cache, ['enable' => true, 'auth_token' => 'secret']);

        $denied = $this->requestEvent('http://example.com/xhprof');
        $listener->onRequest($denied);
        $this->assertSame(403, $denied->getResponse()->getStatusCode());

        $allowed = $this->requestEvent('http://example.com/xhprof?token=secret');
        $listener->onRequest($allowed);
        $this->assertSame(200, $allowed->getResponse()->getStatusCode());
        $this->assertStringContainsString('XHProf', (string) $allowed->getResponse()->getContent());
        $this->assertSame([], $this->runs($cache));
    }

    #[Test]
    public function assetsPathServesBundledFileWithoutSampling(): void
    {
        $cache = new FakeCache();
        $event = $this->requestEvent('http://example.com/xhprof-assets/css/xhprof.css');
        $this->listener($cache)->onRequest($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertStringEndsWith(
            '/src/html/css/xhprof.css',
            $response->getFile()->getPathname(),
            '必须落在包内 src/html 下（防目录穿越）'
        );
        $this->assertStringContainsString('max-age=86400', (string) $response->headers->get('Cache-Control'));
        $this->assertNull(xhprof_disable(), '静态资源请求不应启动采样');
        $this->assertSame([], $this->runs($cache));
    }

    // ---------- 入口类：守卫 2（子请求） ----------

    #[Test]
    public function subRequestDoesNotStartSampling(): void
    {
        $cache = new FakeCache();
        $listener = $this->listener($cache);

        $listener->onRequest($this->requestEvent('http://example.com/_fragment', HttpKernelInterface::SUB_REQUEST));

        $this->assertNull(xhprof_disable(), '子请求不应启动采样');
        $this->assertSame([], $this->runs($cache));
    }

    #[Test]
    public function subRequestResponseDoesNotStopMainSampling(): void
    {
        $cache = new FakeCache();
        $listener = $this->listener($cache);

        $main = $this->requestEvent('http://example.com/page');
        $listener->onRequest($main);

        // ESI / fragment / forward()：子请求的 request 与 response 都不能碰主请求的采样状态
        $sub = $this->requestEvent('http://example.com/_fragment', HttpKernelInterface::SUB_REQUEST);
        $listener->onRequest($sub);
        $listener->onResponse($this->responseEvent($sub));

        $this->assertSame([], $this->runs($cache), '子请求的 response 提前停了主请求采样，并落了一条不完整数据');

        $sample = xhprof_disable();
        $this->assertIsArray($sample, '主请求的采样被提前终止');
        $this->assertArrayHasKey('main()', $sample);
    }

    // ---------- 入口类：守卫 3（幂等兜底） ----------

    #[Test]
    public function secondStopIsHarmless(): void
    {
        $cache = new FakeCache();
        $listener = $this->listener($cache);
        $event = $this->requestEvent('http://example.com/index');

        $listener->onRequest($event);
        $listener->onResponse($this->responseEvent($event));
        $listener->onResponse($this->responseEvent($event)); // shutdown 兜底与 onResponse 可能都到

        $this->assertCount(1, $this->runs($cache), '二次 stop 不能写第二条（空）采样');
    }

    // ---------- 入口类：三条接线 ----------

    #[Test]
    public function enabledMainRequestSamplesAndSavesOnResponse(): void
    {
        $cache = new FakeCache();
        $listener = $this->listener($cache);
        $event = $this->requestEvent('http://example.com/index?x=1');

        $listener->onRequest($event);
        $this->assertFalse($event->hasResponse(), '普通请求不应短路');

        $listener->onResponse($this->responseEvent($event));

        $runs = $this->runs($cache);
        $this->assertCount(1, $runs);
        $data = unserialize((string) $cache->get('xhprof:xhprof_log:' . $runs[0]));
        $this->assertIsArray($data);
        $this->assertArrayHasKey('main()', $data, '落库的必须是本次真实采样，不是空数据');
    }

    #[Test]
    public function disabledConfigDoesNotSampleNorSave(): void
    {
        $cache = new FakeCache();
        $listener = $this->listener($cache, ['enable' => false]);
        $event = $this->requestEvent('http://example.com/index');

        $listener->onRequest($event);
        $this->assertNull(xhprof_disable(), 'enable=false 不应启动采样');

        $listener->onResponse($this->responseEvent($event));
        $this->assertSame([], $this->runs($cache));
    }

    #[Test]
    public function ignoreUrlConfigSkipsSampling(): void
    {
        // enable=true 但命中 ignore_url_arr：采样会开，但 save_run 里 isIgnore() 拦下不落库
        $cache = new FakeCache();
        $listener = $this->listener($cache, ['enable' => true, 'ignore_url_arr' => ['/admin']]);
        $event = $this->requestEvent('http://example.com/admin/users');

        $listener->onRequest($event);
        $listener->onResponse($this->responseEvent($event));

        $this->assertSame([], $this->runs($cache));
    }

    // ---------- 入口类：shutdown 兜底（真子进程，否则无法观测 shutdown 时机） ----------

    /**
     * @return array{runs:int, hasMain:bool}
     */
    private function runShutdownScenario(string $scenario): array
    {
        $script = sys_get_temp_dir() . '/xhprof-symfony-shutdown-' . bin2hex(random_bytes(4)) . '.php';
        $this->tempFiles[] = $script;
        file_put_contents($script, <<<'PHP'
<?php

use ErikWang2013\Xhprof\Symfony\XhprofListener;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

require $argv[1] . '/vendor/autoload.php';
require $argv[1] . '/tests/Fixtures/Fakes.php';
require $argv[1] . '/tests/Stubs/Framework/Symfony.php';

$scenario = $argv[2];

$cache = new FakeCache();
$listener = new XhprofListener(['enable' => true], $cache);

$kernel = new class implements HttpKernelInterface {
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return new Response('ok');
    }
};

$request = Request::create('/index');
$listener->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

if ($scenario === 'normal') {
    // 正常路径：kernel.response 到达
    $listener->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('ok')));
} elseif ($scenario !== 'rethrow') {
    fwrite(STDERR, "unknown scenario: $scenario\n");
    exit(2);
}
// 'rethrow'：HttpKernel 重抛、kernel.response 永不触发，直接结束进程 —— 只能靠 shutdown 兜底

// 本闭包在监听器的 shutdown 回调**之后**注册，因此读到的是兜底跑完的状态
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

        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL', $script,
                dirname(__DIR__, 3), $scenario],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($proc, 'proc_open 失败');
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $decoded = json_decode(trim($stdout), true);
        $this->assertIsArray($decoded, "子进程未输出合法 JSON（exit {$code}）：" . trim($stderr !== '' ? $stderr : $stdout));
        return $decoded;
    }

    #[Test]
    public function shutdownFallbackSavesWhenKernelRethrows(): void
    {
        // 正常路径：onResponse 落库一次，随后的 shutdown 兜底必须幂等（不是 2 条）
        $this->assertSame(
            ['runs' => 1, 'hasMain' => true],
            $this->runShutdownScenario('normal'),
            '正常路径应恰好落库一次（shutdown 兜底不得重复落库）'
        );

        // 重抛路径：kernel.response 不触发，只有 shutdown 兜底能救
        $this->assertSame(
            ['runs' => 1, 'hasMain' => true],
            $this->runShutdownScenario('rethrow'),
            'kernel 重抛时 shutdown 兜底必须把采样落库'
        );
    }
}
