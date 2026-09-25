<?php

declare(strict_types=1);

/**
 * Drupal —— 用**真实** symfony/http-foundation + symfony/http-kernel 验证 src/Drupal/**。
 *
 * 前提：Drupal 9+ 的 Request/Response **就是** Symfony 的（`drupal/core` 只是把它们
 * 包一层 `Drupal\Core\*` 的服务），所以 L1/L2 的 Request/Response 语义可以用真包验满。
 *
 * L1（签名存在）：反射断言 src/Drupal 调用的每个方法/常量/属性在真实包里存在：
 *   - Request::create/getRequestUri/getHost/getUri/getClientIp、$query/$request/$headers 的类型；
 *   - InputBag::all() 的结构、HeaderBag::set() 的第二参声明（string|array|null）；
 *   - Response::setStatusCode()/setContent()、BinaryFileResponse extends Response；
 *   - HttpKernelInterface 的常量值（MAIN_REQUEST=1 / SUB_REQUEST=2），以及
 *     XhprofMiddleware::handle() 的签名与真实接口**逐参一致**（它是被 Drupal 的
 *     StackedHttpKernel 直接 call 的，签名漂了就是加载期 fatal）。
 *
 * L2（契约语义，用真包，不需要 ext-xhprof）—— 每条都是**设计前提的证据**，不是复述代码：
 *   a) R-1：真实 getRequestUri() 只含 path+query，而 getUri() 含 host —— 所以
 *      `uri()` 必须用后者；用一个 host 叫 xhprof.example.com 的站点做反证
 *      （isIgnore() 对 uri() 做子串匹配，返回绝对 URL 会让整个站被误忽略）；
 *   b) R-2/R-3：真实 getHost() 去端口并小写；真实 getClientIp() 无 REMOTE_ADDR 时
 *      返回 null，而契约声明 : string；
 *   c) InputBag::get() 对数组值抛 BadRequestException —— 适配器改读 all() 的**依据**；
 *   d) HeaderBag::set() 传 int 抛 TypeError（strict_types 下）—— 加 (string) 的**依据**；
 *   e) R-4/R-5：真实 Response 的链式调用、setStatusCode(99) 抛 InvalidArgumentException、
 *      真实 BinaryFileResponse 的 getContent() 恒为 false（流式）与 Cache-Control 归一化；
 *   f) 真实 HttpKernel + 真实 ControllerResolver/ArgumentResolver 跑本包的
 *      XhprofMiddleware：主请求被采样、子请求透传（Drupal 的 ESI / fragment 形状：
 *      控制器里再调一次 $kernel->handle($sub, SUB_REQUEST)、异常逃出 handle()。
 *
 * L2（需要 ext-xhprof）：采样类断言（真的开了采样才谈得上"落库了/没被腰斩"）。
 *   本机有扩展 → skips=0。扩展缺失时这些断言**计入 skips**（不伪装成通过）。
 *
 * **不验的三项（skips，理由见文件末尾的 $skips 累加处）**：
 *   1) ConfigAdapter 对真实 config.factory / ImmutableConfig 的语义 —— 环的
 *      composer.json 里没有 drupal/core（本卡不许改它），且真实语义需要引导过的内核 + 配置存储；
 *   2) 服务串接（`http_middleware` 标签、priority 1000 是否真的最外、内层 kernel 的注入）
 *      —— 需要编译容器，属集成测试；
 *   3) `Drupal\Core\*` 接口与真实包的声明一致性 —— 同 1；本 case 用本仓库的桩让
 *      中间件可实例化，**桩的忠实性不计入本环结论**（已对 drupal/core 10.0.7 与 11.4.7
 *      源码人工核对，见卡内报告）。
 */

// 采样数据里以 main()==>xhprof_drupal_marker 这类 key 出现，用来证明"落库的是本次真实采样"
if (!function_exists('xhprof_drupal_marker')) {
    function xhprof_drupal_marker(): int
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
    // contracts.yml 只 install tools/contracts，主仓库 dev vendor 在 CI 里不存在。
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

    // 必须在 src 自注册**之后**：Fakes.php 里的 FakeCache 加载期就要 implements Core 契约
    require_once $repoRoot . '/tests/Fixtures/Fakes.php';

    // Drupal 侧接口：环里没有 drupal/core，只能用本仓库的桩让中间件/适配器可实例化。
    // 桩只在这里用于**可运行**，它对真实接口的忠实性单独记 SKIP（见文件末尾）。
    // 带 autoload 的 interface_exists：万一将来环里装了 drupal/core，这里就不会再加载桩、
    // 也就不会撞成 "Cannot declare interface" 的加载期 fatal。
    $stubLoaded = false;
    if (!interface_exists('Drupal\\Core\\Config\\ConfigFactoryInterface')) {
        require_once $repoRoot . '/tests/Stubs/Framework/Drupal.php';
        $stubLoaded = true;
    }

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
    $expectThrows = static function (string $label, string $class, callable $fn) use (&$checks, &$failures): void {
        $checks++;
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $class) {
                return;
            }
            $failures[] = $label . '：抛的是 ' . get_class($e) . '（期望 ' . $class . '）：' . $e->getMessage();
            return;
        }
        $failures[] = $label . '：没有抛 ' . $class;
    };

    $ext = extension_loaded('xhprof');
    $extChecks = 0;
    $extFailures = [];
    $extExpect = static function (string $label, mixed $actual, mixed $expected) use (&$extChecks, &$extFailures): void {
        $extChecks++;
        if ($actual !== $expected) {
            $extFailures[] = $label . '：得到 ' . var_export($actual, true) . '，期望 ' . var_export($expected, true);
        }
    };

    if (!$stubLoaded) {
        // 现实里到不了这里（composer.json 没有 drupal/core，且本卡不许改它）。
        // 真到了说明环境变了，宁可整例红掉让人看见，也不要静默跳过。
        return [
            'status' => 'FAIL',
            'detail' => '环里出现了真实 drupal/core —— 本 case 的桩不被加载，'
                . 'Drupal 侧断言全部未执行。请把 drupal/core 加进 composer.json 后重写本 case 的 Drupal 侧装置。',
            'skips' => 0,
        ];
    }

    // ================= L1：签名存在（真实 Symfony） =================

    $symbols = [
        'Symfony\Component\HttpFoundation\Request' => [
            'create', 'getMethod', 'getClientIp', 'getHost', 'getRequestUri', 'getUri',
        ],
        'Symfony\Component\HttpFoundation\InputBag' => ['all', 'get'],
        'Symfony\Component\HttpFoundation\HeaderBag' => ['all', 'get', 'set'],
        'Symfony\Component\HttpFoundation\Response' => [
            '__construct', 'setContent', 'getContent', 'setStatusCode', 'getStatusCode', 'isCacheable',
        ],
        'Symfony\Component\HttpFoundation\BinaryFileResponse' => ['__construct', 'getFile'],
        'Symfony\Component\HttpKernel\HttpKernelInterface' => ['handle'],
        'Symfony\Component\HttpKernel\HttpKernel' => ['__construct', 'handle'],
        'Symfony\Component\HttpKernel\Controller\ControllerResolver' => ['__construct', 'getController'],
        'Symfony\Component\HttpKernel\Controller\ArgumentResolver' => ['__construct', 'getArguments'],
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

    // 适配器直接读 $request->query / ->request / ->headers
    foreach ([
        'query' => 'Symfony\Component\HttpFoundation\InputBag',
        'request' => 'Symfony\Component\HttpFoundation\InputBag',
        'headers' => 'Symfony\Component\HttpFoundation\HeaderBag',
    ] as $prop => $type) {
        $expect(
            "L1 Request::\${$prop} 的类型是 {$type}",
            (string) (new ReflectionProperty(Symfony\Component\HttpFoundation\Request::class, $prop))->getType(),
            $type
        );
    }

    // 常量值：中间件用 self::MAIN_REQUEST 判断主/子请求，值错了守卫就形同虚设
    $expect('L1 HttpKernelInterface::MAIN_REQUEST', Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST, 1);
    $expect('L1 HttpKernelInterface::SUB_REQUEST', Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST, 2);

    // HeaderBag::set() 的第二参不含 int —— 适配器加 (string) 的类型层依据
    $setParam = (new ReflectionMethod(Symfony\Component\HttpFoundation\HeaderBag::class, 'set'))->getParameters()[1];
    $expect(
        'L1 HeaderBag::set() 第二参是 array|string|null（不含 int）',
        (string) $setParam->getType(),
        'array|string|null'
    );
    // InputBag::get() 的返回类型里没有 array —— "它永远不可能返回数组"的类型层依据
    $expect(
        'L1 InputBag::get() 的返回类型不含 array（所以适配器读 all() 而不是 get()）',
        (string) (new ReflectionMethod(Symfony\Component\HttpFoundation\InputBag::class, 'get'))->getReturnType(),
        'string|int|float|bool|null'
    );

    $expect(
        'L1 BinaryFileResponse extends Response（控制器靠 instanceof Response 判断）',
        is_subclass_of(Symfony\Component\HttpFoundation\BinaryFileResponse::class, Symfony\Component\HttpFoundation\Response::class),
        true
    );

    // ---- 本包自己的类实现了真实接口，且 handle() 的默认值没漂 ----

    $expect(
        'L1 XhprofMiddleware implements 真实 HttpKernelInterface',
        is_subclass_of(ErikWang2013\Xhprof\Drupal\XhprofMiddleware::class, Symfony\Component\HttpKernel\HttpKernelInterface::class),
        true
    );
    // 参数类型/必选性漂了会被 PHP 的接口兼容性检查在**加载期**打死（不是本环发现的），
    // 但**默认值** PHP 不校验：把 self::MAIN_REQUEST 手滑写成 self::SUB_REQUEST，
    // 语法和 LSP 都过得去，而 Drupal 的 StackedHttpKernel 正是用默认值调 handle() 的
    // —— 那样全站主请求都会被当子请求透传、一次采样都不开。这条只能靠反射验。
    $handleParams = (new ReflectionMethod(ErikWang2013\Xhprof\Drupal\XhprofMiddleware::class, 'handle'))->getParameters();
    $expect(
        'L1 handle() 的 $type 默认值是 MAIN_REQUEST',
        $handleParams[1]->getDefaultValue(),
        Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST
    );
    $expect('L1 handle() 的 $catch 默认值是 true', $handleParams[2]->getDefaultValue(), true);
    $expect(
        'L1 handle() 的返回类型是真实 Response',
        (string) (new ReflectionMethod(ErikWang2013\Xhprof\Drupal\XhprofMiddleware::class, 'handle'))->getReturnType(),
        'Symfony\Component\HttpFoundation\Response'
    );

    // ================= L2：真实 Request / Response 语义 =================

    $mk = static function (string $uri, array $post = [], array $server = []): Symfony\Component\HttpFoundation\Request {
        return Symfony\Component\HttpFoundation\Request::create($uri, $post === [] ? 'GET' : 'POST', $post, [], [], $server);
    };

    // ---- R-1：uri() 只能是 path+query ----
    $hostCalledXhprof = $mk('http://xhprof.example.com:8080/admin?x=1');
    $expectContains(
        'L2 反证前提：真实 getUri()（= url()）含 host，站点叫 xhprof.* 时时它天然含 "xhprof"',
        $hostCalledXhprof->getUri(),
        'xhprof.example.com'
    );
    $expect('L2 R-1 真实 getRequestUri() 只含 path+query', $hostCalledXhprof->getRequestUri(), '/admin?x=1');
    $expect(
        'L2 R-1 RequestAdapter::uri() 用的是 getRequestUri()（不含 host，否则整站被 ignore_url_arr 误伤）',
        (new ErikWang2013\Xhprof\Drupal\Adapter\RequestAdapter($hostCalledXhprof))->uri(),
        '/admin?x=1'
    );
    $expect(
        'L2 R-1 uri() 与 url() 在真实 Request 上确实是两个东西',
        strpos((new ErikWang2013\Xhprof\Drupal\Adapter\RequestAdapter($hostCalledXhprof))->uri(), '://') === false,
        true
    );

    // ---- R-2 / R-3 ----
    $portHost = $mk('http://Example.COM:8080/a');
    $expect('L2 R-2 真实 getHost() 去端口', $portHost->getHost(), 'example.com');
    $expect(
        'L2 R-2 RequestAdapter::host()',
        (new ErikWang2013\Xhprof\Drupal\Adapter\RequestAdapter($portHost))->host(),
        'example.com'
    );

    $noRemoteAddr = new Symfony\Component\HttpFoundation\Request([], [], [], [], [], ['SERVER_NAME' => 'example.com']);
    $expect('L2 R-3 真实 getClientIp() 无 REMOTE_ADDR 时是 null', $noRemoteAddr->getClientIp(), null);
    $expect('L2 R-3 (string) null === ""（适配器的兜底）', (string) $noRemoteAddr->getClientIp(), '');
    $expect(
        'L2 R-3 RequestAdapter::getRealIp() 永远返回 string',
        (new ErikWang2013\Xhprof\Drupal\Adapter\RequestAdapter($noRemoteAddr))->getRealIp(),
        ''
    );
    $expect(
        'L2 R-3 有 REMOTE_ADDR 时原样透传',
        (new ErikWang2013\Xhprof\Drupal\Adapter\RequestAdapter($mk('/a')))->getRealIp(),
        '127.0.0.1'
    );

    // ---- 适配器整体行为（真实 Request::create） ----
    $rich = $mk('/admin?x=1&y=2', ['p' => 1], ['HTTP_X_FOO' => 'bar', 'REMOTE_ADDR' => '10.0.0.9']);
    $adapter = new ErikWang2013\Xhprof\Drupal\Adapter\RequestAdapter($rich);
    $expect('L2 get() 取 query', $adapter->get('x'), '1');
    $expect('L2 get() 取 POST body（GET 请求时 body 为空，故这里用 POST 再验一次）',
        (new ErikWang2013\Xhprof\Drupal\Adapter\RequestAdapter($mk('/admin', ['p' => 1])))->get('p'), 1);
    $expect('L2 get() 缺省值', $adapter->get('nope', 'd'), 'd');
    $expect('L2 all() 是 query+body 的并集', $adapter->all(), ['x' => '1', 'y' => '2', 'p' => 1]);
    $expect('L2 method()（带 body 的请求，真实 getMethod() 已大写）', $adapter->method(), 'POST');
    $expect(
        'L2 method() 小写输入也被真实 Request 规范成大写',
        (new ErikWang2013\Xhprof\Drupal\Adapter\RequestAdapter(
            Symfony\Component\HttpFoundation\Request::create('/a', 'get')
        ))->method(),
        'GET'
    );
    $expect('L2 header()（真实的 HeaderBag 大小写不敏感）', $adapter->header('X-Foo'), 'bar');
    $expect('L2 header() 缺省是 null（契约 ?string）', $adapter->header('X-Nope'), null);
    $expect(
        'L2 url() 是绝对 URL',
        (new ErikWang2013\Xhprof\Drupal\Adapter\RequestAdapter($mk('http://example.com:8080/admin?x=1')))->url(),
        'http://example.com:8080/admin?x=1'
    );

    // ---- 适配器设计的依据：InputBag::get() 对数组值抛，all() 拿得到 ----
    $arrayParam = $mk('/xhprof?run[]=a');
    $expectThrows(
        'L2 依据：真实 InputBag::get() 对数组值抛 BadRequestException',
        Symfony\Component\HttpFoundation\Exception\BadRequestException::class,
        static fn () => $arrayParam->query->get('run')
    );
    $expect('L2 依据：真实 InputBag::all() 拿得到数组', $arrayParam->query->all()['run'], ['a']);
    $expect(
        'L2 所以 RequestAdapter::get() 返回数组而不是抛（调用点 Xhprof::index() 靠 is_string() 判 400）',
        (new ErikWang2013\Xhprof\Drupal\Adapter\RequestAdapter($arrayParam))->get('run'),
        ['a']
    );

    // ---- R-4：真实 Response 的链式调用 ----
    $responseAdapter = new ErikWang2013\Xhprof\Drupal\Adapter\ResponseAdapter();
    $expect('L2 R-4 withStatus 返回自身', $responseAdapter->withStatus(403) === $responseAdapter, true);
    $expect('L2 R-4 withBody 返回自身', $responseAdapter->withBody('403 Forbidden') === $responseAdapter, true);
    $sent = $responseAdapter->withHeaders(['X-A' => '1'])->send();
    $expect('L2 R-4 send() 给出真实 Response', $sent instanceof Symfony\Component\HttpFoundation\Response, true);
    $expect('L2 R-4 状态码落在真实对象上', $sent->getStatusCode(), 403);
    $expect('L2 R-4 内容落在真实对象上', $sent->getContent(), '403 Forbidden');
    $expect('L2 R-4 头落在真实对象上', $sent->headers->get('X-A'), '1');
    $expectThrows(
        'L2 R-4 真实 Response::setStatusCode(99) 抛 InvalidArgumentException（适配器不吞它）',
        \InvalidArgumentException::class,
        static function (): void {
            (new ErikWang2013\Xhprof\Drupal\Adapter\ResponseAdapter())->withStatus(99);
        }
    );

    // ---- 依据：HeaderBag::set() 传 int 会 TypeError（本文件是 strict_types） ----
    $expectThrows(
        'L2 依据：HeaderBag::set() 传 int 抛 TypeError',
        \TypeError::class,
        static function (): void {
            (new Symfony\Component\HttpFoundation\Response())->headers->set('Content-Length', 12);
        }
    );
    $expect(
        'L2 所以 ResponseAdapter::withHeaders() 必须转字符串',
        (new ErikWang2013\Xhprof\Drupal\Adapter\ResponseAdapter())
            ->withHeaders(['Content-Length' => 12])->send()->headers->get('Content-Length'),
        '12'
    );
    $expect(
        'L2 withHeaders() 的数组值原样交给真实 HeaderBag（多值头）',
        (new ErikWang2013\Xhprof\Drupal\Adapter\ResponseAdapter())
            ->withHeaders(['X-Multi' => ['a', 'b']])->send()->headers->all('X-Multi'),
        ['a', 'b']
    );

    // ---- R-5：真实 BinaryFileResponse + file()->withHeaders() ----
    $css = $repoRoot . '/src/html/css/xhprof.css';
    $fileAdapter = new ErikWang2013\Xhprof\Drupal\Adapter\ResponseAdapter();
    $expect('L2 R-5 file() 返回自身', $fileAdapter->file($css) === $fileAdapter, true);
    $fileSent = $fileAdapter->withHeaders(['Cache-Control' => 'public, max-age=86400'])->send();
    $expect('L2 R-5 真实类型是 BinaryFileResponse', $fileSent instanceof Symfony\Component\HttpFoundation\BinaryFileResponse, true);
    $expect('L2 R-5 file() 之后头落在新对象上（不是被丢弃的那个）', $fileSent->getFile()->getRealPath(), realpath($css));
    $expectContains(
        'L2 R-5 Cache-Control 落在真实响应上',
        (string) $fileSent->headers->get('Cache-Control'),
        'max-age=86400'
    );
    // 流式响应没有 content —— 控制器里 (string) 转换与 instanceof 判断的前提
    $expect('L2 R-5 真实 BinaryFileResponse::getContent() 恒为 false（流式发送）', $fileSent->getContent(), false);

    // Content-Type 必须由 Core 的 MIME 表钉住：交给真实 prepare() 会按**内容**嗅探，
    // 本包的 css/js 会被猜成 text/plain / text/x-Algol68，浏览器直接丢弃样式表与脚本。
    // 未钉时的猜测值只作为观测写进 detail，不冻结成期望（那是 symfony/mime 的行为，会随它升级变）。
    $expect('L2 R-5 css 钉成 Core MIME 表的 text/css（prepare 前）', $fileSent->headers->get('Content-Type'), 'text/css');
    // 真实链路上 HttpKernel::filterResponse() 会调 prepare()：它给 text/* 追加 charset，
    // 但**不会**改写类型本身 —— 所以钉住的值一路活到浏览器
    $fileSent->prepare(new Symfony\Component\HttpFoundation\Request());
    $expect(
        'L2 R-5 prepare() 只追加 charset，不改写类型（真实 filterResponse 链路）',
        $fileSent->headers->get('Content-Type'),
        'text/css; charset=utf-8'
    );
    $guess = (static function () use ($css): string {
        $probe = new Symfony\Component\HttpFoundation\BinaryFileResponse($css);
        $probe->prepare(new Symfony\Component\HttpFoundation\Request());
        return (string) $probe->headers->get('Content-Type');
    })();
    $expect(
        'L2 R-5 js 钉成 application/javascript（真实 prepare() 会猜成别的）',
        (new ErikWang2013\Xhprof\Drupal\Adapter\ResponseAdapter())
            ->file($repoRoot . '/src/html/js/xhprof_report.js')->send()->headers->get('Content-Type'),
        'application/javascript'
    );

    // 读不出文件：退化 404 而不是抛 FileNotFoundException（那会变成 500，且异常要穿过 HttpKernel）
    $missing = (new ErikWang2013\Xhprof\Drupal\Adapter\ResponseAdapter())
        ->file($repoRoot . '/src/html/css/__nope__.css')->send();
    $expect('L2 R-5 读不出文件退化 404（真实 FileNotFoundException 不穿出去）', $missing->getStatusCode(), 404);
    $expect(
        'L2 R-5 读不出文件时不返回 BinaryFileResponse',
        $missing instanceof Symfony\Component\HttpFoundation\BinaryFileResponse,
        false
    );

    // ================= L2：真实 HttpKernel 跑本包中间件 =================

    // 真实内核 + 一层录像装饰器：记录每次进入内层 kernel 的 $type —— 子请求守卫的观测点
    $recorder = new class implements Symfony\Component\HttpKernel\HttpKernelInterface {
        public array $types = [];
        public ?Symfony\Component\HttpKernel\HttpKernelInterface $inner = null;

        public function handle(
            Symfony\Component\HttpFoundation\Request $request,
            int $type = self::MAIN_REQUEST,
            bool $catch = true
        ): Symfony\Component\HttpFoundation\Response {
            $this->types[] = $type;
            return $this->inner->handle($request, $type, $catch);
        }
    };
    $recorder->inner = new Symfony\Component\HttpKernel\HttpKernel(
        new Symfony\Component\EventDispatcher\EventDispatcher(),
        new Symfony\Component\HttpKernel\Controller\ControllerResolver(),
        new Symfony\Component\HttpFoundation\RequestStack(),
        new Symfony\Component\HttpKernel\Controller\ArgumentResolver()
    );

    $factory = new ErikWang2013\Xhprof\Tests\Stubs\Framework\Drupal\FakeConfigFactory([
        'xhprof.settings' => ['enable' => true, 'auth_token' => null],
    ]);
    $loggerFactory = new ErikWang2013\Xhprof\Tests\Stubs\Framework\Drupal\FakeLoggerFactory();
    $cache = new ErikWang2013\Xhprof\Tests\Fixtures\FakeCache();
    $middleware = new ErikWang2013\Xhprof\Drupal\XhprofMiddleware($recorder, $factory, $loggerFactory, $cache);

    $observed = [];
    $main = $mk('/page');
    $main->attributes->set('_controller', static function (Symfony\Component\HttpFoundation\Request $request) use (&$observed, &$middleware): Symfony\Component\HttpFoundation\Response {
        xhprof_drupal_marker();
        // Drupal 的 ESI / fragment 形状：控制器里再走一次 http_kernel
        $sub = Symfony\Component\HttpFoundation\Request::create('/_fragment');
        $sub->attributes->set('_controller', static function (): Symfony\Component\HttpFoundation\Response {
            return new Symfony\Component\HttpFoundation\Response('fragment');
        });
        $middleware->handle($sub, Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST, false);
        $observed['uri_after_sub'] = ErikWang2013\Xhprof\Core\Xhprof::getRequest()?->uri();
        return new Symfony\Component\HttpFoundation\Response('business');
    });

    $mainResponse = $middleware->handle($main);

    $expect('L2 业务响应原样返回', $mainResponse->getContent(), 'business');
    $expect('L2 主请求与子请求都经过了内层 kernel', $recorder->types, [
        Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST,
        Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST,
    ]);
    $expect(
        'L2 子请求不得 bootstrap 掉宿主请求的适配器（Drupal 的 fragment 会原样回到中间件）',
        $observed['uri_after_sub'] ?? null,
        '/page'
    );
    $expect(
        'L2 bootstrap 用的是真实 Request（适配器类型）',
        ErikWang2013\Xhprof\Core\Xhprof::getRequest() instanceof ErikWang2013\Xhprof\Drupal\Adapter\RequestAdapter,
        true
    );

    // 异常：真实 HttpKernel 在无人处理时**重抛**（catch=true 也一样），中间件的 finally 仍须收尾
    $boom = $mk('/boom');
    $boom->attributes->set('_controller', static function (): Symfony\Component\HttpFoundation\Response {
        throw new \RuntimeException('controller boom');
    });
    $threw = null;
    try {
        $middleware->handle($boom);
    } catch (\Throwable $e) {
        $threw = get_class($e);
    }
    $expect('L2 控制器异常逃出中间件（不被吞掉）', $threw, \RuntimeException::class);

    // ================= L2：采样（需要 ext-xhprof） =================

    $runs = static function () use ($cache): array {
        return $cache->lRange('xhprof:run_id', 0, -1);
    };
    $hasMarker = static function (array $runIds) use ($cache): bool {
        if ($runIds === []) {
            return false;
        }
        $data = (array) unserialize((string) $cache->get('xhprof:xhprof_log:' . $runIds[0]));
        foreach (array_keys($data) as $key) {
            if (strpos((string) $key, 'xhprof_drupal_marker') !== false) {
                return true;
            }
        }
        return false;
    };

    // 每个场景一条：跑完一次带标记的请求周期，断言"恰好 1 条 + 数据里真有本次采样"
    $cycles = [
        '主请求' => static function () use ($middleware, $mk): void {
            $request = $mk('/index?x=1');
            $request->attributes->set('_controller', static function (): Symfony\Component\HttpFoundation\Response {
                xhprof_drupal_marker();
                return new Symfony\Component\HttpFoundation\Response('ok');
            });
            $middleware->handle($request);
        },
        '业务抛异常' => static function () use ($middleware, $mk): void {
            $request = $mk('/boom');
            $request->attributes->set('_controller', static function (): Symfony\Component\HttpFoundation\Response {
                xhprof_drupal_marker();
                throw new \RuntimeException('boom');
            });
            try {
                $middleware->handle($request);
            } catch (\Throwable $e) {
                // 期望逃出；finally 里已经 stop 过
            }
        },
    ];

    $extDeclared = 0;
    foreach ($cycles as $label => $cycle) {
        $extDeclared += 3;   // 条数 + 标记 + 采样状态已清理，共 3 条
        if (!$ext) {
            continue;
        }
        $cache->reset();
        $cycle();
        $got = $runs();
        $extExpect("L2 采样（{$label}）：恰好落库 1 条", count($got), 1);
        $extExpect("L2 采样（{$label}）：落库的是本次真实采样（含标记函数）", $hasMarker($got), true);
        $extExpect("L2 采样（{$label}）：stop 之后采样状态已清理", xhprof_disable(), null);
    }

    // 报告页/静态资源：命中默认 ignore_url_arr，采样会开但不落库
    $extDeclared += 1;
    if ($ext) {
        $cache->reset();
        $report = $mk('/xhprof');
        $report->attributes->set('_controller', static function (): Symfony\Component\HttpFoundation\Response {
            return new Symfony\Component\HttpFoundation\Response('report');
        });
        $middleware->handle($report);
        $extExpect('L2 报告页（/xhprof）不落库', count($runs()), 0);
    }

    // 报告页/静态资源：**只跳过采样、不短路响应**，即便 ignore_url_arr 被清空（「什么都
    // 不过滤」）。这是六家里 Drupal 独有的一条：其余五家在入口类里就把这两个路径短路掉了，
    // 请求根本进不到采样阶段；Drupal 走模块路由，必须靠中间件里的路径守卫。
    // 正对照（普通页面照样落库）在同一次运行里，防「什么都没跑」的假绿。
    $extDeclared += 3;
    if ($ext) {
        $bareCache = new ErikWang2013\Xhprof\Tests\Fixtures\FakeCache();
        // 内层 kernel：只数到达次数（守卫若短路响应，计数就停在 0/1，拿不到 2）
        $bareInner = new class implements Symfony\Component\HttpKernel\HttpKernelInterface {
            public int $reached = 0;

            public function handle(
                Symfony\Component\HttpFoundation\Request $request,
                int $type = self::MAIN_REQUEST,
                bool $catch = true
            ): Symfony\Component\HttpFoundation\Response {
                $this->reached++;
                return new Symfony\Component\HttpFoundation\Response('business');
            }
        };
        $bareMw = new ErikWang2013\Xhprof\Drupal\XhprofMiddleware(
            $bareInner,
            new ErikWang2013\Xhprof\Tests\Stubs\Framework\Drupal\FakeConfigFactory([
                'xhprof.settings' => ['enable' => true, 'ignore_url_arr' => []],
            ]),
            $loggerFactory,
            $bareCache
        );
        $bareMw->handle($mk('/xhprof'));
        $bareMw->handle($mk('/xhprof-assets/css/xhprof.css'));
        $extExpect(
            'L2 ignore_url_arr 清空时报告页/资源请求也不落库（路径守卫跳过采样）',
            count($bareCache->lRange('xhprof:run_id', 0, -1)),
            0
        );
        $extExpect(
            'L2 守卫不短路响应：两个请求都到达了内层 kernel（响应仍由路由/控制器产生）',
            $bareInner->reached,
            2
        );
        $bareMw->handle($mk('/node/1'));
        $extExpect(
            'L2 正对照：同一份配置下普通页面照样落库',
            count($bareCache->lRange('xhprof:run_id', 0, -1)),
            1
        );
    }

    if ($ext && $extChecks !== $extDeclared) {
        $failures[] = "扩展相关检查数漂移：实际跑了 {$extChecks} 条，声明 {$extDeclared} 条"
            . '（声明值决定扩展缺失时记多少 skip，漂了会让 CI 的 skip 数不准）';
        $checks++;
    }
    $checks += $extChecks;   // 采样类断言也是断言，计入总数
    foreach ($extFailures as $f) {
        $failures[] = $f;
    }

    // ================= 报告页的 Cache-Control：真包上「断字面量」没有判别力 =================
    //
    // 真实 ResponseHeaderBag 对**从未设过** Cache-Control 的响应也会算出一个值，而它恰好就是
    // 'no-cache, private'（ResponseHeaderBag.php:35-37 → :121-125 → :243-252）。所以
    // `assertSame('no-cache, private', $r->headers->get('Cache-Control'))` 在真包上恒真：
    // 控制器漏设一样通过。判别式是**扰动 Last-Modified** —— 计算值会翻成 'private, must-revalidate'，
    // 显式设过的值纹丝不动（显式那支 $this->cacheControl 非空，computeCacheControlValue() 原样返回）。
    // 先钉判别式本身：这两条前置条件红了 = HttpFoundation 改了规则，下面的结论必须重估。
    $perturb = static function (Symfony\Component\HttpFoundation\Response $response): string {
        $response->headers->set('Last-Modified', 'Thu, 01 Jan 1970 00:00:00 GMT');

        return (string) $response->headers->get('Cache-Control');
    };
    $expect(
        '判别式前置：未设过 Cache-Control 的响应被扰动后算出 private, must-revalidate',
        $perturb(new Symfony\Component\HttpFoundation\Response()),
        'private, must-revalidate'
    );
    $expect(
        '判别式前置：显式设过 no-cache, private 的响应不被同一扰动改变',
        $perturb(new Symfony\Component\HttpFoundation\Response('', 200, ['Cache-Control' => 'no-cache, private'])),
        'no-cache, private'
    );

    // 生产链路：中间件 bootstrap → 模块控制器（报告页由路由/控制器提供，不能用内核桩伪造响应）
    $reportInner = new class implements Symfony\Component\HttpKernel\HttpKernelInterface {
        public function handle(
            Symfony\Component\HttpFoundation\Request $request,
            int $type = self::MAIN_REQUEST,
            bool $catch = true
        ): Symfony\Component\HttpFoundation\Response {
            return (new ErikWang2013\Xhprof\Drupal\Controller\XhprofController())->report();
        }
    };
    $reportMw = new ErikWang2013\Xhprof\Drupal\XhprofMiddleware($reportInner, $factory, $loggerFactory, $cache);
    $reportResponse = $reportMw->handle($mk('/xhprof'));
    $expect(
        'L2 报告页显式设了 no-cache（同一扰动下不变 → 与计算默认值可区分）',
        $perturb($reportResponse),
        'no-cache, private'
    );
    $expect(
        'L2 报告页显式设了 Content-Type（不是 prepare() 的推断值）',
        $reportResponse->headers->get('Content-Type'),
        'text/html; charset=UTF-8'
    );

    // ================= 装配文件的静态核对（跑不了容器，但文件说了什么可以钉死） =================
    //
    // 这几条盯的是本卡唯一一个**站点级**事故面：`arguments` 里写内侧 kernel 的别名。
    // ≤11.2（含整个 10.x）没有 `http_middleware_inner` 这个别名 → 编译期
    // ServiceNotFoundException → 容器构建失败、整站白屏；≥11.3 由 StackedKernelPass
    // 自己 setAlias，写了就是重复注入 → 每个请求 TypeError。
    // 两个版本里 pass 都会把内侧 kernel 前插为构造参数 0，所以 yml 只能写自己的额外参数。
    // 真实装配要编译容器（记 SKIP），但「文件里有没有写错」在这里就能钉死。

    $servicesYml = (string) file_get_contents($repoRoot . '/drupal/xhprof/xhprof.services.yml');
    $routingYml = (string) file_get_contents($repoRoot . '/drupal/xhprof/xhprof.routing.yml');
    $infoYml = (string) file_get_contents($repoRoot . '/drupal/xhprof/xhprof.info.yml');

    // 只看非注释行：文件里有大段注释**解释**为什么不能写那个别名，注释不算装配
    $codeOnly = static function (string $yaml): string {
        $kept = [];
        foreach (explode("\n", $yaml) as $line) {
            if (strpos(ltrim($line), '#') === 0) {
                continue;
            }
            $kept[] = $line;
        }
        return implode("\n", $kept);
    };
    $servicesCode = $codeOnly($servicesYml);

    $expect(
        '装配：services.yml 不得出现 http_middleware_inner 别名（≤11.2 无此别名→整站白屏；≥11.3 重复注入→TypeError）',
        strpos($servicesCode, 'http_middleware_inner') === false,
        true
    );
    $expect(
        '装配：services.yml 不得把 http_kernel 写进 arguments（内侧 kernel 由 pass 前插）',
        preg_match('/arguments:.*http_kernel/', $servicesCode) === 1,
        false
    );
    $expectContains('装配：http_middleware 标签存在', $servicesYml, 'name: http_middleware');
    $expectContains('装配：priority 是 1000（core 现有最高 D10=400 / D11=500，故确实在页面缓存外）', $servicesYml, 'priority: 1000');
    $expectContains('装配：只声明自己的额外参数', $servicesYml, "arguments: ['@config.factory', '@logger.factory']");
    $expectContains('装配：中间件类指向本包', $servicesYml, 'ErikWang2013\Xhprof\Drupal\XhprofMiddleware');
    $expectContains('路由：报告页路径', $routingYml, "path: '/xhprof'");
    $expectContains('路由：静态资源路径前缀与 assets_url 一致', $routingYml, "path: '/xhprof-assets/{file}'");
    $expect(
        '路由：两条路由都声明了 _permission（Drupal 里没有访问要求的理由由是拒绝访问，最坏是配错成公开）',
        substr_count($routingYml, '_permission:'),
        2
    );
    $expectContains('info.yml：核心版本要求覆盖 10 与 11', $infoYml, 'core_version_requirement: ^10 || ^11');

    // ================= SKIP：本环结构上验不了的三项 =================

    $skips += 3;
    $skipNote = 'skips=3：①ConfigAdapter 的真实 config.factory/ImmutableConfig 语义（环里没有 drupal/core，'
        . '且真实语义要引导过的内核 + 配置存储）②服务串接（http_middleware 标签 / priority 1000 是否真最外 / '
        . 'StackedKernelPass 注入内层 kernel——要编译容器）③Drupal\\Core\\* 接口与真实包的声明一致性'
        . '（用本仓库桩代跑，桩的忠实性不计入本环；已对 drupal/core 10.0.7 与 11.4.7 源码人工核对）';

    $note = 'ext-xhprof ' . ($ext ? '已加载（采样类断言全部真跑）' : '**未加载**（采样类断言计入 skips，非静默通过）')
        . '；Drupal 侧接口用本仓库桩（真实 drupal/core 不在环的依赖里）'
        . '；Content-Type 观测：不钉时真实 prepare() 把 xhprof.css 猜成 ' . $guess
        . '（已钉成 Core MIME 表的 text/css）'
        . '；报告页的 no-cache 在环里用**扰动式判别**（真实 ResponseHeaderBag 对没设过的响应算出同一字面量，'
        . '故先钉判别式：扰动 Last-Modified → 计算值翻成 private, must-revalidate、显式值不动；再对控制器产出的'
        . '响应施加同一扰动，值不变才算通过）；单测侧另有前提守卫盯着桩别补上那段计算逻辑'
        . '；' . $skipNote;

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
            . ($ext ? '' : '；' . $extDeclared . ' 项采样断言因缺 ext-xhprof 未验'),
        'skips' => $skips + ($ext ? 0 : $extDeclared),
    ];
};
