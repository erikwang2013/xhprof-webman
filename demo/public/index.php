<?php

declare(strict_types=1);

/*
 * xhprof-webman 演示的「被分析的应用」——一个原生 PHP 前端控制器（无框架）。
 *
 * 接入方式是 Native 入口类的唯一一行（README「原生 PHP（无框架）」一节）：
 * 采样窗口 = 这一行 → 进程结束（register_shutdown_function）。
 * 这一行之前的代码（composer autoload）不在窗口里，所以 start() 之前只有 require。
 *
 * 报告页 /xhprof 与资源路径 /xhprof-assets/* 由这一行自己接管（命中即 exit），
 * 原生应用不需要写任何路由。
 */

require __DIR__ . '/../vendor/autoload.php';

use ErikWang2013\Xhprof\Native\XhprofBootstrap;

XhprofBootstrap::start([
    // 只覆盖 Redis 地址；其余配置用包内默认值
    // （src/Native/config/xhprof.php：全采、不鉴权、key 前缀 xhprof、保留 7 天）。
    // 默认值 `redis` 就是 compose 里的服务名；不用 docker、直接 php -S 跑时，
    // 用环境变量指到本机：XHPROF_DEMO_REDIS_HOST=127.0.0.1 php -S ...
    'redis' => ['host' => getenv('XHPROF_DEMO_REDIS_HOST') ?: 'redis'],
]);

// ---------------------------------------------------------------------------
// 下面就是「被测的应用」：三段人为耗时的工作，让报告页有东西可看。
// ---------------------------------------------------------------------------

/** 递归斐波那契：制造一眼可见的调用次数与调用栈深度。 */
function fib(int $n): int
{
    return $n < 2 ? $n : fib($n - 1) + fib($n - 2);
}

/** 字符串拼接 + md5：制造扁平、稳定的一层 CPU 开销。 */
function buildString(int $rounds): int
{
    $s = '';
    for ($i = 0; $i < $rounds; $i++) {
        $s .= md5((string) $i);
    }

    return strlen($s);
}

$start = microtime(true);

$fib = fib(23);
$stringLength = buildString(20000);

// 等待：只计入整条请求的墙钟时间。usleep 是内建函数，而本包用
// XHPROF_FLAGS_NO_BUILTINS 启用采样（只采集用户代码），所以它不会出现在函数列表里
// ——整条请求的 wt 里却含着这 20ms。报告页因此看不到它，这是包的有意行为。
usleep(20000);

$elapsedMs = (microtime(true) - $start) * 1000;

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <title>xhprof-webman 演示</title>
    <style>
        body { font: 15px/1.7 system-ui, -apple-system, "PingFang SC", "Microsoft YaHei", sans-serif;
               max-width: 42em; margin: 3em auto; padding: 0 1em; color: #222; }
        code { background: #f2f2f2; padding: .1em .35em; border-radius: 3px; }
        .btn { display: inline-block; margin-top: .5em; padding: .55em 1.1em; border-radius: 6px;
               background: #2f6fdd; color: #fff; text-decoration: none; }
        .btn:hover { background: #245bc0; }
        .hint { color: #666; font-size: 14px; }
    </style>
</head>
<body>
<h1>xhprof-webman 演示</h1>
<p>本次请求跑完了三段人为耗时的工作，并且已经被 xhprof 采样：</p>
<ul>
    <li>递归 <code>fib(23)</code> → <?= $fib ?>（约 9.3 万次函数调用）</li>
    <li>字符串拼接 20000 轮 → 长度 <?= $stringLength ?></li>
    <li><code>usleep(20000)</code> → 只计入整条请求的墙钟时间（见下）</li>
</ul>
<p>本次墙钟耗时约 <strong><?= number_format($elapsedMs, 1) ?> ms</strong>。</p>
<p><a class="btn" href="/xhprof">打开 /xhprof 看报告页 →</a></p>
<p class="hint">多刷新几次本页（或 curl 打几次），报告列表里就会多几条 run —— 采样在每次请求结束时落进 Redis。
    函数列表里只会看到 <code>fib</code> / <code>buildString</code> 这些你自己写的代码：本包启用采样时带了
    <code>XHPROF_FLAGS_NO_BUILTINS</code>，内建函数（<code>usleep</code> / <code>md5</code> 这类）不采集。</p>
</body>
</html>
