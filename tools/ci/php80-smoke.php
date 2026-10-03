#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * PHP 8.0 冒烟三连：lint 扫描面、8.1+ 函数黑名单、类加载 + 纯逻辑。
 *
 * 为什么是一份脚本而不是三处内联：ci.yml 的 lint/smoke job 与 release.yml 的 php80
 * 前置 job 曾把这三块**逐字复制**成两份，然后漂移了 —— ci 的类加载清单有
 * `Core\XhprofLib\Display\FlameGraph`，release 没有，而 release 的注释还写着
 * 「与 ci.yml 的 8.0 侧逐字对应」。复制品漂移是无声的：两份都绿，只是各自少覆盖一块
 * ——类加载清单少了 FlameGraph，一个让 FlameGraph 加载期崩溃的提交照样能在
 * release.yml 里拿到 tag（那个 job 存在的唯一理由就是拦住这类提交）。
 * 抽成这一份后，两处 workflow 都调它，「漂移」在结构上不再可能。
 *
 * **本文件自身保持 PHP 8.0 语法**：它就是拿 8.0/8.1 的解释器跑的（lint 矩阵的 8.0 格
 * 会 lint 到它自己 —— tools/ci 已在扫描面里，自证）。
 *
 * 用法（任意 cwd，脚本自己 chdir 到仓库根）：
 *   php tools/ci/php80-smoke.php            # 三段全跑
 *   php tools/ci/php80-smoke.php lint       # 只跑 lint 扫描面
 *   php tools/ci/php80-smoke.php functions  # 只跑 8.1+ 函数黑名单
 *   php tools/ci/php80-smoke.php classes    # 只跑类加载 + 纯逻辑（需先 composer dump-autoload）
 * 分段调用是 ci.yml 的需要：lint 矩阵格（8.0–8.5）不装依赖，跑不了 classes 段。
 *
 * 退出码：0 全过；1 有检查红；2 用法错误。
 */

define('ROOT', dirname(__DIR__, 2));

function out(string $line): void
{
    fwrite(STDOUT, $line . "\n");
}

function fail(string $msg): void
{
    fwrite(STDERR, '[php80-smoke] ' . $msg . "\n");
}

/**
 * 段一：lint 扫描面。
 *
 * 扫描面 = src tests joomla wordpress（随包交付、声明 composer.json 的 php >= 8.0）
 *        + tools/i18n（声明"全部合 PHP 8.0 语法"，见 i18n.yml 头部）
 *        + tools/ci tools/purge.php（同一条 php >= 8.0 声明；此前不在任何 lint 面上，
 *          8.0 兼容性从未被机器验证过。并进来后 8.0 那一格就是那句声明的证据）。
 * `tools/contracts` 刻意不在面上：它声明 php >= 8.2（两份 composer.json），放进
 * 8.0/8.1 的矩阵格是假红。
 *
 * 逐文件 `php -l` 的输出（每文件一行 "No syntax errors detected"）原样透传到 CI 日志：
 * 日志里能看到扫描面实际扫到了什么，且与抽取之前逐字一致。
 */
function checkLint(): void
{
    $cmd = "find src tests joomla wordpress tools/i18n tools/ci tools/purge.php"
        . " -name '*.php' -print0 | xargs -0 -n1 php -l";
    $code = 0;
    passthru($cmd, $code);
    if ($code !== 0) {
        fail('php -l 报错（见上方输出）');
        exit(1);
    }
}

/**
 * 段二：8.1+ 函数黑名单。黑名单只列**函数**（语法层面的 8.1+ 形态由段一的 8.0 解析器
 * 拦截，这里补它拦不到的"语法合法、函数不存在"）。名单按原文保留 —— 删一个等于放行
 * 一类在 8.0 上必 fatal 的提交。
 */
function checkFunctions(): void
{
    $names = 'array_is_list|enum_exists|array_find|array_any|array_all|json_validate|mb_str_pad'
        . '|ini_parse_quantity|memory_reset_peak_usage|mb_ucfirst|mb_lcfirst';
    $cmd = "grep -rnE '\\b({$names})[[:space:]]*\\(' src/ joomla/ wordpress/ --include='*.php'";
    $lines = [];
    $code = 0;
    exec($cmd, $lines, $code);
    if ($code === 0) {
        foreach ($lines as $line) {
            fwrite(STDERR, $line . "\n");
        }
        fail('src/ joomla/ wordpress/ 使用了 PHP 8.1+ 才提供的函数，与 composer.json 的 php >= 8.0 不符');
        exit(1);
    }
    if ($code !== 1) {
        // grep 的 1 = 没匹配（要的结果）；>1 = 出错（不可读的目录、坏正则……），不能当"过"。
        fail("grep 退出码 {$code}（>1 是出错，不是「没匹配」）");
        exit(1);
    }
}

/**
 * 文件里声明的类型：[$kind, $shortName]，不是类型文件（如词表 `return [...]`）返回 null。
 * 只认行首的声明关键字（可带 final/abstract 等修饰符，8.1+ 的 readonly 也带上）：
 * src/ 的写法统一是行首声明，`Foo::class`（关键字前有 `::`）与注释里的词不会误匹配。
 * 声明形态往前兼容 —— 这份脚本要能在 8.0 上跑，不能写 enum_exists 之类的 8.1+ 判断。
 *
 * @return array{0: string, 1: string}|null
 */
function declaredType(string $code): ?array
{
    $re = '/^[ \t]*(?:(?:final|abstract|readonly)[ \t]+)*(class|interface|trait|enum)[ \t]+([A-Za-z_][A-Za-z0-9_]*)/m';
    if (preg_match($re, $code, $m) !== 1) {
        return null;
    }

    return [$m[1], $m[2]];
}

/**
 * 段三：把盘上 src/Core 的**全部**类型加载一遍，再跑两条纯逻辑断言。
 *
 * 清单不是手写的，是从磁盘推出来的（PSR-4：`src/Core/A/B.php` → `ErikWang2013\Xhprof\Core\A\B`）：
 * 手写清单正是漂移的载体（见文件头），盘上的文件才是唯一事实来源。
 *   * 每个文件先读它声明的类型，再按类型只调**一个**存在性函数。刻意不用
 *     `class_exists() || interface_exists() || trait_exists()` 的链：类名对不上时第一次
 *     调用会把文件 include 进来、第二次调用会**再 include 一遍**（PHP 的 include 不去重），
 *     直接撞成 "Cannot declare class ... already in use" 的致命错误，本想要的那句
 *     "无法加载 X" 反而看不到。单次调用 + 短名核对，错误路径才是干净的。
 *   * `src/Core/I18n/lang/*.php` 是词表（`return [...]`），不是类型 —— 显式、写下来地
 *     跳过，跳过数会打印出来（数字变了看得见）。**其它任何非类型文件在这里红**，这是
 *     刻意的："不检查"必须是一笔写下来的账，不能是正则没匹配上的沉默。
 *
 * 纯逻辑断言取的是两个此前就在跑、且能抓住真实回归的点：指标数（analyzer 的输入面）
 * 与千分位格式（`1,234`，跨 12 种语言的数字格式都从这里过）。
 */
function checkClasses(): void
{
    $autoload = ROOT . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        fail('vendor/autoload.php 不存在 —— 先 composer dump-autoload（--no-dev 即可）');
        exit(1);
    }
    require $autoload;

    $ns = 'ErikWang2013\\Xhprof\\';
    $src = ROOT . '/src/';
    $langDir = 'Core/I18n/lang/';

    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src . 'Core', FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    $checked = 0;
    $skipped = 0;
    foreach ($files as $path) {
        $rel = str_replace('\\', '/', substr($path, strlen($src)));   // 如 Core/Xhprof.php
        $declared = declaredType((string) file_get_contents($path));
        if ($declared === null) {
            if (strpos($rel, $langDir) === 0) {
                $skipped++;
                continue;
            }
            fail("{$rel} 没声明任何类型，也不在 {$langDir}（词表）里 —— 非类型文件必须逐个写下来，不能沉默跳过");
            exit(1);
        }
        $fqcn = $ns . str_replace('/', '\\', substr($rel, 0, -4));
        if ($declared[1] !== basename($rel, '.php')) {
            fail("{$rel} 声明的是 {$declared[1]}，与 PSR-4 路径推得的类名对不上（自动加载必然失败）");
            exit(1);
        }
        // enum 在 8.1+，class_exists 对它也返回 true；8.0 上 enum 文件先会死在 lint 段。
        // 加载期抛错（缺父类/接口/特性是 Error，不是 false）也要走同一条红灯，且带上原因。
        try {
            $exists = $declared[0] === 'interface' ? interface_exists($fqcn)
                : ($declared[0] === 'trait' ? trait_exists($fqcn) : class_exists($fqcn));
        } catch (Throwable $e) {
            fail("加载 {$fqcn}（src/{$rel}）时抛错：" . $e->getMessage());
            exit(1);
        }
        if (!$exists) {
            fail("无法加载 {$fqcn}（src/{$rel}）：声明还在，加载期就崩了（缺父类/接口/特性？）");
            exit(1);
        }
        $checked++;
    }
    if ($checked === 0) {
        // 扫描面空 = 这段检查本身失效（目录搬走、后缀改了），不是"全过"。
        fail('src/Core 下一个类型都没扫到 —— 核对逻辑本身失效了');
        exit(1);
    }

    // 两条断言包在 catch 里：**从盘上消失的类型文件**不在上面的循环里（循环按盘上的文件走，
    // 文件没了就没有它这一项），它只会在被别的类用到时炸在加载/调用处。实测删掉
    // `Core/I18n/I18n.php` 后这里的 `I18n::numberFormat()` 抛 Error，裸奔的话是 PHP 的
    // 未捕获 fatal（rc=255，能拦下但输出是堆栈）；catch 成一行诊断，红的理由和人话都齐。
    $lib = $ns . 'Core\\XhprofLib\\Utils\\XhprofLib';
    $disp = $ns . 'Core\\XhprofLib\\Display\\XhprofDisplay';
    try {
        if (count($lib::xhprof_get_possible_metrics()) !== 7) {
            fail('xhprof_get_possible_metrics() 的指标数不是 7');
            exit(1);
        }
        if ($disp::xhprof_count_format(1234) !== '1,234') {
            fail('xhprof_count_format(1234) 不是 "1,234"（千分位格式变了？）');
            exit(1);
        }
    } catch (Throwable $e) {
        fail('纯逻辑断言执行时抛错（某类型文件被删/改名？）：' . $e->getMessage());
        exit(1);
    }

    out("classes ok: {$checked} 个类型全部可加载（跳过 {$skipped} 个词表文件），纯逻辑断言通过 on " . PHP_VERSION);
}

$section = $argv[1] ?? 'all';
$sections = [
    'lint' => 'checkLint',
    'functions' => 'checkFunctions',
    'classes' => 'checkClasses',
];
if ($section === 'all') {
    $todo = $sections;
} elseif (isset($sections[$section])) {
    $todo = [$section => $sections[$section]];
} else {
    fail("未知段落：{$section}（可用：lint / functions / classes / 不加参数 = 全跑）");
    exit(2);
}

chdir(ROOT);
foreach ($todo as $name => $fn) {
    out("== php80-smoke: {$name}");
    $fn();
}
exit(0);
