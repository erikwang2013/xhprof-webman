<?php

declare(strict_types=1);

/**
 * 单 case 执行器 —— **接口冻结**，Wave 1 起六个框架各写一个 case 都接这个协议。
 *
 * 用法：`php case-runner.php <case 文件绝对路径>`
 * stdout：一行 JSON `{"status":"PASS|FAIL|SKIP","detail":"...","skips":N}`
 * 退出码：0 = PASS，非 0 = 非 PASS。
 *
 * case 文件的契约：`return` 一个 callable；调用它须返回
 *   ['status' => 'PASS'|'FAIL'|'SKIP', 'detail' => string, 'skips' => int]
 * `skips` 是本 case 内部「诚实标为不可验证」的子项数（不是 case 个数）。
 * case 里可以直接用 `contracts_run_php()` / `contracts_dir()` / `contracts_repo_root()`
 * （本文件已 require lib/proc.php），用来起它自己的子进程。
 *
 * 为什么 case 必须由**独立子进程**跑（即本文件由 run.php 拉起，而不是被 run.php require）：
 * 加载期 fatal（`implements` 了不存在的接口之类）不可 catch，只有进程隔离才能让一个
 * case 崩溃不掩盖其他 case 的结论。同理，case 内部要反射真实 PSR 包时也必须自己再起子进程。
 */

require_once __DIR__ . '/lib/proc.php';

$case = $argv[1] ?? '';

$result = ['status' => 'FAIL', 'detail' => '', 'skips' => 0];

if ($case === '' || !is_file($case)) {
    $result['detail'] = "case 文件不存在：{$case}";
} else {
    try {
        $caseRunner = require $case;

        if (!is_callable($caseRunner)) {
            $result['detail'] = 'case 必须 return 一个 callable';
        } else {
            $outcome = $caseRunner();

            if (!is_array($outcome)
                || !isset($outcome['status'])
                || !in_array($outcome['status'], ['PASS', 'FAIL', 'SKIP'], true)
            ) {
                $result['detail'] = 'case 返回值不是合法三态：' . json_encode($outcome, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } else {
                $result['status'] = $outcome['status'];
                $result['detail'] = (string) ($outcome['detail'] ?? '');
                $result['skips'] = max(0, (int) ($outcome['skips'] ?? 0));
            }
        }
    } catch (\Throwable $e) {
        $result['detail'] = get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . str_replace(contracts_repo_root() . '/', '', $e->getFile()) . ':' . $e->getLine();
    }
}

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";

exit($result['status'] === 'PASS' ? 0 : 1);
