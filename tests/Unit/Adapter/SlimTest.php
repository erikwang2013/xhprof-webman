<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface as PsrServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface as PsrLoggerInterface;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\StaticController;
use ErikWang2013\Xhprof\Core\Xhprof as CoreXhprof;
use ErikWang2013\Xhprof\Slim\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Slim\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Slim\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Slim\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Slim\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Slim\XhprofMiddleware;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\FakePsrResponse;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\FakeResponseFactory;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\FakeServerRequest;

/**
 * Slim 4 适配器 + 入口中间件。
 *
 * 覆盖不到的部分（刻意不在这里假造）：`Slim\MiddlewareDispatcher` 的真实调度顺序、
 * `$app->add(类名)` 的 CallableResolver 解析 —— 那两个必须用真实 Slim 包，
 * 在 tools/contracts/cases/Slim.php（L2）里钉住。
 */
class SlimTest extends TestCase
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
        $path = sys_get_temp_dir() . '/xhprof-slim-' . bin2hex(random_bytes(4)) . '.' . $ext;
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

    /**
     * 断言一次 save_run 完整落库，返回 run_id。
     *
     * 只断言「键存在」等于没测：把 stop() 改成 save_run([]) 整个套件照样全绿。
     * 这里连采样数据的内容一起验，并刻意**全部走 FakeCache 的公开契约方法**
     * （它的 $store/$lists 是 private，只能通过 CacheInterface 读回来）。
     */
    private function assertRunSaved(FakeCache $cache): string
    {
        $rids = $cache->lRange('xhprof:run_id', 0, -1);
        $this->assertCount(1, $rids, 'run_id 列表里应当恰有本次采样的一条记录');
        $rid = $rids[0];
        $this->assertIsString($rid);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $rid);

        $this->assertIsString($cache->get("xhprof:request_log:$rid"));
        $data = unserialize((string) $cache->get("xhprof:xhprof_log:$rid"));
        $this->assertIsArray($data);
        $this->assertArrayHasKey('main()', $data, '采样数据缺少 main() 帧 → 报告页会是空的');
        return $rid;
    }

    /** @param array<string, mixed> $config */
    private function middleware(FakeCache $cache, array $config, ?PsrLoggerInterface $logger = null): XhprofMiddleware
    {
        return new XhprofMiddleware(
            new FakeResponseFactory(),
            $config,
            $cache,
            new LogAdapter($logger)
        );
    }

    private function handlerReturning(PsrResponseInterface $response): RequestHandlerInterface
    {
        return new class($response) implements RequestHandlerInterface {
            private PsrResponseInterface $response;

            public function __construct(PsrResponseInterface $response)
            {
                $this->response = $response;
            }

            public function handle(PsrServerRequestInterface $request): PsrResponseInterface
            {
                return $this->response;
            }
        };
    }

    // ---------- RequestAdapter ----------

    #[Test]
    public function requestAdapterDelegatesQueryAndParsedBody(): void
    {
        $request = new FakeServerRequest('POST', 'http://example.com/list?page=2', [], [], 'ignored');
        $request = $request->withParsedBody(['from_body' => 'yes']);
        $adapter = new RequestAdapter($request);

        $this->assertInstanceOf(RequestInterface::class, $adapter);
        $this->assertSame('2', $adapter->get('page'), 'query 必须能取到');
        $this->assertSame('yes', $adapter->get('from_body'), 'parsed body 也要能取到');
        $this->assertSame('d', $adapter->get('nope', 'd'));
        $this->assertSame(['page' => '2', 'from_body' => 'yes'], $adapter->all());
        $this->assertSame('POST', $adapter->method());
    }

    #[Test]
    public function requestAdapterGetAndAllAgreeWhenQueryAndBodyCollide(): void
    {
        // get() 与 all() 是同一个契约的两种访问方式，对同一请求**必须**给同一答案。
        // 曾经 get() 是 query→body 逐级回退、all() 是 array_replace(query, body)（body 胜出），
        // 于是 `?run=<合法>` + POST `run=<任意>` 时 get('run') 给合法值、all()['run'] 给任意值。
        // Core 的 Xhprof::index() 恰好两条路都用：前者做白名单校验，后者交给渲染 ——
        // 结果是「校验合法值、渲染非法值」，外层校验被绕过。这条断言把分叉钉死。
        $request = (new FakeServerRequest('POST', 'http://example.com/xhprof?run=0123456789abcdef&only_query=1'))
            ->withParsedBody(['run' => 'EVIL', 'only_body' => 2]);
        $adapter = new RequestAdapter($request);

        $this->assertSame(
            $adapter->get('run'),
            $adapter->all()['run'],
            'get() 与 all() 对同名冲突参数必须一致，否则 Core 的校验与渲染会各拿一个值'
        );
        $this->assertSame('0123456789abcdef', $adapter->get('run'), '冲突时 query 优先');
        $this->assertSame('0123456789abcdef', $adapter->all()['run'], 'all() 的冲突解也必须与 get() 相同');

        // 合并语义本身不能被这条修复搞坏：两边各自的键都要还在
        $this->assertSame('1', $adapter->get('only_query'), '仅 query 有的键');
        $this->assertSame('1', $adapter->all()['only_query']);
        $this->assertSame(2, $adapter->get('only_body'), '仅 body 有的键');
        $this->assertSame(2, $adapter->all()['only_body']);
        $this->assertSame('d', $adapter->get('nope', 'd'), '两边都没有时给默认值');
    }

    #[Test]
    public function requestAdapterAllIsArrayEvenWithoutParsedBody(): void
    {
        // getParsedBody() 对非表单请求返回 null；all() 声明 : array，透传会 TypeError
        $adapter = new RequestAdapter(new FakeServerRequest('GET', '/x?a=1'));
        $this->assertSame(['a' => '1'], $adapter->all());
    }

    #[Test]
    public function requestAdapterHeaderIsCaseInsensitiveAndNullWhenMissing(): void
    {
        // R-3：`: string` 的方法绝不能返回 null，但 header() 声明的是 ?string，
        // 缺省必须是 null（不是 ''）。
        $adapter = new RequestAdapter(new FakeServerRequest('GET', '/', [], ['X-Forwarded-Proto' => 'https']));

        $this->assertSame('https', $adapter->header('x-forwarded-proto'));
        $this->assertNull($adapter->header('X-Nope'));
    }

    #[Test]
    public function requestAdapterHostHasNoPort(): void
    {
        // R-2：host() 只返回 host，不含端口
        $adapter = new RequestAdapter(new FakeServerRequest('GET', 'http://example.com:8080/list'));
        $this->assertSame('example.com', $adapter->host());
        $this->assertStringNotContainsString('8080', $adapter->host());
    }

    #[Test]
    public function requestAdapterUriIsPathAndQueryOnly(): void
    {
        // R-1：uri() 绝不含 scheme/host。XhprofLib::isIgnore() 对它做 strpos 子串匹配，
        // 返回绝对 URL 会让 host 叫 xhprof.* 的站点每个请求都被误判为需忽略。
        $adapter = new RequestAdapter(new FakeServerRequest('GET', 'http://xhprof.example.com/list?page=2&x=1'));

        $this->assertSame('/list?page=2&x=1', $adapter->uri());
        $this->assertStringNotContainsString('://', $adapter->uri());
        $this->assertStringNotContainsString('xhprof.example.com', $adapter->uri());
    }

    #[Test]
    public function requestAdapterUriHasNoTrailingQuestionMark(): void
    {
        $adapter = new RequestAdapter(new FakeServerRequest('GET', 'http://example.com/list'));
        $this->assertSame('/list', $adapter->uri());
    }

    #[Test]
    public function requestAdapterUrlIsAbsolute(): void
    {
        $adapter = new RequestAdapter(new FakeServerRequest('GET', 'https://example.com:8080/list?page=2'));
        $this->assertSame('https://example.com:8080/list?page=2', $adapter->url());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function realIpProvider(): array
    {
        return [
            'HTTP_X_FORWARDED_FOR 多 IP 取第一个并去空格' => [
                ['HTTP_X_FORWARDED_FOR' => '1.2.3.4, 5.6.7.8', 'REMOTE_ADDR' => '9.9.9.9'],
                '1.2.3.4',
            ],
            '小写 x-forwarded-for（Swoole 形态）' => [
                ['x-forwarded-for' => '2.2.2.2', 'REMOTE_ADDR' => '9.9.9.9'],
                '2.2.2.2',
            ],
            'HTTP_X_REAL_IP 兜底' => [
                ['HTTP_X_REAL_IP' => '8.8.8.8', 'REMOTE_ADDR' => '9.9.9.9'],
                '8.8.8.8',
            ],
            'REMOTE_ADDR 兜底' => [
                ['REMOTE_ADDR' => '7.7.7.7'],
                '7.7.7.7',
            ],
            '全缺省回落 127.0.0.1' => [
                [],
                '127.0.0.1',
            ],
            '空字符串不覆盖兜底' => [
                ['HTTP_X_FORWARDED_FOR' => '', 'REMOTE_ADDR' => '7.7.7.7'],
                '7.7.7.7',
            ],
        ];
    }

    #[Test]
    #[DataProvider('realIpProvider')]
    public function requestAdapterGetRealIp(array $serverParams, string $expected): void
    {
        $adapter = new RequestAdapter(new FakeServerRequest('GET', '/', $serverParams));
        $this->assertSame($expected, $adapter->getRealIp());
    }

    // ---------- ResponseAdapter ----------

    #[Test]
    public function responseAdapterBodySurvivesHeadersAndStatus(): void
    {
        // R-4：withX() 一律 return $this，链式可调用
        $adapter = new ResponseAdapter(new FakePsrResponse());
        $this->assertInstanceOf(ResponseInterface::class, $adapter);

        $sent = $adapter->withStatus(201)->withBody('hello')->withHeaders(['X-A' => '1'])->send();

        $this->assertSame(201, $sent->getStatusCode());
        $this->assertSame('1', $sent->getHeaderLine('x-a'));
        // 注意：PSR-7 响应**没有** __toString()，读回内容只能走 getBody()
        $this->assertSame('hello', (string) $sent->getBody());
    }

    #[Test]
    public function responseAdapterWithBodyWritesInPlace(): void
    {
        // 就地写的核心不变量：写完之后 withHeader() 产出的 clone 仍能读到内容。
        // 这正是 R-5 依赖的 PSR-7 clone 共享 stream 语义。
        $inner = new FakePsrResponse();
        $adapter = new ResponseAdapter($inner);
        $adapter->withBody('page-html');

        $withHeader = $inner->withHeader('Cache-Control', 'public, max-age=86400');
        $this->assertSame('page-html', (string) $withHeader->getBody());
        $this->assertSame('public, max-age=86400', $withHeader->getHeaderLine('cache-control'));
    }

    #[Test]
    public function responseAdapterFileThenHeadersKeepsBoth(): void
    {
        // R-5：StaticController::serve() 的调用是 file($realFile)->withHeaders([...])
        $path = $this->tempFile('css', 'body{}');
        $sent = (new ResponseAdapter(new FakePsrResponse()))->file($path)->withHeaders([
            'Cache-Control' => 'public, max-age=86400',
        ])->send();

        $this->assertSame(200, $sent->getStatusCode());
        $this->assertSame('body{}', (string) $sent->getBody());
        $this->assertSame('text/css', $sent->getHeaderLine('content-type'));
        $this->assertSame('public, max-age=86400', $sent->getHeaderLine('cache-control'));
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
        $sent = (new ResponseAdapter(new FakePsrResponse()))->file($this->tempFile($ext, 'content'))->send();
        $this->assertSame(200, $sent->getStatusCode());
        $this->assertSame($expectedType, $sent->getHeaderLine('content-type'));
        $this->assertSame('content', (string) $sent->getBody());
    }

    #[Test]
    public function responseAdapterFileMissingReturns404(): void
    {
        $sent = (new ResponseAdapter(new FakePsrResponse()))->file('/no/such/file.css')->send();
        $this->assertSame(404, $sent->getStatusCode());
    }

    // ---------- ConfigAdapter ----------

    #[Test]
    public function configAdapterServesBothShapes(): void
    {
        // R-6：XhprofProfiler::bootstrap() 用 get('xhprof')，Xhprof::index() 用 get('xhprof.assets_url')
        $adapter = new ConfigAdapter(['assets_url' => '/static/xhprof']);

        $this->assertIsArray($adapter->get('xhprof'));
        $this->assertSame('/static/xhprof', $adapter->get('xhprof.assets_url'));
        $this->assertSame('/static/xhprof', $adapter->get('xhprof')['assets_url']);
        $this->assertSame('d', $adapter->get('xhprof.nope', 'd'));
        $this->assertSame('d', $adapter->get('nope', 'd'));
    }

    #[Test]
    public function configAdapterFallsBackToShippedDefaults(): void
    {
        $block = (new ConfigAdapter())->get('xhprof');

        $this->assertSame(
            ['enable', 'time_limit', 'log_num', 'view_wtred', 'ignore_url_arr', 'assets_url', 'auth_token', 'key_prefix', 'log_ttl'],
            array_keys($block)
        );
        $this->assertTrue($block['enable']);
        $this->assertSame('/xhprof-assets', $block['assets_url']);
        $this->assertSame(['/xhprof'], $block['ignore_url_arr']);
    }

    #[Test]
    public function configAdapterMergesUserConfigWithoutRecursingIntoLists(): void
    {
        // R-7：必须 array_replace 而不是 array_replace_recursive。
        $adapter = new ConfigAdapter(['ignore_url_arr' => ['/admin'], 'enable' => false]);

        $this->assertSame(['/admin'], $adapter->get('xhprof.ignore_url_arr'));
        $this->assertFalse($adapter->get('xhprof.enable'));
        $this->assertSame(1000, $adapter->get('xhprof.log_num'), '未覆盖的键仍取默认值');

        // 上面那三行走的是**不可判别**的分支：包内默认 ignore_url_arr 只有 1 个元素
        // ['/xhprof']，任何纯列表输入都与它下标兼容（用户的项覆盖下标 0，递归也不会
        // 多出尾巴），此时 array_replace 与 array_replace_recursive 结果完全一致。
        // 两个函数只在「键的并集不同」时才分叉，所以下面这条才是真正钉住 R-7 的断言：
        // 用户传空数组是想说「什么都不忽略」，递归版本会静默把 /xhprof 塞回去。
        $cleared = new ConfigAdapter(['ignore_url_arr' => []]);
        $this->assertSame([], $cleared->get('xhprof.ignore_url_arr'),
            'R-7：用户数组必须整体替换默认数组，不得逐下标/逐键合并');
    }

    // ---------- LogAdapter ----------

    #[Test]
    public function logAdapterForwardsToPsrLogger(): void
    {
        $logger = new class implements PsrLoggerInterface {
            /** @var array<int, string> */
            public array $messages = [];

            public function emergency(string|\Stringable $message, array $context = []): void {}
            public function alert(string|\Stringable $message, array $context = []): void {}
            public function critical(string|\Stringable $message, array $context = []): void {}
            public function error(string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = $message . ':' . json_encode($context);
            }
            public function warning(string|\Stringable $message, array $context = []): void {}
            public function notice(string|\Stringable $message, array $context = []): void {}
            public function info(string|\Stringable $message, array $context = []): void {}
            public function debug(string|\Stringable $message, array $context = []): void {}
            public function log($level, string|\Stringable $message, array $context = []): void {}
        };

        (new LogAdapter($logger))->error('boom', ['a' => 1]);
        $this->assertSame(['boom:{"a":1}'], $logger->messages);
    }

    #[Test]
    public function logAdapterWithoutLoggerIsANoOp(): void
    {
        // Slim 自身不提供 logger；不传就必须静默丢弃，而不是抛 "Call to a member function on null"
        (new LogAdapter())->error('boom');
        $this->addToAssertionCount(1);
    }

    // ---------- RedisAdapter ----------

    /**
     * 只要 trait 需要的那些方法，用来观察 RedisAdapter 是否真的把调用转了出去。
     * 用注入而非 `new \Redis()`：单测不该依赖真实 Redis 服务。
     */
    private function fakeRedis(): object
    {
        return new class {
            /** @var array<int, array{string, array<int, mixed>}> */
            public array $calls = [];

            public function get(string $key): mixed { $this->calls[] = ['get', [$key]]; return $this->store[$key] ?? null; }
            public function set(string $key, mixed $value): mixed { $this->calls[] = ['set', [$key, $value]]; return true; }
            public function setex(string $key, int $ttl, mixed $value): mixed { $this->calls[] = ['setex', [$key, $ttl, $value]]; return true; }
            public function mget(array $keys): array { $this->calls[] = ['mget', [$keys]]; return []; }
            public function incr(string $key): int { $this->calls[] = ['incr', [$key]]; return 1; }
            public function decr(string $key): int { $this->calls[] = ['decr', [$key]]; return -1; }
            public function lpush(string $key, mixed $value): int { $this->calls[] = ['lpush', [$key, $value]]; return 7; }
            public function rpop(string $key): mixed { $this->calls[] = ['rpop', [$key]]; return null; }
            public function lrange(string $key, int $s, int $e): array { $this->calls[] = ['lrange', [$key, $s, $e]]; return []; }
            public function del(string ...$keys): int { $this->calls[] = ['del', $keys]; return count($keys); }

            /** @var array<string, mixed> */
            public array $store = [];
        };
    }

    #[Test]
    public function redisAdapterUsesTraitSemantics(): void
    {
        $redis = $this->fakeRedis();
        $adapter = new RedisAdapter($redis);
        $this->assertInstanceOf(CacheInterface::class, $adapter);

        // R-8：lPush 透传 push 后的列表长度（_checkLogNum 依赖它裁剪）
        $this->assertSame(7, $adapter->lPush('xhprof:run_id', 'abc'));

        // R-8：mget([]) 必须是 []，不能透传 phpredis 的 false（会 TypeError）
        $this->assertSame([], $adapter->mget([]));

        // R-8：ttl <= 0 退化为不带过期的普通 SET；ttl > 0 走 setex
        $adapter->set('k', 'v', 0);
        $adapter->set('k', 'v', 600);
        $this->assertSame(['set', ['k', 'v']], $redis->calls[1]);
        $this->assertSame(['setex', ['k', 600, 'v']], $redis->calls[2]);

        // R-8：rPop 空列表返回 falsy 且不抛错
        $this->assertEmpty($adapter->rPop('xhprof:run_id'));
    }

    // 注：`new RedisAdapter()` 的惰性建连（构造函数不碰 ext-redis）在本机不可观测 ——
    // ext-redis 已装，`new \Redis()` 不会失败。没有能变红的断言就不写这条测试。

    // ---------- XhprofMiddleware ----------

    #[Test]
    public function middlewareRecordsWhenEnabled(): void
    {
        $cache = new FakeCache();
        $response = new FakePsrResponse(200);
        $middleware = $this->middleware($cache, ['enable' => true]);

        $result = $middleware->process(
            new FakeServerRequest('GET', 'http://example.com/index'),
            $this->handlerReturning($response)
        );

        $this->assertSame($response, $result, '正常路径必须原样返回下游响应');
        $this->assertRunSaved($cache);
        $this->assertInstanceOf(CacheInterface::class, CoreXhprof::getCache());
    }

    #[Test]
    public function middlewareSkipsWhenDisabled(): void
    {
        $cache = new FakeCache();
        $response = new FakePsrResponse(200);
        $middleware = $this->middleware($cache, ['enable' => false]);

        $result = $middleware->process(
            new FakeServerRequest('GET', 'http://example.com/index'),
            $this->handlerReturning($response)
        );

        $this->assertSame($response, $result);
        $this->assertSame([], $cache->calls, 'disable 时一次缓存调用都不该有');
    }

    #[Test]
    public function middlewareFinallyRunsWhenHandlerThrows(): void
    {
        $cache = new FakeCache();
        $middleware = $this->middleware($cache, ['enable' => true]);

        $handlerRan = false;
        $handler = new class($handlerRan) implements RequestHandlerInterface {
            private bool $ran;

            public function __construct(bool &$ran)
            {
                $this->ran = &$ran;
            }

            public function handle(PsrServerRequestInterface $request): PsrResponseInterface
            {
                $this->ran = true;
                throw new \RuntimeException('handler boom');
            }
        };

        $caught = null;
        try {
            $middleware->process(new FakeServerRequest('GET', 'http://example.com/index'), $handler);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertTrue($handlerRan);
        $this->assertInstanceOf(\RuntimeException::class, $caught);
        $this->assertSame('handler boom', $caught->getMessage());
        $this->assertRunSaved($cache);
    }

    #[Test]
    public function middlewareRebootstrapsConfigPerRequest(): void
    {
        // 计划要求 bootstrap 必须在 isEnabled() 之前 —— 否则长驻进程里会读到上一个
        // 请求的配置（XhprofProfiler::$config 是静态的）。
        // 顺序写反时：第二次请求会沿用第一次的 enable=false，这里就不会落库。
        $cacheA = new FakeCache();
        $this->middleware($cacheA, ['enable' => false])->process(
            new FakeServerRequest('GET', 'http://example.com/index'),
            $this->handlerReturning(new FakePsrResponse(200))
        );
        $this->assertSame([], $cacheA->calls);

        $cacheB = new FakeCache();
        $this->middleware($cacheB, ['enable' => true])->process(
            new FakeServerRequest('GET', 'http://example.com/index'),
            $this->handlerReturning(new FakePsrResponse(200))
        );
        $this->assertRunSaved($cacheB);
    }

    #[Test]
    public function middlewareServesReportPageWithoutSampling(): void
    {
        $cache = new FakeCache();
        $response = new FakePsrResponse(200);
        $middleware = $this->middleware($cache, ['enable' => true]);

        $result = $middleware->process(
            // run 要过 xhprof_valid_run_id 白名单（/^[a-f0-9]{13,32}$/），否则 index() 会 deny 400
            new FakeServerRequest('GET', 'http://example.com/xhprof?run=0123456789abcdef'),
            $this->handlerReturning($response)
        );

        $this->assertNotSame($response, $result, '报告页必须短路，不能落到下游 handler');
        $this->assertSame(200, $result->getStatusCode());
        $this->assertStringContainsString('XHProf 性能分析报告', (string) $result->getBody());
        // PSR-7 响应不带默认 Content-Type，Slim\ResponseEmitter 也不补 —— 漏了它
        // 浏览器会把报告页按纯文本渲染。这条断言钉住这个显式 header。
        $this->assertSame('text/html; charset=UTF-8', $result->getHeaderLine('content-type'));
        // no-cache：报告是即时数据，且访问 URL 可能带 ?token=xxx，不能让中间缓存留副本。
        // 字面量与 Drupal 控制器（六框架里唯一有页面缓存可承重的那家）完全一致。
        // 判别力已实测（删掉源码那行 → 本条红）。这里裸断字面量是安全的：PSR-7 的 header 不会
        // 像 HttpFoundation 的 ResponseHeaderBag 那样给没设过的响应算默认值。
        // （Symfony 那条在真包上必须用扰动式判别，见 SymfonyTest::reportPathRendersReportWithoutSampling。）
        $this->assertSame('no-cache, private', $result->getHeaderLine('cache-control'));
        $this->assertSame([], $cache->calls, '报告页自身不采样、不落库');
    }

    #[Test]
    public function middlewareReportPathIsExactNotPrefix(): void
    {
        // 报告路径必须是精确相等：`/xhprofxxxx` 这类业务路径不能被吞掉
        $cache = new FakeCache();
        $response = new FakePsrResponse(200);
        $middleware = $this->middleware($cache, ['enable' => false]);

        $result = $middleware->process(
            new FakeServerRequest('GET', 'http://example.com/xhprof-custom-page'),
            $this->handlerReturning($response)
        );

        $this->assertSame($response, $result);
        $this->assertSame([], $cache->calls);
    }

    #[Test]
    public function middlewareReportPageHonoursAuthTokenDeny(): void
    {
        // Xhprof::index() 内部走 deny() 时返回的**不是字符串**而是 send() 的结果。
        // 这条钉住那条分支：写死 ->withBody(Xhprof::index()) 会在这里 TypeError。
        $cache = new FakeCache();
        $middleware = $this->middleware($cache, ['enable' => true, 'auth_token' => 'secret']);

        $result = $middleware->process(
            new FakeServerRequest('GET', 'http://example.com/xhprof'),
            $this->handlerReturning(new FakePsrResponse(200))
        );

        $this->assertSame(403, $result->getStatusCode());
        $this->assertSame('403 Forbidden', (string) $result->getBody());
    }

    #[Test]
    public function middlewareReportPageAcceptsCorrectToken(): void
    {
        $cache = new FakeCache();
        $middleware = $this->middleware($cache, ['enable' => true, 'auth_token' => 'secret']);

        $result = $middleware->process(
            new FakeServerRequest('GET', 'http://example.com/xhprof?token=secret'),
            $this->handlerReturning(new FakePsrResponse(200))
        );

        $this->assertSame(200, $result->getStatusCode());
        $this->assertStringContainsString('XHProf 性能分析报告', (string) $result->getBody());
        $this->assertSame('text/html; charset=UTF-8', $result->getHeaderLine('content-type'));
    }

    #[Test]
    public function middlewareServesAssetsWithoutSampling(): void
    {
        $cache = new FakeCache();
        $response = new FakePsrResponse(200);
        $middleware = $this->middleware($cache, ['enable' => true]);

        $result = $middleware->process(
            new FakeServerRequest('GET', 'http://example.com/xhprof-assets/css/xhprof.css'),
            $this->handlerReturning($response)
        );

        $this->assertNotSame($response, $result);
        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame('text/css', $result->getHeaderLine('content-type'));
        $this->assertStringContainsString('.xhprof', (string) $result->getBody());
        $this->assertSame([], $cache->calls, '静态资源不采样');
    }

    #[Test]
    public function middlewareAssetsPathTraversalIsRejected(): void
    {
        // StaticController::serve() 里对 '..' 的防线要真的生效（走的是同一条短路）
        $cache = new FakeCache();
        $middleware = $this->middleware($cache, ['enable' => false]);

        $result = $middleware->process(
            new FakeServerRequest('GET', 'http://example.com/xhprof-assets/../../../etc/passwd'),
            $this->handlerReturning(new FakePsrResponse(200))
        );

        $this->assertSame('', (string) $result->getBody());
        $this->assertSame([], $cache->calls);
    }

    #[Test]
    public function middlewareAssetsGateFollowsConfiguredAssetsUrl(): void
    {
        $cache = new FakeCache();
        $response = new FakePsrResponse(200);
        $middleware = $this->middleware($cache, ['enable' => false, 'assets_url' => '/static/xhprof']);

        // 配了新前缀后，旧前缀 /xhprof-assets/... 不该再被本中间件吞掉
        $passedThrough = $middleware->process(
            new FakeServerRequest('GET', 'http://example.com/xhprof-assets/css/xhprof.css'),
            $this->handlerReturning($response)
        );
        $this->assertSame($response, $passedThrough, '前缀应跟随 assets_url 配置');

        $shortCircuited = $middleware->process(
            new FakeServerRequest('GET', 'http://example.com/static/xhprof/css/xhprof.css'),
            $this->handlerReturning(new FakePsrResponse(500))
        );
        $this->assertNotSame($response, $shortCircuited, '新前缀应被短路');
    }

    /**
     * 已知缺陷（**不是本卡的期望行为**，src/Core 只读、不改）：
     * `StaticController::URI_PREFIX` 是硬编码的 '/xhprof-assets'，与配置项
     * `assets_url` 完全无关。所以一旦把 assets_url 改成别的值，本中间件会按配置
     * 短路，而 Core 只认老前缀、直接返回空体 —— 静态资源静默 404。
     *
     * 这条测试把该缺陷**钉住**：它红了说明 Core 修好了（那时删掉本条即可），
     * 它绿着就是提醒没人以为这条路径在工作。已写进交付报告。
     */
    #[Test]
    public function assetsUrlOverrideIsBrokenByCoreHardcodedPrefix(): void
    {
        $middleware = $this->middleware(new FakeCache(), ['enable' => false, 'assets_url' => '/static/xhprof']);

        $result = $middleware->process(
            new FakeServerRequest('GET', 'http://example.com/static/xhprof/css/xhprof.css'),
            $this->handlerReturning(new FakePsrResponse(200))
        );

        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame('', (string) $result->getBody(), '空体 = Core 不认这个前缀，资源拿不到');
        $this->assertSame('', $result->getHeaderLine('content-type'));
    }

    #[Test]
    public function middlewareImplementsPsr15(): void
    {
        $this->assertInstanceOf(\Psr\Http\Server\MiddlewareInterface::class, $this->middleware(new FakeCache(), []));
        $this->assertSame('/xhprof', XhprofMiddleware::REPORT_PATH);
    }
}
