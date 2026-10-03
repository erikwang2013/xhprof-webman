<?php

declare(strict_types=1);

/**
 * 真 workerman 服务器 + 真 TCP 请求下的**协程交错**探针（cases/Webman.php 的 ⑥ 用它）。
 *
 * 为什么要有这一个进程形状：本卡的 ①–⑤ 都是"直接构造 Request 调适配器"，从不跑
 * `Worker::runAll()`（事件循环起不来）。而「常驻 worker 里一个请求渲染到一半让出、另一个
 * 请求把整页渲染完」这件事**只有在真事件循环里才存在**，所以这一段起真服务器、发真 TCP 请求。
 *
 * 一个脚本两个角色（子进程的 argv[1] 要留给 workerman 的 `start`，所以角色/参数走环境变量）：
 *   父（driver）：php lib/webman-coroutine-interleave.php <repoRoot> <ring autoload> <port>
 *   子（server）：PROBE_ROLE=server PROBE_REPO=.. PROBE_RING_AUTOLOAD=.. PROBE_PORT=.. php <本文件> start
 *
 * 交错顺序**确定性**，不靠 sleep 竞速：
 *   ① 父 GET A（只写不读）：A 的 onMessage 渲染到 `:request_log:` 读时真挂起（见下），
 *      服务器把「已挂起几次」记在计数器里；
 *   ② 父轮询 `/__status` 直到 suspends≥1（这条轮询本身还证明：A 挂起期间事件循环**仍在服务**，
 *      新回调照样被 Events\Fiber 各自包一层 Fiber —— stock webman 的形状）；
 *   ③ 父 GET B，把 B 的整页读完，再取一次 `/__status`：此刻 resumes 必须还是 0（A 还没醒）；
 *   ④ 父 GET `/__go` → 服务器把「Redis 回包」写到 A 等着的那个 socket 上 → A 被事件循环唤醒；
 *   ⑤ 父读 A 的回包（①那条连接），按字段原样报出去（断言在 case 里做，计数器归 case）。
 *
 * 「真挂起」不是造的：`FiberIo::await()` 在一条真 unix socket 上注册 Revolt 的 onReadable
 * 并 `\Fiber::suspend()`，恢复由真可读事件驱动 —— 与生产里渲染中途那次 Redis GET 让出
 * 协程是同一类机制（本环没有异步 Redis 客户端，所以用一个缓存桩把"那次 GET"换成这次真
 * socket 等待；被挂起的整个渲染调用栈、事件循环、每回调一层 Fiber 全是真的）。
 *
 * 输出：stdout 上一行 JSON（父角色）；服务器子进程的输出落在 <tmp>/server.log 供诊断。
 */

$role = getenv('PROBE_ROLE') ?: 'driver';
if ($role === 'server') {
    $repoRoot = (string) getenv('PROBE_REPO');
    $ringAutoload = (string) getenv('PROBE_RING_AUTOLOAD');
    $port = (int) getenv('PROBE_PORT');
    $logFile = (string) getenv('PROBE_SERVER_LOG');
} else {
    // 父角色：argv 1..3 = 仓库根、环 autoload、端口（0 = 自己挑一个空闲端口）
    $repoRoot = (string) ($argv[1] ?? '');
    $ringAutoload = (string) ($argv[2] ?? '');
    $port = (int) ($argv[3] ?? 0);
    $logFile = sys_get_temp_dir() . '/xhprof-webman-coroutine-probe-' . getmypid() . '-server.log';
}

if ($repoRoot === '' || $ringAutoload === '' || !is_file($ringAutoload)) {
    fwrite(STDERR, "用法: php webman-coroutine-interleave.php <repoRoot> <ring vendor/autoload.php> <port>\n");
    exit(2);
}

require $ringAutoload;                                     // 真 workerman + workerman/coroutine + revolt
spl_autoload_register(static function (string $class) use ($repoRoot): void {
    $prefix = 'ErikWang2013\\Xhprof\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = $repoRoot . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
require_once $repoRoot . '/tests/Fixtures/Fakes.php';      // 本仓自己的测试夹具（须在 src 自动加载之后：它 implements 契约接口）

use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeConfig;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeRequest;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeResponse;

/** 平表各行第一个单元格的函数名，按渲染顺序。 */
function probe_flat_rows(string $html): array
{
    if (preg_match('#<tbody>(.*)</tbody>#s', $html, $m) !== 1) {
        return [];
    }
    preg_match_all('#<td><a href="[^"]*">([^<]+)</a>#', $m[1], $rows);

    return array_map('html_entity_decode', $rows[1]);
}

/** `<html lang="…">` 里的标签（报告页页首那次读）。 */
function probe_html_lang(string $html): ?string
{
    return preg_match('#<html lang="([^"]*)"#', $html, $m) === 1 ? $m[1] : null;
}

/**
 * 「真让出」：在真 unix socket 上等一次真可读事件，期间挂起当前 Fiber。
 * 恢复由 Revolt 事件循环的 onReadable 回调驱动（与 workerman `Events\Fiber` 跑回调同一个循环）。
 */
final class ProbeFiberIo
{
    /** @var resource|null */
    private static $rd = null;
    /** @var resource|null */
    private static $wr = null;

    /** 真被事件循环唤醒过几次 */
    public static int $resumes = 0;

    public static function init(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new \RuntimeException('stream_socket_pair() 失败');
        }
        [$rd, $wr] = $pair;
        stream_set_blocking($rd, false);
        stream_set_blocking($wr, false);
        self::$rd = $rd;
        self::$wr = $wr;
    }

    public static function await(): void
    {
        $me = \Fiber::getCurrent();
        if ($me === null) {
            throw new \RuntimeException('await() 不在协程里（stock webman 的 onMessage 就这样）');
        }
        \Revolt\EventLoop::onReadable(self::$rd, static function (string $id) use ($me): void {
            \Revolt\EventLoop::cancel($id);
            fread(self::$rd, 64);          // 真读
            self::$resumes++;
            $me->resume();                 // 唤醒挂起的渲染调用栈
        });
        \Fiber::suspend();
    }

    /** 对端写一个字节：fd 变可读，事件循环稍后会唤醒挂起者。 */
    public static function release(): void
    {
        fwrite(self::$wr, 'go');           // 真写
    }
}

/** 与生产同形的缓存桩：读 `:request_log:` 时触发一次钩子（生产里那一次是会让出协程的 Redis GET）。 */
final class ProbeYieldCache extends FakeCache
{
    /** @var (callable(string):void)|null */
    public ?\Closure $onRequestLog = null;

    public function get(string $key): mixed
    {
        $cb = $this->onRequestLog;
        if ($cb !== null && str_contains($key, ':request_log:')) {
            $this->onRequestLog = null;    // 只让出一次
            $cb($key);
        }

        return parent::get($key);
    }
}

$idA = 'a1a1a1a1a1a1a1a1';
$idB = 'b2b2b2b2b2b2b2b2';

/** 两个 run 的边表：排序列/指标/函数名逐项不同，串扰一眼可辨。 */
$runA = [
    'main()' => ['ct' => 1, 'wt' => 100000, 'mu' => 2048],
    'main()==>zzz()' => ['ct' => 1, 'wt' => 90000, 'mu' => 512],
    'main()==>aaa()' => ['ct' => 1, 'wt' => 10000, 'mu' => 256],
];
$runB = [
    'main()' => ['ct' => 1, 'wt' => 500000, 'mu' => 9999, 'pmu' => 8888],
    'main()==>other()' => ['ct' => 3, 'wt' => 400000, 'mu' => 777, 'pmu' => 666],
];

/** 一次完整的报告页渲染（与单测里的夹具同形）。 */
$render = static function (string $runId, string $lang, ?string $sort, ProbeYieldCache $cache): string {
    $params = ['run' => $runId, 'source' => 'xhprof_foo', 'all' => '1', 'lang' => $lang];
    if ($sort !== null) {
        $params['sort'] = $sort;
    }
    Xhprof::$time_limit = 0;
    Xhprof::$ignore_url_arr = ['/xhprof'];
    Xhprof::$key_prefix = 'xhprof';
    Xhprof::$view_wtred = 3;
    Xhprof::$ui_html = '';
    Xhprof::bootstrap(
        new FakeRequest($params, ['uri' => '/xhprof']),
        new FakeResponse(),
        new FakeConfig(['xhprof' => []]),
        $cache,
        new FakeLogger()
    );

    return (string) Xhprof::index();
};

$makeCache = static function (bool $yielding) use ($idA, $idB, $runA, $runB): ProbeYieldCache {
    $cache = new ProbeYieldCache();
    $cache->set('xhprof:xhprof_log:' . $idA, serialize($runA));
    $cache->set('xhprof:xhprof_log:' . $idB, serialize($runB));
    $cache->set('xhprof:request_log:' . $idA, (string) json_encode(['request_uri' => '/a', 'method' => 'GET']));
    $cache->set('xhprof:request_log:' . $idB, (string) json_encode(['request_uri' => '/b', 'method' => 'GET']));
    if ($yielding) {
        $cache->onRequestLog = static function (): void {
            ProbeFiberIo::await();       // 真挂起：生产里这一次是 Redis GET
        };
    }

    return $cache;
};

// =====================================================================
// 服务器角色
// =====================================================================
if ($role === 'server') {
    ProbeFiberIo::init();
    \Workerman\Worker::$eventLoopClass = \Workerman\Events\Fiber::class;   // 每个回调一层 Fiber
    // workerman 默认把日志写到**启动脚本旁边**（Worker.php:753 用启动文件目录拼 workerman.log）——
    // 不指走的话跑一次就在 tools/contracts/lib/ 里落一个仓库外文件。指到本子进程的日志文件，
    // 顺带让服务端自己的消息和 stdout/stderr 待在一起（出错时尾部会嵌进 JSON）。
    \Workerman\Worker::$logFile = $logFile !== '' ? $logFile : '/dev/null';
    // 与 stock webman 同形：`support/App.php:117` 用 `config('app.request_class', support\Request::class)`
    // 构造 `\Webman\App`，后者（App.php:266）把它交给 workerman 的 Http 协议 —— 不设的话
    // onMessage 收到的是基类 `Workerman\Protocols\Http\Request`，没有 getRemoteIp() 这些子类方法。
    \Workerman\Protocols\Http::requestClass(\support\Request::class);

    /** @var array<string, array<string, mixed>> 每请求的观察值（/__status 原样报出去，断言在 case 里） */
    $obs = [];
    /** @var array<string, int> 控制路由的口径：A 真正挂起 / 真正被唤醒过几次 */
    $suspends = ['a' => 0];
    $resumesAfterGo = 0;

    $w = new \Workerman\Worker('http://127.0.0.1:' . $port);
    $w->count = 1;
    $w->onMessage = static function (
        \Workerman\Connection\TcpConnection $conn,
        \Workerman\Protocols\Http\Request $req
    ) use ($render, $makeCache, $idA, &$obs, &$suspends): void {
        $uri = $req->uri();

        if ($uri === '/__status') {
            // 这条路由本身是证据：A 挂起期间事件循环还在服务新连接。
            $conn->send(probe_json([
                'suspends' => $suspends['a'],
                'resumes' => ProbeFiberIo::$resumes,
                'obs' => $obs,
            ]));
            $conn->close();

            return;
        }
        if ($uri === '/__go') {
            ProbeFiberIo::release();     // 把「Redis 回包」写上，A 等的那个 fd 变可读
            $conn->send(probe_json(['released' => true]));
            $conn->close();

            return;
        }

        $runId = (string) $req->get('run', '');
        $isA = $runId === $idA;
        $cache = $makeCache($isA);
        if ($isA) {
            $cache->onRequestLog = static function () use (&$suspends): void {
                $suspends['a']++;
                ProbeFiberIo::await();
            };
        }

        // 本次请求看到的协程/后端事实 —— 正是 `Xhprof::coroutineContextClass()` 此刻的答案
        $observed = [
            'is_coroutine' => \Workerman\Coroutine::isCoroutine(),
            'fiber' => \Fiber::getCurrent() !== null,
            'context_class' => Xhprof::coroutineContextClass(),
            'uid' => $req->get('run'),
            'req_class' => get_class($req),        // 正对照：必须是 support\Request（stock webman 的请求类）
        ];
        if ($isA) {
            // A 这一条是真 TCP 连接（workerman 协议解析时挂上的 $request->connection）：
            // 顺带把 getRealIp 的"有连接"形状量出来（无连接那条 0.0.0.0 已在 case ② 钉过）。
            $observed['remote_ip'] = $req->getRemoteIp();
            $observed['is_intranet'] = \Webman\Http\Request::isIntranetIp($req->getRemoteIp());
            $observed['real_ip'] = (new \ErikWang2013\Xhprof\Webman\Adapter\RequestAdapter($req))->getRealIp();
        }

        $body = $render($runId, (string) $req->get('lang', 'zh_CN'), $req->get('sort'), $cache);

        $observed['php'] = PHP_VERSION;
        $obs[(string) $runId] = $observed;

        $conn->send("HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: "
            . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
        $conn->close();
    };
    \Workerman\Worker::runAll();
    exit(0);
}

// =====================================================================
// 父角色（driver）：起服务器、按确定顺序发请求、把事实报成 JSON
// =====================================================================
function probe_json(array $payload): string
{
    return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

$stage = 'init';
$proc = null;

/** 输出 JSON 并收摊（无论从哪条路退出，服务器进程都不留）。 */
$finish = static function (array $payload) use (&$proc, &$stage, $logFile): void {
    $payload['stage'] = $stage;
    if ($proc !== null) {
        @proc_terminate($proc);                       // 默认 SIGTERM：workerman 主进程据此停 worker（用数字常量，不依赖 ext-pcntl）
        $deadline = microtime(true) + 3.0;
        $status = @proc_get_status($proc);
        while (is_array($status) && $status['running'] && microtime(true) < $deadline) {
            usleep(50_000);
            $status = @proc_get_status($proc);
        }
        if (is_array($status) && $status['running']) {
            @proc_terminate($proc, 9);                // 兜底；SIGTERM 正常时走不到这里
        }
        @proc_close($proc);
    }
    if (($payload['error'] ?? null) === null) {
        @unlink($logFile);                            // 跑通了就不留日志（出错时日志尾已嵌进 JSON）
    }
    echo probe_json($payload);
    exit(($payload['error'] ?? null) === null ? 0 : 3);
};

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static function () use ($finish): void {
        $finish(['error' => '探针超时（40s），停在阶段：' . $GLOBALS['stage']]);
    });
    pcntl_alarm(40);
}

if ($port <= 0) {
    $srv = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($srv === false) {
        $finish(['error' => "挑不到空闲端口: $errstr"]);
    }
    $name = (string) stream_socket_get_name($srv, false);
    fclose($srv);
    $port = (int) substr($name, (int) strrpos($name, ':') + 1);
}

// 服务器子进程：argv[1] 必须是 workerman 的命令字 start（PHP 的 getopt 遇到第一个非选项参数
// 就停，后面的选项会被静默吞掉），所以参数全走环境变量。
$stage = 'start_server';
$env = [
    'PROBE_ROLE' => 'server', 'PROBE_REPO' => $repoRoot, 'PROBE_RING_AUTOLOAD' => $ringAutoload,
    'PROBE_PORT' => (string) $port, 'PROBE_SERVER_LOG' => $logFile,
] + getenv();
$proc = @proc_open(
    [PHP_BINARY, '-d', 'display_errors=stderr', __FILE__, 'start'],
    [1 => ['file', $logFile, 'w'], 2 => ['file', $logFile, 'a']],
    $pipes,
    null,
    $env
);
if (!is_resource($proc)) {
    $finish(['error' => 'proc_open() 起不了服务器子进程']);
}

$connect = static function () use ($port): mixed {
    $c = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 2);
    if ($c === false) {
        return null;
    }
    stream_set_timeout($c, 10);

    return $c;
};

$deadline = microtime(true) + 10.0;
$up = false;
while (microtime(true) < $deadline) {
    $c = $connect();
    if ($c !== null) {
        fclose($c);
        $up = true;
        break;
    }
    usleep(80_000);
}
if (!$up) {
    $finish(['error' => "服务器 10s 没起来（端口 {$port}）；server.log：" . probe_tail($logFile)]);
}

$send = static function (string $path, array $headers = []) use ($connect): mixed {
    $c = $connect();
    if ($c === null) {
        return null;
    }
    $extra = '';
    foreach ($headers as $name => $value) {
        $extra .= "{$name}: {$value}\r\n";
    }
    fwrite($c, "GET {$path} HTTP/1.1\r\nHost: localhost\r\n{$extra}Connection: close\r\n\r\n");

    return $c;
};
$readBody = static function ($c): string {
    $raw = (string) stream_get_contents($c);
    fclose($c);
    $pos = strpos($raw, "\r\n\r\n");

    return $pos === false ? '' : substr($raw, $pos + 4);
};
$status = static function () use ($send, $readBody): array {
    $c = $send('/__status');
    $decoded = $c === null ? null : json_decode($readBody($c), true);

    return is_array($decoded) ? $decoded : [];
};

// ① GET A（只写不读）：A 渲染到 :request_log: 读时真挂起
$stage = 'a_suspend';
$connA = $send(
    '/xhprof?run=' . $idA . '&source=xhprof_foo&all=1&lang=en',
    ['X-Forwarded-For' => '9.9.9.9, 8.8.8.8']     // ⑥ 顺带实测真连接下的 getRealIp（remote 是环回 = 内网 → 看 XFF 首项）
);
if ($connA === null) {
    $finish(['error' => '连不上 A']);
}
$suspended = false;
$deadline = microtime(true) + 10.0;
while (microtime(true) < $deadline) {
    if (($status()['suspends'] ?? 0) >= 1) {
        $suspended = true;
        break;
    }
    usleep(20_000);
}
if (!$suspended) {
    $finish(['error' => 'A 10s 内没挂起（渲染没走到 request_log 读？）；server.log：' . probe_tail($logFile)]);
}

// ② GET B：A 挂起期间，B 在服务器上整页渲染完（真 TCP、真事件循环）
$stage = 'b_render';
$connB = $send('/xhprof?run=' . $idB . '&source=xhprof_foo&all=1&lang=zh-CN&sort=fn');
$bodyB = $connB === null ? '' : $readBody($connB);
$atBDone = $status();

// ③ /__go：这才把「Redis 回包」写上，A 被唤醒
$stage = 'release_a';
$connGo = $send('/__go');
$goBody = $connGo === null ? '' : $readBody($connGo);

// ④ 读 A 的回包（①那条连接）
$stage = 'a_read';
$bodyA = $readBody($connA);

$stage = 'poll_after_resume';
$deadline = microtime(true) + 5.0;
$after = $status();
while (($after['resumes'] ?? 0) < 1 && microtime(true) < $deadline) {
    usleep(20_000);
    $after = $status();
}

$stage = 'done';
$finish([
    'server' => [
        'php' => PHP_VERSION,
        'workerman' => \Composer\InstalledVersions::getPrettyVersion('workerman/workerman'),
        'coroutine' => \Composer\InstalledVersions::getPrettyVersion('workerman/coroutine'),
        'event_loop_class' => \Workerman\Events\Fiber::class,
        'port' => $port,
    ],
    'suspends' => $after['suspends'] ?? 0,
    'resumes_at_b_done' => $atBDone['resumes'] ?? -1,
    'resumes_at_end' => $after['resumes'] ?? -1,
    'run_a' => $idA,
    'run_b' => $idB,
    'obs' => $after['obs'] ?? [],
    'go_answered' => str_contains($goBody, 'released'),
    'bytes_a' => strlen($bodyA),
    'bytes_b' => strlen($bodyB),
    'rows_a' => probe_flat_rows($bodyA),
    'rows_b' => probe_flat_rows($bodyB),
    'lang_a' => probe_html_lang($bodyA),
    'lang_b' => probe_html_lang($bodyB),
    'pmu_in_a' => str_contains($bodyA, 'pmu'),
    'pmu_in_b' => str_contains($bodyB, 'pmu'),
    'log_tail' => '',
]);

/** 诊断用：子进程日志的最后几行（出错时才进 JSON）。 */
function probe_tail(string $file, int $lines = 12): string
{
    if (!is_file($file)) {
        return '（没有 server.log）';
    }
    $all = explode("\n", (string) file_get_contents($file));

    return trim(implode(' | ', array_slice($all, -$lines)));
}
