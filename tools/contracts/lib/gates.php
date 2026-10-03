<?php

declare(strict_types=1);

/**
 * 契约环三道硬闸门的**判定逻辑**（从 run.php 搬出来）。
 *
 * 为什么搬家：这三道闸门此前从没被负路径测过 —— 而本仓库的规矩是「检查必须被证明会红」。
 * run.php 是脚本，require 它就会把整环跑起来（需要 Redis 与已装的 vendor），所以判定逻辑
 * 必须住在一个**能被单测直接 require 的纯函数文件**里：tests/Unit/Contracts/GatesTest.php。
 *
 * 本文件的纪律：只放判定，不放 IO 之外的行为、不放 exit、不 require 任何东西。
 * `contracts_leg_identity_error()` 里读 composer.lock 是有意的 IO（测它要写真文件，
 * 也正是它的语义），除此之外三个函数是纯函数。
 *
 * run.php 的对外行为（stdout/stderr 文本、退出码 0/1/2）不因这次搬家改变一个字 ——
 * 搬家只准移动逻辑，不准改语义。
 */

/**
 * 核对腿的版本身份。返回错误说明，或 null 表示没问题。
 *
 * 读腿自己的 composer.lock（不加载真实包、不起子进程）：lock 是 CI 与本地共用的那一份，
 * 所以这是「这条腿装的是什么」最直接的证据。
 */
function contracts_leg_identity_error(string $legName, string $legDir, array $expect): ?string
{
    $lock = $legDir . '/composer.lock';
    if (!is_file($lock)) {
        return "腿 {$legName} 没有 composer.lock（{$lock}）—— 没有 lock 的腿每次跑到的是"
            . '"当前最新"，一道会无故变红的闸门等于一道会被忽略的闸门';
    }
    $decoded = json_decode((string) file_get_contents($lock), true);
    if (!is_array($decoded) || !is_array($decoded['packages'] ?? null)) {
        return "腿 {$legName} 的 composer.lock 不是合法 JSON 或没有 packages";
    }
    $installed = [];
    foreach ($decoded['packages'] as $package) {
        $installed[(string) ($package['name'] ?? '')] = (string) ($package['version'] ?? '');
    }

    $problems = [];
    foreach ($expect as $package => $prefix) {
        $version = $installed[$package] ?? null;
        if ($version === null) {
            $problems[] = "{$package} 不在 lock 里";
        } elseif (!str_starts_with(ltrim($version, 'v'), $prefix)) {
            $problems[] = "{$package} 是 {$version}，期望 {$prefix}x";
        }
    }

    return $problems === [] ? null : "腿 {$legName} 的版本身份不对：" . implode('；', $problems);
}

/**
 * 单个 case 计入 SKIP 总数的条数（run.php 汇总循环的规则）。
 *
 * SKIP 状态的 case **至少记 1 条**：否则「把整个 case 标成 SKIP 且 skips=0」就能绕过
 * run.php 的冻结常量断言 —— 那道断言正是「验不过就标 skip」的堵口。
 * 非 SKIP 状态可以带子项 skip 数（PASS 也可能诚实地跳了若干子项），但不许为负。
 */
function contracts_case_skips(string $status, int $declaredSkips): int
{
    return $status === 'SKIP' ? max(1, $declaredSkips) : max(0, $declaredSkips);
}

/**
 * 环的终审判决：'FAIL' | 'SKIP-MISMATCH' | 'OK'。
 *
 * FAIL 优先于 SKIP-MISMATCH：真有 case 失败时，SKIP 数对不对没人关心，
 * 「看哪一行 FAIL」才是该引导人去看的结论（两者都非零退出，含义完全不同，
 * 混在一起会把人引去查错方向）。SKIP 必须**恰好**等于冻结常量：多了是覆盖面变了，
 * 少了等于少签一个字 —— 两个方向都判不符。
 */
function contracts_verdict(int $failed, int $skipped, int $expectedSkips): string
{
    if ($failed > 0) {
        return 'FAIL';
    }

    return $skipped !== $expectedSkips ? 'SKIP-MISMATCH' : 'OK';
}

/**
 * 判决 → 进程退出码。0 只给恰好 'OK' 一种情形（fail-closed：任何不认识的字面量
 * 都不许静默变绿）。
 */
function contracts_verdict_exit_code(string $verdict): int
{
    return $verdict === 'OK' ? 0 : 1;
}
