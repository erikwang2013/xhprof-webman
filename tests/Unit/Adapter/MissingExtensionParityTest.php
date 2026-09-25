<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 「装了 ext-xhprof、缺 ext-redis」时九个采样入口的一致行为：报一句、跳过采样、
 * 报告页不进入采样路径。
 *
 * 缺扩展**没有**在进程内模拟：整个用例跑在 `php -n -d extension=…/xhprof.so` 子进程里，
 * 那里的 redis 是真实缺席的（`__env__` 断言把这件事钉成前提，环境变了就红，不会静默空转）。
 * 为什么不用命名空间函数覆盖 / 测试专用开关：那种模拟对「忘判 redis」的变体是**瞎的**
 * ——替身里的 `extension_loaded()` 是照抄实现写的，等于用被测代码证明被测代码；
 * 而这里 redis 是真的没被加载，谁少判一次就真去 `new \Redis()`。
 *
 * 判别力由正控给出：子进程里绕过入口直接 `xhprofStart()/xhprofStop()`，探针必须看见这次
 * 落库（runs===1）。看不见说明探针本身空转，后面的 runs===0 一条证据都不算。
 *
 * 已知覆盖缺口（不在这里假装覆盖了）：
 *  - Symfony：`src/Symfony/XhprofListener.php` 只判了 `extension_loaded('xhprof')`，
 *    该目录不在本次改动范围内，故不列入 ENTRIES；
 *  - WordPress 报告页分支以 `exit` 收尾，报告路径只在真实 HTTP 下验
 *    （见 WordpressTest::reportPageAndAssetsOverRealHttp）。
 */
class MissingExtensionParityTest extends TestCase
{
    /**
     * 九个采样入口。Hyperf 必须排最后：它的 `markHyperfContext()` 会把 Core 的 getter
     * 切到协程 Context（进程内不可逆），此后 `Xhprof::getCache()` 读到的是 Hyperf 的容器。
     */
    private const ENTRIES = [
        'webman',
        'laravel',
        'thinkphp',
        'yii3',
        'slim',
        'drupal',
        'wordpress',
        'joomla',
        'hyperf',
    ];

    #[Test]
    public function everyEntrySkipsSamplingWhenRedisIsMissing(): void
    {
        $result = $this->runDriver('sampling');
        $out = $this->probe($result);

        $this->assertMissingRedisEnvironment($out);
        $this->assertSame(array_merge(['__env__', '__positive_control__'], self::ENTRIES), array_keys($out));

        // 正控：探针看得见一次真实落库，否则 runs===0 全是空转
        $this->assertSame(1, $out['__positive_control__']['runs'], '正控没落库 → 探针空转，本用例失去判别力');
        $this->assertFalse($out['__positive_control__']['leaked']);

        foreach (self::ENTRIES as $entry) {
            $row = $out[$entry];

            $this->assertArrayNotHasKey('error', $row, "$entry: 入口抛异常");
            $this->assertFalse($row['leaked'], "$entry: 采样泄漏（start 之后没走到 stop）");
            $this->assertSame(0, $row['runs'], "$entry: 缺 redis 仍落了库 → 采样没被跳过");
        }

        // 业务请求该往下走：短路/关闭只应发生在报告页与资源路径
        $this->assertTrue($out['yii3']['handler'], 'Yii3: 业务请求不该被入口吞掉');
        $this->assertTrue($out['slim']['handler'], 'Slim: 业务请求不该被入口吞掉');
        $this->assertTrue($out['drupal']['handler'], 'Drupal: 业务请求要进内核');
        $this->assertFalse($out['joomla']['closed'], 'Joomla: 业务请求不该 close()');

        foreach (self::ENTRIES as $entry) {
            if ($entry === 'yii3') {
                // Yii3 的默认 LogAdapter 走 error_log()（无 PSR logger 注入点），告警只能在外层看 stderr
                $this->assertSame([], $out[$entry]['warnings']);
                $this->assertSame(
                    1,
                    substr_count($result['stderr'], 'redis扩展未安装，性能采样已跳过'),
                    'Yii3: error_log 端口径的缺扩展告警应恰好一条'
                );
                continue;
            }

            $this->assertCount(1, $out[$entry]['warnings'], "$entry: 应恰好一条缺扩展告警");
            // 说的是 redis 缺，不是 xhprof 缺：xhprof 在本子进程里是**装着**的
            $this->assertStringContainsString('redis扩展未安装，性能采样已跳过', $out[$entry]['warnings'][0], $entry);
        }
    }

    #[Test]
    public function reportPageDoesNotEnterTheSamplingPathWhenRedisIsMissing(): void
    {
        $result = $this->runDriver('report');
        $out = $this->probe($result);

        $this->assertMissingRedisEnvironment($out);
        $this->assertSame(['__env__', '__positive_control__', 'yii3', 'slim', 'drupal', 'joomla'], array_keys($out));
        $this->assertSame(1, $out['__positive_control__']['runs'], '正控没落库 → 探针空转，本用例失去判别力');

        foreach (['yii3', 'slim', 'drupal', 'joomla'] as $entry) {
            $row = $out[$entry];

            $this->assertArrayNotHasKey('error', $row, "$entry: 报告页抛异常（缺 redis 时报告页不该致命）");
            $this->assertFalse($row['leaked'], "$entry: 报告页泄漏了采样");
            $this->assertSame(0, $row['runs'], "$entry: 报告页进了采样路径");
        }

        // 「报告页由入口短路」这件事本身也要有判别力：短路没了的变体会让下游/内核跑起来。
        $this->assertFalse($out['yii3']['handler'], 'Yii3: 报告页应由入口短路输出，下游 handler 不该跑');
        $this->assertFalse($out['slim']['handler'], 'Slim: 报告页应由入口短路输出，下游 handler 不该跑');
        $this->assertTrue($out['joomla']['closed'], 'Joomla: 报告页分支应以 close() 收尾（真实实现是 exit）');

        // Drupal 是例外且是既知设计：报告页由路由 + Controller/XhprofController 提供，
        // 入口不短路，只保证不采样。这里如实断言这条差异，不当成 bug 抹平。
        $this->assertTrue($out['drupal']['handler'], 'Drupal: 报告页由路由提供，内核照常跑');
    }

    #[Test]
    public function missingExtensionWarningIsLoggedOncePerProcessNotPerRequest(): void
    {
        $out = $this->probe($this->runDriver('repeat'));

        $this->assertMissingRedisEnvironment($out);
        $this->assertSame(['__env__', '__positive_control__', 'webman_x3'], array_keys($out));

        // 三个请求：告警仍是 1 条（粒度照抄 Webman 原有的 private static $warned 进程级一次性）
        $this->assertSame(3, $out['webman_x3']['requests']);
        $this->assertCount(1, $out['webman_x3']['warnings'], '缺扩展告警变成了每请求一条');
        $this->assertSame(0, $out['webman_x3']['runs']);
        $this->assertFalse($out['webman_x3']['leaked']);
    }

    /**
     * 另一个环境：**两个扩展都没有**（`php -n`，一个扩展也不加载）。
     *
     * 这条覆盖 SamplingGuard 里 `!extension_loaded('xhprof')` 那半边，以及「缺哪个说哪个」
     * 的配对行为——Webman 原有的实现就是两条各说一次（先 xhprof 后 redis），
     * 收进 Core 时逐字保留，这里把它钉成断言（HEAD 上这两句文案**没有任何测试**覆盖）。
     */
    #[Test]
    public function everyEntryReportsBothMissingExtensionsWhenNeitherIsLoaded(): void
    {
        $result = $this->runDriver('sampling', loadXhprof: false);
        $out = $this->probe($result);

        $this->assertSame(
            ['xhprof' => false, 'redis' => false],
            $out['__env__'] ?? null,
            '本用例的前提是两个扩展都不在；环境不满足就该红'
        );
        $this->assertSame(array_merge(['__env__', '__positive_control__'], self::ENTRIES), array_keys($out));
        // 没有扩展就起不了采样，正控在本模式下不成立（驱动会标 skipped），故这里不拿 runs 当判据
        $this->assertArrayHasKey('skipped', $out['__positive_control__']);

        foreach (self::ENTRIES as $entry) {
            $row = $out[$entry];

            $this->assertArrayNotHasKey('error', $row, "$entry: 入口抛异常");
            $this->assertFalse($row['leaked'], "$entry: 采样泄漏");
            $this->assertSame(0, $row['runs'], "$entry: 没有扩展却落了库");

            if ($entry === 'yii3') {
                // 同上：Yii3 的告警只出现在 stderr，两条都要有、各一次
                $this->assertSame([], $row['warnings']);
                foreach (['xhprof扩展未安装，性能采样已跳过', 'redis扩展未安装，性能采样已跳过'] as $message) {
                    $this->assertSame(1, substr_count($result['stderr'], $message), "Yii3: {$message}");
                }
                continue;
            }

            // 两条都要说，且顺序与 warnMissingOnce() 一致（先 xhprof 后 redis，照抄 Webman 原文案）
            $this->assertCount(2, $row['warnings'], "$entry: 应恰好两条缺扩展告警");
            $this->assertStringContainsString('xhprof扩展未安装，性能采样已跳过', $row['warnings'][0], $entry);
            $this->assertStringContainsString('redis扩展未安装，性能采样已跳过', $row['warnings'][1], $entry);
        }
    }

    // ---------- 子进程驱动 ----------

    /**
     * @return array{code:int, stdout:string, stderr:string}
     */
    private function runDriver(string $mode, bool $loadXhprof = true): array
    {
        $so = $loadXhprof ? $this->locateXhprofSo() : null;
        $root = dirname(__DIR__, 3);
        $file = (string) tempnam(sys_get_temp_dir(), 'xhprof-driver-') . '.php';
        file_put_contents($file, self::driverCode());

        $proc = proc_open(
            array_merge(
                [
                    PHP_BINARY,
                    // -n：不读 php.ini，redis 因此**真的**没被加载（本用例的前提，由 __env__ 断言钉住）
                    '-n',
                    '-d', 'display_errors=stderr',
                    '-d', 'error_reporting=E_ALL',
                ],
                // 显式加载 ext-xhprof：不加载就是「两个扩展都没有」那个环境
                $so === null ? [] : ['-d', 'extension=' . $so],
                [$file, $root, $mode]
            ),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($proc, 'proc_open 失败');

        try {
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            unlink($file);
        }

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * @param array{code:int, stdout:string, stderr:string} $result
     *
     * @return array<string, array<string, mixed>>
     */
    private function probe(array $result): array
    {
        $pos = strpos($result['stdout'], '<<<XHPROF_JSON>>>');
        $this->assertNotFalse($pos, "子进程没有输出探针：{$result['stdout']}\n{$result['stderr']}");

        $end = strpos($result['stdout'], '<<<XHPROF_END>>>', (int) $pos);
        $this->assertNotFalse($end, '探针没有结束标记');

        $json = substr($result['stdout'], (int) $pos + strlen('<<<XHPROF_JSON>>>'), (int) $end - (int) $pos - strlen('<<<XHPROF_JSON>>>'));
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded, "探针 JSON 解析失败：{$json}");

        return $decoded;
    }

    /**
     * 前提不是假设出来的：子进程自己报「xhprof 在、redis 不在」。
     *
     * @param array<string, array<string, mixed>> $out
     */
    private function assertMissingRedisEnvironment(array $out): void
    {
        $this->assertSame(
            ['xhprof' => true, 'redis' => false],
            $out['__env__'] ?? null,
            '本用例的前提是「装了 ext-xhprof、缺 ext-redis」；环境不满足就该红，不能静默空转'
        );
    }

    /**
     * 找得到能真正加载 xhprof.so 的绝对路径（`-n` 下没有 php.ini，只能用绝对路径）。
     *
     * 判据是**起一个子进程试一次**，不是路径长得像：`extension=` 指到坏文件时 PHP 只往
     * stderr 打一行 warning 然后照常启动，靠文件名猜会得到一个「装了 xhprof」的假子进程。
     */
    private function locateXhprofSo(): string
    {
        $dir = (string) PHP_EXTENSION_DIR;
        $candidates = array_merge(
            is_dir($dir) && is_file($dir . '/xhprof.so') ? [$dir . '/xhprof.so'] : [],
            glob($dir . '/*xhprof*.so') ?: []
        );

        foreach (array_unique($candidates) as $candidate) {
            $probe = proc_open(
                [PHP_BINARY, '-n', '-d', 'extension=' . $candidate, '-r', 'echo (int) extension_loaded("xhprof"), (int) extension_loaded("redis");'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            if (!is_resource($probe)) {
                continue;
            }
            $out = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($probe);

            // 「10」= xhprof 在、redis 不在，正是本用例要的环境
            if ($out === '10') {
                return $candidate;
            }
        }

        $this->markTestSkipped(
            '本机找不到能在 `php -n` 下加载的 xhprof.so（找过：' . implode(', ', array_unique($candidates)) . '）' .
            '；该用例需要真实的 ext-xhprof 环境，不做进程内模拟。'
        );
    }

    /**
     * 子进程脚本。`$root`/`$mode` 由 argv 传入。
     *
     * 每个 arm 做三件事：重置「已告警」标志（Core\SamplingGuard::$warned 的进程级一次性
     * 状态，不重置就只能验到第一个入口）、驱一次入口、报三个观测量——
     * runs（本次落库了几条）、leaked（采样还开着=有人 start 了没 stop）、
     * 以及下游是否跑过（报告页短路的判别力所在）。
     */
    private static function driverCode(): string
    {
        return <<<'PHP'
<?php

$root = $argv[1];
$mode = $argv[2] ?? 'sampling';
require $root . '/tests/bootstrap.php';

$GLOBALS['warn_once_hold'] = false;

$out = [];

$out['__env__'] = ['xhprof' => extension_loaded('xhprof'), 'redis' => extension_loaded('redis')];

$runs = static function (): int {
    $cache = \ErikWang2013\Xhprof\Core\Xhprof::getCache();
    if ($cache === null) {
        return -1;
    }

    return count($cache->lRange(\ErikWang2013\Xhprof\Core\Xhprof::$key_prefix . ':run_id', 0, -1));
};

/** 采样还开着 = 有人 start() 了没人 stop()（xhprof_disable() 在无采样时返回 null） */
$leaked = static function (): bool {
    // 一个扩展都没有的那个环境里没有这个函数可调；没有它就不可能有采样在跑
    return function_exists('xhprof_disable') && xhprof_disable() !== null;
};

$resetWarnOnce = static function (): void {
    if ($GLOBALS['warn_once_hold'] === true) {
        return;
    }
    $warned = new ReflectionProperty(\ErikWang2013\Xhprof\Core\SamplingGuard::class, 'warned');
    $warned->setAccessible(true);
    $warned->setValue(null, false);
};

$arms = [];

$arms['webman'] = static function (string $uri) use ($runs, $leaked, $resetWarnOnce): array {
    $resetWarnOnce();
    \ErikWang2013\Xhprof\Tests\Stubs\Registry::$webmanConfig = [
        'plugin' => ['aaron-dev' => ['xhprof' => ['xhprof' => ['enable' => true, 'ignore_url_arr' => []]]]],
    ];
    \support\Log::reset();
    (new \ErikWang2013\Xhprof\Webman\XhprofMiddleware())->process(
        new \Webman\Http\Request([], ['uri' => $uri]),
        static function ($r) {
            return new \Webman\Http\Response(200, [], 'business');
        }
    );

    return ['warnings' => \support\Log::$errors, 'runs' => $runs(), 'leaked' => $leaked()];
};

$arms['laravel'] = static function (string $uri) use ($runs, $leaked, $resetWarnOnce): array {
    $resetWarnOnce();
    \ErikWang2013\Xhprof\Tests\Stubs\Registry::$laravelConfig = [
        'xhprof' => ['enable' => true, 'ignore_url_arr' => []],
    ];
    \Illuminate\Support\Facades\Log::reset();
    (new \ErikWang2013\Xhprof\Laravel\Middleware())->handle(
        new \Illuminate\Http\Request([], ['uri' => $uri]),
        static function () {
            return new \Illuminate\Http\Response('business');
        }
    );

    return ['warnings' => \Illuminate\Support\Facades\Log::$errors, 'runs' => $runs(), 'leaked' => $leaked()];
};

$arms['thinkphp'] = static function (string $uri) use ($runs, $leaked, $resetWarnOnce): array {
    $resetWarnOnce();
    \think\facade\Config::$data = ['xhprof' => ['enable' => true, 'ignore_url_arr' => []]];
    \think\facade\Log::reset();
    (new \ErikWang2013\Xhprof\Thinkphp\Middleware())->handle(
        new \think\Request([], ['uri' => $uri]),
        static function ($r) {
            return new \think\Response('business');
        }
    );

    return ['warnings' => \think\facade\Log::$errors, 'runs' => $runs(), 'leaked' => $leaked()];
};

$arms['yii3'] = static function (string $uri) use ($runs, $leaked, $resetWarnOnce): array {
    $resetWarnOnce();
    $cache = new \ErikWang2013\Xhprof\Tests\Fixtures\FakeCache();
    $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
        public bool $called = false;

        public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
        {
            $this->called = true;

            return new \ErikWang2013\Xhprof\Tests\Stubs\Framework\FakePsrResponse(200, [], 'business');
        }
    };
    (new \ErikWang2013\Xhprof\Yii3\XhprofMiddleware(
        new \ErikWang2013\Xhprof\Tests\Stubs\Framework\FakeResponseFactory(),
        ['enable' => true, 'ignore_url_arr' => []],
        $cache
    ))->process(new \ErikWang2013\Xhprof\Tests\Stubs\Framework\FakeServerRequest('GET', $uri), $handler);

    // Yii3 的默认 LogAdapter 走 error_log()（无 PSR logger 注入点），告警只能在外层看 stderr
    return ['warnings' => [], 'runs' => $runs(), 'leaked' => $leaked(), 'handler' => $handler->called];
};

$arms['slim'] = static function (string $uri) use ($runs, $leaked, $resetWarnOnce): array {
    $resetWarnOnce();
    $cache = new \ErikWang2013\Xhprof\Tests\Fixtures\FakeCache();
    $logger = new \ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger();
    $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
        public bool $called = false;

        public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
        {
            $this->called = true;

            return new \ErikWang2013\Xhprof\Tests\Stubs\Framework\FakePsrResponse(200, [], 'business');
        }
    };
    (new \ErikWang2013\Xhprof\Slim\XhprofMiddleware(
        new \ErikWang2013\Xhprof\Tests\Stubs\Framework\FakeResponseFactory(),
        ['enable' => true, 'ignore_url_arr' => []],
        $cache,
        $logger
    ))->process(new \ErikWang2013\Xhprof\Tests\Stubs\Framework\FakeServerRequest('GET', $uri), $handler);

    return ['warnings' => $logger->errors, 'runs' => $runs(), 'leaked' => $leaked(), 'handler' => $handler->called];
};

$arms['drupal'] = static function (string $uri) use ($runs, $leaked, $resetWarnOnce): array {
    $resetWarnOnce();
    $cache = new \ErikWang2013\Xhprof\Tests\Fixtures\FakeCache();
    $loggerFactory = new \ErikWang2013\Xhprof\Tests\Stubs\Framework\Drupal\FakeLoggerFactory();
    $kernel = new class implements \Symfony\Component\HttpKernel\HttpKernelInterface {
        public int $calls = 0;

        public function handle(
            \Symfony\Component\HttpFoundation\Request $request,
            int $type = self::MAIN_REQUEST,
            bool $catch = true
        ): \Symfony\Component\HttpFoundation\Response {
            $this->calls++;

            return new \Symfony\Component\HttpFoundation\Response('business');
        }
    };
    (new \ErikWang2013\Xhprof\Drupal\XhprofMiddleware(
        $kernel,
        new \ErikWang2013\Xhprof\Tests\Stubs\Framework\Drupal\FakeConfigFactory([
            'xhprof.settings' => ['enable' => true, 'ignore_url_arr' => []],
        ]),
        $loggerFactory,
        $cache
    ))->handle(\Symfony\Component\HttpFoundation\Request::create($uri));

    return ['warnings' => $loggerFactory->errors, 'runs' => $runs(), 'leaked' => $leaked(), 'handler' => $kernel->calls > 0];
};

$arms['wordpress'] = static function (string $uri) use ($runs, $leaked, $resetWarnOnce): array {
    $resetWarnOnce();
    $_SERVER = ['REQUEST_URI' => $uri, 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com', 'REMOTE_ADDR' => '127.0.0.1'];
    $_GET = [];
    $_POST = [];
    $_REQUEST = [];
    \ErikWang2013\Xhprof\Tests\Stubs\Framework\WordpressHooks::reset();
    $cache = new \ErikWang2013\Xhprof\Tests\Fixtures\FakeCache();
    $logger = new \ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger();
    $plugin = new \ErikWang2013\Xhprof\Wordpress\XhprofPlugin(['enable' => true, 'ignore_url_arr' => []], $cache, $logger);

    // 报告页分支里 onPluginsLoaded() 会 exit，本文件只在 sampling 模式驱它（故这次调用一定会返回）
    $plugin->onPluginsLoaded();
    \ErikWang2013\Xhprof\Tests\Stubs\Framework\WordpressHooks::do('shutdown');

    return ['warnings' => $logger->errors, 'runs' => $runs(), 'leaked' => $leaked()];
};

$arms['joomla'] = static function (string $uri) use ($runs, $leaked, $resetWarnOnce): array {
    $resetWarnOnce();
    $tmp = sys_get_temp_dir() . '/xhprof-missing-ext-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0777, true);
    file_put_contents($tmp . '/xhprof.php', "<?php\n\nreturn ['enable' => true, 'ignore_url_arr' => []];\n");
    if (!defined('JPATH_ROOT')) {
        define('JPATH_ROOT', $tmp);
    }
    $_SERVER = ['REQUEST_URI' => $uri, 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com', 'REMOTE_ADDR' => '127.0.0.1'];
    $_GET = [];
    $_REQUEST = [];
    $cache = new \ErikWang2013\Xhprof\Tests\Fixtures\FakeCache();
    $logger = new \ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger();
    $app = new \ErikWang2013\Xhprof\Tests\Stubs\Framework\JoomlaFakeApplication([]);
    $plugin = new \ErikWang2013\Xhprof\Joomla\Extension\Xhprof(
        new \ErikWang2013\Xhprof\Tests\Stubs\Framework\JoomlaNoopDispatcher(),
        [],
        $cache,
        $logger
    );
    $plugin->setApplication($app);
    $plugin->onAfterInitialise();
    $plugin->onAfterRespond();
    @unlink($tmp . '/xhprof.php');
    @rmdir($tmp);

    // 报告页分支以 $app->close() 收尾（真实实现是 exit）；业务请求不该 close
    return ['warnings' => $logger->errors, 'runs' => $runs(), 'leaked' => $leaked(), 'closed' => $app->closeCalls > 0];
};

// Hyperf 放最后：markHyperfContext() 会把 Core 的 getter 切到协程 Context（static 在本进程里
// 不可逆），此后的 arm 用 getCache() 会读到 Hyperf 的 cache。
$arms['hyperf'] = static function (string $uri) use ($runs, $leaked, $resetWarnOnce): array {
    $resetWarnOnce();
    \Hyperf\Context\Context::reset();
    \Hyperf\Context\ApplicationContext::reset();
    $container = \Hyperf\Context\ApplicationContext::getContainer();
    $logger = new class implements \Psr\Log\LoggerInterface {
        /** @var array<int, string> */
        public array $errors = [];

        public function error(string $message, array $context = []): void
        {
            $this->errors[] = $message;
        }
    };
    $container->set(\Hyperf\HttpServer\Contract\RequestInterface::class, new \Hyperf\HttpServer\Request([], ['uri' => $uri]));
    $container->set(\Hyperf\HttpServer\Contract\ResponseInterface::class, new \Hyperf\HttpServer\Response());
    $container->set(\Hyperf\Contract\ConfigInterface::class, new \Hyperf\Config(['xhprof' => ['enable' => true, 'ignore_url_arr' => []]]));
    $container->set(\Hyperf\Redis\Redis::class, new \Hyperf\Redis\Redis());
    $container->set(\Psr\Log\LoggerInterface::class, $logger);
    $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
        public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
        {
            return new \ErikWang2013\Xhprof\Tests\Stubs\Framework\FakePsrResponse(200, [], 'business');
        }
    };
    (new \ErikWang2013\Xhprof\Hyperf\Middleware())->process(new \ErikWang2013\Xhprof\Tests\Stubs\Framework\FakeServerRequest('GET', $uri), $handler);

    return ['warnings' => $logger->errors, 'runs' => $runs(), 'leaked' => $leaked()];
};

// ---- 正控：绕过入口直接 start/stop：探针必须看得见这一次落库 ----
// 看不见就说明整个探针是空转的（比如 save_run 在 FakeCache 下根本不落库）。
// 没有 ext-xhprof 的环境里 start() 会因函数未定义直接致命，故这条正控只能跳过。
$out['__positive_control__'] = (static function () use ($runs, $leaked): array {
    $cache = new \ErikWang2013\Xhprof\Tests\Fixtures\FakeCache();
    \ErikWang2013\Xhprof\Core\Xhprof::bootstrap(
        new \ErikWang2013\Xhprof\Tests\Fixtures\FakeRequest([], ['uri' => '/forced']),
        new \ErikWang2013\Xhprof\Tests\Fixtures\FakeResponse(),
        new \ErikWang2013\Xhprof\Tests\Fixtures\FakeConfig(['xhprof' => ['enable' => true, 'ignore_url_arr' => []]]),
        $cache,
        new \ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger()
    );
    if (!extension_loaded('xhprof')) {
        return ['warnings' => [], 'runs' => $runs(), 'leaked' => $leaked(), 'skipped' => '没有 ext-xhprof，起不了采样'];
    }
    \ErikWang2013\Xhprof\Core\Xhprof::xhprofStart();
    \ErikWang2013\Xhprof\Core\Xhprof::xhprofStop();

    return ['warnings' => [], 'runs' => $runs(), 'leaked' => $leaked()];
})();

if ($mode === 'report') {
    foreach (['yii3', 'slim', 'drupal', 'joomla'] as $entry) {
        try {
            $out[$entry] = $arms[$entry]('/xhprof');
        } catch (\Throwable $e) {
            $out[$entry] = ['error' => get_class($e) . ': ' . $e->getMessage()];
        }
    }
} elseif ($mode === 'repeat') {
    // 同一进程里连驱三次 webman 入口：只有第一次该告警（$warned 是进程级一次性）
    $warnings = [];
    $row = ['runs' => 0, 'leaked' => false];
    $GLOBALS['warn_once_hold'] = false;
    for ($i = 0; $i < 3; $i++) {
        $row = $arms['webman']('/business');
        $warnings = array_merge($warnings, $row['warnings']);
        $GLOBALS['warn_once_hold'] = true;
    }
    $out['webman_x3'] = ['warnings' => $warnings, 'runs' => $row['runs'], 'leaked' => $row['leaked'], 'requests' => 3];
} else {
    foreach (['webman', 'laravel', 'thinkphp', 'yii3', 'slim', 'drupal', 'wordpress', 'joomla', 'hyperf'] as $entry) {
        try {
            $out[$entry] = $arms[$entry]('/business');
        } catch (\Throwable $e) {
            $out[$entry] = ['error' => get_class($e) . ': ' . $e->getMessage()];
        }
    }
}

fwrite(STDOUT, "\n<<<XHPROF_JSON>>>" . json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "<<<XHPROF_END>>>\n");
PHP;
    }
}
