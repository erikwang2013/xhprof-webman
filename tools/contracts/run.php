<?php

declare(strict_types=1);

/**
 * 契约验证环总入口。`php tools/contracts/run.php`
 *
 * 每个 case 起一个**独立 PHP 子进程**（case-runner.php）：加载期 fatal 不可 catch，
 * 只有进程隔离才能让一个 case 崩溃不掩盖其他 case 的结论。
 *
 * 两道硬断言，都是为了堵住"悄悄让检查通过"：
 *  1) 任一 FAIL → exit 1。
 *  2) SKIP 总数必须**恰好**等于 EXPECTED_SKIPS → 不等则 exit 1。
 *     没有第 2 条，"验不过就标 skip"能让环全绿地什么都不证明。
 */

require_once __DIR__ . '/lib/proc.php';

/**
 * 冻结常量：SKIP 总数必须**恰好**等于它。
 *
 * 含义：环里有 N 个子项被诚实标注为「本环结构上验不了」。改这个数字要经过人眼，
 * 这正是重点 —— SKIP 是一次需要签字的决定，不是一句注释。
 *
 * ---------------------------------------------------------------------------
 * 2026-09-25 签字：8（Drupal 3 + Joomla 5），逐条审核如下。
 * 同日变更：WordPress 的 1 个 SKIP **解冻**，见下面那条留档。
 * ---------------------------------------------------------------------------
 * WordPress 0 ｜ L2 适配器语义 —— 2026-09-25 **解冻（9 → 8）**。原理由是「php-stubs 的函数体
 *   是空的，加载它跑适配器只能得到 null；真 WP 需完整安装 + DB + wp-settings.php 引导」。
 *   前半句实测成立（`wordpress-stubs.php:131565` 的 `function wp_unslash($value) {}`、
 *   `:143461` 的 `function is_ssl() {}`），但**结论推错了**：要用真语义不必装站 —— 真
 *   `wp-includes/{plugin,load,formatting,functions}.php` 只要定义 `ABSPATH`/`WPINC` 就能
 *   独立包含，无 DB、无 wp-config.php、无引导（本机实测 WordPress 6.9.9：4 个文件 15ms）。
 *   里面的语义是真的：`wp_unslash()` 走 `stripslashes_deep()`（递归 + 非字符串透传）、
 *   `is_ssl()` 是真分支表（且**完全不看** X-Forwarded-Proto），`status_header()` 过真
 *   `apply_filters('status_header', ...)`，`add_action()` 背后是真的 `WP_Hook`（PHP_INT_MIN
 *   优先级被原样保留）。故该卡改为 **L2-lite：真 WP 核心源码 + 真适配器**，39 项断言、0 SKIP；
 *   依赖 `roots/wordpress-no-content`（composer，`^6.9`，实测 6.9.9），缺包时 FAIL 不 SKIP。
 *   诚实边界（不是 SKIP，是另一层已有覆盖）：CLI 下 `header()` 是 no-op、`headers_list()` 恒空，
 *   「头真的发出去了吗」在契约环里不可观测；那一半由 `tests/Unit/Adapter/WordpressTest.php`
 *   的真 `php -S` 往返覆盖（含 `default_mimetype`/`default_charset` 扰动）。本卡只钉可观测的
 *   那一半：真 `status_header()` 收到的状态码与状态行字面量（经真过滤器）、`send()` 的 echo 体。
 * Drupal 3 ｜ ① ConfigAdapter 的真实 config.factory/ImmutableConfig 要 booted kernel +
 *   配置存储；② 服务串接（http_middleware 标签 / priority 是否真最外 / 内层 kernel 注入）
 *   要编译容器；③ Drupal\Core\* 的桩忠实性无法自证。②的替代证据是该卡**跨 13 个版本
 *   独立复核** StackedKernelPass 的源码结论（并因此拦下一次「整站白屏」级错误）。
 * Joomla 5 ｜ 全部落在 `Joomla\CMS\*` 这一层：无任何 composer 包提供（该卡用干净子进程
 *   实测 6 个触碰点全 class_exists=false，**并加了正对照**防「假阴性」），只能用桩代跑。
 *   `Joomla\Input\Input`/`Registry`/`UriHelper`/`Event\Dispatcher` 是**真 L2，52 项断言、0 SKIP**。
 *
 * 注意 EXPECTED_SKIPS 必须与环境无关：Joomla 的 case 在**缺 ext-xhprof** 时会把 4 条
 * 采样断言计入 SKIP（诚实做法，好过静默通过），总数会变成 13。故 contracts.yml
 * **必须装 xhprof** —— 否则这个常量随环境漂移，这道签字闸门就失去意义。
 */
const EXPECTED_SKIPS = 8;

$cases = glob(__DIR__ . '/cases/*.php') ?: [];
sort($cases);

if ($cases === []) {
    fwrite(STDERR, "::error::tools/contracts/cases/ 里一个 case 都没有——空环会全绿，必须挂\n");
    exit(1);
}

$rows = [];
$passed = 0;
$failed = 0;
$skipped = 0;

foreach ($cases as $case) {
    $name = basename($case, '.php');
    $run = contracts_run_php([__DIR__ . '/case-runner.php', $case]);
    $decoded = json_decode(trim($run['stdout']), true);

    if (!is_array($decoded) || !in_array($decoded['status'] ?? null, ['PASS', 'FAIL', 'SKIP'], true)) {
        // 子进程没吐出合法 JSON，通常就是加载期 fatal。原文照抄，不猜。
        $rows[] = [$name, 'FAIL', 'case-runner 未输出合法 JSON（exit ' . $run['code'] . '）：'
            . trim(($run['stderr'] ?? '') !== '' ? $run['stderr'] : $run['stdout'])];
        $failed++;
        continue;
    }

    $status = $decoded['status'];
    $caseSkips = max(0, (int) ($decoded['skips'] ?? 0));

    if ($status === 'SKIP') {
        // 整个 case 标 SKIP 时至少记 1 次——否则"把整个 case 标成 SKIP 且 skips=0"
        // 就能绕过下面那条断言。
        $caseSkips = max(1, $caseSkips);
    }
    $skipped += $caseSkips;

    if ($status === 'FAIL') {
        $failed++;
    } elseif ($status === 'PASS') {
        $passed++;
    }

    $rows[] = [$name, $status, (string) ($decoded['detail'] ?? '')];
}

echo "契约验证环（每 case 一个独立子进程）\n\n";
$width = max(array_map(static fn (array $r): int => strlen($r[0]), $rows));
printf("%-{$width}s  %-6s  %s\n", 'case', 'status', 'detail');
printf("%s\n", str_repeat('-', $width + 8 + 60));
foreach ($rows as [$name, $status, $detail]) {
    printf("%-{$width}s  %-6s  %s\n", $name, $status, $detail);
}
printf("\nPASS %d  FAIL %d  SKIP %d（冻结期望 %d）\n", $passed, $failed, $skipped, EXPECTED_SKIPS);

// 「环红」有**两种互不相同**的成因，而 CI 只看得到退出码，所以必须把结论说清楚：
//   - FAIL          某个 case 自己判定失败 —— 真正的失败，要去看那一行
//   - SKIP-MISMATCH 所有 case 都没失败，只是有 case 诚实标注的「不可验证子项」数与
//                   冻结常量不符 —— 这是**一次需要签字的决定**，不是失败
// 两者都非零退出（都不该静默放行），但含义完全不同；混在一起会把人引去查错方向。
$verdict = $failed > 0 ? 'FAIL' : ($skipped !== EXPECTED_SKIPS ? 'SKIP-MISMATCH' : 'OK');
printf(
    "RESULT: %s%s\n",
    $verdict,
    $verdict === 'SKIP-MISMATCH' ? '（没有 case 失败；是 SKIP 数需要签字）' : ''
);

if ($failed > 0) {
    fwrite(STDERR, "::error::契约验证环有 {$failed} 个 case FAIL —— 看上面哪一行的 status 是 FAIL\n");
    exit(1);
}

if ($skipped !== EXPECTED_SKIPS) {
    fwrite(
        STDERR,
        "::error::SKIP-MISMATCH：**没有 case 失败**，但有 case 诚实标注的不可验证子项数变了"
        . "（{$skipped} vs 冻结常量 " . EXPECTED_SKIPS . '）。'
        . "含义是「验证覆盖面变了」，需要人工**逐个审核每个 SKIP 的理由是否成立**后签字更新 "
        . EXPECTED_SKIPS . '，而不是当成失败去修。'
        . "反过来也一样：缺一个 SKIP 等于少签一个字，不要为了让它变绿而少报。\n"
    );
    exit(1);
}

exit(0);
