<?php

declare(strict_types=1);

/**
 * 契约验证环总入口。
 *
 *   php tools/contracts/run.php                  # 主腿：cases/*.php 全部，用 tools/contracts/vendor
 *   php tools/contracts/run.php --leg=symfony64  # 第二腿：只有 cases/Symfony.php，用
 *                                                #   tools/contracts/legacy-symfony64/vendor（真 Symfony 6.4）
 *   php tools/contracts/run.php --leg=symfony8   # 第三腿：Symfony.php + Laravel.php，用
 *                                                #   tools/contracts/legacy-symfony8/vendor
 *                                                #   （真 Symfony 8.1 + 真 Laravel 13；PHP >= 8.4.1）
 *
 * 第二腿的存在理由：README 声明 Symfony 支持 `^6.4|^7.0`，但主环一个 composer 项目装不下两版
 * （laravel/framework v11 要求 symfony/http-foundation ^7.2）。所以第二条腿是**独立嵌套项目**，
 * 只装 Symfony 6.4，并用 `--leg` 把 vendor 根换过去 —— **不复制 case 文件**（实现见
 * lib/proc.php 的 contracts_dir()：子进程通过 CONTRACTS_DIR_OVERRIDE 收到镜像目录）。
 * 装依赖：`composer install -d tools/contracts/legacy-symfony64`。
 *
 * 第三腿（2026-10-04 新增）的存在理由：Symfony 8 线上有**两个只有真跑才看得见**的差异 ——
 *   * `Request::get()` 被**删掉**（7.4 起 trigger_deprecation、8.0 移除；8.1 的 Request.php
 *     里已无此方法）。本包 src/Laravel/Adapter/RequestAdapter.php:22 正在用它；Laravel 12 的
 *     `Illuminate\Http\Request::get()` 是 `return parent::get(...)`（所以 12 线在 Symfony 8 上
 *     根本不成立，composer 层面就装不出来：它要求 symfony ^7.2）；Laravel 13 把它**内联**成
 *     自己的一份拷贝，于是 13 线在 Symfony 8 上成立。两条线的分界只有真包能钉住。
 *   * `EventSubscriberInterface::getSubscribedEvents()` 8.0 起声明 `array`（6.4/7.x 无返回类型，
 *     8.1.8 实测）—— 这条腿第一次跑就红在这里，是这条腿有判别力的第一个证据。
 * Symfony 8 要求 php >= 8.4.1，所以这条腿在 CI 里跑 PHP 8.5（contracts.yml 的 matrix 逐腿给
 * php；主腿与 6.4 腿仍是 8.3）。Laravel 13 在这条腿上**刻意一起装**：它是上游当前线，主腿
 * （Laravel 12）不覆盖它，而它与 Symfony 8 的组合正是上面那个陷阱的现场。
 * 装依赖：`composer install -d tools/contracts/legacy-symfony8`（本机 PHP >= 8.4.1）。
 * 腿名拼错/未知参数 = 直接报错退出（exit 2），**不回落到主腿** —— 静默回落等于少跑一条腿
 * 还以为跑了，正是这个开关最容易骗人的方式。
 *
 * 每个 case 起一个**独立 PHP 子进程**（case-runner.php）：加载期 fatal 不可 catch，
 * 只有进程隔离才能让一个 case 崩溃不掩盖其他 case 的结论。
 *
 * 四道硬断言，都是为了堵住"悄悄让检查通过"：
 *  1) 任一 FAIL → exit 1。
 *  2) SKIP 总数必须**恰好**等于该腿的冻结常量 → 不等则 exit 1。
 *     没有第 2 条，"验不过就标 skip"能让环全绿地什么都不证明。
 *  3) 每个 PASS 的 case 断言数必须 ≥ 该腿的冻结下限（EXPECTED_ASSERTIONS）→ 掉线则 exit 1。
 *     没有第 3 条，case 里的早退 / 断言被删 / 被包进跑不到的分支（**编辑型缩水**）能全绿
 *     地少验东西 —— 第 2 条防的是"跳过"，防不了"删除"。两条是配对的，理由见下限表头部。
 *  4) 腿声明的版本身份要能被机器核对（见 LEGS 的 lock_versions）→ 不符则 exit 2。
 *     没有第 4 条，第二腿的 vendor 一旦被换成 7.x，它就悄悄变成主腿的副本、全绿。
 * 退出码：0 = OK，1 = FAIL 或 SKIP-MISMATCH 或 ASSERTION-SHRINK，2 = 参数/腿配置错（还没跑任何 case）。
 */

require_once __DIR__ . '/lib/proc.php';
// 三道硬闸门的判定逻辑住在 lib/gates.php，那里能被 tests/Unit/Contracts/GatesTest.php 直接
// require —— 闸门本身必须被负路径测过（见该文件头部）。这里的调用点只做 IO 与输出。
require_once __DIR__ . '/lib/gates.php';

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
 *   独立包含，无 DB、无 wp-config.php、无引导（本机实测 WordPress 7.1.2：4 个文件 31ms；
 *   6.9.9 上是 15ms —— 量级没变，口径不变）。
 *   里面的语义是真的：`wp_unslash()` 走 `stripslashes_deep()`（递归 + 非字符串透传）、
 *   `is_ssl()` 是真分支表（且**完全不看** X-Forwarded-Proto），`status_header()` 过真
 *   `apply_filters('status_header', ...)`，`add_action()` 背后是真的 `WP_Hook`（PHP_INT_MIN
 *   优先级被原样保留）。故该卡改为 **L2-lite：真 WP 核心源码 + 真适配器**，39 项断言、0 SKIP；
 *   依赖 `roots/wordpress-no-content`（composer，`^7.0`，2026-10-04 升档，实测 7.1.2），
 *   缺包时 FAIL 不 SKIP。卡里有一条版本身份断言（核心自报 `$wp_version` = composer 锁版），
 *   所以「升了哪一版」每次都在 PASS/FAIL 文案里写出来，不靠 vendor 目录名猜。
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
 *   （5.4.9 / 4.4.14，composer 钉死版本）全程参与。`Joomla\Input\Input`/`Registry`/
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
 * 第三腿（symfony8）的冻结常量：**0**。
 *
 * 这条腿跑 Symfony.php + Laravel.php 两个 case，两个都是 0 SKIP：
 *   * Symfony.php 的 skips 唯一来源是缺 ext-xhprof（$extDeclared = 36，带扩展时自检漂移）；
 *   * Laravel.php 连 skip 分支都没有 —— 缺 ext-xhprof / ext-redis 时是 FAIL 不是 SKIP
 *     （case 里 "本环前提" 那两条断言）。
 * 所以这个 0 与 6.4 腿的 0 同义：一旦这条腿上冒出任何 SKIP（最典型的是 runner 忘了装
 * xhprof → 36），就与冻结期望不符而红，而不是"没事可跳"。
 *
 * 2026-10-04 签字：0 —— 逐条核对过两个 case 的 skips 来源（如上），都不存在"环境一变
 * 数字就变"的路径；CI 的 contracts.yml 给这条腿同样装 xhprof 与 redis。
 */
const EXPECTED_SKIPS_SYMFONY8 = 0;

/**
 * 冻结：每个 PASS 的 case 的**断言数下限**（逐腿，与 SKIP 常量同一条规矩：要签字、与环境无关）。
 *
 * 防的是一件 SKIP 常量防不了的事 —— **编辑型缩水**：case 里加个早退、把断言包进跑不到的
 * 分支、或直接删掉几段，status 仍是 PASS、SKIP 数也不动，第 2 道闸门看不见。
 * 判据是 `实测断言数 >= 冻结下限`（用 ≥ 不用 == 的理由、以及这条闸门**射程外**的东西，
 * 都写在 lib/gates.php 的 contracts_assertion_floor_errors() 头部）。
 *
 * 值 = 2026-10-04 本机三腿实测（每 case 独立子进程；环境与 contracts.yml 同款：
 * ext-xhprof + ext-redis + 127.0.0.1:6379 上的真 Redis）。数字与各 case 自己在 detail 里
 * 写的是**同一个** `$checks` 计数器，不是另数一套：
 *   * main（12 个）：Drupal 167、Joomla 145、Laravel 109、Psr7 65、Redis 52、Slim 108、
 *     Symfony 232、Thinkphp 231、Webman 160、Wordpress 69、Yii2 134、Yii3 123
 *     （Psr7 的 65 = 9 个接口逐字段比对 + 56 项 fake 行为；Wordpress 的 69 = L2-lite 40 +
 *     L3 29 —— 两家的数字都是照自己 detail 的口径合成的，不另立第二套计数）
 *   * symfony64：Symfony 231（这条腿没装 symfony/mime，走 prepare() 抛 LogicException 的
 *     分支，比有 mime 的腿少 1 条 —— 同一个 case 在不同腿上数字不同是**正常**的，
 *     这正是表要按腿分开的原因）
 *   * symfony8：Symfony 232、Laravel 109（Laravel 13）
 * 量法：把某条腿的表留空（或写个更小的值）跑一次，掉线清单里会带**本次实测条数**，
 * 照抄回表 —— 这次首次冻结就是这么量的；之后调表就是签字动作本身。
 *
 * 与环境无关性（为什么不需要给环境留余量）：几个 case 的断言数是环境门控的（缺 ext-xhprof
 * 就少跑 N 条采样断言），但那 N 条**同时**会被计进 SKIP → 第 2 道闸门先红成 SKIP-MISMATCH。
 * 也就是说下限表只在"SKIP 恰好等于冻结值"的世界里被比较；环境降级时两张表一起响，
 * 判决函数把结论归给 SKIP-MISMATCH（后者是前者的后果），断言数明细照样打印出来。
 * 两条闸门是配对使用的：删掉任一条，另一条就会在环境降级时给出误导性的诊断。
 *
 * 签名：2026-10-04 首次冻结（审计 r4-tools #12 / M-5）。改这张表下沿 = 承认"这些条不再验了"，
 * 与改 SKIP 常量一样要过人的眼睛；表里没有的 case 名字一律算问题（新 case 要签字进环）。
 */
const EXPECTED_ASSERTIONS = [
    'main' => [
        'Drupal' => 167,
        'Joomla' => 145,
        'Laravel' => 109,
        'Psr7' => 65,
        'Redis' => 52,
        'Slim' => 108,
        'Symfony' => 232,
        'Thinkphp' => 231,
        'Webman' => 160,
        'Wordpress' => 69,
        'Yii2' => 134,
        'Yii3' => 123,
    ],
    'symfony64' => ['Symfony' => 231],
    'symfony8' => ['Symfony' => 232, 'Laravel' => 109],
];

/**
 * 腿表：腿名 => [vendor 根目录（null = 本目录）, 要跑的 case 文件名（null = 全部）, 冻结 SKIP, 身份核对]。
 *
 * 6.4 腿**只跑 Symfony.php** 是刻意的：别的 case 与 Symfony 的版本无关，主腿已经用各自的
 * 最新包跑过；把它们拖进这条腿只会让这条腿多装一堆与版本矩阵无关的包，且镜像目录里没有
 * lib/（见 lib/proc.php 的上限）。镜像目录的硬条件是"case 对 contracts_dir() 的用法只有
 * vendor/"：Symfony.php 与 Laravel.php 都满足（Laravel.php 对 contracts_dir() 只有
 * `/vendor/autoload.php` 一处）—— Psr7.php 之类不满足，别顺手加进来。
 *
 * symfony8 腿跑 **Symfony.php + Laravel.php**：它装的是 Symfony 8 + Laravel 13（见文件头），
 * 而 Laravel.php 钉的正是"Laravel 适配器在 Symfony 8 上还能不能跑"——Laravel 12 的
 * `Request::get()` 是 `return parent::get(...)`，在 Symfony 8 上没有 parent 可调；13 内联了
 * 自己的一份拷贝。这个分界只有把真 13 + 真 8 装在一起跑才看得见。Laravel.php 因此**不能**
 * 进 6.4 腿（Laravel 12/13 都要求 symfony >= 7.2，装不进去）。
 *
 * lock_versions：腿是"某条版本线"这个说法必须机器可核对，否则 vendor 被换成 7.x 时这条腿
 * 会静默变成主腿的副本（全绿、零信息）。核对的是**腿自己的 composer.lock**（CI 与本地都
 * install 自它）。
 * 前缀写法逐腿各有理由，不是随便写的：
 *   * 6.4 腿写 "6.4."（不是 "6.4" —— 后者会放过 6.40）：6.4 是 6.x 的**最后一个**小版本线，
 *     不会再出 6.5，所以"这条腿是 6.4"能钉到小版本。
 *   * symfony8 腿写 "8."：8.x 还会继续出小版本（8.0→8.1→…），这条腿的身份是**大版本 8**
 *     （区别于主腿的 7.x），钉到 "8.1." 反而会让 8.2 发布后每次锁更新都变成假红。
 *   * Laravel 侧同理写 "13."（上游当前线，patch 漂移不改身份）。
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
    'symfony8' => [
        'dir' => 'legacy-symfony8',
        'cases' => ['Symfony.php', 'Laravel.php'],
        'skips' => EXPECTED_SKIPS_SYMFONY8,
        'lock_versions' => [
            'laravel/framework' => '13.',
            'symfony/event-dispatcher' => '8.',
            'symfony/http-foundation' => '8.',
            'symfony/http-kernel' => '8.',
        ],
    ],
];

// contracts_leg_identity_error() 已搬去 lib/gates.php（判定要能被单测，见那里的头部注释），
// 下面的调用点不变。

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
/** @var array<string, int> 只有 PASS 的 case 进这里（下限表只对它们说话，理由见 gates.php） */
$observedAssertions = [];

foreach ($cases as $case) {
    $name = basename($case, '.php');
    $run = contracts_run_php([__DIR__ . '/case-runner.php', $case]);
    $decoded = json_decode(trim($run['stdout']), true);

    if (!is_array($decoded) || !in_array($decoded['status'] ?? null, ['PASS', 'FAIL', 'SKIP'], true)) {
        // 子进程没吐出合法 JSON，通常就是加载期 fatal。原文照抄，不猜。
        $rows[] = [$name, 'FAIL', 0, 'case-runner 未输出合法 JSON（exit ' . $run['code'] . '）：'
            . trim(($run['stderr'] ?? '') !== '' ? $run['stderr'] : $run['stdout'])];
        $failed++;
        continue;
    }

    $status = $decoded['status'];
    $assertions = max(0, (int) ($decoded['assertions'] ?? 0));
    // 计数规则（含「整个 case 标 SKIP 时至少记 1 次」的堵口）住在 lib/gates.php，有单测。
    $skipped += contracts_case_skips($status, (int) ($decoded['skips'] ?? 0));

    if ($status === 'FAIL') {
        $failed++;
    } elseif ($status === 'PASS') {
        $passed++;
        $observedAssertions[$name] = $assertions;
    }

    $rows[] = [$name, $status, $assertions, (string) ($decoded['detail'] ?? '')];
}

// 断言数下限（编辑型缩水的堵口）。判定住在 lib/gates.php；这里只负责喂 PASS 的行 + 报账。
$assertionFloors = EXPECTED_ASSERTIONS[$legName] ?? [];
$assertionProblems = contracts_assertion_floor_errors($observedAssertions, $assertionFloors);

echo "契约验证环（每 case 一个独立子进程）\n";
echo "腿：{$legName}    vendor：{$legDir}/vendor\n\n";
$width = max(array_map(static fn (array $r): int => strlen($r[0]), $rows));
printf("%-{$width}s  %-6s  %7s  %s\n", 'case', 'status', 'asserts', 'detail');
printf("%s\n", str_repeat('-', $width + 8 + 60));
foreach ($rows as [$name, $status, $assertions, $detail]) {
    printf("%-{$width}s  %-6s  %7d  %s\n", $name, $status, $assertions, $detail);
}
printf("\nPASS %d  FAIL %d  SKIP %d（腿 %s 的冻结期望 %d）\n", $passed, $failed, $skipped, $legName, $leg['skips']);

// 断言数下限的报账：达标也打一行（"跑了但没人看"的闸门等于没有闸门），掉线逐条点名带差额。
// 写明「只有 PASS 的 case 参与比较」—— 免得 FAIL 的 case 被排除后这行读起来像"全都好"。
$notCompared = count($rows) - count($observedAssertions);
printf(
    "断言数：%d 个 PASS case 比对冻结下限（%s）—— %s%s\n",
    count($observedAssertions),
    count($assertionFloors) . ' 条',
    $assertionProblems === [] ? '全部达标（≥ 下限）' : '**有 case 掉线/未签字，逐条在下面**',
    $notCompared > 0
        ? "；另有 {$notCompared} 个非 PASS 的 case 不参与比较（结论由 FAIL/SKIP 闸门给）"
        : ''
);
foreach ($assertionProblems as $problem) {
    echo "  {$problem}\n";
}

// 「环红」有**三种互不相同**的成因，而 CI 只看得到退出码，所以必须把结论说清楚：
//   - FAIL             某个 case 自己判定失败 —— 真正的失败，要去看那一行
//   - SKIP-MISMATCH    所有 case 都没失败，只是有 case 诚实标注的「不可验证子项」数与
//                      冻结常量不符 —— 这是**一次需要签字的决定**，不是失败
//   - ASSERTION-SHRINK 没有 case 失败、SKIP 数也对，但有 PASS 的 case 断言数低于冻结下限 ——
//                      「case 里少验了东西」的签名（早退/删断言），不是环境问题
//                      （环境降级会先让 SKIP 数变，结论按上面的优先级归给 SKIP-MISMATCH）
// 三者都非零退出（都不该静默放行），但含义完全不同；混在一起会把人引去查错方向。
$verdict = contracts_verdict($failed, $skipped, $leg['skips'], count($assertionProblems));
printf(
    "RESULT: %s%s\n",
    $verdict,
    match ($verdict) {
        'SKIP-MISMATCH' => '（没有 case 失败；是 SKIP 数需要签字）',
        'ASSERTION-SHRINK' => '（没有 case 失败、SKIP 数也对；是 case 的断言数低于冻结下限）',
        default => '',
    }
);

// 三种成因各自的说明照旧打到 stderr；退出码统一由 verdict 决定（0 只给恰好 OK）。
$shrinkNote = $assertionProblems === []
    ? ''
    : '（注意：本次同时有 ' . count($assertionProblems) . ' 个 case 的断言数掉线/未签字 ——'
        . " 明细在上面的「断言数」一段里）\n";
if ($verdict === 'FAIL') {
    fwrite(STDERR, "::error::契约验证环有 {$failed} 个 case FAIL —— 看上面哪一行的 status 是 FAIL\n");
} elseif ($verdict === 'SKIP-MISMATCH') {
    fwrite(
        STDERR,
        "::error::SKIP-MISMATCH：**没有 case 失败**，但有 case 诚实标注的不可验证子项数变了"
        . "（腿 {$legName}：{$skipped} vs 冻结常量 {$leg['skips']}）。"
        . "含义是「验证覆盖面变了」，需要人工**逐个审核每个 SKIP 的理由是否成立**后签字更新"
        . "该腿的常量，而不是当成失败去修。"
        . "反过来也一样：缺一个 SKIP 等于少签一个字，不要为了让它变绿而少报。\n"
        . $shrinkNote
    );
} elseif ($verdict === 'ASSERTION-SHRINK') {
    fwrite(
        STDERR,
        "::error::ASSERTION-SHRINK：**没有 case 失败、SKIP 数也正确**，但有 case 的断言数低于"
        . "冻结下限（腿 {$legName}）。含义是「case 里少验了东西」—— 早退、断言被删、被包进"
        . "跑不到的分支，都是这个签名；环境降级不背这个锅（那会先让 SKIP 数变）。"
        . "要么把断言加回来，要么把下限表当一次签字改（改它 = 承认这些条不再验了）：\n"
        . implode("\n", array_map(static fn (string $p): string => "  {$p}", $assertionProblems)) . "\n"
    );
}

exit(contracts_verdict_exit_code($verdict));
