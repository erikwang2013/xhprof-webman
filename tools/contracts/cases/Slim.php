<?php

declare(strict_types=1);

/**
 * Slim 4 —— 用**真实** slim/slim + nyholm/psr7 验证 src/Slim/**。
 *
 * L1（签名存在）：反射断言 src/Slim 调用的每个方法/常量在真实包里存在。
 *   这一层专杀 `Call to undefined method` —— 最怕的失效模式。
 * L2（契约语义）：用真实的 Slim\MiddlewareDispatcher / Slim\App / PSR-7 对象跑我们的
 *   适配器与入口中间件，断言：
 *     1) `$app->add()` 的顺序语义（后加的更外层、先执行）—— 用**两个中间件 + 断言执行序列**
 *        实测，不靠读源码推断；
 *     2) `$app->getResponseFactory()` 的声明返回类型；
 *     3) `$app->add(XhprofMiddleware::class)` 到底怎么解析构造函数参数；
 *     4) body 就地写的可行性（getBody()->write() → withHeader() 之后仍在）。
 *
 * 两种 PSR-7 实现都钉住：nyholm/psr7 与 slim/psr7。后者是 `AppFactory::create()` 默认
 *   用的那一个（`Slim\Psr7\Factory\ResponseFactory`），也就是真实 Slim 用户拿到的响应对象。
 *   body 相关断言对两者跑**同一套**（见 2.6 的循环）——任一实现将来行为漂移都会 FAIL，
 *   而不是靠人肉比对。本 case 里没有任何"验不了就跳过"的子项，所以 skips 恒为 0。
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

    // ================= L1：签名存在 =================

    $interfaces = [
        'Psr\Http\Message\ServerRequestInterface' => ['getQueryParams', 'getParsedBody', 'getHeaderLine', 'getMethod', 'getUri', 'getServerParams'],
        'Psr\Http\Message\UriInterface' => ['getHost', 'getQuery', 'getPath', '__toString'],
        'Psr\Http\Message\ResponseInterface' => ['getBody', 'withHeader', 'withStatus', 'getStatusCode', 'getHeaderLine', 'hasHeader'],
        'Psr\Http\Message\StreamInterface' => ['write', 'isWritable'],
        'Psr\Http\Message\ResponseFactoryInterface' => ['createResponse'],
        'Psr\Http\Server\MiddlewareInterface' => ['process'],
        'Psr\Http\Server\RequestHandlerInterface' => ['handle'],
        'Psr\Log\LoggerInterface' => ['error'],
        'Slim\MiddlewareDispatcher' => ['__construct', 'add', 'addMiddleware', 'handle', 'seedMiddlewareStack'],
        'Slim\App' => ['__construct', 'add', 'addMiddleware', 'addRoutingMiddleware', 'addErrorMiddleware', 'getResponseFactory', 'get', 'handle'],
    ];

    foreach ($interfaces as $name => $methods) {
        $expect("L1 {$name} 存在", interface_exists($name) || class_exists($name), true);
        if (!interface_exists($name) && !class_exists($name)) {
            continue;
        }
        $rc = new ReflectionClass($name);
        foreach ($methods as $method) {
            $expect("L1 {$name}::{$method}() 存在", $rc->hasMethod($method), true);
        }
    }

    // 我们的适配器/入口类必须是真实接口的实现（而不是"长得像"）
    $expect(
        'L1 XhprofMiddleware 是真实 Psr\Http\Server\MiddlewareInterface 的实现',
        is_subclass_of(\ErikWang2013\Xhprof\Slim\XhprofMiddleware::class, \Psr\Http\Server\MiddlewareInterface::class),
        true
    );
    $expect(
        'L1 RequestAdapter 是 Core 契约的实现',
        is_subclass_of(\ErikWang2013\Xhprof\Slim\Adapter\RequestAdapter::class, \ErikWang2013\Xhprof\Core\Contract\RequestInterface::class),
        true
    );

    // ================= L2：真实对象 =================

    // 内存 Cache（只实现程序里真正用到的语义）。必须显式注入 —— 中间件默认的
    // RedisAdapter 会去连真 Redis，而报告页的 list_runs() 会真的读它。
    // 这条也是真实行为：**没有 Redis 就没有报告页**，四个既有框架同理。
    $makeCache = static function (): \ErikWang2013\Xhprof\Core\Contract\CacheInterface {
        return new class implements \ErikWang2013\Xhprof\Core\Contract\CacheInterface {
            /** @var array<string, mixed> */
            private array $store = [];

            /** @var array<string, array<int, mixed>> */
            private array $lists = [];

            public function get(string $key): mixed
            {
                return $this->store[$key] ?? null;
            }

            public function set(string $key, mixed $value, ?int $ttl = null): mixed
            {
                $this->store[$key] = $value;
                return $value;
            }

            public function mget(array $keys): array
            {
                $out = [];
                foreach ($keys as $key) {
                    $out[$key] = $this->store[$key] ?? null;
                }
                return $out;
            }

            public function incr(string $key): int
            {
                return $this->store[$key] = (int) ($this->store[$key] ?? 0) + 1;
            }

            public function decr(string $key): int
            {
                return $this->store[$key] = (int) ($this->store[$key] ?? 0) - 1;
            }

            public function lPush(string $key, mixed $value): int
            {
                $this->lists[$key] ??= [];
                array_unshift($this->lists[$key], $value);
                return count($this->lists[$key]);
            }

            public function rPop(string $key): mixed
            {
                return empty($this->lists[$key]) ? null : array_pop($this->lists[$key]);
            }

            public function lRange(string $key, int $start, int $end): array
            {
                return $this->lists[$key] ?? [];
            }

            public function del(string ...$keys): int
            {
                $n = 0;
                foreach ($keys as $key) {
                    unset($this->store[$key], $this->lists[$key]);
                    $n++;
                }
                return $n;
            }
        };
    };

    $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
    $makeRequest = static fn (string $method, string $uri): \Psr\Http\Message\ServerRequestInterface
        => new \Nyholm\Psr7\ServerRequest($method, $uri);
    $bodyOf = static fn (\Psr\Http\Message\MessageInterface $m): string => (string) $m->getBody();

    // ---- 2.1 顺序语义：两个中间件，断言执行序列 ----
    $order = [];
    $recorder = static function (string $name) use (&$order): \Psr\Http\Server\MiddlewareInterface {
        return new class($name, $order) implements \Psr\Http\Server\MiddlewareInterface {
            private string $name;
            /** @var array<int, string> */
            private array $order;

            public function __construct(string $name, array &$order)
            {
                $this->name = $name;
                $this->order = &$order;
            }

            public function process(
                \Psr\Http\Message\ServerRequestInterface $request,
                \Psr\Http\Server\RequestHandlerInterface $handler
            ): \Psr\Http\Message\ResponseInterface {
                $this->order[] = $this->name . ':before';
                $response = $handler->handle($request);
                $this->order[] = $this->name . ':after';
                return $response;
            }
        };
    };
    $kernel = new class($psr17) implements \Psr\Http\Server\RequestHandlerInterface {
        private \Psr\Http\Message\ResponseFactoryInterface $factory;

        public function __construct(\Psr\Http\Message\ResponseFactoryInterface $factory)
        {
            $this->factory = $factory;
        }

        public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
        {
            return $this->factory->createResponse(200);
        }
    };

    $dispatcher = new \Slim\MiddlewareDispatcher($kernel);
    $dispatcher->addMiddleware($recorder('A'));   // 先 add
    $dispatcher->addMiddleware($recorder('B'));   // 后 add
    $order = [];
    $dispatcher->handle($makeRequest('GET', 'http://localhost/x'));
    $expect(
        'L2 MiddlewareDispatcher：后 add 的更外层、先执行（LIFO）',
        $order,
        ['B:before', 'A:before', 'A:after', 'B:after']
    );

    $dispatcher2 = new \Slim\MiddlewareDispatcher($kernel);
    $dispatcher2->add($recorder('A'));
    $dispatcher2->add($recorder('B'));
    $order = [];
    $dispatcher2->handle($makeRequest('GET', 'http://localhost/x'));
    $expect('L2 add() 与 addMiddleware() 顺序语义一致', $order, ['B:before', 'A:before', 'A:after', 'B:after']);

    // ---- 2.2 getResponseFactory() 的声明返回类型 ----
    $app0 = new \Slim\App($psr17);
    $rfType = (new ReflectionMethod($app0, 'getResponseFactory'))->getReturnType();
    $expect(
        'L2 $app->getResponseFactory() 声明返回 Psr\Http\Message\ResponseFactoryInterface',
        $rfType === null ? null : (string) $rfType,
        'Psr\Http\Message\ResponseFactoryInterface'
    );
    $expect('L2 getResponseFactory() 就是构造 App 时传进去的那个实例', $app0->getResponseFactory(), $psr17);

    // ---- 2.3 真 App 上的顺序 ----
    // 注意：Slim 4 的路由 callable **必须**返回 ResponseInterface，返回裸字符串会被
    // RouteRunner 抛 RuntimeException、由 ErrorMiddleware 变成 500 页 —— 那种情况下
    // 执行顺序日志照样完整，只看日志会把 500 误判成通过。所以下面每个路由都返回真响应，
    // 并单独断言状态码。
    $routeOk = static function (array &$log, string $marker = 'route:handler') use ($psr17): \Psr\Http\Message\ResponseInterface {
        $log[] = $marker;
        return $psr17->createResponse(200)->withBody($psr17->createStream('route-ok'));
    };

    $appOrder = new \Slim\App($psr17);
    $appOrder->addRoutingMiddleware();
    $appOrder->addErrorMiddleware(false, false, false);
    $appOrder->get('/hello', static function () use (&$order, $routeOk): \Psr\Http\Message\ResponseInterface {
        return $routeOk($order);
    });
    $appOrder->add($recorder('OUTER'));
    $order = [];
    $helloResponse = $appOrder->handle($makeRequest('GET', 'http://localhost/hello'));
    $expect('L2 真 App：路由 handler 在中间件的最内层', $order, ['OUTER:before', 'route:handler', 'OUTER:after']);
    $expect('L2 真 App：路由真的被执行了（不是被错误中间件吞成 500）', $helloResponse->getStatusCode(), 200);
    $expect('L2 真 App：路由返回值即为响应体', $bodyOf($helloResponse), 'route-ok');

    // ---- 2.4 我们的中间件挂在真 App 上跑 ----
    $buildApp = static function (array $config) use ($psr17, $makeCache, $routeOk): array {
        $app = new \Slim\App($psr17);
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);
        $unused = [];
        $app->get('/hello', static function () use ($routeOk, &$unused): \Psr\Http\Message\ResponseInterface {
            return $routeOk($unused);
        });
        $middleware = new \ErikWang2013\Xhprof\Slim\XhprofMiddleware(
            $app->getResponseFactory(),
            $config,
            $makeCache()
        );
        $app->add($middleware);   // 最后 add = 最外层，故包住 routing
        return [$app, $middleware];
    };

    [$app, ] = $buildApp(['enable' => false]);

    $report = $app->handle($makeRequest('GET', 'http://localhost/xhprof'));
    $expect('L2 报告页短路：200', $report->getStatusCode(), 200);
    $expectContains('L2 报告页短路：返回的是报告 HTML', $bodyOf($report), 'XHProf 性能分析报告');
    $expect('L2 报告页短路：content-type 是 html', $report->getHeaderLine('content-type'), 'text/html; charset=UTF-8');
    $expect('L2 报告页短路：没有被路由的 404 顶掉', $report->getHeaderLine('content-type') !== 'application/json', true);

    $asset = $app->handle($makeRequest('GET', 'http://localhost/xhprof-assets/css/xhprof.css'));
    $expect('L2 静态资源短路：200', $asset->getStatusCode(), 200);
    $expect('L2 静态资源短路：text/css', $asset->getHeaderLine('content-type'), 'text/css');
    $expect('L2 静态资源短路：body 非空', $bodyOf($asset) !== '', true);
    $expect('L2 静态资源短路：带 Cache-Control', $asset->getHeaderLine('cache-control'), 'public, max-age=86400');

    $normal = $app->handle($makeRequest('GET', 'http://localhost/hello'));
    $expect('L2 正常请求透传：状态码', $normal->getStatusCode(), 200);
    $expect('L2 正常请求透传：业务响应体', $bodyOf($normal), 'route-ok');

    // 报告路径是精确匹配，不是前缀：/xhprof-custom-page 必须走业务路由（这里是 404）
    $notReport = $app->handle($makeRequest('GET', 'http://localhost/hello2'));
    $expect('L2 非报告路径不受影响（业务 404 就 404）', $notReport->getStatusCode(), 404);

    // 真实 PSR-7 响应上验证我们的 ResponseAdapter（R-1~R-5 走真包）
    $adapter = new \ErikWang2013\Xhprof\Slim\Adapter\ResponseAdapter($psr17->createResponse());
    $sent = $adapter->withStatus(201)->withBody('hello-body')->withHeaders(['X-A' => '1'])->send();
    $expect('L2 ResponseAdapter：状态码', $sent->getStatusCode(), 201);
    $expect('L2 ResponseAdapter：就地写的 body 在真实 PSR-7 响应里可见', $bodyOf($sent), 'hello-body');
    $expect('L2 ResponseAdapter：header 生效', $sent->getHeaderLine('x-a'), '1');

    $fileAdapter = new \ErikWang2013\Xhprof\Slim\Adapter\ResponseAdapter($psr17->createResponse());
    $fileSent = $fileAdapter->file($repoRoot . '/src/html/css/xhprof.css')->withHeaders([
        'Cache-Control' => 'public, max-age=86400',
    ])->send();
    $expect('L2 ResponseAdapter R-5：file() 之后 withHeaders() 仍生效', $fileSent->getHeaderLine('cache-control'), 'public, max-age=86400');
    $expect('L2 ResponseAdapter R-5：file() 的内容没被后续 withHeaders() 冲掉', strpos($bodyOf($fileSent), '.xhprof') !== false, true);
    $expect('L2 ResponseAdapter：file() 推出 MIME', $fileSent->getHeaderLine('content-type'), 'text/css');

    // 真实 PSR-7 请求上验证 RequestAdapter（R-1/R-2/R-3 走真包）
    // nyholm 的构造函数第 3 个参数是 headers，serverParams 在第 6 个 —— 传错位置
    // 会让 getRealIp() 静默退化成 127.0.0.1（第一版就踩了这个坑）。
    $realReq = new \Nyholm\Psr7\ServerRequest(
        'POST',
        'http://xhprof.example.com:8080/list?page=2',
        [],
        null,
        '1.1',
        ['HTTP_X_FORWARDED_FOR' => '1.2.3.4, 5.6.7.8']
    );
    $reqAdapter = new \ErikWang2013\Xhprof\Slim\Adapter\RequestAdapter($realReq);
    $expect('L2 R-1：uri() 只含 path+query', $reqAdapter->uri(), '/list?page=2');
    $expect('L2 R-1：uri() 不含 scheme', strpos($reqAdapter->uri(), '://') === false, true);
    $expect('L2 R-2：host() 不含端口', $reqAdapter->host(), 'xhprof.example.com');
    $expect('L2 R-3：header() 缺省返回 null', $reqAdapter->header('X-Absent'), null);
    $expect('L2 R-3：getRealIp() 取 XFF 第一个', $reqAdapter->getRealIp(), '1.2.3.4');
    $expect('L2 url() 是绝对 URL', $reqAdapter->url(), 'http://xhprof.example.com:8080/list?page=2');
    $expect('L2 method()', $reqAdapter->method(), 'POST');

    // ---- 2.5 类名字符串 `$app->add(XhprofMiddleware::class)` 到底行不行 ----
    //
    // 实测结论：Slim\CallableResolver::resolveSlimNotation() 对类名只会做
    //   `$instance = new $class($this->container)`  ——  即**只传容器这一个参数**
    // （容器为 null 时就是 `new $class(null)`），且解析发生在 **handle() 期**而非 add() 期。
    //
    // 所以：
    //   a) 容器里**有**定义 → 走 $container->get()，构造函数完全由容器决定 → 可行；
    //   b) 容器里没有定义 / 没容器 → `new XhprofMiddleware(null)` → 我们要求第一个参数是
    //      ResponseFactoryInterface，于是**请求期**抛 TypeError（不是启动期！）。
    // b) 这条是本 middleware 的构造函数形状的直接后果，必须让用户知道。

    $container = new class implements \Psr\Container\ContainerInterface {
        /** @var array<string, mixed> */
        public array $defs = [];

        public function get(string $id): mixed
        {
            return $this->defs[$id];
        }

        public function has(string $id): bool
        {
            return array_key_exists($id, $this->defs);
        }
    };

    // a) 容器里有定义 → 类名字符串可用
    $appWithContainer = new \Slim\App($psr17, $container);
    $appWithContainer->addRoutingMiddleware();
    $appWithContainer->addErrorMiddleware(false, false, false);
    $appWithContainer->get('/hello', static fn (): string => 'route-ok');
    $container->defs[\ErikWang2013\Xhprof\Slim\XhprofMiddleware::class] =
        new \ErikWang2013\Xhprof\Slim\XhprofMiddleware($appWithContainer->getResponseFactory(), ['enable' => false], $makeCache());
    $appWithContainer->add(\ErikWang2013\Xhprof\Slim\XhprofMiddleware::class);
    $viaClassString = $appWithContainer->handle($makeRequest('GET', 'http://localhost/xhprof'));
    $expect('L2 add(类名) + 容器有定义：报告页短路成功', $viaClassString->getStatusCode(), 200);
    $expectContains('L2 add(类名) + 容器有定义：拿到报告 HTML', $bodyOf($viaClassString), 'XHProf 性能分析报告');

    // b) 无容器 → add() 不报错，handle() 抛 TypeError（记录确切类型与信息）
    $appNoContainer = new \Slim\App($psr17);
    $appNoContainer->addRoutingMiddleware();
    $appNoContainer->addErrorMiddleware(false, false, false);
    $appNoContainer->get('/hello', static fn (): string => 'route-ok');
    $appNoContainer->add(\ErikWang2013\Xhprof\Slim\XhprofMiddleware::class);

    $addThrew = null;
    try {
        $appNoContainer->add(\ErikWang2013\Xhprof\Slim\XhprofMiddleware::class);
    } catch (\Throwable $e) {
        $addThrew = $e;
    }
    $expect('L2 add(类名) 无容器：add() 阶段**不**报错（解析被推迟）', $addThrew, null);

    $handleThrew = null;
    try {
        $appNoContainer->handle($makeRequest('GET', 'http://localhost/hello'));
    } catch (\Throwable $e) {
        $handleThrew = $e;
    }
    $expect('L2 add(类名) 无容器：handle() 阶段抛 TypeError', $handleThrew instanceof \TypeError, true);
    $expectContains(
        'L2 add(类名) 无容器：TypeError 说的是第一个参数',
        $handleThrew === null ? '' : $handleThrew->getMessage(),
        'Argument #1 ($responseFactory)'
    );

    // ---- 2.6 body 就地写的可行性：两种真实 PSR-7 实现跑同一套断言 ----
    // slim/psr7 是 AppFactory::create() 默认用的实现，必须和 nyholm 一起钉住。
    $impls = [
        'nyholm/psr7' => [
            new \Nyholm\Psr7\Factory\Psr17Factory(),
            new \Nyholm\Psr7\Factory\Psr17Factory(),
        ],
        'slim/psr7' => [
            new \Slim\Psr7\Factory\ResponseFactory(),
            new \Slim\Psr7\Factory\StreamFactory(),
        ],
    ];

    foreach ($impls as $impl => [$responseFactory, $streamFactory]) {
        $plain = $responseFactory->createResponse(200);
        $plain->getBody()->write('hello');
        $expect("L2 body[$impl]：getBody()->write() 后内容可见（读回必须走 getBody()）", $bodyOf($plain), 'hello');
        $expect("L2 body[$impl]：新建响应的流可写", $plain->getBody()->isWritable(), true);
        $expect(
            "L2 body[$impl]：withHeader() 的 clone 与原响应共享同一个 stream 对象",
            $plain->withHeader('X-A', '1')->getBody() === $plain->getBody(),
            true
        );
        $expect("L2 body[$impl]：withHeader() 之后内容仍在", $bodyOf($plain->withHeader('X-A', '1')), 'hello');
        $expect("L2 body[$impl]：withStatus() 之后内容仍在", $bodyOf($plain->withStatus(404)), 'hello');

        // PSR-7 响应没有 __toString()：计划里"(string) $response"那个写法本身是错的，
        // 这一条把它钉住，免得以后有人再照着写。
        $expect("L2 body[$impl]：ResponseInterface 没有 __toString()", method_exists($plain, '__toString'), false);

        // write() 写的是**当前流指针位置**，既不追加也不截断。上一行写完 'hello' 后指针
        // 停在 5，所以再写是接在后面 —— "每个响应只写一次"这条不变量正是靠这一点成立。
        $plain->getBody()->write('world');
        $expect("L2 body[$impl]：第二次 write 接在指针后（故依赖「只写一次」不变量）", $bodyOf($plain), 'helloworld');

        // 反过来，若指针在 0（流里已有内容），write() 是**覆盖**开头且不截断尾巴 ——
        // 这一条把"第二次写会追加"这个含糊说法纠正为真正的机制。
        $positioned = $responseFactory->createResponse(200)
            ->withBody($streamFactory->createStream('hello'));
        $positioned->getBody()->write('A');
        $expect("L2 body[$impl]：指针在 0 时 write() 覆盖开头且不截断（得到 Aello）", $bodyOf($positioned), 'Aello');

        // 端到端：我们的 ResponseAdapter 直接吃这个实现的响应对象（R-5）
        $sent = (new \ErikWang2013\Xhprof\Slim\Adapter\ResponseAdapter($responseFactory->createResponse()))
            ->withBody("hi-$impl")
            ->withStatus(201)
            ->withHeaders(['Content-Type' => 'text/plain'])
            ->send();
        $expect("L2 ResponseAdapter[$impl]：就地写的内容可见", $bodyOf($sent), "hi-$impl");
        $expect("L2 ResponseAdapter[$impl]：状态码落上去了", $sent->getStatusCode(), 201);
        $expect("L2 ResponseAdapter[$impl]：header 落上去了", $sent->getHeaderLine('Content-Type'), 'text/plain');
    }

    // 真包上跑一遍 ConfigAdapter（R-6/R-7）
    $cfg = new \ErikWang2013\Xhprof\Slim\Adapter\ConfigAdapter(['ignore_url_arr' => ['/admin']]);
    $expect('L2 R-6：get(\'xhprof\') 返回整块', is_array($cfg->get('xhprof')), true);
    $expect('L2 R-6：get(\'xhprof.assets_url\') 返回叶子', $cfg->get('xhprof.assets_url'), '/xhprof-assets');
    $expect('L2 R-7：用户列表整体替换，不与默认值逐下标合并', $cfg->get('xhprof.ignore_url_arr'), ['/admin']);

    if ($failures !== []) {
        return [
            'status' => 'FAIL',
            'detail' => count($failures) . ' 项不符：' . implode('；', array_slice($failures, 0, 10)),
            'skips' => 0,
        ];
    }

    return [
        'status' => 'PASS',
        'detail' => "L1 签名存在 + L2 真实 slim/slim 4.15.3 语义，PSR-7 用 nyholm/psr7 与 slim/psr7 两种实现各跑一套，"
            . "共 {$checks} 项断言通过（含 add() 顺序 LIFO、getResponseFactory() 返回类型、类名字符串解析、body 就地写）",
        'skips' => 0,
    ];
};
