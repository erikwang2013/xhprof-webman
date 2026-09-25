<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use support\Redis;
use support\Log;
use Webman\Http\Request;
use Webman\Http\Response;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\Xhprof as CoreXhprof;
use ErikWang2013\Xhprof\Tests\Stubs\Registry;
use ErikWang2013\Xhprof\Webman\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Webman\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Webman\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Webman\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Webman\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Webman\Install;
use ErikWang2013\Xhprof\Webman\StaticController;
use ErikWang2013\Xhprof\Webman\Xhprof;
use ErikWang2013\Xhprof\Webman\XhprofMiddleware;

class WebmanTest extends TestCase
{
    /** @var array<int, string> 测试创建的临时文件，tearDown 清理 */
    private array $tempFiles = [];

    /** @var array<string, mixed> Xhprof 静态属性快照 */
    private array $saved = [];

    private ?string $tempBasePath = null;

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
        // 防御：若断言中断导致 start/stop 不配对，静默关闭 xhprof，避免污染后续测试
        xhprof_disable();
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (isset($this->tempBasePath) && is_dir($this->tempBasePath)) {
            $this->removeTree($this->tempBasePath);
        }
    }

    private function tempFile(string $name, string $content = 'body'): string
    {
        $path = sys_get_temp_dir() . '/' . $name . '-' . bin2hex(random_bytes(4));
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return $path;
    }

    private function removeTree(string $dir): void
    {
        $items = glob($dir . '/*') ?: [];
        foreach ($items as $item) {
            is_dir($item) ? $this->removeTree($item) : unlink($item);
        }
        rmdir($dir);
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
    public function configGetResolvesPluginPrefixedTree(): void
    {
        Registry::$webmanConfig = [
            'plugin' => ['aaron-dev' => ['xhprof' => ['xhprof' => [
                'enable' => true,
                'log_ttl' => 3600,
            ]]]],
        ];
        $adapter = new ConfigAdapter();
        $this->assertInstanceOf(ConfigInterface::class, $adapter);
        $this->assertTrue($adapter->get('xhprof.enable'));
        $this->assertSame(3600, $adapter->get('xhprof.log_ttl'));
        // 缺省分支：键缺失返回 default，子树存在但子键缺失也返回 default
        $this->assertSame('d', $adapter->get('xhprof.nope', 'd'));
        $this->assertSame('d', $adapter->get('missing.enable', 'd'));
    }

    #[Test]
    public function logAdapterForwardsToSupportLog(): void
    {
        (new LogAdapter())->error('boom', ['a' => 1]);
        $this->assertInstanceOf(LoggerInterface::class, new LogAdapter());
        $this->assertSame(['boom'], Log::$errors);
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
        $this->assertContains('lpush:list', Redis::$log);

        $this->assertSame(1, $adapter->del('k'));
        $this->assertNull($adapter->get('k'));
    }

    #[Test]
    public function requestAdapterDelegates(): void
    {
        $request = new Request(['a' => 1, 'b' => 2], [
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
        $this->assertSame(['a' => 1, 'b' => 2], $adapter->all());
        $this->assertSame('POST', $adapter->method());
        $this->assertSame('yes', $adapter->header('x-fwd'));
        $this->assertSame('example.com', $adapter->host());
        $this->assertSame('/path', $adapter->uri());
        $this->assertSame('http://example.com:8080/path', $adapter->url());
        $this->assertSame('10.0.0.1', $adapter->getRealIp());
    }

    /**
     * 契约 R-2：host() 不含端口 —— 而 webman 的 `host()` **默认就带端口**。
     *
     * 真包实测（workerman 5.2.2，/tmp 有装好的包）：`host()` 给 'example.com:8080'、
     * `host(true)` 给 'example.com'。桩按同一语义实现（见 framework-stubs.php），
     * 所以「忘了传 true」在这里会红。**夹具必须带端口**：不带端口时两种调用同值，
     * 那样的夹具对这条契约永远绿 —— 这个 bug 之前就是这么漏掉的。
     */
    #[Test]
    public function requestAdapterHostDropsThePortThatTheRealRequestKeeps(): void
    {
        $request = new Request([], ['host' => 'example.com:8080']);

        // 桩的两半（判别力来源，不是产品断言）：真包给什么，桩就得给什么
        $this->assertSame('example.com:8080', $request->host(), '默认原样返回 Host 头，端口还在');
        $this->assertSame('example.com', $request->host(true), 'true 才剥端口');
        // 剥的是**尾部数字端口**（真包正则 /:\d{1,5}$/）：冒号后不是数字就原样给
        $this->assertSame('example.com:', (new Request([], ['host' => 'example.com:']))->host(true));

        $this->assertSame('example.com', (new RequestAdapter($request))->host(), '适配器必须走剥端口的那次调用');
    }

    /**
     * get() 与 all() 必须同源 —— 同一个 key 两种取法给同一个答案。
     *
     * 潜伏项：README 给 webman 的报告路由是 Route::get，所以"参数只出现在 body 里"目前
     * 到不了 Core。但真实 webman 的 Request::get() 只读 query，而 all() 是
     * `get() + post()`（webman-framework v2.2.4 src/Http/Request.php:71），桩按同一语义
     * 把两个来源拆开了（第二参的 'post'）。旧实现是裸透传：get('only_body') 给 default，
     * all()['only_body'] 给值 —— Core 的白名单校验走前者、渲染走后者，两个答案。
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
        $this->assertSame('b', $adapter->get('only_body'), 'query 里没有的键必须从 body 里取到');
        $this->assertSame('d', $adapter->get('missing', 'd'));
    }

    #[Test]
    public function responseAdapterBodyHeadersStatus(): void
    {
        $adapter = new ResponseAdapter(new Response(200));
        $this->assertInstanceOf(ResponseInterface::class, $adapter);

        $adapter->withBody('hello')->withHeaders(['X-A' => '1', 'X-B' => '2']);
        $res = $adapter->send();
        // 真包上 $status/$headers/$body 都是 **protected**（workerman 5.2.2
        // src/Protocols/Http/Response.php:268-270），只能走访问器读：
        // `$res->headers['X-A']` 在真包上是 Error: Cannot access protected property，
        // 桩此前把它们开成 public，把这行写法的错误掩盖了。
        $this->assertSame('hello', $res->rawBody());
        $this->assertSame('1', $res->getHeader('X-A'));
        $this->assertSame('2', $res->getHeader('X-B'));

        // withStatus 就地改同一个响应（workerman 的 Response 是可变的）
        $adapter->withStatus(404);
        $res2 = $adapter->send();
        $this->assertSame($res, $res2, '就地改，不是重建');
        $this->assertSame(404, $res2->getStatusCode());
        $this->assertSame('hello', $res2->rawBody(), '先设的正文不能因为改状态而丢');
    }

    /**
     * 调用顺序不是契约的一部分：先设的头不能因为后面设正文/状态就消失。
     *
     * 旧实现 `withStatus()` 里是 `new Response($status)` —— 重建响应，此前
     * withHeaders()/withBody() 攒下的一切全丢。触发形态是 Core 的任意一条链被重排
     * （报告页那条就是 status → headers → body，见 Symfony 的 serveReport()）。
     * 另外 7 个适配器都是就地改，这里对齐。
     */
    #[Test]
    public function responseAdapterHeadersSurviveLaterBodyAndStatus(): void
    {
        $adapter = new ResponseAdapter(new Response(200));
        $adapter->withHeaders(['Cache-Control' => 'public, max-age=86400'])
            ->withBody('hello')
            ->withStatus(404);

        $res = $adapter->send();
        $this->assertSame('public, max-age=86400', $res->getHeader('Cache-Control'), '先设的头被 withBody/withStatus 冲掉了');
        $this->assertSame('hello', $res->rawBody());
        $this->assertSame(404, $res->getStatusCode());
    }

    #[Test]
    public function responseAdapterFile(): void
    {
        $path = $this->tempFile('xhprof-webman.css', '.a{}');
        $adapter = new ResponseAdapter(new Response(200));
        $adapter->file($path);
        $res = $adapter->send();
        // 真包唯一的 public 属性是 `?array $file`（workerman 5.2.2 :56），
        // 形状 ['file'=>路径,'offset'=>int,'length'=>int]（:434）；`$res->filePath` 真包上没有。
        // 旧断言里的 `$res->body === '.a{}'` 也不成立：文件内容由 __toString()/ResponseEmitter
        // 在发送时从磁盘流出，**不进**响应对象 —— 这条在真包声明面上无法表达，已删（见报告）。
        $this->assertSame(['file' => $path, 'offset' => 0, 'length' => 0], $res->file);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('', $res->rawBody(), '文件响应的正文不落进对象');

        // 文件不存在：真包 withFile() 不记路径，而是改 404 并把固定正文塞进 body（:430-438）
        $adapter->file('/no/such/file.css');
        $res = $adapter->send();
        $this->assertNull($res->file, '不存在的文件不会进 $file');
        $this->assertSame(404, $res->getStatusCode());
        $this->assertSame('<h3>404 Not Found</h3>', $res->rawBody());
    }

    #[Test]
    public function middlewareBootstrapsAndReturnsHandlerResponse(): void
    {
        Registry::$webmanConfig = [
            'plugin' => ['aaron-dev' => ['xhprof' => ['xhprof' => [
                'enable' => true,
                // time_limit 极大 → save_run 提前返回，本测试只验证接线不验证落库
                'time_limit' => PHP_INT_MAX,
            ]]]],
        ];
        $middleware = new XhprofMiddleware();
        $request = new Request([], ['uri' => '/index']);
        $handler = function (Request $r): Response {
            return new Response(200, [], 'ok');
        };

        $res = $middleware->process($request, $handler);

        $this->assertInstanceOf(Response::class, $res);
        $this->assertSame('ok', $res->rawBody());
        $this->assertInstanceOf(CacheInterface::class, CoreXhprof::getCache());
        $this->assertInstanceOf(ConfigAdapter::class, CoreXhprof::getConfig());
        $this->assertTrue(CoreXhprof::getConfig()->get('xhprof.enable'));
        // 扩展均已安装时不应输出扩展缺失告警
        $this->assertSame([], Log::$errors);
    }

    #[Test]
    public function staticControllerServesRealAsset(): void
    {
        $adapter = new StaticController();
        $request = new Request([], ['uri' => '/xhprof-assets/js/xhprof_report.js']);
        $res = $adapter->serve($request);
        $this->assertInstanceOf(Response::class, $res);
        $this->assertNotNull($res->file);
        $this->assertStringContainsString('src/html/js/xhprof_report.js', $res->file['file']);
        $this->assertSame('public, max-age=86400', $res->getHeader('Cache-Control'));
    }

    #[Test]
    public function staticControllerRejectsInvalidPaths(): void
    {
        $adapter = new StaticController();
        foreach (['/other', '/xhprof-assets/../etc/passwd', '/xhprof-assets/nope.css'] as $uri) {
            $res = $adapter->serve(new Request([], ['uri' => $uri]));
            $this->assertSame('', $res->rawBody(), "uri=$uri 应返回空 body");
            $this->assertNull($res->file);
        }
    }

    #[Test]
    public function xhprofFacadeExtendsCore(): void
    {
        $this->assertTrue(is_subclass_of(Xhprof::class, CoreXhprof::class));
        $this->assertInstanceOf(CoreXhprof::class, new Xhprof());
        $this->assertSame('xhprof', Xhprof::$key_prefix);
        $this->assertSame(['/xhprof'], Xhprof::$ignore_url_arr);
    }

    #[Test]
    public function installByRelationCopiesConfig(): void
    {
        $this->tempBasePath = sys_get_temp_dir() . '/xhprof-install-' . bin2hex(random_bytes(4));
        mkdir($this->tempBasePath, 0777, true);
        Registry::$basePath = $this->tempBasePath;

        Install::installByRelation();

        $this->assertCount(1, Registry::$copied);
        $entry = Registry::$copied[0];
        $this->assertStringContainsString('config/plugin/aaron-dev/xhprof', $entry);
        $this->assertStringContainsString($this->tempBasePath . '/config/plugin/aaron-dev/xhprof', $entry);
    }

    #[Test]
    public function uninstallByRelationSkipsWhenMissing(): void
    {
        $this->tempBasePath = sys_get_temp_dir() . '/xhprof-uninstall-' . bin2hex(random_bytes(4));
        mkdir($this->tempBasePath, 0777, true);
        Registry::$basePath = $this->tempBasePath;

        Install::uninstallByRelation();

        $this->assertSame([], Registry::$removed);
        // 目录不存在时应安静跳过而不是抛异常——能执行到这里本身即证明未抛
    }

    #[Test]
    public function uninstallByRelationUnlinksFile(): void
    {
        $this->tempBasePath = sys_get_temp_dir() . '/xhprof-unlink-' . bin2hex(random_bytes(4));
        Registry::$basePath = $this->tempBasePath;
        $path = $this->tempBasePath . '/config/plugin/aaron-dev/xhprof';
        mkdir(dirname($path), 0777, true);
        file_put_contents($path, 'x');

        Install::uninstallByRelation();

        $this->assertFileDoesNotExist($path);
    }

    #[Test]
    public function uninstallByRelationRemovesDir(): void
    {
        $this->tempBasePath = sys_get_temp_dir() . '/xhprof-rmdir-' . bin2hex(random_bytes(4));
        Registry::$basePath = $this->tempBasePath;
        $path = $this->tempBasePath . '/config/plugin/aaron-dev/xhprof';
        mkdir($path, 0777, true);
        file_put_contents($path . '/xhprof.php', 'x');

        Install::uninstallByRelation();

        $this->assertSame([$path], Registry::$removed);
    }
}
