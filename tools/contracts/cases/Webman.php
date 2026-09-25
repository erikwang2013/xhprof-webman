<?php

declare(strict_types=1);

/**
 * Webman 契约卡 —— 用**真实** workerman/webman-framework 验证 src/Webman/**。
 *
 * 版本：webman-framework v2.2.4 + workerman/workerman v5.2.2 + workerman/coroutine v1.1.6。
 *
 * 本卡要钉的两件事（任务书原文）：
 *   1) `Webman\Http\Request::host(bool $withoutPort = false)` 的**两种调用**：真包实测
 *      `host()` = 'example.com:8080'、`host(true)` = 'example.com'，而**本仓适配器必须传 true**
 *      （契约 R-2：host() 只给主机名）。十家的 Request API 里只有 webman/thinkphp 默认带端口，
 *      调用点 XHProfRunsDefault.php 拿它拼 request_log 的展示文本 —— 差一个端口就是数据出错。
 *   2) `Webman\Http\Response` 的**可变语义**：withStatus/withHeaders/withBody/withFile 改的都是
 *      `$this` 并返回它（不是 Laravel/Symfony 那样的 with* 克隆）。本仓 ResponseAdapter 依赖
 *      这一点（R-5：先设头再设状态时头不能丢）。
 *
 * ---------------------------------------------------------------------------------------
 * 一个必须记下来的环结构事实：**一个 vendor 树装不下两个都定义同名全局助手的框架。**
 * laravel/framework 的 `Illuminate/Foundation/helpers.php` 与 webman 的
 * `src/support/helpers.php` 都定义了全局 `response()` / `config()` / `request()`，两边都用
 * `function_exists()` 守卫，于是**谁先被 composer 的 files 自动加载谁赢**。本环实测（下面有
 * 断言钉住）：`vendor/composer/autoload_files.php` 里 laravel 的 helpers.php 排在 webman 之前
 * （`:48` vs `:52`），所以**在本 case 这个进程里 `response()` 是 Laravel 的**。
 * 后果：Webman 适配器里所有走全局助手的路径（`ResponseAdapter::file()` 的 `response()->file()`、
 * `ConfigAdapter::get()` 的 `config()`）在共享进程里跑出来的不是 webman 语义 —— 直接测会得到
 * 一个**环造成的假 FAIL**，而不是本仓的 bug。
 * 处理办法是加一个**干净子进程**（下方 ③）：先 `require` webman 的 helpers.php 再 `require` 环的
 * autoloader，于是该进程里 `response()`/`config()` 归 webman（子进程里也有正对照断言钉住）。
 * 这不是"用自造桩冒充真实框架"：跑的是**真包的真 helper 文件**，只是加载顺序回到了"只装了
 * webman 的应用"那一档；被测的适配器一行未改。
 * ---------------------------------------------------------------------------------------
 *
 * 覆盖到哪一层（诚实边界）：
 *   - 覆盖：L1 十个真实方法签名 + L2 RequestAdapter 全部方法、ResponseAdapter 的可变语义与
 *     助手可用时的完整链路（含 `ResponseAdapter::file()` → 真 `response()->file()` → 真
 *     `notModifiedSince()`/`withFile()`）、ConfigAdapter + `Webman\Config::load()` 读**包内真实
 *     配置文件**、`Xhprof::bootstrap()` 把配置灌进静态量、`Webman\StaticController::serve()`
 *     的四条路径（命中 / 不存在 / `..` / 空路径）与 Content-Type 钉头、配置化 assets_url
 *     的前缀跟随。
 *   - **不**覆盖：真 HTTP 往返与 workerman 事件循环（`Worker::runAll()` 起的服务器）。本卡直接
 *     构造 `Webman\Http\Request`（真包构造函数，raw 报文）+ 用 `Webman\Context::set()` 摆出
 *     framework 自己每请求都会摆的那份状态 —— 这是 webman 的公开 API，`Webman\App::request()`
 *     的实现就是读它。
 *   - **不**覆盖：`Request::getRealIp()` 里"远端 IP 非内网就直接返回它、不看 XFF"那条短路。
 *     它要 `$request->connection`（真 TcpConnection，受 workerman 事件循环约束）才可达，本环
 *     起不了服务器。已用真包的 `isIntranetIp()` 谓词逐项钉住该分支的**判据**（6 项断言），
 *     所以少的只是"那一行 return 被执行过"，不是"判据没验"。不记 SKIP：这是**框架内部**分支，
 *     不在本仓适配器契约面上；SKIP 留给"整块适配器语义验不了"（WordPress 那类）。
 */

use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface as XhprofResponseInterface;
use ErikWang2013\Xhprof\Webman\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Webman\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Webman\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Webman\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Webman\XhprofMiddleware;
use Webman\App;
use Webman\Config;
use Webman\Context;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

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

    // 本包 src/ 的 PSR-4 自注册 —— 刻意**不**依赖仓库根的 vendor/autoload.php：
    // .github/workflows/contracts.yml 只 install tools/contracts，主仓库的 dev vendor
    // 在 CI 里根本不存在，依赖它会让这个 case 在加载期崩溃。
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
    $expectNoThrow = static function (string $label, callable $fn) use (&$checks, &$failures): mixed {
        $checks++;
        try {
            return $fn();
        } catch (\Throwable $e) {
            $failures[] = $label . '：抛了 ' . get_class($e) . ': ' . $e->getMessage();
            return null;
        }
    };
    $expectThrows = static function (string $label, callable $fn) use (&$checks, &$failures): void {
        $checks++;
        try {
            $fn();
        } catch (\Throwable $e) {
            return;
        }
        $failures[] = $label . '：没抛，但应当抛（这条是在钉住一个真实耦合，不是在钉住缺陷）';
    };

    // --- 真包构造请求：raw 报文走真包自己的解析器，不是我摆出来的数组 ---
    $mkRequest = static fn (string $raw): Request => new Request($raw);

    // ================= ① L1：签名存在 =================
    //
    // 列的都是 **src/Webman/** 与 Core 真正调用到的东西。缺失即 "Call to undefined method"，
    // 是最怕的失效模式，所以先把它钉死再谈语义。
    $l1 = [
        Request::class => ['uri', 'url', 'fullUrl', 'path', 'host', 'method', 'header', 'all', 'get', 'post', 'input', 'getRealIp', 'getRemoteIp', 'isIntranetIp', 'queryString'],
        Response::class => ['withStatus', 'withHeaders', 'withBody', 'withFile', 'file', 'header', 'getHeaders', 'getStatusCode', 'getMimeType'],
        Config::class => ['load', 'get', 'loadFromDir'],
        App::class => ['request'],
        Context::class => ['set', 'get', 'has'],
        MiddlewareInterface::class => ['process'],
    ];
    foreach ($l1 as $class => $methods) {
        $exists = class_exists($class) || interface_exists($class);
        $expect("L1 {$class} 存在", $exists, true);
        if (!$exists) {
            continue;
        }
        $rc = new \ReflectionClass($class);
        foreach ($methods as $method) {
            $expect("L1 {$class}::{$method}() 存在", $rc->hasMethod($method), true);
        }
    }

    // 框架从入口类拿到的东西，类型必须真的对得上：入口类 `process(Request $request, callable $handler):
    // Response` 的参数/返回类型是 PHP 强制的，而框架传进来的实例其实是子类
    // `support\Request`（`response('')` 给的是 `support\Response`）。这两个断言的
    // 意思就是"强类型参数不会因为子类关系被拒"——反过来若 webman 改成不兼容的类型，
    // 这里先红，而不是等到线上 500。
    $expect('L1 support\\Request 是 Webman\\Http\\Request 的子类', is_subclass_of(\support\Request::class, Request::class), true);
    $expect('L1 support\\Response 是 Webman\\Http\\Response 的子类', is_subclass_of(\support\Response::class, Response::class), true);
    $expect('L1 Webman\\Http\\Request 继承 workerman 的 Request', get_parent_class(Request::class), 'Workerman\\Protocols\\Http\\Request');
    $expect('L1 Webman\\Http\\Response 继承 workerman 的 Response', get_parent_class(Response::class), 'Workerman\\Protocols\\Http\\Response');

    // `Webman\Http\Request::host()` 的签名是本卡的核心，逐字钉住（含参数名与默认值）：
    // 适配器传的是位置参数 `host(true)`，一旦真包把默认值翻过来或加个参数，这里先红。
    $hostMethod = new \ReflectionMethod(Request::class, 'host');
    $hostParams = $hostMethod->getParameters();
    $expect('L1 host() 第 1 个参数名', $hostParams[0]->getName(), 'withoutPort');
    $expect('L1 host() 第 1 个参数默认值（默认**带**端口）', $hostParams[0]->getDefaultValue(), false);
    $expect('L1 host() 参数个数（多余参数会让适配器的位置传参错位）', count($hostParams), 1);
    $expect('L1 getRealIp() 第 1 个参数名', (new \ReflectionMethod(Request::class, 'getRealIp'))->getParameters()[0]->getName(), 'safeMode');

    // Response 三个 setter 的返回类型是 `static`（不是 `self`）：子类调用返回子类实例，
    // 这正是"就地改 $this 再返回它"的签名证据。若哪天变成 `clone $this` 的克隆语义，
    // 本仓 ResponseAdapter 里 `$this->response = $this->response->withX()` 就开始丢头。
    foreach (['withStatus', 'withHeaders', 'withBody', 'withFile'] as $mutator) {
        $rt = (new \ReflectionMethod(Response::class, $mutator))->getReturnType();
        $expect("L1 Response::{$mutator}() 返回 static", $rt !== null ? (string) $rt : null, 'static');
    }
    // 文件内容挂在一个**公开属性**上（不是 body 字符串）：Core 的 StaticController 依赖
    // "正文在 file 里、不在 body 里"这个事实（它先 file() 再钉头）。
    $expect('L1 Response::$file 是公开属性', (new \ReflectionProperty(Response::class, 'file'))->isPublic(), true);
    // `file()` 内部第一句就是 `notModifiedSince()` → `App::request()->header(...)`，
    // 后者是 **protected**。所以"webman 的 file() 必须有每请求 Context"是结构性的，
    // 不是实现细节，下面 ③ 的子进程必须 Context::set() 才跑得动。
    $expect('L1 Response::notModifiedSince() 是 protected', (new \ReflectionMethod(Response::class, 'notModifiedSince'))->isProtected(), true);

    // 四个适配器 + 入口类必须是真接口的实现（而不是"长得像"）
    $expect('L1 RequestAdapter 实现 Core RequestInterface', is_subclass_of(RequestAdapter::class, RequestInterface::class), true);
    $expect('L1 ResponseAdapter 实现 Core ResponseInterface', is_subclass_of(ResponseAdapter::class, XhprofResponseInterface::class), true);
    $expect('L1 ConfigAdapter 实现 Core ConfigInterface', is_subclass_of(ConfigAdapter::class, ConfigInterface::class), true);
    $expect('L1 LogAdapter 实现 Core LoggerInterface', is_subclass_of(LogAdapter::class, \ErikWang2013\Xhprof\Core\Contract\LoggerInterface::class), true);
    $expect('L1 XhprofMiddleware 实现 Webman MiddlewareInterface', is_subclass_of(XhprofMiddleware::class, MiddlewareInterface::class), true);
    $expect(
        'L1 XhprofMiddleware 是 ErikWang2013\Xhprof\Webman\XhprofMiddleware',
        class_exists(XhprofMiddleware::class),
        true
    );
    // 入口类的 process() 签名必须与框架接口逐字一致：参数名（框架按位置调用，但 IDE/子类
    // 覆盖检查看的是签名）与**返回类型** `Response`（framework 会把它当响应对象 send()）。
    $process = new \ReflectionMethod(XhprofMiddleware::class, 'process');
    $expect('L1 process() 参数个数', $process->getNumberOfParameters(), 2);
    $expect('L1 process() 第 1 参数类型', (string) $process->getParameters()[0]->getType(), Request::class);
    $expect('L1 process() 第 2 参数类型', (string) $process->getParameters()[1]->getType(), 'callable');
    $expect('L1 process() 返回类型', (string) $process->getReturnType(), Response::class);

    // ================= ② L2：RequestAdapter（本卡重点 1/2） =================
    //
    // 一份带 query + body + XFF 的真报文。body 里的 `run` 与 query 里的 `run` **故意不同值**，
    // 因为"query 胜还是 body 胜"正是十家最容易分叉的地方（webman: `all()` = `get() + post()`，
    // query 胜；Laravel: body 胜）。
    $raw = "POST /list?page=2&run=legal&sort[]=wt&sort[]=mem HTTP/1.1\r\n"
        . "Host: example.com:8080\r\n"
        . "X-Forwarded-For: 1.2.3.4, 5.6.7.8\r\n"
        . "Content-Type: application/x-www-form-urlencoded\r\n"
        . "Content-Length: 38\r\n\r\n"
        . 'run=body-value&only_body=from-body';
    $request = $mkRequest($raw);
    $adapter = new RequestAdapter($request);

    // —— host()：两种调用 + 适配器的选择（任务书第一条）——
    $expect('L2 真包 host() 带端口', $request->host(), 'example.com:8080');
    $expect('L2 真包 host(true) 去掉端口', $request->host(true), 'example.com');
    $expect('L2 适配器 host() 不含端口（R-2）', $adapter->host(), 'example.com');
    $expect('L2 适配器 host() === 真包 host(true)', $adapter->host(), $request->host(true));
    $expect('L2 适配器 host() !== 真包 host()（即：无参调用就是错的）', $adapter->host() === $request->host(), false);

    // 无 Host 头：真包两个调用都返回 null，而 RequestAdapter 声明 `: string` 且文件是
    // strict_types=1 —— 直接透传会抛 TypeError（`_saveToRedis()` 每个被采样请求都要取 host）。
    $noHost = $mkRequest("GET /a?b=1 HTTP/1.1\r\n\r\n");
    $expect('L2 无 Host 头时真包 host() 为 null', $noHost->host(), null);
    $expect('L2 无 Host 头时真包 host(true) 为 null', $noHost->host(true), null);
    $expect('L2 无 Host 头时适配器给空串而不是抛 TypeError', (new RequestAdapter($noHost))->host(), '');

    // —— uri()/url()：R-1（uri 只含 path+query）——
    $expect('L2 真包 uri() = path+query', $request->uri(), '/list?page=2&run=legal&sort[]=wt&sort[]=mem');
    $expect('L2 真包 path() 不含 query（对比项，证明适配器选的是 uri()）', $request->path(), '/list');
    $expect('L2 适配器 uri() 与真包 uri() 一致', $adapter->uri(), $request->uri());
    // 真包 url() = '//' . host()（**带端口**） . path()：协议相对、**无 query**，且与它自己的
    // host(true) 口径不同 —— 记下来，免得有人以为适配器的 host() 和 url() 是同一套口径。
    $expect('L2 真包 url() 协议相对且带端口、无 query', $request->url(), '//example.com:8080/list');
    $expect('L2 适配器 url() 与真包一致（不加工）', $adapter->url(), '//example.com:8080/list');
    $expect('L2 真包 fullUrl() = url() + query', $request->fullUrl(), '//example.com:8080/list?page=2&run=legal&sort[]=wt&sort[]=mem');

    // —— method()/header() ——
    $expect('L2 适配器 method()', $adapter->method(), 'POST');
    $expect('L2 适配器 header()', $adapter->header('x-forwarded-for'), '1.2.3.4, 5.6.7.8');
    $expect('L2 适配器 header() 查不到时给 null', $adapter->header('x-nope'), null);
    // 真包大小写不敏感（解析时已小写化），Core 里 I18n::resolve() 读 'accept-language' 靠它。
    $expect('L2 真包 header() 大小写不敏感', $request->header('X-FORWARDED-FOR'), '1.2.3.4, 5.6.7.8');

    // —— 参数合并：query vs body（本卡最容易出错的一处）——
    $expect('L2 真包 post() 读 body', $request->post('run'), 'body-value');
    $expect('L2 真包 get() 只读 query', $request->get('run'), 'legal');
    $expect('L2 真包 all() 是 query 优先的并集', $request->all()['run'], 'legal');
    $expect('L2 适配器 get() 与 all() 同源（query 胜出）', $adapter->get('run'), 'legal');
    $expect('L2 适配器 all() 交给真包', $adapter->all(), $request->all());
    // body 独有的键才是分歧点：真包 get() 给 null，而 all() 有它（`get() + post()` 是并集）。
    // 适配器的 get() 走 all()，所以它给的是 body 的值 —— 与**真包自己的 input()** 同口径
    // （`get($k, post($k, $default))`），而不是与 get() 同口径。Core 里 `Xhprof::index()`
    // 用 get()/all() 两条路读同一批参数，两条给不同答案就是"校验一个值、渲染另一个值"。
    $expect('L2 真包 get() 读不到 body 独有键', $request->get('only_body'), null);
    $expect('L2 真包 all() 里有 body 独有键', $request->all()['only_body'], 'from-body');
    $expect('L2 适配器 get() 能读到 body 独有键（与 all() 同源）', $adapter->get('only_body'), 'from-body');
    $expect('L2 适配器 get() 与真包 input() 同口径', $adapter->get('only_body'), $request->input('only_body'));
    $expect('L2 适配器 get() 缺键回落默认值', $adapter->get('nope', 'DEFAULT'), 'DEFAULT');

    // 数组形态的 query（`?sort[]=wt`）：Core 拿它判 400（非字符串就是坏请求），
    // 所以"数组能原样穿过适配器"是那条防线的前提。
    $expect('L2 数组形态 query 原样穿过适配器', $adapter->get('sort'), ['wt', 'mem']);
    $expect('L2 数组形态 query 是真数组（Core 的 400 分支据此判定）', is_array($adapter->get('sort')), true);

    // —— getRealIp()：safeMode 的判据 ——
    // 无连接时真包 getRemoteIp() 兜底 '0.0.0.0'（workerman 的 reserved 段）= 内网，
    // 于是 safeMode=true 也不会短路掉 XFF，取的是 XFF 的**第一项**。
    $expect('L2 无连接时真包 getRemoteIp() 兜底', $request->getRemoteIp(), '0.0.0.0');
    $expect('L2 真包 getRealIp(true) 取 XFF 首项', $request->getRealIp(true), '1.2.3.4');
    $expect('L2 适配器 getRealIp() 显式传 safeMode=true', $adapter->getRealIp(), '1.2.3.4');
    // 短路分支的判据（分支本身要真 TcpConnection，见文件头"不覆盖"）。
    $expect('L2 isIntranetIp(0.0.0.0) —— 无连接兜底值算内网', Request::isIntranetIp('0.0.0.0'), true);
    $expect('L2 isIntranetIp(127.0.0.1)', Request::isIntranetIp('127.0.0.1'), true);
    $expect('L2 isIntranetIp(10.1.2.3)', Request::isIntranetIp('10.1.2.3'), true);
    $expect('L2 isIntranetIp(::1)', Request::isIntranetIp('::1'), true);
    $expect('L2 isIntranetIp(8.8.8.8) —— 公网：safeMode 会短路，不看 XFF', Request::isIntranetIp('8.8.8.8'), false);
    $expect('L2 isIntranetIp("") —— 非 IP 一律 false', Request::isIntranetIp(''), false);

    // ================= ③ L2：ResponseAdapter 的可变语义（本卡重点 2/2） =================
    //
    // 用真包 `new Webman\Http\Response(200)` —— 构造参数顺序是 **(status, headers, body)**，
    // 与 Laravel 的 `(content, status, headers)` 相反，写错就是把状态码塞进正文。
    $inner = new Response(200);
    $expect('L2 new Response(200) 的第一个参数是状态码', $inner->getStatusCode(), 200);
    $expect('L2 new Response(200) 的第一个参数不是正文', $inner->rawBody(), '');

    $resAdapter = new ResponseAdapter($inner);
    $expect('L2 适配器 withStatus() 返回自身（$this）', $resAdapter->withStatus(403) === $resAdapter, true);
    $expect('L2 withStatus 就地改：调用方手里的对象也变 403', $inner->getStatusCode(), 403);
    $expect('L2 adapter::send() 仍是同一个对象（没有 new Response($status)）', $resAdapter->send() === $inner, true);

    // R-5：先设头再设状态，头不能丢（Laravel 的 withHeaders 是克隆语义，webman 是就地语义；
    // 本仓适配器两种都要支持，所以这条断言在两家都要有）。
    $resAdapter->withHeaders(['Cache-Control' => 'public, max-age=86400']);
    $resAdapter->withStatus(404);
    $expect('L2 先 withHeaders 再 withStatus：头仍在', $resAdapter->send()->getHeaders()['Cache-Control'], 'public, max-age=86400');
    $expect('L2 再 withStatus 后状态是 404', $resAdapter->send()->getStatusCode(), 404);

    $resAdapter->withBody('CONTRAST-BODY');
    $resAdapter->withStatus(418);
    $expect('L2 先 withBody 再 withStatus：正文仍在', $resAdapter->send()->rawBody(), 'CONTRAST-BODY');
    $expect('L2 三次 setter 后仍是同一对象', $resAdapter->send() === $inner, true);

    // 真包的坑：`withHeaders()` 的实现是 `array_merge_recursive($this->headers, $headers)`
    // —— 同一个键设第二次会得到**数组**（'Content-Type' => ['text/html','text/css']），
    // workerman 序列化时会打出 "Array to string conversion"。所以"钉 Content-Type"这件事
    // 在 webman 上必须**只钉一次**；Core\StaticController 的顺序（file() 之后钉一次）满足它。
    $pt = new Response(200);
    $pt->withHeaders(['Content-Type' => 'text/html']);
    $pt->withHeaders(['Content-Type' => 'text/css']);
    $expect('L2 真包 withHeaders 同键两次 → 值是数组（merge_recursive）', $pt->getHeaders()['Content-Type'], ['text/html', 'text/css']);

    // —— file() 一行实现的等价原语（干净子进程里再跑真 response()->file()） ——
    // 真包 `Response::file()` 第一句是 `notModifiedSince()` → `App::request()->header(...)`。
    // 没有每请求 Context 时 `App::request()` 是 null → 抛 Error。这是 webman 的**结构性耦合**：
    // 任何调用 file() 的代码（本仓 StaticController 就是）都必须在 framework 的请求上下文里。
    // 本卡把它钉住，免得以后有人以为可以脱离上下文调 file()。
    $cssPath = $repoRoot . '/src/html/css/xhprof.css';
    $expectThrows('L2 没有 Webman Context 时 file() 抛（notModifiedSince → App::request() 为 null）', static function () use ($cssPath): void {
        (new Response(200))->file($cssPath);
    });
    // 摆出 framework 每请求都会摆的那份状态（`Webman\Context` 是公开 API，App::request() 的实现就是读它）
    Context::set(Request::class, $request);
    $afterFile = new Response(200);
    $ret = $expectNoThrow('L2 有 Context 时 file() 不抛', static fn (): Response => $afterFile->file($cssPath));
    $expect('L2 file() 返回同一个对象（就地）', $ret === $afterFile, true);
    $expect('L2 正文挂在公开的 $file 上而不是 body 里', $afterFile->file['file'] ?? null, $cssPath);
    // 真包在**对象层**不设 Content-Type：类型是序列化时按**扩展名**从 `$mimeTypeMap` 取的
    // （css → text/css），与 Laravel/Symfony 用 finfo 按**内容**嗅探完全是两回事。
    // 所以"Core 显式钉头"在 webman 上的意义是让响应对象自己带着类型（十家一致），
    // 而不是"不然浏览器收不到类型"。
    $expect('L2 file() 本身不设 Content-Type（webman 在序列化时按扩展名补）', $afterFile->getHeaders(), []);
    $expect('L2 真包的扩展名→类型表认得 css', $afterFile->getMimeType('css'), 'text/css');
    $expect('L2 真包的扩展名→类型表认得 js', $afterFile->getMimeType('js'), 'application/javascript');
    // 从 file() 之后的对象接上适配器：Core\StaticController::serve() 的"钉头"就落在这里。
    $resAdapter2 = new ResponseAdapter($afterFile);
    $resAdapter2->withHeaders(['Cache-Control' => 'public, max-age=86400', 'Content-Type' => 'text/css']);
    $headers2 = $resAdapter2->send()->getHeaders();
    $expect('L2 钉头后 Content-Type 是字符串（不是 merge_recursive 数组）', $headers2['Content-Type'], 'text/css');
    $expect('L2 钉头后 Cache-Control', $headers2['Cache-Control'], 'public, max-age=86400');
    $expect('L2 钉头只钉了两个键（多一个就等于多钉了一次）', count($headers2), 2);
    $expect('L2 钉头后文件仍在（头挂在同一个对象上）', $resAdapter2->send()->file['file'] ?? null, $cssPath);

    // ================= ④ 全局助手归属：本环的事实（下面子进程存在的理由） =================
    //
    // 实测：本环 `autoload_files.php` 里 laravel 的 helpers.php 排在 webman 之前，于是
    // `response()`/`config()`/`request()` 全是 laravel/framework 的。这两个断言是**环境事实**、
    // 也是 ⑤ 那个干净子进程的对照：一边证明"共享进程里 webman 助手被遮住"，一边证明
    // "干净进程里它能赢"。若哪天 composer 的顺序变了或某个包被移除，这里先红，读者会看到
    // 下面这段注释而知道该改的是"子进程还要不要存在"，而不是去改适配器。
    $expectContains(
        'L2 本环 response() 归 laravel/framework（共享进程里 webman 助手被遮住）',
        (string) (new \ReflectionFunction('response'))->getFileName(),
        'laravel/framework'
    );
    $expectContains(
        'L2 本环 config() 归 laravel/framework',
        (string) (new \ReflectionFunction('config'))->getFileName(),
        'laravel/framework'
    );

    // ================= ⑤ 干净子进程：webman 助手可用时的完整链路 =================
    //
    // 先 require webman 的 helpers.php，再 require 环的 autoloader（两者都有 function_exists
    // 守卫，先到先得）。该进程里 `response()`/`config()` 归 webman，于是 ConfigAdapter 与
    // StaticController（含 ResponseAdapter::file()）能跑真链路。每次一个进程只跑一个配置场景：
    // `Config::load()` 是 `array_replace_recursive` 累加的，同进程连load两个配置目录会互相污染。
    $tmpBase = sys_get_temp_dir() . '/xhprof-contract-webman-' . getmypid();
    $probeFile = $tmpBase . '/probe.php';
    @mkdir($tmpBase, 0777, true);
    file_put_contents($probeFile, <<<'PHP'
<?php

declare(strict_types=1);

// 本文件由 tools/contracts/cases/Webman.php 生成并删除。argv:
//  1=webman helpers.php 2=环 autoloader 3=配置骨架目录(临时) 4=assets_url 前缀 5=包内真实配置目录 6=仓库根
$helpers = $argv[1];
$ringAutoload = $argv[2];
$cfgDir = $argv[3];
$prefix = $argv[4];

require $helpers;        // 先手：本进程里 response()/config() 归 webman
require $ringAutoload;   // 环的 autoloader（laravel 的同名助手会被 function_exists 挡掉）

// 本包 src/ 的自注册要在这里**重来一遍**：这是独立进程，父进程里的 spl_autoload_register
// 不继承，而环的 autoloader 只管 tools/contracts 自己的依赖（主仓库未 install 到环里）。
$repoRoot = $argv[6];
spl_autoload_register(static function (string $class) use ($repoRoot): void {
    $nsPrefix = 'ErikWang2013\\Xhprof\\';
    if (strncmp($class, $nsPrefix, strlen($nsPrefix)) !== 0) {
        return;
    }
    $file = $repoRoot . '/src/' . str_replace('\\', '/', substr($class, strlen($nsPrefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$out = [
    'helpers' => [
        'response' => (new ReflectionFunction('response'))->getFileName(),
        'config' => (new ReflectionFunction('config'))->getFileName(),
    ],
    'response_helper_class' => get_class(\response('')),
];
$out['helpers']['response_is_webman'] = str_contains($out['helpers']['response'], 'webman-framework');
$out['helpers']['config_is_webman'] = str_contains($out['helpers']['config'], 'webman-framework');

// 配置骨架：直接复制包内**真实**的插件配置（config/plugin/aaron-dev/xhprof/{app.php,xhprof.php}），
// 只把 assets_url 改成待测值 —— 改的是"这个值"，不是"这个夹具的形状"。
$src = $argv[5];
@mkdir($cfgDir . '/plugin/aaron-dev/xhprof', 0777, true);
copy($src . '/app.php', $cfgDir . '/plugin/aaron-dev/xhprof/app.php');
$xhprofConfig = (string) file_get_contents($src . '/xhprof.php');
$xhprofConfig = str_replace("'/xhprof-assets'", var_export($prefix, true), $xhprofConfig);
file_put_contents($cfgDir . '/plugin/aaron-dev/xhprof/xhprof.php', $xhprofConfig);
\Webman\Config::load($cfgDir);

$mkReq = static fn (string $uri): \Webman\Http\Request => new \Webman\Http\Request(
    "GET {$uri} HTTP/1.1\r\nHost: example.com:8080\r\n\r\n"
);
$snap = static function (\Webman\Http\Response $r): array {
    return ['status' => $r->getStatusCode(), 'headers' => $r->getHeaders(), 'file' => $r->file];
};

$req = $mkReq('/x');
\Webman\Context::set(\Webman\Http\Request::class, $req);
$cfg = new \ErikWang2013\Xhprof\Webman\Adapter\ConfigAdapter();
\ErikWang2013\Xhprof\Core\Xhprof::bootstrap(
    new \ErikWang2013\Xhprof\Webman\Adapter\RequestAdapter($req),
    new \ErikWang2013\Xhprof\Webman\Adapter\ResponseAdapter(new \Webman\Http\Response(200)),
    $cfg
);

$out['config'] = [
    'assets_url' => $cfg->get('xhprof.assets_url'),
    'log_ttl' => $cfg->get('xhprof.log_ttl'),
    'time_limit' => $cfg->get('xhprof.time_limit'),
    'view_wtred' => $cfg->get('xhprof.view_wtred'),
    'log_num' => $cfg->get('xhprof.log_num'),
    'key_prefix' => $cfg->get('xhprof.key_prefix'),
    'ignore_url_arr' => $cfg->get('xhprof.ignore_url_arr'),
    'enable' => $cfg->get('xhprof.enable'),
    'auth_token' => $cfg->get('xhprof.auth_token'),
    // `?? $default` 的语义：配置里**值为 null** 与键不存在一样，都会落到默认值
    'auth_token_with_default' => $cfg->get('xhprof.auth_token', 'FELL-BACK'),
    'missing_with_default' => $cfg->get('xhprof.nope', 'FELL-BACK'),
    'whole_array_is_array' => is_array($cfg->get('xhprof', null)),
];
$out['statics'] = [
    'key_prefix' => \ErikWang2013\Xhprof\Core\Xhprof::$key_prefix,
    'log_ttl' => \ErikWang2013\Xhprof\Core\Xhprof::$log_ttl,
    'time_limit' => \ErikWang2013\Xhprof\Core\Xhprof::$time_limit,
    'view_wtred' => \ErikWang2013\Xhprof\Core\Xhprof::$view_wtred,
    'log_num' => \ErikWang2013\Xhprof\Core\Xhprof::$log_num,
    'ignore_url_arr' => \ErikWang2013\Xhprof\Core\Xhprof::$ignore_url_arr,
];
$out['profiler_enabled'] = \ErikWang2013\Xhprof\Core\XhprofProfiler::isEnabled();

// StaticController::serve()：几条路径。每次换一个新请求并同步 Context（framework 就是每请求这样做）。
// URI 都按**本次进程的配置前缀**拼，另外单列一条**恒为默认前缀**的 URI：两者在两种配置下的
// 组合起来，正好把「前缀取自配置」和「没有硬编码」两件事都钉住。
// 越界用的 `../../composer.json` 是**两级**、且目标文件**真实存在**（<repo>/composer.json）：
// 一级（`../`）会落到 <repo>/src/ 下、那里没有 composer.json，realpath 直接为 false，
// 于是断言会因为"文件本来就不存在"而通过 —— 那是假过（实测：连两道守卫都拆掉它都不红）。
foreach ([
    'served_css' => $prefix . '/css/xhprof.css',
    'served_js' => $prefix . '/js/xhprof_report.js',
    'missing' => $prefix . '/css/nope.css',
    'traversal' => $prefix . '/../../composer.json',
    'empty_path' => $prefix . '/',
    'default_prefix_css' => '/xhprof-assets/css/xhprof.css',
] as $key => $uri) {
    $r = $mkReq($uri);
    \Webman\Context::set(\Webman\Http\Request::class, $r);
    $out[$key] = $snap(\ErikWang2013\Xhprof\Webman\StaticController::serve($r));
}

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
PHP);

    $assetsConfigSrc = $repoRoot . '/src/Webman/config/plugin/aaron-dev/xhprof';
    $probe = static function (string $prefix, string $cfgDir) use ($probeFile, $assetsConfigSrc, $repoRoot): array {
        $run = contracts_run_php([
            $probeFile,
            contracts_dir() . '/vendor/workerman/webman-framework/src/support/helpers.php',
            contracts_dir() . '/vendor/autoload.php',
            $cfgDir,
            $prefix,
            $assetsConfigSrc,
            $repoRoot,
        ]);
        $decoded = json_decode(trim($run['stdout']), true);

        return [$decoded, $run];
    };

    // 场景 A：默认前缀
    [$default, $runA] = $probe('/xhprof-assets', $tmpBase . '/cfg-default');
    // 场景 B：把 assets_url 改成一个非默认值（同一个真实配置文件，只换这一个值）
    [$custom, $runB] = $probe('/assets-xhprof', $tmpBase . '/cfg-custom');

    if (!is_array($default) || !is_array($custom)) {
        $checks += 1;
        $broken = is_array($default) ? $runB : $runA;
        $failures[] = '子进程未输出合法 JSON（exit ' . $broken['code'] . '）：'
            . trim($broken['stderr'] !== '' ? $broken['stderr'] : $broken['stdout']);
    } else {
        // 正对照：干净子进程里 webman 的助手真的赢了（否则下面所有结论都测错了对象）
        $expect('⑤ 子进程 response() 归 webman 的 helpers.php', $default['helpers']['response_is_webman'], true);
        $expect('⑤ 子进程 config() 归 webman 的 helpers.php', $default['helpers']['config_is_webman'], true);
        // `response('')` 给的是 support\Response —— ResponseAdapter 的构造参数类型是
        // Webman\Http\Response，靠继承关系才吃得下（L1 已断言子类关系，这里是运行时证据）。
        $expect('⑤ response("") 给的是 support\Response', $default['response_helper_class'], \support\Response::class);

        // —— ConfigAdapter：读的是**包内真实配置文件** ——
        $expect('⑤ ConfigAdapter 读到真文件里的 assets_url', $default['config']['assets_url'], '/xhprof-assets');
        $expect('⑤ ConfigAdapter 读到真文件里的 log_ttl（86400*7 由真文件算出）', $default['config']['log_ttl'], 604800);
        $expect('⑤ ConfigAdapter 读到真文件里的 time_limit', $default['config']['time_limit'], 0);
        $expect('⑤ ConfigAdapter 读到真文件里的 view_wtred', $default['config']['view_wtred'], 3);
        $expect('⑤ ConfigAdapter 读到真文件里的 log_num', $default['config']['log_num'], 1000);
        $expect('⑤ ConfigAdapter 读到真文件里的 key_prefix', $default['config']['key_prefix'], 'xhprof');
        $expect('⑤ ConfigAdapter 读到真文件里的 ignore_url_arr', $default['config']['ignore_url_arr'], ['/xhprof']);
        $expect('⑤ ConfigAdapter 读到真文件里的 enable', $default['config']['enable'], true);
        // `XhprofProfiler::bootstrap()` 要的是**整份配置数组挂在 'xhprof' 键下**，
        // ConfigAdapter 把 'xhprof' 映射成 plugin.aaron-dev.xhprof.xhprof 整份文件 —— 形状必须对。
        $expect('⑤ ConfigAdapter 的 get("xhprof") 给整份数组（XhprofProfiler::bootstrap 要的形状）', $default['config']['whole_array_is_array'], true);
        // 适配器实现是 `config(...) ?? $default`：配置里值为 null 与键不存在一样，都回落默认值。
        // 对 auth_token 无害（null 本来就是"不鉴权"），但这条语义得钉住，免得有人以为 null 能传下去。
        $expect('⑤ 值为 null 的键会回落默认值（?? 语义）', $default['config']['auth_token_with_default'], 'FELL-BACK');
        $expect('⑤ 缺失键回落默认值', $default['config']['missing_with_default'], 'FELL-BACK');
        $expect('⑤ 真文件里 auth_token 就是 null', $default['config']['auth_token'], null);

        // —— 配置 → bootstrap → 静态量：R-6/R-7 的整条链 ——
        // `XhprofProfiler::isEnabled()` 直接决定采样开不开，它读的是同一份配置。
        $expect('⑤ 真配置 enable:true → isEnabled()', $default['profiler_enabled'], true);
        $expect('⑤ bootstrap 把 key_prefix 灌进静态量', $default['statics']['key_prefix'], 'xhprof');
        $expect('⑤ bootstrap 把 log_ttl 灌进静态量', $default['statics']['log_ttl'], 604800);
        $expect('⑤ bootstrap 把 time_limit 灌进静态量', $default['statics']['time_limit'], 0);
        $expect('⑤ bootstrap 把 view_wtred 灌进静态量', $default['statics']['view_wtred'], 3);
        $expect('⑤ bootstrap 把 log_num 灌进静态量', $default['statics']['log_num'], 1000);
        $expect('⑤ bootstrap 把 ignore_url_arr 灌进静态量', $default['statics']['ignore_url_arr'], ['/xhprof']);

        // —— StaticController::serve()（走真 ResponseAdapter::file() → 真 response()->file()） ——
        $cssReal = realpath($repoRoot . '/src/html/css/xhprof.css');
        $expect('⑤ 命中静态资源：状态码', $default['served_css']['status'], 200);
        $expect('⑤ 命中静态资源：Content-Type 是字符串（钉头只钉了一次）', $default['served_css']['headers']['Content-Type'] ?? null, 'text/css');
        $expect('⑤ 命中静态资源：Cache-Control 钉住', $default['served_css']['headers']['Cache-Control'] ?? null, 'public, max-age=86400');
        $expect('⑤ 命中静态资源：正文在 file 里（真实路径）', $default['served_css']['file']['file'] ?? null, $cssReal);
        $expect('⑤ 命中静态资源：file 的 offset/length 是 0（整文件）', [$default['served_css']['file']['offset'] ?? null, $default['served_css']['file']['length'] ?? null], [0, 0]);
        $expect('⑤ js 也钉对类型（不是只测了 css）', $default['served_js']['headers']['Content-Type'] ?? null, 'application/javascript');
        // 不存在的资源 / 越界路径 / 空前缀：空 body 200（不是 500，也不是把 composer.json 送出去）
        $expect('⑤ 不存在的资源：200 空响应', [$default['missing']['status'], $default['missing']['file'], $default['missing']['headers']], [200, null, []]);
        // 越界：`/../../composer.json` 指向 <repo>/composer.json（真实存在、可读）。
        // Core 有两道防线（'..' 黑名单 + realpath 必须在 assets 目录内），实测各自都够，
        // 拆掉任意一道这条仍绿；两道都拆才红 —— 所以它验的是"结果不许越界"，不是某一行的实现。
        $expect('⑤ `..` 越界：200 空响应（没被送出去）', [$default['traversal']['status'], $default['traversal']['file']], [200, null]);
        $expect('⑤ 空前缀：200 空响应', [$default['empty_path']['status'], $default['empty_path']['file']], [200, null]);
        // 默认配置下，"按配置前缀"和"恒为默认前缀"的两条 URI 是同一条，结果必须一致。
        $expect('⑤ 默认配置下两条 URI 等价（前缀就是默认值）', $default['default_prefix_css']['file']['file'] ?? null, $cssReal);

        // —— 场景 B：assets_url 改成非默认值，前缀必须跟着配置走 ——
        // 这是 Core\StaticController::uriPrefix() 那段注释的回归守卫：曾经它硬编码
        // '/xhprof-assets'，于是配成别的值时入口类按配置把请求交进来、serve() 却只认老前缀，
        // 静态资源静默变成空 body 200（报告页无 JS 无 CSS）。
        $expect('⑤ 非默认前缀：适配器读到配置值', $custom['config']['assets_url'], '/assets-xhprof');
        $expect('⑤ 非默认前缀：配置前缀下能取到同一个文件', $custom['served_css']['file']['file'] ?? null, $cssReal);
        $expect('⑤ 非默认前缀：配置前缀下类型照样钉住', $custom['served_css']['headers']['Content-Type'] ?? null, 'text/css');
        $expect(
            '⑤ 非默认前缀：**默认**前缀下的真实文件不被接管（否则就是硬编码复发）',
            [$custom['default_prefix_css']['status'], $custom['default_prefix_css']['file'], $custom['default_prefix_css']['headers']],
            [200, null, []]
        );
    }

    // 清理：临时目录里只有本进程生成的东西
    $rmrf = static function (string $path) use (&$rmrf): void {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $rmrf($path . '/' . $entry);
                }
            }
            @rmdir($path);
            return;
        }
        @unlink($path);
    };
    $rmrf($tmpBase);

    if ($failures !== []) {
        return [
            'status' => 'FAIL',
            'detail' => count($failures) . ' 项不符：' . implode('；', array_slice($failures, 0, 10)),
            'skips' => 0,
        ];
    }

    return [
        'status' => 'PASS',
        'detail' => 'L1 签名存在 + L2 真 workerman/webman-framework '
            . (\Composer\InstalledVersions::getPrettyVersion('workerman/webman-framework') ?? '?')
            . '+workerman/workerman ' . (\Composer\InstalledVersions::getPrettyVersion('workerman/workerman') ?? '?')
            . " 语义，共 {$checks} 项断言通过："
            . 'host() 带端口 vs host(true) 去端口（适配器取后者）、uri() 只含 path+query、'
            . '参数合并 query 胜（与真包 input() 同口径）、getRealIp 的 XFF 首项与 isIntranetIp 判据、'
            . 'withStatus/withHeaders/withBody/withFile 就地改 $this、withHeaders 的 merge_recursive 坑、'
            . 'file() 需 Webman Context、Clean 子进程里真 response()->file() + 真配置文件驱动的'
            . 'ConfigAdapter/bootstrap/StaticController（含非默认 assets_url 前缀跟随）',
        'skips' => 0,
    ];
};
