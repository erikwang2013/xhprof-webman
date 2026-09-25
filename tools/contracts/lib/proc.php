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

/** tools/contracts 的绝对路径。 */
function contracts_dir(): string
{
    return dirname(__DIR__);
}

/** 仓库根：tools/contracts 往上两级。 */
function contracts_repo_root(): string
{
    return dirname(contracts_dir(), 2);
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
