<?php

declare(strict_types=1);

/**
 * Symfony —— 用**真实** symfony/http-foundation + http-kernel + event-dispatcher 验证 src/Symfony/**。
 *
 * L1（签名存在）：反射断言 src/Symfony 调用的每个方法/常量/属性在真实包里存在，
 *   并断**桩与真实接口的声明一致**（这是桩唯一无法自证的部分）：
 *     - EventSubscriberInterface::getSubscribedEvents() **没有**返回类型（桩也没写）；
 *     - InputBag::get() 声明 string|int|float|bool|null → 它**永远不可能**返回数组；
 *     - HeaderBag::set() 第二参声明 array|string|null → int 会被 strict_types 拦下；
 *     - Request::$query / $request 是 InputBag、$headers 是 HeaderBag；
 *     - ResponseEvent 是 final；KernelEvents / HttpKernelInterface 的常量值。
 *
 * L2（契约语义，不需要 ext-xhprof）—— 每条都是**设计前提的证据**，不是复述代码：
 *   a) 真实 Request → R-1（uri 只含 path+query）/R-2（host 去端口）/R-3（不返回 null）；
 *   b) InputBag::get() 对数组值抛 BadRequestException —— 适配器改走 all() 的**依据**；
 *   c) HeaderBag::set() 传 int 抛 TypeError —— 适配器加 (string) 的**依据**；
 *   d) 真实 EventDispatcher + 真实 HttpKernel：onRequest 的落点在**恰好 10000**
 *      （用 10001 上 Xhprof::$config 仍为 null、9999 上已被 bootstrap 夹出来），
 *      且 128/32/8 上的监听器都在它之后；报告页在 kernel.request 里 setResponse
 *      短路掉传播 —— 32 上那个"路由"监听器一次都没跑；
 *   e) 真实 HttpKernel 的子请求事件顺序是 req:main → req:sub → resp:sub → resp:main
 *      —— 没有子请求守卫时，子请求的 response 会先停掉主请求采样（守卫的**依据**）；
 *   f) 真实 HttpKernel 在控制器抛异常且无人处理时**重抛**（catch=true 也一样），
 *      kernel.response 一次都不派发 —— shutdown 兜底的**依据**；
 *   g) 真实 BinaryFileResponse 的 Cache-Control 归一化（'public, max-age=86400'
 *      → 'max-age=86400, public'）、getContent() 恒为 false（流式发送）、
 *      Response::prepare() 只给 text/* 补 charset；
 *   g2) **MIME 陷阱（实测）**：prepare()（真实应用里 ResponseListener@0 会调）靠 Mime 组件的
 *      **内容**嗅探猜类型 —— 本机实测 src/html 的 11 个资源里 8 个被猜错：3 个 css 全是
 *      text/plain，5 个 js 里 4 个 text/plain 而 `js/dataTables.bootstrap.js` 是 **text/html**
 *      （只有 2 个 png 与 1 个 gif 猜对）；缺 symfony/mime 时更是直接 LogicException。
 *      注意嗅探结果来自**运行环境的 libmagic 数据库**，不是稳定期望值：所以反面证据写成
 *      判别式（`!= text/css`）+ 一条前提守卫，实测字面量只进 detail 不进 $expect。
 *      适配器因此从 Core 的 MIME 表自己钉类型，并逐个断言 src/html 里**每个真实资源**的
 *      最终 Content-Type；
 *   g3) **报告页 Cache-Control 的掩体（实测）**：真实 ResponseHeaderBag 对**没设过**
 *      Cache-Control 的响应会自己**计算**一个默认值，恰好等于 'no-cache, private' ——
 *      所以直接断言这个字面量在真包路径上恒真（实测：去掉入口类的显式设，照样绿）。
 *      判别式：**未设过**时计算值随 Last-Modified 变成 'private, must-revalidate'，
 *      显式设过则纹丝不动；断言前先把判别式本身钉住（前提变了要它先红）。
 *      （单测那条测得出来是靠**桩比真包笨**：桩不做这个计算。别去"修"它。）
 *   h) getSubscribedEvents() 的返回值与真实 ResponseListener 的优先级（0）对比。
 *
 * L2（需要 ext-xhprof）：采样类断言（真的开了采样才谈得上"没被提前停掉"）。
 *
 * 版本覆盖：本 case 跑的是环里 `tools/contracts/composer.json` 装的那一版（当前 7.4）。
 * 卡里的目标矩阵是 `^6.4|^7.0`，**6.4 已手工实测**（在 /tmp 装 http-foundation/http-kernel
 * 6.4.46 + event-dispatcher 6.4.44，只覆写 lib/proc.php 的三个路径 helper，同一份文件原样跑，
 * 234 项全过；那次实测抓出并修掉了两处只在 7.4 上成立的过拟合断言——6.4 的
 * Request::$query/$request/$headers 无原生类型、prepare() 补 charset 的大小写不同）。
 * **但 CI 只跑 7.4**：要覆盖 6.4 需要第二个 composer 项目或 matrix job，
 * 在补上之前不要把本 case 的绿读成"矩阵全覆盖"。
 *   本机有扩展 → skips=0。扩展缺失时这些断言**计入 skips**（不伪装成通过）：
 *   run.php 会因 skips≠冻结期望而直接红 —— 这是**故意**的：CI 的 contracts.yml
 *   没装 xhprof（ci.yml 装了），这条红是要人显式决定"给 contracts.yml 加扩展"
 *   还是"接受这批断言在 CI 上不验"，而不是让环悄悄变绿。
 */

// 子进程脚本里要用到的标记函数：采样数据里以 main()==>xhprof_sym_marker 这类 key 出现
if (!function_exists('xhprof_sym_marker')) {
    function xhprof_sym_marker(): int
    {
        return 42;
    }
}

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

    $repoRoot = contracts_repo_root();

    // 本项目 src/ 的 PSR-4 自注册 —— 刻意**不**依赖仓库根的 vendor/autoload.php：
    // .github/workflows/contracts.yml 只 install tools/contracts，主仓库 dev vendor 在
    // CI 里不存在，依赖它会让本 case 加载期崩溃。
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

    require_once $repoRoot . '/tests/Fixtures/Fakes.php';

    $checks = 0;
    $failures = [];
    $skips = 0;

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

    $ext = extension_loaded('xhprof');

    // ================= L1：签名存在 =================

    $symbols = [
        'Symfony\Component\EventDispatcher\EventSubscriberInterface' => ['getSubscribedEvents'],
        'Symfony\Component\EventDispatcher\EventDispatcher' => ['addSubscriber', 'addListener', 'dispatch'],
        'Symfony\Component\HttpFoundation\Request' => [
            'create', 'getMethod', 'getClientIp', 'getHost', 'getRequestUri', 'getUri', 'getContent', 'setTrustedProxies',
        ],
        'Symfony\Component\HttpFoundation\InputBag' => ['all', 'get', 'has'],
        'Symfony\Component\HttpFoundation\HeaderBag' => ['all', 'get', 'set', 'has'],
        'Symfony\Component\HttpFoundation\Response' => [
            '__construct', 'setContent', 'getContent', 'setStatusCode', 'getStatusCode', 'prepare',
        ],
        'Symfony\Component\HttpFoundation\BinaryFileResponse' => ['__construct', 'getFile', 'prepare'],
        'Symfony\Component\HttpFoundation\Exception\BadRequestException' => [],
        'Symfony\Component\HttpKernel\HttpKernelInterface' => ['handle'],
        'Symfony\Component\HttpKernel\KernelEvents' => [],
        'Symfony\Component\HttpKernel\HttpKernel' => ['__construct', 'handle'],
        'Symfony\Component\HttpKernel\Controller\ControllerResolver' => ['__construct', 'getController'],
        'Symfony\Component\HttpKernel\Controller\ArgumentResolver' => ['__construct'],
        'Symfony\Component\HttpKernel\Event\RequestEvent' => [
            'isMainRequest', 'getRequest', 'getRequestType', 'setResponse', 'hasResponse', 'getResponse',
        ],
        'Symfony\Component\HttpKernel\Event\ResponseEvent' => [
            'isMainRequest', 'getRequest', 'getRequestType', 'getResponse',
        ],
        'Symfony\Component\HttpKernel\EventListener\ResponseListener' => ['__construct', 'onKernelResponse', 'getSubscribedEvents'],
        'Psr\Log\LoggerInterface' => ['error'],
    ];

    foreach ($symbols as $name => $methods) {
        $exists = interface_exists($name) || class_exists($name);
        $expect("L1 {$name} 存在", $exists, true);
        if (!$exists) {
            continue;
        }
        $rc = new ReflectionClass($name);
        foreach ($methods as $method) {
            $expect("L1 {$name}::{$method}() 存在", $rc->hasMethod($method), true);
        }
    }

    // 属性类型（适配器直接读 $request->query / ->request / ->headers）。
    // 承重的是**运行时**类型：6.4 这三个属性没有原生类型（7.0 才补上声明），反射在 6.4 上得到 ''，
    // 只断声明会把 6.4 误判成失败。声明那条改成"只允许两种已知形态"——真出现第三种（换类型/换类名）
    // 还是要红。
    $typedRequest = new Symfony\Component\HttpFoundation\Request();
    foreach ([
        'query' => 'Symfony\Component\HttpFoundation\InputBag',
        'request' => 'Symfony\Component\HttpFoundation\InputBag',
        'headers' => 'Symfony\Component\HttpFoundation\HeaderBag',
    ] as $prop => $type) {
        $expect("L1 Request::\${$prop} 运行时是 {$type}", get_class($typedRequest->{$prop}), $type);
        $declared = (string) (new ReflectionProperty(Symfony\Component\HttpFoundation\Request::class, $prop))->getType();
        $expect(
            "L1 Request::\${$prop} 的声明类型只允许 ''(6.4) 或 {$type}(7.x)，得到 '{$declared}'",
            in_array($declared, ['', $type], true),
            true
        );
    }

    // 常量值
    $expect('L1 KernelEvents::REQUEST', Symfony\Component\HttpKernel\KernelEvents::REQUEST, 'kernel.request');
    $expect('L1 KernelEvents::RESPONSE', Symfony\Component\HttpKernel\KernelEvents::RESPONSE, 'kernel.response');
    $expect('L1 HttpKernelInterface::MAIN_REQUEST', Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST, 1);
    $expect('L1 HttpKernelInterface::SUB_REQUEST', Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST, 2);
    $expect(
        'L1 ResponseEvent 是 final（入口类不能靠继承它来 hook）',
        (new ReflectionClass(Symfony\Component\HttpKernel\Event\ResponseEvent::class))->isFinal(),
        true
    );

    // ---- 桩与真实包的**声明**一致性：桩唯一自证不了的部分 ----

    $expect(
        'L1 真实 EventSubscriberInterface::getSubscribedEvents() 没有返回类型（桩照抄了这一点）',
        (string) (new ReflectionMethod(Symfony\Component\EventDispatcher\EventSubscriberInterface::class, 'getSubscribedEvents'))->getReturnType(),
        ''
    );
    // 这条是 RequestAdapter 绕过 InputBag::get() 的根据：它的返回类型里没有 array，
    // 数组值只能抛异常，而 Core 契约声明的是 mixed（Xhprof::index() 靠 is_string() 自己给 400）
    $expect(
        'L1 真实 InputBag::get() 的返回类型（永不含 array）',
        (string) (new ReflectionMethod(Symfony\Component\HttpFoundation\InputBag::class, 'get'))->getReturnType(),
        'string|int|float|bool|null'
    );
    $expect(
        'L1 真实 InputBag::all() 返回 array（适配器改走它）',
        (string) (new ReflectionMethod(Symfony\Component\HttpFoundation\InputBag::class, 'all'))->getReturnType(),
        'array'
    );
    // 这条是 ResponseAdapter::withHeaders() 里 (string) 强转的根据
    $expect(
        'L1 真实 HeaderBag::set() 第二参的类型（不含 int，strict_types 下会 TypeError）',
        (string) (new ReflectionMethod(Symfony\Component\HttpFoundation\HeaderBag::class, 'set'))->getParameters()[1]->getType(),
        'array|string|null'
    );

    // 我们的类必须是**真实**接口的实现（而不是"长得像"）
    $expect(
        'L1 XhprofListener 是真实 EventSubscriberInterface 的实现',
        is_subclass_of(\ErikWang2013\Xhprof\Symfony\XhprofListener::class, Symfony\Component\EventDispatcher\EventSubscriberInterface::class),
        true
    );
    foreach ([
        'RequestAdapter' => \ErikWang2013\Xhprof\Core\Contract\RequestInterface::class,
        'ResponseAdapter' => \ErikWang2013\Xhprof\Core\Contract\ResponseInterface::class,
        'ConfigAdapter' => \ErikWang2013\Xhprof\Core\Contract\ConfigInterface::class,
        'RedisAdapter' => \ErikWang2013\Xhprof\Core\Contract\CacheInterface::class,
        'LogAdapter' => \ErikWang2013\Xhprof\Core\Contract\LoggerInterface::class,
    ] as $short => $iface) {
        $expect(
            "L1 {$short} 实现 {$iface}",
            is_subclass_of('ErikWang2013\\Xhprof\\Symfony\\Adapter\\' . $short, $iface),
            true
        );
    }

    // ================= L2：真实对象（不需要扩展） =================

    // ---- a) 真实 Request → 适配器（R-1 / R-2 / R-3） ----
    $real = Symfony\Component\HttpFoundation\Request::create(
        'http://Example.COM:8080/admin?x=1&y=2',
        'POST',
        ['p' => 1],
        [],
        [],
        ['HTTP_X_FOO' => 'bar']
    );
    $req = new \ErikWang2013\Xhprof\Symfony\Adapter\RequestAdapter($real);
    $expect('L2 R-1 uri() 只含 path+query', $req->uri(), '/admin?x=1&y=2');
    $expect('L2 R-1 uri() 不含 scheme', strpos($req->uri(), '://') === false, true);
    $expect('L2 R-1 uri() 不含 host（isIgnore 做的是子串匹配）', strpos($req->uri(), 'example.com') === false, true);
    $expect('L2 R-2 host() 去端口', $req->host(), 'example.com');
    $expect('L2 R-2 host() 已小写（真实 Request 就返回小写）', $real->getHost(), 'example.com');
    $expect('L2 url() 是绝对 URL', $req->url(), 'http://example.com:8080/admin?x=1&y=2');
    $expect('L2 url() 与真实 getUri() 一致', $req->url(), $real->getUri());
    $expect('L2 query 参数是 string', $req->get('x'), '1');
    $expect('L2 POST body 参数保持 int', $req->get('p'), 1);
    $expect('L2 缺省值', $req->get('nope', 'd'), 'd');
    $expect('L2 all() 合并 query 与 body', $req->all(), ['x' => '1', 'y' => '2', 'p' => 1]);
    $expect('L2 method() 大写', (new \ErikWang2013\Xhprof\Symfony\Adapter\RequestAdapter(
        Symfony\Component\HttpFoundation\Request::create('/a', 'post')
    ))->method(), 'POST');
    $expect('L2 header() 命中（HeaderBag 大小写不敏感）', $req->header('x-foo'), 'bar');
    $expect('L2 R-3 header() 缺省必须是 null（声明 ?string）', $req->header('X-Absent'), null);
    $expect('L2 R-3 getRealIp() 常规路径', $req->getRealIp(), '127.0.0.1');

    // R-3：真实 getClientIp() 声明 ?string，无 REMOTE_ADDR 时真的是 null
    $noIp = new Symfony\Component\HttpFoundation\Request([], [], [], [], [], ['SERVER_NAME' => 'example.com']);
    $expect('L2 R-3 前置条件：真实 getClientIp() 此刻为 null', $noIp->getClientIp(), null);
    $expect('L2 R-3 getRealIp() 声明 : string，绝不能是 null', (new \ErikWang2013\Xhprof\Symfony\Adapter\RequestAdapter($noIp))->getRealIp(), '');

    // trusted proxy：适配器只是转发，取哪一跳由框架决定；这里固化**实测**结果
    // （真实实现 normalizeAndFilterClientIps() 会 array_reverse，故 [0] 是**最靠近我们的
    //  那个非可信跳**，而不是 XFF 最左边的原始客户端）
    Symfony\Component\HttpFoundation\Request::setTrustedProxies(
        ['127.0.0.1'],
        Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_FOR
    );
    $xff = Symfony\Component\HttpFoundation\Request::create('http://example.com/a', 'GET', [], [], [], [
        'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 5.6.7.8',
    ]);
    $expect('L2 trusted proxy 下 getRealIp() 转发真实 getClientIp()', $xff->getClientIp(), '5.6.7.8');
    $expect(
        'L2 trusted proxy 下适配器给出同一个值',
        (new \ErikWang2013\Xhprof\Symfony\Adapter\RequestAdapter($xff))->getRealIp(),
        '5.6.7.8'
    );
    Symfony\Component\HttpFoundation\Request::setTrustedProxies([], -1);

    // ---- b) InputBag::get() 对数组值抛异常（适配器绕开它的根据） ----
    $arrReq = Symfony\Component\HttpFoundation\Request::create('/xhprof?run[]=a');
    $thrown = null;
    try {
        $arrReq->query->get('run');
    } catch (\Throwable $e) {
        $thrown = get_class($e);
    }
    $expect(
        'L2 依据：真实 InputBag::get() 对数组值抛 BadRequestException',
        $thrown,
        'Symfony\Component\HttpFoundation\Exception\BadRequestException'
    );
    $arrAdapter = new \ErikWang2013\Xhprof\Symfony\Adapter\RequestAdapter($arrReq);
    $expect('L2 ?run[]=a：适配器返回数组而不是抛异常', $arrAdapter->get('run'), ['a']);
    $expect('L2 ?run[]=a：all() 也拿得到', $arrAdapter->all(), ['run' => ['a']]);

    // ---- c) HeaderBag::set() 不接受 int（适配器 (string) 强转的根据） ----
    $intThrown = null;
    try {
        (new Symfony\Component\HttpFoundation\Response())->headers->set('Content-Length', 12);
    } catch (\Throwable $e) {
        $intThrown = get_class($e);
    }
    $expect('L2 依据：真实 HeaderBag::set() 传 int 抛 TypeError', $intThrown, 'TypeError');

    // ---- d) ResponseAdapter 语义 ----
    $res = new \ErikWang2013\Xhprof\Symfony\Adapter\ResponseAdapter();
    $expect('L2 R-4 withStatus() 返回 $this', $res->withStatus(201), $res);
    $expect('L2 R-4 withBody() 返回 $this', $res->withBody('hello'), $res);
    $expect('L2 R-4 withHeaders() 返回 $this', $res->withHeaders(['X-A' => '1', 'Content-Length' => 12, 'X-Multi' => ['a', 'b']]), $res);

    ob_start();
    $sent = $res->send();
    $echoed = (string) ob_get_clean();
    $expect(
        'L2 send() 返回真实 Response（Symfony 由 index.php 统一 send()，适配器不许自己 echo）',
        $sent instanceof Symfony\Component\HttpFoundation\Response,
        true
    );
    $expect('L2 send() 什么都没输出', $echoed, '');
    $expect('L2 状态码', $sent->getStatusCode(), 201);
    $expect('L2 body', $sent->getContent(), 'hello');
    $expect('L2 header 生效', $sent->headers->get('X-A'), '1');
    $expect('L2 int 值被转成字符串（不 TypeError）', $sent->headers->get('Content-Length'), '12');
    $expect('L2 数组值多值写入', $sent->headers->all('X-Multi'), ['a', 'b']);

    // ---- e) file()：真实 BinaryFileResponse + Cache-Control 归一化 ----
    $css = $repoRoot . '/src/html/css/xhprof.css';
    $expect('L2 前置条件：包内 css 存在', is_file($css), true);
    $fileRes = new \ErikWang2013\Xhprof\Symfony\Adapter\ResponseAdapter();
    $expect('L2 R-5 file() 返回 $this', $fileRes->file($css), $fileRes);
    $expect('L2 R-5 file() 之后 withHeaders() 仍返回 $this', $fileRes->withHeaders(['Cache-Control' => 'public, max-age=86400']), $fileRes);
    $fileSent = $fileRes->send();
    $expect(
        'L2 R-5 file() 产出真实 BinaryFileResponse',
        $fileSent instanceof Symfony\Component\HttpFoundation\BinaryFileResponse,
        true
    );
    $expect('L2 R-5 指向同一个文件', $fileSent->getFile()->getPathname(), $css);
    $expect('L2 R-5 头落在**新**响应对象上（file() 换掉了旧对象）', $fileSent->headers->get('Cache-Control'), 'max-age=86400, public');
    $expect('L2 BinaryFileResponse::getContent() 恒为 false（流式发送，不占内存）', $fileSent->getContent(), false);
    $expect('L2 状态码默认 200', $fileSent->getStatusCode(), 200);

    // 读不出的路径：真实 BinaryFileResponse 会抛 FileNotFoundException（穿到 HttpKernel 之外
    // 就是 500），适配器必须退化成 404。先钉住"真实实现确实会抛"这个前提，否则断言是空的。
    $missing = $repoRoot . '/src/html/css/__missing__.css';
    $missingThrown = null;
    try {
        new Symfony\Component\HttpFoundation\BinaryFileResponse($missing);
    } catch (\Throwable $e) {
        $missingThrown = get_class($e);
    }
    $expect(
        'L2 前置条件：真实 BinaryFileResponse 对缺失文件抛 FileNotFoundException',
        $missingThrown,
        Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException::class
    );
    $missingRes = (new \ErikWang2013\Xhprof\Symfony\Adapter\ResponseAdapter())->file($missing)->send();
    $expect('L2 file() 读不出文件退化成 404（不抛，不 500）', $missingRes->getStatusCode(), 404);
    $expect(
        'L2 file() 读不出文件时不是文件响应（没有可流式的文件）',
        $missingRes instanceof Symfony\Component\HttpFoundation\BinaryFileResponse,
        false
    );

    // prepare() 由 ResponseListener@0（FrameworkBundle 注册）在每个响应上调用，它会补
    // Content-Type。我们的适配器**已经自己钉了**类型，所以：
    //  - 无论有没有 symfony/mime，prepare() 都不该改动我们钉的类型（下面第一条断言）；
    //  - 反面证据（为什么必须自己钉）：不钉的话 prepare() 会走 Mime 组件的**内容**嗅探。
    //    实测本机：src/html 的 11 个资源里 8 个被猜错，最好复现的例子是
    //    `js/dataTables.bootstrap.js` → **text/html**（js 被当成 HTML，浏览器直接拒收脚本）
    //    ——这个文件就在仓库里，永远可复现，换台机器也一样。
    //    但"猜错成什么"是**运行环境 libmagic 数据库**的事（别的机器可能真猜对 text/css），
    //    所以这里钉判别式（`!= text/css`）+ 一条前提守卫（prepare() 确实给出了非空
    //    Content-Type，否则判别式在"什么都没给"上空转），实测字面量进 detail 不进 $expect。
    //    没有 mime 时更是直接抛 LogicException。这两种环境各有一条真断言，都不是 skip。
    $assetsRequest = Symfony\Component\HttpFoundation\Request::create('/xhprof-assets/css/xhprof.css');
    $fileSent->prepare($assetsRequest);
    // charset 的**大小写不比**：prepare() 6.4 补 'UTF-8'、7.x 补 'utf-8'（实测），而 charset 参数
    // 按 RFC 2046/9110 大小写不语义。要钉的是主类型：不能被换成 text/plain（反面证据在下面）。
    $expect(
        'L2 prepare() 不改动适配器钉的 Content-Type（主类型+charset，大小写不敏感）',
        strtolower((string) $fileSent->headers->get('Content-Type')),
        'text/css; charset=utf-8'
    );
    $expect('L2 prepare() 后 Cache-Control 仍在', $fileSent->headers->get('Cache-Control'), 'max-age=86400, public');
    $expect('L2 prepare() 后仍是 200 的文件响应', $fileSent->getStatusCode(), 200);

    $mimeNote = '';
    if (class_exists(Symfony\Component\Mime\MimeTypes::class)) {
        $bare = new Symfony\Component\HttpFoundation\BinaryFileResponse($css);
        $bare->prepare($assetsRequest);
        $bareType = $bare->headers->get('Content-Type');
        // 前提守卫：少了这一条，"猜错了"在 prepare() 什么都没给（null）时也成立——空转。
        $expect(
            'L2 反面证据的前提：不钉类型时 prepare() 确实给出了非空 Content-Type（否则下面的判别式空转）',
            is_string($bareType) && $bareType !== '',
            true
        );
        // 判别式而不是字面量：嗅探值是运行环境 libmagic 的结论，冻成期望等于让环的红绿
        // 取决于换没换机器。这条只在「嗅探恰好猜对」时红 —— 那正是值得停下来看的时刻：
        // 猜对了，适配器显式钉类型这件事就需要重新论证，而不是继续被当作理所当然。
        $expect(
            'L2 反面证据：不钉类型时 prepare() 用内容嗅探，给出的不是该文件该有的 text/css（实测值见失败输出/detail）',
            str_starts_with(strtolower((string) $bareType), 'text/css'),
            false
        );
        $mimeNote = 'symfony/mime 已装：不钉 Content-Type 时 prepare() 按内容嗅探，本机实测 '
            . var_export($bareType, true) . '（src/html 11 个资源里 8 个被猜错；最好复现的例子是'
            . ' js/dataTables.bootstrap.js → text/html），故适配器改用 Core 的 MIME 表';
    } else {
        $bareThrown = null;
        $bare = new Symfony\Component\HttpFoundation\BinaryFileResponse($css);
        try {
            $bare->prepare($assetsRequest);
        } catch (\Throwable $e) {
            $bareThrown = get_class($e);
        }
        $expect(
            'L2 反面证据：不钉类型且缺 symfony/mime 时 prepare() 直接抛 LogicException',
            $bareThrown,
            'LogicException'
        );
        $mimeNote = 'symfony/mime **未装**：不钉 Content-Type 的 BinaryFileResponse 走 prepare() 会直接抛'
            . ' LogicException（适配器自己钉了类型，所以不受影响）';
    }

    // 逐个断言包内**真实存在**的每个资源都被钉成浏览器认得的类型；
    // 出现未登记的新扩展名就 FAIL —— 强迫后来者显式补 MIME 表，而不是悄悄服务成 octet-stream
    // Response::prepare() 只给 text/* 补 charset（实测规律），所以 js 保持不带 charset；
    // charset 大小写同上不比。
    $assetTypes = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript',
        'png' => 'image/png',
        'gif' => 'image/gif',
    ];
    $shipped = 0;
    $shippedDirs = [];
    foreach ((array) glob($repoRoot . '/src/html/*/*') as $assetPath) {
        if (!is_file((string) $assetPath)) {
            continue;
        }
        $shipped++;
        $shippedDirs[basename(dirname((string) $assetPath))] = true;
        $ext = pathinfo((string) $assetPath, PATHINFO_EXTENSION);
        $expect("L2 资源类型：src/html 里出现的是已登记扩展名（{$ext}）", array_key_exists($ext, $assetTypes), true);
        $srv = (new \ErikWang2013\Xhprof\Symfony\Adapter\ResponseAdapter())->file((string) $assetPath)->send();
        $srv->prepare($assetsRequest);
        $expect(
            'L2 资源类型：' . str_replace($repoRoot . '/', '', (string) $assetPath),
            strtolower((string) $srv->headers->get('Content-Type')),
            strtolower($assetTypes[$ext] ?? '（未登记）')
        );
    }
    // 地板只用来证明 glob 真扫到了文件（不是空目录），不是「资源配额」：
    // 2026-09-25 删掉 7 个零引用文件后 src/html 是 11 个（18→11），地板跟着降到 11，
    // 此后任何一次删除都会在这里显式红一次 —— 想降就得连带改这行。
    // 光看总数看不出「某个子目录被清空」，所以四个子目录也必须各自贡献至少一个文件。
    $expect('L2 资源类型：包内资源确实被遍历到（不是空目录）', $shipped >= 11, true);
    foreach (['css', 'js', 'images', 'jquery'] as $assetDir) {
        $expect("L2 资源类型：src/html/{$assetDir} 至少一个资源（子目录没被清空）", isset($shippedDirs[$assetDir]), true);
    }

    // ---- f) ConfigAdapter：R-6 两种取法 + R-7 非递归合并 ----
    $defaults = new \ErikWang2013\Xhprof\Symfony\Adapter\ConfigAdapter();
    $expect('L2 R-6 get(\'xhprof\') 返回整块（不是 null）', is_array($defaults->get('xhprof')), true);
    $expect('L2 R-6 get(\'xhprof.assets_url\') 返回叶子', $defaults->get('xhprof.assets_url'), '/xhprof-assets');
    $expect('L2 R-6 点号路径缺省值', $defaults->get('xhprof.nope', 'd'), 'd');
    $expect('L2 R-7 前置条件：ignore_url_arr 的默认值是非空列表', $defaults->get('xhprof.ignore_url_arr'), ['/xhprof']);
    $expect(
        'L2 R-7 非递归合并：用户写 [] （什么都不忽略）不该被默认值按下标补回',
        (new \ErikWang2013\Xhprof\Symfony\Adapter\ConfigAdapter(['ignore_url_arr' => []]))->get('xhprof.ignore_url_arr'),
        []
    );

    // ---- g) 真实 Dispatcher + 真实 HttpKernel：优先级与短路 ----
    $dispatcher = new Symfony\Component\EventDispatcher\EventDispatcher();
    // `locale` 钉死不是装饰：报告页文案现在是语言协商来的，而 Symfony 的
    // Request::create() **自带** `Accept-Language: en-us,en;q=0.5`（实测），
    // 协商出来是 en，下面那条中文标题断言就会红。钉住配置的 locale 既让断言
    // 与框架的默认头无关，也顺带在真实请求上验了「配置压过浏览器头」这一级。
    $listener = new \ErikWang2013\Xhprof\Symfony\XhprofListener(['enable' => true, 'locale' => 'zh_CN'], new ErikWang2013\Xhprof\Tests\Fixtures\FakeCache());
    $dispatcher->addSubscriber($listener);

    $order = [];
    $bootstrappedAt = [];
    // 10001：入口类**还没** bootstrap（Xhprof::$config 仍是 null）
    $dispatcher->addListener(Symfony\Component\HttpKernel\KernelEvents::REQUEST, function () use (&$order, &$bootstrappedAt): void {
        $order[] = 'req-10001';
        $bootstrappedAt['10001'] = null !== \ErikWang2013\Xhprof\Core\Xhprof::$config;
    }, 10001);
    // 9999：入口类**已经** bootstrap —— 夹出 onRequest 的落点恰在 (10001, 9999)
    $dispatcher->addListener(Symfony\Component\HttpKernel\KernelEvents::REQUEST, function () use (&$order, &$bootstrappedAt): void {
        $order[] = 'req-9999';
        $bootstrappedAt['9999'] = null !== \ErikWang2013\Xhprof\Core\Xhprof::$config;
    }, 9999);
    foreach ([128 => 'req-128', 32 => 'req-32', 8 => 'req-8'] as $prio => $tag) {
        $dispatcher->addListener(Symfony\Component\HttpKernel\KernelEvents::REQUEST, function () use (&$order, $tag): void {
            $order[] = $tag;
        }, $prio);
    }
    $responseListener = new Symfony\Component\HttpKernel\EventListener\ResponseListener('UTF-8', false);
    $dispatcher->addSubscriber($responseListener);

    $stack = new Symfony\Component\HttpFoundation\RequestStack();
    $kernel = new Symfony\Component\HttpKernel\HttpKernel(
        $dispatcher,
        new Symfony\Component\HttpKernel\Controller\ControllerResolver(),
        $stack,
        new Symfony\Component\HttpKernel\Controller\ArgumentResolver()
    );

    \ErikWang2013\Xhprof\Core\Xhprof::$config = null;   // 让 10001 的观测有意义
    $normal = Symfony\Component\HttpFoundation\Request::create('/hello');
    $normal->attributes->set('_controller', static fn (): Symfony\Component\HttpFoundation\Response => new Symfony\Component\HttpFoundation\Response('route-ok'));
    $normalResponse = $kernel->handle($normal);

    $expect('L2 真 HttpKernel：控制器真的跑了', $normalResponse->getContent(), 'route-ok');
    $expect('L2 onRequest 之前（10001）未 bootstrap', $bootstrappedAt['10001'], false);
    $expect('L2 onRequest 之后（9999）已 bootstrap', $bootstrappedAt['9999'], true);
    $expect(
        'L2 顺序：入口类夹在 10001 与 9999 之间，且早于 Session(128)/Router(32)/Firewall(8)',
        $order,
        ['req-10001', 'req-9999', 'req-128', 'req-32', 'req-8']
    );

    // 报告页短路：把"路由"监听器放在 32 上，它若被调用就炸（用完立刻摘掉，
    // 否则后面子请求场景的正常请求会撞上它 —— 它模拟的是"报告页的请求不该走到路由"）
    $routerCalled = 0;
    $thrower = function () use (&$routerCalled): void {
        $routerCalled++;
        throw new \RuntimeException('RouterListener(32) 不该被调用：入口类应在 kernel.request @10000 就 setResponse 短路');
    };
    $dispatcher->addListener(Symfony\Component\HttpKernel\KernelEvents::REQUEST, $thrower, 32);
    $orderBeforeReport = $order;

    $report = $kernel->handle(Symfony\Component\HttpFoundation\Request::create('http://example.com/xhprof'));
    $expect('L2 报告页：短路后 32 上的"路由"监听器一次都没跑', $routerCalled, 0);
    $expect(
        'L2 报告页：传播真的在 10000 处停住（10001 跑了，9999 没跑）',
        $order,
        array_merge($orderBeforeReport, ['req-10001'])
    );
    $dispatcher->removeListener(Symfony\Component\HttpKernel\KernelEvents::REQUEST, $thrower);
    $expect('L2 报告页：状态码 200', $report->getStatusCode(), 200);
    $expectContains('L2 报告页：返回的是报告 HTML', (string) $report->getContent(), 'XHProf 性能分析报告');
    // 上一行用的是配置里钉死的 locale；这一行验最高一级：`?lang=` 压过配置。
    // 两条合起来才说明「中文」不是碰巧（框架工厂默认头是 en-us,en）。
    $turned = $kernel->handle(Symfony\Component\HttpFoundation\Request::create('http://example.com/xhprof?lang=en'));
    $expectContains('L2 报告页：?lang=en 压过配置的 locale，整页切成英文', (string) $turned->getContent(), 'XHProf Performance Report');
    // Content-Type 由入口类**自己**显式设，不依赖 ResponseListener 的 prepare()。
    // 真实 Response 构造后 Content-Type 是 NULL（下面这条前置断言钉住这个事实）——
    // 依赖 prepare() 补值 = 依赖 @0 在位 + 依赖 symfony/mime + charset 取值随监听器构造参数变。
    $prepared = new Symfony\Component\HttpFoundation\Response('<h1>x</h1>', 200);
    $expect('L2 前置条件：真实 Response 构造后 Content-Type 为 null', $prepared->headers->get('Content-Type'), null);
    $expect(
        'L2 报告页：Content-Type 由入口类显式设（不靠 prepare() 补）',
        $report->headers->get('Content-Type'),
        'text/html; charset=UTF-8'
    );
    $report->prepare(Symfony\Component\HttpFoundation\Request::create('/xhprof'));
    $expect(
        'L2 报告页：显式设的 Content-Type 经 prepare() 原样保留',
        $report->headers->get('Content-Type'),
        'text/html; charset=UTF-8'
    );
    // 上面那条**测不出**"我们有没有设"：这条链路里真 ResponseListener 的 prepare() 恰好补出同一个值
    // （实测：把入口类的显式设去掉，上面照样绿）。绕过 HttpKernel 直接问入口类的响应才测得到。
    $bareEvent = new Symfony\Component\HttpKernel\Event\RequestEvent(
        $kernel,
        Symfony\Component\HttpFoundation\Request::create('http://example.com/xhprof'),
        Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST
    );
    (new \ErikWang2013\Xhprof\Symfony\XhprofListener(['enable' => true], new \ErikWang2013\Xhprof\Tests\Fixtures\FakeCache()))
        ->onRequest($bareEvent);
    $expect(
        'L2 报告页：Content-Type 是入口类自己设的（绕开 ResponseListener 的 prepare() 也成立）',
        $bareEvent->getResponse()->headers->get('Content-Type'),
        'text/html; charset=UTF-8'
    );
    // Cache-Control 是同一个坑的第二层，掩体换成了 ResponseHeaderBag 自己：真实
    // `new Response(...)` 在**没设过** Cache-Control 时，get() 返回的是它**计算**出来的
    // 默认值，而那恰好就是 'no-cache, private'（实测）。所以
    // `assertSame('no-cache, private', get('Cache-Control'))` 在真包路径上恒真 ——
    // 实测：把入口类的显式 Cache-Control 去掉，这条照样绿。判别式：**未显式设过**时
    // 计算值会随 Last-Modified 变成 'private, must-revalidate'；显式设过则纹丝不动。
    // 先断言判别式本身成立 —— 将来 HttpFoundation 改了计算规则，这条前置条件先红，
    // 而不是让下面那条悄悄退化成恒真。
    $calcProbe = new Symfony\Component\HttpFoundation\Response('<h1>x</h1>', 200);
    $calcProbe->headers->set('Last-Modified', 'Wed, 01 Jan 2025 00:00:00 GMT');
    $expect(
        'L2 前置条件：真实 Response 未设 Cache-Control 时计算值随 Last-Modified 变（判别式成立）',
        $calcProbe->headers->get('Cache-Control'),
        'private, must-revalidate'
    );
    $bareReport = $bareEvent->getResponse();
    $bareReport->headers->set('Last-Modified', 'Wed, 01 Jan 2025 00:00:00 GMT');
    $expect(
        'L2 报告页：Cache-Control 是入口类自己设的（显式值压过 ResponseHeaderBag 的计算默认）',
        $bareReport->headers->get('Cache-Control'),
        'no-cache, private'
    );

    // ---- g) 子请求：事件顺序（守卫 2 的依据） ----
    $subSeq = [];
    $dispatcher->addListener(Symfony\Component\HttpKernel\KernelEvents::REQUEST, function (Symfony\Component\HttpKernel\Event\RequestEvent $e) use (&$subSeq): void {
        $subSeq[] = 'req:' . ($e->isMainRequest() ? 'main' : 'sub');
    }, 10001);
    $dispatcher->addListener(Symfony\Component\HttpKernel\KernelEvents::RESPONSE, function (Symfony\Component\HttpKernel\Event\ResponseEvent $e) use (&$subSeq): void {
        $subSeq[] = 'resp:' . ($e->isMainRequest() ? 'main' : 'sub');
    }, -10001);

    $sub = Symfony\Component\HttpFoundation\Request::create('/_fragment');
    $sub->attributes->set('_controller', static fn (): Symfony\Component\HttpFoundation\Response => new Symfony\Component\HttpFoundation\Response('frag'));
    $main = Symfony\Component\HttpFoundation\Request::create('/page');
    // ESI / fragment / forward() 的真实形态：主请求控制器内 handle 一个子请求
    $main->attributes->set('_controller', static function () use ($kernel, $sub): Symfony\Component\HttpFoundation\Response {
        $kernel->handle($sub, Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST);

        return new Symfony\Component\HttpFoundation\Response('page');
    });
    $mainResponse = $kernel->handle($main);

    $expect('L2 子请求：主请求响应正常', $mainResponse->getContent(), 'page');
    $expect(
        'L2 子请求：真实事件顺序是 req:main → req:sub → resp:sub → resp:main（子请求的 response 先到）',
        $subSeq,
        ['req:main', 'req:sub', 'resp:sub', 'resp:main']
    );
    $subEvent = new Symfony\Component\HttpKernel\Event\RequestEvent(
        $kernel,
        Symfony\Component\HttpFoundation\Request::create('/_fragment'),
        Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST
    );
    $mainEvent = new Symfony\Component\HttpKernel\Event\RequestEvent(
        $kernel,
        Symfony\Component\HttpFoundation\Request::create('/page'),
        Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST
    );
    $expect('L2 子请求：isMainRequest() 对 SUB_REQUEST 为 false', $subEvent->isMainRequest(), false);
    $expect('L2 子请求：isMainRequest() 对 MAIN_REQUEST 为 true', $mainEvent->isMainRequest(), true);

    // ---- h) 重抛：kernel.response 不派发（守卫 3 的依据） ----
    $responseEvents = 0;
    $dispatcher->addListener(Symfony\Component\HttpKernel\KernelEvents::RESPONSE, function () use (&$responseEvents): void {
        $responseEvents++;
    }, -10001);
    $boom = Symfony\Component\HttpFoundation\Request::create('/boom');
    $boom->attributes->set('_controller', static function (): never {
        throw new \RuntimeException('boom');
    });
    $caught = null;
    try {
        $kernel->handle($boom);   // catch=true（生产默认值）也一样重抛：没人处理 kernel.exception
    } catch (\Throwable $e) {
        $caught = get_class($e);
    }
    $expect('L2 重抛依据：控制器异常在 catch=true 下也逃出 handle()', $caught, 'RuntimeException');
    $expect('L2 重抛依据：整条请求里 kernel.response 一次都没派发', $responseEvents, 0);

    // ---- i) 事件订阅声明 ----
    $expect(
        'L2 getSubscribedEvents() 的返回值（优先级 10000 / -10000 的来源）',
        \ErikWang2013\Xhprof\Symfony\XhprofListener::getSubscribedEvents(),
        [
            Symfony\Component\HttpKernel\KernelEvents::REQUEST => ['onRequest', 10000],
            Symfony\Component\HttpKernel\KernelEvents::RESPONSE => ['onResponse', -10000],
        ]
    );
    $expect(
        'L2 对照：真实 ResponseListener 订阅 kernel.response 用的是默认优先级 0',
        Symfony\Component\HttpKernel\EventListener\ResponseListener::getSubscribedEvents(),
        [Symfony\Component\HttpKernel\KernelEvents::RESPONSE => 'onKernelResponse']
    );

    // ================= L2：需要 ext-xhprof =================
    // 顺序是被设计过的：先跑不采样/只读的，最后跑会真开采样的，避免静态状态串味。
    $extChecks = 0;
    $extFailures = [];
    $extExpect = static function (string $label, mixed $actual, mixed $expected) use (&$extChecks, &$extFailures): void {
        $extChecks++;
        if ($actual !== $expected) {
            $extFailures[] = $label . '：得到 ' . var_export($actual, true) . '，期望 ' . var_export($expected, true);
        }
    };
    $extDeclared = 36;   // 与下面实际条数一致（本机有扩展时会自检，见末尾）

    if (!$ext) {
        $skips += $extDeclared;
    } else {
        $runs = static function (ErikWang2013\Xhprof\Tests\Fixtures\FakeCache $cache): array {
            return $cache->lRange('xhprof:run_id', 0, -1);
        };
        $hasKeyLike = static function (mixed $data, string $needle): bool {
            foreach ((array) $data as $key => $_) {
                if (strpos((string) $key, $needle) !== false) {
                    return true;
                }
            }

            return false;
        };

        $newListener = static function (ErikWang2013\Xhprof\Tests\Fixtures\FakeCache $cache, array $config = ['enable' => true]): array {
            $d = new Symfony\Component\EventDispatcher\EventDispatcher();
            $l = new \ErikWang2013\Xhprof\Symfony\XhprofListener($config, $cache);
            $d->addSubscriber($l);
            $st = new Symfony\Component\HttpFoundation\RequestStack();

            return [$d, $l, new Symfony\Component\HttpKernel\HttpKernel(
                $d,
                new Symfony\Component\HttpKernel\Controller\ControllerResolver(),
                $st,
                new Symfony\Component\HttpKernel\Controller\ArgumentResolver()
            )];
        };

        // 1) 报告页与静态资源：短路发生在 isEnabled()/extension_loaded() **之前**，不许开采样
        $cache1 = new ErikWang2013\Xhprof\Tests\Fixtures\FakeCache();
        [, , $kernel1] = $newListener($cache1);
        $kernel1->handle(Symfony\Component\HttpFoundation\Request::create('http://example.com/xhprof'));
        $extExpect('L2 采样：报告页请求未开采样', xhprof_disable(), null);
        $extExpect('L2 采样：报告页请求未落库', $runs($cache1), []);

        $cache2 = new ErikWang2013\Xhprof\Tests\Fixtures\FakeCache();
        [, $listener2, $kernel2] = $newListener($cache2);
        $assetEvent = new Symfony\Component\HttpKernel\Event\RequestEvent(
            $kernel2,
            Symfony\Component\HttpFoundation\Request::create('http://example.com/xhprof-assets/css/xhprof.css'),
            Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST
        );
        $listener2->onRequest($assetEvent);
        $extExpect(
            'L2 采样：静态资源短路成 BinaryFileResponse',
            $assetEvent->getResponse() instanceof Symfony\Component\HttpFoundation\BinaryFileResponse,
            true
        );
        $extExpect('L2 采样：静态资源请求未开采样', xhprof_disable(), null);
        $extExpect('L2 采样：静态资源请求未落库', $runs($cache2), []);

        // 2) enable=false：既不开采样也不落库
        $cache3 = new ErikWang2013\Xhprof\Tests\Fixtures\FakeCache();
        [, , $kernel3] = $newListener($cache3, ['enable' => false]);
        $disabled = Symfony\Component\HttpFoundation\Request::create('/index');
        $disabled->attributes->set('_controller', static fn (): Symfony\Component\HttpFoundation\Response => new Symfony\Component\HttpFoundation\Response('ok'));
        $kernel3->handle($disabled);
        $extExpect('L2 采样：enable=false 未开采样', xhprof_disable(), null);
        $extExpect('L2 采样：enable=false 未落库', $runs($cache3), []);

        // 3) 常开请求：真实 HttpKernel 全链路，落一条**含本次采样**的记录
        $cache4 = new ErikWang2013\Xhprof\Tests\Fixtures\FakeCache();
        [, , $kernel4] = $newListener($cache4);
        $normalReq = Symfony\Component\HttpFoundation\Request::create('/index?x=1');
        $normalReq->attributes->set('_controller', static function (): Symfony\Component\HttpFoundation\Response {
            xhprof_sym_marker();

            return new Symfony\Component\HttpFoundation\Response('ok');
        });
        $kernel4->handle($normalReq);
        $runs4 = $runs($cache4);
        $extExpect('L2 采样：常开请求恰好落一条', count($runs4), 1);
        $data4 = $runs4 === [] ? null : unserialize((string) $cache4->get('xhprof:xhprof_log:' . $runs4[0]));
        $extExpect('L2 采样：落库数据是本次采样（有 main()）', is_array($data4) && array_key_exists('main()', $data4), true);
        $extExpect('L2 采样：采样覆盖到控制器里的调用（有 xhprof_sym_marker 的边）', $hasKeyLike($data4, 'xhprof_sym_marker'), true);
        $extExpect('L2 采样：请求结束后采样已停（二级 stop 无害）', xhprof_disable(), null);

        // 4) onResponse 的优先级落点：-10001 上还没落库、-9999 上已落库 → 恰在 -10000
        $cache5 = new ErikWang2013\Xhprof\Tests\Fixtures\FakeCache();
        $d5 = new Symfony\Component\EventDispatcher\EventDispatcher();
        $d5->addSubscriber(new \ErikWang2013\Xhprof\Symfony\XhprofListener(['enable' => true], $cache5));
        $seen = [];
        // 优先级大的先跑：-9999 早于 onResponse(-10000)，-10001 晚于它。
        // 夹住 (9999 → -10001] 就只剩 -10000 这一个整数落点。
        $d5->addListener(Symfony\Component\HttpKernel\KernelEvents::RESPONSE, function () use (&$seen, $cache5): void {
            $seen['at-9999'] = count($cache5->lRange('xhprof:run_id', 0, -1));
        }, -9999);
        $d5->addListener(Symfony\Component\HttpKernel\KernelEvents::RESPONSE, function () use (&$seen, $cache5): void {
            $seen['at-10001'] = count($cache5->lRange('xhprof:run_id', 0, -1));
        }, -10001);
        // 0 号位 = 真实 ResponseListener 坐的位置：此刻必须**还没**落库（onResponse 在更后面）
        $d5->addListener(Symfony\Component\HttpKernel\KernelEvents::RESPONSE, function () use (&$seen, $cache5): void {
            $seen['at-0'] = count($cache5->lRange('xhprof:run_id', 0, -1));
        }, 0);
        $st5 = new Symfony\Component\HttpFoundation\RequestStack();
        $kernel5 = new Symfony\Component\HttpKernel\HttpKernel(
            $d5,
            new Symfony\Component\HttpKernel\Controller\ControllerResolver(),
            $st5,
            new Symfony\Component\HttpKernel\Controller\ArgumentResolver()
        );
        $req5 = Symfony\Component\HttpFoundation\Request::create('/index');
        $req5->attributes->set('_controller', static fn (): Symfony\Component\HttpFoundation\Response => new Symfony\Component\HttpFoundation\Response('ok'));
        $kernel5->handle($req5);
        $extExpect('L2 优先级：-9999（onResponse 之前）还没落库', $seen['at-9999'] ?? null, 0);
        $extExpect('L2 优先级：-10001（onResponse 之后）已落库 → onResponse 恰在 -10000', $seen['at-10001'] ?? null, 1);
        // 注意别从 getSubscribedEvents() 的返回值里"取 [1] 当优先级"：它返回的是**字符串形态**
        // （['kernel.response' => 'onKernelResponse']），[1] 是字符 'n'。0 这个数只有 dispatcher 自己知道。
        $extExpect(
            'L2 优先级：真实 dispatcher 里 ResponseListener 的实际注册优先级是 0（下面那条的"0"不是我们写死的）',
            $dispatcher->getListenerPriority(
                Symfony\Component\HttpKernel\KernelEvents::RESPONSE,
                [$responseListener, 'onKernelResponse']
            ),
            0
        );
        $extExpect(
            'L2 优先级：在真实 ResponseListener 所在的 0 号位上，采样还没落库 → onResponse(-10000) 确实排在它之后',
            $seen['at-0'] ?? null,
            0
        );

        // 5) 子请求守卫：子请求的 response 事件不许停掉主请求采样
        $cache6 = new ErikWang2013\Xhprof\Tests\Fixtures\FakeCache();
        $d6 = new Symfony\Component\EventDispatcher\EventDispatcher();
        $d6->addSubscriber(new \ErikWang2013\Xhprof\Symfony\XhprofListener(['enable' => true], $cache6));
        $atSubResponse = null;
        $d6->addListener(Symfony\Component\HttpKernel\KernelEvents::RESPONSE, function (Symfony\Component\HttpKernel\Event\ResponseEvent $e) use (&$atSubResponse, $cache6): void {
            if (!$e->isMainRequest()) {
                // 去掉 onResponse 的子请求守卫时，这里会变成 1（子请求抢先落库、主请求后半段丢失）
                $atSubResponse = count($cache6->lRange('xhprof:run_id', 0, -1));
            }
        }, -10001);
        $st6 = new Symfony\Component\HttpFoundation\RequestStack();
        $kernel6 = new Symfony\Component\HttpKernel\HttpKernel(
            $d6,
            new Symfony\Component\HttpKernel\Controller\ControllerResolver(),
            $st6,
            new Symfony\Component\HttpKernel\Controller\ArgumentResolver()
        );
        $sub6 = Symfony\Component\HttpFoundation\Request::create('/_fragment');
        $sub6->attributes->set('_controller', static fn (): Symfony\Component\HttpFoundation\Response => new Symfony\Component\HttpFoundation\Response('frag'));
        $main6 = Symfony\Component\HttpFoundation\Request::create('/page');
        $main6->attributes->set('_controller', static function () use ($kernel6, $sub6): Symfony\Component\HttpFoundation\Response {
            $kernel6->handle($sub6, Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST);
            xhprof_sym_marker();   // 子请求**之后**的调用：被提前停掉的话它就采不到

            return new Symfony\Component\HttpFoundation\Response('page');
        });
        $kernel6->handle($main6);
        $extExpect('L2 子请求：子请求的 response 事件上没有发生落库', $atSubResponse, 0);
        $runs6 = $runs($cache6);
        $extExpect('L2 子请求：整条请求仍只落一条', count($runs6), 1);
        $data6 = $runs6 === [] ? null : unserialize((string) $cache6->get('xhprof:xhprof_log:' . $runs6[0]));
        $extExpect('L2 子请求：主请求采样没被子请求截断（有子请求之后的调用）', $hasKeyLike($data6, 'xhprof_sym_marker'), true);

        // onRequest 的守卫单独验：只跑子请求时**不许**开采样。
        // （混合场景里它被掩盖着：子请求悄悄重启采样，后面的断言照样通过。）
        $cache7 = new ErikWang2013\Xhprof\Tests\Fixtures\FakeCache();
        [, , $kernel7] = $newListener($cache7);
        $sub7 = Symfony\Component\HttpFoundation\Request::create('/_fragment');
        $sub7->attributes->set('_controller', static fn (): Symfony\Component\HttpFoundation\Response => new Symfony\Component\HttpFoundation\Response('frag'));
        $kernel7->handle($sub7, Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST);
        $extExpect('L2 子请求：只跑子请求时不开采样', xhprof_disable(), null);
        $extExpect('L2 子请求：只跑子请求时不落库', $runs($cache7), []);

        // 6) shutdown 兜底：真子进程（shutdown 时机在进程内无法观测），用**真实** HttpKernel
        $scenarios = [
            // 正常路径：onResponse 落库一次，随后的兜底必须幂等
            'normal' => ['runs' => 1, 'marker' => true, 'threw' => null, 'registrations' => 1],
            // 重抛路径：kernel.response 不触发，只有兜底能救
            'rethrow' => ['runs' => 1, 'marker' => true, 'threw' => 'RuntimeException', 'registrations' => 1],
            // 三个请求周期：每条各落一次（3 条），而 register_shutdown_function 只注册 1 次
            // —— 长驻进程里按请求注册会无限堆积，这条守卫只有多周期才观测得到
            'multicycle' => ['runs' => 3, 'marker' => true, 'threw' => null, 'registrations' => 1],
            // enable=false：**全新进程里的第一个周期就没开采样**。专测 $stopped 的初值 true
            // ——「没开过采样就绝不 stop」。线程内测不出来：先跑过的场景会把 $stopped 翻成 true，
            // 把初值掩盖掉；报告页分支也掩盖（/xhprof 在 ignore_url_arr 里，isIgnore() 先一步
            // 挡掉落库）。实测把初值改成 false：这里 runs 从 0 变 1（多一条空采样）。
            'disabled' => ['runs' => 0, 'marker' => false, 'threw' => null, 'registrations' => 0],
        ];
        foreach ($scenarios as $scenario => $want) {
            $child = __DIR__ . '/.symfony-shutdown-' . $scenario . '.php';
            file_put_contents($child, symfony_shutdown_child_script());
            $proc = contracts_run_php([$child, $repoRoot, $scenario]);
            @unlink($child);
            $decoded = json_decode(trim($proc['stdout']), true);
            $extExpect(
                "L2 兜底（{$scenario}）：子进程输出合法 JSON（exit {$proc['code']}）"
                . (is_array($decoded) ? '' : '：' . trim($proc['stderr'] !== '' ? $proc['stderr'] : $proc['stdout'])),
                is_array($decoded),
                true
            );
            $extExpect(
                "L2 兜底（{$scenario}）：落库条数与采样内容"
                . ($scenario === 'rethrow' ? '（kernel.response 从未触发，只能靠兜底）' : ''),
                is_array($decoded) ? [$decoded['runs'] ?? null, $decoded['marker'] ?? null] : null,
                [$want['runs'], $want['marker']]
            );
            $extExpect(
                "L2 兜底（{$scenario}）：异常逃出 handle() 的情况",
                is_array($decoded) ? ($decoded['threw'] ?? null) : null,
                $want['threw']
            );
            $extExpect(
                "L2 兜底（{$scenario}）：register_shutdown_function 的注册次数",
                is_array($decoded) ? ($decoded['registrations'] ?? null) : null,
                $want['registrations']
            );
        }
    }

    if ($ext && $extChecks !== $extDeclared) {
        $failures[] = "扩展相关检查数漂移：实际跑了 {$extChecks} 条，声明 {$extDeclared} 条"
            . '（声明值决定扩展缺失时记多少 skip，漂了会让 CI 的 skip 数不准）';
        $checks++;
    }
    $checks += $extChecks;   // 采样类断言也是断言，计入总数（否则 detail 里会把 28 条数漏掉）
    foreach ($extFailures as $f) {
        $failures[] = $f;
    }

    $note = 'ext-xhprof ' . ($ext ? '已加载（采样类断言全部真跑）' : '**未加载**（采样类断言计入 skips，非静默通过）')
        . '；' . $mimeNote;

    if ($failures !== []) {
        return [
            'status' => 'FAIL',
            'detail' => $note . ' —— 共 ' . $checks . ' 项断言，' . count($failures) . ' 项失败：'
                . implode(' | ', array_slice($failures, 0, 6))
                . (count($failures) > 6 ? ' | …另有 ' . (count($failures) - 6) . ' 项' : ''),
            'skips' => $skips,
        ];
    }

    return [
        'status' => 'PASS',
        'detail' => $note . ' —— ' . $checks . ' 项断言全部通过'
            . ($skips > 0 ? '；' . $skips . ' 项采样断言因缺 ext-xhprof 未验' : ''),
        'skips' => $skips,
    ];
};

/**
 * 子进程脚本：真实 HttpKernel + 真实 shutdown 时机。
 *
 * 四个场景：
 *   normal     —— 控制器正常返回，kernel.response 触发（onResponse 落库一次），
 *                 随后 shutdown 兜底也必须幂等（总共仍是 1 条）；
 *   rethrow    —— 控制器抛异常且无人处理，HttpKernel 重抛（**真实**行为，catch=true 也一样），
 *                 kernel.response 永不触发 —— 只有 shutdown 兜底能救。去掉兜底时这里会变 0 条；
 *   multicycle —— 三个请求周期，各落一条（3 条），而 register_shutdown_function 只注册 1 次。
 *                 长驻进程（RoadRunner/Swoole）里按请求注册会无限堆积，只有多周期观测得到。
 *   disabled   —— enable=false：全新进程的第一个周期就没开采样，专测 $stopped 的初值。
 *
 * 观测点（register_shutdown_function）在 handle() **之后**注册，因此跑在入口类注册的
 * 兜底回调之后，读到的是兜底跑完的状态。
 */
function symfony_shutdown_child_script(): string
{
    return <<<'PHP'
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Symfony {
    /**
     * 观测 register_shutdown_function 的**调用次数**。入口类里那个未限定的调用会先命中
     * 本命名空间的同名函数（PHP 的 namespace fallback 语义），计数后原样转发给全局函数。
     * 没有这个影子函数，"每进程只注册一次"这条守卫在单进程测试里根本观测不到。
     */
    function register_shutdown_function(callable $callback, mixed ...$args): void
    {
        $GLOBALS['xhprof_sym_shutdown_registrations'] = ($GLOBALS['xhprof_sym_shutdown_registrations'] ?? 0) + 1;
        \register_shutdown_function($callback, ...$args);
    }
}

namespace {

    use ErikWang2013\Xhprof\Symfony\XhprofListener;
    use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
    use Symfony\Component\EventDispatcher\EventDispatcher;
    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\HttpFoundation\RequestStack;
    use Symfony\Component\HttpFoundation\Response;
    use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
    use Symfony\Component\HttpKernel\Controller\ControllerResolver;
    use Symfony\Component\HttpKernel\HttpKernel;

    if (!function_exists('xhprof_sym_marker')) {
        function xhprof_sym_marker(): int
        {
            return 42;
        }
    }

    $repo = $argv[1];
    $scenario = $argv[2];

    require $repo . '/tools/contracts/vendor/autoload.php';

    spl_autoload_register(static function (string $class) use ($repo): void {
        $prefix = 'ErikWang2013\\Xhprof\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $file = $repo . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });

    // 必须在 src 自注册**之后**：Fakes.php 里的 FakeCache 加载期就要 implements Core 契约
    require $repo . '/tests/Fixtures/Fakes.php';

    $cache = new FakeCache();
    $dispatcher = new EventDispatcher();
    $dispatcher->addSubscriber(new XhprofListener(['enable' => $scenario !== 'disabled'], $cache));
    $stack = new RequestStack();
    $kernel = new HttpKernel($dispatcher, new ControllerResolver(), $stack, new ArgumentResolver());

    $threw = null;
    $cycles = $scenario === 'multicycle' ? 3 : 1;

    for ($i = 0; $i < $cycles; $i++) {
        $request = Request::create('/index');
        $request->attributes->set('_controller', static function () use ($scenario): Response {
            xhprof_sym_marker();
            if ($scenario === 'rethrow') {
                throw new RuntimeException('controller boom');
            }

            return new Response('ok');
        });
        try {
            $kernel->handle($request);
        } catch (Throwable $e) {
            $threw = get_class($e);
        }
    }

    \register_shutdown_function(static function () use ($cache, $threw): void {
        $runs = $cache->lRange('xhprof:run_id', 0, -1);
        $data = $runs === [] ? [] : (array) unserialize((string) $cache->get('xhprof:xhprof_log:' . $runs[0]));
        $marker = false;
        foreach (array_keys($data) as $key) {
            if (strpos((string) $key, 'xhprof_sym_marker') !== false) {
                $marker = true;
                break;
            }
        }
        echo json_encode([
            'runs' => count($runs),
            'marker' => $marker,
            'threw' => $threw,
            'registrations' => $GLOBALS['xhprof_sym_shutdown_registrations'] ?? 0,
        ]), "\n";
    });
}
PHP;
}
