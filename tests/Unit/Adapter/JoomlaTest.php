<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\Xhprof as CoreXhprof;
use ErikWang2013\Xhprof\Joomla\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Joomla\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Joomla\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Joomla\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Joomla\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Joomla\Extension\Xhprof;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeConfig;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeRequest;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeResponse;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\JoomlaFakeApplication;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\JoomlaNoopDispatcher;
use Joomla\CMS\Log\Log;
use Joomla\Event\Priority;
use Joomla\Event\SubscriberInterface;
use Joomla\Input\Input;

/**
 * Joomla 适配器与入口类。桩在 tests/Stubs/Framework/Joomla.php。
 *
 * 单测里 `Joomla\Input\Input` / `Registry` / `UriHelper` / `Event\*` 走的是桩，
 * 同一批语义由 tools/contracts/cases/Joomla.php 用**真实** joomla/input|registry|uri|event
 * 再验一遍（含真 Dispatcher 的 addSubscriber 语义）。`Joomla\CMS\*` 没有可安装的真包，
 * 只有桩，整块在环里是 SKIP 项——所以这里的「报告页只输出、不碰组件」类断言，
 * 证明的是**本包代码在给定 CMS 契约下的行为**，不是 CMS 本身的行为。
 *
 * 三条守卫各自有一条「去掉就变红」的用例：
 *  1. 二次停止守卫 `Xhprof::$stopped` → secondStopIsHarmless + stopWithoutStartIsNoOp
 *     + shutdownFallbackSavesOnce（真子进程）
 *  2. Input 的 'raw' 过滤器           → requestAdapterRawFilterPreservesSpecialCharacters
 *  3. Registry 的 stdClass 归一化      → configAdapterSupportsBothKeyShapes
 */
class JoomlaTest extends TestCase
{
    /** 站点根（JPATH_ROOT）。常量全进程只能定义一次，故在 setUpBeforeClass 里建。 */
    private static string $root;

    /** @var array<string, mixed> */
    private array $saved = [];

    /** @var array<string, mixed> */
    private array $savedServer = [];

    /** @var array<string, mixed> */
    private array $savedRequest = [];

    /** @var list<string> */
    private array $tempFiles = [];

    /** 不带 REQUEST_METHOD：这样 method() 的缺省分支能在用例里被显式构造出来。 */
    private const BASE_SERVER = [
        'HTTP_HOST' => 'example.com',
        'REQUEST_URI' => '/index.php',
        'REMOTE_ADDR' => '10.0.0.1',
    ];

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/xhprof-joomla-root-' . getmypid();
        if (!is_dir(self::$root)) {
            mkdir(self::$root, 0777, true);
        }
        if (!defined('JPATH_ROOT')) {
            define('JPATH_ROOT', self::$root);
        }
    }

    public static function tearDownAfterClass(): void
    {
        // 站点根里只剩本类自己写的文件（xhprof.php），删干净。
        foreach (glob(self::$root . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir(self::$root)) {
            rmdir(self::$root);
        }
    }

    protected function setUp(): void
    {
        $this->saved = $this->snapshotXhprofStatics();
        $this->savedServer = $_SERVER;
        $this->savedRequest = $_REQUEST;
        Log::reset();
        // 默认不带站点根覆盖：要用的用例自己 writeSiteConfig()。否则上一个用例留下的
        // enable=false / auth_token 会静默改掉下一个用例的前提。
        $this->removeSiteConfig();
    }

    protected function tearDown(): void
    {
        // Xhprof::$stopped 是跨用例存活的静态量。用例可能刻意停在「采样中」（
        // shutdownFallbackSavesOnce 的子进程就是那种路径），这里补一次收尾，
        // 否则下一个用例会被残留状态污染。放在 restore 之前：此刻 xhprof 是本次
        // 用例注入的 FakeCache，落库不会碰真 Redis。
        Xhprof::stopSampling();

        $this->restoreXhprofStatics($this->saved);
        $_SERVER = $this->savedServer;
        $_REQUEST = $this->savedRequest;
        Log::reset();
        $this->removeSiteConfig();
        xhprof_disable();
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    // ---------- 辅助 ----------

    /**
     * 摆好超全局并造一个 Input。
     *
     * `$request` 会被同时写进 `$_REQUEST` 与 `new Input($request)`：真实 CMSApplication 的
     * Input 就是 `new Input()`（无参 → 数据取 `$_REQUEST`），两种形态必须等价，
     * 否则这里造出的前提与生产不符。
     *
     * `$_SERVER` 必须在**第一次访问 `$input->server` 之前**摆好：Input 的 `__get('server')`
     * 会把 `$_SERVER` 快照成一个新 Input 并缓存。
     */
    private function input(array $request = [], array $server = []): Input
    {
        $_REQUEST = $request;
        $_SERVER = $server + self::BASE_SERVER;

        return new Input($request);
    }

    private function app(array $request = [], array $server = []): JoomlaFakeApplication
    {
        $this->input($request, $server);

        return new JoomlaFakeApplication($request);
    }

    /**
     * @param ?CacheInterface      $cache        注入的 cache 适配器；传 null 走入口类的默认（RedisAdapter）
     * @param array<string, mixed> $pluginConfig 插件构造参数（`['params' => ...]` 等），本包刻意不从它读配置
     */
    private function plugin(
        JoomlaFakeApplication $app,
        ?CacheInterface $cache = null,
        array $pluginConfig = [],
        ?FakeLogger $logger = null
    ): Xhprof {
        $plugin = new Xhprof(new JoomlaNoopDispatcher(), $pluginConfig, $cache, $logger);
        $plugin->setApplication($app);

        return $plugin;
    }

    /** 包内默认配置（入口类 siteConfig() 之外的另一半来源）。 */
    private function packageConfig(): array
    {
        return require dirname(__DIR__, 3) . '/src/Joomla/config/xhprof.php';
    }

    private static function siteConfigPath(): string
    {
        return self::$root . '/xhprof.php';
    }

    /** 用户在站点根放的覆盖文件（README 里写明的两条覆盖路径之一）。 */
    private function writeSiteConfig(array $config): void
    {
        file_put_contents(
            self::siteConfigPath(),
            "<?php\n\nreturn " . var_export($config, true) . ";\n"
        );
    }

    private function removeSiteConfig(): void
    {
        if (is_file(self::siteConfigPath())) {
            unlink(self::siteConfigPath());
        }
    }

    private function tempFile(string $ext, string $content = 'body{}'): string
    {
        $path = sys_get_temp_dir() . '/xhprof-joomla-' . bin2hex(random_bytes(4)) . '.' . $ext;
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    /** @return array<int, string> */
    private function runs(FakeCache $cache): array
    {
        return $cache->lRange('xhprof:run_id', 0, -1);
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

    // ---------- RequestAdapter ----------

    #[Test]
    public function requestAdapterDelegates(): void
    {
        $adapter = new RequestAdapter($this->input(
            ['x' => '1', 'y' => '2'],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin?x=1']
        ));

        $this->assertInstanceOf(RequestInterface::class, $adapter);
        $this->assertSame('1', $adapter->get('x'));
        $this->assertSame('d', $adapter->get('nope', 'd'));
        $this->assertSame(['x' => '1', 'y' => '2'], $adapter->all());
        $this->assertSame('POST', $adapter->method());
        $this->assertSame('example.com', $adapter->host());
        $this->assertSame('/admin?x=1', $adapter->uri());
        $this->assertSame('http://example.com/admin?x=1', $adapter->url());
        $this->assertNull($adapter->header('X-Nope'));
        $this->assertSame('10.0.0.1', $adapter->getRealIp());
    }

    #[Test]
    public function requestAdapterRawFilterPreservesSpecialCharacters(): void
    {
        // 前提一：Input::get() 的默认过滤器是 'cmd'，它把 [^A-Z0-9_.-] 全删掉。
        // 适配器若省掉第三个参数，auth_token='a+b/c=d e' 会被读成 'abcde'，
        // hash_equals() 永不相等 —— 报告页对任何含特殊字符的 token 恒 403。
        $input = $this->input(['token' => 'a+b/c=d e']);
        $this->assertSame('abcde', $input->get('token'), "前提：Input::get() 默认过滤器是 'cmd'");

        $this->assertSame('a+b/c=d e', (new RequestAdapter($input))->get('token'));
    }

    #[Test]
    public function requestAdapterAllIsConsistentWithGet(): void
    {
        // all() 只用 getArray() 枚举 key，再用同一个过滤器读值：
        // 直接用 getArray() 的返回值会把每个**值**当过滤器名再清洗一遍（XSS 过滤），
        // 于是 all()['k'] 与 get('k') 口径不一致。
        $input = $this->input(['a' => '<b>x</b>', 'b' => ['n' => '<i>p</i>']]);
        $adapter = new RequestAdapter($input);

        $this->assertSame(['a', 'b'], array_keys($adapter->all()));
        $this->assertSame('<b>x</b>', $adapter->all()['a']);
        $this->assertSame($adapter->get('a'), $adapter->all()['a']);
        // getArray() 把每个**值**当过滤器名再清洗一遍（未知过滤器退化成 cleanString）：
        // 直接拿它的返回值当 all() 会让 all()['a'] 与 get('a') 口径不一致。
        $this->assertSame('<b>x</b>', $input->get('a', null, 'raw'));
        $this->assertNotSame($input->getArray(), $adapter->all(), 'getArray() 会把值当过滤器名清洗');
    }

    #[Test]
    public function requestAdapterHeaderReadsHttpPrefixAndFallback(): void
    {
        $adapter = new RequestAdapter($this->input([], [
            'HTTP_X_FOO' => 'bar',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_EMPTY' => '',
        ]));

        $this->assertSame('bar', $adapter->header('X-Foo'));
        $this->assertSame('bar', $adapter->header('x-foo'), '头名大小写不敏感');
        $this->assertSame('application/json', $adapter->header('Content-Type'), 'CGI 里 Content-Type 不带 HTTP_ 前缀');
        // R-3：无值与空串都必须返回 null，绝不返回 '' 或非字符串
        $this->assertNull($adapter->header('X-Empty'));
        $this->assertNull($adapter->header('Nope-At-All'));
    }

    #[Test]
    public function requestAdapterMethodDefaultsToGetWhenServerVarMissing(): void
    {
        // 前提：Input::getMethod() 内部是 strtoupper($this->server->getCmd('REQUEST_METHOD'))，
        // REQUEST_METHOD 缺失时 strtoupper(null) 返回 ''（并触发 deprecation）。
        // 适配器声明 method(): string，'' 会被 Core 当成一个真实的、未知的动词。
        $adapter = new RequestAdapter($this->input());
        $this->assertFalse(array_key_exists('REQUEST_METHOD', $_SERVER), '前提：本用例刻意不带 REQUEST_METHOD');

        $this->assertSame('GET', $adapter->method());
        $this->assertSame('get', strtolower($adapter->method()), '真实有值时会被 strtoupper');
        $this->assertSame('POST', (new RequestAdapter($this->input([], ['REQUEST_METHOD' => 'post'])))->method());
    }

    #[Test]
    public function requestAdapterHostStripsPortAndFallsBack(): void
    {
        // R-2：host() 不含端口
        $this->assertSame('example.com', (new RequestAdapter($this->input([], ['HTTP_HOST' => 'example.com:8080'])))->host());
        $this->assertSame('example.com', (new RequestAdapter($this->input([], ['HTTP_HOST' => '', 'SERVER_NAME' => 'example.com'])))->host());
        $this->assertSame('localhost', (new RequestAdapter($this->input([], ['HTTP_HOST' => '', 'SERVER_NAME' => ''])))->host());

        // Host 头是攻击者可影响的输入：UriHelper::parse_url 会把 path 拆走，
        // 不会把 'evil.test/path' 整串当成 host 带出去。
        $this->assertSame('evil.test', (new RequestAdapter($this->input([], ['HTTP_HOST' => 'evil.test/path'])))->host());
    }

    #[Test]
    public function requestAdapterUriIsPathAndQueryOnly(): void
    {
        // R-1：isIgnore() 对 uri() 做子串匹配，返回绝对 URL 会让 host 叫 xhprof.* 的站点全站被忽略
        foreach ([
            '/admin?x=1' => '/admin?x=1',
            '/index.php' => '/index.php',
            'http://example.com/admin?x=1' => '/admin?x=1',
            '//evil.test/p?q=1' => '/p?q=1',
            '' => '/',
            'http:///x' => '/',
        ] as $requestUri => $expected) {
            $adapter = new RequestAdapter($this->input([], ['REQUEST_URI' => (string) $requestUri]));
            $this->assertSame($expected, $adapter->uri(), "REQUEST_URI=" . var_export($requestUri, true));
            $this->assertStringNotContainsString('://', $adapter->uri());
        }
    }

    #[Test]
    public function requestAdapterGetRealIpIsNeverNull(): void
    {
        // R-3：getRealIp() 声明 : string，任何一条来源缺失都不能漏出 null/''。
        // X-Forwarded-For 取第一段（多级代理串联时的最左者是客户端）。
        $this->assertSame('1.2.3.4', (new RequestAdapter($this->input([], [
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 5.6.7.8',
        ])))->getRealIp());
        $this->assertSame('1.2.3.4', (new RequestAdapter($this->input([], [
            'HTTP_X_REAL_IP' => '1.2.3.4',
        ])))->getRealIp());
        $this->assertSame('10.0.0.1', (new RequestAdapter($this->input()))->getRealIp());
        $this->assertSame('127.0.0.1', (new RequestAdapter($this->input([], ['REMOTE_ADDR' => ''])))->getRealIp());
    }

    #[Test]
    public function requestAdapterSchemeFollowsHttpsConvention(): void
    {
        $this->assertSame('http://example.com/index.php', (new RequestAdapter($this->input()))->url());
        $this->assertSame(
            'https://example.com/index.php',
            (new RequestAdapter($this->input([], ['HTTPS' => 'on'])))->url()
        );
        $this->assertSame(
            'http://example.com/index.php',
            (new RequestAdapter($this->input([], ['HTTPS' => 'off'])))->url(),
            "CGI 约定里 'off' 等于没有"
        );
    }

    // ---------- ResponseAdapter ----------

    #[Test]
    public function responseAdapterChainsAndEchoesWithoutClosing(): void
    {
        // R-4：Xhprof::deny() 的调用链是 withStatus()->withBody()->send()
        $app = $this->app();
        $adapter = new ResponseAdapter($app);

        $this->assertInstanceOf(ResponseInterface::class, $adapter);
        $this->assertSame($adapter, $adapter->withStatus(403));
        $this->assertSame($adapter, $adapter->withBody('403 Forbidden'));
        $this->assertSame($adapter, $adapter->withHeaders(['X-A' => '1']));

        ob_start();
        $returned = $adapter->send();
        $echoed = ob_get_clean();

        $this->assertSame('403 Forbidden', $echoed, 'Joomla 没有可 return 的响应对象，输出只能 echo');
        $this->assertNull($returned, 'deny() 靠 send() 的返回值区分「已发送」，必须返回 null');
        $this->assertSame('1', $app->headerValue('X-A'));
        $this->assertSame('403', $app->headerValue('Status'), '状态码走 Status 头');
        $this->assertSame(1, $app->sendHeadersCalls);
        // close() 只由入口类调一次：send() 自己也 close 的话，报告页短路处就会出现两次终止，
        // 「谁终止请求」不再有唯一答案（真实 close() 是 exit()，多调一次不会有事，但结构上不该有）。
        $this->assertSame(0, $app->closeCalls);
    }

    #[Test]
    public function responseAdapter200DoesNotSetStatusHeader(): void
    {
        $app = $this->app();
        (new ResponseAdapter($app))->withBody('ok')->send();

        $this->assertNull($app->headerValue('Status'), '200 是默认状态，不必显式设头');
    }

    #[Test]
    public function responseAdapterFileThenHeadersStillApplies(): void
    {
        // R-5：StaticController::serve() 的调用是 file($realFile)->withHeaders([...])
        $path = $this->tempFile('css', 'body{}');
        $app = $this->app();
        $adapter = new ResponseAdapter($app);

        $this->assertSame($adapter, $adapter->file($path));
        $this->assertSame($adapter, $adapter->withHeaders(['Cache-Control' => 'public, max-age=86400']));

        ob_start();
        $adapter->send();
        $echoed = ob_get_clean();

        $this->assertSame('body{}', $echoed);
        $this->assertSame('text/css', $app->headerValue('Content-Type'));
        $this->assertSame('public, max-age=86400', $app->headerValue('Cache-Control'), 'file() 之后加的头不能被丢掉');
    }

    #[Test]
    public function responseAdapterFileMissingBecomes404(): void
    {
        // 与其它 9 个框架同形：读不出文件给 404，而不是抛异常
        $app = $this->app();
        (new ResponseAdapter($app))->file($this->tempFile('css') . '.missing')->send();

        $this->assertSame('404', $app->headerValue('Status'));
    }

    // ---------- ConfigAdapter ----------

    #[Test]
    public function configAdapterSupportsBothKeyShapes(): void
    {
        // R-6：XhprofProfiler::bootstrap() 用 get('xhprof') 取整块（然后 $block['ignore_url_arr']），
        // Xhprof::index() 用 get('xhprof.assets_url') 取叶子。
        // Registry 把整块存成 stdClass，对 stdClass 做下标访问是 Error（不是 warning）——
        // 不归一化成数组的话，每个请求都在 bootstrap() 里 500。
        $config = new ConfigAdapter($this->packageConfig());

        $this->assertInstanceOf(ConfigInterface::class, $config);

        $block = $config->get('xhprof');
        $this->assertIsArray($block, 'get(\'xhprof\') 必须是数组：bootstrap() 会直接下标访问');
        $this->assertTrue($block['enable']);
        $this->assertSame(['/xhprof'], $block['ignore_url_arr']);

        $this->assertSame('/xhprof-assets', $config->get('xhprof.assets_url'));
        $this->assertNull($config->get('xhprof.auth_token'));
        $this->assertSame('d', $config->get('xhprof.nope', 'd'));
        $this->assertSame('d', $config->get('nope.nope', 'd'));
    }

    #[Test]
    public function configAdapterUserValuesWinAndMergeIsNotRecursive(): void
    {
        // R-7：必须 array_replace。array_replace_recursive 会把用户写的 [] 与包内列表按下标
        // 合并，包内默认值又冒出来 —— 「用户显式清空」变成静默失配。
        $config = new ConfigAdapter($this->packageConfig(), [
            'enable' => false,
            'auth_token' => 'secret',
            'ignore_url_arr' => [],
            'key_prefix' => 'site1',
        ]);

        $this->assertFalse($config->get('xhprof.enable'));
        $this->assertSame('secret', $config->get('xhprof.auth_token'));
        $this->assertSame([], $config->get('xhprof.ignore_url_arr'));
        $this->assertSame('site1', $config->get('xhprof.key_prefix'));
        $this->assertSame(1000, $config->get('xhprof.log_num'), '没覆盖的键取包内默认值');
        $this->assertSame([], $config->get('xhprof')['ignore_url_arr'], '整块形态也要是用户的值');
    }

    #[Test]
    public function configAdapterWithoutDefaultsIsStillUsable(): void
    {
        // 不传包内配置也不能炸（构造参数都是可选的）
        $config = new ConfigAdapter();
        $this->assertIsArray($config->get('xhprof'));
        $this->assertSame('d', $config->get('xhprof.anything', 'd'));
    }

    // ---------- RedisAdapter / LogAdapter ----------

    #[Test]
    public function redisAdapterWithoutInstanceDoesNotConnectOnConstruct(): void
    {
        // 懒连接：构造时不许建连（把采样数据推到别处的请求不该付连接开销，
        // 而且报告页/静态资源路径一次都不碰 Redis）。
        $this->assertInstanceOf(CacheInterface::class, new RedisAdapter());
    }

    #[Test]
    public function logAdapterForwardsToJoomlaLog(): void
    {
        (new LogAdapter())->error('boom', ['a' => 1]);

        $this->assertSame([[
            'message' => 'boom',
            'priority' => Log::ERROR,
            'category' => 'xhprof',
            'context' => ['a' => 1],
        ]], Log::$entries);
        // 桩的常量表按真实源码抄写（真实 Log 是位掩码 EMERGENCY=1…DEBUG=128，不是 PSR-3 的 0..7）。
        // CMS 不可安装，这里钉的是桩，不是 Joomla —— 但抄错一次就够让 LogAdapter 静默写错级别。
        $this->assertSame(8, Log::ERROR);
    }

    // ---------- 入口类：事件订阅 ----------

    #[Test]
    public function subscribedEventsPinLiteralNamesAndPriorities(): void
    {
        $this->assertTrue(is_subclass_of(Xhprof::class, SubscriberInterface::class));
        $this->assertSame(0, Priority::NORMAL);
        $this->assertSame(-3, Priority::MIN);

        // 事件名是**裸字符串**：Joomla\CMS\Event\Application\ApplicationEvents 在 Joomla
        // 4.4/5.x 里不存在（写成常量会致命错误），而 CMSApplication 派发的就是这两个名字
        // （Dispatcher::addSubscriber() 直接拿本数组的键当事件名）。
        // 真实分发由验证环用真 joomla/event 复核。
        $this->assertSame([
            'onAfterInitialise' => ['onAfterInitialise', Priority::NORMAL],
            'onAfterRespond' => ['onAfterRespond', Priority::MIN],
        ], Xhprof::getSubscribedEvents());
    }

    // ---------- 入口类：接线 ----------

    #[Test]
    public function onAfterInitialiseWiresInjectedAdapters(): void
    {
        $cache = new FakeCache();
        $logger = new FakeLogger();
        $app = $this->app();
        $this->plugin($app, $cache, [], $logger)->onAfterInitialise();

        $this->assertSame($cache, CoreXhprof::getCache());
        $this->assertSame($logger, CoreXhprof::getLogger());
        $this->assertInstanceOf(RequestAdapter::class, CoreXhprof::getRequest());
        $this->assertInstanceOf(ResponseAdapter::class, CoreXhprof::getResponse());
        $this->assertInstanceOf(ConfigAdapter::class, CoreXhprof::getConfig());
    }

    #[Test]
    public function defaultsConstructRedisAdapterWithoutConnecting(): void
    {
        // 两个适配器都不注入（cache=null / logger=null）时走入口类的默认实现
        $this->plugin($this->app())->onAfterInitialise();

        // 默认是 RedisAdapter，但只是构造，不许连（本机没有 Redis 也不该抛）
        $this->assertInstanceOf(RedisAdapter::class, CoreXhprof::getCache());
        $this->assertInstanceOf(LogAdapter::class, CoreXhprof::getLogger());
    }

    // ---------- 入口类：报告页 / 静态资源短路 ----------

    #[Test]
    public function reportPathWithTokenRendersReportAndClosesOnce(): void
    {
        $cache = new FakeCache();
        $app = $this->app(['token' => 'secret'], ['REQUEST_URI' => '/xhprof']);
        $this->writeSiteConfig(['enable' => true, 'auth_token' => 'secret']);

        ob_start();
        $this->plugin($app, $cache)->onAfterInitialise();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('XHProf', $html);
        $this->assertSame('text/html; charset=UTF-8', $app->headerValue('Content-Type'));
        // no-cache：报告是即时数据，且访问 URL 可能带 ?token=xxx，不能让 Joomla 的
        // System - Page Cache 插件（或反代）留副本。字面量与 Drupal 控制器一致。
        // 判别力已实测（删掉源码那行 → 本条红）。裸断字面量安全：Joomla 的 setHeader 只是记录，
        // 不会像 HttpFoundation 那样给没设过的响应计算默认值。
        $this->assertSame('no-cache, private', $app->headerValue('Cache-Control'));
        $this->assertNull($app->headerValue('Status'), '正常渲染不是错误状态');
        $this->assertSame(1, $app->closeCalls, '报告页必须终止请求：不 close 的话组件渲染会叠在报告页后面');
        $this->assertSame([], $this->runs($cache));
        $this->assertNull(xhprof_disable(), '报告页请求不应启动采样');
    }

    #[Test]
    public function reportPathWithoutTokenIsDeniedWith403(): void
    {
        $cache = new FakeCache();
        $app = $this->app([], ['REQUEST_URI' => '/xhprof']);
        $this->writeSiteConfig(['enable' => true, 'auth_token' => 'secret']);

        ob_start();
        $this->plugin($app, $cache)->onAfterInitialise();
        $body = (string) ob_get_clean();

        $this->assertSame('403 Forbidden', $body);
        $this->assertSame('403', $app->headerValue('Status'));
        // 鉴权失败时 index() 自己已经发过一次响应（走 ResponseAdapter::send()），
        // 入口类随后 close 一次 —— 恰好 1 次。send() 也 close 的话这里会是 2。
        $this->assertSame(1, $app->closeCalls);
        $this->assertSame(1, $app->sendHeadersCalls);
        $this->assertSame([], $this->runs($cache));
        $this->assertNull(xhprof_disable());
    }

    #[Test]
    public function reportPathWithMatchingTokenIsNotDenied(): void
    {
        // token 里的特殊字符不会被 Input 的默认过滤器吃掉（auth_token 本身也带特殊字符）
        $app = $this->app(['token' => 'a+b/c=d'], ['REQUEST_URI' => '/xhprof']);
        $this->writeSiteConfig(['enable' => true, 'auth_token' => 'a+b/c=d']);

        ob_start();
        $this->plugin($app, new FakeCache())->onAfterInitialise();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('XHProf', $html);
        $this->assertNull($app->headerValue('Status'));
    }

    #[Test]
    public function reportPathIsMatchedOnPathOnly(): void
    {
        // uri() 是 path+query，报告页判断必须只看 path：/xhprof?run=x 也必须是报告页
        $app = $this->app([], ['REQUEST_URI' => '/xhprof?run=abc']);
        $this->writeSiteConfig(['enable' => true, 'auth_token' => 'secret']);

        ob_start();
        $this->plugin($app, new FakeCache())->onAfterInitialise();
        $body = (string) ob_get_clean();

        $this->assertSame('403 Forbidden', $body, '带 query 的报告页同样走鉴权');
        $this->assertSame(1, $app->closeCalls);
    }

    #[Test]
    public function assetsPathServesBundledFileAndClosesOnce(): void
    {
        $cache = new FakeCache();
        $app = $this->app([], ['REQUEST_URI' => '/xhprof-assets/css/xhprof.css']);

        ob_start();
        $this->plugin($app, $cache)->onAfterInitialise();
        $echoed = (string) ob_get_clean();

        $this->assertNotSame('', $echoed);
        $this->assertStringContainsString('xhprof', strtolower($echoed));
        $this->assertSame('text/css', $app->headerValue('Content-Type'));
        $this->assertSame('public, max-age=86400', $app->headerValue('Cache-Control'));
        $this->assertSame(1, $app->closeCalls, '资源也必须终止请求：不 close 的话组件渲染会拼在资源字节后面');
        $this->assertSame([], $this->runs($cache));
        $this->assertNull(xhprof_disable(), '静态资源请求不应启动采样');
    }

    #[Test]
    public function traversalAttemptInAssetsPathDoesNotReadOutsidePackage(): void
    {
        $app = $this->app([], ['REQUEST_URI' => '/xhprof-assets/../../../etc/passwd']);
        ob_start();
        $this->plugin($app, new FakeCache())->onAfterInitialise();
        $echoed = (string) ob_get_clean();

        $this->assertSame('', $echoed, '目录穿越必须返回空响应');
        $this->assertSame(1, $app->closeCalls);
    }

    // ---------- 入口类：采样 ----------

    #[Test]
    public function disabledConfigDoesNotSampleNorClose(): void
    {
        $cache = new FakeCache();
        $app = $this->app([], ['REQUEST_URI' => '/index.php']);
        $this->writeSiteConfig(['enable' => false]);
        $plugin = $this->plugin($app, $cache);

        ob_start();
        $plugin->onAfterInitialise();
        $echoed = (string) ob_get_clean();
        $plugin->onAfterRespond();

        $this->assertSame('', $echoed, '普通请求不该有任何输出');
        $this->assertSame(0, $app->closeCalls, 'enable=false 不该短路请求');
        $this->assertNull(xhprof_disable(), 'enable=false 不应启动采样');
        $this->assertSame([], $this->runs($cache));
    }

    #[Test]
    public function pluginParamsAreNotAConfigSource(): void
    {
        // 已知取舍（README 里写明的）：配置来自包内 config/xhprof.php + 站点根 xhprof.php，
        // **不读**插件参数（#__extensions.params 要查库，而配置每个请求都读）。
        // 故插件参数里写 enable=false 不生效，站点根 xhprof.php 里的 enable=true 才算数。
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('ext-xhprof 未加载');
        }

        $cache = new FakeCache();
        $app = $this->app([], ['REQUEST_URI' => '/index.php']);
        $this->writeSiteConfig(['enable' => true]);
        $plugin = $this->plugin($app, $cache, ['params' => ['enable' => false]]);

        $plugin->onAfterInitialise();
        $plugin->onAfterRespond();

        $runs = $this->runs($cache);
        $this->assertCount(1, $runs, '插件参数里的 enable=false 不生效（配置不来自 #__extensions.params）');
        $data = unserialize((string) $cache->get('xhprof:xhprof_log:' . $runs[0]));
        $this->assertIsArray($data);
        $this->assertArrayHasKey('main()', $data);
    }

    #[Test]
    public function enabledRequestSamplesAndSavesOnceOnOnAfterRespond(): void
    {
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('ext-xhprof 未加载');
        }

        $cache = new FakeCache();
        $app = $this->app([], ['REQUEST_URI' => '/index.php?x=1']);
        $this->writeSiteConfig(['enable' => true]);
        $plugin = $this->plugin($app, $cache);

        ob_start();
        $plugin->onAfterInitialise();
        $echoed = (string) ob_get_clean();
        $this->assertSame('', $echoed, '普通请求不短路、不输出');
        $this->assertSame(0, $app->closeCalls);

        $plugin->onAfterRespond();

        $runs = $this->runs($cache);
        $this->assertCount(1, $runs);
        $data = unserialize((string) $cache->get('xhprof:xhprof_log:' . $runs[0]));
        $this->assertIsArray($data);
        $this->assertArrayHasKey('main()', $data, '落库的必须是本次真实采样，不是空数据');
    }

    #[Test]
    public function secondStopIsHarmless(): void
    {
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('ext-xhprof 未加载');
        }

        // onAfterRespond 与 shutdown 兜底可能都到达 stopSampling()。没有 $stopped 守卫时，
        // 第二次 xhprof_disable() 返回空数据，而 save_run() 不会因此提前返回：
        // 它照样 lPush 一个 run_id、写入 request_log 与 serialize(null) === 'N;' 的 xhprof_log
        // —— 报告列表里凭空多一条没有 main() 帧的垃圾 run。
        $cache = new FakeCache();
        $app = $this->app([], ['REQUEST_URI' => '/index.php']);
        $this->writeSiteConfig(['enable' => true]);
        $plugin = $this->plugin($app, $cache);

        $plugin->onAfterInitialise();
        $plugin->onAfterRespond();
        $plugin->onAfterRespond();

        $this->assertCount(1, $this->runs($cache), '二次 stop 不能写第二条（空）采样');
    }

    #[Test]
    public function freshProcessStopWithoutStartWritesNothing(): void
    {
        // $stopped 的初值 = 「没有采样在跑」。它只能在**新鲜进程**里观测：进程内跑到这里时
        // 静态量早被别的用例改过了，写成进程内断言会在「初值被改成 false」时照样绿
        // ——那种「覆盖」不算覆盖（实测：把初值改成 false，进程内版本仍然全绿）。
        // 'disabled' 场景不启动采样就调 stopSampling()，观测的正是初值。
        // 这条不 gate ext-xhprof：它断言的是「没启动采样」，与扩展在不在无关。
        $this->assertSame(
            ['runs' => 0, 'hasMain' => false],
            $this->runScenario('disabled'),
            '没启动过采样时 stopSampling() 必须是空操作（否则落一条没有 main() 帧的空 run）'
        );
    }

    #[Test]
    public function ignoreUrlConfigSkipsSampling(): void
    {
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('ext-xhprof 未加载');
        }

        // enable=true 但命中 ignore_url_arr：采样会开，但 save_run 里 isIgnore() 拦下不落库
        $cache = new FakeCache();
        $app = $this->app([], ['REQUEST_URI' => '/admin/users']);
        $this->writeSiteConfig(['enable' => true, 'ignore_url_arr' => ['/admin']]);
        $plugin = $this->plugin($app, $cache);

        $plugin->onAfterInitialise();
        $plugin->onAfterRespond();

        $this->assertSame([], $this->runs($cache));
    }

    // ---------- 入口类：shutdown 兜底 / 静态初值（真子进程，否则观测不到 shutdown 时机与初值） ----------

    /**
     * 跑一个真子进程场景。静态量的**初值**与 shutdown 时机都只在新鲜进程里成立，
     * 进程内断言会被前序用例污染（$stopped 初值改成 false 时进程内版本照样全绿）。
     *
     *  - delivered：采样启动，AFTER_RESPOND 送达 → 1 条，且 shutdown 兜底不重复落库
     *  - lost     ：采样启动，AFTER_RESPOND 不送达 → 只剩 shutdown 兜底，仍须 1 条
     *  - disabled ：enable=false，从未启动采样，随后 stopSampling() → 0 条（观测 $stopped 初值）
     *
     * @return array{runs:int, hasMain:bool}
     */
    private function runScenario(string $scenario): array
    {
        $script = sys_get_temp_dir() . '/xhprof-joomla-shutdown-' . bin2hex(random_bytes(4)) . '.php';
        $this->tempFiles[] = $script;
        file_put_contents($script, <<<'PHP'
<?php

use ErikWang2013\Xhprof\Joomla\Extension\Xhprof;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\JoomlaFakeApplication;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\JoomlaNoopDispatcher;

require $argv[1] . '/vendor/autoload.php';
require $argv[1] . '/tests/Fixtures/Fakes.php';
require $argv[1] . '/tests/Stubs/Framework/Joomla.php';

$scenario = $argv[2];
if (!in_array($scenario, ['delivered', 'lost', 'disabled'], true)) {
    fwrite(STDERR, "unknown scenario: {$scenario}\n");
    exit(2);
}
define('JPATH_ROOT', $argv[3]);

// 'disabled' 场景不启动采样（enable=false），随后显式 stop 一次 —— 观测的是 $stopped 的初值。
$enable = $scenario === 'disabled' ? 'false' : 'true';
file_put_contents($argv[3] . '/xhprof.php', "<?php\n\nreturn ['enable' => {$enable}];\n");

$_SERVER = [
    'HTTP_HOST' => 'example.com',
    'REQUEST_URI' => '/index.php',
    'REQUEST_METHOD' => 'GET',
    'REMOTE_ADDR' => '10.0.0.1',
];
$_REQUEST = [];

$cache = new FakeCache();
$app = new JoomlaFakeApplication([]);
$plugin = new Xhprof(new JoomlaNoopDispatcher(), [], $cache);
$plugin->setApplication($app);

$plugin->onAfterInitialise();

if ($scenario === 'delivered') {
    // 正常路径：AFTER_RESPOND 送达
    $plugin->onAfterRespond();
} elseif ($scenario === 'disabled') {
    // 从未启动采样：此刻 $stopped 还是初值，这次调用必须是空操作
    Xhprof::stopSampling();
}
// 'lost'：响应阶段抛异常 / 进程被终止，AFTER_RESPOND 永不送达 —— 只剩 shutdown 兜底

// 本回调在入口类注册的 stopSampling 之后注册，因此读到的是兜底跑完的状态
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

        $siteRoot = sys_get_temp_dir() . '/xhprof-joomla-subroot-' . bin2hex(random_bytes(4));
        mkdir($siteRoot, 0777, true);
        $siteConfig = $siteRoot . '/xhprof.php';
        $this->tempFiles[] = $siteConfig;

        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL', $script,
                dirname(__DIR__, 3), $scenario, $siteRoot],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($proc, 'proc_open 失败');
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        // 站点根由子进程写入，故在它退出后再收拾
        if (is_file($siteConfig)) {
            unlink($siteConfig);
        }
        @rmdir($siteRoot);

        $decoded = json_decode(trim($stdout), true);
        $this->assertIsArray($decoded, "子进程未输出合法 JSON（exit {$code}）：" . trim($stderr !== '' ? $stderr : $stdout));

        return $decoded;
    }

    #[Test]
    public function shutdownFallbackSavesOnce(): void
    {
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('ext-xhprof 未加载');
        }

        // 正常路径：onAfterRespond 落库一次，随后的 shutdown 兜底必须幂等（不是 2 条）
        $this->assertSame(
            ['runs' => 1, 'hasMain' => true],
            $this->runScenario('delivered'),
            '正常路径应恰好落库一次（shutdown 兜底不得重复落库）'
        );

        // 异常路径：AFTER_RESPOND 不送达，只有 shutdown 兜底能救
        $this->assertSame(
            ['runs' => 1, 'hasMain' => true],
            $this->runScenario('lost'),
            'AFTER_RESPOND 未送达时 shutdown 兜底必须把采样落库'
        );
    }
}
