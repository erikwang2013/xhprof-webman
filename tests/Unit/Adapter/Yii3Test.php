<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface as PsrServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface as CoreLoggerInterface;
use ErikWang2013\Xhprof\Core\RedisAdapterTrait;
use ErikWang2013\Xhprof\Core\StaticController;
use ErikWang2013\Xhprof\Core\Xhprof as CoreXhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\FakePsrResponse;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\FakeResponseFactory;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\FakeServerRequest;
use ErikWang2013\Xhprof\Yii3\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Yii3\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Yii3\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Yii3\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Yii3\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Yii3\XhprofMiddleware;

class Yii3Test extends TestCase
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
        $path = sys_get_temp_dir() . "/xhprof-yii3-$ext-" . bin2hex(random_bytes(4)) . ".$ext";
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function snapshotXhprofStatics(): array
    {
        return [
            'profilerConfig' => $this->profilerConfig(),
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

    /**
     * XhprofProfiler::$config 是私有静态、没有公开的 reset，而 isEnabled() 优先读它。
     * 不还原的话本类的 enable=true 会漏给同进程的其它测试类（它们若直接调 isEnabled()
     * 就会读到别人的配置）。
     */
    private function profilerConfig(?array $set = null): ?array
    {
        $prop = new \ReflectionProperty(XhprofProfiler::class, 'config');
        $prop->setAccessible(true);
        $current = $prop->getValue();
        if (func_num_args() === 1) {
            $prop->setValue(null, $set);
        }

        return is_array($current) ? $current : null;
    }

    private function restoreXhprofStatics(array $s): void
    {
        $this->profilerConfig($s['profilerConfig']);
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

    // ================= RequestAdapter =================

    #[Test]
    public function requestAdapterDelegatesToPsr7Request(): void
    {
        $adapter = new RequestAdapter(new FakeServerRequest(
            'POST',
            'http://example.com:8080/admin?x=1&y=2',
            ['REMOTE_ADDR' => '10.0.0.9'],
            ['X-Foo' => 'bar']
        ));

        $this->assertInstanceOf(RequestInterface::class, $adapter);
        $this->assertSame('POST', $adapter->method());
        $this->assertSame('bar', $adapter->header('x-foo'));
        $this->assertSame('1', $adapter->get('x'));
        $this->assertSame('落空', $adapter->get('nope', '落空'));
        $this->assertSame(['x' => '1', 'y' => '2'], $adapter->all());
    }

    #[Test]
    public function requestAdapterUriIsPathAndQueryOnly(): void
    {
        // R-1：绝不能含 scheme/host —— XhprofLib::isIgnore() 对它做子串匹配，
        // 绝对 URL 会让 host 含 "xhprof" 的站点每个请求都被判为需忽略。
        $adapter = new RequestAdapter(new FakeServerRequest('GET', 'http://example.com:8080/admin?x=1'));

        $this->assertSame('/admin?x=1', $adapter->uri());
        $this->assertStringNotContainsString('://', $adapter->uri());
        $this->assertStringNotContainsString('example.com', $adapter->uri());
    }

    #[Test]
    public function requestAdapterUriHasNoTrailingQuestionMarkWithoutQuery(): void
    {
        $adapter = new RequestAdapter(new FakeServerRequest('GET', 'http://example.com/admin'));

        $this->assertSame('/admin', $adapter->uri());
        $this->assertStringNotContainsString('?', $adapter->uri());
    }

    #[Test]
    public function requestAdapterHostDropsPort(): void
    {
        // R-2：host() 只给 host，不含端口
        $adapter = new RequestAdapter(new FakeServerRequest('GET', 'http://example.com:8080/admin'));

        $this->assertSame('example.com', $adapter->host());
        $this->assertStringNotContainsString('8080', $adapter->host());
    }

    #[Test]
    public function requestAdapterHeaderIsNullWhenAbsent(): void
    {
        // R-3：契约允许 null，但 fetch 到空串时必须归一成 null（不是 ''）
        $adapter = new RequestAdapter(new FakeServerRequest());

        $this->assertNull($adapter->header('x-forwarded-proto'));
        $this->assertNull($adapter->header('x-real-ip'));
    }

    #[Test]
    public function requestAdapterUrlKeepsSchemeHostPort(): void
    {
        // url() 与 uri() 相反，是完整 URL（XhprofDisplay 用 dirname(url()) 兜底推资源目录）
        $adapter = new RequestAdapter(new FakeServerRequest('GET', 'http://example.com:8080/admin?x=1'));

        $this->assertSame('http://example.com:8080/admin?x=1', $adapter->url());
        $this->assertStringContainsString('://', $adapter->url());
    }

    #[Test]
    public function requestAdapterPrefersQueryOverParsedBodyInBothAccessors(): void
    {
        $request = (new FakeServerRequest('POST', '/p?q=from-query'))
            ->withParsedBody(['q' => 'from-body', 'b' => 'only-body']);
        $adapter = new RequestAdapter($request);

        $this->assertSame('from-query', $adapter->get('q'));
        $this->assertSame('only-body', $adapter->get('b'));
        // get() 与 all() 必须给出同一个答案，否则报告页参数与落库参数会不一致
        $this->assertSame('from-query', $adapter->all()['q']);
        $this->assertSame('only-body', $adapter->all()['b']);
    }

    #[Test]
    public function requestAdapterIgnoresNonArrayParsedBody(): void
    {
        // getParsedBody() 在 JSON 请求里可能是对象/字符串，不能让它污染 all()
        $adapter = new RequestAdapter(
            (new FakeServerRequest('POST', '/p?a=1'))->withParsedBody('not-an-array')
        );

        $this->assertSame(['a' => '1'], $adapter->all());
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>, string}>
     */
    public static function realIpProvider(): array
    {
        return [
            'x-forwarded-for 多 IP 取第一个' => [
                ['headers' => ['X-Forwarded-For' => '1.2.3.4, 5.6.7.8'], 'server' => ['REMOTE_ADDR' => '9.9.9.9']],
                '1.2.3.4',
            ],
            'x-real-ip 兜底' => [
                ['headers' => ['X-Real-Ip' => '8.8.8.8'], 'server' => ['REMOTE_ADDR' => '9.9.9.9']],
                '8.8.8.8',
            ],
            'REMOTE_ADDR 兜底' => [
                ['headers' => [], 'server' => ['REMOTE_ADDR' => '7.7.7.7']],
                '7.7.7.7',
            ],
            'serverParams 里的 HTTP_X_FORWARDED_FOR' => [
                ['headers' => [], 'server' => ['HTTP_X_FORWARDED_FOR' => '6.6.6.6, 1.1.1.1']],
                '6.6.6.6',
            ],
            '无任何来源时给 127.0.0.1' => [
                ['headers' => [], 'server' => ['REMOTE_ADDR' => '']],
                '127.0.0.1',
            ],
        ];
    }

    #[Test]
    #[DataProvider('realIpProvider')]
    public function requestAdapterGetRealIpAlwaysReturnsString(array $input, string $expected): void
    {
        // R-3：`: string` 的方法在任何分支都不能返回 null
        $adapter = new RequestAdapter(new FakeServerRequest(
            'GET',
            '/',
            $input['server'],
            $input['headers']
        ));

        $this->assertSame($expected, $adapter->getRealIp());
    }

    // ================= ResponseAdapter =================

    #[Test]
    public function responseAdapterIsFluentAndAppliesStateOnSend(): void
    {
        $adapter = new ResponseAdapter(new FakeResponseFactory());

        // R-4：withX() 一律 return $this —— Xhprof::deny() 的调用链
        // 是 withStatus()->withBody()->send()，中途换实例链就断了
        $this->assertSame($adapter, $adapter->withStatus(403));
        $this->assertSame($adapter, $adapter->withBody('nope'));
        $this->assertSame($adapter, $adapter->withHeaders(['X-A' => '1']));

        $response = $adapter->send();
        $this->assertInstanceOf(PsrResponseInterface::class, $response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('nope', (string) $response->getBody());
        $this->assertSame('1', $response->getHeaderLine('X-A'));
    }

    #[Test]
    public function responseAdapterSendProducesAnIndependentResponseEachTime(): void
    {
        $adapter = new ResponseAdapter(new FakeResponseFactory());
        $adapter->withBody('one');

        $first = $adapter->send();
        $adapter->withStatus(500)->withBody('two');
        $second = $adapter->send();

        $this->assertNotSame($first, $second);
        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame('one', (string) $first->getBody());
        $this->assertSame(500, $second->getStatusCode());
        $this->assertSame('two', (string) $second->getBody());
    }

    #[Test]
    public function responseAdapterWithHeadersAfterFileStillApplies(): void
    {
        // R-5：StaticController::serve() 的调用是 file($realFile)->withHeaders([...])。
        // 若 file() 用「重建响应」实现，先设的 header 会被冲掉。
        $path = $this->tempFile('css', '.xp {}');
        $adapter = new ResponseAdapter(new FakeResponseFactory());
        $adapter->file($path)->withHeaders(['Cache-Control' => 'public, max-age=86400']);

        $response = $adapter->send();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('.xp {}', (string) $response->getBody());
        $this->assertSame('text/css', $response->getHeaderLine('Content-Type'));
        $this->assertSame('public, max-age=86400', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function responseAdapterFileSetsMimeAndMissingFileIsEmpty404(): void
    {
        $adapter = new ResponseAdapter(new FakeResponseFactory());
        $adapter->file('/no/such/file.css');

        $response = $adapter->send();
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
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
            '未知后缀' => ['bin', 'application/octet-stream'],
        ];
    }

    #[Test]
    #[DataProvider('mimeProvider')]
    public function responseAdapterFileMapsMimeType(string $ext, string $expectedType): void
    {
        $adapter = new ResponseAdapter(new FakeResponseFactory());
        $adapter->file($this->tempFile($ext, 'content'));

        $response = $adapter->send();
        $this->assertSame($expectedType, $response->getHeaderLine('Content-Type'));
        $this->assertSame('content', (string) $response->getBody());
    }

    #[Test]
    public function responseAdapterEmptyBodyWritesNothing(): void
    {
        $adapter = new ResponseAdapter(new FakeResponseFactory());

        $this->assertSame('', (string) $adapter->send()->getBody());
    }

    // ================= ConfigAdapter =================

    #[Test]
    public function configAdapterSupportsBothKeyShapes(): void
    {
        // R-6：'xhprof' 返回整块（XhprofProfiler::bootstrap()），
        // 'xhprof.assets_url' 返回叶子（Xhprof::index()）
        $adapter = new ConfigAdapter(['enable' => true, 'key_prefix' => 'custom']);

        $this->assertIsArray($adapter->get('xhprof'));
        $this->assertTrue($adapter->get('xhprof')['enable']);
        $this->assertSame('custom', $adapter->get('xhprof.key_prefix'));
        $this->assertSame('/xhprof-assets', $adapter->get('xhprof.assets_url'));
        $this->assertSame('d', $adapter->get('xhprof.nope', 'd'));
    }

    #[Test]
    public function configAdapterStartsWithPackageDefaults(): void
    {
        $adapter = new ConfigAdapter();

        $this->assertSame('/xhprof-assets', $adapter->get('xhprof.assets_url'));
        $this->assertSame('xhprof', $adapter->get('xhprof.key_prefix'));
        $this->assertSame(86400 * 7, $adapter->get('xhprof.log_ttl'));
        $this->assertNull($adapter->get('xhprof.auth_token'));
        $this->assertTrue($adapter->get('xhprof.enable'));
    }

    #[Test]
    public function configAdapterReplacesListKeysInsteadOfMergingThem(): void
    {
        // R-7：用户的列表整段生效（不是与默认值合并、也不是默认值赢）
        $adapter = new ConfigAdapter(['ignore_url_arr' => ['/admin']]);

        $this->assertSame(['/admin'], $adapter->get('xhprof')['ignore_url_arr']);
        // 注：这一条**区分不出** array_replace 与 array_replace_recursive
        // （两者下标 0 都是覆盖），真正的判据在下一个用例。
    }

    #[Test]
    public function configAdapterEmptyListStaysEmptyInsteadOfFallingBackToDefault(): void
    {
        // R-7 的真判据：默认 ['/xhprof'] 只有 1 个元素、用户列表也有下标 0，
        // 所以「换成 array_replace_recursive」在 ['/admin'] 上看不出任何差别。
        // 唯一能区分的输入是**空列表**：用户显式写 []（表示「什么都不忽略」），
        // 递归合并会把它与默认值按 key 合并，默认的 '/xhprof' 静默复活。
        $adapter = new ConfigAdapter(['ignore_url_arr' => []]);

        $this->assertSame([], $adapter->get('xhprof')['ignore_url_arr']);
    }

    #[Test]
    public function configAdapterKeepsUnrelatedDefaultsWhenOverridingOneKey(): void
    {
        $adapter = new ConfigAdapter(['key_prefix' => 'proj']);

        $block = $adapter->get('xhprof');
        $this->assertSame('proj', $block['key_prefix']);
        $this->assertSame(1000, $block['log_num']);
        $this->assertSame(['/xhprof'], $block['ignore_url_arr']);
    }

    #[Test]
    public function configAdapterAlsoAcceptsBareKeys(): void
    {
        $adapter = new ConfigAdapter();

        $this->assertSame('/xhprof-assets', $adapter->get('assets_url'));
        $this->assertSame('d', $adapter->get('missing.nested', 'd'));
    }

    // ================= RedisAdapter =================

    #[Test]
    public function redisAdapterSatisfiesCacheContractViaCoreTrait(): void
    {
        $this->assertInstanceOf(CacheInterface::class, new RedisAdapter());
        $traits = class_uses(RedisAdapter::class);
        $this->assertContains(RedisAdapterTrait::class, $traits);

        // R-9：R-8 的语义全部由 Core\RedisAdapterTrait 提供，本适配器只给连接。
        // 用一个只记录调用的假连接验证「真的转发到了 phpredis 的对应方法」。
        $fake = new class {
            public array $calls = [];

            public function lpush(string $key, mixed $value): int
            {
                $this->calls[] = "lpush:$key";

                return 7;
            }

            public function mget(array $keys): array
            {
                $this->calls[] = 'mget';

                return ['a' => '1'];
            }
        };
        $adapter = new class ($fake) extends RedisAdapter {
            private object $fake;

            public function __construct(object $fake)
            {
                parent::__construct([]);
                $this->fake = $fake;
            }

            protected function redis(): mixed
            {
                return $this->fake;
            }
        };

        // R-8：lPush 返回的是 push 后的列表长度（原样透传），不是布尔
        $this->assertSame(7, $adapter->lPush('k', 'v'));
        $this->assertSame('1', $adapter->mget(['a'])['a']);
        $this->assertSame(['lpush:k', 'mget'], $fake->calls);
    }

    #[Test]
    public function redisAdapterDoesNotConnectOnConstruction(): void
    {
        // 构造必须不连 Redis：入口类在每个请求上、早于 enable 判断就构造本适配器，
        // 构造期连不上会让整个应用每个请求 500，而不是只丢采样。
        // 端口 1 必然连不上，这里只断言「构造没抛」，连接推迟到真正取用。
        $adapter = new RedisAdapter(['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.05]);
        $this->assertInstanceOf(CacheInterface::class, $adapter);

        $thrown = null;
        try {
            $adapter->get('k');
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        $this->assertInstanceOf(\RedisException::class, $thrown);
    }

    #[Test]
    public function middlewareBuildsRedisCacheFromRedisSubConfig(): void
    {
        // `redis` 子数组是 Yii3 特有的（其余框架用各自框架的缓存组件），
        // 也是 README 要写的配置项之一 —— 它必须真的被读进连接参数，不能只是文档。
        $middleware = new XhprofMiddleware(new FakeResponseFactory(), [
            'redis' => ['host' => '10.1.2.3', 'port' => 6390, 'password' => 'pw', 'database' => 3, 'timeout' => 2.5],
        ]);

        $options = $this->optionsOf($this->cacheOf($middleware));
        $this->assertSame('10.1.2.3', $options['host']);
        $this->assertSame(6390, $options['port']);
        $this->assertSame('pw', $options['password']);
        $this->assertSame(3, $options['database']);
        $this->assertSame(2.5, $options['timeout']);

        // 不配 redis 时用本机默认值
        $default = $this->optionsOf($this->cacheOf(new XhprofMiddleware(new FakeResponseFactory())));
        $this->assertSame('127.0.0.1', $default['host']);
        $this->assertSame(6379, $default['port']);
        $this->assertSame(0, $default['database']);
    }

    private function cacheOf(XhprofMiddleware $middleware): RedisAdapter
    {
        $prop = new \ReflectionProperty(XhprofMiddleware::class, 'cache');
        $prop->setAccessible(true);   // PHP 8.0 上私有属性必须显式放开
        $cache = $prop->getValue($middleware);
        $this->assertInstanceOf(RedisAdapter::class, $cache);

        return $cache;
    }

    /** @return array<string, mixed> */
    private function optionsOf(RedisAdapter $adapter): array
    {
        $prop = new \ReflectionProperty(RedisAdapter::class, 'options');
        $prop->setAccessible(true);

        return (array) $prop->getValue($adapter);
    }

    // ================= LogAdapter =================

    #[Test]
    public function logAdapterForwardsToInjectedPsrLogger(): void
    {
        // 桩里的 Psr\Log\LoggerInterface 只声明了 error()（见 tests/Stubs/framework-stubs.php），
        // 而 LogAdapter 也只用到 error() —— 这里断言的是「真的转发给了 PSR-3 实现」
        $logger = new class implements \Psr\Log\LoggerInterface {
            public array $records = [];

            public function error(string $message, array $context = []): void
            {
                $this->records[] = $message . ':' . json_encode($context);
            }
        };
        $adapter = new LogAdapter($logger);

        $this->assertInstanceOf(CoreLoggerInterface::class, $adapter);
        $adapter->error('boom', ['a' => 1]);
        $this->assertSame('boom:{"a":1}', $logger->records[0]);
    }

    #[Test]
    public function logAdapterWithoutLoggerWritesToErrorLog(): void
    {
        $path = $this->tempFile('log', '');
        $previous = ini_get('error_log');
        $previousLogErrors = ini_get('log_errors');
        ini_set('error_log', $path);
        ini_set('log_errors', '1');

        try {
            (new LogAdapter())->error('xhprof-fallback-marker');
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            ini_set('log_errors', $previousLogErrors === false ? '' : $previousLogErrors);
        }

        $this->assertStringContainsString('xhprof-fallback-marker', (string) file_get_contents($path));
    }

    // ================= XhprofMiddleware =================

    private function requestHandler(?PsrResponseInterface $response = null): RequestHandlerInterface
    {
        return new class ($response ?? new FakePsrResponse(200, [], 'ok')) implements RequestHandlerInterface {
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

    private function middleware(array $config, FakeCache $cache): XhprofMiddleware
    {
        return new XhprofMiddleware(new FakeResponseFactory(), $config, $cache);
    }

    /** 断言一次 save_run 完整落库 */
    private function assertRunSaved(FakeCache $cache): void
    {
        $runIds = $cache->lRange('xhprof:run_id', 0, -1);
        $this->assertCount(1, $runIds, '落库后 run_id 列表应恰好一条');
        $runId = $runIds[0];
        $this->assertIsString($runId);

        $this->assertIsString($cache->get('xhprof:request_log:' . $runId));
        $serialized = $cache->get('xhprof:xhprof_log:' . $runId);
        $this->assertIsString($serialized);

        // 只断言「键存在」等于没测：stop() 改成 save_run([]) 整个套件照样全绿。
        $data = unserialize($serialized);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('main()', $data, '采样数据缺少 main() 帧 → 报告页会是空的');
    }

    #[Test]
    public function middlewareRecordsWhenEnabled(): void
    {
        $cache = new FakeCache();
        $handler = $this->requestHandler(new FakePsrResponse(200, [], 'business'));
        $middleware = $this->middleware(['enable' => true], $cache);

        $response = $middleware->process(new FakeServerRequest('GET', '/index'), $handler);

        $this->assertTrue($handler->called);
        $this->assertSame('business', (string) $response->getBody());
        $this->assertRunSaved($cache);
    }

    #[Test]
    public function middlewareSkipsWhenDisabled(): void
    {
        $cache = new FakeCache();
        $handler = $this->requestHandler();
        $middleware = $this->middleware(['enable' => false], $cache);

        $response = $middleware->process(new FakeServerRequest('GET', '/index'), $handler);

        $this->assertTrue($handler->called);
        $this->assertSame(200, $response->getStatusCode());
        // 先看调用记录：lRange() 自己也会记一条，放到后面断言会把自己算进去
        $this->assertSame([], $cache->calls, 'disable 时不该碰缓存');
        $this->assertSame([], $cache->lRange('xhprof:run_id', 0, -1));
    }

    #[Test]
    public function middlewareFinallyStopsSamplingWhenHandlerThrows(): void
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

        $thrown = null;
        try {
            $this->middleware(['enable' => true], $cache)->process(new FakeServerRequest('GET', '/index'), $handler);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertTrue($handler->ran);
        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertSame('handler boom', $thrown->getMessage());
        // finally 必须已经 stop 并落库；且采样状态不残留。
        // stop() 里已调过一次 xhprof_disable()，这里再调一次：若 finally 没跑到，
        // 采样仍开着，这一次会吐出完整数据而不是空 → 断言非空即失败。
        $this->assertRunSaved($cache);
        $this->assertEmpty(xhprof_disable(), 'stop 后不该还有可导出的采样状态');
    }

    #[Test]
    public function middlewareBootstrapsConfigBeforeDecidingToSample(): void
    {
        $cache = new FakeCache();
        $handler = $this->requestHandler();
        $middleware = $this->middleware(['enable' => true, 'key_prefix' => 'proj', 'ignore_url_arr' => ['/zzz']], $cache);

        $middleware->process(new FakeServerRequest('GET', '/index'), $handler);

        // bootstrap 必须在 isEnabled() 之前落到 Core 的静态属性上
        $this->assertSame('proj', CoreXhprof::$key_prefix);
        $this->assertSame(['/zzz'], CoreXhprof::$ignore_url_arr);
        $this->assertInstanceOf(ConfigAdapter::class, CoreXhprof::getConfig());
        $this->assertInstanceOf(CacheInterface::class, CoreXhprof::getCache());
    }

    #[Test]
    public function middlewareShortCircuitsReportPathWithoutSamplingOrHandler(): void
    {
        $cache = new FakeCache();
        $handler = $this->requestHandler();
        // auth_token 设了但请求没带 token → Xhprof::index() 走 deny()，不碰缓存
        $middleware = $this->middleware(['enable' => true, 'auth_token' => 'secret'], $cache);

        // 用**绝对 URL**（带端口）发请求：uri() 若退化成返回绝对 URL（R-1 破坏），
        // 这里的路径就变成 "http://example.com/xhprof"，短路失效 → 本用例变红。
        $response = $middleware->process(new FakeServerRequest('GET', 'http://example.com:8080/xhprof'), $handler);

        $this->assertFalse($handler->called, '报告页必须在业务 handler 之前短路');
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('403 Forbidden', (string) $response->getBody());
        $this->assertSame([], $cache->calls, '报告页短路时不该采样/落库');
    }

    #[Test]
    public function middlewareShortCircuitsReportPathWithQueryString(): void
    {
        $cache = new FakeCache();
        $handler = $this->requestHandler();
        $middleware = $this->middleware(['enable' => true, 'auth_token' => 'secret'], $cache);

        // ?token=wrong 走 deny，但足以证明「去 query 后判路径」生效
        $response = $middleware->process(new FakeServerRequest('GET', '/xhprof?token=wrong&run=abc'), $handler);

        $this->assertFalse($handler->called);
        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function middlewareRendersReportPageWhenNotDenied(): void
    {
        $cache = new FakeCache();
        $handler = $this->requestHandler();
        $middleware = $this->middleware(['enable' => true], $cache);

        $response = $middleware->process(new FakeServerRequest('GET', '/xhprof'), $handler);

        $this->assertFalse($handler->called);
        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('XHProf 性能分析报告', $body);
        $this->assertStringContainsString('/xhprof-assets', $body);
        // PSR-7 响应不带默认 Content-Type，发送器也不补 —— 不显式给，浏览器会把
        // HTML 报告按纯文本渲染。FakePsrResponse 不做任何默认填充，所以这条断言
        // 只能由本中间件那行 withHeaders() 满足（回退验证里已验证会变红）。
        $this->assertSame('text/html; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        // no-cache：报告是即时数据，且访问 URL 可能带 ?token=xxx，不能让中间缓存留副本。
        // 字面量与 Drupal 控制器（六框架里唯一有页面缓存可承重的那家）完全一致。
        // 判别力已实测（删掉源码那行 → 本条红）。裸断字面量在这里安全：PSR-7 的 header 不会像
        // HttpFoundation 的 ResponseHeaderBag 那样给没设过的响应算默认值。
        $this->assertSame('no-cache, private', $response->getHeaderLine('Cache-Control'));
        $this->assertSame([], $cache->lRange('xhprof:run_id', 0, -1), '报告页本身不该被采样');
    }

    #[Test]
    public function middlewareServesAssetsThroughStaticController(): void
    {
        $cache = new FakeCache();
        $handler = $this->requestHandler();
        $middleware = $this->middleware(['enable' => true], $cache);

        $response = $middleware->process(new FakeServerRequest('GET', '/xhprof-assets/css/xhprof.css'), $handler);

        $this->assertFalse($handler->called, '资源请求必须在业务 handler 之前短路');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/css', $response->getHeaderLine('Content-Type'));
        // R-5：file() 之后设的 header 仍然生效
        $this->assertSame('public, max-age=86400', $response->getHeaderLine('Cache-Control'));

        $expected = file_get_contents(StaticController::getAssetsPath() . '/css/xhprof.css');
        $this->assertSame($expected, (string) $response->getBody());
        $this->assertSame([], $cache->calls);
    }

    #[Test]
    public function middlewareDoesNotShortCircuitNearMissPaths(): void
    {
        $cache = new FakeCache();
        $handler = $this->requestHandler();
        $middleware = $this->middleware(['enable' => false], $cache);

        // 前缀必须带 '/'：/xhprof-assets-nope 与 /xhprof-other 都是业务路径
        $middleware->process(new FakeServerRequest('GET', '/xhprof-assets-nope'), $handler);
        $this->assertTrue($handler->called, '/xhprof-assets-nope 不该被短路');

        $handler2 = $this->requestHandler();
        $middleware->process(new FakeServerRequest('GET', '/xhprof-other'), $handler2);
        $this->assertTrue($handler2->called, '/xhprof-other 不该被短路');
    }

    #[Test]
    public function middlewareRendersConfiguredCdnAssetsUrlAndStopsServingLocally(): void
    {
        $cache = new FakeCache();
        $middleware = $this->middleware(['enable' => true, 'assets_url' => 'https://cdn.test/xhprof-assets'], $cache);

        $report = $middleware->process(new FakeServerRequest('GET', '/xhprof'), $this->requestHandler());
        $this->assertStringContainsString(
            "https://cdn.test/xhprof-assets/css/xhprof.css",
            (string) $report->getBody(),
            '报告页的资源链接要跟着 assets_url 走'
        );

        // assets_url 是绝对 URL 时，短路前缀也变成绝对 URL，本地 /xhprof-assets/...
        // 不再命中（请求落到业务路由）—— 资源由 CDN 托管，本地不该再服务。
        $handler = $this->requestHandler();
        $middleware->process(new FakeServerRequest('GET', '/xhprof-assets/css/xhprof.css'), $handler);
        $this->assertTrue($handler->called, '配置了 CDN 后本中间件不再接管本地资源路径');
    }

    #[Test]
    public function characterizationTrailingSlashInAssetsUrlLeaksIntoLinks(): void
    {
        // 特征化测试：钉住两个方向的现状。
        // 1) 链接：Xhprof::index() 直接 `$assetsUrl . '/css/...'` 拼，不归一化尾斜杠
        //    （Core 只读），于是出现 `//`；本中间件管不着。
        // 2) 短路前缀：本中间件自己 rtrim 过，所以带尾斜杠的**本地**前缀照样能服务到文件
        //    —— 不 rtrim 的话前缀会变成 `/xhprof-assets//`，永远匹配不上。
        $cache = new FakeCache();
        $middleware = $this->middleware(['enable' => true, 'assets_url' => '/xhprof-assets/'], $cache);

        $report = $middleware->process(new FakeServerRequest('GET', '/xhprof'), $this->requestHandler());
        $this->assertStringContainsString(
            "/xhprof-assets//css/xhprof.css",
            (string) $report->getBody(),
            '尾斜杠会漏进链接（Core 不归一化）'
        );

        $handler = $this->requestHandler();
        $response = $middleware->process(new FakeServerRequest('GET', '/xhprof-assets/css/xhprof.css'), $handler);
        $this->assertFalse($handler->called, '本中间件 rtrim 后前缀仍能匹配，资源没被漏给业务');
        $this->assertSame('text/css', $response->getHeaderLine('Content-Type'));
    }

    /**
     * 特征化测试（characterization test）：**钉住现状，不是在声明这是对的**。
     *
     * 根因在只读的 Core\StaticController 里：`serve()` 把 URI 前缀硬编码成
     * `/xhprof-assets`，而本中间件按**配置的** assets_url 决定是否短路。两者不一致时，
     * 配置前缀下的请求会被本中间件吞掉、再由 serve() 返回一个**空 body 的 200**
     * （业务路由也因此拿不到这些路径）。
     *
     * 若将来把 StaticController 的前缀改成可配置（或本中间件改为自行解析文件），
     * 请删掉本用例，换成断言真能读到 css 内容。
     */
    #[Test]
    public function characterizationCustomLocalAssetsPrefixYieldsEmpty200(): void
    {
        $cache = new FakeCache();
        $middleware = $this->middleware(['enable' => true, 'assets_url' => '/static/xhprof'], $cache);

        $handler = $this->requestHandler();
        $response = $middleware->process(new FakeServerRequest('GET', '/static/xhprof/css/xhprof.css'), $handler);

        $this->assertFalse($handler->called, '配置前缀下的请求被本中间件接管');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody(), 'StaticController 不认 /static/xhprof，读不到文件');
        $this->assertSame('', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function middlewarePassesHandlerResponseThroughUnchanged(): void
    {
        $cache = new FakeCache();
        $expected = new FakePsrResponse(418, ['X-Tea' => 'pot'], 'teapot');
        $handler = $this->requestHandler($expected);

        $response = $this->middleware(['enable' => false], $cache)
            ->process(new FakeServerRequest('GET', '/index'), $handler);

        $this->assertSame($expected, $response);
    }

    #[Test]
    public function middlewareIsPsr15Middleware(): void
    {
        $this->assertInstanceOf(\Psr\Http\Server\MiddlewareInterface::class, $this->middleware([], new FakeCache()));

        // 构造参数 2/3 可省略（reflection 默认值）——README 的
        // `withMiddlewares([XhprofMiddleware::class])` 最简写法依赖这一点，
        // 真实 yiisoft/di 的行为在 tools/contracts/cases/Yii3.php 里定死。
        $reflection = new \ReflectionMethod(XhprofMiddleware::class, '__construct');
        $params = $reflection->getParameters();
        $this->assertCount(3, $params);
        $this->assertTrue($params[1]->isDefaultValueAvailable());
        $this->assertNull($params[1]->getDefaultValue());
        $this->assertTrue($params[2]->isDefaultValueAvailable());
        $this->assertNull($params[2]->getDefaultValue());
    }
}
