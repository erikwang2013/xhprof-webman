<?php

declare(strict_types=1);

/**
 * Laravel 契约卡 —— 用**真实** laravel/framework 验证 src/Laravel/**。
 *
 * 为什么装整包 laravel/framework 而不是只装 illuminate/http + illuminate/support：
 * 本包的三个适配器直接调用**全局助手函数** `response()` / `config()` / `app()`，
 * 它们定义在 `Illuminate\Foundation\helpers.php` 里，而这个文件只存在于
 * laravel/framework —— illuminate/* 子包里没有。少装它就只剩两条路：要么写一份
 * `function response()` 的假助手（那就是"用自造桩冒充真实框架"，本环存在的意义
 * 正是反对这件事），要么跳过 `ResponseAdapter::file()` 与 `ConfigAdapter` 两个
 * 适配器。装整包换来的是：`response()->file()`、`config('xhprof.x')`、`Log` 门面
 * 全部走真实现。代价是 53 个新包（含 illuminate/* 与 symfony/console 等），
 * 已在 composer.json 里如实写明。
 *
 * 版本选择：README.md:29 声称支持 `laravel/framework ^9.0|^10.0|^11.0`，本卡钉住其中
 * **最新的一档 ^11.0**（v11 要求 php ^8.2，与本环 composer.json 的 `php: >=8.2`
 * 一致）。已知副作用：v11 传递依赖 symfony/http-foundation ^7.0，因此**无法**再在
 * 同一个 composer.json 里拼出 symfony 6.4 的矩阵腿（composer 会直接拒绝求解）。
 *
 * 覆盖到哪一层（诚实边界）：
 *   - 覆盖：十个 L1 方法签名 + L2 真语义（Request/Response/Config/Log 四个适配器
 *     逐个跑真对象），以及入口类 `Laravel\Middleware::handle()` 的一次真实调用。
 *   - **不**覆盖：HTTP 内核与路由分发。本卡不 boot 一个完整应用（那需要
 *     bootstrap/app.php + 服务提供者 + 目录骨架），入口类是按 README 的用法
 *     **直接**调用的（`handle()` 的签名就是 `handle(Request $request, Closure $next)`，
 *     直接调用与 Laravel 内核调用它时走的是同一个方法）。所以"Laravel 内核会不会
 *     把中间件挂对"这件事本卡不证明，它证明的是"挂对之后不会炸"。
 *   - **不**覆盖：报告页渲染（要真 Redis 读缓存）。本卡只走到 `Xhprof::index()` 的
 *     鉴权/白名单两条 deny 分支——它们在任何缓存访问**之前**返回，所以不需要 Redis。
 *     真正的 Redis 端到端在 `cases/Redis.php`（Slim + 真 phpredis）。
 */

use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface as XhprofResponseInterface;
use ErikWang2013\Xhprof\Core\StaticController;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Laravel\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Laravel\Middleware;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\FileViewFinder;
use Illuminate\View\Factory as ViewFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Mime\MimeTypes;

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

    // ================= 装配：**全部是真实 Laravel 类**，一个手写的假对象都没有 =================
    //
    // `response()` = `app(ResponseFactory::class)`，`config()` = `app('config')->get()`，
    // 所以必须先立一个真容器并绑上这两样。ResponseFactory 的构造函数是两个**必填**的
    // 真依赖（ViewFactory + Redirector），于是要把它们各自的真依赖也造出来：
    // View\Factory 要 EngineResolver + ViewFinder + Dispatcher；Redirector 要
    // UrlGenerator，UrlGenerator 要 RouteCollection + Request。全部来自
    // laravel/framework 自带的那几个子包，没有一个是本卡自己写的桩。
    // `file()`/`config()` 都不会碰到 view/redirector，但"造真的"比"造能骗过类型检查的"便宜：
    // 一共 6 行。
    $container = new Container();
    Container::setInstance($container);
    // `Container::setInstance()` **不**顺带设置门面根（实测：它的函数体就是
    // `return static::$instance = $container;`，见真包 Container.php:1556）。
    // 少了这一行，`Log::error()` 会抛 RuntimeException: A facade root has not been set.
    // 真实 Laravel 里这一步由 `Illuminate\Foundation\Application` 在构造时做；
    // 本卡不 boot 完整应用（见文件头"不覆盖"），所以自己补这一句公开 API。
    \Illuminate\Support\Facades\Facade::setFacadeApplication($container);

    $configItems = [
        'xhprof' => [
            'enable' => false,                        // 关采样：本卡不写 Redis（见文件头"不覆盖"）
            'ignore_url_arr' => ['/xhprof', '/admin'],
            'assets_url' => '/xhprof-assets',
            'auth_token' => 'contract-secret',
            'key_prefix' => 'xhprof_contract',
            'log_num' => 1234,
            'log_ttl' => 3600,
            'view_wtred' => 3,
            'time_limit' => 0,
        ],
    ];
    $container->instance('config', new \Illuminate\Config\Repository($configItems));
    // Log 门面：`LogAdapter` 调 `Log::error()`，门面根需要容器里有 'log'。
    // 用真 Monolog 的 NullHandler（laravel/framework 自带 monolog ^3）。
    $container->instance('log', new \Illuminate\Log\Logger(new \Monolog\Logger('xhprof-contract', [new \Monolog\Handler\NullHandler()])));

    $viewFactory = new ViewFactory(
        new EngineResolver(),
        new FileViewFinder(new Filesystem(), [sys_get_temp_dir()]),
        new Dispatcher($container)
    );
    $urlGenerator = new UrlGenerator(new RouteCollection(), Request::create('/'));
    $container->instance(ViewFactoryContract::class, $viewFactory);
    $container->instance(
        ResponseFactoryContract::class,
        new ResponseFactory($viewFactory, new Redirector($urlGenerator))
    );

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
    // 断言"这一段不该抛"：逐条捕获，异常变成一条失败信息（而不是让整个 case 崩在
    // 第一条上 —— 崩了就只能看见第一个问题，修完再崩下一个）。返回调用的结果，
    // 抛了则返回 null，调用方按 null 处理。
    $expectNoThrow = static function (string $label, callable $fn) use (&$checks, &$failures): mixed {
        $checks++;
        try {
            return $fn();
        } catch (\Throwable $e) {
            $failures[] = $label . '：抛了 ' . get_class($e) . ': ' . $e->getMessage();
            return null;
        }
    };

    // ================= L1：签名存在 =================
    //
    // 列的都是 **src/Laravel/** 真正调用到的东西。缺失即 "Call to undefined method"，
    // 是最怕的失效模式，所以先把它钉死再谈语义。
    $l1 = [
        Request::class => ['get', 'all', 'method', 'header', 'getHost', 'getHttpHost', 'getRequestUri', 'url', 'ip', 'setTrustedProxies'],
        Response::class => ['setContent', 'withHeaders', 'setStatusCode', 'getContent', 'getStatusCode'],
        ResponseFactory::class => ['file', 'make'],
        // `boot()` **不在** ServiceProvider 上（实测 hasMethod('boot') === false）——
        // 框架是 `method_exists($provider, 'boot')` 才调它，所以"有没有这个方法"由
        // 我们的 XhprofServiceProvider 决定，不是父类保证的。下面单列一条钉住它。
        \Illuminate\Support\ServiceProvider::class => ['register', 'mergeConfigFrom', 'publishes'],
    ];
    foreach ($l1 as $class => $methods) {
        $expect("L1 {$class} 存在", class_exists($class), true);
        if (!class_exists($class)) {
            continue;
        }
        $rc = new \ReflectionClass($class);
        foreach ($methods as $method) {
            $expect("L1 {$class}::{$method}() 存在", $rc->hasMethod($method), true);
        }
    }

    // 两个门面（Facade）：RedisAdapter/LogAdapter 只用到类名 + 静态调用，
    // 方法本身由 __callStatic 兜（reflection 查不到 get/setex），所以这里只断言类存在
    // 与"最终落到谁身上"——见下面 \Redis 的那一段。
    foreach ([\Illuminate\Support\Facades\Redis::class, \Illuminate\Support\Facades\Log::class] as $facade) {
        $expect("L1 门面 {$facade} 存在", class_exists($facade), true);
    }

    // 适配器必须是 Core 契约的真实现（而不是"长得像"）
    $expect(
        'L1 RequestAdapter 是 Core RequestInterface 的实现',
        is_subclass_of(RequestAdapter::class, RequestInterface::class),
        true
    );
    $expect(
        'L1 ResponseAdapter 是 Core ResponseInterface 的实现',
        is_subclass_of(ResponseAdapter::class, XhprofResponseInterface::class),
        true
    );
    $expect('L1 ConfigAdapter 是 Core ConfigInterface 的实现', is_subclass_of(ConfigAdapter::class, ConfigInterface::class), true);
    $expect('L1 LogAdapter 是 Core LoggerInterface 的实现', is_subclass_of(LogAdapter::class, LoggerInterface::class), true);

    // 服务提供者：本包 `src/Laravel/XhprofServiceProvider.php` 声明了 register()/boot()，
    // 而 Laravel 的自动发现按 `method_exists($provider, 'boot')` 决定要不要调它 ——
    // 所以"两个方法都在我们的类上"是这个提供者能被真正 boot 的充分必要条件。
    $provider = new \ReflectionClass(\ErikWang2013\Xhprof\Laravel\XhprofServiceProvider::class);
    $expect('L1 XhprofServiceProvider 声明了 register()', $provider->hasMethod('register'), true);
    $expect('L1 XhprofServiceProvider 声明了 boot()', $provider->hasMethod('boot'), true);
    $expect('L1 XhprofServiceProvider 是 ServiceProvider 的子类', $provider->isSubclassOf(\Illuminate\Support\ServiceProvider::class), true);

    // 入口类：Laravel 内核按 handle($request, $next) 调它，`$next` 是 Closure 而不是
    // PSR-15 的 RequestHandlerInterface —— 这是 Laravel 中间件的形状，必须与真包对上。
    $handle = new \ReflectionMethod(Middleware::class, 'handle');
    $expect('L1 Middleware::handle() 有 2 个参数（$request, $next）', $handle->getNumberOfParameters(), 2);
    $expect(
        'L1 Middleware::handle() 第一个参数声明为 Illuminate\Http\Request',
        (string) $handle->getParameters()[0]->getType(),
        Request::class
    );
    $expect(
        'L1 Middleware::handle() 第二个参数声明为 Closure',
        (string) $handle->getParameters()[1]->getType(),
        'Closure'
    );

    // ================= L2.1：请求适配器（真 Illuminate\Http\Request） =================
    //
    // 一次性构造出"四种来源都不同"的请求：query 与 body 同名不同值、Host 带端口、
    // XFF 与 REMOTE_ADDR 都给了。后面所有断言都从这一个对象出发，避免"每个断言
    // 一个请求"导致读的人分不清是在说哪一条。
    $realRequest = Request::create(
        'http://xhprof.example.com:8080/list?page=2&run=legal',
        'POST',
        ['run' => 'body-value', 'only_in_body' => 'B'],
        [],
        [],
        ['HTTP_X_FORWARDED_FOR' => '1.2.3.4, 5.6.7.8', 'REMOTE_ADDR' => '10.0.0.9']
    );
    $req = new RequestAdapter($realRequest);

    // R-2：host() **不含端口**。真包的 getHost() 已经剥掉端口（Symfony 语义），
    // 而 getHttpHost() 是带端口的那个 —— 把两者都钉住，将来谁改成 getHttpHost()
    // 会立刻红，而不是等用户发现列表页链接变成 host:8080。
    $expect('L2 R-2：真包 getHost() 不含端口', $realRequest->getHost(), 'xhprof.example.com');
    $expect('L2 R-2：真包 getHttpHost() 带端口（即我们**不**该用的那个）', $realRequest->getHttpHost(), 'xhprof.example.com:8080');
    $expect('L2 R-2：适配器 host() 不含端口', $req->host(), 'xhprof.example.com');

    // R-1：uri() 只有 path+query，绝不含 scheme/host
    $expect('L2 R-1：适配器 uri() 只含 path+query', $req->uri(), '/list?page=2&run=legal');
    $expect('L2 R-1：uri() 不含 scheme', strpos($req->uri(), '://') === false, true);

    // url() 与 uri() 的关系被实测钉住：**url() 不含 query**（Symfony 的 Request::url()
    // 是 preg_replace('/\?.*/') 之后的结果），但**含 scheme 与端口**。
    // 这两个方法在报告页里是两种用途（url() 做展示、uri() 做路径匹配），所以
    // "url() 会带 query 吗"这种含糊说法必须落成可执行的断言。
    $expect('L2 url() 是绝对 URL 且保留端口', $req->url(), 'http://xhprof.example.com:8080/list');
    $expect('L2 url() **不含** query（与 uri() 的差别就在这里）', strpos($req->url(), '?') === false, true);

    $expect('L2 method()', $req->method(), 'POST');
    $expect('L2 header() 缺省返回 null（不是空串）', $req->header('X-Absent'), null);
    $expect('L2 header() 命中时原样返回', $req->header('X-Forwarded-For'), '1.2.3.4, 5.6.7.8');

    // 客户端 IP：Laravel 的 ip() = Symfony 的 getClientIp()，**默认不信 XFF**。
    // 这与 Slim/Yii3 的适配器（无条件取 XFF 第一个）是不同的语义，本仓在 Laravel
    // 上把安全边界交给了框架（应用配了 TrustProxies 才会信）。两条都钉住：
    // 默认取 REMOTE_ADDR，且把 REMOTE_ADDR 列进信任代理后才取 XFF 第一个。
    $expect('L2 ip() 默认取 REMOTE_ADDR，不轻信 XFF', $req->getRealIp(), '10.0.0.9');
    // 信任哪一段决定拿到哪一个：Symfony 从 XFF **最右**往左找第一个不在信任表里的地址。
    // 实测（不是读源码推断）：XFF = '1.2.3.4, 5.6.7.8'，REMOTE_ADDR = 10.0.0.9 时
    //   信任 [10.0.0.9]        → '5.6.7.8'（只信直接代理，链路上游仍可疑）
    //   信任 [10.0.0.9, 5.6.7.8] → '1.2.3.4'（把整条链路都列进信任表才拿到最左的客户端）
    // XFF 只有一跳时两种配置都给 '1.2.3.4'。三条都钉住：这是"配了 TrustProxies 才会变"
    // 的**可执行**说明，也挡住了"以为 Laravel 会无条件取 XFF 第一个"的误读。
    $makeXff = static function (array $xff): Request {
        return Request::create(
            'http://xhprof.example.com/list',
            'GET',
            [],
            [],
            [],
            ['HTTP_X_FORWARDED_FOR' => implode(', ', $xff), 'REMOTE_ADDR' => '10.0.0.9']
        );
    };
    $trustImmediate = $makeXff(['1.2.3.4', '5.6.7.8']);
    $trustImmediate->setTrustedProxies(['10.0.0.9'], Request::HEADER_X_FORWARDED_FOR);
    $expect('L2 只信任直接代理时 ip() 给 XFF 最右（5.6.7.8）', (new RequestAdapter($trustImmediate))->getRealIp(), '5.6.7.8');
    $trustChain = $makeXff(['1.2.3.4', '5.6.7.8']);
    $trustChain->setTrustedProxies(['10.0.0.9', '5.6.7.8'], Request::HEADER_X_FORWARDED_FOR);
    $expect('L2 信任整条链路时 ip() 给最左的客户端（1.2.3.4）', (new RequestAdapter($trustChain))->getRealIp(), '1.2.3.4');
    $trustOneHop = $makeXff(['1.2.3.4']);
    $trustOneHop->setTrustedProxies(['10.0.0.9'], Request::HEADER_X_FORWARDED_FOR);
    $expect('L2 只有一跳 XFF 时 ip() 给那一跳', (new RequestAdapter($trustOneHop))->getRealIp(), '1.2.3.4');

    // ================= L2.2：get() 与 all() 同源（本卡最重要的一条） =================
    //
    // 真包内部**自相矛盾**（实测，不是读源码推断）：同一个 key `run`
    //   - `get('run')`  = 'legal'      （Symfony 的 ParameterBag 顺序：attributes→query→request，query 胜）
    //   - `all()['run']` = 'body-value'（Laravel 的 `input()` = `getInputSource()->all() + $this->query->all()`，`+` 保留左侧=body 胜）
    // Xhprof::index() 两条路都用：白名单校验走 get()、渲染走 all()。照原样透传就是
    // "校验合法值、渲染非法值"。适配器的 all() 逐键回读 get() 把它收敛到同一条规则。
    $expect('L2 真包 all() 是 body 胜出（与 get() 相反）', $realRequest->all()['run'], 'body-value');
    $expect('L2 真包 get() 是 query 胜出', $realRequest->get('run'), 'legal');
    $expect('L2 适配器 all() 与 get() 对同一个 key 给同一个答案', $req->all()['run'], 'legal');
    $expect('L2 适配器 get() 与 all() 同源（逐键相等）', $req->get('run'), $req->all()['run']);
    // 只出现在 body 里的键不能被丢掉（all() 是渲染用的全集）
    $expect('L2 适配器 all() 保留只在 body 里的键', $req->all()['only_in_body'], 'B');
    $expect('L2 适配器 get() 取只出现在 body 里的键', $req->get('only_in_body'), 'B');
    $expect('L2 适配器 get() 缺省值', $req->get('nope', 'D'), 'D');

    // ================= L2.3：响应适配器（真 Illuminate\Http\Response） =================
    //
    // R-5：三个就地改方法必须返回**同一个对象**（本仓适配器依赖这一点：
    // 它把返回值重新赋给 $this->response，若框架返回新对象而适配器忘了接，
    // 头就会静默丢掉）。Laravel 这三个方法都来自 Symfony/Illuminate 的
    // `return $this` 约定，逐条钉住。
    $plain = response('');
    $expect('L2 response(\'\') 得到 Illuminate\Http\Response', get_class($plain), Response::class);
    $expect('L2 ResponseAdapter 无参构造退化为 response(\'\')', get_class((new ResponseAdapter())->send()), Response::class);

    $byBody = $plain->setContent('hello');
    $expect('L2 setContent() 就地改（返回 $this）', $byBody === $plain, true);
    $byHeaders = $plain->withHeaders(['X-A' => '1']);
    $expect('L2 withHeaders() 就地改（返回 $this）', $byHeaders === $plain, true);
    $byStatus = $plain->setStatusCode(201);
    $expect('L2 setStatusCode() 就地改（返回 $this）', $byStatus === $plain, true);

    // R-5 的**顺序**回归：先挂头再改状态码，头必须还在。
    // （若哪天有人把 withStatus() 写成 `response($body, $status)` 重建响应，这条会红。）
    $sent = (new ResponseAdapter())
        ->withHeaders(['Cache-Control' => 'public, max-age=86400', 'X-Trace' => 'keep-me'])
        ->withStatus(201)
        ->withBody('body-after-headers')
        ->send();
    // 注意读出来的**不是**写进去的那个字符串：Symfony 的 ResponseHeaderBag 会把
    // Cache-Control 拆成指令再重排，`public, max-age=86400` 回读为 `max-age=86400, public`
    // （max-age 在前、public 在后；没写 public 时还会自动补一个 private）。
    // 这是真包行为，不是本仓的差异，所以断言写**归一化后**的值；同时下面单列两条
    // 钉住归一化规则本身 —— 否则下一个人会以为"头丢了"。
    $expect('L2 R-5：先设头再设状态，头不丢（回读为 Symfony 归一化形式）', $sent->headers->get('Cache-Control'), 'max-age=86400, public');
    $expect('L2 R-5：先设头再设 body，头不丢', $sent->headers->get('X-Trace'), 'keep-me');
    $expect('L2 R-5：body 落上去', $sent->getContent(), 'body-after-headers');
    $expect('L2 R-5：状态码落上去', $sent->getStatusCode(), 201);
    $expect('L2 Symfony 会把 Cache-Control 的指令重排（max-age 在前）', response('', 200, ['Cache-Control' => 'public, max-age=60'])->headers->get('Cache-Control'), 'max-age=60, public');
    $expect('L2 Symfony 对没写 public 的 Cache-Control 自动补 private', response('', 200, ['Cache-Control' => 'max-age=60'])->headers->get('Cache-Control'), 'max-age=60, private');
    // Illuminate\Http\Response 自带四个默认头（Date / Cache-Control: no-cache, private …）；
    // 记住它们不是"我们设的"，免得以后把默认值当成自己的输出。
    $expectContains('L2 空响应的 Cache-Control 默认值来自 Illuminate 而不是我们', (string) response('')->headers->get('Cache-Control'), 'no-cache');

    // ================= L2.4：两个全局助手（真 response()/config()/app()） =================
    //
    // 这条同时钉住 Core\Xhprof::autoDetect() 注释里那个坑：`response()` **无参**调用
    // 返回的是工厂而不是响应对象（`func_num_args() === 0` 分支），所以必须写
    // `response('')`。写错了不会在构造期炸，而是等到 Xhprof::deny() 调 withStatus()
    // 时命中 Macroable::__call 抛 BadMethodCallException —— 403/400 变 500。
    $expect('L2 response() 无参返回的是工厂，不是响应', get_class(response()), ResponseFactory::class);
    $expect('L2 response(\'\') 才是响应对象', is_subclass_of(response(''), \Symfony\Component\HttpFoundation\Response::class), true);

    $cfg = new ConfigAdapter();
    $expect('L2 R-6：get(\'xhprof\') 返回整块', is_array($cfg->get('xhprof')), true);
    $expect('L2 R-6：get(\'xhprof.assets_url\') 取到叶子', $cfg->get('xhprof.assets_url'), '/xhprof-assets');
    $expect('L2 R-6：get(\'xhprof.log_num\') 取到叶子', $cfg->get('xhprof.log_num'), 1234);
    $expect('L2 配置缺失时返回调用方给的默认值', $cfg->get('xhprof.nope', 'D'), 'D');
    $expect('L2 配置整块缺失时也返回默认值', $cfg->get('nope.deeper', 'D'), 'D');
    $expect('L2 R-7：用户列表整体替换，不与默认值逐下标合并', $cfg->get('xhprof.ignore_url_arr'), ['/xhprof', '/admin']);

    // LogAdapter 走真门面 + 真 Monolog（NullHandler，副作用为零）。这里证明的是
    // "方法能调到真实现上"，而不是"日志写出来了"（那要看 handler，不在本卡范围）。
    $expectNoThrow('L2 LogAdapter::error() 能调到真 Log 门面', static function () {
        (new LogAdapter())->error('contract probe', ['k' => 'v']);
        return true;
    });

    // ================= L2.5：file() 那条 Content-Type 路径 =================
    //
    // 这条是本卡的核心：`StaticController::serve()` 是
    //     `$response->file($realFile)->withHeaders(['Cache-Control' =>..., 'Content-Type' =>...])`
    // 而 Laravel 适配器的 `file()` = `response()->file($path)` = 一个**纯 Symfony** 的
    // BinaryFileResponse（`Illuminate\Routing\ResponseFactory::file()` 就是
    // `new BinaryFileResponse($file, 200, $headers)`，见真包源码
    // vendor/laravel/framework/src/Illuminate/Routing/ResponseFactory.php:262-264）。
    //
    // 两个必须先钉住的真包事实：
    //   ① 这个类**没有** withHeaders()（它是 Illuminate 的 ResponseTrait 给的，
    //      只挂在 Illuminate\Http\Response 上）。实测 `method_exists(..., 'withHeaders') === false`。
    //   ② 它的 prepare() 在**缺** Content-Type 时用 finfo 按**内容**嗅探，不看扩展名。
    //
    // ②用一个环境无关的构造来证明：把真 PNG 的字节写进一个叫 `probe.css` 的文件。
    // 扩展名表说 text/css，finfo 说 image/png，prepare() 必须给出后者 ——
    // 只要 libmagic 认识 PNG（任何发行版都认识），这条在任何机器上都是同一个结论。
    $expect(
        'L2 file() 的实测返回类型是 **Symfony** 的 BinaryFileResponse（不是 Illuminate 的）',
        get_class(response()->file($repoRoot . '/src/html/css/xhprof.css')),
        BinaryFileResponse::class
    );
    $expect(
        'L2 这个类没有 withHeaders()（静态资源路由必须先知道这条）',
        method_exists(BinaryFileResponse::class, 'withHeaders'),
        false
    );
    $expect('L2 它的 setContent() 会抛 LogicException（file 之后不能再 withBody）', (static function () use ($repoRoot): string {
        try {
            (new BinaryFileResponse($repoRoot . '/src/html/css/xhprof.css'))->setContent('x');
        } catch (\LogicException $e) {
            return 'LogicException';
        } catch (\Throwable $e) {
            return get_class($e);
        }
        return 'no-throw';
    })(), 'LogicException');

    // 探针文件：字节是**真 PNG**（1×1，base64 内联，不依赖包内任何文件），名字故意叫 .css。
    $probeFile = sys_get_temp_dir() . '/xhprof-contract-' . getmypid() . '-probe.css';
    file_put_contents($probeFile, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
    try {
        // 两条对照：扩展名 → text/css（Symfony 的静态表按**扩展名**给），
        // 文件内容 → image/png（finfo 按**魔数**给）。两者不同才说明这个探针有区分力。
        $expect('L2 扩展名表把 .css 说成 text/css', MimeTypes::getDefault()->getMimeTypes('css')[0] ?? null, 'text/css');
        $expect('L2 本仓扩展名表也把它说成 text/css', StaticController::contentType($probeFile), 'text/css');
        $expect('L2 finfo 按**内容**把它认成 image/png', (new File($probeFile))->getMimeType(), 'image/png');

        $unpinned = new BinaryFileResponse($probeFile);
        $unpinned->prepare(Request::create('/'));
        $expect('L2 缺头时 prepare() 走内容嗅探（不是扩展名）', $unpinned->headers->get('Content-Type'), 'image/png');

        // 钉住头之后，prepare() 不再改它 —— 只是给 text/* 补 charset（Symfony 的
        // Response::prepare()：`stripos(..., 'text/') === 0 && 没有 charset` 才补）。
        $pinned = new BinaryFileResponse($probeFile, 200, ['Content-Type' => 'text/css']);
        $pinned->prepare(Request::create('/'));
        $expect('L2 钉住头之后 prepare() 保留 text/css 并只补 charset', $pinned->headers->get('Content-Type'), 'text/css; charset=utf-8');

        $binaryPinned = new BinaryFileResponse($probeFile, 200, ['Content-Type' => 'image/png']);
        $binaryPinned->prepare(Request::create('/'));
        $expect('L2 非 text/* 的钉头连 charset 都不补', $binaryPinned->headers->get('Content-Type'), 'image/png');

        // BinaryFileResponse 的正文**不在对象里**：getContent() 返回 false（声明是
        // `string|false`），正文由 sendContent() 从文件流出去。所以"断言正文等于文件内容"
        // 这种写法在这里必然假红 —— 要看正文得看 getFile()。
        $expect('L2 BinaryFileResponse::getContent() 返回 false（正文在文件里，不在对象里）', $unpinned->getContent(), false);
        $expect('L2 BinaryFileResponse::getFile()->getPathname() 就是那个文件', $unpinned->getFile()->getPathname(), $probeFile);
    } finally {
        if (is_file($probeFile)) {
            unlink($probeFile);
        }
    }

    // ---- 端到端：README.md:148 那条 Laravel 静态资源路由 ——
    // 用真请求跑我们的适配器 + Core\StaticController::serve()。
    $assetRequest = new RequestAdapter(Request::create('http://xhprof.example.com/xhprof-assets/css/xhprof.css'));
    $assetResponse = $expectNoThrow(
        'L2 README 的静态资源路由（ResponseAdapter + StaticController::serve()）不该抛',
        static fn () => StaticController::serve($assetRequest, new ResponseAdapter(response('')))->send()
    );
    if ($assetResponse !== null) {
        $expect('L2 静态资源：Content-Type 被显式钉成 text/css（不是 finfo 猜的 text/plain）', $assetResponse->headers->get('Content-Type'), 'text/css');
        $expect('L2 静态资源：Cache-Control 挂上去了（Symfony 归一化形式）', $assetResponse->headers->get('Cache-Control'), 'max-age=86400, public');
        $expect('L2 静态资源：响应指向的就是那个 css 文件', $assetResponse->getFile()->getPathname(), $repoRoot . '/src/html/css/xhprof.css');
        $expect('L2 静态资源：文件内容真的是 xhprof 的样式表', strpos((string) file_get_contents($assetResponse->getFile()->getPathname()), '.xhprof') !== false, true);
    } else {
        // 上面已经记了一条失败；这里不再重复记，直接标出"因此这几条断言没被验证"。
        $checks += 4;
        $failures[] = 'L2 静态资源的 Content-Type / Cache-Control / 文件指向 三条断言未能执行（上一条已抛）';
    }

    // ================= L2.6：入口类（真 handle() 调用） =================
    //
    // 注意：Laravel 的入口类叫 `Laravel\Middleware`（这个命名空间下**没有**
    // XhprofMiddleware），且它**不短路**报告页/静态资源 —— 与 Slim/Symfony/Yii3
    // 三家的入口类不同，README 给 Laravel 的用法是"中间件只负责采样，
    // 报告页由用户自己的控制器调 Xhprof::index()"。所以这里按 README 的控制器
    // 形态调用：`handle()` 内部 bootstrap（适配器全由它装配）→ 闭包里跑 index()。
    $middleware = new Middleware();

    // ① 透传：非报告路径时 `$next` 的返回值就是返回值，中间件不包装、不改写。
    $passThrough = $expectNoThrow(
        'L2 入口类：业务请求透传，返回 $next 的结果',
        static fn () => $middleware->handle(Request::create('http://xhprof.example.com/hello'), static fn (): string => 'route-ok')
    );
    $expect('L2 入口类：透传值原样返回', $passThrough, 'route-ok');
    // ② bootstrap 把适配器装进 Core 静态属性 —— 这是"入口类真的把 5 个适配器接对了"的证据，
    //    也是 `response('')`/`config()` 在真实入口路径上被调到过一遍的证据。
    $bootstrappedRequest = Xhprof::getRequest();
    $expect(
        'L2 入口类：bootstrap 后 Xhprof::getRequest() 是本仓的 Laravel 适配器',
        $bootstrappedRequest === null ? null : get_class($bootstrappedRequest),
        RequestAdapter::class
    );
    $expect('L2 入口类：Xhprof::getConfig() 是 Laravel ConfigAdapter', Xhprof::getConfig() instanceof ConfigAdapter, true);
    $expect('L2 入口类：Xhprof::getResponse() 是 Laravel ResponseAdapter', Xhprof::getResponse() instanceof ResponseAdapter, true);
    $expect('L2 入口类：Xhprof::getCache() 是 Laravel RedisAdapter', Xhprof::getCache() instanceof \ErikWang2013\Xhprof\Laravel\Adapter\RedisAdapter, true);
    // ③ 两个扩展都在（本环的运行前提，contracts.yml 显式装 xhprof+redis）：
    //    `$enabled = SamplingGuard::available() && isEnabled()`，故这两条是上面
    //    "透传"断言真实发生的前提。缺扩展时这里红，而不是静默 SKIP —— 见 run.php 的签字注释。
    $expect('L2 本环前提：ext-xhprof 已装', extension_loaded('xhprof'), true);
    $expect('L2 本环前提：ext-redis 已装', extension_loaded('redis'), true);

    // ④ 报告路径的 deny 分支：auth_token 配了但请求没带 token → 403。
    //    这一条走的是 Xhprof::deny() → Laravel ResponseAdapter 的
    //    withStatus()->withBody()->send()，且在**任何缓存访问之前**返回（故不需要 Redis）。
    $reportResponse = $expectNoThrow(
        'L2 入口类：报告页无 token 时 deny(403)',
        static fn () => $middleware->handle(Request::create('http://xhprof.example.com/xhprof'), static fn () => Xhprof::index())
    );
    if ($reportResponse !== null) {
        $expect('L2 deny(403)：返回的是真 Illuminate 响应', $reportResponse instanceof Response, true);
        $expect('L2 deny(403)：状态码落上去了', $reportResponse->getStatusCode(), 403);
        $expect('L2 deny(403)：正文落上去了', $reportResponse->getContent(), '403 Forbidden');
    } else {
        $checks += 3;
        $failures[] = 'L2 deny(403) 的三条断言未能执行（上一条已抛）';
    }

    // ⑤ 带 token 但 run 非法 → 400（run 白名单 /^[a-f0-9]{13,32}$/，'notavalidrun' 含 g-z 故非法）。
    //    同样在缓存访问之前返回。
    $badRunResponse = $expectNoThrow(
        'L2 入口类：run 非法时 deny(400)',
        static fn () => $middleware->handle(
            Request::create('http://xhprof.example.com/xhprof?token=contract-secret&run=notavalidrun'),
            static fn () => Xhprof::index()
        )
    );
    if ($badRunResponse !== null) {
        $expect('L2 deny(400)：状态码', $badRunResponse->getStatusCode(), 400);
        $expect('L2 deny(400)：正文', $badRunResponse->getContent(), '400 Bad Request');
    } else {
        $checks += 2;
        $failures[] = 'L2 deny(400) 的两条断言未能执行（上一条已抛）';
    }

    if ($failures !== []) {
        return [
            'status' => 'FAIL',
            'detail' => count($failures) . ' 项不符：' . implode('；', array_slice($failures, 0, 10)),
            'skips' => 0,
        ];
    }

    return [
        'status' => 'PASS',
        'detail' => 'L1 签名存在 + L2 真 laravel/framework '
            . (\Composer\InstalledVersions::getPrettyVersion('laravel/framework') ?? '?')
            . "（含真 response()/config()/app() 助手与真 Monolog）语义，共 {$checks} 项断言通过："
            . 'host() 不含端口（对比 getHttpHost() 含端口）、uri() 只含 path+query、url() 不含 query、'
            . 'ip() 默认不轻信 XFF、all() 与 get() 同源、setContent/withHeaders/setStatusCode 就地改、'
            . 'BinaryFileResponse 按内容嗅探故必须钉 Content-Type、入口类 handle() 透传与两条 deny 分支',
        'skips' => 0,
    ];
};
