<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface as CoreLoggerInterface;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\RedisAdapterTrait;
use ErikWang2013\Xhprof\Core\StaticController;
use ErikWang2013\Xhprof\Core\Xhprof as CoreXhprof;
use ErikWang2013\Xhprof\Drupal\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Drupal\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Drupal\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Drupal\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Drupal\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Drupal\Adapter\RoutedPathRequestAdapter;
use ErikWang2013\Xhprof\Drupal\Controller\XhprofController;
use ErikWang2013\Xhprof\Drupal\XhprofMiddleware;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeRequest;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\Drupal\FakeConfigFactory;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\Drupal\FakeLoggerFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Drupal 适配器、中间件与控制器。
 *
 * Drupal 9+ 的 Request/Response 就是 Symfony 的，所以这里用的是与 SymfonyTest
 * 同一批桩（tests/Stubs/Framework/Symfony.php），真实语义另由
 * tools/contracts/cases/Drupal.php 用真包再验一遍（L2）。
 *
 * Drupal 独有的三处，各自都有一条「去掉就变红」的用例：
 *  1. handle() 的子请求透传   → subRequestDoesNotTouchHostRequest
 *  2. ConfigAdapter 的键路径   → configAdapterSupportsBothKeyShapes
 *  3. 装配文件与 schema 键集   → installYamlAndSchemaDeclareTheSameKeys
 */
class DrupalTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->saved = $this->snapshotXhprofStatics();
    }

    protected function tearDown(): void
    {
        // 与 SymfonyTest 同理：中间件在 finally 里 stop，但子请求用例会故意把主请求
        // 留在采样中（那是为了观测子请求有没有提前终止它），这里补一次收尾。
        // 放在 restore 之后：Xhprof::$cache 已复位为 null，落库会静默失败，不碰 FakeCache。
        xhprof_disable();
        $this->restoreXhprofStatics($this->saved);
    }

    // ---------- 辅助 ----------

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
            'log_ttl' => CoreXhprof::$log_ttl,
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
        CoreXhprof::$log_ttl = $s['log_ttl'];
        CoreXhprof::$ui_html = $s['ui_html'];
        CoreXhprof::$symbol_lookup_url = $s['symbol_lookup_url'];
    }

    private function configFactory(array $config = []): FakeConfigFactory
    {
        // 真实的 config.factory->get('xhprof.settings') 读的就是
        // drupal/xhprof/config/install/xhprof.settings.yml 装出来的那块配置
        return new FakeConfigFactory(['xhprof.settings' => $config]);
    }

    /**
     * 站点装在子目录时的请求：文档根 = /sites/app、站点在 /sites/app/sub，
     * front controller 是 /sub/index.php（base path 由 SCRIPT_NAME/SCRIPT_FILENAME 决定）。
     *
     * 实测 symfony/http-foundation v7.4.19（/tmp 真包，11 个语料与桩逐个比对过）：
     * getRequestUri() 带 '/sub'，getPathInfo() 剥掉它 —— 而 Drupal 路由匹配的是后者。
     */
    private function subdirectoryRequest(string $path): Request
    {
        return Request::create($path, 'GET', [], [], [], [
            'SCRIPT_NAME' => '/sub/index.php',
            'SCRIPT_FILENAME' => '/var/www/html/sub/index.php',
        ]);
    }

    /**
     * @param callable|null $handler 置空则返回固定响应；给定则完全接管
     */
    private function kernel(?callable $handler = null)
    {
        return new class($handler) implements HttpKernelInterface {
            /** @var array<int, int> 收到的 $type 序列 */
            public array $calls = [];

            /** @var callable|null */
            private $handler;

            public function __construct(?callable $handler)
            {
                $this->handler = $handler;
            }

            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                $this->calls[] = $type;
                if ($this->handler !== null) {
                    return ($this->handler)($request, $type, $catch);
                }
                return new Response('business');
            }
        };
    }

    private function middleware(
        HttpKernelInterface $kernel,
        FakeCache $cache,
        array $config = ['enable' => true],
        ?FakeLoggerFactory $loggerFactory = null
    ): XhprofMiddleware {
        return new XhprofMiddleware(
            $kernel,
            $this->configFactory($config),
            $loggerFactory ?? new FakeLoggerFactory(),
            $cache
        );
    }

    /** @return array<int, string> run_id 列表（新→旧） */
    private function runs(FakeCache $cache): array
    {
        return $cache->lRange('xhprof:run_id', 0, -1);
    }

    /** 取最新一条采样数据，顺带断言它真的落了库 */
    private function latestRun(FakeCache $cache): array
    {
        $runs = $this->runs($cache);
        $this->assertCount(1, $runs, '应恰好落库一条采样');
        $data = unserialize((string) $cache->get('xhprof:xhprof_log:' . $runs[0]));
        $this->assertIsArray($data);
        $this->assertArrayHasKey('main()', $data, '落库的必须是本次真实采样，不是空数据');
        return $data;
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
        // R-1：isIgnore() 对 uri() 做子串匹配，返回绝对 URL 会让 host 叫 xhprof.*
        // 的站点（例如 xhprof.example.com）全站被误忽略
        $adapter = new RequestAdapter(Request::create('http://xhprof.example.com/admin?x=1'));
        $this->assertSame('/admin?x=1', $adapter->uri());
        $this->assertStringNotContainsString('://', $adapter->uri());
        $this->assertStringNotContainsString('xhprof.', $adapter->uri());

        // 默认的 ignore_url_arr 命中报告页，命中静态资源，但不能命中普通页面
        $this->assertStringContainsString('/xhprof', (new RequestAdapter(Request::create('/xhprof')))->uri());
        $this->assertStringNotContainsString('/xhprof', $adapter->uri());
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
        // R-3：getClientIp() 声明 ?string，无 REMOTE_ADDR（CLI / 内部子请求）时返回 null；
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

    #[Test]
    public function routedPathAdapterDiffersFromItsParentOnUriOnly(): void
    {
        // 静态资源路由专用：uri() 换成了路由匹配用的 pathInfo（子目录安装下两者不同），
        // 其余方法必须一个不差地沿用父类 —— 只覆盖了 uri() 才敢让它只服务资源路由。
        $request = $this->subdirectoryRequest('/sub/xhprof-assets/css/xhprof.css?x=1');
        $plain = new RequestAdapter($request);
        $routed = new RoutedPathRequestAdapter($request);

        $this->assertInstanceOf(RequestInterface::class, $routed);
        $this->assertSame('/sub/xhprof-assets/css/xhprof.css?x=1', $plain->uri(), '父类给原始请求 URI（带 base path）');
        $this->assertSame('/xhprof-assets/css/xhprof.css', $routed->uri(), '子类给路由匹配到的路径（不带查询串）');

        $this->assertSame('1', $routed->get('x'));
        $this->assertSame($plain->get('x'), $routed->get('x'));
        $this->assertSame($plain->all(), $routed->all());
        $this->assertSame($plain->method(), $routed->method());
        $this->assertSame($plain->header('x-none'), $routed->header('x-none'));
        $this->assertSame($plain->host(), $routed->host());
        $this->assertSame($plain->url(), $routed->url());
        $this->assertSame($plain->getRealIp(), $routed->getRealIp());
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
        $adapter = new ResponseAdapter();
        $this->assertSame($adapter, $adapter->file($this->cssFile()));
        $this->assertSame($adapter, $adapter->withHeaders(['Cache-Control' => 'public, max-age=86400']));

        // file() 换了整个 Response 对象，头必须落在新对象上而不是被丢掉的那个
        $response = $adapter->send();
        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame($this->cssFile(), $response->getFile()->getPathname());
        $this->assertStringContainsString('max-age=86400', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('text/css', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function responseAdapterFilePinsContentTypeFromCoreMap(): void
    {
        // 不钉类型的话 BinaryFileResponse::prepare() 会按**文件内容**猜（走 Mime 组件的内容嗅探）：
        // 实测本包的 css/js 全被猜成 text/plain（js/dataTables.bootstrap.js 甚至是 text/html），
        // 浏览器会直接丢弃样式表和脚本 → 报告页裸奔。这里断言的是包内真实资源，不是临时文件。
        foreach ([
            'src/html/css/xhprof.css' => 'text/css',
            'src/html/js/xhprof_report.js' => 'application/javascript',
            'src/html/images/sort_both.png' => 'image/png',
            'src/html/jquery/indicator.gif' => 'image/gif',
        ] as $rel => $expected) {
            $response = (new ResponseAdapter())->file(dirname(__DIR__, 3) . '/' . $rel)->send();
            $this->assertInstanceOf(BinaryFileResponse::class, $response, $rel);
            $this->assertSame($expected, $response->headers->get('Content-Type'), $rel);
        }
    }

    #[Test]
    public function responseAdapterFileMissingBecomes404(): void
    {
        // 与 Slim/Yii3/Wordpress 同形：读不出文件给 404 而不是抛 FileNotFoundException
        // （那会变成 500，且异常要穿过 HttpKernel）
        $response = (new ResponseAdapter())->file($this->cssFile() . '.missing')->send();
        $this->assertSame(404, $response->getStatusCode());
        $this->assertNotInstanceOf(BinaryFileResponse::class, $response);
    }

    #[Test]
    public function responseAdapterInvalidStatusThrows(): void
    {
        // 兜底防线：HttpFoundation 对 <100 / >599 抛 InvalidArgumentException，
        // 适配器不该把它吞成 200
        $this->expectException(\InvalidArgumentException::class);
        (new ResponseAdapter())->withStatus(99);
    }

    private function cssFile(): string
    {
        return dirname(__DIR__, 3) . '/src/html/css/xhprof.css';
    }

    // ---------- ConfigAdapter ----------

    #[Test]
    public function configAdapterSupportsBothKeyShapes(): void
    {
        // R-6：bootstrap() 用 get('xhprof') 取整块，index() 用 get('xhprof.assets_url') 取叶子
        $factory = $this->configFactory(['enable' => true, 'assets_url' => '/xhprof-assets']);
        $config = new ConfigAdapter($factory);
        $this->assertInstanceOf(\ErikWang2013\Xhprof\Core\Contract\ConfigInterface::class, $config);

        $block = $config->get('xhprof');
        $this->assertIsArray($block);
        $this->assertTrue($block['enable']);

        $this->assertSame('/xhprof-assets', $config->get('xhprof.assets_url'));
        $this->assertSame('d', $config->get('xhprof.nope', 'd'));

        // 首段 'xhprof' 必须映射到 Drupal 的配置对象名 'xhprof.settings'，
        // 而不是去取一个叫 'xhprof' 的配置对象（那在 Drupal 里不存在，永远是空数组）
        $this->assertSame(['xhprof.settings'], array_values(array_unique($factory->requested)));
    }

    #[Test]
    public function configAdapterMissingConfigFallsBackToDefault(): void
    {
        // 模块未安装 / 配置被删时 config.factory 给空配置对象而不是抛异常，
        // 此时 get('xhprof', []) 必须给 $default，否则 bootstrap() 会拿到 []
        // 之外的意外值、isEnabled() 读到 null
        $config = new ConfigAdapter(new FakeConfigFactory());
        $this->assertSame([], $config->get('xhprof', []));
        $this->assertNull($config->get('xhprof'));
        $this->assertSame('d', $config->get('xhprof.enable', 'd'));
        $this->assertSame('d', $config->get('nope', 'd'));
    }

    #[Test]
    public function configAdapterKeepsFalsyValuesInsteadOfFallingBack(): void
    {
        // R-6/R-7 家族：$default 只在**键不存在**时生效（Drupal 的 ConfigBase::get() 那时给 null）。
        // 配置里显式写下的假值必须原样返回，尤其是空列表：用户把 ignore_url_arr 设成 []
        // 的意思是「什么都不过滤」，一旦被 $default（['/xhprof']）顶掉，报告页反而被排除在
        // 采样之外，而且没有任何报错。（对照：另外五个适配器里 array_replace 整体替换列表键，
        // Drupal 这里没有合并步骤 —— 配置对象给什么就是什么，连"像合并"的机会都不该有。）
        $config = new ConfigAdapter($this->configFactory([
            'enable' => true,
            'ignore_url_arr' => [],
            'assets_url' => '',
            'view_wtred' => 0,
            'log_ttl' => '0',
            'key_prefix' => false,
        ]));
        $this->assertSame([], $config->get('xhprof.ignore_url_arr', ['/xhprof']));
        $this->assertSame('', $config->get('xhprof.assets_url', '/xhprof-assets'));
        $this->assertSame(0, $config->get('xhprof.view_wtred', 3));
        $this->assertSame('0', $config->get('xhprof.log_ttl', 604800));
        $this->assertSame(false, $config->get('xhprof.key_prefix', 'xhprof'));
        // 键不存在（null）才轮到 $default
        $this->assertSame(['/xhprof'], $config->get('xhprof.nope', ['/xhprof']));
    }

    #[Test]
    public function configAdapterFeedsBootstrapAndIsEnabled(): void
    {
        // 端到端：中间件 bootstrap 之后 isEnabled() 读到的就是这份配置
        $cache = new FakeCache();
        $kernel = $this->kernel();
        $this->middleware($kernel, $cache, ['enable' => false, 'key_prefix' => 'pfx'])->handle(Request::create('/index'));

        $this->assertFalse(\ErikWang2013\Xhprof\Core\XhprofProfiler::isEnabled());
        $this->assertSame('pfx', CoreXhprof::$key_prefix);
        $this->assertInstanceOf(ConfigAdapter::class, CoreXhprof::getConfig());
    }

    #[Test]
    public function installYamlAndSchemaDeclareTheSameKeys(): void
    {
        // 键集不一致的后果：config:import / config_inspector 报 "missing schema"，
        // 而两份文件都在本卡的文件范围内，最容易在后期加键时漏改一份
        $install = file_get_contents(dirname(__DIR__, 3) . '/drupal/xhprof/config/install/xhprof.settings.yml');
        $schema = file_get_contents(dirname(__DIR__, 3) . '/drupal/xhprof/config/schema/xhprof.schema.yml');
        $this->assertIsString($install);
        $this->assertIsString($schema);

        // 安装文件：顶层 `key: value`；schema：mapping 里 4 空格缩进的 `key:`
        preg_match_all('/^([a-z_]+):/m', $install, $a);
        preg_match_all('/^    ([a-z_]+):$/m', $schema, $b);

        $this->assertSame(
            ['enable', 'time_limit', 'log_num', 'view_wtred', 'ignore_url_arr', 'assets_url', 'auth_token', 'key_prefix', 'log_ttl', 'locale'],
            array_values(array_unique($a[1])),
            '安装文件应恰好声明这 10 个键'
        );
        $this->assertSame($a[1], $b[1], 'install 与 schema 的键集/顺序必须一致');
    }

    // ---------- LogAdapter ----------

    #[Test]
    public function logAdapterForwardsToXhprofChannel(): void
    {
        $factory = new FakeLoggerFactory();
        $adapter = new LogAdapter($factory);
        $this->assertInstanceOf(CoreLoggerInterface::class, $adapter);

        $adapter->error('boom', ['a' => 1]);

        $this->assertSame(['boom'], $factory->errors);
        // Drupal 的惯例是每个模块一个通道；用 'xhprof' 才能在 watchdog 里过滤出本模块
        $this->assertSame(['xhprof'], $factory->channels);
    }

    // ---------- RedisAdapter ----------

    #[Test]
    public function redisAdapterUsesTraitAndDoesNotConnectOnConstruct(): void
    {
        $adapter = new RedisAdapter();
        $this->assertInstanceOf(CacheInterface::class, $adapter);
        // R-9：lPush 的返回值、mget([]) 的短路等语义全在 trait 里，
        // 自己重写一遍就会漂移；class_uses 是唯一能证明「用的是那份实现」的断言
        $this->assertContains(RedisAdapterTrait::class, class_uses($adapter));

        // 懒连接：中间件每个请求都会 new 一个（$this->cache ?? new RedisAdapter()），
        // 构造时建连就是每个请求一次握手——即使采样是关的。用 Closure::bind 读私有
        // 属性而不是 ReflectionProperty::getValue()：CI 矩阵含 PHP 8.0，
        // 那里 setAccessible 之外的写法读私有属性会抛。
        $read = \Closure::bind(static function (RedisAdapter $a): mixed {
            return $a->client;
        }, null, RedisAdapter::class);
        $this->assertIsCallable($read);
        $this->assertNull($read($adapter), '构造时不得建连');
    }

    // ---------- XhprofMiddleware：三条接线 ----------

    #[Test]
    public function enabledMainRequestSamplesAndSaves(): void
    {
        $cache = new FakeCache();
        $kernel = $this->kernel();
        $response = $this->middleware($kernel, $cache)->handle(Request::create('/index?x=1'));

        $this->assertSame([HttpKernelInterface::MAIN_REQUEST], $kernel->calls, '内层 kernel 必须被调用一次');
        $this->assertSame('business', $response->getContent(), '业务响应必须原样返回');
        $this->latestRun($cache);
    }

    #[Test]
    public function disabledConfigSamplesNothing(): void
    {
        $cache = new FakeCache();
        $this->middleware($this->kernel(), $cache, ['enable' => false])->handle(Request::create('/index'));

        $this->assertNull(xhprof_disable(), 'enable=false 不应启动采样');
        $this->assertSame([], $cache->calls, '不采样就不该碰缓存');
    }

    #[Test]
    public function bootstrapHappensEvenWhenDisabled(): void
    {
        // 顺序守卫：bootstrap 必须在 isEnabled() 之前。反过来写的话，
        // 常驻进程里读到的是上一个请求的 XhprofProfiler::$config——
        // 上一个请求开了采样，这一个请求即使配了 enable=false 也会被采样。
        $cache = new FakeCache();
        $this->middleware($this->kernel(), $cache, ['enable' => false, 'key_prefix' => 'pfx'])
            ->handle(Request::create('/index'));

        $this->assertSame('pfx', CoreXhprof::$key_prefix, 'enable=false 时也必须完成 bootstrap');
        $this->assertInstanceOf(RequestAdapter::class, CoreXhprof::getRequest());
        $this->assertInstanceOf(ResponseAdapter::class, CoreXhprof::getResponse());
        $this->assertInstanceOf(LogAdapter::class, CoreXhprof::getLogger());
    }

    #[Test]
    public function missingCacheFallsBackToRedisAdapter(): void
    {
        // 契约要求 bootstrap() 的 cache 非空：为 null 时 save_run() 在 null 上调用
        // lPush()，异常被 XhprofProfiler::stop() 的 catch 吞掉 —— 表现是"请求正常，
        // 但报告页永远没有数据"。Drupal 核心不含 Redis，故中间件自带一个 phpredis 兜底；
        // enable=false 保证这条用例不会真的去连 Redis。
        $middleware = new XhprofMiddleware(
            $this->kernel(),
            $this->configFactory(['enable' => false]),
            new FakeLoggerFactory()
        );
        $middleware->handle(Request::create('/index'));

        $this->assertInstanceOf(RedisAdapter::class, CoreXhprof::getCache());
    }

    #[Test]
    public function staleEnabledStateDoesNotLeakIntoDisabledRequest(): void
    {
        // 顺序守卫的另一半：XhprofProfiler::$config 是**静态**的，常驻进程（RoadRunner/Swoole）
        // 里它活到下一个请求。第一个请求 enable=true 把它置上，第二个请求 enable=false——
        // 若 isEnabled() 先于 bootstrap() 求值，第二个请求会沿用第一个请求的 enable=true。
        $cache = new FakeCache();
        $kernel = $this->kernel();
        $this->middleware($kernel, $cache, ['enable' => true])->handle(Request::create('/first'));
        $this->latestRun($cache);
        $cache->reset();

        $this->middleware($kernel, $cache, ['enable' => false])->handle(Request::create('/second'));

        $this->assertNull(xhprof_disable(), 'enable=false 的请求不该因为上一个请求开着采样就被采样');
        $this->assertSame([], $cache->calls, '更不该落库');
    }

    #[Test]
    public function exceptionStillStopsAndSaves(): void
    {
        // try/finally：业务抛异常也必须 stop，否则采样状态泄漏到下一个请求
        $cache = new FakeCache();
        $kernel = $this->kernel(static function (): Response {
            throw new \RuntimeException('business blew up');
        });

        try {
            $this->middleware($kernel, $cache)->handle(Request::create('/index'));
            $this->fail('业务异常必须继续向上抛，不能被中间件吞掉');
        } catch (\RuntimeException $e) {
            $this->assertSame('business blew up', $e->getMessage());
        }

        $this->assertNull(xhprof_disable(), '异常路径也必须停掉采样');
        $this->latestRun($cache);
    }

    #[Test]
    public function ignoredUrlIsSampledButNotSaved(): void
    {
        // enable=true 且 URI 命中 ignore_url_arr：采样会开，但 save_run() 里
        // isIgnore() 拦下不落库。**用普通页面而不是报告页**：报告页现在在 xhprofStart()
        // 之前就被守卫跳过了（见下面的 reportAndAssetsAreNeverSampled…），走不到这条路径。
        $cache = new FakeCache();
        $this->middleware($this->kernel(), $cache, ['enable' => true, 'ignore_url_arr' => ['/admin']])
            ->handle(Request::create('/admin/config'));

        $this->assertSame([], $this->runs($cache));
    }

    #[Test]
    public function reportAndAssetsAreNeverSampledEvenWhenNothingIsIgnored(): void
    {
        // 报告页与静态资源：只跳过采样、**不短路响应**。所以即便用户把 ignore_url_arr
        // 清空（「什么都不过滤」），这两个请求也不该出现在报告里 —— 否则「打开报告」这个
        // 动作本身会被写进报告，每次刷新新增一条 run。
        $cache = new FakeCache();
        $controller = new XhprofController();
        $reached = [];
        $kernel = $this->kernel(function (Request $request) use ($controller, &$reached): Response {
            // 记录「响应确实由模块控制器产生」——即中间件没有短路掉路由
            $path = (string) parse_url($request->getRequestUri(), PHP_URL_PATH);
            $reached[] = $path;

            return str_starts_with($path, '/xhprof-assets')
                ? $controller->assets($request)
                : $controller->report();
        });
        $middleware = $this->middleware($kernel, $cache, ['enable' => true, 'ignore_url_arr' => []]);

        $report = $middleware->handle(Request::create('/xhprof'));
        $assets = $middleware->handle(Request::create('/xhprof-assets/css/xhprof.css'));

        // 响应仍然由路由/控制器产生（不是中间件短路），且内容真实
        $this->assertSame(['/xhprof', '/xhprof-assets/css/xhprof.css'], $reached);
        $this->assertSame(200, $report->getStatusCode());
        $this->assertStringContainsString('XHProf', (string) $report->getContent());
        $this->assertInstanceOf(BinaryFileResponse::class, $assets);
        // 两个请求都没落库
        $this->assertSame([], $this->runs($cache));

        // 正对照：同一份配置下普通页面照样被采样并落库
        // （没有它，这条测试在「什么都没跑」时也会绿）
        $middleware->handle(Request::create('/node/1'));
        $this->latestRun($cache);
    }

    // ---------- XhprofMiddleware：守卫（子请求） ----------

    #[Test]
    public function subRequestDoesNotTouchHostRequest(): void
    {
        $cache = new FakeCache();
        $observations = [];
        $middleware = null;

        // 内层 kernel 在主请求处理过程中发起一个子请求（Drupal 的 ESI / fragment /
        // 渲染子请求都是这个形状：$http_kernel->handle($sub, SUB_REQUEST)）。
        // 必须按 $type 分岔：子请求会原样回到这个 handler，不分岔就是无限递归。
        $kernel = $this->kernel(function (Request $request, int $type) use (&$observations, &$middleware, $cache): Response {
            if (HttpKernelInterface::MAIN_REQUEST !== $type) {
                return new Response('fragment');
            }
            $middleware->handle(Request::create('/_fragment'), HttpKernelInterface::SUB_REQUEST);
            // 观测点：子请求刚跑完，主请求还没结束
            $observations['cache_calls'] = $cache->calls;
            $observations['uri'] = CoreXhprof::getRequest()->uri();
            return new Response('business');
        });

        $middleware = $this->middleware($kernel, $cache);
        $middleware->handle(Request::create('/page'));

        $this->assertSame([HttpKernelInterface::MAIN_REQUEST, HttpKernelInterface::SUB_REQUEST], $kernel->calls);
        $this->assertSame([], $observations['cache_calls'], '子请求不得落库：它结束时会 xhprof_disable()，把主请求的采样腰斩');
        $this->assertSame('/page', $observations['uri'], '子请求不得 bootstrap 掉宿主请求的适配器');
        $this->latestRun($cache);
    }

    #[Test]
    public function reportAndAssetsAreNotSampledWhenDrupalLivesInASubdirectory(): void
    {
        // 中间件是最外层（priority 1000），跑在路由之前，只能自己算路径 —— 而路由算的
        // 是剥掉 base path 的 pathInfo。用原始 URI 匹配的话子目录安装下全部失配：
        // 报告页与资源请求照常采样，ignore_url_arr 被清空（「什么都不过滤」）时每次刷新
        // 报告都多一条 run，正是那份配置想避免的事。
        $cache = new FakeCache();
        $middleware = $this->middleware($this->kernel(), $cache, ['enable' => true, 'ignore_url_arr' => []]);

        $middleware->handle($this->subdirectoryRequest('/sub/xhprof'));
        $middleware->handle($this->subdirectoryRequest('/sub/xhprof-assets/css/xhprof.css'));
        $this->assertSame([], $this->runs($cache), '报告页与资源请求不得落库');

        // 正对照：同一份配置下普通页面照样被采样并落库（没有它，这条在「什么都没跑」时也绿）
        $middleware->handle($this->subdirectoryRequest('/sub/node/1'));
        $this->latestRun($cache);
    }

    /**
     * 资源前缀的口径必须与 Core\StaticController 同一个：**守卫认的路径 = serve() 认的路径**。
     *
     * 这里此前只覆盖默认前缀，而那正是缺陷的藏身处：守卫镜像了 Core 的私有常量
     * '/xhprof-assets'，Core 改成读 `xhprof.assets_url` 之后两处分叉 —— 配了自定义前缀时
     * 守卫认不出资源请求（照常采样），serve() 那边也不服务它们，两边一起错所以两边都不报。
     *
     * 判据一次问两票（守卫是否跳过采样、serve() 是否给出文件），不一致就红：只看任何一边
     * 都能被「两边一起错」骗过。数据组与 Core\StaticControllerTest::assetsUrlProvider 同一批
     * 边界（默认/自定义/尾斜杠/CDN 绝对 URL/空串/'/'）。
     *
     * @return iterable<string, array{?string, string, bool}>
     */
    public static function assetsUrlGuardProvider(): iterable
    {
        yield '没有配置' => [null, '/xhprof-assets/css/xhprof.css', true];
        yield '配置 = 默认值' => ['/xhprof-assets', '/xhprof-assets/css/xhprof.css', true];
        yield '配置带尾斜杠' => ['/xhprof-assets/', '/xhprof-assets/css/xhprof.css', true];
        yield '自定义前缀' => ['/static/xhprof', '/static/xhprof/css/xhprof.css', true];
        yield '自定义前缀 + 尾斜杠' => ['/static/xhprof/', '/static/xhprof/css/xhprof.css', true];
        // 这两格是判别力来源：配了自定义前缀后**老路径不再被认**（否则同一份文件有两个 URL）。
        // Drupal 资源路由写死在 xhprof.routing.yml 里不跟配置走，所以「老路径 + 自定义前缀」
        // 正是这种部署下资源请求的真实形态：采样跳过与资源服务必须一起失效，不能只失效一半。
        yield '自定义前缀 + 老路径' => ['/static/xhprof', '/xhprof-assets/css/xhprof.css', false];
        yield 'CDN 绝对 URL' => ['https://cdn.test/xhprof-assets', '/xhprof-assets/css/xhprof.css', false];
        yield '配置成空串' => ['', '/xhprof-assets/css/xhprof.css', false];
        yield '配置成 /' => ['/', '/css/xhprof.css', true];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('assetsUrlGuardProvider')]
    public function assetsGuardAndServeAgreeOnWhichPathsAreAssets(?string $assetsUrl, string $path, bool $isAsset): void
    {
        $config = ['enable' => true, 'ignore_url_arr' => []];
        if ($assetsUrl !== null) {
            $config['assets_url'] = $assetsUrl;
        }
        $cache = new FakeCache();
        $middleware = $this->middleware($this->kernel(), $cache, $config);

        // 第 1 票：中间件守卫（跳过采样 ⇔ 认作报告页/资源）
        $middleware->handle($this->subdirectoryRequest('/sub' . $path));

        // 第 2 票：Core 自己的判定（serve() 给出包内文件 ⇔ 认作资源）
        $served = StaticController::serve(
            new RoutedPathRequestAdapter($this->subdirectoryRequest($path)),
            new ResponseAdapter(new Response())
        )->send();
        $isServed = $served instanceof BinaryFileResponse
            && $served->getFile()->getPathname() === $this->cssFile();

        $this->assertSame($isAsset, $isServed, "serve() 对 $path 的判定（前缀 " . var_export($assetsUrl, true) . '）');

        if ($isAsset) {
            $this->assertSame([], $this->runs($cache), "认作资源的 $path 不得被采样落库");
        } else {
            $this->latestRun($cache);   // 非资源请求照常采样并落库
        }
    }

    /** @return iterable<string, array{?string, string, bool}> */
    public static function customAssetsPrefixProvider(): iterable
    {
        yield '默认前缀（无配置）：仍交给模块路由' => [null, '/xhprof-assets/css/xhprof.css', false];
        yield '默认前缀（显式）：同上' => ['/xhprof-assets', '/xhprof-assets/css/xhprof.css', false];
        yield '自定义前缀：中间件自己服务' => ['/static/xhprof', '/static/xhprof/css/xhprof.css', true];
        yield '自定义前缀 + 尾斜杠：同上' => ['/static/xhprof/', '/static/xhprof/css/xhprof.css', true];
        yield '自定义前缀 + 老路径：模块路由那条，中间件不接管' => ['/static/xhprof', '/xhprof-assets/css/xhprof.css', false];
        yield '空串（不启用）：不接管' => ['', '/xhprof-assets/css/xhprof.css', false];
        yield '自定义前缀 + 同名兄弟路径：不接管' => ['/static/xhprof', '/static/xhprof-nope/x.js', false];
    }

    /**
     * 自定义 `assets_url` 前缀下的资源由**中间件**服务。
     *
     * 模块的资源路由 path 写死在 `xhprof.routing.yml`（`/xhprof-assets/{file}`），匹配不到
     * 别的前缀 —— 不接管的话报告页会静默丢样式与脚本。默认前缀**不接管**：仍走模块路由 +
     * Controller，保留 Drupal「用路由器提供报告页/资源」的既有设计（那两处不是死代码）。
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('customAssetsPrefixProvider')]
    public function customAssetsPrefixIsServedByTheMiddleware(?string $assetsUrl, string $path, bool $servedByMiddleware): void
    {
        $config = ['enable' => true, 'ignore_url_arr' => []];
        if ($assetsUrl !== null) {
            $config['assets_url'] = $assetsUrl;
        }

        $kernel = $this->kernel();
        $response = $this->middleware($kernel, new FakeCache(), $config)->handle($this->subdirectoryRequest($path));

        if ($servedByMiddleware) {
            $this->assertInstanceOf(BinaryFileResponse::class, $response, '资源必须由中间件真读出来，而不是空响应');
            $this->assertSame($this->cssFile(), $response->getFile()->getPathname());
            $this->assertSame([], $kernel->calls, '接管了就不该再落到内层 kernel');
            return;
        }

        $this->assertSame([HttpKernelInterface::MAIN_REQUEST], $kernel->calls, '不接管时必须原样透传');
        $this->assertSame('business', $response->getContent());
    }

    #[Test]
    public function subRequestAloneSamplesNothing(): void
    {
        $cache = new FakeCache();
        $host = new FakeRequest(['a' => 1], ['uri' => '/page', 'host' => 'example.com']);
        CoreXhprof::$request = $host;

        $kernel = $this->kernel();
        $response = $this->middleware($kernel, $cache)->handle(Request::create('/_fragment'), HttpKernelInterface::SUB_REQUEST);

        $this->assertSame([HttpKernelInterface::SUB_REQUEST], $kernel->calls, '子请求仍须透传给内层 kernel');
        $this->assertSame('business', $response->getContent());
        $this->assertNull(xhprof_disable(), '子请求不应启动采样');
        $this->assertSame($host, CoreXhprof::getRequest(), '子请求不应碰宿主的适配器');
        $this->assertSame([], $cache->calls);
    }

    // ---------- 控制器：报告页 ----------

    #[Test]
    public function stubDoesNotComputeCacheControlDefaults(): void
    {
        // 前提守卫，不是产品断言：下面两条 no-cache 断言的**全部**判别力来自「桩不计算默认值」。
        // 真实 ResponseHeaderBag::__construct() 对从未设过 Cache-Control 的响应会自己填上
        // 'no-cache, private'（ResponseHeaderBag.php:35-37 → :121 → :243-252），而显式设成同一个
        // 字面量后终态**逐字节相同**（HeaderBag::set() 先从字面量解析出 cacheControl，
        // 于是 computeCacheControlValue() 原样返回它）—— 真包上断这个字面量恒真、无判别力。
        // 桩的 HeaderBag::get() 对缺键返回 null，所以这里判别得出来。
        // 谁若给桩补上那段计算逻辑（"让桩更忠实"），这条会先红：那时上面两条必须改成判别式
        // （如 Last-Modified 扰动），不能继续直接断字面量。
        $this->assertNull((new Response())->headers->get('Cache-Control'));
    }

    #[Test]
    public function reportRouteRendersHtmlWithNoCacheHeader(): void
    {
        $cache = new FakeCache();
        $controller = new XhprofController();
        $kernel = $this->kernel(static fn (): Response => $controller->report());
        $response = $this->middleware($kernel, $cache)->handle(Request::create('/xhprof'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('XHProf', (string) $response->getContent());
        // 报告是即时数据，且访问 URL 可能带 ?token=xxx，不能让页面缓存留副本
        // 判别力依赖桩不实现 cache-control 计算（真包会把没设过的响应也算成同一个字面量）：
        // 见 stubDoesNotComputeCacheControlDefaults —— 那条红了就必须把本断言改成扰动式判别
        // （真包上：设了 Last-Modified 时计算值变 'private, must-revalidate'，显式值纹丝不动）。
        $this->assertSame('no-cache, private', $response->headers->get('Cache-Control'));
        // Symfony 7.4 的 ResponseHeaderBag 不再自带 Content-Type，只有 prepare() 会补，
        // 而 prepare() 会先看 request format —— 报告页的 MIME 不能取决于那一步的推断。
        // 断言的是**未 prepare** 的对象：不显式设就是 null，所以这条有判别力。
        $this->assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
        // 报告页自身不落库（默认 ignore_url_arr 命中 /xhprof）
        $this->assertSame([], $this->runs($cache));
    }

    #[Test]
    public function reportRouteEnforcesAuthToken(): void
    {
        $controller = new XhprofController();
        $kernel = $this->kernel(static fn (): Response => $controller->report());
        $middleware = $this->middleware($kernel, new FakeCache(), ['enable' => true, 'auth_token' => 'secret']);

        $denied = $middleware->handle(Request::create('/xhprof'));
        $this->assertSame(403, $denied->getStatusCode());
        $this->assertSame('403 Forbidden', $denied->getContent());

        $allowed = $middleware->handle(Request::create('/xhprof?token=secret'));
        $this->assertSame(200, $allowed->getStatusCode());
        $this->assertStringContainsString('XHProf', (string) $allowed->getContent());
    }

    #[Test]
    public function reportRouteRejectsArrayRunIdWith400(): void
    {
        // 适配器返回数组而不是抛 BadRequestException，才轮到 Xhprof::index()
        // 用 is_string() 判成我们自己的 400；这条同时盯着适配器和控制器
        $controller = new XhprofController();
        $kernel = $this->kernel(static fn (): Response => $controller->report());
        $response = $this->middleware($kernel, new FakeCache())->handle(Request::create('/xhprof?run[]=a'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('400 Bad Request', $response->getContent());
    }

    #[Test]
    public function reportRouteRejectsInvalidRunIdWith400(): void
    {
        $controller = new XhprofController();
        $kernel = $this->kernel(static fn (): Response => $controller->report());
        $response = $this->middleware($kernel, new FakeCache())->handle(Request::create('/xhprof?run=../../etc/passwd'));

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function reportRouteWorksWithoutMiddleware(): void
    {
        // 中间件没跑（单测直接实例化控制器 / 别的入口调用）时的兜底：
        // 路由必须永远有响应，而不是在 null 上调用方法变成 500。
        // 刻意不动 $response：Xhprof::getResponse() 为 null 时走控制器里的 new Response() 分支。
        CoreXhprof::$request = new FakeRequest();
        CoreXhprof::$cache = new FakeCache();
        $this->assertNull(CoreXhprof::getResponse(), '前置条件：没有 ResponseAdapter');

        $response = (new XhprofController())->report();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('XHProf', (string) $response->getContent());
        // 同前一条：有效性与上面的 stubDoesNotComputeCacheControlDefaults 绑在一起
        $this->assertSame('no-cache, private', $response->headers->get('Cache-Control'));
        // 兜底分支（new Response($body, 200, $headers)）也必须带上 Content-Type —— 两条分支
        // 共用同一个 $headers，这条断言盯的是它没有被复制粘贴漏掉
        $this->assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
    }

    // ---------- 控制器：静态资源 ----------

    #[Test]
    public function assetsRouteServesBundledCss(): void
    {
        $response = (new XhprofController())->assets(Request::create('/xhprof-assets/css/xhprof.css'));

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame($this->cssFile(), $response->getFile()->getPathname(), '必须落在包内 src/html 下');
        $this->assertStringContainsString('max-age=86400', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('text/css', $response->headers->get('Content-Type'), 'Drupal 报告页的样式表必须带对 MIME');
    }

    #[Test]
    public function assetsRouteServesBundledCssWhenDrupalLivesInASubdirectory(): void
    {
        // 路由是在**剥掉 base path 的 pathInfo** 上匹配的（RequestContext::fromRequest()），
        // 所以资源能路由到本控制器；但 StaticController 是拿 uri() 反推资源相对路径的。
        // 若这里交给它的是原始请求 URI（带 /sub），反推前缀对不上 → 空 body 的 200：
        // 报告页没样式没 JS，且**不报任何错**（README 只记了守卫失配那一半，这半边更重）。
        $request = $this->subdirectoryRequest('/sub/xhprof-assets/css/xhprof.css');

        // 前提：两种路径确实不同（桩与真包在同一语料上逐字比对过）
        $this->assertSame('/sub/xhprof-assets/css/xhprof.css', $request->getRequestUri(), '原始 URI 带 base path');
        $this->assertSame('/xhprof-assets/css/xhprof.css', $request->getPathInfo(), '路由匹配用的路径剥掉了它');

        $response = (new XhprofController())->assets($request);

        $this->assertInstanceOf(BinaryFileResponse::class, $response, '子目录安装下也必须命中包内资源');
        $this->assertSame($this->cssFile(), $response->getFile()->getPathname());
        $this->assertSame('text/css', $response->headers->get('Content-Type'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badAssetUris(): array
    {
        return [
            'path traversal' => ['/xhprof-assets/../composer.json'],
            'missing file' => ['/xhprof-assets/css/nope.css'],
            'outside prefix' => ['/something-else/css/xhprof.css'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('badAssetUris')]
    public function assetsRouteMissesReturnEmptyBody(string $uri): void
    {
        // 未命中时 StaticController 给的是空 body + 保持框架默认状态码（200），
        // 与另外五个框架的短路行为逐字一致（见 SlimTest::middlewareAssetsPathTraversalIsRejected）。
        // 这里不返回 404：六框架同语义优先于「单看 Drupal 更该 404」。
        $response = (new XhprofController())->assets(Request::create($uri));
        $this->assertNotInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame('', $response->getContent());
        $this->assertSame(200, $response->getStatusCode());
    }
}
