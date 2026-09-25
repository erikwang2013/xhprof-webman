<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;
use ErikWang2013\Xhprof\Core\RedisAdapterTrait;
use ErikWang2013\Xhprof\Core\StaticController as CoreStaticController;
use ErikWang2013\Xhprof\Core\Xhprof as CoreXhprof;
use ErikWang2013\Xhprof\Native\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Native\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Native\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Native\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Native\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Native\XhprofBootstrap;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger;
use ErikWang2013\Xhprof\Tests\Fixtures\FileCache;

/**
 * 原生 PHP（无框架）入口：适配器 + 报告页/资源短路 + 采样落库。
 *
 * 与另外十家的对照用例同一组口径（报告页短路、默认前缀资源、自定义前缀资源、
 * 近似路径仍是业务请求、guard 与 serve 同源的两票制边界表）。
 *
 * **为什么这两条路径的用例都在子进程里**：报告页/资源分支的实现里带 `exit`
 * （照 WordPress 先例，不 exit 的话入口文件后面的应用代码会叠在报告页后面），
 * 进程内既观测不到「已经终止」，也拿不到 exit 之前的输出。靠 shutdown 探针把
 * 退出前才可观测的状态带出来——这与 WordpressTest 的 runRequest() 同一手法。
 *
 * **「有没有落库」不看探针，看进程退出后的存储文件**：入口类自己的止点也是挂在
 * `register_shutdown_function` 上的，而 shutdown 回调按**注册顺序**跑——探针先注册
 * （必须在 `start()` 之前，因为短路路径上 `start()` 不返回），所以入口的回调在探针
 * 之后才跑，探针里读不到「exit 之后才写进去的」那条 run。这不是推测：把短路挪到
 * `xhprofStart()` 之后，探针版断言全绿（判别力为零），换成 FileCache + 父进程在
 * `proc_close()` 后读文件才红。所以子进程用 FileCache，父进程读总账。
 *
 * 强依赖：`send()` 会 echo，所有进程内调用都包在 ob_start() 里——phpunit.xml 设了
 * failOnRisky=true，裸输出会被判 risky 而失败。
 */
class NativeTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server = [];

    /** @var array<string, mixed> */
    private array $get = [];

    /** @var array<string, mixed> */
    private array $post = [];

    /** @var array<string, mixed> */
    private array $cookie = [];

    /** @var array<string, mixed> Xhprof 静态属性快照 */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->get = $_GET;
        $this->post = $_POST;
        $this->cookie = $_COOKIE;
        $_SERVER = [
            'REQUEST_URI' => '/index.php?p=1',
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'example.com',
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $this->saved = $this->snapshotXhprofStatics();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET = $this->get;
        $_POST = $this->post;
        $_COOKIE = $this->cookie;
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

    /** 读私有属性：响应头 / 状态码在 CLI 下没有别的可观测出口。 */
    private function prop(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }

    /** send() 会 echo：必须 ob_start() 包住，否则 failOnRisky 直接判失败。 */
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

    // ---------- RequestAdapter ----------

    #[Test]
    public function requestAdapterReadsGetThenPost(): void
    {
        $_GET = ['a' => '1'];
        $_POST = ['b' => '2', 'a' => 'post-wins-not'];
        $req = new RequestAdapter();

        $this->assertSame('1', $req->get('a'), 'GET 优先于 POST');
        $this->assertSame('2', $req->get('b'), 'GET 里没有时回退到 POST');
        $this->assertSame('dflt', $req->get('missing', 'dflt'));
        $this->assertNull($req->get('missing'));
        $this->assertSame(['a' => '1', 'b' => '2'], $req->all(), '`+` 合并：GET 赢且键序不变');
    }

    /**
     * Cookie **不是** auth_token 的来源。
     *
     * 报告页的 token 判定在 Xhprof::index()：`$cfg->get('xhprof.auth_token')` 与
     * `$req->get('token')` 比对。适配器只读 $_GET/$_POST，$_COOKIE 一个都不进
     * ——否则「配了 auth_token」的站点，任何一个能写 Cookie 的域都能读到报告页
     * （WordPress 适配器对 $_REQUEST 是同一条理由）。
     */
    #[Test]
    public function cookieIsNotAnAuthTokenSource(): void
    {
        $_GET = [];
        $_POST = [];
        $_COOKIE = ['token' => 'sekrit'];
        $req = new RequestAdapter();

        $this->assertNull($req->get('token'), 'Cookie 里的 token 不能被读到');
        $this->assertSame([], $req->all(), 'all() 里也不能出现 Cookie 键');
    }

    #[Test]
    public function requestAdapterMethodAndDefaults(): void
    {
        $this->assertSame('GET', (new RequestAdapter())->method());
        $_SERVER['REQUEST_METHOD'] = 'post';
        $this->assertSame('POST', (new RequestAdapter())->method());
        unset($_SERVER['REQUEST_METHOD']);
        $this->assertSame('GET', (new RequestAdapter())->method(), 'CLI/内部请求没有 REQUEST_METHOD 时不能空串');
    }

    /**
     * 头表的两条来源都要覆盖：`getallheaders()`（apache/fpm/cli-server 有）与
     * `$_SERVER` 还原（**纯 CLI 没有 getallheaders**，实测 function_exists === false）。
     *
     * 本用例跑在 CLI 上，走的正是第二条；第一条用一个假的 getallheaders 验证不了
     * （PHP 不允许在命名空间外重定义内置函数），所以这里只钉第二条 + 头名归一化。
     * 真实 SAPI 那条在 reportPageAssetsAndBusinessOverRealHttp（php -S）里会被跑到，
     * 但两条分支在 php -S 上等价（实测同值，见那条用例的注释），所以没有任何断言能
     * 「二选一」地钉住 getallheaders() 分支——能钉的只是「客户端头 → 落库 → 列表页」这条链。
     */
    #[Test]
    public function requestAdapterHeaderMapping(): void
    {
        $this->assertFalse(
            function_exists('getallheaders'),
            '本用例的前提：CLI 下没有 getallheaders()，因此走的是 $_SERVER 还原那条'
        );

        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $req = new RequestAdapter();

        // 大小写与连字符都要能对上；XHProfRunsDefault 每个被采样请求都会读一次这个小写名。
        $this->assertSame('https', $req->header('x-forwarded-proto'));
        $this->assertSame('https', $req->header('X-Forwarded-Proto'));
        $this->assertSame('application/json', $req->header('Content-Type'), 'PHP 不为这两个头加 HTTP_ 前缀');
        $this->assertNull($req->header('X-Absent'), 'R-3：缺省必须是 null 而不是空串');
        $this->assertNull($req->header(''));
    }

    #[Test]
    public function requestAdapterHostStripsPort(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com:8080';
        $this->assertSame('example.com', (new RequestAdapter())->host(), 'R-2：host 不含端口');

        $_SERVER['HTTP_HOST'] = '[::1]:8080';
        $this->assertSame('[::1]', (new RequestAdapter())->host());

        unset($_SERVER['HTTP_HOST']);
        $_SERVER['SERVER_NAME'] = 'fallback.test';
        $this->assertSame('fallback.test', (new RequestAdapter())->host());

        unset($_SERVER['SERVER_NAME']);
        $this->assertSame('', (new RequestAdapter())->host(), 'R-3：无值也不能返回 null（声明是 : string）');
    }

    #[Test]
    public function requestAdapterUriIsPathAndQueryOnly(): void
    {
        // R-1：host 叫 xhprof.* 的站点，uri() 若带上 host 会让 isIgnore() 每个请求都误判。
        $_SERVER['HTTP_HOST'] = 'xhprof.example.com';
        $_SERVER['REQUEST_URI'] = '/admin?run=abc';
        $req = new RequestAdapter();

        $this->assertSame('/admin?run=abc', $req->uri());
        $this->assertStringNotContainsString('://', $req->uri());
        $this->assertStringNotContainsString('xhprof.example.com', $req->uri());
        $this->assertSame('http://xhprof.example.com/admin?run=abc', $req->url(), 'url() 才是绝对地址');

        // 纯 CLI（没有 REQUEST_URI）时是空串而不是 null：声明是 : string，且
        // XhprofLib::isIgnore() 对空 uri() 的判定是「不落库」（该方法的第二个 return）。
        unset($_SERVER['REQUEST_URI']);
        $this->assertSame('', (new RequestAdapter())->uri());
    }

    #[Test]
    public function requestAdapterUrlSchemeFollowsHttpsAndPort(): void
    {
        $_SERVER['REQUEST_URI'] = '/x';
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT']);
        $this->assertSame('http://example.com/x', (new RequestAdapter())->url());

        $_SERVER['HTTPS'] = 'on';
        $this->assertSame('https://example.com/x', (new RequestAdapter())->url());

        // 反代以外的常规部署：HTTPS 没设但端口是 443
        unset($_SERVER['HTTPS']);
        $_SERVER['SERVER_PORT'] = '443';
        $this->assertSame('https://example.com/x', (new RequestAdapter())->url());

        // 与 WP 的 is_ssl() 同口径：HTTPS 一旦被设置（哪怕值是 'off'）就不再走端口那条分支
        $_SERVER['HTTPS'] = 'off';
        $this->assertSame('http://example.com/x', (new RequestAdapter())->url());

        // 刻意不看 X-Forwarded-Proto：反代终止 TLS 的部署要在前段把 HTTPS 配好
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertSame('http://example.com/x', (new RequestAdapter())->url());
    }

    #[Test]
    public function requestAdapterRealIp(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9, 10.0.0.1';
        $this->assertSame('203.0.113.9', (new RequestAdapter())->getRealIp(), '取 XFF 第一段（客户端）');

        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        $_SERVER['HTTP_X_REAL_IP'] = '198.51.100.7';
        $this->assertSame('198.51.100.7', (new RequestAdapter())->getRealIp());

        unset($_SERVER['HTTP_X_REAL_IP'], $_SERVER['REMOTE_ADDR']);
        $this->assertSame('127.0.0.1', (new RequestAdapter())->getRealIp(), 'R-3：不得返回 null');
    }

    // ---------- ResponseAdapter ----------

    #[Test]
    public function responseAdapterWithMethodsAreChainableAndSendEchoes(): void
    {
        $res = new ResponseAdapter();
        // R-4：Xhprof::deny() 的调用链是 withStatus()->withBody()->send()
        $this->assertSame($res, $res->withStatus(403));
        $this->assertSame($res, $res->withBody('x'));
        $this->assertSame($res, $res->withHeaders(['A' => '1']));

        $out = $this->capture(static function () use ($res): void {
            $ret = $res->withBody('hello')->send();
            if ($ret !== null) {
                echo 'RET:' . var_export($ret, true);
            }
        });
        $this->assertSame('hello', $out, 'send() 返回非 null 会被调用方再输出一遍（403 被 200 覆盖）');

        $empty = $this->capture(static fn () => (new ResponseAdapter())->send());
        $this->assertSame('', $empty);
    }

    #[Test]
    public function responseAdapterFileSetsBodyAndContentType(): void
    {
        $path = CoreStaticController::getAssetsPath() . '/css/xhprof.css';
        $res = new ResponseAdapter();
        $res->file($path);

        $headers = $this->prop($res, 'headers');
        $this->assertSame('text/css', $headers['Content-Type']);
        $this->assertSame(200, $this->prop($res, 'status'));
        $this->assertSame(file_get_contents($path), $this->prop($res, 'body'));
    }

    #[Test]
    public function responseAdapterMissingFileBecomes404(): void
    {
        $res = new ResponseAdapter();
        $res->file('/nonexistent/xhprof.css');

        $this->assertSame(404, $this->prop($res, 'status'));
        $this->assertSame('', $this->prop($res, 'body'));
    }

    #[Test]
    public function responseAdapterHeadersSurviveFile(): void
    {
        // R-5：StaticController::serve() 的调用是 file($realFile)->withHeaders([...])，
        // 头若存在「会被 file() 覆盖」的那个字段里，Cache-Control 就丢了。
        $path = CoreStaticController::getAssetsPath() . '/css/xhprof.css';
        $res = new ResponseAdapter();
        $res->file($path)->withHeaders(['Cache-Control' => 'public, max-age=86400']);

        $headers = $this->prop($res, 'headers');
        $this->assertSame('public, max-age=86400', $headers['Cache-Control']);
        $this->assertSame('text/css', $headers['Content-Type'], 'file() 推出的 MIME 不能被 withHeaders 抹掉');

        $out = $this->capture(static fn () => $res->send());
        $this->assertSame(file_get_contents($path), $out, 'body 是文件内容');
    }

    // ---------- ConfigAdapter ----------

    #[Test]
    public function configAdapterServesWholeBlockAndLeaf(): void
    {
        $cfg = new ConfigAdapter();

        // R-6：两处调用点分别要整块与叶子
        $whole = $cfg->get('xhprof');
        $this->assertInstanceOf(ConfigInterface::class, $cfg);
        $this->assertIsArray($whole, 'XhprofProfiler::bootstrap() 用的是整块');
        $this->assertSame('/xhprof-assets', $cfg->get('xhprof.assets_url'), 'Xhprof::index() 用的是叶子');
        $this->assertSame($whole['assets_url'], $cfg->get('xhprof.assets_url'));
        $this->assertSame('missing', $cfg->get('xhprof.nope', 'missing'));
        $this->assertNull($cfg->get('nope.at.all'));
    }

    #[Test]
    public function configAdapterReplacesListsWholesale(): void
    {
        // R-7：合并必须是 array_replace（整体替换）。两个探针缺一不可——空列表区分
        // array_replace_recursive，第二次实例化区分 require_once 的返回值陷阱。
        $first = new ConfigAdapter(['ignore_url_arr' => ['/admin'], 'log_num' => 7]);

        $this->assertSame(['/admin'], $first->get('xhprof.ignore_url_arr'));
        $this->assertSame(7, $first->get('xhprof.log_num'));
        $this->assertSame(86400 * 7, $first->get('xhprof.log_ttl'), '未覆盖的键保留默认值');

        $second = new ConfigAdapter(['ignore_url_arr' => []]);

        $this->assertSame([], $second->get('xhprof.ignore_url_arr'), '显式清空就是清空，不能残留默认项');
        $this->assertSame('/xhprof-assets', $second->get('xhprof.assets_url'), '第二次实例化仍要读到默认值');
        $this->assertSame(86400 * 7, $second->get('xhprof.log_ttl'), '第二次实例化仍要读到默认值');
    }

    #[Test]
    public function configAdapterShipsAllDocumentedKeys(): void
    {
        $cfg = new ConfigAdapter();
        $keys = array_keys((array) $cfg->get('xhprof'));
        sort($keys);

        // 与 README「配置项说明」表一致（ConfigParityTest 会跨十一家比对 key 集与默认值）
        $this->assertSame([
            'assets_url', 'auth_token', 'enable', 'ignore_url_arr',
            'key_prefix', 'locale', 'log_num', 'log_ttl', 'time_limit', 'view_wtred',
        ], $keys);
        $this->assertNull($cfg->get('xhprof.auth_token'), '默认不鉴权');
    }

    // ---------- LogAdapter / RedisAdapter ----------

    #[Test]
    public function logAdapterWritesToPhpErrorLog(): void
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'xhprof-log-');
        $prev = ini_get('error_log');
        ini_set('error_log', $tmp);
        try {
            (new LogAdapter())->error('xhprof boom', ['k' => 'v']);
        } finally {
            ini_set('error_log', $prev === false ? '' : $prev);
        }
        $written = (string) file_get_contents($tmp);
        unlink($tmp);

        $this->assertStringContainsString('xhprof boom', $written);
        $this->assertStringContainsString('"k":"v"', $written);
    }

    #[Test]
    public function redisAdapterUsesTraitAndStaysLazy(): void
    {
        $adapter = new RedisAdapter();
        $this->assertInstanceOf(CacheInterface::class, $adapter);
        // R-9：lPush 的返回值、mget([]) 的短路、set() 的 ttl 退化等语义全在 trait 里，
        // 自己重写一遍就会漂移；class_uses 是唯一能证明「用的是那份实现」的断言
        $this->assertContains(RedisAdapterTrait::class, class_uses($adapter));

        // 懒连接：入口类每个请求都会 new 一个（XhprofBootstrap::__construct），构造时建连
        // 就是每个请求一次握手——即使采样是关的，或这个请求根本走不到落库。
        $read = \Closure::bind(static function (RedisAdapter $a): mixed {
            return $a->client;
        }, null, RedisAdapter::class);
        $this->assertIsCallable($read);
        $this->assertNull($read($adapter), '构造时不得建连');
    }

    /** 连接参数与 Yii3 的直连适配器同名同默认（另十家里只有它也是这种「选项数组」形态）；用户配置只覆盖给出的那几个键。 */
    #[Test]
    public function redisAdapterReadsConnectionOptionsFromConfig(): void
    {
        $read = static function (RedisAdapter $a): array {
            $prop = new \ReflectionProperty($a, 'options');

            return (array) $prop->getValue($a);
        };

        $this->assertSame([
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
            'database' => 0,
            'timeout' => 1.0,
        ], $read(new RedisAdapter()));

        $this->assertSame([
            'host' => 'redis.internal',
            'port' => 6380,
            'password' => 'sekrit',
            'database' => 3,
            'timeout' => 1.0,
        ], $read(new RedisAdapter(['host' => 'redis.internal', 'port' => 6380, 'password' => 'sekrit', 'database' => 3])));
    }

    /**
     * 真 ext-redis 走一遍（与 WordpressTest 的同名用例同一条理由）：旧实现的 redis() 返回
     * 字符串类名时，trait 的 `call_user_func([$this->redis(), 'get'], …)` 是对实例方法做静态
     * 调用，PHP 直接抛 TypeError，而异常被 XhprofProfiler::stop() 吞掉 → 报告页永远没有数据。
     *
     * 本机/CI 没有 Redis 服务端时允许 RedisException：那证明调用方式已合法（旧实现连这一步都到不了）。
     */
    #[Test]
    public function redisAdapterReallyCallsPhpRedis(): void
    {
        $adapter = new RedisAdapter();
        $key = 'xhprof:native-probe:' . bin2hex(random_bytes(4));

        try {
            $this->assertFalse($adapter->get($key), '不存在的键（phpredis 返回 false）');
            $this->assertTrue($adapter->set($key, 'v', 60), 'phpredis 的 set() 返回 bool');
            $this->assertSame('v', $adapter->get($key), '写进去的值要能读回来');
            $this->assertSame(['v'], $adapter->mget([$key]));
        } catch (\TypeError $e) {
            $this->fail('调用方式非法（redis() 没给出实例）：' . $e->getMessage());
        } catch (\RedisException $e) {
            $this->assertStringNotContainsString('call_user_func', $e->getMessage());
            $this->addToAssertionCount(1);
        } finally {
            try {
                $adapter->del($key);
            } catch (\Throwable $e) {
                // 没连上就没东西可清
            }
        }
    }

    // ---------- 接线：起采样 / 幂等止点 ----------

    /**
     * 一次采样只落库一次：显式 stop() 与进程 shutdown 回调会竞争（后者由 start() 注册）。
     *
     * 没有幂等守卫时，第二次 xhprof_disable() 返回空数据，而 save_run() 不会因此提前返回
     * ——照样 lPush 一个 run_id、照样写 xhprof_log（内容是 `serialize(null) === 'N;'`，
     * 非空字符串，!empty 判真）→ 报告列表里凭空多一条没有数据的 run。
     */
    #[Test]
    public function entryStopsSamplingOnlyOncePerRequest(): void
    {
        $cache = new FakeCache();
        $entry = XhprofBootstrap::start(
            ['enable' => true, 'ignore_url_arr' => ['/never-matches']],
            $cache,
            new FakeLogger()
        );

        $entry->stop();
        $entry->stop();

        $saved = array_values(array_filter(
            $cache->calls,
            static fn (string $call): bool => str_starts_with($call, 'set:xhprof:xhprof_log:')
        ));
        $this->assertCount(1, $saved, '两次 stop() 只允许落库一次：' . implode(',', $cache->calls));
        $this->assertContains('lPush:xhprof:run_id', $cache->calls);

        $rid = substr($saved[0], strlen('set:xhprof:xhprof_log:'));
        $data = unserialize((string) $cache->get('xhprof:xhprof_log:' . $rid));
        $this->assertIsArray($data);
        $this->assertArrayHasKey('main()', $data, '采样数据缺少 main() 帧 → 报告页会是空的');
    }

    // ---------- 报告页 / 资源短路：不需要应用注册任何路由 ----------

    /**
     * 报告页：入口类在业务代码之前短路并 exit，应用自己的输出不会叠在后面。
     *
     * `ignore_url_arr` 必须挪开默认的 `['/xhprof']`：XhprofLib::isIgnore() 是 **子串** 匹配，
     * URI 里含 '/xhprof' 就整个不落库，于是「报告页没被采样」这条断言会被默认配置**顺手**
     * 满足——把短路挪到 xhprofStart() 之后也照样绿（判别力为零）。挪开之后，落不落库只由
     * 「有没有跑过 xhprofStart/xhprofStop」决定，这条断言才真的能红。
     */
    #[Test]
    public function reportPageIsServedWithoutAnyUserRoute(): void
    {
        $run = $this->runRequest('/xhprof?run=abc', ['enable' => true, 'ignore_url_arr' => ['/never-matches']]);

        $this->assertSame(0, $run['code'], $run['stderr']);
        $probe = $this->probe($run['stderr']);

        $this->assertFalse($probe['reached_end'], '报告页必须在应用代码之前 exit');
        $this->assertStringNotContainsString('BUSINESS', $run['stdout']);
        $this->assertStringStartsWith('<html lang="zh-CN">', $run['stdout']);
        $this->assertStringEndsWith('</html>', rtrim($run['stdout']));
        $this->assertStringContainsString('/xhprof-assets', $run['stdout'], '报告页资源链接走默认前缀');
        $this->assertSame(200, $probe['status'], 'http_response_code() 在 CLI 下读得到（实测）');

        // 报告页本身要读 run 列表（list_runs 的 lRange + mget）才渲染得出来，但绝不能写。
        $this->assertContains('lRange:xhprof:run_id', $probe['cache_calls'], '报告页没读 run 列表 → 渲染出来的不是列表页');
        $this->assertSame([], $run['runs'], '报告页不该落库');
        $this->assertNull($run['saved_log']);
    }

    #[Test]
    public function reportPageDeniesWhenTokenIsWrong(): void
    {
        $run = $this->runRequest('/xhprof', ['enable' => true, 'auth_token' => 'sekrit', 'ignore_url_arr' => ['/never-matches']], ['token' => 'wrong']);
        $probe = $this->probe($run['stderr']);

        $this->assertSame('403 Forbidden', $run['stdout'], '鉴权失败：只有 403 正文，不能再补发 200');
        $this->assertFalse($probe['reached_end']);
        $this->assertSame(403, $probe['status'], 'HTTP 状态码必须是 403');
        $this->assertSame([], $run['runs'], '鉴权失败的报告页也不该被采样落库');
    }

    /** 默认前缀（配置里不写 assets_url）下的资源请求：入口类接管并真读出包内文件。 */
    #[Test]
    public function assetRequestIsServedUnderTheDefaultPrefix(): void
    {
        $run = $this->runRequest('/xhprof-assets/css/xhprof.css', ['enable' => true, 'ignore_url_arr' => ['/never-matches']]);
        $probe = $this->probe($run['stderr']);

        $this->assertFalse($probe['reached_end'], '资源请求必须在应用代码之前 exit');
        $this->assertStringNotContainsString('BUSINESS', $run['stdout']);
        $this->assertSame(
            file_get_contents(CoreStaticController::getAssetsPath() . '/css/xhprof.css'),
            $run['stdout'],
            '必须真的读到包内 css（逐字节）'
        );
        $this->assertSame(200, $probe['status']);
        $this->assertSame([], $probe['cache_calls'], '静态资源不该碰缓存（读也不读）');
        $this->assertSame([], $run['runs'], '静态资源不该被采样落库');
    }

    /**
     * 自定义前缀：改配置就够了，**不需要**动入口文件之外的东西（原生应用本来也没有路由层，
     * 这条路径完全由入口类按 `assets_url` 接管）。
     */
    #[Test]
    public function assetRequestIsServedUnderACustomPrefixWithoutTouchingRoutes(): void
    {
        $run = $this->runRequest('/static/xhprof/css/xhprof.css', [
            'enable' => true,
            'assets_url' => '/static/xhprof',
            'ignore_url_arr' => ['/never-matches'],
        ]);
        $probe = $this->probe($run['stderr']);

        $this->assertFalse($probe['reached_end']);
        $this->assertSame(
            file_get_contents(CoreStaticController::getAssetsPath() . '/css/xhprof.css'),
            $run['stdout']
        );
        $this->assertSame(200, $probe['status']);
        $this->assertSame([], $probe['cache_calls']);
        $this->assertSame([], $run['runs'], '静态资源不该被采样落库');
    }

    /**
     * 近似路径不被接管，且照常当业务请求采样落库。
     *
     * 同样要挪开 `ignore_url_arr`：`/xhprof-assets-nope` 含子串 '/xhprof'，默认配置下
     * 「没落库」是 isIgnore() 给的，不是短路给的——那份断言同样会被顺手满足。
     */
    #[Test]
    public function nearMissAssetPathIsStillABusinessRequest(): void
    {
        $run = $this->runRequest('/xhprof-assets-nope', ['enable' => true, 'ignore_url_arr' => ['/never-matches']]);
        $probe = $this->probe($run['stderr']);

        $this->assertTrue($probe['reached_end'], '前缀必须带斜杠：/xhprof-assets-nope 是业务路径');
        $this->assertSame('BUSINESS', $run['stdout']);

        // 断言「落库的是本次采样数据」而不是只断言键存在（后者把 stop() 改成 save_run([])
        // 也照样绿，照 WiringTest::assertRunSaved 的口径）。
        $this->assertCount(1, $run['runs'], '普通业务请求照常采样落库');
        $data = unserialize((string) $run['saved_log']);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('main()', $data);
    }

    /**
     * 与另十家同一组边界：入口类的短路判定与 Core 的 serve() 判定必须同源。
     *
     * 分叉的后果不是报错而是**静默**：报告页 CSS/JS 全空、业务代码也拿不到那些路径。
     * 单跑 serve() 或单跑入口都看不出来，只有同一条路径问两次才成立。
     *
     * 第 1 票必须开子进程：判据是「入口有没有 exit」，进程内看不到终止这件事。
     */
    public static function assetsUrlBoundaries(): iterable
    {
        yield '没有配置' => [null, '/xhprof-assets/css/xhprof.css', true];
        yield '配置 = 默认值' => ['/xhprof-assets', '/xhprof-assets/css/xhprof.css', true];
        yield '配置带尾斜杠' => ['/xhprof-assets/', '/xhprof-assets/css/xhprof.css', true];
        yield '自定义前缀' => ['/static/xhprof', '/static/xhprof/css/xhprof.css', true];
        yield '自定义前缀 + 尾斜杠' => ['/static/xhprof/', '/static/xhprof/css/xhprof.css', true];
        // 判别力来源：配了自定义前缀后**老路径不再被认**（否则同一份文件有两个 URL）
        yield '自定义前缀 + 老路径' => ['/static/xhprof', '/xhprof-assets/css/xhprof.css', false];
        yield 'CDN 绝对 URL' => ['https://cdn.test/xhprof-assets', '/xhprof-assets/css/xhprof.css', false];
        // 空串 = 不启用资源短路（两边都一个都不认，不能一边回落成默认前缀）
        yield '配置成空串' => ['', '/xhprof-assets/css/xhprof.css', false];
        yield '配置成 /' => ['/', '/css/xhprof.css', true];
        // 尾斜杠是「近似路径不算资源」的唯一来源
        yield '近似路径' => [null, '/xhprof-assets-nope', false];
        yield '近似路径（自定义前缀）' => ['/static/xhprof', '/static/xhprof-nope', false];
    }

    #[Test]
    #[DataProvider('assetsUrlBoundaries')]
    public function guardAndServeAgreeOnWhichPathsAreAssets(?string $assetsUrl, string $path, bool $isAsset): void
    {
        $config = ['enable' => true];
        if ($assetsUrl !== null) {
            $config['assets_url'] = $assetsUrl;
        }

        // 第 1 票：入口类短路 —— 应用代码没被跑到 ⇔ 认作资源
        $run = $this->runRequest($path, $config);
        $probe = $this->probe($run['stderr']);
        $this->assertSame(
            $isAsset,
            !$probe['reached_end'],
            "入口类对 {$path} 的判定（assets_url = " . var_export($assetsUrl, true) . '）'
        );
        $this->assertSame(
            $isAsset,
            str_contains($run['stdout'], '--xp-bg: #f6f8fa'),
            "入口类短路后送出的必须是包内 css（{$path}）"
        );

        // 第 2 票：Core 自己的判定 —— 真读出包内 css ⇔ 认作资源。放在第 1 票之后，
        // 因为 serve() 读的是 bootstrap 写进去的那份配置。
        // Native 的 RequestAdapter 没有构造参数（读的是超全局），所以路径要放回 $_SERVER
        // ——这正是「原生」的含义，也是这条用例与另十家唯一的差别（那边是 new Request(['uri'=>$path])）。
        $_SERVER['REQUEST_URI'] = $path;
        CoreXhprof::bootstrap(
            new RequestAdapter(),
            new ResponseAdapter(),
            new ConfigAdapter($config),
            new FakeCache(),
            new FakeLogger()
        );
        $body = $this->capture(static function () use ($path): void {
            CoreStaticController::serve(new RequestAdapter(), new ResponseAdapter())->send();
        });
        $this->assertSame(
            $isAsset,
            str_contains($body, '--xp-bg: #f6f8fa'),
            "Core serve() 对 {$path} 的判定（assets_url = " . var_export($assetsUrl, true) . '）'
        );
    }

    // ---------- 真实 HTTP：唯一能观测「实际发出的头/状态行」的方式 ----------

    /**
     * `php -S` + 一个最小前端控制器，跑通整条链：
     * 业务请求（真起采样、真落库）→ 报告列表页 200（看得见那条 run）→
     * 报告详情页 200（读得到采样数据）→ 资源 200（逐字节）→ 鉴权失败 403。
     *
     * 为什么必须真实 HTTP：CLI 下 `header()` 只被记录、不发出去，`headers_list()` 恒空
     * （实测），状态行更是没有——Content-Type / Cache-Control / 状态码这三样只有真的
     * 走一次 SAPI 才算验证过（与 WordpressTest::reportPageAndAssetsOverRealHttp 同一理由）。
     *
     * 存储用 FileCache（文件版 FakeCache）：`php -S` 每个请求都是独立的脚本执行，
     * 内存版在下个请求里什么都不剩，而这条链要的正是「这个请求写的，那个请求读得到」。
     * 不拿真 Redis 顶上：CI 的单测 job 没有 redis 服务（真连必红），而且这里要验证的是
     * 「原生入口接得上 Core 的落库/读取路径」，不是 phpredis 本身。
     */
    #[Test]
    public function reportPageAssetsAndBusinessOverRealHttp(): void
    {
        $root = dirname(__DIR__, 3);
        $port = $this->freePort();
        $store = (string) tempnam(sys_get_temp_dir(), 'xhprof-native-store-');
        $router = (string) tempnam(sys_get_temp_dir(), 'xhprof-native-router-') . '.php';
        file_put_contents($router, '<?php
require ' . var_export($root . '/vendor/autoload.php', true) . ';
require ' . var_export($root . '/tests/Fixtures/Fakes.php', true) . ';

$cache = new \ErikWang2013\Xhprof\Tests\Fixtures\FileCache(' . var_export($store, true) . ');
\ErikWang2013\Xhprof\Native\XhprofBootstrap::start(
    [
        \'enable\' => true,
        \'auth_token\' => \'sekrit\',
        // 默认 ignore_url_arr 是子串匹配的 [\'/xhprof\']，会把「有没有落库」这条断言顺手满足
        \'ignore_url_arr\' => [\'/never-matches\'],
    ],
    $cache,
    new \ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger()
);

echo \'BUSINESS\';
');

        $proc = proc_open(
            [
                PHP_BINARY,
                '-d', 'display_errors=stderr',
                '-d', 'error_reporting=E_ALL',
                // 这两行是**断言的强度所在**，别当噪音删掉：SAPI 在脚本没发 Content-Type 时
                // 会拿 default_mimetype/default_charset 顶上，而它们的默认值恰好就是
                // `text/html; charset=UTF-8`——正好是报告页该发的值。不改默认值的话，
                // 「报告页忘了发 Content-Type」也会得到一模一样的响应头，断言就是空转的。
                '-d', 'default_mimetype=text/plain',
                '-d', 'default_charset=ISO-8859-1',
                '-S', "127.0.0.1:{$port}",
                $router,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($proc, 'php -S 起不来');

        try {
            $this->waitForServer($port);

            // 1) 业务请求：起采样 → 应用输出 → 请求结束时止点落库。
            //    特意带一个 X-Forwarded-For：原生适配器的 getRealIp() 优先取它，于是这条断言
            //    把「客户端请求头 → 适配器 → 落库的 request_log → 渲染出的列表页」串成一条链。
            //    **但它分辨不出读到头上的是哪个来源**：本机实测 php -S 下 `$_SERVER['HTTP_X_FORWARDED_FOR']`
            //    与 `getallheaders()['X-Forwarded-For']` 同值（都是 203.0.113.7），
            //    allHeaders() 的两条分支在这个 SAPI 上等价。两者的差异只出现在「PHP 不把某个头
            //    填进 $_SERVER」的 SAPI 上（经典例子是 Apache + Authorization，本包不用这个头），
            //    这里测不到，也不该假装测到了。
            $business = $this->httpGet($port, '/business?probe=1', ['X-Forwarded-For: 203.0.113.7']);
            $this->assertSame('HTTP/1.1 200 OK', $business['headers'][0]);
            $this->assertSame('BUSINESS', $business['body'], '业务请求不该被入口吞掉');

            // 2) 报告列表页：上一条请求落的那条 run 必须在这里看得见（php -S 单进程串行，
            //    上一个请求的 shutdown 一定已经跑完，不存在读到一半的竞态）
            $list = $this->httpGet($port, '/xhprof?token=sekrit');
            $this->assertSame('HTTP/1.1 200 OK', $list['headers'][0]);
            $this->assertHeader($list['headers'], 'Content-Type', 'text/html; charset=UTF-8');
            $this->assertHeader($list['headers'], 'Cache-Control', 'no-cache, private');
            $this->assertStringStartsWith('<html lang="zh-CN">', $list['body']);
            $this->assertStringContainsString('127.0.0.1/business?probe=1', $list['body'], '列表里应有上一条业务请求的记录');
            $this->assertStringContainsString('203.0.113.7', $list['body'], 'IP 列应来自客户端发来的 X-Forwarded-For（请求头 → getRealIp → request_log → 列表页 整条链）');
            $this->assertStringNotContainsString('BUSINESS', $list['body'], '报告页后面不该叠业务输出（exit 的判据）');

            // 3) 鉴权失败：状态行只有真实 HTTP 才看得到
            $denied = $this->httpGet($port, '/xhprof?token=wrong');
            $this->assertSame('HTTP/1.1 403 Forbidden', $denied['headers'][0]);
            $this->assertSame('403 Forbidden', $denied['body'], '鉴权失败：只有 403 正文，不能再补发 200');

            // 4) 报告详情页：读得到这次采样的数据（xhprof_log 走的是原生适配器 + FileCache）。
            //    照列表页自己给出的 href 点进去，而不是手拼参数：`source` 少一个，
            //    XHProfRunsDefault::get_run() 的 xhprof_valid_source(null) 直接返回 false，
            //    页面显示「性能数据已不存在」——看着像落库失败，其实是请求少了参数
            //    （已实测踩过一次）。链接里同时带着 token，顺带钉住鉴权参数被拼进了导航。
            $cache = new FileCache($store);
            $runIds = $cache->lRange('xhprof:run_id', 0, -1);
            $this->assertCount(1, $runIds, '业务请求应当恰好落库一条 run');
            $rid = preg_quote((string) $runIds[0], '#');
            $this->assertMatchesRegularExpression('#href="[^"]*run=' . $rid . '[^"]*"[^>]*>' . preg_quote('127.0.0.1/business?probe=1', '#') . '#', $list['body'], '列表页里该有指向本次 run 的链接');
            preg_match('#href="(/xhprof\?[^"]*run=' . $rid . '[^"]*)"#', $list['body'], $m);
            $this->assertArrayHasKey(1, $m, '从列表页里取不出详情链接');
            $detail = $this->httpGet($port, html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $this->assertSame('HTTP/1.1 200 OK', $detail['headers'][0]);
            $this->assertStringContainsString('main()', $detail['body'], '详情页要读得到采样数据');

            // 5) 静态资源：`file()->withHeaders()` 攒下的头必须真的发出去了
            $asset = $this->httpGet($port, '/xhprof-assets/css/xhprof.css');
            $this->assertSame('HTTP/1.1 200 OK', $asset['headers'][0]);
            $this->assertHeader($asset['headers'], 'Content-Type', 'text/css');
            $this->assertHeader($asset['headers'], 'Cache-Control', 'public, max-age=86400');
            $this->assertSame(
                file_get_contents(CoreStaticController::getAssetsPath() . '/css/xhprof.css'),
                $asset['body'],
                '资源内容必须逐字节送达'
            );
        } finally {
            proc_terminate($proc);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($proc);
            unlink($router);
            unlink($store);
        }
    }

    /**
     * 断言响应头里存在某个头、且它的值包含给定子串（头名大小写不敏感）。
     *
     * 不能拿 `assertContains('Content-Type: text/css', $headers)` 硬比整串：`php -S` 会把
     * 头名规范成 `Content-type`，并给 text/* 的 Content-Type 追加 `;charset=UTF-8`。
     *
     * @param list<string> $headers
     */
    private function assertHeader(array $headers, string $name, string $needle): void
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') !== 0) {
                continue;
            }
            $this->assertStringContainsStringIgnoringCase($needle, $header, "响应头「{$header}」里没有「{$needle}」");

            return;
        }

        $this->fail("响应头里没有 {$name}：" . implode(' | ', $headers));
    }

    /** 借内核分配一个空闲端口（绑 :0 拿号后立刻释放），避免写死端口在 CI 上撞车。 */
    private function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($sock, "拿不到空闲端口：{$errstr}");

        $name = (string) stream_socket_get_name($sock, false);
        fclose($sock);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /** 等内置服务器开始监听（最多 3 秒），不去猜固定 sleep 时长。 */
    private function waitForServer(int $port): void
    {
        for ($i = 0; $i < 60; $i++) {
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if (is_resource($sock)) {
                fclose($sock);

                return;
            }
            usleep(50_000);
        }

        $this->fail("php -S 三秒内没起来（127.0.0.1:{$port}）");
    }

    /**
     * 发一次真实 GET，返回正文与**实际收到的响应头**。
     *
     * `ignore_errors` 是必须的：PHP 的 http 流包装器把 4xx/5xx 当**失败**，默认让
     * file_get_contents 返回 false（但 `$http_response_header` 照样有值）。本用例偏偏
     * 要断言那条 403 的状态行，不开这个开关就会拿到「请求失败」而不是响应——已实测踩过一次
     * （curl 同一条路径是正常的 HTTP/1.1 403 Forbidden）。
     *
     * `$http_response_header` 由流包装器在调用作用域里创建，必须在同一作用域里取。
     *
     * @return array{body:string, headers:list<string>}
     */
    private function httpGet(int $port, string $path, array $headers = []): array
    {
        $options = ['ignore_errors' => true, 'timeout' => 5];
        if ($headers !== []) {
            $options['header'] = implode("\r\n", $headers);
        }
        $context = stream_context_create(['http' => $options]);
        $body = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
        $this->assertNotFalse($body, "HTTP 请求失败：{$path}");

        $headers = $http_response_header ?? [];
        $this->assertNotEmpty($headers, "没拿到响应头：{$path}");

        return ['body' => $body, 'headers' => $headers];
    }

    // ---------- 子进程驱动 ----------

    /**
     * 在独立子进程里跑一次完整请求（入口只给一行，其余全部走适配器默认值）。
     *
     * 落库的观测点是**文件**（FileCache + 父进程在子进程退出后读），不是 shutdown 探针：
     * 见类注释——探针比入口自己注册的 stop 回调先跑，看不见 exit 之后才发生的写入。
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $get
     *
     * @return array{code:int, stdout:string, stderr:string, runs:list<string>, saved_log:?string}
     */
    private function runRequest(string $uri, array $config, array $get = []): array
    {
        $root = dirname(__DIR__, 3);
        $store = (string) tempnam(sys_get_temp_dir(), 'xhprof-native-store-');
        $code = <<<'PHP'
require $argv[1] . '/vendor/autoload.php';
require $argv[1] . '/tests/Fixtures/Fakes.php';

use ErikWang2013\Xhprof\Native\XhprofBootstrap;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger;
use ErikWang2013\Xhprof\Tests\Fixtures\FileCache;

$s = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
$_SERVER = [
    'REQUEST_URI' => $s['uri'],
    'REQUEST_METHOD' => 'GET',
    'HTTP_HOST' => 'example.com:8080',
    'REMOTE_ADDR' => '198.51.100.4',
];
$_GET = $s['get'];
$_POST = [];

$cache = new FileCache($argv[3]);
$state = ['reached_end' => false];

// exit 与正常结束都会走到这里——「脚本跑到哪儿了」只能这样观测。
register_shutdown_function(static function () use ($cache, &$state): void {
    fwrite(STDERR, "\n__PROBE__" . json_encode([
        'reached_end' => $state['reached_end'],
        'cache_calls' => $cache->calls,
        // CLI 下 http_response_code() 读得到（实测 int(403)），但 header() 只被记录、
        // headers_list() 恒空——头只能在真实 HTTP 上看。
        'status' => http_response_code(),
    ]) . "\n");
});

$entry = XhprofBootstrap::start($s['config'], $cache, new FakeLogger());
echo 'BUSINESS';
$state['reached_end'] = true;

// 显式停表（= 进程结束时的 shutdown 回调做的事，见 register_shutdown_function 那条）
$entry->stop();
PHP;

        $result = $this->runCode($code, [
            $root,
            json_encode(['uri' => $uri, 'config' => $config, 'get' => $get], JSON_THROW_ON_ERROR),
            $store,
        ]);

        // 子进程已完全退出（shutdown 回调都跑完了）才读存储：写没写、写了几条，这里是总账。
        $cache = new FileCache($store);
        $runs = $cache->lRange('xhprof:run_id', 0, -1);
        $result['runs'] = $runs;
        $result['saved_log'] = $runs === [] ? null : $cache->get('xhprof:xhprof_log:' . $runs[0]);
        unlink($store);

        return $result;
    }

    /** `php -r` 子进程的公共部分：argv 数组（不经 shell）、stderr 收警告、收齐输出与退出码。 */
    private function runCode(string $code, array $args): array
    {
        $cmd = array_merge(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL', '-r', $code],
            $args
        );
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'proc_open 失败');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * 从子进程 stderr 里取出 shutdown 探针。
     *
     * @return array{reached_end:bool, cache_calls:list<string>, status:int}
     */
    private function probe(string $stderr): array
    {
        $pos = strpos($stderr, '__PROBE__');
        $this->assertNotFalse($pos, "子进程没有输出探针（stderr）：{$stderr}");

        $json = substr($stderr, (int) $pos + strlen('__PROBE__'));
        $decoded = json_decode(trim($json), true);
        $this->assertIsArray($decoded, "探针 JSON 解析失败：{$json}");

        return $decoded;
    }
}
