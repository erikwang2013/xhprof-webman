<?php

declare(strict_types=1);

/**
 * Yii2 —— 用**真实** yiisoft/yii2（2.0.x）验证 src/Yii2/**。
 *
 * L1（签名存在）：反射断言 src/Yii2 调用的每个方法/常量在真实包里存在。
 *   这一层专杀 `Call to undefined method` —— 最怕的失效模式。
 * L2（契约语义）：全部用真实对象实测。这里钉的不是「实现看起来对」，而是三处**桩测不到**的
 *   真包行为，每一条都带对照：
 *   1) `Response::prepare()` 的 HTML 格式化器**无条件**覆盖 Content-Type（`web/HtmlResponseFormatter.php:37`），
 *      而 `FORMAT_RAW` 一个头都不碰 —— 对照组是同一次断言里把 format 换回默认，头立刻被改。
 *      「适配器必须置 FORMAT_RAW」这条结论就是从这里来的。
 *   2) `prepare()` 在 `data !== null` 时把 data 拷进 content，随后 `is_array($content)` 抛
 *      InvalidArgumentException（`web/Response.php:1122`）—— 对照组直接吃这个异常。
 *   3) `Request::getHeaders()` 经 `filterHeaders()` 滤掉 `secureHeaders`（X-Forwarded-For 等），
 *      所以 `getUserIP()` 默认落到 REMOTE_ADDR；配了 `trustedHosts` 后才认转发头，
 *      且**不是**取首段（`web/Request.php:1286` 从右往左走）。
 *   另有四条真实生命周期/注册事实：
 *   4) `Application::end()` 从 BEFORE_REQUEST 处理器里调用是合法的：状态机走到 SENDING、
 *      响应只发一次、`YII_ENV_TEST` 下抛 ExitException（生产是 `exit`）—— 短路靠它收尾。
 *   5) `yii\console\Application` **不覆写 `run()`**（声明类是 `yii\base\Application`）⇒ CLI 也触发
 *      `EVENT_BEFORE_REQUEST`，而控制台请求**没有 `getUrl()`** ⇒ 入口类的 Web 守卫是必需的。
 *   6) 容器注册形状：`Yii::createObject(['class' => …, 'config' => …, 'cache' => …])` 把其余键
 *      按**公有属性**赋值（`di/Container.php:389`），不走构造参数 —— README 的写法就建在这上面。
 *   7) 真 `yii\web\Application` + `YII_ENV=test` 跑一次 `/xhprof`：短链产出 200 +
 *      `text/html; charset=UTF-8` + `no-cache, private` 的真报告页，业务处理一次都没跑。
 *
 * 已知未覆盖（诚实标注，不是伪装成通过）：
 *   - **不验采样落库**：本 case 全程注入内存版 CacheInterface，不连真 Redis，也不断言 runs。
 *     采样窗口/落库由 tests/Unit/Adapter/Yii2Test.php（含子进程 shutdown 兜底）覆盖，
 *     两者合起来才算覆盖，单独看任何一边都不足。
 *   - 不验 `header()` 的真实发送（CLI 下 `header()` 是 no-op、`headers_list()` 恒空）；
 *     本 case 只断言响应对象上的头与状态，真发头那一半在单测里用 `php -S` 往返覆盖。
 *   - 不验 urlManager/路由/错误页渲染（本包不依赖；报告页与资源都不进路由）。
 *   - `getUserIP()` 的 CIDR 匹配只验「配了 trustedHosts 后认转发头」这一格，不逐个 CIDR 展开。
 */

return static function (): array {
    $autoload = contracts_dir() . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        return [
            'status' => 'FAIL',
            'detail' => 'tools/contracts/vendor 未安装，先跑 composer install -d tools/contracts',
            'skips' => 0,
        ];
    }
    require_once $autoload;

    // 存在性判定必须走**文件**，不能 class_exists('yii\BaseYii')：那会先让 composer 的
    // PSR-4 把 BaseYii.php 加载进来，紧接着 Yii.php 里那句**无条件 `require`**
    // （`yiisoft/yii2/Yii.php:9`，不是 require_once）就会 "Cannot redeclare class yii\BaseYii"
    // 加载期致命错误 —— 实测踩过。
    if (!is_file(contracts_dir() . '/vendor/yiisoft/yii2/Yii.php')) {
        return [
            'status' => 'FAIL',
            'detail' => 'yiisoft/yii2 不在 tools/contracts 依赖里（composer.json 需要加 "yiisoft/yii2": "^2.0" 与 asset-packagist 仓库）',
            'skips' => 0,
        ];
    }

    // yiisoft/yii2 的 composer.json **没有** files autoload（`{"psr-4": {"yii\\": ""}}`），
    // 真应用是在入口脚本里 require Yii.php —— 这里照做，否则 Yii::$container / Yii::createObject
    // 都不存在。**在 require 之前**定义 YII_ENV=test：真包里 `Application::end()` 靠它决定
    // 「抛 ExitException」还是「exit(0)」，两者对测试进程的后果完全不同。
    defined('YII_ENV') or define('YII_ENV', 'test');
    defined('YII_DEBUG') or define('YII_DEBUG', false);
    defined('YII_ENABLE_ERROR_HANDLER') or define('YII_ENABLE_ERROR_HANDLER', false);
    require_once contracts_dir() . '/vendor/yiisoft/yii2/Yii.php';

    // 本项目 src/ 的 PSR-4 自注册 —— 刻意**不**依赖仓库根的 vendor/autoload.php：
    // .github/workflows/contracts.yml 只 install tools/contracts，主仓库的 dev vendor
    // 在 CI 里根本不存在，依赖它会让这个 case 在 CI 上加载期崩溃。
    $repoRoot = contracts_repo_root();
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

    $checks = 0;
    $failures = [];
    $expect = static function (string $label, mixed $actual, mixed $expected) use (&$checks, &$failures): void {
        $checks++;
        if ($actual !== $expected) {
            $failures[] = $label . '：得到 ' . var_export($actual, true) . '，期望 ' . var_export($expected, true);
        }
    };
    $expectContains = static function (string $label, string $haystack, string $needle) use (&$checks, &$failures): void {
        $checks++;
        if (strpos($haystack, $needle) === false) {
            $failures[] = $label . '：' . var_export($needle, true) . ' 不在 ' . var_export($haystack, true) . ' 里';
        }
    };
    $expectTrue = static function (string $label, bool $ok) use (&$checks, &$failures): void {
        $checks++;
        if (!$ok) {
            $failures[] = $label;
        }
    };

    // 必须在 src 自注册**之后**：Fakes.php 里的 FakeCache 加载期就要 implements Core 契约
    require_once $repoRoot . '/tests/Fixtures/Fakes.php';

    // ================= L1：签名存在 =================

    $classes = [
        'yii\base\BootstrapInterface' => ['bootstrap'],
        'yii\base\Event' => [],
        'yii\base\ExitException' => ['__construct'],
        'yii\base\InvalidConfigException' => [],
        'yii\base\Application' => ['run', 'end', 'on', 'trigger', 'hasEventHandlers', 'getRequest', 'getResponse', 'handleRequest'],
        'yii\web\Application' => ['run', 'end', 'getRequest', 'getResponse'],
        'yii\web\Request' => [
            'getUrl', 'getAbsoluteUrl', 'getHostName', 'getHeaders', 'getQueryParams',
            'getBodyParams', 'getMethod', 'getUserIP', 'getRemoteIP', 'getScriptFile',
        ],
        'yii\web\Response' => ['send', 'sendContent', 'prepare', 'getHeaders', 'setStatusCode', 'getStatusCode'],
        'yii\web\HeaderCollection' => ['get', 'set', 'has', 'remove', 'toArray'],
        'yii\web\HtmlResponseFormatter' => ['format'],
        'yii\console\Application' => ['run'],
        'yii\console\Request' => ['resolve'],
        'yii\BaseYii' => ['createObject', 'configure'],
        'yii\di\Container' => ['get', 'has'],
    ];

    foreach ($classes as $name => $methods) {
        $exists = class_exists($name) || interface_exists($name);
        $expect("L1 {$name} 存在", $exists, true);
        if (!$exists) {
            continue;
        }
        $rc = new ReflectionClass($name);
        foreach ($methods as $method) {
            $expect("L1 {$name}::{$method}() 存在", $rc->hasMethod($method), true);
        }
    }

    // 常量与属性：适配器/入口类直接引用它们
    $expect('L1 Application::EVENT_BEFORE_REQUEST', \yii\base\Application::EVENT_BEFORE_REQUEST, 'beforeRequest');
    $expect('L1 Application::EVENT_AFTER_REQUEST', \yii\base\Application::EVENT_AFTER_REQUEST, 'afterRequest');
    $expect('L1 Response::FORMAT_RAW', \yii\web\Response::FORMAT_RAW, 'raw');
    $expect('L1 Response::FORMAT_HTML', \yii\web\Response::FORMAT_HTML, 'html');
    $expect('L1 Application::STATE_END', \yii\base\Application::STATE_END, 6);

    foreach (['format', 'data', 'content', 'stream', 'isSent', 'exitStatus', 'charset', 'statusText'] as $prop) {
        $expect("L1 Response::\${$prop} 属性存在", property_exists(\yii\web\Response::class, $prop), true);
    }
    foreach (['format', 'data', 'content', 'stream'] as $prop) {
        $p = new ReflectionProperty(\yii\web\Response::class, $prop);
        $expect("L1 Response::\${$prop} 是 public（适配器直接赋值）", $p->isPublic(), true);
    }
    $expect('L1 Request::$secureHeaders 默认含 X-Forwarded-For', in_array('X-Forwarded-For', (new \yii\web\Request())->secureHeaders, true), true);
    $expect('L1 Request::$trustedHosts 默认空（不信任任何转发头）', (new \yii\web\Request())->trustedHosts, []);

    // 适配器必须实现 Core 的 5 个契约
    foreach ([
        'RequestAdapter' => \ErikWang2013\Xhprof\Core\Contract\RequestInterface::class,
        'ResponseAdapter' => \ErikWang2013\Xhprof\Core\Contract\ResponseInterface::class,
        'ConfigAdapter' => \ErikWang2013\Xhprof\Core\Contract\ConfigInterface::class,
        'RedisAdapter' => \ErikWang2013\Xhprof\Core\Contract\CacheInterface::class,
        'LogAdapter' => \ErikWang2013\Xhprof\Core\Contract\LoggerInterface::class,
    ] as $short => $contract) {
        $class = 'ErikWang2013\\Xhprof\\Yii2\\Adapter\\' . $short;
        $expect("L1 {$short} 实现 " . substr($contract, strrpos($contract, '\\') + 1), is_subclass_of($class, $contract), true);
    }
    $expect(
        'L1 XhprofBootstrap 实现 yii\base\BootstrapInterface',
        is_subclass_of(\ErikWang2013\Xhprof\Yii2\XhprofBootstrap::class, \yii\base\BootstrapInterface::class),
        true
    );
    $ctor = new ReflectionMethod(\ErikWang2013\Xhprof\Yii2\XhprofBootstrap::class, '__construct');
    $allOptional = true;
    foreach ($ctor->getParameters() as $param) {
        if (!$param->isOptional()) {
            $allOptional = false;
        }
    }
    $expectTrue('L1 XhprofBootstrap::__construct 全部可选（bootstrap 数组里只写类名也成立）', $allOptional);
    foreach (['config', 'cache', 'logger'] as $prop) {
        $p = new ReflectionProperty(\ErikWang2013\Xhprof\Yii2\XhprofBootstrap::class, $prop);
        $expect("L1 XhprofBootstrap::\${$prop} 是 public（容器按属性赋值）", $p->isPublic(), true);
    }

    // ================= L2：真实 Request =================

    $server = $_SERVER;
    $get = $_GET;
    try {
        $_SERVER = [
            'REQUEST_URI' => '/business?run=abc&source=x',
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'example.com:8080',
            'REMOTE_ADDR' => '203.0.113.9',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            // 真包 `getScriptUrl()`（web/Request.php:882）要求 SCRIPT_NAME 的 basename 与
            // SCRIPT_FILENAME 的 basename 一致，否则抛 InvalidConfigException
            // ——「Unable to determine the entry script URL」（实测踩过）。
            'SCRIPT_FILENAME' => contracts_dir() . '/case-runner.php',
            'SCRIPT_NAME' => '/case-runner.php',
        ];
        $_GET = ['run' => 'abc', 'source' => 'x'];

        // 真 Web 应用**先**建：`Response::init()` 会读 `Yii::$app->charset`
        // （web/Response.php:259），应用还不存在时每次 new Response() 都是一条 warning。
        // 这里只建不跑，事件处理器挂上但不会触发（触发在文件末尾那次针对 /xhprof 的 run()）。
        $app = new \yii\web\Application([
            'id' => 'contracts-web',
            'basePath' => contracts_dir(),
            'bootstrap' => [[
                'class' => \ErikWang2013\Xhprof\Yii2\XhprofBootstrap::class,
                'config' => ['enable' => false],
                'cache' => new \ErikWang2013\Xhprof\Tests\Fixtures\FakeCache(),
            ]],
        ]);

        $request = new \yii\web\Request();
        $adapter = new \ErikWang2013\Xhprof\Yii2\Adapter\RequestAdapter($request);

        $expect('L2 R-1：uri() = 真包 getUrl() = path+query', $adapter->uri(), '/business?run=abc&source=x');
        $expect('L2 R-1：uri() 不含 scheme/host', strpos($adapter->uri(), '://'), false);
        $expect('L2 R-2：host() 去掉端口', $adapter->host(), 'example.com');
        $expect('L2 host() 与真包 getHostName() 同源', $adapter->host(), (string) $request->getHostName());
        $expect('L2 url() = 真包 getAbsoluteUrl()（含端口）', $adapter->url(), 'http://example.com:8080/business?run=abc&source=x');
        $expect('L2 method()', $adapter->method(), 'GET');
        $expect('L2 R-3：缺省 header() 返回 null 不是空串', $adapter->header('x-none'), null);
        $expect('L2 get() 取 query', $adapter->get('run'), 'abc');
        $expect('L2 get() 缺省回默认值', $adapter->get('missing', 'D'), 'D');
        $expect('L2 all() 与 get() 同源', $adapter->all(), ['run' => 'abc', 'source' => 'x']);
        $expect('L2 R-3：无转发头时 getRealIp() = REMOTE_ADDR', $adapter->getRealIp(), '203.0.113.9');

        // 真包会把 REQUEST_URI / getallheaders 的转发头滤掉（secureHeaders）：
        // 这正是适配器「未配 trustedHosts 不信任转发头」的依据
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
        $request2 = new \yii\web\Request();
        $expect('L2 真包默认滤掉 X-Forwarded-For（header() 取不到）', $request2->getHeaders()->has('X-Forwarded-For'), false);
        $expect('L2 同上 ⇒ 适配器 getRealIp() 仍是 REMOTE_ADDR', (new \ErikWang2013\Xhprof\Yii2\Adapter\RequestAdapter($request2))->getRealIp(), '203.0.113.9');

        // 配了 trustedHosts 才认转发头，且取的是「右起第一个不可信地址」而不是首段
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 5.6.7.8';
        $request4 = new \yii\web\Request(['trustedHosts' => ['203.0.113.9']]);
        $expect('L2 配 trustedHosts 后认转发头（右起第一个不可信地址）', $request4->getUserIP(), '5.6.7.8');
        $expect('L2 适配器照抄真包语义', (new \ErikWang2013\Xhprof\Yii2\Adapter\RequestAdapter($request4))->getRealIp(), '5.6.7.8');
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);

        // 真包 getUrl() 在三个来源都缺时抛 InvalidConfigException；适配器退化成空串
        $savedRequestUri = $_SERVER['REQUEST_URI'];
        unset($_SERVER['REQUEST_URI']);
        $threw = null;
        try {
            (new \yii\web\Request())->getUrl();
        } catch (\Throwable $e) {
            $threw = $e;
        }
        $expect('L2 真包 getUrl() 无来源时抛 InvalidConfigException', $threw instanceof \yii\base\InvalidConfigException, true);
        $expect('L2 适配器 uri() 退化成空串（不把请求打成 500）', (new \ErikWang2013\Xhprof\Yii2\Adapter\RequestAdapter(new \yii\web\Request()))->uri(), '');
        $_SERVER['REQUEST_URI'] = $savedRequestUri;

        // body 解析：真包配了 JsonParser 后畸形 JSON 会抛，适配器必须退化成「只有 query」
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $_SERVER['HTTP_CONTENT_TYPE'] = 'application/json';
        $jsonRequest = new \yii\web\Request(['parsers' => ['application/json' => 'yii\web\JsonParser']]);
        $bodyThrew = null;
        try {
            $jsonRequest->setRawBody('{bad json');
            $jsonRequest->getBodyParams();
        } catch (\Throwable $e) {
            $bodyThrew = $e;
        }
        $expectTrue(
            'L2 真包对畸形 JSON 抛异常（' . ($bodyThrew === null ? '没抛！' : get_class($bodyThrew)) . '）',
            $bodyThrew instanceof \Throwable
        );
        $expect(
            'L2 适配器 all() 在 body 解析失败时只剩 query（等价于 GET 语义）',
            (new \ErikWang2013\Xhprof\Yii2\Adapter\RequestAdapter($jsonRequest))->all(),
            ['run' => 'abc', 'source' => 'x']
        );
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_CONTENT_TYPE']);

        // ================= L2：真实 Response =================

        $realResponse = new \yii\web\Response();
        $responseAdapter = new \ErikWang2013\Xhprof\Yii2\Adapter\ResponseAdapter($realResponse);
        $expect('L2 ResponseAdapter::send() 交回的**就是**应用那个响应对象', $responseAdapter->send(), $realResponse);

        // 1) FORMAT_RAW 保头 vs 默认格式覆盖头（对照组在同一次断言里）
        $rawOk = new \yii\web\Response();
        $rawAdapter = new \ErikWang2013\Xhprof\Yii2\Adapter\ResponseAdapter($rawOk);
        $rawAdapter->file($repoRoot . '/src/html/css/xhprof.css')->withHeaders(['Cache-Control' => 'public, max-age=86400']);
        ob_start();
        $rawOk->send();
        $rawBody = (string) ob_get_clean();
        $expect('L2 资源 Content-Type 没被格式化器覆盖', $rawOk->getHeaders()->get('Content-Type'), 'text/css');
        $expect('L2 R-5：file() 之后 withHeaders() 仍生效', $rawOk->getHeaders()->get('Cache-Control'), 'public, max-age=86400');
        $expectContains('L2 file() 真读了包内 css', $rawBody, '--xp-bg');

        $clobbered = new \yii\web\Response();
        $clobbered->content = 'body{}';
        $clobbered->getHeaders()->set('Content-Type', 'text/css');
        ob_start();
        $clobbered->send();
        ob_end_clean();
        $expect(
            'L2 对照组：同一内容不置 FORMAT_RAW 时 Content-Type 被覆盖成 text/html（证明上面那条有判别力）',
            $clobbered->getHeaders()->get('Content-Type'),
            'text/html; charset=UTF-8'
        );

        // 2) data 覆盖 content → 抛 InvalidArgumentException（对照组）+ 适配器置 data=null 后不抛
        $control = new \yii\web\Response();
        $control->format = \yii\web\Response::FORMAT_RAW;
        $control->data = ['not' => 'a string'];
        $controlThrew = null;
        try {
            ob_start();
            $control->send();
        } catch (\Throwable $e) {
            $controlThrew = $e;
        } finally {
            ob_end_clean();
        }
        $expect(
            'L2 对照组：data 非 null 时 RAW 分支把 data 拷进 content 并抛 yii\\base\\InvalidArgumentException',
            $controlThrew instanceof \yii\base\InvalidArgumentException,
            true
        );
        // 顺带钉住真实类层级：它不是 PHP 内置的 \InvalidArgumentException
        // （`base/InvalidParamException.php:17` → extends \BadMethodCallException）
        $expect('L2 yii\\base\\InvalidArgumentException 不是内置异常的子类', $controlThrew instanceof \InvalidArgumentException, false);

        $neutralised = new \yii\web\Response();
        $neutralised->data = ['not' => 'a string'];
        $adapter2 = new \ErikWang2013\Xhprof\Yii2\Adapter\ResponseAdapter($neutralised);
        $adapter2->withBody('<html>report</html>');
        $sendThrew = null;
        try {
            ob_start();
            $neutralised->send();
        } catch (\Throwable $e) {
            $sendThrew = $e;
        } finally {
            ob_end_clean();
        }
        $expect('L2 withBody() 之后 send() 不抛（data 已被清掉）', $sendThrew, null);
        $expect('L2 withBody() 写入的正文原样保留', $neutralised->content, '<html>report</html>');
        $expect('L2 withBody() 置了 FORMAT_RAW', $neutralised->format, \yii\web\Response::FORMAT_RAW);

        // 3) 状态与头
        $statusProbe = new \ErikWang2013\Xhprof\Yii2\Adapter\ResponseAdapter(new \yii\web\Response());
        $statusProbe->withStatus(403)->withHeaders(['X-A' => 1, 'X-Multi' => ['a', 'b']])->withBody('403 Forbidden');
        /** @var \yii\web\Response $statusProbeResponse */
        $statusProbeResponse = $statusProbe->send();
        $expect('L2 withStatus() 落到真响应上', $statusProbeResponse->getStatusCode(), 403);
        $expect('L2 withHeaders() 把 int 值归一成字符串', $statusProbeResponse->getHeaders()->get('X-A'), '1');
        $expect('L2 withHeaders() 的数组值原样交给 HeaderCollection', $statusProbeResponse->getHeaders()->get('X-Multi', null, false), ['a', 'b']);
        $expect('L2 file() 缺文件退化成 404', $statusProbe->file('/nonexistent/x.css')->send()->getStatusCode(), 404);

        // 4) ConfigAdapter（R-6/R-7）
        $cfg = new \ErikWang2013\Xhprof\Yii2\Adapter\ConfigAdapter(['ignore_url_arr' => ['/admin']]);
        $expect('L2 R-6：get(\'xhprof\') 返回整块', is_array($cfg->get('xhprof')), true);
        $expect('L2 R-6：get(\'xhprof.assets_url\') 返回叶子', $cfg->get('xhprof.assets_url'), '/xhprof-assets');
        $expect('L2 R-7：用户列表整体替换，不与默认值逐下标合并', $cfg->get('xhprof.ignore_url_arr'), ['/admin']);

        // ================= L2：真实应用生命周期 =================
        // 5) 容器注册形状：非 Configurable 类按公有属性赋值
        $injectedCache = new \ErikWang2013\Xhprof\Tests\Fixtures\FakeCache();
        $entry = \Yii::createObject([
            'class' => \ErikWang2013\Xhprof\Yii2\XhprofBootstrap::class,
            'config' => ['enable' => false, 'assets_url' => '/cdn'],
            'cache' => $injectedCache,
        ]);
        $expect('L2 容器把 config 键按属性赋值（不走构造参数）', $entry->config, ['enable' => false, 'assets_url' => '/cdn']);
        $expect('L2 容器把注入的 cache 赋到公有属性上', $entry->cache, $injectedCache);

        // 6) 控制台应用：不覆写 run()（所以 CLI 也触发 BEFORE_REQUEST），请求没有 getUrl()
        $runDeclarer = (new ReflectionMethod(\yii\console\Application::class, 'run'))->getDeclaringClass()->getName();
        $expect('L2 console\\Application 不覆写 run()（声明类是 yii\base\Application）', $runDeclarer, 'yii\base\Application');
        $expect('L2 console\\Request 没有 getUrl()（Web 守卫的判别力来源）', method_exists(\yii\console\Request::class, 'getUrl'), false);

        $consoleApp = new \yii\console\Application([
            'id' => 'contracts-console',
            'basePath' => contracts_dir(),
            'bootstrap' => [],
        ]);
        $consoleEntry = new \ErikWang2013\Xhprof\Yii2\XhprofBootstrap();
        $consoleEntry->bootstrap($consoleApp);
        $expect('L2 控制台应用上一条钩子都没挂', $consoleApp->hasEventHandlers(\yii\base\Application::EVENT_BEFORE_REQUEST), false);

        // 7) Web 应用 + YII_ENV=test 下真跑一次 /xhprof（应用在 L2 开头已建好）
        $_SERVER['REQUEST_URI'] = '/xhprof';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];

        $caught = null;
        ob_start();
        try {
            $app->run();
        } catch (\Throwable $e) {
            $caught = $e;
        }
        $body = (string) ob_get_clean();

        $expect('L2 /xhprof 以 ExitException 收尾（YII_ENV=test；生产是 exit）', $caught instanceof \yii\base\ExitException, true);
        $expect('L2 响应已发送', $app->getResponse()->isSent, true);
        $expect('L2 状态 200', $app->getResponse()->getStatusCode(), 200);
        $expect('L2 报告页 Content-Type 字面量', $app->getResponse()->getHeaders()->get('Content-Type'), 'text/html; charset=UTF-8');
        $expect('L2 报告页 Cache-Control 字面量', $app->getResponse()->getHeaders()->get('Cache-Control'), 'no-cache, private');
        $expectContains('L2 正文是报告页（只 echo 一次）', $body, '<html');
        $expect('L2 正文长度与响应内容一致（没有二次输出）', strlen($body), strlen((string) $app->getResponse()->content));
        $expect('L2 业务处理一次都没跑（短路在路由之前）', $app->requestedRoute, null);
    } finally {
        $_SERVER = $server;
        $_GET = $get;
    }

    if ($failures !== []) {
        return [
            'status' => 'FAIL',
            'detail' => count($failures) . ' 项不符：' . implode('；', array_slice($failures, 0, 10)),
            'skips' => 0,
        ];
    }

    $version = class_exists('Composer\InstalledVersions')
        ? \Composer\InstalledVersions::getPrettyVersion('yiisoft/yii2')
        : 'unknown';

    return [
        'status' => 'PASS',
        'detail' => "L1 签名存在 + L2 真实 yiisoft/yii2 {$version} 语义，共 {$checks} 项断言通过"
            . '（含 FORMAT_RAW 与格式化器覆盖头的对照、data 拷进 content 的异常对照、'
            . 'secureHeaders 过滤与 trustedHosts 两态、end() 的 ExitException 收尾、'
            . 'console run() 声明类、容器按公有属性赋值、/xhprof 真报告页）。'
            . ' 已知未覆盖：采样落库（本 case 注入内存 cache，不连 Redis）与真实 header() 发送。',
        'assertions' => $checks,
        'skips' => 0,
    ];
};
