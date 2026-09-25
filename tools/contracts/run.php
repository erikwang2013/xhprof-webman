<?php

declare(strict_types=1);

/**
 * 契约验证环总入口。
 *
 *   php tools/contracts/run.php                  # 主腿：cases/*.php 全部，用 tools/contracts/vendor
 *   php tools/contracts/run.php --leg=symfony64  # 第二腿：只有 cases/Symfony.php，用
 *                                                #   tools/contracts/legacy-symfony64/vendor（真 Symfony 6.4）
 *
 * 第二腿的存在理由：README 声明 Symfony 支持 `^6.4|^7.0`，但主环一个 composer 项目装不下两版
 * （laravel/framework v11 要求 symfony/http-foundation ^7.2）。所以第二条腿是**独立嵌套项目**，
 * 只装 Symfony 6.4，并用 `--leg` 把 vendor 根换过去 —— **不复制 case 文件**（实现见
 * lib/proc.php 的 contracts_dir()：子进程通过 CONTRACTS_DIR_OVERRIDE 收到镜像目录）。
 * 装依赖：`composer install -d tools/contracts/legacy-symfony64`。
 * 腿名拼错/未知参数 = 直接报错退出（exit 2），**不回落到主腿** —— 静默回落等于少跑一条腿
 * 还以为跑了，正是这个开关最容易骗人的方式。
 *
 * 每个 case 起一个**独立 PHP 子进程**（case-runner.php）：加载期 fatal 不可 catch，
 * 只有进程隔离才能让一个 case 崩溃不掩盖其他 case 的结论。
 *
 * 三道硬断言，都是为了堵住"悄悄让检查通过"：
 *  1) 任一 FAIL → exit 1。
 *  2) SKIP 总数必须**恰好**等于该腿的冻结常量 → 不等则 exit 1。
 *     没有第 2 条，"验不过就标 skip"能让环全绿地什么都不证明。
 *  3) 腿声明的版本身份要能被机器核对（见 LEGS 的 lock_versions）→ 不符则 exit 2。
 *     没有第 3 条，第二腿的 vendor 一旦被换成 7.x，它就悄悄变成主腿的副本、全绿。
 * 退出码：0 = OK，1 = FAIL 或 SKIP-MISMATCH，2 = 参数/腿配置错（还没跑任何 case）。
 */

require_once __DIR__ . '/lib/proc.php';

/**
 * 冻结常量：SKIP 总数必须**恰好**等于它。
 *
 * 含义：环里有 N 个子项被诚实标注为「本环结构上验不了」。改这个数字要经过人眼，
 * 这正是重点 —— SKIP 是一次需要签字的决定，不是一句注释。
 *
 * ---------------------------------------------------------------------------
 * 2026-09-25 签字：2（全部落在 Joomla 的 `Joomla\CMS\*` 一层），逐条审核如下。
 * 同日变更留档：9 → 8（WordPress 解冻）→ 2（Drupal 全部解冻、Joomla 再解冻三条）。
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
 *   **同日再解冻 L3（2026-09-25，仍 0 SKIP）**：真引导的 WordPress 站点跑得起来 —— 真核心 +
 *   官方 SQLite drop-in + 真 `wp_install()`，**无 MySQL、无假 $wpdb**，29 项断言钉住
 *   mu-plugin 是否被加载、`plugins_loaded` 的时点、致命错误下 `shutdown` 是否触发。
 *   诚实边界（不是 SKIP，是另一层已有覆盖）：CLI 下 `header()` 是 no-op、`headers_list()` 恒空，
 *   「头真的发出去了吗」在契约环里不可观测；那一半由 `tests/Unit/Adapter/WordpressTest.php`
 *   的真 `php -S` 往返覆盖（含 `default_mimetype`/`default_charset` 扰动）。本卡只钉可观测的
 *   那一半：真 `status_header()` 收到的状态码与状态行字面量（经真过滤器）、`send()` 的 echo 体。
 * Drupal 0 ｜ 三条**全部解冻（2026-09-25，3 → 0）**：装了真 `drupal/core` 11.4.7，
 *   `config.factory`/`logger.factory`/`StackedHttpKernel`/`StackedKernelPass` 全用真包；
 *   priority 是**实测值**（core 非测试模块最高 `http_middleware` 优先级 = 500，
 *   `http_middleware.ajax_page_state`）；桩只在「桩未覆盖的真包方法」那一项里作为**对照物**
 *   加载（54 个，只记数不上红）。原先「要编译容器才验得了服务串接」的替代证据也保留在卡里。
 * Joomla 2 ｜ 仍不可验证的两条（Joomla\CMS\* 层，见 `cases/Joomla.php` 的
 *   `JOOMLA_SKIPS_DECLARED` 与 §11/§11c）：
 *     [A] `#__extensions.params` 的真实读取路径（`PluginHelper::getPlugin()` → `bootPlugin()`）；
 *     [B] 安装器形态（namespacemap 被写过、`bootPlugin()` 找得到类）。
 *   两条的抛点都在**干净子进程里量过**，不是散文结论。原先的 SKIP 1/2/5（CMS 类语义、
 *   裸名分发点、4.4 vs 5.x 构造器按引用）已解冻成真断言 —— 两个真实 CMS 发布包
 *   （5.2.2 / 4.4.14，composer 钉死版本）全程参与。`Joomla\Input\Input`/`Registry`/
 *   `UriHelper`/`Event\Dispatcher` 也一直是真 L2。
 *
 * 注意 EXPECTED_SKIPS 必须与环境无关：Joomla 的 case 把**环境缺件**也计入 SKIP（诚实做法，
 * 好过静默通过）—— 缺 ext-xhprof 漂移 +`JOOMLA_EXT_CHECKS_DECLARED`、缺 ext-redis 漂移
 * +`JOOMLA_REDIS_CHECKS_DECLARED`（`cases/Joomla.php:281`/`:288`，**以卡内常量为准**：两者
 * 的实际条数在 §6/§9 另有自检，漂移即 FAIL。按当前值 10 / 8，两个都缺是 2+10+8=20）。
 * 故 contracts.yml **必须同时装 xhprof 与 redis**（`.github/workflows/contracts.yml:69`）
 * —— 否则这个常量随环境漂移，这道签字闸门就失去意义。
 */
const EXPECTED_SKIPS = 2;

/**
 * 第二腿（symfony64）的冻结常量：**0**。
 *
 * 这条腿只跑 Symfony 一个 case，而它在装了 ext-xhprof 的环境里一条都不跳（36 条采样断言
 * 全真跑，mime 在不在都各有真断言，没有 SKIP 分支）。所以这个 0 不是"没事可跳"，而是闸门：
 * 一旦这条腿上冒出任何 SKIP（最典型的是 runner 忘了装 xhprof → 36），就与冻结期望不符而红。
 * 与主腿的 EXPECTED_SKIPS 同一条规矩：SKIP 是一次要签字的决定，且必须与环境无关。
 *
 * 2026-09-25 签字：0 —— 逐条核对 Symfony.php 的 skips 来源只有 $extDeclared(=36)，
 * 且带扩展时 $extChecks 会自检是否漂移，不存在"环境一变数字就变"的路径。
 */
const EXPECTED_SKIPS_SYMFONY64 = 0;

/**
 * 腿表：腿名 => [vendor 根目录（null = 本目录）, 要跑的 case 文件名（null = 全部）, 冻结 SKIP, 身份核对]。
 *
 * 6.4 腿**只跑 Symfony.php** 是刻意的：别的 case 与 Symfony 的版本无关，主腿已经用各自的
 * 最新包跑过；把它们拖进这条腿只会让这条腿多装一堆与版本矩阵无关的包，且镜像目录里没有
 * lib/（见 lib/proc.php 的上限）。反过来说，Symfony.php 是唯一能满足"contracts_dir() 只
 * 取 vendor/"的 case —— 别顺手把 Psr7.php 之类加进来。
 *
 * lock_versions：腿是"某条版本线"这个说法必须机器可核对，否则 vendor 被换成 7.x 时这条腿
 * 会静默变成主腿的副本（全绿、零信息）。核对的是**腿自己的 composer.lock**（CI 与本地都
 * install 自它），前缀匹配到小版本（"6.4." 而不是 "6.4" —— 后者会放过 6.40）。
 */
const LEGS = [
    'main' => [
        'dir' => null,
        'cases' => null,
        'skips' => EXPECTED_SKIPS,
        'lock_versions' => [],
    ],
    'symfony64' => [
        'dir' => 'legacy-symfony64',
        'cases' => ['Symfony.php'],
        'skips' => EXPECTED_SKIPS_SYMFONY64,
        'lock_versions' => [
            'symfony/event-dispatcher' => '6.4.',
            'symfony/http-foundation' => '6.4.',
            'symfony/http-kernel' => '6.4.',
        ],
    ],
];

/**
 * 核对腿的版本身份。返回错误说明，或 null 表示没问题。
 *
 * 读腿自己的 composer.lock（不加载真实包、不起子进程）：lock 是 CI 与本地共用的那一份，
 * 所以这是"这条腿装的是什么"最直接的证据。
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

// ---- 选腿：参数 → 腿表 → vendor 根 → case 清单 ----
$legName = 'main';
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (!str_starts_with((string) $arg, '--leg=')) {
        fwrite(STDERR, "::error::run.php 不认识的参数 {$arg}；只支持 --leg=<腿名>（已知："
            . implode(', ', array_keys(LEGS)) . "），用法见本文件顶部\n");
        exit(2);
    }
    $legName = substr((string) $arg, 6);
}

if (!isset(LEGS[$legName])) {
    fwrite(STDERR, '::error::未知的腿 ' . var_export($legName, true) . '；已知：' . implode(', ', array_keys(LEGS))
        . "（**不**回落到主腿：静默回落等于少跑一条腿还以为跑了）\n");
    exit(2);
}

$leg = LEGS[$legName];
$legDir = $leg['dir'] === null ? __DIR__ : __DIR__ . '/' . $leg['dir'];

if ($leg['dir'] === null) {
    // 显式清掉：跑哪条腿只由 --leg 决定，不随调用者 shell 里的残留环境变量漂移。
    putenv('CONTRACTS_DIR_OVERRIDE');
} else {
    if (!is_file($legDir . '/vendor/autoload.php')) {
        fwrite(STDERR, "::error::腿 {$legName} 的 vendor 没装：先跑 composer install -d tools/contracts/{$leg['dir']}\n");
        exit(2);
    }
    $identityError = contracts_leg_identity_error($legName, $legDir, $leg['lock_versions']);
    if ($identityError !== null) {
        fwrite(STDERR, '::error::' . $identityError . "\n");
        exit(2);
    }
    // 子进程（case-runner → case）靠它决定 contracts_dir() 返回哪 —— 见 lib/proc.php。
    // proc_open 的 env 传 null（不替换环境），所以 putenv 会一路传到 case 自己起的子进程。
    putenv('CONTRACTS_DIR_OVERRIDE=' . $legDir);
}

if ($leg['cases'] === null) {
    $cases = glob(__DIR__ . '/cases/*.php') ?: [];
    sort($cases);
} else {
    $cases = [];
    foreach ($leg['cases'] as $file) {
        $path = __DIR__ . '/cases/' . $file;
        if (!is_file($path)) {
            fwrite(STDERR, "::error::腿 {$legName} 要跑的 case 不存在：cases/{$file}"
                . "（腿定义与文件脱节就该挂，不能让一条空腿全绿）\n");
            exit(2);
        }
        $cases[] = $path;
    }
}

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

echo "契约验证环（每 case 一个独立子进程）\n";
echo "腿：{$legName}    vendor：{$legDir}/vendor\n\n";
$width = max(array_map(static fn (array $r): int => strlen($r[0]), $rows));
printf("%-{$width}s  %-6s  %s\n", 'case', 'status', 'detail');
printf("%s\n", str_repeat('-', $width + 8 + 60));
foreach ($rows as [$name, $status, $detail]) {
    printf("%-{$width}s  %-6s  %s\n", $name, $status, $detail);
}
printf("\nPASS %d  FAIL %d  SKIP %d（腿 %s 的冻结期望 %d）\n", $passed, $failed, $skipped, $legName, $leg['skips']);

// 「环红」有**两种互不相同**的成因，而 CI 只看得到退出码，所以必须把结论说清楚：
//   - FAIL          某个 case 自己判定失败 —— 真正的失败，要去看那一行
//   - SKIP-MISMATCH 所有 case 都没失败，只是有 case 诚实标注的「不可验证子项」数与
//                   冻结常量不符 —— 这是**一次需要签字的决定**，不是失败
// 两者都非零退出（都不该静默放行），但含义完全不同；混在一起会把人引去查错方向。
$verdict = $failed > 0 ? 'FAIL' : ($skipped !== $leg['skips'] ? 'SKIP-MISMATCH' : 'OK');
printf(
    "RESULT: %s%s\n",
    $verdict,
    $verdict === 'SKIP-MISMATCH' ? '（没有 case 失败；是 SKIP 数需要签字）' : ''
);

if ($failed > 0) {
    fwrite(STDERR, "::error::契约验证环有 {$failed} 个 case FAIL —— 看上面哪一行的 status 是 FAIL\n");
    exit(1);
}

if ($skipped !== $leg['skips']) {
    fwrite(
        STDERR,
        "::error::SKIP-MISMATCH：**没有 case 失败**，但有 case 诚实标注的不可验证子项数变了"
        . "（腿 {$legName}：{$skipped} vs 冻结常量 {$leg['skips']}）。"
        . "含义是「验证覆盖面变了」，需要人工**逐个审核每个 SKIP 的理由是否成立**后签字更新"
        . "该腿的常量，而不是当成失败去修。"
        . "反过来也一样：缺一个 SKIP 等于少签一个字，不要为了让它变绿而少报。\n"
    );
    exit(1);
}

exit(0);
