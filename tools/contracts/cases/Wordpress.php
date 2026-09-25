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
 * L2-lite（adapters 的语义）：**跑真 WordPress 核心源码**，不装站、不连数据库、不引导
 * `wp-settings.php`。本卡 2026-09-25 之前标 SKIP，理由是「php-stubs 的函数体是空的，加载它
 * 只能得到 null」——那半句是真的（桩确实空），但结论推错了：**要用真语义不必装站**。真
 * `wp-includes/{plugin,load,formatting,functions}.php` 只需 `ABSPATH`/`WPINC` 两个常量就能
 * 独立包含（实测 6.9.9 下 4 个文件 15ms，无 DB、无引导），里面是真实现：
 * `wp_unslash()` 走 `stripslashes_deep()`（递归 + 非字符串透传）、`is_ssl()` 是真分支表、
 * `status_header()` 会过真 `apply_filters('status_header', ...)`、`add_action()` 背后是真的
 * `WP_Hook`。所以这张卡现在是 L2-lite：**真 WP 核心 + 真适配器**，只有缓存是假的
 * （采样链路本身由 Redis 卡用真服务器覆盖）。
 *
 * 仍在进程外跑：本 case 进程要加载 php-stubs 与 `tests/Stubs` 两份同名函数声明，与真 WP 核心
 * 撞函数名是加载期 fatal（不可 catch）。探针脚本写在临时文件里、由子进程执行，输出一份
 * 观测快照，断言留在本文件里——与其余 case 的子进程分工一致。
 *
 * 已知边界（诚实记，不是 SKIP）：CLI 下 `header()` 是 no-op、`headers_list()` 恒空，
 * 「头真的发出去了吗」在这里不可观测。本卡退一步钉**可观测的那一半**：真
 * `status_header()` 收到的状态码与状态行字面量（经真过滤器）、`send()` 的 echo 体、返回值。
 * 头与状态码在真 SAPI 下的行为由 `tests/Unit/Adapter/WordpressTest.php` 的真 `php -S` 往返覆盖。
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

    // ---- 4) php-stubs 是**桩**：这一点钉住，免得再有人拿它跑适配器然后得到一堆 null ----
    // 桩的函数体是空的（本包 vendor 里 wordpress-stubs.php:131565 `function wp_unslash($value) {}`、
    // :143461 `function is_ssl() {}`），所以它只配当 L1/桩保真的对象。探针挑两个「真实现一定有
    // 返回值」的输入：桩回 null、真源码回真值 —— 这条**对比**就是 L2-lite 必须换真源码的理由，
    // 现在它可机器复核，而不是一句注释。
    // 实测（把下面第 5 段探针的四个 require 换成 wordpress-stubs.php 即复现）：拿桩跑适配器
    // 不是「得到一堆 null」，而是**加载就跑不动**——`wp_unslash($_GET)` 回 null 后
    // `array_key_exists($key, null)` 直接抛 TypeError。桩连「跑一遍」都撑不住，谈语义是多余的。
    $hollow = contracts_run_php([
        '-r',
        "require " . var_export($realStubs, true) . ";\n"
        . "echo json_encode([wp_unslash('x'), is_ssl()]), \"\\n\";",
    ]);
    $hollowValues = json_decode(trim($hollow['stdout']), true);
    if ($hollowValues !== [null, null]) {
        return [
            'status' => 'FAIL',
            'detail' => 'php-stubs 的函数不再是空体（实测 wp_unslash/is_ssl → ' . json_encode($hollowValues)
                . '）：L1 的桩保真对比与「桩没有可执行语义」的前提都要重新评估（桩里出现真实现了？）。'
                . 'L2-lite 不受影响——它跑的是真 WP 源码。',
            'skips' => 0,
        ];
    }

    // ---- 5) L2-lite：真 WordPress 核心源码（不是桩、不装站、不连库）----
    $wpRoot = contracts_dir() . '/vendor/roots/wordpress-no-content';
    if (!is_file($wpRoot . '/wp-includes/formatting.php')) {
        // 缺包 FAIL 不 SKIP：与 php-stubs 同一条理由——装不上真源码就证明不了任何事。
        return [
            'status' => 'FAIL',
            'detail' => 'tools/contracts/vendor 里没有 roots/wordpress-no-content（真 WP 核心源码），'
                . '先跑 composer install -d tools/contracts',
            'skips' => 0,
        ];
    }

    $probeBase = sys_get_temp_dir() . '/xhprof-contract-wordpress-' . getmypid();
    $probeFile = $probeBase . '/probe.php';
    @mkdir($probeBase, 0777, true);
    file_put_contents($probeFile, <<<'PROBE'
<?php

declare(strict_types=1);

// 本文件由 tools/contracts/cases/Wordpress.php 生成并删除。argv: 1=仓库根 2=真 WordPress 根
$repoRoot = $argv[1];
$wpRoot = $argv[2];

// 真 WP 核心的四个文件独立包含：只要 ABSPATH/WPINC，不要数据库、不要 wp-config.php、
// 不要 wp-settings.php 引导。functions.php 会 require option.php、plugin.php 会 require
// class-wp-hook.php（都在同目录），plugin.php 顺带初始化 $wp_filter 等全局。
define('ABSPATH', $wpRoot . '/');
define('WPINC', 'wp-includes');
foreach (['plugin', 'load', 'formatting', 'functions'] as $core) {
    require ABSPATH . WPINC . '/' . $core . '.php';
}

// 本包 src/ 的 PSR-4 自注册要在这里重来一遍：独立进程不继承父进程的 autoloader。
spl_autoload_register(static function (string $class) use ($repoRoot): void {
    $prefix = 'ErikWang2013\\Xhprof\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = $repoRoot . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$out = [];

// ---- (a) 真 wp_unslash()：RequestAdapter 的三个调用点（get/all/uri）----
// 夹具就是 wp_magic_quotes() 的形态（等价于 addslashes），其中 `server_uri_raw` 是本探针
// 的自证：输入**确实**带上了反斜杠，否则下面的「剥掉了」是空转。
$_GET = [
    'run' => addslashes("a'b"),
    'page' => 3,                                  // 非字符串：stripslashes_deep 原样透传
    'flag' => false,
    'nested' => ['q' => addslashes('x\\y'), 'n' => 7], // 数组：map_deep 递归
    'nullv' => null,
    'plain' => '/plain-path',                     // 无引号：unslash 不该改动它
];
$_POST = [
    'run' => addslashes('POST-value'),            // GET 同名：被 GET 覆盖
    'only_post' => addslashes("p'q"),
];
$_SERVER['REQUEST_URI'] = addslashes("/xhprof?run=a'b&x=1");
$_SERVER['HTTP_HOST'] = 'wp.example.com';
$_SERVER['REQUEST_METHOD'] = 'post';

$request = new \ErikWang2013\Xhprof\Wordpress\Adapter\RequestAdapter();
$out['request'] = [
    'server_uri_raw' => $_SERVER['REQUEST_URI'],
    'get_string' => $request->get('run'),
    'get_int' => $request->get('page'),
    'get_bool' => $request->get('flag'),
    'get_nested' => $request->get('nested'),
    'get_null' => $request->get('nullv', 'DEFAULT'),   // 键在、值为 null → 不落默认值
    'get_missing' => $request->get('nope', 'DEFAULT'),
    'get_plain' => $request->get('plain'),
    'get_from_post' => $request->get('only_post'),
    'all' => $request->all(),                          // GET 在前、POST 独有键在后
    'uri' => $request->uri(),
    'method' => $request->method(),
];

// ---- (b) 真 is_ssl()：url() 的 scheme。逐条钉真分支表，不钉"我以为的"分支表 ----
$base = ['REQUEST_URI' => '/xhprof?run=1', 'HTTP_HOST' => 'wp.example.com'];
$urlWith = static function (array $server) use ($request, $base): string {
    $_SERVER = $server + $base;

    return $request->url();
};
$out['is_ssl'] = [
    'bare' => $urlWith([]),
    'https_on' => $urlWith(['HTTPS' => 'on']),
    'https_ON' => $urlWith(['HTTPS' => 'ON']),        // strtolower：大小写不敏感
    'https_1' => $urlWith(['HTTPS' => '1']),
    'https_off' => $urlWith(['HTTPS' => 'off']),
    // `elseif`：HTTPS 存在时 SERVER_PORT 根本不看 —— 天真实现（两者取 ||）在这里会红
    'https_off_443' => $urlWith(['HTTPS' => 'off', 'SERVER_PORT' => 443]),
    'port_443' => $urlWith(['SERVER_PORT' => 443]),
    'port_80' => $urlWith(['SERVER_PORT' => 80]),
    // 真 is_ssl() **不认** X-Forwarded-Proto（实测 6.9.9 与 6.6.2 一致）
    'xfp_https' => $urlWith(['HTTP_X_FORWARDED_PROTO' => 'https']),
];

// ---- (c) 真 status_header()：经真 apply_filters('status_header', ...) 观察状态行 ----
$lines = [];
$headersSent = [];
add_filter('status_header', static function ($line, $code, $description, $protocol) use (&$lines): string {
    $lines[] = ['line' => $line, 'code' => $code, 'description' => $description, 'protocol' => $protocol];

    return $line;
}, 10, 4);

$snapshot = static function () use (&$lines, &$headersSent): array {
    $captured = $lines;
    $lines = [];
    $headersSent[] = headers_sent();

    return ['filter' => $captured, 'body' => (string) ob_get_clean()];
};
$send = static function (\ErikWang2013\Xhprof\Wordpress\Adapter\ResponseAdapter $response) use (&$headersSent): mixed {
    ob_start();
    $headersSent[] = headers_sent();

    return $response->send();
};

$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$returned = $send(
    (new \ErikWang2013\Xhprof\Wordpress\Adapter\ResponseAdapter())
        ->withStatus(404)
        ->withHeaders(['Content-Type' => 'text/plain; charset=UTF-8'])
        ->withBody('body-404')
);
$out['send']['status_404'] = $snapshot() + ['returned' => $returned];

$send((new \ErikWang2013\Xhprof\Wordpress\Adapter\ResponseAdapter())->withStatus(200)->withBody('<html>report</html>'));
$out['send']['status_200'] = $snapshot();

// file() 读不出文件 → 404 空体，走的是同一条真 status_header
$send((new \ErikWang2013\Xhprof\Wordpress\Adapter\ResponseAdapter())->file('/nonexistent-contract-file.css'));
$out['send']['file_missing'] = $snapshot();

// 真 wp_get_server_protocol() 只认 HTTP/1.1|HTTP/2|HTTP/2.0|HTTP/3，其余一律回落 HTTP/1.0
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/9.9';
$send((new \ErikWang2013\Xhprof\Wordpress\Adapter\ResponseAdapter())->withStatus(404));
$out['send']['bogus_protocol'] = $snapshot();

unset($_SERVER['SERVER_PROTOCOL']);   // CLI 下就是这样：没有 SERVER_PROTOCOL
$send((new \ErikWang2013\Xhprof\Wordpress\Adapter\ResponseAdapter())->withStatus(404));
$out['send']['no_protocol'] = $snapshot();
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

// 守卫自证：整个过程 headers_sent() 必须是 false，否则 send() 里的 `if (!headers_sent())`
// 会把 status_header 整段跳过、上面 5 条断言全部空转（本探针全程在 ob_ 缓冲里输出）。
$out['send']['headers_sent_observed'] = array_values(array_unique($headersSent));

// ---- (d) 真 add_action()/do_action()/WP_Hook ----
$_SERVER = [
    'REQUEST_URI' => '/wp-json/wp/v2/posts?per_page=5',
    'HTTP_HOST' => 'wp.example.com',
    'REQUEST_METHOD' => 'GET',
    'REMOTE_ADDR' => '10.0.0.1',
    'SERVER_PROTOCOL' => 'HTTP/1.1',
    'HTTPS' => 'on',
];
$plugin = new \ErikWang2013\Xhprof\Wordpress\XhprofPlugin([
    'enable' => true,
    'key_prefix' => 'xhprof-wpcontract',
    'ignore_url_arr' => ['/xhprof', '/wp-json'],
    'log_ttl' => 60,
]);
$hooks = [
    'ext_xhprof' => extension_loaded('xhprof'),
    'ext_redis' => extension_loaded('redis'),
    // 初始态：证明下面 registered/shutdown 不是"本来就有"
    'plugins_loaded_before' => has_action('plugins_loaded'),
    'shutdown_before' => has_action('shutdown'),
    'php_int_min' => PHP_INT_MIN,
    'php_int_max' => PHP_INT_MAX,
];

// 旁观者必须**先**挂、优先级取默认的 10：同优先级按注册序，所以它一旦看到适配器，
// 就证明「本包的处理器靠 PHP_INT_MIN 抢在默认优先级之前**真跑过**」——只证明注册上了是不够的。
$witness = [];
add_action('plugins_loaded', static function () use (&$witness): void {
    $request = \ErikWang2013\Xhprof\Core\Xhprof::$request;
    $witness['request_class'] = $request === null ? null : get_class($request);
    $witness['key_prefix'] = \ErikWang2013\Xhprof\Core\Xhprof::$key_prefix;
}, 10);

$plugin->register();
$hooks['registered_priority'] = has_action('plugins_loaded', [$plugin, 'onPluginsLoaded']);

// enable=true + 非报告页路径 → 真 xhprof_enable() + 注册 shutdown 止点。**不** do_action('shutdown')：
// 止点一跑就会落库（那是 Redis 卡的事），本卡只钉「注册上了、且优先级真的是 PHP_INT_MAX」。
do_action('plugins_loaded');

$hooks['witness'] = $witness;
$hooks['shutdown_after'] = has_action('shutdown');
$hooks['shutdown_priorities'] = array_keys($GLOBALS['wp_filter']['shutdown']->callbacks ?? []);
$hooks['shutdown_callback_count'] = count($GLOBALS['wp_filter']['shutdown']->callbacks[PHP_INT_MAX] ?? []);
$out['hooks'] = $hooks;

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
PROBE);
    $probe = contracts_run_php([$probeFile, $repoRoot, $wpRoot]);
    @unlink($probeFile);
    @rmdir($probeBase);

    if ($probe['code'] !== 0) {
        return [
            'status' => 'FAIL',
            'detail' => '真 WP 探针子进程 exit ' . $probe['code'] . '：' . trim($probe['stderr']),
            'skips' => 0,
        ];
    }
    $obs = json_decode(trim($probe['stdout']), true);
    if (!is_array($obs)) {
        return [
            'status' => 'FAIL',
            'detail' => '真 WP 探针没吐出可解析的 JSON：' . var_export(trim($probe['stdout']), true)
                . '；stderr=' . trim($probe['stderr']),
            'skips' => 0,
        ];
    }

    $checks = 0;
    $failures = [];
    $expect = static function (string $label, mixed $actual, mixed $expected) use (&$checks, &$failures): void {
        $checks++;
        if ($actual !== $expected) {
            $failures[] = $label . '：得到 ' . var_export($actual, true) . '，期望 ' . var_export($expected, true);
        }
    };

    // 采样断言的前置：真调度那一段要 SamplingGuard 放行（缺扩展时插件自己会 return）。
    // 缺扩展 → FAIL 不 SKIP：理由与 Redis 卡相同（EXPECTED_SKIPS 是签字常量，不许随环境漂移），
    // 而且这张卡要的 redis 扩展本来就被 Redis 卡强制着，没有新增环境要求。
    if (!$obs['hooks']['ext_xhprof'] || !$obs['hooks']['ext_redis']) {
        return [
            'status' => 'FAIL',
            'detail' => '真 WP 的采样/调度断言需要 ext-xhprof 与 ext-redis（SamplingGuard 前置）：实测 '
                . 'xhprof=' . var_export($obs['hooks']['ext_xhprof'], true)
                . ' redis=' . var_export($obs['hooks']['ext_redis'], true)
                . '。contracts.yml 的 setup-php 里已声明 extensions: xhprof, redis。',
            'skips' => 0,
        ];
    }

    // 真 wp_unslash()：递归、非字符串透传、三个调用点都过它
    $expect('uri() 剥掉 REQUEST_URI 上的反斜杠（夹具确实带反斜杠）', $obs['request']['server_uri_raw'], "/xhprof?run=a\\'b&x=1");
    $expect('uri() = 剥掉反斜杠后的 path+query', $obs['request']['uri'], "/xhprof?run=a'b&x=1");
    $expect('get() 剥掉字符串上的反斜杠', $obs['request']['get_string'], "a'b");
    $expect('get() 的 POST 值同样剥反斜杠', $obs['request']['get_from_post'], "p'q");
    $expect('get() 不动无引号的路径（unslash 不是"改字符串"）', $obs['request']['get_plain'], '/plain-path');
    $expect('get() 递归剥数组里的字符串（stripslashes_deep/map_deep）', $obs['request']['get_nested'], ['q' => 'x\y', 'n' => 7]);
    $expect('get() 对非字符串原样透传（int）', $obs['request']['get_int'], 3);
    $expect('get() 对非字符串原样透传（false 不是空串）', $obs['request']['get_bool'], false);
    $expect('get() 键存在而值为 null 时返回 null（不是默认值）', $obs['request']['get_null'], null);
    $expect('get() 键不存在才回默认值', $obs['request']['get_missing'], 'DEFAULT');
    $expect('all() = GET 覆盖 POST 且 GET 在前（顺序也钉住）', $obs['request']['all'], [
        'run' => "a'b", 'page' => 3, 'flag' => false, 'nested' => ['q' => 'x\y', 'n' => 7],
        'nullv' => null, 'plain' => '/plain-path', 'only_post' => "p'q",
    ]);
    $expect('method() 大写化（is_ssl 无关，走同一适配器）', $obs['request']['method'], 'POST');

    // 真 is_ssl()：五条分支 + 一条「它不认什么」
    $baseUrl = 'wp.example.com/xhprof?run=1';
    $expect('url() 裸环境 = http（真 is_ssl 无 HTTPS/443）', $obs['is_ssl']['bare'], 'http://' . $baseUrl);
    $expect('url() HTTPS=on → https', $obs['is_ssl']['https_on'], 'https://' . $baseUrl);
    $expect('url() HTTPS=ON → https（strtolower）', $obs['is_ssl']['https_ON'], 'https://' . $baseUrl);
    $expect('url() HTTPS=1 → https', $obs['is_ssl']['https_1'], 'https://' . $baseUrl);
    $expect('url() HTTPS=off → http', $obs['is_ssl']['https_off'], 'http://' . $baseUrl);
    $expect('url() HTTPS=off + SERVER_PORT=443 → http（真实现是 elseif，不看端口）', $obs['is_ssl']['https_off_443'], 'http://' . $baseUrl);
    $expect('url() SERVER_PORT=443 → https', $obs['is_ssl']['port_443'], 'https://' . $baseUrl);
    $expect('url() SERVER_PORT=80 → http', $obs['is_ssl']['port_80'], 'http://' . $baseUrl);
    $expect('url() X-Forwarded-Proto=https 不算 https（真 is_ssl 不看它）', $obs['is_ssl']['xfp_https'], 'http://' . $baseUrl);

    // 真 status_header()：状态行字面量 + 状态码进了真过滤器 + send() 的 echo 体
    $expect('send() 全程 headers_sent() 为 false（否则状态行断言空转）', $obs['send']['headers_sent_observed'], [false]);
    $expect('404 的状态行经真 apply_filters(status_header)', $obs['send']['status_404']['filter'][0]['line'] ?? null, 'HTTP/1.1 404 Not Found');
    $expect('404 的状态码原样进真过滤器（不是只拼了个字符串）', $obs['send']['status_404']['filter'][0]['code'] ?? null, 404);
    $expect('send() echo 出 body', $obs['send']['status_404']['body'], 'body-404');
    $expect('send() 返回 null（已自行输出的标记，调用方据此不重复输出）', $obs['send']['status_404']['returned'], null);
    $expect('200 的状态行是 OK', $obs['send']['status_200']['filter'][0]['line'] ?? null, 'HTTP/1.1 200 OK');
    $expect('file() 读不出 → 404（同上真 status_header）', $obs['send']['file_missing']['filter'][0]['line'] ?? null, 'HTTP/1.1 404 Not Found');
    $expect('file() 读不出 → 空体', $obs['send']['file_missing']['body'], '');
    $expect('协议不在白名单（HTTP/9.9）→ 回落 HTTP/1.0', $obs['send']['bogus_protocol']['filter'][0]['line'] ?? null, 'HTTP/1.0 404 Not Found');
    $expect('CLI 下没有 SERVER_PROTOCOL → 同样回落 HTTP/1.0', $obs['send']['no_protocol']['filter'][0]['line'] ?? null, 'HTTP/1.0 404 Not Found');

    // 真 add_action()/do_action()/WP_Hook
    $expect('探针起手时 plugins_loaded 上没有任何钩子', $obs['hooks']['plugins_loaded_before'], false);
    $expect('探针起手时 shutdown 上没有任何钩子', $obs['hooks']['shutdown_before'], false);
    $expect('register() 的优先级真的是 PHP_INT_MIN（真 WP_Hook 原样保留，不夹取）', $obs['hooks']['registered_priority'], $obs['hooks']['php_int_min']);
    $expect('do_action(plugins_loaded) 真跑到了本包处理器：旁观者看到 RequestAdapter', $obs['hooks']['witness']['request_class'] ?? null, 'ErikWang2013\\Xhprof\\Wordpress\\Adapter\\RequestAdapter');
    $expect('处理器跑完了 bootstrap：配置经真链路进 Xhprof::$key_prefix', $obs['hooks']['witness']['key_prefix'] ?? null, 'xhprof-wpcontract');
    $expect('采样已开：shutdown 止点注册上了', $obs['hooks']['shutdown_after'], true);
    $expect('止点的优先级是 PHP_INT_MAX（WP_Hook 里真的落在最高一档）', $obs['hooks']['shutdown_priorities'], [$obs['hooks']['php_int_max']]);
    $expect('shutdown 上恰好一个回调（不会重复注册）', $obs['hooks']['shutdown_callback_count'], 1);

    $l2Checks = $checks;

    // ================= L3：真 WordPress 引导（SQLite 后端） =================
    //
    // L2-lite 跑的是**核心函数**，没有站点、没有引导——所以「mu-plugin 真的被加载了吗」
    // 「plugins_loaded 到底什么时候烧」「致命错误下 shutdown 动作还来不来」这三问它答不了
    // （README 的「未自动化验证」表里就是这三条）。要答就得让真 wp-settings.php 跑起来，
    // 而它必须读 active_plugins 选项 → 必须有数据库。
    //
    // 走 SQLite：`wordpress/sqlite-database-integration`（官方插件，composer 锁版本）提供
    // `wp-content/db.php` drop-in，把 MySQL 方言翻译到 SQLite——于是 `wp_install()` 与真引导
    // 都不需要外部服务。**没有假 $wpdb**：真 WP 核心 + 真 drop-in + 真 `wp_install()`，
    // 数据库就是那个 SQLite 文件。
    //
    // 观测只能从进程外拿：探针是**普通 mu-plugin / 普通插件**（WP 自己的加载顺序决定谁先谁后），
    // 它们只记录时点事实，不改本包行为。三条断言链：
    //   ① mu-plugin 被收进并 include（且此刻普通插件还没加载、plugins_loaded 还没烧）
    //   ② do_action('plugins_loaded') 真的调到本包的处理器（正对照：普通插件 @10 的回调同轮也跑了）
    //   ③ 致命错误下 shutdown 止点照样执行，且采样真的落了库（Redis 里本进程新增一条 run）
    $sqlitePkg = contracts_dir() . '/vendor/wordpress/sqlite-database-integration';
    if (!is_file($sqlitePkg . '/wp-includes/sqlite/db.php')) {
        return [
            'status' => 'FAIL',
            'detail' => 'tools/contracts/vendor 里没有 wordpress/sqlite-database-integration'
                . '（真 WP 引导的 SQLite drop-in），先跑 composer install -d tools/contracts',
            'skips' => 0,
        ];
    }

    // 落库断言读真 Redis。前置与 Redis 卡同一台（contracts.yml 的 services: redis），
    // 缺了是 FAIL 而不是 SKIP：EXPECTED_SKIPS 是签字常量，不许随环境漂移。
    $redis = new \Redis();
    try {
        $redis->connect('127.0.0.1', 6379, 1.0);
        $redis->ping();
    } catch (\Throwable $e) {
        return [
            'status' => 'FAIL',
            'detail' => '连不上 127.0.0.1:6379 的 Redis（本卡 L3 落库断言的前置，与 Redis 卡同一台）：'
                . $e->getMessage(),
            'skips' => 0,
        ];
    }

    $site = sys_get_temp_dir() . '/xhprof-contract-wpboot-' . getmypid();
    // 夹具收尾：挂 shutdown 覆盖所有退出路径（成功、提前 return 的 FAIL、异常都算）。
    // FAIL 时留着不删——那是唯一能进现场复现的东西，路径写进 detail。
    $keepSite = false;
    register_shutdown_function(static function () use ($site, &$keepSite): void {
        if (!$keepSite) {
            wordpress_rmdir_recursive($site);
        }
    });

    /** 子进程 stderr 先剔掉本机 Xdebug 的 ini 噪音再截断——归因时不被它淹没。 */
    $diag = static function (string $text, int $limit = 600): string {
        $clean = trim((string) preg_replace('/^Xdebug:.*$/m', '', $text));

        return strlen($clean) > $limit ? substr($clean, 0, $limit) . '…' : $clean;
    };
    $buildError = wordpress_build_site($site, $wpRoot, $repoRoot, $sqlitePkg);
    if ($buildError !== null) {
        return ['status' => 'FAIL', 'detail' => '真 WP 站点建不出来：' . $buildError, 'skips' => 0];
    }

    // 夹具确实用了**仓库里那份**真 mu-plugin 与**composer 包里那份**真 drop-in：
    // 站点是生成的，但这两件必须是原件的字节。
    $muSource = $repoRoot . '/wordpress/xhprof-webman.php';
    $expect('L3 夹具用的是仓库里那份真 mu-plugin（字节一致，没有另写一份）',
        md5_file($site . '/wp-content/mu-plugins/xhprof-webman.php'), md5_file($muSource));
    $expect('L3 夹具用的是 composer 包里那份真 SQLite drop-in（db.copy 原样）',
        md5_file($site . '/wp-content/db.php'), md5_file($sqlitePkg . '/db.copy'));

    /** 起一次真引导，返回观测快照（探针在 shutdown 里写盘）。 */
    $bootObserve = static function (string $mode) use ($site, $redis, $diag, &$failures, &$checks): array {
        $out = $site . '/obs-' . $mode . '.json';
        @unlink($out);
        // 引导前的最新 run_id：止点落库断言靠它做「本进程新增」的差分（不是"有个 run 就算过"）。
        $headBefore = (string) $redis->lIndex('xhprof:run_id', 0);
        $run = contracts_run_php([$site . '/boot.php', $mode, $out, $headBefore]);
        $checks++;
        if (!is_file($out)) {
            $failures[] = "真引导（{$mode}）没写出观测文件（exit {$run['code']}）："
                . $diag((string) ($run['stderr'] !== '' ? $run['stderr'] : $run['stdout']));

            return ['__run' => $run, '__obs' => []];
        }
        $obs = json_decode((string) file_get_contents($out), true);

        return [
            '__run' => $run,
            '__obs' => is_array($obs) ? $obs : [],
        ];
    };

    $install = $bootObserve('install');
    if (($install['__run']['code'] ?? -1) !== 0
        || !str_contains((string) ($install['__run']['stdout'] ?? ''), 'blog_installed=yes')
    ) {
        $keepSite = true;

        return [
            'status' => 'FAIL',
            'detail' => '真 wp_install()（SQLite 后端）没成功：exit ' . ($install['__run']['code'] ?? '?')
                . '；stdout=' . trim((string) ($install['__run']['stdout'] ?? ''))
                . '；stderr=' . $diag((string) ($install['__run']['stderr'] ?? ''))
                . "；夹具留在 {$site}",
            'skips' => 0,
        ];
    }

    $life = $bootObserve('normal');
    $fatal = $bootObserve('fatal');
    $lifeObs = $life['__obs'];
    $fatalObs = $fatal['__obs'];

    // ---- ① mu-plugin 真的被 WP 收进并 include（mu-plugins 阶段）----
    $expect('L3 WP 把本包的 mu-plugin 收进了 mu-plugins 列表（真的被 include 了）',
        $lifeObs['mu_phase']['mu_plugins_order'] ?? null, ['xhprof-webman.php', 'zz-contracts-fatal.php', 'zz-contracts-probe.php']);
    $expect('L3 mu-plugin 加载时还没到 muplugins_loaded（时点在 WP 引导早期）',
        $lifeObs['mu_phase']['did_muplugins_loaded'] ?? null, 0);
    $expect('L3 mu-plugin 加载时 plugins_loaded 还没烧（更早）',
        $lifeObs['mu_phase']['did_plugins_loaded'] ?? null, 0);
    $expect('L3 mu-plugin 加载时普通插件还没被 include（:471 在 :560 之前）',
        $lifeObs['mu_phase']['probe_plugin_loaded'] ?? null, false);
    $expect('L3 mu-plugin 在 mu-plugins 阶段就把处理器挂上了 plugins_loaded@PHP_INT_MIN',
        $lifeObs['mu_phase']['min_bucket'] ?? null,
        ['ErikWang2013\\Xhprof\\Wordpress\\XhprofPlugin::onPluginsLoaded']);

    // ---- ② plugins_loaded 的时点与顺序 ----
    $expect('L3 正对照：探针普通插件确实被 include 了（否则下面那条是空转）',
        $lifeObs['plugin_phase']['did_muplugins_loaded'] ?? null, 1);
    $expect('L3 普通插件被 include 时采样还没起（plugins_loaded 未触发）',
        $lifeObs['plugin_phase']['xhprof_config_yet'] ?? null, false);
    $expect('L3 普通插件 include 时 plugins_loaded 计数器仍是 0',
        $lifeObs['plugin_phase']['did_plugins_loaded'] ?? null, 0);
    $expect('L3 do_action(plugins_loaded) 真的调用了本包处理器（配置经真链路进 Xhprof::$config）',
        $lifeObs['after_xhprof_at_min']['config_is_wordpress_adapter'] ?? null, true);
    $expect('L3 处理器运行时 plugins_loaded 正在分发中（did_action=1）',
        $lifeObs['after_xhprof_at_min']['did_plugins_loaded'] ?? null, 1);
    $expect('L3 普通插件的 plugins_loaded 回调在同一轮 do_action 里也跑了（窗口开在普通插件之前）',
        $lifeObs['plugins_loaded_priority10']['config_set'] ?? null, true);
    $expect('L3 真引导走到了 wp_loaded（wp-settings.php 全程跑完）',
        $lifeObs['boot_phase']['did_wp_loaded'] ?? null, 1);
    $expect('L3 引导的是真安装过的站点（选项表里有 active_plugins）',
        $lifeObs['boot_phase']['active_plugins'] ?? null, ['contracts-probe/contracts-probe.php']);

    // ---- ③ shutdown 生命周期的两个落点：正常路径 & 致命错误 ----
    $expect('L3 正常路径：WP 自己触发了 shutdown 动作', $lifeObs['shutdown_phase']['did_shutdown_action'] ?? null, 1);
    $expect('L3 正常路径：本包的 shutdown 止点真的执行了（stopped=true）',
        $lifeObs['shutdown_phase']['xhprof_stopped'] ?? null, true);
    $expect('L3 正常路径：止点落库了（本进程在 Redis 里新增一条 run，不只是"列表里有东西"）',
        $lifeObs['shutdown_phase']['run_saved'] ?? null, true);
    $expect('L3 正常路径：落库的 run_id 是 16 位小写十六进制（与 get_run() 白名单同形）',
        preg_match('/^[a-f0-9]{16}$/', (string) ($lifeObs['shutdown_phase']['head_run_id'] ?? '')), 1);

    $expect('L3 致命错误：子进程 exit 255（真 fatal，不是被 catch 的异常）',
        $fatal['__run']['code'] ?? null, 255);
    $expect('L3 致命错误：stderr 里就是那条真错误',
        str_contains((string) ($fatal['__run']['stderr'] ?? ''), 'Uncaught Error: Call to undefined function contracts_probe_undefined_function_call()'),
        true);
    $expect('L3 致命错误发生在采样窗口**之内**（plugins_loaded/wp_loaded 都已发生）',
        [$fatalObs['fatal_phase']['did_plugins_loaded'] ?? null, $fatalObs['fatal_phase']['did_wp_loaded'] ?? null], [1, 1]);
    $expect('L3 致命错误下 WP 照样触发 shutdown 动作', $fatalObs['shutdown_phase']['did_shutdown_action'] ?? null, 1);
    $expect('L3 致命错误下本包的 shutdown 止点照样执行（这就是「WordPress 上的 finally」）',
        $fatalObs['shutdown_phase']['xhprof_stopped'] ?? null, true);
    $expect('L3 致命错误下采样仍然落库（止点不是空转）',
        $fatalObs['shutdown_phase']['run_saved'] ?? null, true);

    // 收尾：把本卡在默认前缀下写进去的 run 删干净（三条：install/正常/致命），
    // 只按 run_id 精确删，绝不动 xhprof:run_id 里别人的数据（多项目共用一台 Redis）。
    $removed = 0;
    foreach ([$install, $life, $fatal] as $one) {
        $obs = $one['__obs']['shutdown_phase'] ?? [];
        // 只删「本次真的新落了库」的那条。run_saved=false 的腿（install 腿不模拟请求，
        // 止点本就该空转）手里那个 head_run_id 是引导前就在列表里的**别人的** run——
        // 按它 lRem 等于替别人删数据。实测过：修 REQUEST_URI 之前三条腿的 run_saved
        // 全是 false，收尾照样"删掉 3 条"，删的是引导前的旧 id。
        if (($obs['run_saved'] ?? false) !== true) {
            continue;
        }
        $rid = (string) ($obs['head_run_id'] ?? '');
        if ($rid === '') {
            continue;
        }
        // phpredis 的参数序是 (key, value, count) —— 与 redis-cli 的 LREM key count value 相反。
        // 计数取 lRem 的返回值（真的删掉几条），不再自增——自增会把"没删到"记成"删掉了"。
        $removed += (int) $redis->lRem('xhprof:run_id', $rid, 1);
        $redis->del('xhprof:request_log:' . $rid, 'xhprof:xhprof_log:' . $rid);
    }
    $expect('L3 收尾：正常/致命两条腿写进去的 run 键都按 id 精确删掉了（不给 Redis 留垃圾）', $removed, 2);

    if ($failures !== []) {
        $keepSite = true;

        return [
            'status' => 'FAIL',
            'detail' => count($failures) . '/' . $checks . " 项断言失败（L2-lite 真 WP 核心源码 + L3 真 WP 引导，"
                . 'WordPress ' . basename($wpRoot) . "）：\n  - " . implode("\n  - ", $failures)
                . "\n（L3 夹具留在 {$site}，可进去复现）",
            'skips' => 0,
        ];
    }

    wordpress_rmdir_recursive($site);

    return [
        'status' => 'PASS',
        'detail' => count($sources) . ' 个源文件语法通过；源码里的 '
            . count(WORDPRESS_EXPECTED_FUNCTIONS) . ' 个 WP 全局函数（'
            . implode('、', WORDPRESS_EXPECTED_FUNCTIONS) . '）在真实 wordpress-stubs 中逐一存在，'
            . '参数名/可选性/默认值/返回类型与 tests/Stubs/Framework/Wordpress.php 逐字段一致；'
            . 'php-stubs 的函数体是空壳（实测 wp_unslash/is_ssl → null），故 L2 不拿它跑。'
            . ' L2-lite：' . $l2Checks . ' 项断言跑在真 WordPress 核心源码上（'
            . 'wp_unslash 的递归/透传经 get()/all()/uri() 三个调用点、is_ssl 的九种 $_SERVER 组合经 url()、'
            . 'status_header 的状态行经真过滤器、add_action/do_action/WP_Hook 的真调度与优先级）。'
            . ' L3：' . ($checks - $l2Checks) . ' 项断言跑在**真引导的 WordPress 站点**上'
            . '（真核心 + 官方 SQLite drop-in + 真 wp_install()，无 MySQL、无假 $wpdb）：'
            . 'mu-plugin 进 mu-plugins 列表且此刻普通插件/plugins_loaded 都还没到；'
            . 'do_action(plugins_loaded) 真调到本包处理器（正对照：普通插件 @10 同轮也跑了）；'
            . '正常与**致命错误**两条路径下 WP 都触发了 shutdown 动作、本包止点都执行了、'
            . '采样都真的落了库（Redis 里按 run_id 差分验证，跑完按 id 删净）。',
        'skips' => 0,
    ];
};

/**
 * 建一个真 WordPress 站点（真核心 + SQLite drop-in + 真 mu-plugin + 只读探针）。
 *
 * 站点树就在临时目录里（真核心 68MB 复制一份 0.3s，换来 vendor 只读、随用随删）；
 * 探针文件由本函数生成，仓库里那份 mu-plugin 与 composer 包里那份 drop-in 都是**原样复制**。
 *
 * @return string|null 失败原因，null = 建好了
 */
function wordpress_build_site(string $site, string $wpRoot, string $repoRoot, string $sqlitePkg): ?string
{
    if (!file_exists($wpRoot . '/wp-settings.php') || !file_exists($sqlitePkg . '/load.php')) {
        return "源目录不完整：{$wpRoot} / {$sqlitePkg}";
    }

    foreach (['', '/vendor', '/wp-content', '/wp-content/mu-plugins', '/wp-content/plugins/contracts-probe'] as $sub) {
        if (!mkdir($site . $sub, 0777, true) && !is_dir($site . $sub)) {
            return "建不出目录 {$site}{$sub}";
        }
    }

    wordpress_copy_tree($wpRoot, $site);
    wordpress_copy_tree($sqlitePkg, $site . '/wp-content/plugins/sqlite-database-integration');
    copy($sqlitePkg . '/db.copy', $site . '/wp-content/db.php');
    copy($repoRoot . '/wordpress/xhprof-webman.php', $site . '/wp-content/mu-plugins/xhprof-webman.php');

    // 站级 composer 自动加载器的等价物（真安装里由 composer 生成）：本包的 PSR-4 前缀。
    // mu-plugin 只认 ABSPATH.'vendor/autoload.php' 这一个路径（包内文件里写明了这条上限）。
    $autoload = <<<'PHP'
<?php
// 契约环夹具：站级 composer 自动加载器的等价物，只注册本包的 PSR-4 前缀。
$repoRoot = '%REPO%';
spl_autoload_register(static function (string $class) use ($repoRoot): void {
    $prefix = 'ErikWang2013\\Xhprof\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = $repoRoot . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
PHP;
    file_put_contents($site . '/vendor/autoload.php', str_replace('%REPO%', $repoRoot, $autoload));

    // wp-config.php：与 wp-config-sample.php 同形，只是 DB 常量给一组"反正也连不上"的值
    // ——真数据库由 wp-content/db.php 这个 SQLite drop-in 提供，它不看这些常量。
    file_put_contents($site . '/wp-config.php', <<<'PHP'
<?php
// 契约环夹具：真 wp-load.php → 真 wp-settings.php，与线上同一条引导路径。
define('DB_NAME', 'wordpress');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
$table_prefix = 'wp_';

define('AUTH_KEY', 'contracts-fixture-key-1');
define('SECURE_AUTH_KEY', 'contracts-fixture-key-2');
define('LOGGED_IN_KEY', 'contracts-fixture-key-3');
define('NONCE_KEY', 'contracts-fixture-key-4');
define('AUTH_SALT', 'contracts-fixture-salt-1');
define('SECURE_AUTH_SALT', 'contracts-fixture-salt-2');
define('LOGGED_IN_SALT', 'contracts-fixture-salt-3');
define('NONCE_SALT', 'contracts-fixture-salt-4');

define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', true);
define('WP_HOME', 'http://xhprof-contract.test');
define('WP_SITEURL', 'http://xhprof-contract.test');

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
PHP);

    // 引导脚本：argv = 1=install|normal|fatal 2=观测输出文件 3=引导前最新 run_id。
    // 观测输出由探针在 shutdown 里写（见下面 zz-contracts-probe.php），所以这里不写文件。
    file_put_contents($site . '/boot.php', <<<'PHP'
<?php
// 契约环夹具：真 WordPress 引导。install 走 WP_INSTALLING + wp_install()，其余是正常引导。
$mode = $argv[1] ?? 'normal';

if ($mode === 'install') {
    define('WP_INSTALLING', true);
    require __DIR__ . '/wp-load.php';
    require ABSPATH . 'wp-admin/includes/upgrade.php';
    if (!is_blog_installed()) {
        wp_install('Xhprof Contract Fixture', 'admin', 'admin@xhprof-contract.test', true, '', 'password');
    }
    update_option('active_plugins', ['contracts-probe/contracts-probe.php']);
    echo 'blog_installed=' . (is_blog_installed() ? 'yes' : 'no'), "\n";
    exit(0);
}

// 正常/致命两条腿模拟一次真实 HTTP 请求最少的三个超全局。**不是装饰**：CLI 下
// $_SERVER['REQUEST_URI'] 为空 -> RequestAdapter::uri() 返回 '' -> XhprofLib::isIgnore()
// 的 empty($request_uri) 分支返回 false -> XHProfRunsDefault::save_run() 第一道门
// `if (!isIgnore()) return false` 早退，止点跑了但一条 run 都不会落库。
// （实测：同一夹具同一次引导，只切这一个变量，run_id 列表长度 270 → 271 / 270 → 270。）
// 真 WP 的 web 请求里这个变量必然存在；install 腿不设——那是装库不是请求，
// 且留空恰好保证安装过程不产生采样。
$_SERVER['REQUEST_URI'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'contract-fixture.test';

$t0 = microtime(true);
require __DIR__ . '/wp-load.php';
$GLOBALS['contracts_probe']['boot_phase'] = [
    'boot_ms' => (int) round((microtime(true) - $t0) * 1000),
    'did_wp_loaded' => did_action('wp_loaded'),
    'active_plugins' => get_option('active_plugins'),
    'request_uri' => $_SERVER['REQUEST_URI'],
];
PHP);

    file_put_contents($site . '/wp-content/plugins/contracts-probe/contracts-probe.php', <<<'PHP'
<?php
/**
 * Plugin Name: Contracts Probe
 * Description: 契约环探针：普通（非 mu）插件，只记录 plugins_loaded 的时点。
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

define('CONTRACTS_PROBE_PLUGIN_FILE_LOADED', __FILE__);

$GLOBALS['contracts_probe']['plugin_phase'] = [
    'did_plugins_loaded' => did_action('plugins_loaded'),
    'did_muplugins_loaded' => did_action('muplugins_loaded'),
    'xhprof_config_yet' => ErikWang2013\Xhprof\Core\Xhprof::$config !== null,
];

add_action('plugins_loaded', function (): void {
    $GLOBALS['contracts_probe']['plugins_loaded_priority10'] = [
        'did_plugins_loaded' => did_action('plugins_loaded'),
        'config_set' => ErikWang2013\Xhprof\Core\Xhprof::$config !== null,
    ];
}, 10);
PHP);

    // mu-plugin 阶段的探针：字母序在 xhprof-webman.php 之后（'x' < 'z'），所以本文件被
    // include 时那份真 mu-plugin 已经跑过 register() —— 这正是「谁先谁后」的证据。
    file_put_contents($site . '/wp-content/mu-plugins/zz-contracts-probe.php', <<<'PHP'
<?php
// 契约环探针（夹具的一部分，不是 WP 的一部分）：只记录时点事实，不改本包行为。
defined('ABSPATH') || exit;

$GLOBALS['contracts_probe'] = $GLOBALS['contracts_probe'] ?? [];

function contracts_probe_plugin_instance(): ?object
{
    $bucket = $GLOBALS['wp_filter']['plugins_loaded']->callbacks[PHP_INT_MIN] ?? [];
    foreach ($bucket as $entry) {
        $fn = $entry['function'];
        if (is_array($fn) && is_object($fn[0])) {
            return $fn[0];
        }
    }

    return null;
}

$GLOBALS['contracts_probe']['mu_phase'] = [
    'did_muplugins_loaded' => did_action('muplugins_loaded'),
    'did_plugins_loaded' => did_action('plugins_loaded'),
    'probe_plugin_loaded' => defined('CONTRACTS_PROBE_PLUGIN_FILE_LOADED'),
    'min_bucket' => array_map(
        static function (array $entry): string {
            $fn = $entry['function'];

            return is_array($fn) ? get_class($fn[0]) . '::' . $fn[1] : get_debug_type($fn);
        },
        array_values($GLOBALS['wp_filter']['plugins_loaded']->callbacks[PHP_INT_MIN] ?? [])
    ),
    'mu_plugins_order' => array_map('basename', wp_get_mu_plugins()),
];

// 同优先级(PHP_INT_MIN)、注册更晚 → WP_Hook 按注册顺序 FIFO，本回调在真 mu-plugin 之后运行：
// 它跑了就说明本包处理器没抛异常地跑完了。
add_action('plugins_loaded', function (): void {
    $GLOBALS['contracts_probe']['after_xhprof_at_min'] = [
        'config_is_wordpress_adapter' => is_object(ErikWang2013\Xhprof\Core\Xhprof::$config)
            && get_class(ErikWang2013\Xhprof\Core\Xhprof::$config) === 'ErikWang2013\Xhprof\Wordpress\Adapter\ConfigAdapter',
        'did_plugins_loaded' => did_action('plugins_loaded'),
    ];
    // 在 plugins_loaded 里注册 → 必然晚于本包在它自己的 plugins_loaded 回调里注册的止点，
    // 于是同优先级(PHP_INT_MAX)下 FIFO 保证本回调在止点**之后**运行（能看见 stopped 的结果）。
    add_action('shutdown', 'contracts_probe_shutdown_observer', PHP_INT_MAX);
}, PHP_INT_MIN);

function contracts_probe_shutdown_observer(): void
{
    $argv = $_SERVER['argv'] ?? [];
    $out = (string) ($argv[2] ?? '');

    $instance = contracts_probe_plugin_instance();
    $stopped = is_object($instance)
        ? (new ReflectionProperty($instance, 'stopped'))->getValue($instance)
        : null;

    $headBefore = (string) ($argv[3] ?? '');
    $head = null;
    $redisError = null;
    try {
        $r = new Redis();
        $r->connect('127.0.0.1', 6379, 1.0);
        $head = (string) $r->lIndex('xhprof:run_id', 0);
    } catch (Throwable $e) {
        $redisError = $e->getMessage();
    }

    $probe = $GLOBALS['contracts_probe'] ?? [];
    $probe['shutdown_phase'] = [
        'did_shutdown_action' => did_action('shutdown'),
        'xhprof_stopped' => $stopped,
        'head_run_id' => $head,
        'head_before' => $headBefore,
        'run_saved' => $head !== null && $head !== '' && $head !== $headBefore,
        'redis_error' => $redisError,
    ];

    if ($out !== '') {
        file_put_contents($out, json_encode($probe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
PHP);

    // 致命错误探针：只在 argv[1]==='fatal' 时于 wp_loaded（采样窗口之内）制造一次真 fatal。
    file_put_contents($site . '/wp-content/mu-plugins/zz-contracts-fatal.php', <<<'PHP'
<?php
// 契约环探针：跟着引导模式走，只在 fatal 模式触发。
defined('ABSPATH') || exit;

if (($_SERVER['argv'][1] ?? '') !== 'fatal') {
    return;
}

add_action('wp_loaded', function (): void {
    $GLOBALS['contracts_probe']['fatal_phase'] = [
        'did_plugins_loaded' => did_action('plugins_loaded'),
        'did_wp_loaded' => did_action('wp_loaded'),
    ];
    // 真·致命错误：调用不存在的函数（PHP 8 下是未捕获的 Error → fatal，exit 255）。
    contracts_probe_undefined_function_call();
}, 5);
PHP);

    return null;
}

/** 递归复制目录树（WP 核心 2913 个文件实测 0.26s）。 */
function wordpress_copy_tree(string $src, string $dst): void
{
    @mkdir($dst, 0777, true);
    foreach (scandir($src) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (is_dir($src . '/' . $entry)) {
            wordpress_copy_tree($src . '/' . $entry, $dst . '/' . $entry);
        } else {
            copy($src . '/' . $entry, $dst . '/' . $entry);
        }
    }
}

/** 递归删除（夹具收尾；失败不抛——它只是清理）。 */
function wordpress_rmdir_recursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            wordpress_rmdir_recursive($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

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
