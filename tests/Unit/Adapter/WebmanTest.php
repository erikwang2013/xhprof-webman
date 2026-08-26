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
            'host' => 'example.com',
            'uri' => '/path',
            'url' => 'http://example.com/path',
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
        $this->assertSame('http://example.com/path', $adapter->url());
        $this->assertSame('10.0.0.1', $adapter->getRealIp());
    }

    #[Test]
    public function responseAdapterBodyHeadersStatus(): void
    {
        $adapter = new ResponseAdapter(new Response(200));
        $this->assertInstanceOf(ResponseInterface::class, $adapter);

        $adapter->withBody('hello')->withHeaders(['X-A' => '1', 'X-B' => '2']);
        $res = $adapter->send();
        $this->assertSame('hello', $res->body);
        $this->assertSame('1', $res->headers['X-A']);
        $this->assertSame('2', $res->headers['X-B']);

        // withStatus 返回全新的 Response(status)
        $adapter->withStatus(404);
        $res2 = $adapter->send();
        $this->assertNotSame($res, $res2);
        $this->assertSame(404, $res2->status);
        $this->assertSame('', $res2->body);
    }

    #[Test]
    public function responseAdapterFile(): void
    {
        $path = $this->tempFile('xhprof-webman.css', '.a{}');
        $adapter = new ResponseAdapter(new Response(200));
        $adapter->file($path);
        $res = $adapter->send();
        $this->assertSame($path, $res->filePath);
        $this->assertSame('.a{}', $res->body);

        // 不存在时仍返回携带路径的 Response（webman 语义），body 为空
        $adapter->file('/no/such/file.css');
        $res = $adapter->send();
        $this->assertSame('/no/such/file.css', $res->filePath);
        $this->assertSame('', $res->body);
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
        $this->assertSame('ok', $res->body);
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
        $this->assertNotNull($res->filePath);
        $this->assertStringContainsString('src/html/js/xhprof_report.js', $res->filePath);
        $this->assertSame('public, max-age=86400', $res->headers['Cache-Control']);
    }

    #[Test]
    public function staticControllerRejectsInvalidPaths(): void
    {
        $adapter = new StaticController();
        foreach (['/other', '/xhprof-assets/../etc/passwd', '/xhprof-assets/nope.css'] as $uri) {
            $res = $adapter->serve(new Request([], ['uri' => $uri]));
            $this->assertSame('', $res->body, "uri=$uri 应返回空 body");
            $this->assertNull($res->filePath);
        }
    }

    #[Test]
    public function xhprofFacadeExtendsCore(): void
    {
        $this->assertTrue(is_subclass_of(Xhprof::class, CoreXhprof::class));
        $this->assertInstanceOf(CoreXhprof::class, new Xhprof());
        $this->assertSame('xhprof', Xhprof::$key_prefix);
        $this->assertSame(['/test'], Xhprof::$ignore_url_arr);
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
        // 目录不存在时跳过，不报错
        $this->assertTrue(true);
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
