<?php

declare(strict_types=1);

/**
 * Yii3 —— 用**真实** yiisoft/middleware-dispatcher 5.4 + yiisoft/di 1.4 + nyholm/psr7
 * 验证 src/Yii3/**。
 *
 * L1（签名存在）：反射断言 src/Yii3 调用的每个方法/常量在真实包里存在。
 *   这一层专杀 `Call to undefined method` —— 最怕的失效模式。
 * L2（契约语义）：全部用真实对象实测，断言：
 *   1) `withMiddlewares()` 是 **MiddlewareDispatcher 的实例方法**，
 *      `__construct()` 里**没有** `middlewares` 参数 —— README 原有的
 *      `'__construct()' => ['middlewares' => [...]]` 写法是错的；
 *   2) 数组里**第一位是最外层**（最先执行、最后结束）—— 用两个中间件断言执行序列，
 *      不靠读源码推断；
 *   3) `withMiddlewares([实例])` 会在 dispatch 期抛 TypeError（定义只收
 *      array|callable|string）—— 用户最容易踩的一脚；
 *   4) 真实 yiisoft/di 容器里 `XhprofMiddleware::class` 能否只靠自动装配解析出来
 *      （第 2、3 个参数走反射默认值）—— README 最简写法成立与否就取决于此；
 *   5) 报告页 / 静态资源短路、业务透传、`/xhprof-assets-nope` 不被前缀误伤；
 *   6) R-1~R-5 在真实 PSR-7 对象上成立。
 *
 * 已知未覆盖（诚实标注，不是伪装成通过）：
 *   - phpredis 的连接语义（host/port/password/select）**不**在这里验：本 case 全程
 *     注入内存版 CacheInterface，不连真 Redis。`\Redis` 的方法名只在扩展已加载时
 *     做反射断言，未加载时把这件事写进 detail（见下方 $redisNote），**不**计入 skips。
 *   - 单测（tests/Unit/Adapter/Yii3Test.php）用 tests/Fixtures 的伪件覆盖连接参数的
 *     解析与延迟连接；两者合起来才算覆盖，单独看任何一边都不足。
 *   - Yii3 真框架（yiisoft/app 那一整套：router/http-runner 等）不在依赖里，本 case
 *     验的是**中间件这一层**在真实 dispatcher + 真实 DI 下的行为，不是"跑起一个
 *     Yii3 应用"。前者是我们实际接触的全部表面。
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

    $redisNote = extension_loaded('redis')
        ? 'ext-redis 已加载，\\Redis 方法名一并反射断言'
        : 'ext-redis **未加载**，\\Redis 方法名本次未验（RedisAdapter 只在实际取连接时才 new \\Redis）';

    $interfaces = [
        'Psr\Http\Message\ServerRequestInterface' => ['getQueryParams', 'getParsedBody', 'getHeaderLine', 'getMethod', 'getUri', 'getServerParams'],
        'Psr\Http\Message\UriInterface' => ['getHost', 'getQuery', 'getPath', '__toString'],
        'Psr\Http\Message\ResponseInterface' => ['getBody', 'withHeader', 'withStatus', 'getStatusCode', 'getHeaderLine', 'hasHeader'],
        'Psr\Http\Message\StreamInterface' => ['write', 'isWritable'],
        'Psr\Http\Message\ResponseFactoryInterface' => ['createResponse'],
        'Psr\Http\Server\MiddlewareInterface' => ['process'],
        'Psr\Http\Server\RequestHandlerInterface' => ['handle'],
        'Psr\Log\LoggerInterface' => ['error'],
        'Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher' => ['__construct', 'withMiddlewares', 'dispatch', 'hasMiddlewares'],
        'Yiisoft\Middleware\Dispatcher\MiddlewareFactory' => ['__construct', 'create'],
        'Yiisoft\Di\Container' => ['__construct', 'get', 'has'],
        'Yiisoft\Di\ContainerConfig' => ['create', 'withDefinitions'],
        // ContainerConfigInterface 上**没有** withDefinitions()（它在具体类上），
        // 接口只声明读取侧。要按接口类型注入配置时别指望能 withDefinitions()。
        'Yiisoft\Di\ContainerConfigInterface' => ['getDefinitions', 'getProviders', 'getTags', 'shouldValidate', 'getDelegates', 'useStrictMode'],
    ];

    if (extension_loaded('redis')) {
        $interfaces['Redis'] = ['connect', 'auth', 'select', 'lpush', 'mget', 'lrange'];
    }

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
        is_subclass_of(\ErikWang2013\Xhprof\Yii3\XhprofMiddleware::class, \Psr\Http\Server\MiddlewareInterface::class),
        true
    );
    $expect(
        'L1 RequestAdapter 是 Core 契约的实现',
        is_subclass_of(\ErikWang2013\Xhprof\Yii3\Adapter\RequestAdapter::class, \ErikWang2013\Xhprof\Core\Contract\RequestInterface::class),
        true
    );
    $expect(
        'L1 ResponseAdapter 是 Core 契约的实现',
        is_subclass_of(\ErikWang2013\Xhprof\Yii3\Adapter\ResponseAdapter::class, \ErikWang2013\Xhprof\Core\Contract\ResponseInterface::class),
        true
    );
    $expect(
        'L1 RedisAdapter 是 Core 契约的实现',
        is_subclass_of(\ErikWang2013\Xhprof\Yii3\Adapter\RedisAdapter::class, \ErikWang2013\Xhprof\Core\Contract\CacheInterface::class),
        true
    );

    // ================= L2：真实对象 =================

    $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
    $bodyOf = static fn (\Psr\Http\Message\MessageInterface $m): string => (string) $m->getBody();
    $makeRequest = static fn (string $method, string $uri): \Psr\Http\Message\ServerRequestInterface
        => new \Nyholm\Psr7\ServerRequest($method, $uri);

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

    $cache = $makeCache();

    $fallback = new class($psr17) implements \Psr\Http\Server\RequestHandlerInterface {
        private \Psr\Http\Message\ResponseFactoryInterface $factory;

        /** @var array<int, string> */
        public array $seen = [];

        public function __construct(\Psr\Http\Message\ResponseFactoryInterface $factory)
        {
            $this->factory = $factory;
        }

        public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
        {
            $this->seen[] = $request->getUri()->getPath();
            return $this->factory->createResponse(200)->withBody($this->factory->createStream('fallback'));
        }
    };

    $container = new \Yiisoft\Di\Container(
        \Yiisoft\Di\ContainerConfig::create()->withDefinitions([
            \Psr\Http\Message\ResponseFactoryInterface::class => $psr17,
        ])
    );
    $factory = new \Yiisoft\Middleware\Dispatcher\MiddlewareFactory($container);

    // ---- 2.1 README 形状：注册中间件到底怎么写 ----
    $ctorParams = array_map(
        static fn (\ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(\Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher::class, '__construct'))->getParameters()
    );
    $expect(
        'L2 MiddlewareDispatcher::__construct() 的参数只有 middlewareFactory + eventDispatcher',
        $ctorParams,
        ['middlewareFactory', 'eventDispatcher']
    );
    $expect(
        'L2 __construct() 里**没有** middlewares 参数 → README 旧写法 __construct()[middlewares] 是错的',
        in_array('middlewares', $ctorParams, true),
        false
    );
    $withMiddlewares = new ReflectionMethod(\Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher::class, 'withMiddlewares');
    $expect('L2 withMiddlewares() 是实例方法而不是静态方法', $withMiddlewares->isStatic(), false);
    $expect(
        'L2 withMiddlewares() 的参数名是 middlewareDefinitions',
        $withMiddlewares->getParameters()[0]->getName(),
        'middlewareDefinitions'
    );

    // ---- 2.2 顺序语义：两个中间件，断言执行序列 ----
    $log = new class {
        /** @var array<int, string> */
        public array $lines = [];
    };
    $recorder = static function (string $name) use ($log): callable {
        return static function (
            \Psr\Http\Message\ServerRequestInterface $request,
            \Psr\Http\Server\RequestHandlerInterface $handler
        ) use ($name, $log): \Psr\Http\Message\ResponseInterface {
            $log->lines[] = $name . ':before';
            $response = $handler->handle($request);
            $log->lines[] = $name . ':after';
            return $response;
        };
    };

    $dispatcher = (new \Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher($factory))
        ->withMiddlewares([$recorder('A'), $recorder('B')]);
    $log->lines = [];
    $dispatcher->dispatch($makeRequest('GET', 'http://localhost/x'), $fallback);
    $expect(
        'L2 withMiddlewares([A, B])：第一位 A 是最外层 —— 先执行、最后结束',
        $log->lines,
        ['A:before', 'B:before', 'B:after', 'A:after']
    );

    $dispatcher2 = (new \Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher($factory))
        ->withMiddlewares([$recorder('B'), $recorder('A')]);
    $log->lines = [];
    $dispatcher2->dispatch($makeRequest('GET', 'http://localhost/x'), $fallback);
    $expect(
        'L2 顺序确实是「按数组下标」而不是巧合（交换后序列同步交换）',
        $log->lines,
        ['B:before', 'A:before', 'A:after', 'B:after']
    );

    // ---- 2.3 withMiddlewares([实例]) 会在 dispatch 期抛 TypeError ----
    // 用户最自然的写法（Slim 那边 addMiddleware($instance) 就是这样）在 Yii3 里不成立：
    // MiddlewareFactory::create() 的形参类型是 array|callable|string。
    $instanceDispatcher = null;
    $defineThrown = null;
    try {
        $instanceDispatcher = (new \Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher($factory))
            ->withMiddlewares([new \ErikWang2013\Xhprof\Yii3\XhprofMiddleware($psr17, ['enable' => false], $makeCache())]);
    } catch (\Throwable $e) {
        $defineThrown = $e;
    }
    $expect('L2 withMiddlewares([实例])：withMiddlewares() 阶段不报错（校验被推迟到 dispatch）', $defineThrown, null);

    $instanceThrown = null;
    try {
        $instanceDispatcher->dispatch($makeRequest('GET', 'http://localhost/x'), $fallback);
    } catch (\Throwable $e) {
        $instanceThrown = $e;
    }
    $expect('L2 withMiddlewares([实例])：dispatch() 期抛 TypeError', $instanceThrown instanceof \TypeError, true);
    $expectContains(
        'L2 withMiddlewares([实例])：TypeError 说明定义只收 callable|array|string',
        $instanceThrown === null ? '' : $instanceThrown->getMessage(),
        'must be of type callable|array|string'
    );

    // 空栈也是抛错而不是"什么都不做"：注册了 0 个中间件的 dispatcher 一 dispatch 就炸。
    $emptyDispatcher = (new \Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher($factory))->withMiddlewares([]);
    $emptyThrown = null;
    try {
        $emptyDispatcher->dispatch($makeRequest('GET', 'http://localhost/x'), $fallback);
    } catch (\Throwable $e) {
        $emptyThrown = $e;
    }
    $expect('L2 withMiddlewares([]) 空栈：dispatch() 抛 RuntimeException', $emptyThrown instanceof \RuntimeException, true);
    $expectContains(
        'L2 空栈：异常文案是 Stack is empty',
        $emptyThrown === null ? '' : $emptyThrown->getMessage(),
        'Stack is empty'
    );

    // ---- 2.4 真实 yiisoft/di：XhprofMiddleware::class 能否只靠自动装配解析 ----
    // 这一段直接决定 README 的最简写法成不成立：容器只绑了 ResponseFactoryInterface，
    // 第 2、3 个参数（?array $config / ?CacheInterface $cache）解析不到。
    $autowireContainer = new \Yiisoft\Di\Container(
        \Yiisoft\Di\ContainerConfig::create()->withDefinitions([
            \Psr\Http\Message\ResponseFactoryInterface::class => $psr17,
        ])
    );
    $autowired = null;
    $autowireThrown = null;
    try {
        $autowired = $autowireContainer->get(\ErikWang2013\Xhprof\Yii3\XhprofMiddleware::class);
    } catch (\Throwable $e) {
        $autowireThrown = $e;
    }
    $expect('L2 容器只绑 ResponseFactoryInterface 时能解析出 XhprofMiddleware（不抛）', $autowireThrown, null);
    $expect('L2 解析出来的是真中间件实例', $autowired instanceof \ErikWang2013\Xhprof\Yii3\XhprofMiddleware, true);

    $readPrivate = static function (object $object, string $property): mixed {
        $prop = new \ReflectionProperty($object, $property);
        $prop->setAccessible(true);
        return $prop->getValue($object);
    };
    // 第 2、3 个参数走反射默认值 → config = null（全默认）、cache = 直连版 RedisAdapter
    $expect(
        'L2 解析不到 ?array $config 时走反射默认值 null（即全部使用包默认配置）',
        $autowired === null ? null : $readPrivate($autowired, 'config') instanceof \ErikWang2013\Xhprof\Yii3\Adapter\ConfigAdapter,
        true
    );
    $expect(
        'L2 解析不到 ?CacheInterface 时走反射默认值 null → 退化成直连版 RedisAdapter',
        $autowired === null ? null : $readPrivate($autowired, 'cache') instanceof \ErikWang2013\Xhprof\Yii3\Adapter\RedisAdapter,
        true
    );

    // 容器的 __construct() 注入口 + 绑定 CacheInterface 时，可选参数该被容器满足
    $dictContainer = new \Yiisoft\Di\Container(
        \Yiisoft\Di\ContainerConfig::create()->withDefinitions([
            \Psr\Http\Message\ResponseFactoryInterface::class => $psr17,
            \ErikWang2013\Xhprof\Core\Contract\CacheInterface::class => $cache,
            \ErikWang2013\Xhprof\Yii3\XhprofMiddleware::class => [
                'class' => \ErikWang2013\Xhprof\Yii3\XhprofMiddleware::class,
                '__construct()' => [1 => ['enable' => true, 'auth_token' => 'secret']],
            ],
        ])
    );
    $bound = null;
    $boundThrown = null;
    try {
        $bound = $dictContainer->get(\ErikWang2013\Xhprof\Yii3\XhprofMiddleware::class);
    } catch (\Throwable $e) {
        $boundThrown = $e;
    }
    $expect('L2 __construct() 注入口 1（config）+ 自动装配其余参数：不抛', $boundThrown, null);
    $expect(
        'L2 容器绑定了 CacheInterface 时，第 3 个参数被容器满足（不是退化成直连 Redis）',
        $bound === null ? null : $readPrivate($bound, 'cache') === $cache,
        true
    );

    // 具名参数形态（比 [1 => ...] 可读，README 打算写这个）：__construct()['config']
    $namedContainer = new \Yiisoft\Di\Container(
        \Yiisoft\Di\ContainerConfig::create()->withDefinitions([
            \Psr\Http\Message\ResponseFactoryInterface::class => $psr17,
            \ErikWang2013\Xhprof\Core\Contract\CacheInterface::class => $cache,
            \ErikWang2013\Xhprof\Yii3\XhprofMiddleware::class => [
                'class' => \ErikWang2013\Xhprof\Yii3\XhprofMiddleware::class,
                '__construct()' => ['config' => ['enable' => true, 'key_prefix' => 'named-form']],
            ],
        ])
    );
    $named = null;
    $namedThrown = null;
    try {
        $named = $namedContainer->get(\ErikWang2013\Xhprof\Yii3\XhprofMiddleware::class);
    } catch (\Throwable $e) {
        $namedThrown = $e;
    }
    $expect('L2 __construct()["config"] 具名参数形态：不抛', $namedThrown, null);
    $namedConfig = $named === null ? null : $readPrivate($named, 'config');
    $expect(
        'L2 具名形态注入的 config 真的生效（key_prefix 被改到）',
        $namedConfig instanceof \ErikWang2013\Xhprof\Yii3\Adapter\ConfigAdapter
            ? $namedConfig->get('xhprof.key_prefix')
            : null,
        'named-form'
    );

    // ---- 2.5 MiddlewareDispatcher 也交给容器装配（withMiddlewares() 方法调用形态）----
    // 这才是 README 要写进 config 的形状：数组里第一位是最外层。
    $appContainer = new \Yiisoft\Di\Container(
        \Yiisoft\Di\ContainerConfig::create()->withDefinitions([
            \Psr\Http\Message\ResponseFactoryInterface::class => $psr17,
            \ErikWang2013\Xhprof\Core\Contract\CacheInterface::class => $cache,
            \Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher::class => [
                'class' => \Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher::class,
                'withMiddlewares()' => [[
                    $recorder('OUTER'),
                    \ErikWang2013\Xhprof\Yii3\XhprofMiddleware::class,
                ]],
            ],
            \ErikWang2013\Xhprof\Yii3\XhprofMiddleware::class => [
                'class' => \ErikWang2013\Xhprof\Yii3\XhprofMiddleware::class,
                // `locale` 钉死：报告页文案随语言协商变化，下面那条中文标题断言
                // 不该依赖请求对象默认带没带 Accept-Language。
                '__construct()' => [1 => ['enable' => true, 'auth_token' => 'secret', 'locale' => 'zh_CN']],
            ],
        ])
    );
    $diDispatcher = null;
    $diThrown = null;
    try {
        $diDispatcher = $appContainer->get(\Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher::class);
    } catch (\Throwable $e) {
        $diThrown = $e;
    }
    $expect('L2 用 DI 定义 withMiddlewares() 装配 MiddlewareDispatcher：不抛', $diThrown, null);
    $expect('L2 装配出来的是真 MiddlewareDispatcher', $diDispatcher instanceof \Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher, true);
    if ($diThrown !== null) {
        $failures[] = 'L2 DI 装配 MiddlewareDispatcher 抛了：' . get_class($diThrown) . ': ' . $diThrown->getMessage();
        $checks++;
    }

    if ($diDispatcher instanceof \Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher) {
        $defs = $readPrivate($diDispatcher, 'middlewareDefinitions');
        $expect('L2 withMiddlewares() 定义被容器调用到了（2 条）', is_array($defs) && count($defs) === 2, true);
        // 注意方向：withMiddlewares() 内部是 `array_reverse($middlewareDefinitions)` 后再
        // 逐个包裹（后包裹的在最外层），两处反转抵消 → 用户书写的**第一位是最外层**。
        // 所以直接读这个私有属性会看到**相反**的顺序，调试时极易看反，这里钉住。
        $expect(
            'L2 私有 middlewareDefinitions 是反转存储的（用户第一位被挪到最后）',
            is_array($defs) ? ($defs[0] ?? null) : null,
            \ErikWang2013\Xhprof\Yii3\XhprofMiddleware::class
        );

        // 报告页：auth_token 设了但请求没带 token → 403，且内层 fallback 不被调用
        $log->lines = [];
        $fallback->seen = [];
        $denied = $diDispatcher->dispatch($makeRequest('GET', 'http://localhost/xhprof'), $fallback);
        $expect('L2 报告页短路（未带 token）：403', $denied->getStatusCode(), 403);
        $expect('L2 报告页短路（未带 token）：body 是 deny 文案', $bodyOf($denied), '403 Forbidden');
        $expect('L2 报告页短路：内层 fallback handler 没被调用', $fallback->seen, []);
        // 我们的中间件在最内层，OUTER 仍能看到 before/after —— 说明短路发生在
        // 中间件链内部（而不是把 OUTER 一起短路掉）
        $expect('L2 报告页短路：外层中间件的 before/after 都发生了', $log->lines, ['OUTER:before', 'OUTER:after']);

        // 带对 token → 200 报告 HTML
        $report = $diDispatcher->dispatch($makeRequest('GET', 'http://localhost/xhprof?token=secret'), $fallback);
        $expect('L2 报告页（带对 token）：200', $report->getStatusCode(), 200);
        $expectContains('L2 报告页：返回报告 HTML', $bodyOf($report), 'XHProf 性能分析报告');
        // 真实 nyholm 响应不会自带 Content-Type（createResponse() 只给个空响应），
        // 所以这条只能由中间件显式设置 —— 契约环里同样验证它真的到了响应上。
        $expect('L2 报告页：Content-Type 是 html（PSR-7 无默认值）', $report->getHeaderLine('content-type'), 'text/html; charset=UTF-8');
        $expect('L2 报告页：没有落到 fallback', $fallback->seen, []);

        // 静态资源
        $asset = $diDispatcher->dispatch($makeRequest('GET', 'http://localhost/xhprof-assets/css/xhprof.css'), $fallback);
        $expect('L2 静态资源短路：200', $asset->getStatusCode(), 200);
        $expect('L2 静态资源短路：text/css', $asset->getHeaderLine('content-type'), 'text/css');
        $expect('L2 静态资源短路：带 Cache-Control', $asset->getHeaderLine('cache-control'), 'public, max-age=86400');
        $expectContains('L2 静态资源短路：拿到真文件内容', $bodyOf($asset), '.xhprof');
        $expect('L2 静态资源短路：没有落到 fallback', $fallback->seen, []);

        // 业务请求透传
        $normal = $diDispatcher->dispatch($makeRequest('GET', 'http://localhost/hello'), $fallback);
        $expect('L2 业务请求透传到 fallback：状态码', $normal->getStatusCode(), 200);
        $expect('L2 业务请求透传到 fallback：body 是业务响应', $bodyOf($normal), 'fallback');
        $expect('L2 业务请求透传到 fallback：fallback 真被调用了', $fallback->seen, ['/hello']);

        // 前缀必须带 '/'：/xhprof-assets-nope 是业务路径，不能被误伤
        $missing = $diDispatcher->dispatch($makeRequest('GET', 'http://localhost/xhprof-assets-nope'), $fallback);
        $expect('L2 /xhprof-assets-nope 不被前缀误伤（走业务）', $bodyOf($missing), 'fallback');
        $expect('L2 /xhprof-assets-nope 真到了 fallback', $fallback->seen, ['/hello', '/xhprof-assets-nope']);

        // 报告路径是精确匹配，不是前缀：/xhprof-other 必须走业务
        $other = $diDispatcher->dispatch($makeRequest('GET', 'http://localhost/xhprof-other'), $fallback);
        $expect('L2 /xhprof-other 走业务', $bodyOf($other), 'fallback');
    }

    // ---- 2.6 R-1~R-5 走真包 ----
    // nyholm 的构造函数第 3 个参数是 headers，serverParams 在第 6 个 —— 传错位置
    // 会让 getRealIp() 静默退化成 127.0.0.1。
    $realReq = new \Nyholm\Psr7\ServerRequest(
        'POST',
        'http://xhprof.example.com:8080/list?page=2',
        [],
        null,
        '1.1',
        ['HTTP_X_FORWARDED_FOR' => '1.2.3.4, 5.6.7.8']
    );
    $reqAdapter = new \ErikWang2013\Xhprof\Yii3\Adapter\RequestAdapter($realReq);
    $expect('L2 R-1：uri() 只含 path+query', $reqAdapter->uri(), '/list?page=2');
    $expect('L2 R-1：uri() 不含 scheme', strpos($reqAdapter->uri(), '://') === false, true);
    $expect('L2 R-2：host() 不含端口', $reqAdapter->host(), 'xhprof.example.com');
    $expect('L2 R-3：header() 缺省返回 null', $reqAdapter->header('X-Absent'), null);
    $expect('L2 R-3：getRealIp() 取 XFF 第一个', $reqAdapter->getRealIp(), '1.2.3.4');
    $expect('L2 url() 是绝对 URL', $reqAdapter->url(), 'http://xhprof.example.com:8080/list?page=2');
    $expect('L2 method()', $reqAdapter->method(), 'POST');

    $realReq2 = new \Nyholm\Psr7\ServerRequest('GET', 'http://xhprof.example.com:8080');
    $reqAdapter2 = new \ErikWang2013\Xhprof\Yii3\Adapter\RequestAdapter($realReq2);
    $expect('L2 R-1：无 query 时 uri() 不带问号', $reqAdapter2->uri(), '');
    $expect(
        'L2 R-3：真实请求上 getRealIp() 仍是 string（不会返回 null）',
        is_string($reqAdapter2->getRealIp()),
        true
    );

    $resAdapter = new \ErikWang2013\Xhprof\Yii3\Adapter\ResponseAdapter($psr17);
    $sent = $resAdapter->withStatus(201)->withBody('hello-body')->withHeaders(['X-A' => '1'])->send();
    $expect('L2 ResponseAdapter：状态码', $sent->getStatusCode(), 201);
    $expect('L2 ResponseAdapter：body 在真实 PSR-7 响应里可见', $bodyOf($sent), 'hello-body');
    $expect('L2 ResponseAdapter：header 生效', $sent->getHeaderLine('x-a'), '1');

    $fileAdapter = new \ErikWang2013\Xhprof\Yii3\Adapter\ResponseAdapter($psr17);
    $fileSent = $fileAdapter->file($repoRoot . '/src/html/css/xhprof.css')->withHeaders([
        'Cache-Control' => 'public, max-age=86400',
    ])->send();
    $expect('L2 R-5：file() 之后 withHeaders() 仍生效', $fileSent->getHeaderLine('cache-control'), 'public, max-age=86400');
    $expect('L2 R-5：file() 的内容没被后续 withHeaders() 冲掉', strpos($bodyOf($fileSent), '.xhprof') !== false, true);
    $expect('L2 ResponseAdapter：file() 推出 MIME', $fileSent->getHeaderLine('content-type'), 'text/css');

    // 真包上跑一遍 ConfigAdapter（R-6/R-7）
    $cfg = new \ErikWang2013\Xhprof\Yii3\Adapter\ConfigAdapter(['ignore_url_arr' => ['/admin']]);
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
        'detail' => "L1 签名存在 + L2 真实 yiisoft/middleware-dispatcher 5.4 + yiisoft/di 1.4 + nyholm/psr7 语义，"
            . "共 {$checks} 项断言通过（含 withMiddlewares 顺序=第一位最外层、__construct 无 middlewares 参数、"
            . "实例定义在 dispatch 期抛 TypeError、DI 自动装配走反射默认值、报告页/资源短路、R-1~R-7）。"
            . ' ' . $redisNote,
        'skips' => 0,
    ];
};
