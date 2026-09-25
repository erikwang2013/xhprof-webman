<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\RedisAdapterTrait;
use ErikWang2013\Xhprof\Core\StaticController;
use ErikWang2013\Xhprof\Core\Xhprof as CoreXhprof;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\WordpressHooks;
use ErikWang2013\Xhprof\Wordpress\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Wordpress\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Wordpress\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Wordpress\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Wordpress\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Wordpress\XhprofPlugin;

/**
 * WordPress 适配器 + 入口类。
 *
 * 报告页 / 静态资源 / 403 三条路径以**独立子进程**验证：它们都会 `exit`（不 exit 的话
 * WordPress 会把整站主题叠在报告页后面），进程内断言不了，而且 `send()` 里的
 * status_header() 也只有在没吐过输出的进程里才会真的被调用（PHPUnit 已经写过 stdout，
 * headers_sent() 恒为 true）。
 *
 * 强依赖：`send()` 会 echo，所有进程内调用都包在 ob_start() 里——phpunit.xml 设了
 * failOnRisky=true，裸输出会被判 risky 而失败。
 */
class WordpressTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server = [];

    /** @var array<string, mixed> */
    private array $get = [];

    /** @var array<string, mixed> */
    private array $post = [];

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->get = $_GET;
        $this->post = $_POST;
        $_SERVER = [
            'REQUEST_URI' => '/index.php?p=1',
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'example.com',
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        $_GET = [];
        $_POST = [];
        WordpressHooks::reset();
        $this->saved = $this->snapshotXhprofStatics();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET = $this->get;
        $_POST = $this->post;
        WordpressHooks::reset();
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

    /** 读私有属性：响应头 / 状态码没有别的可观测出口（CLI 下 header() 是 no-op）。 */
    private function prop(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }

    /** send() 会 echo：必须 ob_start() 包住，否则 phpunit.xml 的 failOnRisky 直接判失败。 */
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
        $this->assertSame(['a' => '1', 'b' => '2'], $req->all());
    }

    #[Test]
    public function requestAdapterUnslashesMagicQuotedInput(): void
    {
        // wp_magic_quotes() 在 wp-settings.php 里给全部超全局加了反斜杠，适配器必须还原。
        $_GET = ['run' => 'ab\\"cd', 'nested' => ['x\\\'y']];
        $_SERVER['REQUEST_URI'] = '/index.php?q=a\\\'b';
        $req = new RequestAdapter();

        $this->assertSame('ab"cd', $req->get('run'));
        $this->assertSame(['x\'y'], $req->get('nested'), '数组要逐元素还原');
        $this->assertSame('/index.php?q=a\'b', $req->uri());
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

    #[Test]
    public function requestAdapterHeaderMapping(): void
    {
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
        $this->assertStringNotContainsString('://', $req->uri());
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
        $path = StaticController::getAssetsPath() . '/css/xhprof.css';
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
        $path = StaticController::getAssetsPath() . '/css/xhprof.css';
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
        $this->assertIsArray($whole, 'XhprofProfiler::bootstrap() 用的是整块');
        $this->assertSame('/xhprof-assets', $cfg->get('xhprof.assets_url'), 'Xhprof::index() 用的是叶子');
        $this->assertSame($whole['assets_url'], $cfg->get('xhprof.assets_url'));
        $this->assertSame('missing', $cfg->get('xhprof.nope', 'missing'));
        $this->assertNull($cfg->get('nope.at.all'));
    }

    #[Test]
    public function configAdapterReplacesListsWholesale(): void
    {
        // R-7：合并必须是 array_replace（整体替换）。两个探针缺一不可：
        //
        // 1) **空列表**——递归合并只在"用户列表比默认短"时露馅：默认 ignore_url_arr 只有
        //    1 个元素，用户给 1 个元素时 array_replace_recursive 按下标覆盖的结果与整体替换
        //    恰好相同（都是 ['/admin']），抓不到；给 0 个元素时才分叉——递归会保留默认值，
        //    整体替换才真正清空。这是"用户显式关掉忽略规则"的真实场景。
        // 2) **第二次实例化**——require_once 第二次返回 true 而不是数组，(array) true 变成
        //    [true]，默认值全丢。只 new 一次测不出来，且不能依赖测试执行顺序。
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

        // 与 README「配置项说明」表一致（Wave 2 的 ConfigParityTest 会跨框架比对 key 集）
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

        // 懒连接：入口类每个请求都会 new 一个（XhprofPlugin:62），构造时建连就是
        // 每个请求一次握手——即使采样是关的。
        $read = \Closure::bind(static function (RedisAdapter $a): mixed {
            return $a->client;
        }, null, RedisAdapter::class);
        $this->assertIsCallable($read);
        $this->assertNull($read($adapter), '构造时不得建连');
    }

    /**
     * 真 ext-redis 走一遍。这是**唯一能抓住本次缺陷**的用例：
     *
     * 旧实现的 redis() 返回字符串 'Redis'，trait 的调用形式是
     * `call_user_func([$this->redis(), 'get'], …)` —— 对 phpredis 的**实例方法**做静态调用，
     * PHP 直接抛 TypeError。Webman/Laravel 之所以没事，是因为那两个返回的是带 __callStatic 的
     * 门面；WordPress 没有门面，于是缓存 100% 失败（异常被 XhprofProfiler::stop() 吞掉，
     * 表现是"请求正常、报告页永远没有数据"）。旧的断言只比类名字符串，从不调用缓存方法，
     * 所以漏掉了它。
     *
     * 「连不上 Redis」与「调用方式非法」必须分开：前者可以是 RedisException（与 Drupal/Symfony
     * 两家直连适配器同形，由 stop() 兜住），后者是本缺陷。
     */
    #[Test]
    public function redisAdapterReallyCallsPhpRedis(): void
    {
        $adapter = new RedisAdapter();
        $key = 'xhprof:probe:' . bin2hex(random_bytes(4));

        try {
            // phpredis 对不存在的键返回 false（不是 null）—— 直连实例的适配器
            // （Drupal/Symfony/Yii3 同族）都是这个语义，而门面（Webman/Laravel）
            // 返回 null。Core 的消费点用 `!is_string($res)` 判空，两种都吃。
            $this->assertFalse($adapter->get($key), '不存在的键');
            $this->assertTrue($adapter->set($key, 'v', 60), 'phpredis 的 set() 返回 bool');
            $this->assertSame('v', $adapter->get($key), '写进去的值要能读回来');
            $this->assertSame(1, $adapter->incr($key . ':n'));
            $this->assertSame(['v'], $adapter->mget([$key]));
        } catch (\TypeError $e) {
            $this->fail('调用方式非法（redis() 没给出实例）：' . $e->getMessage());
        } catch (\RedisException $e) {
            // 本机没有 Redis 服务端：允许。注意旧实现走到这里时**什么都不会抛**
            // （连 TypeError 之前的 call_user_func 校验都过不了），故捕获到 RedisException
            // 本身就证明调用方式已合法。
            $this->assertStringNotContainsString('call_user_func', $e->getMessage());
            $this->addToAssertionCount(1);
        } finally {
            try {
                $adapter->del($key, $key . ':n');
            } catch (\Throwable $e) {
                // 没连上就没东西可清
            }
        }
    }

    // ---------- XhprofPlugin：接线 ----------

    #[Test]
    public function pluginIsConstructibleWithoutArguments(): void
    {
        // mu-plugin 引导文件走的就是无参构造
        $this->assertInstanceOf(XhprofPlugin::class, new XhprofPlugin());
    }

    #[Test]
    public function pluginRegistersPluginsLoadedHook(): void
    {
        $plugin = new XhprofPlugin();
        $this->assertSame(0, WordpressHooks::count('plugins_loaded'));

        $plugin->register();
        $this->assertSame(1, WordpressHooks::count('plugins_loaded'));
        $this->assertSame(0, WordpressHooks::count('shutdown'), '采样开始前不应注册止点');
    }

    #[Test]
    public function pluginRecordsRunWhenEnabled(): void
    {
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $cache = new FakeCache();
        $logger = new FakeLogger();
        $plugin = new XhprofPlugin(['enable' => true], $cache, $logger);
        $plugin->register();

        WordpressHooks::do('plugins_loaded');
        $this->assertSame(1, WordpressHooks::count('shutdown'), '开始采样后必须挂上止点');
        $this->assertSame([], $cache->calls, '此时还没落库');

        WordpressHooks::do('shutdown');
        $rid = $this->assertRunSaved($cache);

        // 落库那一行把 host()/uri()/method()/getRealIp()/header() 全串起来了，
        // 断言它的内容比断言"键存在"强得多。
        $row = json_decode((string) $cache->get("xhprof:request_log:$rid"), true);
        $this->assertSame('https://example.com/index.php?p=1', $row['request_uri']);
        $this->assertSame('GET', $row['method']);
        $this->assertSame('203.0.113.5', $row['ip']);
        $this->assertSame([], $logger->errors);
    }

    #[Test]
    public function pluginSkipsWhenDisabled(): void
    {
        $cache = new FakeCache();
        $plugin = new XhprofPlugin(['enable' => false], $cache, new FakeLogger());
        $plugin->register();

        WordpressHooks::do('plugins_loaded');
        WordpressHooks::do('shutdown');

        $this->assertSame(0, WordpressHooks::count('shutdown'), 'enable=false 时不该挂止点');
        $this->assertSame([], $cache->calls);
    }

    #[Test]
    public function pluginStopsOnlyOncePerRequest(): void
    {
        $cache = new FakeCache();
        $plugin = new XhprofPlugin(['enable' => true], $cache, new FakeLogger());
        $plugin->register();
        WordpressHooks::do('plugins_loaded');

        WordpressHooks::do('shutdown');
        $afterFirst = count($cache->calls);
        $this->assertSame(3, $afterFirst, '一次采样落库是 3 次写：lPush + 两个 set');

        // 别的插件/对象缓存 drop-in 手动 do_action('shutdown') 时，第二次 xhprof_disable()
        // 返回空数据，会被当成一次真实采样写成一条没有 main() 帧的垃圾记录。
        WordpressHooks::do('shutdown');
        $this->assertSame($afterFirst, count($cache->calls));
    }

    #[Test]
    public function pluginStopSurvivesBusinessException(): void
    {
        // WordPress 没有 try/finally：止点挂在 shutdown 动作上，业务代码抛异常时它照样触发
        // （WP 用 register_shutdown_function 注册了该动作；该前提本身需真实 WordPress 确认，
        // 见 README「未自动化验证的」表）。这里验证的是"止点确实只挂在那个动作上"。
        $cache = new FakeCache();
        $plugin = new XhprofPlugin(['enable' => true], $cache, new FakeLogger());
        $plugin->register();
        WordpressHooks::do('plugins_loaded');

        $thrown = null;
        try {
            throw new \RuntimeException('handler boom');
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }
        $this->assertInstanceOf(\RuntimeException::class, $thrown);

        WordpressHooks::do('shutdown');
        $this->assertRunSaved($cache);
    }

    /**
     * 断言一次采样完整落库，返回 run_id。
     *
     * FakeCache 的 store/lists 是私有的，只能靠 calls + get() 回读——但回读到的是真实
     * 采样数据，所以「只断言键存在」那种空转不会发生。
     */
    private function assertRunSaved(FakeCache $cache): string
    {
        $rid = null;
        foreach ($cache->calls as $call) {
            if (preg_match('/^set:xhprof:xhprof_log:([0-9a-f]+)$/', $call, $m) === 1) {
                $rid = $m[1];
            }
        }
        $this->assertNotNull($rid, '没有落库，调用记录：' . implode(',', $cache->calls));

        $data = unserialize((string) $cache->get("xhprof:xhprof_log:$rid"));
        $this->assertIsArray($data);
        $this->assertArrayHasKey('main()', $data, '采样数据缺少 main() 帧 → 报告页会是空的');
        $this->assertContains('lPush:xhprof:run_id', $cache->calls);

        return $rid;
    }

    // ---------- 请求级短路：三条路径都会 exit，只能起子进程 ----------

    #[Test]
    public function reportPageIsServedAndExitsBeforeTheme(): void
    {
        $run = $this->runRequest('/xhprof?sort=wt', ['enable' => true], ['sort' => 'wt']);

        $this->assertSame(0, $run['code'], $run['stderr']);
        $this->assertStringNotContainsString('THEME_RENDERED', $run['stdout'], '没有 exit：主题渲染会叠在报告页后面');
        $this->assertStringStartsWith('<html lang="zh-CN">', $run['stdout']);
        $this->assertStringEndsWith('</html>', rtrim($run['stdout']));
        $this->assertStringContainsString("/xhprof-assets/css/xhprof.css", $run['stdout']);

        $probe = $this->probe($run['stderr']);
        $this->assertSame([['code' => 200, 'description' => '']], $probe['statuses']);
        $this->assertSame(0, $probe['shutdown_hooks'], '报告页不该进入采样');
        // 报告页本身要读 run 列表（lRange/mget），但绝不能写。
        $this->assertSame(
            [],
            $this->writeCalls($probe['cache_calls']),
            '报告页不该写库：' . implode(',', $probe['cache_calls'])
        );
    }

    #[Test]
    public function reportPageDeniesWhenTokenMissing(): void
    {
        $run = $this->runRequest('/xhprof', ['enable' => true, 'auth_token' => 'sekrit'], ['token' => 'wrong']);

        $this->assertSame(0, $run['code'], $run['stderr']);
        $this->assertSame('403 Forbidden', $run['stdout'], '鉴权失败：只有 403 正文，不能再补发 200');
        $this->assertStringNotContainsString('THEME_RENDERED', $run['stdout']);

        $probe = $this->probe($run['stderr']);
        $this->assertSame([['code' => 403, 'description' => '']], $probe['statuses'], 'HTTP 状态码必须是 403');
        $this->assertSame(0, $probe['shutdown_hooks']);
    }

    #[Test]
    public function assetsAreServedFromPackageWithCacheControl(): void
    {
        $asset = StaticController::getAssetsPath() . '/css/xhprof.css';
        $run = $this->runRequest('/xhprof-assets/css/xhprof.css', ['enable' => true], []);

        $this->assertSame(0, $run['code'], $run['stderr']);
        $this->assertSame(file_get_contents($asset), $run['stdout'], '资源内容必须逐字节送达');
        $this->assertStringNotContainsString('THEME_RENDERED', $run['stdout']);

        $probe = $this->probe($run['stderr']);
        $this->assertSame([['code' => 200, 'description' => '']], $probe['statuses']);
        $this->assertSame(0, $probe['shutdown_hooks'], '静态资源不该进入采样');
        $this->assertSame([], $probe['cache_calls']);
    }

    // ---------- 真实 HTTP：唯一能观测「实际发出的头/状态行」的方式 ----------

    #[Test]
    public function reportPageAndAssetsOverRealHttp(): void
    {
        // WordPress 没有框架响应对象：报告页是自己 `header()` + `echo` + `exit`。CLI 下这三种
        // 观测手段都不算证据——`headers_list()` 恒为空；`header()` 在 CLI 只被记录不发送；
        // `xdebug_get_headers()` 连「被 !headers_sent() 拦下、根本没发出去」的头也照报。
        // 所以起一个真的 `php -S`，读回真实的响应头与状态行。
        // 用 FakeCache 注入，全程不碰 Redis（报告页只读列表，绝不写库）。
        $root = dirname(__DIR__, 3);
        $port = $this->freePort();
        $router = (string) tempnam(sys_get_temp_dir(), 'xhprof-router-') . '.php';
        file_put_contents($router, '<?php
require ' . var_export($root . '/vendor/autoload.php', true) . ';
require ' . var_export($root . '/tests/Fixtures/Fakes.php', true) . ';
require ' . var_export($root . '/tests/Stubs/Framework/Wordpress.php', true) . ';

$plugin = new \ErikWang2013\Xhprof\Wordpress\XhprofPlugin(
    [],
    new \ErikWang2013\Xhprof\Tests\Fixtures\FakeCache(),
    new \ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger()
);
$plugin->register();
\ErikWang2013\Xhprof\Tests\Stubs\Framework\WordpressHooks::do(\'plugins_loaded\');

echo \'THEME_RENDERED\';
');

        $proc = proc_open(
            [
                PHP_BINARY,
                '-d', 'display_errors=stderr',
                '-d', 'error_reporting=E_ALL',
                // 这两行是**断言的强度所在**，别当噪音删掉：SAPI 在脚本没发 Content-Type 时会
                // 拿 default_mimetype/default_charset 顶上，而它们的默认值恰好就是
                // `text/html; charset=UTF-8`——正好是报告页该发的值。不改默认值的话，
                // 「报告页忘了发 Content-Type」也会得到一模一样的响应头，断言就是空转的
                // （回退验证实测：去掉 withHeaders 后测试仍然全绿）。改成不可能撞上的值，
                // 断言才真的只可能被我们发出去的头满足。
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

            $report = $this->httpGet($port, '/xhprof?sort=wt');
            $asset = $this->httpGet($port, '/xhprof-assets/css/xhprof.css');
        } finally {
            proc_terminate($proc);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($proc);
            unlink($router);
        }

        // 报告页：状态行、Content-Type、正文边界、以及 exit（否则主题会叠在报告页后面）
        $this->assertSame('HTTP/1.1 200 OK', $report['headers'][0]);
        $this->assertHeader($report['headers'], 'Content-Type', 'text/html; charset=UTF-8');
        // no-cache：报告是即时数据，且访问 URL 可能带 ?token=xxx。WP 的页面缓存插件 /
        // 反代不在本包内，这条头是唯一能告诉缓存层「别存」的信号。字面量与 Drupal 控制器一致。
        // 只能在真实 HTTP 上断言：CLI 下 header() 是 no-op、headers_list() 恒空。
        // 判别力已实测（删掉源码那行 → 本条红）。裸断字面量安全：WP 的 header() 只记录，
        // 不会像 HttpFoundation 那样给没设过的响应计算默认值。
        $this->assertHeader($report['headers'], 'Cache-Control', 'no-cache, private');
        $this->assertStringStartsWith('<html lang="zh-CN">', $report['body']);
        $this->assertStringEndsWith('</html>', rtrim($report['body']));
        $this->assertStringNotContainsString('THEME_RENDERED', $report['body']);

        // 静态资源：`file()->withHeaders()` 攒下的头必须真的发出去了（Cache-Control 由
        // StaticController 传、Content-Type 由 file() 按扩展名推）
        $this->assertSame('HTTP/1.1 200 OK', $asset['headers'][0]);
        $this->assertHeader($asset['headers'], 'Content-Type', 'text/css');
        $this->assertHeader($asset['headers'], 'Cache-Control', 'public, max-age=86400');
        $this->assertSame(
            file_get_contents(StaticController::getAssetsPath() . '/css/xhprof.css'),
            $asset['body'],
            '资源内容必须逐字节送达'
        );
    }

    /**
     * 断言响应头里存在某个头、且它的值包含给定子串（头名大小写不敏感）。
     *
     * 不能拿 `assertContains('Content-Type: text/css', $headers)` 硬比整串：`php -S` 会把
     * 头名规范成 `Content-type`，并给 text/* 的 Content-Type 追加 `;charset=UTF-8`。
     * 那种断言测的是 SAPI 的规范化行为，不是我们发的内容（实测本机拿到的是
     * `Content-type: text/css;charset=UTF-8`）。
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
     * `$http_response_header` 由流包装器在调用作用域里创建，必须在同一作用域里取。
     *
     * @return array{body:string, headers:list<string>}
     */
    private function httpGet(int $port, string $path): array
    {
        $body = @file_get_contents("http://127.0.0.1:{$port}{$path}");
        $this->assertNotFalse($body, "HTTP 请求失败：{$path}");

        $headers = $http_response_header ?? [];
        $this->assertNotEmpty($headers, "没拿到响应头：{$path}");

        return ['body' => $body, 'headers' => $headers];
    }

    // ---------- mu-plugin 引导文件（用户唯一会碰的文件） ----------

    #[Test]
    public function muPluginBootstrapsEntryClassThroughSiteAutoloader(): void
    {
        // 复刻真实安装态：mu-plugin 被 include 时（wp-settings.php:396），站点根的 composer
        // 自动加载器还没有被任何东西 require 过——WP 核心也不会 require 它。引导文件必须自己
        // 兜一次。走 `/xhprof-assets/` 这条短路路径，全程不碰 Redis、不进入采样。
        $root = dirname(__DIR__, 3);
        $code = <<<'PHP'
define('ABSPATH', $argv[1] . '/');
require $argv[1] . '/tests/Stubs/Framework/Wordpress.php';

$_SERVER = ['REQUEST_URI' => '/xhprof-assets/css/xhprof.css', 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com'];
$_GET = [];
$_POST = [];

$class = \ErikWang2013\Xhprof\Wordpress\XhprofPlugin::class;
$before = class_exists($class, false);

register_shutdown_function(static function () use ($class, $before): void {
    fwrite(STDERR, "\n__PROBE__" . json_encode([
        'class_before' => $before,
        'class_after' => class_exists($class, false),
        'plugins_loaded' => \ErikWang2013\Xhprof\Tests\Stubs\Framework\WordpressHooks::count('plugins_loaded'),
        'statuses' => \ErikWang2013\Xhprof\Tests\Stubs\Framework\WordpressHooks::$statuses,
    ]) . "\n");
});

include $argv[1] . '/wordpress/xhprof-webman.php';

// wp-settings.php:506 会触发它——子进程里没有 WordPress，手动补上这一步。
\ErikWang2013\Xhprof\Tests\Stubs\Framework\WordpressHooks::do('plugins_loaded');

echo 'THEME_RENDERED';
PHP;

        $run = $this->runCode($code, [$root]);
        $this->assertSame(0, $run['code'], $run['stderr']);
        $this->assertSame(
            file_get_contents(StaticController::getAssetsPath() . '/css/xhprof.css'),
            $run['stdout'],
            '引导文件没能把资源送出来：' . $run['stderr']
        );
        $this->assertStringNotContainsString('THEME_RENDERED', $run['stdout']);

        $probe = $this->probe($run['stderr']);
        $this->assertFalse($probe['class_before'], '子进程里预先加载过包类的话，就测不到引导文件的兜底 require');
        $this->assertTrue($probe['class_after'], '引导文件没有兜底 require 站点 autoloader');
        $this->assertSame(1, $probe['plugins_loaded'], '引导文件没把入口挂上 plugins_loaded');
        $this->assertSame([['code' => 200, 'description' => '']], $probe['statuses']);
    }

    #[Test]
    public function muPluginExitsSilentlyWhenAbspathMissing(): void
    {
        // 直接访问 wp-content/mu-plugins/xhprof-webman.php：没有 ABSPATH 就该立刻退出，
        // 不能去调 add_action()（WP 没加载时那是致命错误）也不能输出任何东西。
        $root = dirname(__DIR__, 3);
        $code = <<<'PHP'
include $argv[1] . '/wordpress/xhprof-webman.php';
echo 'THEME_RENDERED';
PHP;

        $run = $this->runCode($code, [$root]);
        $this->assertSame(0, $run['code'], $run['stderr']);
        $this->assertSame('', $run['stdout'], '没有 ABSPATH 时必须在动任何东西之前 exit');
        // 本机的 xdebug 每次启动都往 stderr 打两行配置提示，与本文件无关，先滤掉再断言。
        $this->assertSame('', $this->stripEnvNoise($run['stderr']), '不该有告警或致命错误');
    }

    /** 去掉本机环境（xdebug 配置提示）注入 stderr 的噪音，只留子进程自己的输出。 */
    private function stripEnvNoise(string $stderr): string
    {
        $lines = array_filter(
            explode("\n", $stderr),
            static fn (string $line): bool => !str_starts_with($line, 'Xdebug:')
        );

        return trim(implode("\n", $lines));
    }

    /**
     * 在独立子进程里跑一次完整请求（走 mu-plugin 用的那条路：register() → plugins_loaded），
     * 返回 stdout / stderr / 退出码。
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $get
     *
     * @return array{code:int, stdout:string, stderr:string}
     */
    private function runRequest(string $uri, array $config, array $get): array
    {
        $root = dirname(__DIR__, 3);
        $code = <<<'PHP'
require $argv[1] . '/vendor/autoload.php';
require $argv[1] . '/tests/Fixtures/Fakes.php';
require $argv[1] . '/tests/Stubs/Framework/Wordpress.php';

use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\WordpressHooks;
use ErikWang2013\Xhprof\Wordpress\XhprofPlugin;

$s = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
$_SERVER = [
    'REQUEST_URI' => $s['uri'],
    'REQUEST_METHOD' => 'GET',
    'HTTP_HOST' => 'example.com:8080',
    'REMOTE_ADDR' => '198.51.100.4',
];
$_GET = $s['get'];
$_POST = [];

$cache = new FakeCache();

// exit 会执行 shutdown 回调，正常 return 也会——用它把 send() 之后才可观测的状态带出来。
register_shutdown_function(static function () use ($cache): void {
    fwrite(STDERR, "\n__PROBE__" . json_encode([
        'statuses' => WordpressHooks::$statuses,
        'shutdown_hooks' => WordpressHooks::count('shutdown'),
        'cache_calls' => $cache->calls,
    ]) . "\n");
});

$plugin = new XhprofPlugin($s['config'], $cache, new FakeLogger());
$plugin->register();
WordpressHooks::do('plugins_loaded');

echo 'THEME_RENDERED';
PHP;

        return $this->runCode($code, [
            $root,
            json_encode(['uri' => $uri, 'config' => $config, 'get' => $get], JSON_THROW_ON_ERROR),
        ]);
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
     * 调用记录里属于「写」的那些（读列表是报告页的正常行为，不能一起断言掉）。
     *
     * @param list<string> $calls
     *
     * @return list<string>
     */
    private function writeCalls(array $calls): array
    {
        return array_values(array_filter(
            $calls,
            static fn (string $call): bool => preg_match('/^(lPush|set|del|incr|decr|rPop)/', $call) === 1
        ));
    }

    /**
     * 从子进程 stderr 里取出 shutdown 探针。
     *
     * @return array{statuses: list<array{code:int, description:string}>, shutdown_hooks: int, cache_calls: list<string>}
     */
    private function probe(string $stderr): array
    {
        $pos = strpos($stderr, '__PROBE__');
        $this->assertNotFalse($pos, "子进程没有输出探针（stderr）：{$stderr}");

        $json = substr($stderr, $pos + strlen('__PROBE__'));
        $decoded = json_decode(trim($json), true);

        $this->assertIsArray($decoded, "探针 JSON 解析失败：{$json}");

        return $decoded;
    }
}
