<?php

declare(strict_types=1);

/**
 * 契约环公用的子进程执行器。
 *
 * 用 proc_open 的 **argv 数组**形式（PHP >= 8.0）而不是 shell 字符串：不经 shell，
 * 也就没有转义 / 注入 / 引号丢失这类问题，`php -r` 塞多行脚本才安全。
 *
 * 已知上限：stdout / stderr 是顺序读的，子进程若把管道写满会死锁。
 * 本环的每个子进程只输出几 KB（JSON / 反射快照），够用；真遇到大输出再换 stream_select。
 */

/**
 * tools/contracts 的绝对路径。
 *
 * 默认就是本文件所在目录的上一层。`run.php --leg=` 会通过环境变量
 * CONTRACTS_DIR_OVERRIDE 把它换成**镜像目录**（例如 tools/contracts/legacy-symfony64）：
 * 那里有自己的 vendor/，于是 case 里所有 `contracts_dir() . '/vendor/...'` 的取用都落到
 * 另一套真实包上 —— 同一份 case 文件跑第二条腿靠的就是这一处，不复制 case。
 *
 * 上限（写清楚免得后人误用）：镜像目录得是个**只以 vendor/ 为契约**的目录。本环目前
 * 只有 Symfony.php 满足（它对 contracts_dir() 的用法只有 vendor/autoload.php 一处）；
 * Psr7.php 还要 `contracts_dir() . '/lib/dump.php'`、Wordpress.php 要
 * `vendor/roots/wordpress-no-content` 之外的目录结构，那些 case 不能进第二条腿
 * （run.php 的腿表里逐腿列了 case，不是按 glob 跑）。
 */
function contracts_dir(): string
{
    $override = getenv('CONTRACTS_DIR_OVERRIDE');

    return is_string($override) && $override !== '' ? $override : dirname(__DIR__);
}

/**
 * 仓库根：tools/contracts 往上两级。
 *
 * 刻意写 dirname(__DIR__, 3) 而不是 dirname(contracts_dir(), 2)：后者会被上面那个覆盖
 * 带偏 —— 镜像目录在 tools/contracts/ **下一层**，从它往上两级只到 tools/，而 case 拿
 * 这个路径去找 src/ 与 tests/Fixtures（见 Symfony.php 的 $repoRoot），那些永远在真仓库里。
 * （层数是 3 不是 2：这里的 __DIR__ 是 lib/，lib → contracts → tools → 仓库根。）
 * 这一条不是理论：第一版写成 dirname(__DIR__, 2) 时，6.4 腿立刻红在
 * `Failed opening required .../tools/tests/Fixtures/Fakes.php` —— 覆盖只该换 vendor，
 * 不该动仓库根，这两个 helper 必须各管各的。
 */
function contracts_repo_root(): string
{
    return dirname(__DIR__, 3);
}

/**
 * 起一个独立的 PHP 子进程并收齐输出。
 *
 * @param list<string> $args PHP 之后的参数，例如 ['-r', $code] 或 ['case-runner.php', $case]
 *
 * @return array{code:int, stdout:string, stderr:string}
 */
function contracts_run_php(array $args): array
{
    // display_errors=stderr：警告 / fatal 一律去 stderr，子进程的 stdout 只留结果本身。
    // 否则一条 PHP warning 就能把要 json_decode 的 stdout 弄脏，而"解析失败"会被误读成
    // "case 崩溃"——归因错了比没检查更糟。
    $cmd = array_merge(
        [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL'],
        $args
    );

    $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return ['code' => -1, 'stdout' => '', 'stderr' => 'proc_open 失败'];
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
}
