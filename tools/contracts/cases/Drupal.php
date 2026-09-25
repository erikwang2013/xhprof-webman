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
 * Drupal 侧（原先记 3 条 SKIP，现已解冻为真断言）—— 环里装了真实的 `drupal/core`：
 *   ① 真实 `config.factory` / `ImmutableConfig` 语义：真 FileStorage 读模块**真的**
 *      config/install/xhprof.settings.yml、真 TypedConfigManager 解析模块**真的** schema、
 *      真 ConfigFactory 组装，再把 ConfigAdapter 接上去跑。键路径、null→默认值、
 *      不存在的配置对象返回空配置（不抛）、`ImmutableConfigException` 全是真包给的。
 *      不需要引导内核 —— 这几件事在 Drupal 里本来就是 `Drupal\Core\Config` 层的事。
 *      仍然不验的：走 `\Drupal::` 静态容器的那部分（`SchemaCheckTrait::checkConfigSchema()`
 *      里 `TypedData::getConstraints()` 要 `\Drupal::typedDataManager()`，没有引导内核就跑不了，
 *      本卡不伪造容器去糊它）——那句「键集与 schema 一致」改用真包解析出的 schema 定义自己比。
 *   ② 服务串接：真 `ContainerBuilder` + Drupal 自己的 `YamlFileLoader` 读模块**真的**
 *      xhprof.services.yml + 真 `StackedKernelPass` 编译，然后**实跑**真
 *      `StackedHttpKernel`（由 pass 装配出的 `http_kernel` 服务），断言进入顺序。
 *      负对照：把内侧 kernel 的别名写进 arguments 的 yml 会 TypeError（本卡真跑一遍）。
 *   ③ `Drupal\Core\*` 接口与真实包的声明一致性：桩与真实包在**两个子进程**里各自
 *      dump 反射快照后逐字段比（同进程加载 = Cannot declare interface）。
 *      方向是「桩可以更窄，不许更宽、不许幻觉」：桩声明了真实包里没有的方法 = 红。
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

    // 环境闸门：环里没有真实 drupal/core 就整例红，而不是退回去拿本仓库的桩跑。
    // 桩在本进程里**不能**加载（同名接口 = Cannot declare interface 加载期 fatal），
    // 它只作为 L0 对照物在子进程里单独加载（见文件末尾「桩保真」段）。
    if (!interface_exists('Drupal\\Core\\Config\\ConfigFactoryInterface')) {
        return [
            'status' => 'FAIL',
            'detail' => '环里没有真实 drupal/core —— Drupal 侧断言全部未执行。'
                . '先跑 composer install -d tools/contracts（composer.json 已 require drupal/core ^11.4）。',
            'skips' => 0,
        ];
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
    $expectNoThrow = static function (string $label, callable $fn) use (&$checks, &$failures): void {
        $checks++;
        try {
            $fn();
        } catch (\Throwable $e) {
            $failures[] = $label . '：抛了 ' . get_class($e) . '：' . $e->getMessage();
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

    // ================= Drupal 侧装置：真实 config.factory / logger.factory =================
    //
    // 这里全是真包（drupal/core 11.4.x，版本锁在 composer.lock）：真 FileStorage 读模块**真的**
    // config/install/xhprof.settings.yml（按字节拷进临时目录，不重写），真 TypedConfigManager
    // 解析模块**真的** config/schema/xhprof.schema.yml，真 ConfigFactory 组装。
    // schema 存储按 Drupal 的做法聚合：core/config/schema/*.schema.yml 提供 config_object /
    // boolean / integer 这些基础类型，模块自己那份提供 xhprof.settings —— 真站点里这一步由
    // ExtensionInstallStorage 做（它要一个完整的 Drupal 根目录，本卡不伪装站点，故只聚合这两处，
    // 结果等价：只启用 core + xhprof 时聚合出来的就是这两处）。
    $coreRoot = contracts_dir() . '/vendor/drupal/core';
    $tmpRoot = sys_get_temp_dir() . '/contracts-drupal-' . getmypid();
    $mkDir = static function (string $dir): string {
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("建不了临时目录 {$dir}");
        }

        return $dir;
    };

    Drupal\Component\FileCache\FileCacheFactory::setPrefix('contracts-drupal');
    $cfgDir = $mkDir($tmpRoot . '/cfg');
    copy($repoRoot . '/drupal/xhprof/config/install/xhprof.settings.yml', $cfgDir . '/xhprof.settings.yml');
    $schemaDir = $mkDir($tmpRoot . '/schema');
    foreach (glob($coreRoot . '/config/schema/*.schema.yml') as $coreSchema) {
        copy($coreSchema, $schemaDir . '/' . basename($coreSchema));
    }
    copy($repoRoot . '/drupal/xhprof/config/schema/xhprof.schema.yml', $schemaDir . '/xhprof.schema.yml');

    $configStorage = new Drupal\Core\Config\FileStorage($cfgDir);
    $schemaStorage = new Drupal\Core\Config\FileStorage($schemaDir);
    // 第二份配置：ignore_url_arr 清空。用**真实** Yaml::encode 从同一份 install 数据派生，
    // 保证它与源文件同源（源文件改了这里跟着改，不会漂成一个手抄的第二份默认值）。
    $installData = (array) $configStorage->read('xhprof.settings');
    $bareDir = $mkDir($tmpRoot . '/bare');
    $bareData = $installData;
    $bareData['ignore_url_arr'] = [];
    file_put_contents($bareDir . '/xhprof.settings.yml', Drupal\Component\Serialization\Yaml::encode($bareData));
    $bareStorage = new Drupal\Core\Config\FileStorage($bareDir);

    $schemaCache = new Drupal\Core\Cache\NullBackend('typed_config_definitions');
    $classResolver = new Drupal\Core\DependencyInjection\ClassResolver(new Symfony\Component\DependencyInjection\Container());
    $typedConfig = new Drupal\Core\Config\TypedConfigManager(
        $configStorage,
        $schemaStorage,
        $schemaCache,
        new Drupal\Core\Extension\ModuleHandler(
            $coreRoot,
            [],
            new Drupal\Core\KeyValueStore\KeyValueMemoryFactory(),
            new Drupal\Core\Utility\CallableResolver($classResolver),
            $schemaCache
        ),
        $classResolver
    );
    $mkConfigFactory = static fn (Drupal\Core\Config\StorageInterface $storage): Drupal\Core\Config\ConfigFactory => new Drupal\Core\Config\ConfigFactory(
        $storage,
        new Symfony\Component\EventDispatcher\EventDispatcher(),
        $typedConfig
    );
    $factory = $mkConfigFactory($configStorage);
    $bareFactory = $mkConfigFactory($bareStorage);
    // 真 LoggerChannelFactory：LogAdapter::error() 会往通道 'xhprof' 写一条 error 级日志。
    // 这里不注册任何 logger（真站点上 dblog/syslog 才会注册），正是最容易被忽略的那条路径：
    // 「没有 logger 时 error() 会不会炸」——真包的回答是不炸（LoggerChannel 内部对空 logger 集合短路）。
    $loggerFactory = new Drupal\Core\Logger\LoggerChannelFactory(
        new Symfony\Component\HttpFoundation\RequestStack(),
        new Drupal\Core\Session\UserSession()
    );

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

    // Content-Type 必须由 Core 的 MIME 表钉住：交给真实 prepare() 会按**内容**嗅探，本机实测
    // src/html 的 11 个资源里 8 个被猜错，最好复现的例子是 js/dataTables.bootstrap.js → text/html
    // （js 被当成 HTML，浏览器直接拒收脚本；3 个 css 则全被猜成 text/plain）。
    // 未钉时的猜测值只作为观测写进 detail，不冻结成期望（那是 symfony/mime 与**运行环境
    // libmagic 数据库**的行为，换台机器会变）。
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
            $bareFactory,
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

    // ================= 装配文件的静态核对（文件里写了什么） =================
    //
    // 这几条盯的是本卡唯一一个**站点级**事故面：`arguments` 里写内侧 kernel 的别名。
    // ≤11.2（含整个 10.x）没有 `http_middleware_inner` 这个别名 → 编译期
    // ServiceNotFoundException → 容器构建失败、整站白屏；≥11.3 由 StackedKernelPass
    // 自己 setAlias，写了就是重复注入 → 每个请求 TypeError。
    // 两个版本里 pass 都会把内侧 kernel 前插为构造参数 0，所以 yml 只能写自己的额外参数。
    // 静态版看的是**文本**（人去改 yml 时最先撞上的那道），动态版在下面 ② 里真编译真跑。

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

    // ================= Drupal 侧 ①：真实 config.factory / ImmutableConfig / ConfigAdapter =================
    //
    // 装置在文件开头（真 FileStorage 读模块真的 install yml + 真 TypedConfigManager 解析模块真的
    // schema + 真 ConfigFactory）。这里只断言语义。
    // 判别力来自**真包**：桩的 ImmutableConfig 是「构造时塞一个数组、get() 走自己写的点路径」，
    // 真包是「ConfigBase::get() + 类型化配置 + ImmutableConfigException」——下面每条都在真包上跑。

    $config = $factory->get('xhprof.settings');
    $expect(
        '① 真实 config.factory->get() 给出 ImmutableConfig',
        get_class($config),
        'Drupal\Core\Config\ImmutableConfig'
    );
    // 下面这些值全部来自模块**真的** config/install/xhprof.settings.yml（字节拷进临时目录后由真
    // Yaml 解析器读出来的）：类型是 YAML 解出来的 PHP 类型，不是手抄的字面量。
    $expect(
        '① 真实 YAML 解析后的类型：enable 是 bool、time_limit/log_ttl 是 int（不是字符串）',
        [$config->get('enable'), $config->get('time_limit'), $config->get('log_ttl')],
        [true, 0, 604800]
    );
    $expect('① 序列原样是 PHP 列表', $config->get('ignore_url_arr'), ['/xhprof']);
    $expect('① 显式 null 就是 null（ConfigAdapter 的 ?? $default 靠它）', $config->get('auth_token'), null);
    $expect('① 不存在的键给 null 而不是抛', $config->get('nope.deeper'), null);
    $expect('① 配置对象不存在时也是空配置对象，不抛（真 ConfigFactory 的行为）',
        $factory->get('nosuch.config')->get(), []);
    $expect('① 同名两次是同一实例（ConfigFactory 内部静态缓存）', $factory->get('xhprof.settings') === $config, true);

    // 只读性：真包会抛异常；桩里**没有这些方法**（这条只有拿真包才立得起来）
    foreach (['set' => ['a', 1], 'clear' => ['a'], 'save' => [], 'delete' => []] as $mutator => $mutatorArgs) {
        $expectThrows(
            "① 真实 ImmutableConfig::{$mutator}() 抛 ImmutableConfigException（只读契约）",
            Drupal\Core\Config\ImmutableConfigException::class,
            static fn () => $config->{$mutator}(...$mutatorArgs)
        );
    }

    $configAdapter = new ErikWang2013\Xhprof\Drupal\Adapter\ConfigAdapter($factory);
    $expect(
        '① ConfigAdapter 的构造参数就是真实接口（类型写错这里就 TypeError）',
        (string) (new ReflectionParameter([ErikWang2013\Xhprof\Drupal\Adapter\ConfigAdapter::class, '__construct'], 0))->getType(),
        'Drupal\Core\Config\ConfigFactoryInterface'
    );
    $expect('① 契约：get("xhprof") 返回整块，与真实 ImmutableConfig::get() 逐值相同',
        $configAdapter->get('xhprof'), $config->get());
    $expect('① 契约：get("xhprof.assets_url") 走真实点路径到叶子', $configAdapter->get('xhprof.assets_url'), '/xhprof-assets');
    $expect('① 契约：get("xhprof.auth_token", "no") —— 真实 null 触发默认值', $configAdapter->get('xhprof.auth_token', 'no'), 'no');
    $expect('① 契约：get("xhprof.nope", "DEF")', $configAdapter->get('xhprof.nope', 'DEF'), 'DEF');
    $expect('① 契约：配置对象不存在时也返回默认值（靠的是上面那条"空配置对象不抛"）',
        $configAdapter->get('nosuch.config', 'DEF'), 'DEF');

    // 模块真的 schema：真 TypedConfigManager 解析出 mapping，配置里每个键都得有 schema。
    // 缺一个 = 真站点上 drush config:status / ConfigSchemaChecker 报 missing schema（保存被拦）。
    $schemaDef = $typedConfig->getDefinition('xhprof.settings');
    $expect('① 模块真的 config/schema/xhprof.schema.yml 被真包解析出来', $typedConfig->hasConfigSchema('xhprof.settings'), true);
    $schemaKeys = array_keys((array) ($schemaDef['mapping'] ?? []));
    $unschemaed = static fn (array $data): array => array_values(array_diff(array_keys($data), $schemaKeys));
    $expect('① 配置文件的每个键都在 schema 的 mapping 里', $unschemaed($installData), []);
    // 反向不查：schema 的基底 config_object 会补出 _core/langcode（Drupal 的约定，实测那两个就在里面）
    $expect('① 判别式自检：凭空多一个键时上一条会红（证明它不是恒真）', $unschemaed($installData + ['nope_key' => 1]), ['nope_key']);

    // ---- ①b 真实 logger.factory / LoggerChannel ----
    $logAdapter = new ErikWang2013\Xhprof\Drupal\Adapter\LogAdapter($loggerFactory);
    $expect(
        '①b LogAdapter 的构造参数就是真实接口',
        (string) (new ReflectionParameter([ErikWang2013\Xhprof\Drupal\Adapter\LogAdapter::class, '__construct'], 0))->getType(),
        'Drupal\Core\Logger\LoggerChannelFactoryInterface'
    );
    $expect('①b logger.factory->get("xhprof") 给出真实 LoggerChannel',
        get_class($loggerFactory->get('xhprof')), 'Drupal\Core\Logger\LoggerChannel');
    $expect('①b 它是真实 LoggerChannelInterface（PSR-3 的超集）',
        $loggerFactory->get('xhprof') instanceof Drupal\Core\Logger\LoggerChannelInterface, true);
    // 一个 logger 都没注册就是真站点上没装 dblog/syslog 的样子：真包对空 logger 集合短路，不炸。
    $expectNoThrow('①b 一个 logger 都没注册时 LogAdapter::error() 不炸（真站点上最容易漏的那条路）',
        static fn () => $logAdapter->error('contracts probe', ['run' => 'x']));

    // ================= Drupal 侧 ②：真实装配（编译容器 + 真 StackedKernelPass + 真 StackedHttpKernel） =================
    //
    // 真 ContainerBuilder + Drupal 自己的 YamlFileLoader 读模块**真的** xhprof.services.yml，
    // 再跑 core 的编译器 pass（StackedKernelPass）把带 http_middleware 标签的服务串成链。
    // 合成服务用上面那套真 config.factory / 真 logger.factory，于是 handle() 一路跑的是
    // 真中间件 + 真配置 + 真 logger + 真 StackedHttpKernel。

    // 这个 stand-in 必须在闭包**里面**声明：文件顶层的类声明在 vendor/autoload.php 之前执行，
    // 那时 implements HttpKernelInterface 解析不了（加载期 fatal）。放在这里 = 此刻才声明。
    // 它不是"假 Drupal"：它就是本卡自己的一层 core 中间件（priority 400，模拟 D10 的协商层），
    // 用来给「我们的中间件是不是真最外」当参照物。
    class ContractsDrupalCoreStandIn implements Symfony\Component\HttpKernel\HttpKernelInterface
    {
        /** @var list<string> */
        public static array $entered = [];

        public function __construct(private Symfony\Component\HttpKernel\HttpKernelInterface $inner)
        {
        }

        public function handle(
            Symfony\Component\HttpFoundation\Request $request,
            int $type = self::MAIN_REQUEST,
            bool $catch = true
        ): Symfony\Component\HttpFoundation\Response {
            // 进到这里时**本次请求**的适配器该已经 bootstrap 过了（我们那层在最外面）。
            // 记 uri 而不是「有没有 bootstrap」：后者会被前面几条腿留下的全局状态掩盖。
            self::$entered[] = 'core(400) 被进入，此刻适配器的 uri=' . var_export(
                ErikWang2013\Xhprof\Core\Xhprof::getRequest()?->uri(),
                true
            );

            return $this->inner->handle($request, $type, $catch);
        }
    }

    $compileYml = static function (string $ymlPath, ?array &$loadedArgs = null) use (
        $factory,
        $loggerFactory,
        $cache
    ): Drupal\Core\DependencyInjection\ContainerBuilder {
        $container = new Drupal\Core\DependencyInjection\ContainerBuilder();
        $container->setDefinition(
            'http_kernel',
            (new Symfony\Component\DependencyInjection\Definition(Drupal\Core\StackMiddleware\StackedHttpKernel::class))->setPublic(true)
        );
        $container->register('http_kernel.basic')->setSynthetic(true)->setPublic(true);
        $container->register('config.factory')->setSynthetic(true)->setPublic(true);
        $container->register('logger.factory')->setSynthetic(true)->setPublic(true);
        $container->setDefinition('contracts.core_stand_in', (new Symfony\Component\DependencyInjection\Definition(
            ContractsDrupalCoreStandIn::class
        ))->setPublic(true)->addTag('http_middleware', ['priority' => 400]));
        (new Drupal\Core\DependencyInjection\YamlFileLoader($container))->load($ymlPath);
        // compile 之后拿到的定义已经被 pass 改过（内侧 kernel 前插到 0），所以 yml 解出来的
        // 原样参数在这里先留一份给上面的断言用。
        $loadedArgs = $container->getDefinition('xhprof.http_middleware')->getArguments();

        // 第 3 个参数（index 2）是 ?CacheInterface $cache：环里用 FakeCache 替 Redis。
        // 编译时 StackedKernelPass 会把内侧 kernel 前插到 index 0，于是它落到 index 3。
        $container->getDefinition('xhprof.http_middleware')->setArgument(2, $cache);
        $container->addCompilerPass(new Drupal\Core\DependencyInjection\Compiler\StackedKernelPass());
        $container->compile();

        $container->set('config.factory', $factory);
        $container->set('logger.factory', $loggerFactory);
        $container->set('http_kernel.basic', new Symfony\Component\HttpKernel\HttpKernel(
            new Symfony\Component\EventDispatcher\EventDispatcher(),
            new Symfony\Component\HttpKernel\Controller\ControllerResolver(),
            new Symfony\Component\HttpFoundation\RequestStack(),
            new Symfony\Component\HttpKernel\Controller\ArgumentResolver()
        ));

        return $container;
    };

    $ymlArgs = [];
    $container = $compileYml($repoRoot . '/drupal/xhprof/xhprof.services.yml', $ymlArgs);
    $oursDef = $container->getDefinition('xhprof.http_middleware');
    $expect('② yml 解出的定义：类指向本包', $oursDef->getClass(), 'ErikWang2013\Xhprof\Drupal\XhprofMiddleware');
    $expect('② yml 解出来的原样参数就是这两个（内侧 kernel 不由 yml 注入）',
        array_map(static fn ($a) => (string) $a, $ymlArgs),
        ['config.factory', 'logger.factory']);
    $expect('② 标签与优先级由 yml 声明', $oursDef->getTag('http_middleware'), [['priority' => 1000]]);

    $hkArgs = $container->getDefinition('http_kernel')->getArguments();
    $expect('② http_kernel 是真实 StackedHttpKernel 定义',
        $container->getDefinition('http_kernel')->getClass(), 'Drupal\Core\StackMiddleware\StackedHttpKernel');
    // StackedHttpKernel::handle() 交给 arg0 —— 谁是 arg0 谁就是最外层
    $expect('② http_kernel 的第 0 个参数是**我们**（priority 1000 = 最外层）',
        (string) $hkArgs[0], 'xhprof.http_middleware');
    $expect('② 中间件链按优先级降序：我们(1000) → core stand-in(400) → 内核本体',
        array_map(static fn ($v) => (string) $v, $hkArgs[1]->getValues()),
        ['xhprof.http_middleware', 'contracts.core_stand_in', 'http_kernel.basic']);
    $expect('② 链是 IteratorArgument（数组会触发 E_USER_DEPRECATED）',
        get_class($hkArgs[1]), 'Symfony\Component\DependencyInjection\Argument\IteratorArgument');
    // 内侧 kernel 由 pass 前插为构造参数 0 —— 所以 yml 里写它只会撞成重复注入（负对照在下）
    $expect('② 内侧 kernel 由 pass 前插到第 0 个构造参数（yml 里没有它）',
        array_map(
            static fn ($a) => $a instanceof Symfony\Component\DependencyInjection\Reference ? (string) $a : get_debug_type($a),
            $oursDef->getArguments()
        ),
        ['contracts.core_stand_in', 'config.factory', 'logger.factory', 'ErikWang2013\Xhprof\Tests\Fixtures\FakeCache']);
    $expect('② pass 写下的别名 xhprof.http_middleware.http_middleware_inner → 内层 kernel',
        (string) $container->getAlias('xhprof.http_middleware.http_middleware_inner'), 'contracts.core_stand_in');

    // ---- 实跑：真 StackedHttpKernel 走完整条链 ----
    ContractsDrupalCoreStandIn::$entered = [];
    $kernel = $container->get('http_kernel');
    $expect('② 容器 get 出来的 http_kernel 是真实 StackedHttpKernel', get_class($kernel), 'Drupal\Core\StackMiddleware\StackedHttpKernel');
    $containerMw = $container->get('xhprof.http_middleware');
    $expect('② 容器装配出的是本包中间件', get_class($containerMw), 'ErikWang2013\Xhprof\Drupal\XhprofMiddleware');
    $expect('② 注入给我们的内层就是 priority 400 那层（不是内核本体）',
        (new ReflectionProperty($containerMw, 'httpKernel'))->getValue($containerMw) instanceof ContractsDrupalCoreStandIn, true);
    $expect('② 容器装配出的中间件拿到了真 config.factory（内部转成 ConfigAdapter）',
        (new ReflectionProperty($containerMw, 'configFactory'))->getValue($containerMw) === $factory, true);

    $assembled = $mk('/index.php?x=1');
    $assembled->attributes->set('_controller', static function (): Symfony\Component\HttpFoundation\Response {
        xhprof_drupal_marker();

        return new Symfony\Component\HttpFoundation\Response('business-ok');
    });
    $assembledResponse = $kernel->handle($assembled);
    $expect('② 响应穿过整条链原样回来', $assembledResponse->getContent(), 'business-ok');
    // 400 那层进来时看到的 uri 必须是**本次请求**的：它在我们里面 ⇒ 我们先进、先 bootstrap。
    // 若我们被排到里面，这里读到的会是上一次请求残留的 uri（或者 null）——所以这条有判别力。
    $expect(
        '② 进入顺序：core(400) 进来时本次请求的适配器已就绪（证明 1000 真在最外面）',
        ContractsDrupalCoreStandIn::$entered,
        ["core(400) 被进入，此刻适配器的 uri='/index.php?x=1'"]
    );

    // ---- 负对照：把内侧 kernel 的别名写进 arguments 的 yml 会撞成 TypeError ----
    // 这是本卡唯一一个站点级事故面（≤11.2 无此别名→白屏；≥11.3 写了→重复注入）。
    // 静态核对看文本，这里真跑一遍：同一个装置、只把 yml 换成"写错的那份"。
    $badYml = $tmpRoot . '/xhprof.services.yml.bad';
    file_put_contents($badYml, str_replace(
        "arguments: ['@config.factory', '@logger.factory']",
        "arguments: ['@xhprof.http_middleware.http_middleware_inner', '@config.factory', '@logger.factory']",
        (string) file_get_contents($repoRoot . '/drupal/xhprof/xhprof.services.yml')
    ));
    // 只数非注释行：这个文件里本来就有一大段注释**解释**为什么不能写那个别名（注释不算装配）
    $expect('② 负对照的变异体确实落在代码里（否则下面那条会变成"没跑也绿"）',
        substr_count($codeOnly((string) file_get_contents($badYml)), 'http_middleware_inner'), 1);
    $expectThrows(
        '② 负对照：yml 里写了内侧别名时，实例化抛 TypeError（参数 #2 收到的是内核而不是 config.factory）',
        \TypeError::class,
        static fn () => $compileYml($badYml)->get('xhprof.http_middleware')
    );

    // ---- priority 1000 是否真在 core 的中间件之外：拿真 core 的 yml 算一遍 ----
    // 模块注释里写的是「core 现有最高 D10=400 / D11=500」——那句话是**会过期**的，
    // 所以不抄字面量，直接扫真包自己的 services.yml 求最大值。
    // 只扫非测试模块（core 的 *_test 模块里有 priority 1000/404 的测试中间件，不参与真实装配）。
    $coreMiddlewareMax = null;
    $coreMiddlewareOwner = '';
    $coreServicesFiles = glob($coreRoot . '/modules/*/*.services.yml') ?: [];
    $coreServicesFiles[] = $coreRoot . '/core.services.yml';
    foreach ($coreServicesFiles as $servicesFile) {
        if (strpos($servicesFile, '/tests/') !== false) {
            continue;
        }
        $decoded = (array) Drupal\Component\Serialization\Yaml::decode((string) file_get_contents($servicesFile));
        foreach ((array) ($decoded['services'] ?? []) as $serviceId => $serviceDef) {
            foreach ((array) ($serviceDef['tags'] ?? []) as $tag) {
                if (($tag['name'] ?? '') !== 'http_middleware') {
                    continue;
                }
                $priority = (int) ($tag['priority'] ?? 0);
                if ($coreMiddlewareMax === null || $priority > $coreMiddlewareMax) {
                    $coreMiddlewareMax = $priority;
                    $coreMiddlewareOwner = (string) $serviceId;
                }
            }
        }
    }
    $expect('② 判别式自检：真 core 的 yml 里确实扫到了 http_middleware 标签（否则下面那条是空的）',
        $coreMiddlewareMax !== null && $coreMiddlewareOwner !== '', true);
    $expect('② priority 1000 严格大于真 core 非测试模块的最高优先级（它才是真最外）',
        1000 > (int) $coreMiddlewareMax, true);

    // ================= Drupal 侧 ③：桩与真实包的声明一致性（两个子进程） =================
    //
    // 本进程里真 drupal/core 已在 → 桩加载不了（同名接口 = Cannot declare interface），
    // 所以两边各起一个子进程，用**同一段** dump 脚本取反射快照，再逐字段比。
    // 方向：桩可以更窄（真包参数更宽，桩的调用方照样成立），但
    //   - 桩声明了真包里没有的方法 → 红（单测会在真站上不存在的 API 上跑绿）
    //   - 桩的参数类型比真包**宽** → 红（桩允许的值真包不接）
    //   - 桩给了默认值而真包那个参数必填 → 红
    //   - 真包返回值不在桩声明的返回类型里 → 红
    // 类的 __construct 不参与比较（桩的构造器是夹具口径：`new ImmutableConfig($data)` 在真包里
    // 不存在），这条已知差异单独钉在下面，桩/真包任一侧变了都会红。
    $stubFile = $repoRoot . '/tests/Stubs/Framework/Drupal.php';
    $drupalTypes = [
        'Drupal\Core\Config\ConfigFactoryInterface',
        'Drupal\Core\Config\ImmutableConfig',
        'Drupal\Core\Logger\LoggerChannelFactoryInterface',
        'Drupal\Core\Logger\LoggerChannelInterface',
    ];
    $dumpScript = <<<'PHP'
$names = json_decode($argv[1], true);
$out = ['missing' => [], 'types' => []];
foreach ($names as $name) {
    if (!interface_exists($name) && !class_exists($name)) { $out['missing'][] = $name; continue; }
    $rc = new ReflectionClass($name);
    $methods = [];
    foreach ($rc->getMethods() as $m) {
        $params = [];
        foreach ($m->getParameters() as $p) {
            $params[] = [
                'type' => $p->getType() === null ? null : (string) $p->getType(),
                'hasDefault' => $p->isDefaultValueAvailable(),
                'default' => $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : null,
                'byRef' => $p->isPassedByReference(),
                'variadic' => $p->isVariadic(),
            ];
        }
        $methods[$m->getName()] = ['return' => $m->getReturnType() === null ? null : (string) $m->getReturnType(), 'params' => $params];
    }
    ksort($methods);
    $out['types'][$name] = ['kind' => $rc->isInterface() ? 'interface' : 'class', 'methods' => $methods];
}
echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
PHP;

    $typesJson = json_encode($drupalTypes, JSON_UNESCAPED_SLASHES);
    $stubRun = contracts_run_php(['-r', 'require ' . var_export($stubFile, true) . ";\n" . $dumpScript, $typesJson]);
    $realRun = contracts_run_php([
        '-r',
        'require ' . var_export(contracts_dir() . '/vendor/autoload.php', true) . ";\n" . $dumpScript,
        $typesJson,
    ]);

    $snapshotOf = static function (array $run, string $side) use (&$failures, &$checks): array {
        $checks++;
        $decoded = json_decode(trim((string) $run['stdout']), true);
        if (!is_array($decoded) || ($decoded['missing'] ?? []) !== []) {
            $failures[] = "③ {$side}反射子进程没有给出完整快照（exit {$run['code']}）："
                . trim((string) ($run['stderr'] !== '' ? $run['stderr'] : $run['stdout']));

            return [];
        }

        return $decoded['types'];
    };
    $stubSnapshot = $snapshotOf($stubRun, '桩侧');
    $realSnapshot = $snapshotOf($realRun, '真实包侧');

    if ($stubSnapshot !== [] && $realSnapshot !== []) {
        $stubDiffs = contracts_drupal_stub_vs_real($stubSnapshot, $realSnapshot);
        $expect(
            '③ 桩的声明真包全认（桩不许更宽、不许幻觉）',
            implode(' | ', $stubDiffs),
            ''
        );

        // 桩没声明、真包有的那些方法：桩是刻意最小化的，只记数不上红（避免把"桩不够全"当成契约失败，
        // 但把盲区量出来，谁要补桩时有据可依）。
        $realOnly = [];
        foreach ($realSnapshot as $type => $realType) {
            $realOnly[$type] = count(array_diff_key($realType['methods'], $stubSnapshot[$type]['methods'] ?? []));
        }
        $blindSpots = array_sum($realOnly);

        // 已知差异：ImmutableConfig 的构造器。真包要 4 参（$name, StorageInterface, EventDispatcherInterface,
        // TypedConfigManagerInterface），桩是 `__construct(array $data = [])` —— 夹具口径，本卡从不用它
        // new 真类（真类一律由真 ConfigFactory 造）。钉住这个差异：两边谁变了都得有人再看一眼。
        $expect('③ 已知差异仍成立：ImmutableConfig 构造器真包 4 参 / 桩 1 参（桩是夹具口径）',
            [count($realSnapshot['Drupal\Core\Config\ImmutableConfig']['methods']['__construct']['params']),
                count($stubSnapshot['Drupal\Core\Config\ImmutableConfig']['methods']['__construct']['params'])],
            [4, 1]);

        // 判别式自检：把桩的快照故意改坏，上面那个比较器必须报出来（每条规则各来一发）。
        $probe = $stubSnapshot;
        $probe['Drupal\Core\Config\ConfigFactoryInterface']['methods']['getEditable'] = $probe['Drupal\Core\Config\ConfigFactoryInterface']['methods']['get'];
        unset($probe['Drupal\Core\Config\ConfigFactoryInterface']['methods']['getEditable']['params'][0]['type']);
        $probe['Drupal\Core\Config\ConfigFactoryInterface']['methods']['getEditable']['params'][0]['hasDefault'] = true;   // 桩给了默认值、真包必填
        $probe['Drupal\Core\Config\ImmutableConfig']['methods']['getNope'] = $probe['Drupal\Core\Config\ImmutableConfig']['methods']['get'];   // 幻觉方法
        $probe['Drupal\Core\Config\ImmutableConfig']['methods']['get']['params'][] = ['type' => 'string', 'hasDefault' => false, 'default' => null, 'byRef' => false, 'variadic' => false];   // 真包参数更少
        $probe['Drupal\Core\Logger\LoggerChannelInterface']['methods']['error']['params'][0]['type'] = 'string|int';   // 桩更宽
        $probe['Drupal\Core\Logger\LoggerChannelInterface']['methods']['error']['return'] = 'string';   // 真包返回 void 不在桩的返回类型里
        $probeDiffs = contracts_drupal_stub_vs_real($probe, $realSnapshot);
        $probeText = implode("\n", $probeDiffs);
        $expect('③ 判别式自检：5 处故意改坏的声明，比较器一处不漏地报出来', count($probeDiffs), 5);
        $expect(
            '③ 判别式自检：报出来的话里点名了具体位置',
            [
                str_contains($probeText, 'getEditable'),
                str_contains($probeText, 'getNope'),
                str_contains($probeText, 'LoggerChannelInterface::error'),
            ],
            [true, true, true]
        );
    } else {
        $blindSpots = 0;
    }

    $skips += 0;
    $skipNote = 'skips=0：原先的三条（真实 config.factory 语义 / 服务串接 / 桩保真）已全部换成真断言'
        . '（环里锁了 drupal/core ^11.4）';

    $note = 'ext-xhprof ' . ($ext ? '已加载（采样类断言全部真跑）' : '**未加载**（采样类断言计入 skips，非静默通过）')
        . '；Drupal 侧用真实 drupal/core ' . Drupal::VERSION . '（config.factory / logger.factory / '
        . 'StackedHttpKernel / StackedKernelPass 全是真包；桩只在 ③ 的子进程里作为对照物加载）'
        . '；③ 桩未覆盖的真包方法共 ' . $blindSpots . ' 个（桩刻意最小化，只记数不上红）'
        . '；core 非测试模块最高 http_middleware 优先级 = ' . var_export($coreMiddlewareMax, true)
        . '（' . $coreMiddlewareOwner . '）'
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

/**
 * 桩的反射快照 vs 真实包的反射快照：返回「桩说的、真包不认」的清单（空数组 = 全认）。
 *
 * 方向（判据是「拿桩写的代码在真站上还成不成立」）：
 *   - 参数类型：真包必须**接得住**桩声明的类型（桩 ⊆ 真包）。真包更宽是允许的（真包没声明类型 = 最宽）。
 *   - 返回类型：真包必须**落在**桩声明的返回类型里（真包 ⊆ 桩）；桩没声明 = 不约束。
 *   - 参数个数：真包不能比桩少（按桩的写法传齐会 ArgumentCountError）。
 *   - 默认值：桩给了默认值而真包那个参数必填 → 按桩的写法少传一个会炸。
 *   - byRef/variadic 必须一致。
 *   - 桩声明了真包里没有的方法/类型 → 红（幻觉 API）。
 * 类的 `__construct` 不参与比较：桩的构造器是夹具口径（真类一律由真包的工厂造，见 case 里钉住的那条差异）。
 *
 * @param array<string, array{kind: string, methods: array<string, array{return: string|null, params: list<array<string, mixed>>}>}> $stub
 * @param array<string, array{kind: string, methods: array<string, array{return: string|null, params: list<array<string, mixed>>}>}> $real
 *
 * @return list<string>
 */
function contracts_drupal_stub_vs_real(array $stub, array $real): array
{
    $bad = [];
    foreach ($stub as $type => $stubType) {
        if (!array_key_exists($type, $real)) {
            $bad[] = "{$type}: 真包里没有这个类型";
            continue;
        }
        $realType = $real[$type];
        if ($stubType['kind'] !== $realType['kind']) {
            $bad[] = "{$type}: 桩声明成 {$stubType['kind']}，真包是 {$realType['kind']}";
        }
        foreach ($stubType['methods'] as $method => $stubMethod) {
            if (!array_key_exists($method, $realType['methods'])) {
                $bad[] = "{$type}::{$method}(): 真包里没有这个方法（幻觉 API）";
                continue;
            }
            if ($method === '__construct' && $stubType['kind'] === 'class') {
                continue;
            }
            $realMethod = $realType['methods'][$method];
            if (count($realMethod['params']) < count($stubMethod['params'])) {
                $bad[] = "{$type}::{$method}(): 真包只收 " . count($realMethod['params'])
                    . ' 个参数，桩声明了 ' . count($stubMethod['params']);
            }
            foreach ($stubMethod['params'] as $index => $stubParam) {
                $realParam = $realMethod['params'][$index] ?? null;
                if ($realParam === null) {
                    continue;
                }
                if ($stubParam['type'] !== null && $realParam['type'] !== null
                    && !contracts_drupal_type_subset((string) $stubParam['type'], (string) $realParam['type'])) {
                    $bad[] = "{$type}::{$method}() 参数 #{$index}：桩声明 {$stubParam['type']}，"
                        . "真包只收 {$realParam['type']}（桩更宽）";
                }
                if ($stubParam['hasDefault'] && !$realParam['hasDefault'] && !$realParam['variadic']) {
                    $bad[] = "{$type}::{$method}() 参数 #{$index}：桩给了默认值，真包是必填";
                }
                if ($stubParam['byRef'] !== $realParam['byRef'] || $stubParam['variadic'] !== $realParam['variadic']) {
                    $bad[] = "{$type}::{$method}() 参数 #{$index}：byRef/variadic 不一致（桩 "
                        . var_export($stubParam['byRef'], true) . '/' . var_export($stubParam['variadic'], true)
                        . '，真包 ' . var_export($realParam['byRef'], true) . '/' . var_export($realParam['variadic'], true) . '）';
                }
            }
            if ($stubMethod['return'] !== null && $realMethod['return'] !== null
                && !contracts_drupal_type_subset((string) $realMethod['return'], (string) $stubMethod['return'])) {
                $bad[] = "{$type}::{$method}(): 真包返回 {$realMethod['return']}，"
                    . "不在桩声明的 {$stubMethod['return']} 里";
            }
        }
    }

    return $bad;
}

/**
 * 类型集合的包含判断：$inner 的每个原子类型都在 $outer 里。
 * 只处理 `A|B` 与 `?A` 两种写法（Drupal 侧这几个声明没有交叉类型），`mixed`/空 = 最宽。
 */
function contracts_drupal_type_subset(string $inner, string $outer): bool
{
    $atoms = static function (string $type): array {
        $type = trim($type);
        if ($type === '' || $type === 'mixed') {
            return ['mixed'];
        }
        $parts = array_map('trim', explode('|', str_replace('?', '', $type)));
        if (str_contains($type, '?')) {
            $parts[] = 'null';
        }

        return array_values(array_unique($parts));
    };
    $outerAtoms = $atoms($outer);
    if (in_array('mixed', $outerAtoms, true)) {
        return true;
    }
    $innerAtoms = $atoms($inner);
    if (in_array('mixed', $innerAtoms, true)) {
        return false;   // 桩没类型（= 什么都收）而真包有类型 → 桩更宽
    }
    foreach ($innerAtoms as $atom) {
        if (!in_array($atom, $outerAtoms, true)) {
            return false;
        }
    }

    return true;
}
