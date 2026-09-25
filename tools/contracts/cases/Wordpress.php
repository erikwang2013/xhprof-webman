<?php

declare(strict_types=1);

/**
 * WordPress 契约卡。
 *
 * L1（**真做**，不是文本核对）：本包 `src/Wordpress/**` 与 `wordpress/*.php` 调用的每一个
 * WordPress 全局函数，都在真实 `php-stubs/wordpress-stubs` 里存在，且参数名 / 可选性 /
 * 默认值 / 返回类型逐字段一致（反射对比，不是 grep 源码文本）。
 *
 * 关键的一步是**扫描方向**：不是「我列 4 个函数去核对 4 个函数」（那只能证明我列的这几个
 * 存在），而是把源码里**所有**函数调用扫出来，减去 PHP 内置函数，剩下的每个名字都必须
 * 落在 WP 桩里——这样「漏核对了一个 WP 函数」或「调了个不存在的东西」都会红。
 * 最怕的失效模式 `Call to undefined function wp_unslah()` 正是被这一步挡住。
 *
 * 另外用同一个桩做一次**桩保真**对比（L0 同类）：`tests/Stubs/Framework/Wordpress.php`
 * 的签名 vs 真实 WP。单测全靠那份桩，桩签名漂了会让单测证明不了任何事。
 *
 * L2（adapters 的语义）：**结构上做不到，诚实标 SKIP**——WordPress 的适配器不使用任何
 * 框架对象，只读超全局（$_GET/$_POST/$_SERVER）与全局函数；`php-stubs` 的函数体全是空的，
 * 加载它跑适配器只会拿到一堆 null，那是「用自造桩冒充真实框架」，不是 L2。
 * 真实 WordPress 需要完整安装 + 数据库 + `wp-settings.php` 引导，本环没有（也不该有）。
 */

/**
 * 本环冻结：本包在 WordPress 下调用的全部 WP 全局函数。
 *
 * 扫描结果与这个列表**必须完全相等**。多一个 = 有人加了未核对的新调用；
 * 少一个 = 调用点被删了（或扫描器坏了，那也是坏消息）。
 */
const WORDPRESS_EXPECTED_FUNCTIONS = [
    'add_action',      // XhprofPlugin::register()/onPluginsLoaded() 挂 plugins_loaded 与 shutdown
    'is_ssl',          // RequestAdapter::scheme() 判 https
    'status_header',   // ResponseAdapter::send() 发状态行
    'wp_unslash',      // RequestAdapter 还原 wp_magic_quotes() 加的反斜杠
];

return static function (): array {
    $repoRoot = contracts_repo_root();
    $stub = $repoRoot . '/tests/Stubs/Framework/Wordpress.php';
    $realStubs = contracts_dir() . '/vendor/php-stubs/wordpress-stubs/wordpress-stubs.php';

    if (!is_file($realStubs)) {
        // 缺包必须 FAIL 而不是 SKIP：装不上真实 WP 桩就证明不了任何事。
        return [
            'status' => 'FAIL',
            'detail' => 'tools/contracts/vendor 里没有 php-stubs/wordpress-stubs，先跑 composer install -d tools/contracts',
            'skips' => 0,
        ];
    }

    // ---- 1) 语法：本卡交付的文件都得能被编译（mu-plugin 文件别处没有任何覆盖） ----
    $sources = array_merge(
        glob($repoRoot . '/src/Wordpress/*.php') ?: [],
        glob($repoRoot . '/src/Wordpress/Adapter/*.php') ?: [],
        glob($repoRoot . '/src/Wordpress/config/*.php') ?: [],
        glob($repoRoot . '/wordpress/*.php') ?: []
    );
    if ($sources === []) {
        return ['status' => 'FAIL', 'detail' => '扫不到 src/Wordpress 与 wordpress/ 下的源文件（路径写错了？）', 'skips' => 0];
    }

    $called = [];
    foreach ($sources as $file) {
        $code = (string) file_get_contents($file);
        try {
            token_get_all($code, TOKEN_PARSE);
        } catch (\ParseError $e) {
            return [
                'status' => 'FAIL',
                'detail' => str_replace($repoRoot . '/', '', $file) . " 语法错误：" . $e->getMessage(),
                'skips' => 0,
            ];
        }
        foreach (wordpress_called_functions($code) as $name) {
            $called[$name] = true;
        }
    }

    // PHP 内置函数不需要 WordPress 提供（header/error_log/parse_url/stripslashes 等）。
    $internal = array_flip(get_defined_functions()['internal']);
    $unknown = [];
    $wpCalled = [];
    foreach (array_keys($called) as $name) {
        if (isset($internal[strtolower($name)])) {
            continue;
        }
        // 本包自己的类/函数：扫描只取「非限定调用」，所以只可能是全局函数。
        $wpCalled[] = $name;
    }
    sort($wpCalled);

    foreach ($wpCalled as $name) {
        if (!in_array($name, WORDPRESS_EXPECTED_FUNCTIONS, true)) {
            $unknown[] = $name;
        }
    }
    if ($unknown !== []) {
        return [
            'status' => 'FAIL',
            'detail' => '扫到未纳入核对的全局函数调用：' . implode('、', $unknown)
                . '（既不是 PHP 内置，也不是本环冻结的 WP 函数——若是 WP API 请加进 WORDPRESS_EXPECTED_FUNCTIONS，'
                . '若是笔误就说明它会在生产上 Call to undefined function）',
            'skips' => 0,
        ];
    }

    $expected = WORDPRESS_EXPECTED_FUNCTIONS;
    sort($expected);
    if ($wpCalled !== $expected) {
        return [
            'status' => 'FAIL',
            'detail' => '冻结清单与源码实际调用不符：清单=' . implode('、', $expected)
                . '；实际=' . implode('、', $wpCalled),
            'skips' => 0,
        ];
    }

    // ---- 2) 真实 WP 桩里反射这 N 个函数 ----
    $signature = <<<'PHP'
function wp_sig(ReflectionFunction $r): array
{
    $params = [];
    foreach ($r->getParameters() as $p) {
        $params[] = [
            'name' => $p->getName(),
            'optional' => $p->isOptional(),
            'default' => $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : null,
        ];
    }

    return [
        'params' => $params,
        'required' => $r->getNumberOfRequiredParameters(),
        'return' => $r->hasReturnType() ? (string) $r->getReturnType() : '',
        'file' => basename((string) $r->getFileName()),
    ];
}

// `php -r code a b`：$argv[0] 是 "Standard input code"，第一个真参数在 $argv[1]。
$names = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$out = ['missing' => [], 'functions' => []];
foreach ($names as $name) {
    if (!function_exists($name)) {
        $out['missing'][] = $name;
        continue;
    }
    $out['functions'][$name] = wp_sig(new ReflectionFunction($name));
}
echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
PHP;

    $namesJson = json_encode(WORDPRESS_EXPECTED_FUNCTIONS, JSON_THROW_ON_ERROR);

    $real = contracts_run_php(['-r', "require " . var_export($realStubs, true) . ";\n" . $signature, $namesJson]);
    $realDecoded = json_decode(trim($real['stdout']), true);
    if (!is_array($realDecoded) || $realDecoded['missing'] !== []) {
        return [
            'status' => 'FAIL',
            'detail' => '真实 WP 桩侧反射失败（exit ' . $real['code'] . '）：' . trim($real['stderr'] !== '' ? $real['stderr'] : $real['stdout'])
                . '；缺失函数=' . json_encode($realDecoded['missing'] ?? null, JSON_UNESCAPED_UNICODE),
            'skips' => 0,
        ];
    }

    // 必须确实来自 php-stubs 生成的桩文件，防止"反射到了别处同名声明"。
    foreach ($realDecoded['functions'] as $name => $sig) {
        if ($sig['file'] !== 'wordpress-stubs.php') {
            return [
                'status' => 'FAIL',
                'detail' => "{$name} 的声明不在 wordpress-stubs.php 里，而是 {$sig['file']}（反射到了别的东西）",
                'skips' => 0,
            ];
        }
    }

    // ---- 3) 桩保真：本包的桩 vs 真实 WP ----
    if (!is_file($stub)) {
        return ['status' => 'FAIL', 'detail' => "桩文件不存在：{$stub}", 'skips' => 0];
    }
    $ours = contracts_run_php(['-r', "require " . var_export($stub, true) . ";\n" . $signature, $namesJson]);
    $oursDecoded = json_decode(trim($ours['stdout']), true);
    if (!is_array($oursDecoded) || $oursDecoded['missing'] !== []) {
        return [
            'status' => 'FAIL',
            'detail' => '本包桩侧反射失败（exit ' . $ours['code'] . '）：' . trim($ours['stderr'] !== '' ? $ours['stderr'] : $ours['stdout'])
                . '；缺失函数=' . json_encode($oursDecoded['missing'] ?? null, JSON_UNESCAPED_UNICODE),
            'skips' => 0,
        ];
    }

    $diffs = [];
    foreach (WORDPRESS_EXPECTED_FUNCTIONS as $name) {
        $a = $oursDecoded['functions'][$name];
        $b = $realDecoded['functions'][$name];
        foreach (['params', 'required', 'return'] as $field) {
            if ($a[$field] !== $b[$field]) {
                $diffs[] = "{$name}.{$field}: 桩=" . json_encode($a[$field], JSON_UNESCAPED_SLASHES)
                    . ' 真实=' . json_encode($b[$field], JSON_UNESCAPED_SLASHES);
            }
        }
        if ($a['file'] !== 'Wordpress.php') {
            $diffs[] = "{$name}: 桩侧反射到的不是 tests/Stubs/Framework/Wordpress.php（{$a['file']}）";
        }
    }
    if ($diffs !== []) {
        return [
            'status' => 'FAIL',
            'detail' => count($diffs) . " 处桩签名与真实 WordPress 不一致：\n  - " . implode("\n  - ", $diffs),
            'skips' => 0,
        ];
    }

    // ---- 4) L2 的 SKIP 不是嘴上说的：把「真实包没有可执行语义」钉成可复核的事实 ----
    // php-stubs 是给 IDE 用的桩，四个函数的函数体都是空的（源码里就是 `{}`）。
    // 探针挑两个「真实 WP 一定有返回值」的：wp_unslash('x') 该返回 'x'、is_ssl() 该返回 bool。
    // 实测都是 null → 加载真实包跑适配器只会得到一堆 null：这是 SKIP 的理由，不是借口。
    // 反过来也成立：哪天它们不再是 null（换了包/换了版本），本 case 会 FAIL 要求重新评估 L2，
    // 而不是继续拿一句可能已经过期的理由标 SKIP。
    $probe = contracts_run_php([
        '-r',
        "require " . var_export($realStubs, true) . ";\n"
        . "echo json_encode([wp_unslash('x'), is_ssl()]), \"\\n\";",
    ]);
    $probeValues = json_decode(trim($probe['stdout']), true);
    if ($probeValues !== [null, null]) {
        return [
            'status' => 'FAIL',
            'detail' => 'php-stubs 的函数已经不再是空体（实测 wp_unslash/is_ssl → ' . json_encode($probeValues)
                . '），L2 标 SKIP 的前提失效，请重新评估 WordPress 卡能否升级到 L2',
            'skips' => 0,
        ];
    }

    // ---- 5) L2：结构上做不到，诚实标 SKIP ----
    return [
        'status' => 'PASS',
        'detail' => count($sources) . ' 个源文件语法通过；源码里的 '
            . count(WORDPRESS_EXPECTED_FUNCTIONS) . ' 个 WP 全局函数（'
            . implode('、', WORDPRESS_EXPECTED_FUNCTIONS) . '）在真实 wordpress-stubs 中逐一存在，'
            . '参数名/可选性/默认值/返回类型与 tests/Stubs/Framework/Wordpress.php 逐字段一致。'
            . ' [SKIP 1] L2（适配器语义）：WP 适配器不使用任何框架对象，只读超全局与全局函数；'
            . 'php-stubs 的函数体是空的，加载它跑适配器得到的是 null 而非真实 WP 语义，'
            . '真实 WordPress 需要完整安装 + 数据库 + wp-settings.php 引导，本环结构上无法提供。',
        'skips' => 1,
    ];
};

/**
 * 扫出一段 PHP 源码里调用的**全局**函数名。
 *
 * 排除：方法调用（`->` `::`）、函数声明（`function`）、`new X()`、变量函数（`$f()`）、
 * 以及带命名空间前缀的调用（`\Foo\bar()` / `Foo\bar()` 不会是 WordPress 全局函数）。
 *
 * @return list<string>
 */
function wordpress_called_functions(string $source): array
{
    $tokens = token_get_all($source);
    $names = [];
    $skipBefore = [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_NS_SEPARATOR];
    $transparent = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_STRING) {
            continue;
        }

        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && in_array($tokens[$j][0], $transparent, true)) {
            $j++;
        }
        if (($tokens[$j] ?? null) !== '(') {
            continue;
        }

        $k = $i - 1;
        while (isset($tokens[$k]) && is_array($tokens[$k]) && in_array($tokens[$k][0], $transparent, true)) {
            $k--;
        }
        $prev = $tokens[$k] ?? null;
        if (is_array($prev) && in_array($prev[0], $skipBefore, true)) {
            continue;
        }
        if ($prev === '\\' || $prev === '$' || $prev === '&') {
            continue;
        }

        $names[$token[1]] = true;
    }

    return array_keys($names);
}
