<?php

declare(strict_types=1);

/**
 * ThinkPHP 契约卡 —— 用**真实** topthink/framework 验证 src/Thinkphp/**。
 *
 * 版本：topthink/framework v8.1.4（纯 PHP，无扩展门槛：require 只有 php>=8.0 + ext-{ctype,json,mbstring}，
 * 不碰 swoole —— 这也是它进环、Hyperf 不进环的实测分界，见报告）。
 *
 * 本卡钉的三件事：
 *   1) `think\Request::host(bool $strict = false)` 的两种调用：真包实测 host() = 'example.com:8080'、
 *      host(true) = 'example.com'，**本仓适配器必须传 true**（契约 R-2，与 Webman 卡同一坑、同一理由：
 *      src/Thinkphp/Adapter/RequestAdapter.php:34 的注释就写在调用点上）。
 *   2) `think\Response` 的**就地**语义：content()/header()/code() 都改 `$this` 并返回它。
 *      本仓 ResponseAdapter 依赖它（先设头再设状态时头不能丢），且 header() 是 array_merge
 *      —— 同名头再设一次是**覆盖**（webman 那边是 array_merge_recursive，会并成数组，两家别抄串）。
 *   3) `param()` 的合并口径：真包把 param + route + get + **post** 合并，**请求体赢过 query**
 *      （webman 是 query 赢）。适配器的 get()/all() 全走 param()，差一个优先级就是报告页数据出错。
 *
 * ---------------------------------------------------------------------------------------
 * 一个必须记下来的环结构事实（与 Webman 卡同型，但触发方式不同）：
 * **think 的全局助手（`response()`/`config()`/`app()`…）不在 composer 的 files 自动加载里。**
 * topthink/framework 自己的 composer.json 是 `"autoload": {"files": []}`（实测 installed.json），
 * 真应用是 `think\App::load()` 里 `include_once $this->thinkPath . 'helper.php'` 加载的
 * （src/think/App.php:540）。而本环 vendor 里 laravel/framework 的
 * `Illuminate/Foundation/helpers.php` 先被 composer 加载并占掉了 `response()`/`config()`
 * （本卡在 case 进程里用断言钉住这个事实），于是**在环进程里哪怕真 boot 一个 think\App，
 * `response()` 也还是 laravel 的**（helper.php 里每个函数都有 `function_exists()` 守卫）——
 * `Thinkphp\Middleware::xhprofAdapters()` 自己就在调 `response('')`，所以整条中间件链路在共享
 * 进程里都跑不出真语义。
 * 处理办法与 Webman 卡相同：加一个**干净子进程**，先 `require` 真包的 helper.php，再 `require`
 * 环的 autoloader —— 加载顺序回到"只装了 thinkphp 的应用"那一档。跑的是真包的真 helper 文件，
 * 被测适配器一行未改；子进程里有正对照断言钉住 `response()` 确实归 think。
 * ---------------------------------------------------------------------------------------
 *
 * 覆盖到哪一层（诚实边界）：
 *   - 覆盖：L1 真包签名/继承面（含门面 `think\facade\*` 经 `Facade::__callStatic` 转发到
 *     `think\Config`/`think\Log`/`think\Cache` 这条链）；L0 桩保真（本包 tests/Stubs 的 think 段
 *     与真包**逐字段**比，差异集按版本冻结）；L2 真对象语义（Request/Response/Config/Log/RedisAdapter
 *     + `Xhprof::bootstrap()` 灌静态量 + 整条中间件：业务放行、报告页短路、资源短路、`..` 越界、
 *     一次采样在 Redis 上恰好留下 3 个键）；降级边界（think 没配 redis store / store 名叫 redis
 *     但驱动不是 redis）。
 *   - **不**覆盖：真 HTTP 往返（`think\App::http->run()`，要 SAPI）；`think\App` 的完整启动
 *     （队列/事件/路由服务）。探针用的是真 `think\App` + 真配置文件 + 真 `think\Request`，
 *     但请求是构造出来的，不是从 php://input 解析的。
 *   - **不**覆盖：`TrustedProxy`/`HTTP_X_FORWARDED_HOST` 分支（Request::host() 会先看 XFF_HOST，
 *     探针的 `withServer()` 没有这个头，走的是 HTTP_HOST 那条）—— 判据已在 L1 用真类反射钉住。
 *
 * 本环前提（缺了就是 FAIL，不是 SKIP）：ext-xhprof + ext-redis + 一个可连的 Redis。
 * "一次采样留 3 个键"这条只有真的起采样才成立；缺扩展时 SamplingGuard 会跳过采样，
 * 那是**环境**没到位，不是本仓行为，所以在这里响亮 FAIL。
 */

use ErikWang2013\Xhprof\Thinkphp\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Thinkphp\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Thinkphp\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Thinkphp\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Thinkphp\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Thinkphp\Middleware;

/**
 * 冻结：桩 ↔ 真包的配对（`桩里的类 => 真包里承载同一面的类`），成员只列
 * `src/Thinkphp/**` **真正调用到**的那些（调用点见各 adapter；多一个都是没依据的）。
 *
 * 三对不是同名映射，理由：
 *   - 门面：桩把 `think\facade\{Config,Log,Cache}` 直接写成真静态方法，真包这三个类
 *     **一个自己的方法都没有**（实测 `method_exists(think\facade\Cache::class,'store') === false`，
 *     走 `think\Facade::__callStatic` → 容器 → `think\Config`/`think\Log`/`think\Cache`），
 *     所以真实侧要比的是转发目标类。L1 里把「门面 → 转发目标」这条链单独钉住。
 *   - 缓存仓库：桩的 `think\CacheStore` 是桩自造的类名；真包 `Cache::store()` 给的是
 *     `think\cache\driver\Redis`（实测），方法 `handler()` 声明在它自己，
 *     get/set/inc 声明在父类 `think\cache\Driver`（反射 getDeclaringClass 实测）。
 *   - 底层句柄：桩的 `think\ThinkFakeHandler` 是 phpredis `\Redis` 的替身
 *     （适配器在 `handler instanceof \Redis` 时走裸 phpredis 面）。
 *
 * @var array<string, array{real: string, members: list<string>}>
 */
const THINKPHP_MEMBER_PAIRS = [
    'think\\Request' => ['real' => 'think\\Request', 'members' => ['param', 'method', 'header', 'host', 'url', 'ip']],
    'think\\Response' => ['real' => 'think\\Response', 'members' => ['content', 'header', 'code', 'getHeader', 'getContent', 'getCode']],
    'think\\facade\\Config' => ['real' => 'think\\Config', 'members' => ['get']],
    'think\\facade\\Log' => ['real' => 'think\\Log', 'members' => ['error']],
    'think\\facade\\Cache' => ['real' => 'think\\Cache', 'members' => ['store']],
    'think\\CacheStore' => ['real' => 'think\\cache\\driver\\Redis', 'members' => ['handler', 'get', 'set', 'inc']],
    'think\\ThinkFakeHandler' => ['real' => 'Redis', 'members' => ['mget', 'lPush', 'rPop', 'lRange', 'del', 'decr']],
];

/**
 * 冻结：桩 vs 真包的**差异集**（由 thinkphp_member_diff() 生成，逐字相等 —— 多一条少一条都 FAIL）。
 * 2026-10-04 于 topthink/framework v8.1.4 实测冻结，共 58 条，**零成员缺失**（7 对里的成员两边都有，
 * 差的只是描述器字段）。五个家族，方向都用「桩可更窄、不可更宽」过了一遍：
 *
 *   1) 参数**改名/加类型**（最多的一族）：桩写 `$key`、真包写 `$name`/`$expire`/`$elements`/`$complete`；
 *      桩写 `string`/`int`/`mixed`，真包不声明类型。方向：真包更松，桩上绿的调用真包也接受。
 *   2) 桩**少声明可选参数**（`param.params.2`、`rPop.params.1`、`decr.params.1`、`method.params.0`
 *      只在真实包里存在）：真包接受而桩不接受的调用，本仓一次都没有（adapter 的调用点见 L1 列表）。
 *   3) 桩把门面写成了**真静态方法**（`static: 桩=true 真实=false`，返回类型 `think\CacheStore`）：
 *      真包走 `Facade::__callStatic` 转发，L1 单独钉了这条链。
 *   4) 真 phpredis 的返回类型是**并集**（`Redis|int|false`、`Redis|array|false`），桩写成具体类型：
 *      真包更宽 —— 适配器不能假定返回类型，这也是它为什么对 `mget` 的 false 占位有专门处理。
 *   5) **两处"桩更宽"，留着但必须签字**（桩不在本卡的围栏里，只记录不修）：
 *      a. `ThinkFakeHandler::del`：桩是 `del(mixed ...$keys)`（0 个参数也能调），真 phpredis 是
 *         `del(array|string $key, string ...$other_keys)`（至少 1 个）→ 桩上绿、真包上
 *         ArgumentCountError。本卡实测踩到过一次（清理逻辑里 `del(...$keys)` 空数组），
 *         探针因此改成"先看 keys 非空再 del"—— 这条差异是**活着**的教训，不是纸面条款。
 *      b. `CacheStore::set` 第三个参数默认值：桩 `$ttl = 0`、真包 `$expire = null`。
 *         调用点（RedisAdapter::set）只在 TTL > 0 时传第三个参数，两边语义都是"不设过期"，
 *         但**默认值本身**是桩给的、真包没有 —— 谁要是把 0 当"显式 TTL=0"传下去就会露馅。
 *
 * 冻结方式：任一侧签名一变（桩改了、或环里 topthink 升版），这条清单就对不上 → FAIL →
 * 人重看一遍再改这里。这是本卡除 L1/L2 之外唯一会因依赖升级而红的闸门。
 *
 * @var list<string>
 */
const THINKPHP_EXPECTED_DIFFS = [
    'think\\CacheStore => think\\cache\\driver\\Redis::get.params.0.name: 桩="key" 真实="name"',
    'think\\CacheStore => think\\cache\\driver\\Redis::get.params.0.type: 桩="string" 真实=""',
    'think\\CacheStore => think\\cache\\driver\\Redis::get.params.1: 只在真实包里存在',
    'think\\CacheStore => think\\cache\\driver\\Redis::handler.return: 桩="object" 真实=""',
    'think\\CacheStore => think\\cache\\driver\\Redis::inc.params.0.name: 桩="key" 真实="name"',
    'think\\CacheStore => think\\cache\\driver\\Redis::inc.params.0.type: 桩="string" 真实=""',
    'think\\CacheStore => think\\cache\\driver\\Redis::inc.params.1: 只在真实包里存在',
    'think\\CacheStore => think\\cache\\driver\\Redis::inc.return: 桩="int" 真实=""',
    'think\\CacheStore => think\\cache\\driver\\Redis::set.params.0.name: 桩="key" 真实="name"',
    'think\\CacheStore => think\\cache\\driver\\Redis::set.params.0.type: 桩="string" 真实=""',
    'think\\CacheStore => think\\cache\\driver\\Redis::set.params.1.type: 桩="mixed" 真实=""',
    'think\\CacheStore => think\\cache\\driver\\Redis::set.params.2.default: 桩="0" 真实="NULL"',
    'think\\CacheStore => think\\cache\\driver\\Redis::set.params.2.name: 桩="ttl" 真实="expire"',
    'think\\CacheStore => think\\cache\\driver\\Redis::set.params.2.type: 桩="int" 真实=""',
    'think\\CacheStore => think\\cache\\driver\\Redis::set.return: 桩="mixed" 真实="bool"',
    'think\\Request => think\\Request::header.params.0.default: 桩=null 真实="\'\'"',
    'think\\Request => think\\Request::header.params.0.optional: 桩=false 真实=true',
    'think\\Request => think\\Request::header.params.1.type: 桩="mixed" 真实="?string"',
    'think\\Request => think\\Request::header.required: 桩=1 真实=0',
    'think\\Request => think\\Request::header.return: 桩="?string" 真实=""',
    'think\\Request => think\\Request::method.params.0: 只在真实包里存在',
    'think\\Request => think\\Request::param.params.0.name: 桩="key" 真实="name"',
    'think\\Request => think\\Request::param.params.0.type: 桩="string" 真实=""',
    'think\\Request => think\\Request::param.params.1.type: 桩="mixed" 真实=""',
    'think\\Request => think\\Request::param.params.2: 只在真实包里存在',
    'think\\Request => think\\Request::param.return: 桩="mixed" 真实=""',
    'think\\Request => think\\Request::url.params.0.name: 桩="full" 真实="complete"',
    'think\\ThinkFakeHandler => Redis::decr.params.1: 只在真实包里存在',
    'think\\ThinkFakeHandler => Redis::decr.return: 桩="int" 真实="Redis|int|false"',
    'think\\ThinkFakeHandler => Redis::del.params.0.name: 桩="keys" 真实="key"',
    'think\\ThinkFakeHandler => Redis::del.params.0.optional: 桩=true 真实=false',
    'think\\ThinkFakeHandler => Redis::del.params.0.type: 桩="mixed" 真实="array|string"',
    'think\\ThinkFakeHandler => Redis::del.params.1: 只在真实包里存在',
    'think\\ThinkFakeHandler => Redis::del.required: 桩=0 真实=1',
    'think\\ThinkFakeHandler => Redis::del.return: 桩="int" 真实="Redis|int|false"',
    'think\\ThinkFakeHandler => Redis::lPush.params.1.name: 桩="value" 真实="elements"',
    'think\\ThinkFakeHandler => Redis::lPush.params.1.optional: 桩=false 真实=true',
    'think\\ThinkFakeHandler => Redis::lPush.required: 桩=2 真实=1',
    'think\\ThinkFakeHandler => Redis::lPush.return: 桩="int" 真实="Redis|int|false"',
    'think\\ThinkFakeHandler => Redis::lRange.return: 桩="array" 真实="Redis|array|false"',
    'think\\ThinkFakeHandler => Redis::mget.return: 桩="array" 真实="Redis|array|false"',
    'think\\ThinkFakeHandler => Redis::rPop.params.1: 只在真实包里存在',
    'think\\ThinkFakeHandler => Redis::rPop.return: 桩="mixed" 真实="Redis|array|string|bool"',
    'think\\facade\\Cache => think\\Cache::store.params.0.default: 桩="\'redis\'" 真实="NULL"',
    'think\\facade\\Cache => think\\Cache::store.params.0.type: 桩="string" 真实="?string"',
    'think\\facade\\Cache => think\\Cache::store.return: 桩="think\\\\CacheStore" 真实=""',
    'think\\facade\\Cache => think\\Cache::store.static: 桩=true 真实=false',
    'think\\facade\\Config => think\\Config::get.params.0.default: 桩=null 真实="NULL"',
    'think\\facade\\Config => think\\Config::get.params.0.name: 桩="key" 真实="name"',
    'think\\facade\\Config => think\\Config::get.params.0.optional: 桩=false 真实=true',
    'think\\facade\\Config => think\\Config::get.params.0.type: 桩="string" 真实="?string"',
    'think\\facade\\Config => think\\Config::get.params.1.type: 桩="mixed" 真实=""',
    'think\\facade\\Config => think\\Config::get.required: 桩=1 真实=0',
    'think\\facade\\Config => think\\Config::get.return: 桩="mixed" 真实=""',
    'think\\facade\\Config => think\\Config::get.static: 桩=true 真实=false',
    'think\\facade\\Log => think\\Log::error.params.0.type: 桩="string" 真实="Stringable|string"',
    'think\\facade\\Log => think\\Log::error.params.1: 只在真实包里存在',
    'think\\facade\\Log => think\\Log::error.static: 桩=true 真实=false',
];

/**
 * 逐字段递归比两份描述器的快照，差异写进 &$out
 * （形如 `think\Request => think\Request::param.params.0.name: 桩="key" 真实="name"`）。
 *
 * 与 cases/Joomla.php 的同名函数同型（那里叫 joomla_member_diff），刻意不跨 case 复用：
 * case 是一个个独立子进程跑的，跨文件 require 会把两张卡耦在一起，代价是这 15 行。
 *
 * @param array<mixed> $a
 * @param array<mixed> $b
 * @param list<string> $out
 */
function thinkphp_member_diff(array $a, array $b, string $path, array &$out): void
{
    foreach ($a as $key => $value) {
        $where = $path . '.' . $key;
        if (!array_key_exists($key, $b)) {
            $out[] = "{$where}: 只在桩里存在";
            continue;
        }
        if (is_array($value)) {
            thinkphp_member_diff($value, $b[$key], $where, $out);
        } elseif ($value !== $b[$key]) {
            $out[] = "{$where}: 桩=" . json_encode($value, JSON_UNESCAPED_SLASHES)
                . ' 真实=' . json_encode($b[$key], JSON_UNESCAPED_SLASHES);
        }
    }
    foreach ($b as $key => $value) {
        if (!array_key_exists($key, $a)) {
            $out[] = $path . '.' . $key . ': 只在真实包里存在';
        }
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

    // 本包 src/ 的 PSR-4 自注册 —— 刻意**不**依赖仓库根的 vendor/autoload.php（CI 只 install
    // tools/contracts，主仓库 dev vendor 不存在；依赖它会在加载期崩溃）。与 Webman.php 同一手法。
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

    // ================= 本环前提（缺了就是 FAIL，不是 SKIP）=================
    $expect('前提 ext-xhprof 已装（采样闸门 SamplingGuard::available 要它）', extension_loaded('xhprof'), true);
    $expect('前提 ext-redis 已装', extension_loaded('redis'), true);
    $expect('前提 \Redis 类可用', class_exists('Redis'), true);

    // ================= ① L1：真包签名 / 继承面（case 进程，不 boot 应用）=================
    //
    // 列的都是 src/Thinkphp/** 与 Core 真正调用到的东西；缺一个就是 "Call to undefined method"。
    $l1 = [
        'think\\Request' => ['param', 'method', 'header', 'host', 'url', 'ip'],
        'think\\Response' => ['content', 'header', 'code', 'getHeader', 'getContent', 'getCode'],
        'think\\Config' => ['get'],
        'think\\Log' => ['error'],
        'think\\Cache' => ['store'],
        'think\\cache\\driver\\Redis' => ['handler', 'get', 'set', 'inc'],
        'think\\cache\\Driver' => ['get', 'set', 'inc'],
        'Redis' => ['mget', 'lPush', 'rPop', 'lRange', 'del', 'decr', 'get', 'set', 'incr'],
    ];
    foreach ($l1 as $class => $methods) {
        $exists = class_exists($class);
        $expect("L1 {$class} 存在", $exists, true);
        if (!$exists) {
            continue;
        }
        $rc = new \ReflectionClass($class);
        foreach ($methods as $method) {
            $expect("L1 {$class}::{$method} 存在", $rc->hasMethod($method), true);
        }
    }

    // 继承面：三处「真包不是你以为的那个类」，L2/diff 的真实侧选型全靠它们。
    $expect('L1 think\Response 是 abstract（应用拿到的是 think\response\Html）', (new \ReflectionClass(\think\Response::class))->isAbstract(), true);
    $expect('L1 think\response\Html 的父类是 think\Response', get_parent_class(\think\response\Html::class), 'think\Response');
    $expect('L1 think\Cache 的父类是 think\Manager', get_parent_class(\think\Cache::class), 'think\Manager');
    $expect('L1 think\cache\driver\Redis 的父类是 think\cache\Driver（get/set/inc 声明在这里）', get_parent_class(\think\cache\driver\Redis::class), 'think\cache\Driver');
    $expect('L1 think\Log 的父类是 think\Manager', get_parent_class(\think\Log::class), 'think\Manager');
    // think\Facade 本体在 topthink/think-container（transitive 依赖）里 —— 不是 framework 自己。
    $expectContains('L1 think\Facade 来自 topthink/think-container', (string) (new \ReflectionClass(\think\Facade::class))->getFileName(), 'topthink/think-container');

    // 门面链：适配器写的是 `Config::get()`/`Cache::store()` 这种静态调用，
    // 真包里它是 __callStatic → 容器 → 转发目标类。
    foreach ([
        'Config' => ['accessor' => 'config', 'business' => 'get', 'real' => 'think\\Config'],
        'Log' => ['accessor' => 'log', 'business' => 'error', 'real' => 'think\\Log'],
        'Cache' => ['accessor' => 'cache', 'business' => 'store', 'real' => 'think\\Cache'],
    ] as $short => $info) {
        $facade = 'think\\facade\\' . $short;
        $expect("L1 {$facade} 是 think\Facade 的子类", is_subclass_of($facade, \think\Facade::class), true);
        // 业务方法**不是真方法**（method_exists 为 false，实测）：静态调用必须经 __callStatic 转发，
        // 所以真实侧要比的是转发目标类 —— 这是 THINKPHP_MEMBER_PAIRS 里三对门面非同名的依据。
        $expect("L1 {$facade}::{$info['business']} 走 __callStatic（不是真方法）", method_exists($facade, $info['business']), false);
        $expect("L1 {$info['real']}::{$info['business']} 是真方法（转发目标）", method_exists($info['real'], $info['business']), true);
        $expect(
            "L1 {$facade} 的 __callStatic 声明在 think\Facade 上",
            (new \ReflectionMethod($facade, '__callStatic'))->getDeclaringClass()->getName(),
            'think\Facade'
        );
        // getFacadeClass() 是 protected static（实测），反射调它拿到容器标识。
        $expect("L1 {$facade} 的容器标识", (new \ReflectionMethod($facade, 'getFacadeClass'))->invoke(null), $info['accessor']);
    }

    // 环里的助手归属：这三条断言解释"为什么整条链路必须进干净子进程"。
    $expectContains(
        'L1 本环 response() 归 laravel/framework（think 的助手在共享进程里被遮住）',
        (string) (new \ReflectionFunction('response'))->getFileName(),
        'laravel/framework'
    );
    $expectContains(
        'L1 本环 config() 归 laravel/framework',
        (string) (new \ReflectionFunction('config'))->getFileName(),
        'laravel/framework'
    );
    $autoloadFiles = require contracts_dir() . '/vendor/composer/autoload_files.php';
    $entries = array_values($autoloadFiles);
    $laravelIdx = array_search(
        contracts_dir() . '/vendor/laravel/framework/src/Illuminate/Foundation/helpers.php',
        $entries,
        true
    );
    $webmanIdx = array_search(
        contracts_dir() . '/vendor/workerman/webman-framework/src/support/helpers.php',
        $entries,
        true
    );
    $expectContains('L1 composer files 里有 laravel 的 helpers.php', is_int($laravelIdx) ? 'yes' : 'no', 'yes');
    $expectContains('L1 composer files 里有 webman 的 helpers.php（两家抢同一个 response()）', is_int($webmanIdx) ? 'yes' : 'no', 'yes');
    $expect('L1 laravel 的 helpers 排在 webman 之前（先到先得，谁在前谁赢）', is_int($laravelIdx) && is_int($webmanIdx) && $laravelIdx < $webmanIdx, true);
    // think 的 helper.php **不在** files 自动加载里（真应用由 App::load() 在 App.php:540 include 它）。
    // 这条是"环进程里 think 的助手永远不会赢"的结构性理由，不是巧合。
    $thinkHelperEntries = array_filter($entries, static fn (string $path): bool => str_contains($path, 'topthink/framework/src/helper.php'));
    $expect('L1 think 的 helper.php 不在 composer files 自动加载里（只有 App::load() 会 include 它）', array_values($thinkHelperEntries), []);

    // ================= ② L0 桩保真：桩 vs 真包逐字段比（两个子进程）=================
    //
    // 桩与真包声明同名类，同进程加载 = 加载期 fatal（think\Request 之类），所以本进程两个都不
    // 显式加载；**同一段 dump 代码喂两边**，比 JSON。与 cases/Wordpress.php / cases/Joomla.php 同型。
    $stubDir = $repoRoot . '/tests/Stubs';
    $stubFile = $stubDir . '/framework-stubs.php';
    if (!is_file($stubFile)) {
        return ['status' => 'FAIL', 'detail' => "桩文件不存在：{$stubFile}", 'skips' => 0];
    }

    $dumpFile = sys_get_temp_dir() . '/xhprof-contract-thinkphp-dump-' . getmypid() . '.php';
    file_put_contents($dumpFile, <<<'DUMP'
<?php

declare(strict_types=1);

// 本文件由 tools/contracts/cases/Thinkphp.php 生成并删除。argv:
//  1=stub|real 2=配对 JSON 3=环 autoloader 4=tests/Stubs 目录
$mode = $argv[1];
$pairs = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
if ($mode === 'stub') {
    // 桩里引用 PSR 接口（framework-stubs.php:1154），得先把它自己的替身装上。
    require $argv[4] . '/Framework/Psr7.php';
    require $argv[4] . '/framework-stubs.php';
} else {
    // 真包：先 helper 再 autoloader（helper 本身不在 files 自动加载里；这里它的存在只影响
    // 别处调用全局助手，dump 只用反射，但加载顺序与干净子进程保持一致，少一个变量）。
    require dirname($argv[3]) . '/topthink/framework/src/helper.php';
    require $argv[3];
}

$describe = static function (string $class, string $member): array {
    if (!class_exists($class)) {
        return ['class_missing' => true];
    }
    $rc = new ReflectionClass($class);
    if (!$rc->hasMethod($member)) {
        return ['missing' => true];
    }
    $rm = $rc->getMethod($member);
    $params = [];
    foreach ($rm->getParameters() as $p) {
        $params[] = [
            'name' => $p->getName(),
            'type' => $p->hasType() ? (string) $p->getType() : '',
            'optional' => $p->isOptional(),
            'default' => $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : null,
            'byref' => $p->isPassedByReference(),
        ];
    }

    return [
        'static' => $rm->isStatic(),
        'abstract' => $rm->isAbstract(),
        'visibility' => $rm->isPublic() ? 'public' : ($rm->isProtected() ? 'protected' : 'private'),
        'params' => $params,
        'required' => $rm->getNumberOfRequiredParameters(),
        'return' => $rm->hasReturnType() ? (string) $rm->getReturnType() : '',
    ];
};

$out = ['pairs' => [], 'files' => [], 'internal' => []];
foreach ($pairs as $stubClass => $info) {
    $key = $stubClass . ' => ' . $info['real'];
    $side = $mode === 'stub' ? $stubClass : $info['real'];
    if (class_exists($side)) {
        $rc = new ReflectionClass($side);
        $out['internal'][$side] = $rc->isInternal();
        $file = $rc->getFileName();
        $out['files'][$side] = $file === false ? '' : $file;
    }
    foreach ($info['members'] as $member) {
        $out['pairs'][$key][$member] = $describe($side, $member);
    }
}

echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
DUMP);

    $pairsJson = json_encode(THINKPHP_MEMBER_PAIRS, JSON_THROW_ON_ERROR);
    $dumps = [];
    foreach (['stub' => '桩侧', 'real' => '真实包侧'] as $side => $label) {
        $run = contracts_run_php([$dumpFile, $side, $pairsJson, $autoload, $stubDir]);
        $decoded = json_decode(trim($run['stdout']), true);
        if (!is_array($decoded) || !isset($decoded['pairs'])) {
            @unlink($dumpFile);
            return [
                'status' => 'FAIL',
                'detail' => "{$label}快照失败（exit {$run['code']}）："
                    . trim($run['stderr'] !== '' ? $run['stderr'] : $run['stdout']),
                'skips' => 0,
            ];
        }
        $dumps[$side] = $decoded;
    }
    @unlink($dumpFile);

    // 声明来源：桩侧每个类都必须落在 tests/Stubs/framework-stubs.php；真侧必须来自 topthink/*
    // （\Redis 是扩展内部类，getFileName() 为 false，单独用 isInternal 钉）。
    $sourceErrors = [];
    foreach ($dumps['stub']['files'] as $class => $file) {
        if (basename((string) $file) !== 'framework-stubs.php') {
            $sourceErrors[] = "桩侧 {$class} 的声明来自 {$file}（不是 framework-stubs.php）";
        }
    }
    foreach ($dumps['real']['files'] as $class => $file) {
        if (($dumps['real']['internal'][$class] ?? false) === true) {
            // 扩展内部类（\Redis）没有文件路径 —— 下面 internal 那道检查单独钉它
            continue;
        }
        if (!str_contains((string) $file, '/topthink/')) {
            $sourceErrors[] = "真实侧 {$class} 的声明来自 {$file}（不像 topthink/* 里的文件）";
        }
    }
    $internalReal = array_keys(array_filter(
        $dumps['real']['internal'],
        static fn (bool $isInternal): bool => $isInternal
    ));
    if ($internalReal !== ['Redis']) {
        $sourceErrors[] = '真实侧的内部类不是恰好 [Redis]：' . json_encode($internalReal)
            . '（phpredis 缺席，或被用户态实现顶替了）';
    }
    if ($sourceErrors !== []) {
        return [
            'status' => 'FAIL',
            'detail' => implode('；', $sourceErrors),
            'skips' => 0,
        ];
    }

    $diffs = [];
    foreach (THINKPHP_MEMBER_PAIRS as $stubClass => $info) {
        $key = $stubClass . ' => ' . $info['real'];
        foreach ($info['members'] as $member) {
            $a = $dumps['stub']['pairs'][$key][$member] ?? ['missing' => 'dump 里没有这一项'];
            $b = $dumps['real']['pairs'][$key][$member] ?? ['missing' => 'dump 里没有这一项'];
            thinkphp_member_diff($a, $b, $key . '::' . $member, $diffs);
        }
    }
    $live = $diffs;
    sort($live);
    $frozen = THINKPHP_EXPECTED_DIFFS;
    sort($frozen);
    if ($live !== $frozen) {
        $onlyLive = array_values(array_diff($live, $frozen));
        $onlyFrozen = array_values(array_diff($frozen, $live));

        return [
            'status' => 'FAIL',
            'detail' => "桩↔真包差异集与冻结清单不符（现场 " . count($live) . ' 条 / 冻结 ' . count($frozen) . " 条）"
                . "\n只在现场（新出现的差异，看是桩漂了还是包升版了）：\n  - " . implode("\n  - ", $onlyLive)
                . "\n只在冻结清单（差异消失了，依据没了）：\n  - " . implode("\n  - ", $onlyFrozen)
                . "\n现场全文（重新签字时逐字替换 THINKPHP_EXPECTED_DIFFS）：\n" . implode("\n", $live),
            'skips' => 0,
        ];
    }
    $expect('L0 桩↔真包差异集逐字等于冻结清单（' . count($frozen) . ' 条）', count($live), count($frozen));

    // ================= ③ 干净子进程：真语义（think 助手可用）=================
    //
    // 四种模式各起一个进程（配置目录 / redis store 形状不同）。探针里的配置骨架是本探针写出的，
    // xhprof.php 是**包内真实配置文件的副本**，只改 key_prefix 与 assets_url 两个值。
    $tmpBase = sys_get_temp_dir() . '/xhprof-contract-thinkphp-' . getmypid();
    $probeFile = $tmpBase . '/probe.php';
    @mkdir($tmpBase, 0777, true);
    file_put_contents($probeFile, <<<'PROBE'
<?php

declare(strict_types=1);

// 本文件由 tools/contracts/cases/Thinkphp.php 生成并删除。argv:
//  1=模式(full|altprefix|noredis|filedriver) 2=环 autoloader 3=仓库根 4=包内真实配置目录
//  5=临时应用根 6=key_prefix 7=assets_url 8=redis host 9=redis port
$mode = $argv[1];
$ringAutoload = $argv[2];
$repoRoot = $argv[3];
$pkgConfigDir = $argv[4];
$appRoot = $argv[5];
$prefix = $argv[6];
$assetsUrl = $argv[7];
$redisHost = $argv[8] ?? '127.0.0.1';
$redisPort = (int) ($argv[9] ?? 6379);

// 第一件：think 的 helper（本进程里 response()/config()/app() 归 think —— 整个子进程存在的理由）。
// 真应用是 App::load() include 它（src/think/App.php:540）；这里提前手动 require，因为环的
// autoloader 一进来 laravel 的 helpers.php 就会先占掉 response()（function_exists 守卫）。
require dirname($ringAutoload) . '/topthink/framework/src/helper.php';
require $ringAutoload;

// 本包 src/ 的自注册要在这里重来：这是独立进程，父进程的 spl_autoload_register 不继承。
$repoRootAutoload = static function (string $class) use ($repoRoot): void {
    $prefixNs = 'ErikWang2013\\Xhprof\\';
    if (strncmp($class, $prefixNs, strlen($prefixNs)) !== 0) {
        return;
    }
    $file = $repoRoot . '/src/' . str_replace('\\', '/', substr($class, strlen($prefixNs))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
};
spl_autoload_register($repoRootAutoload);

$out = [];
$out['helpers'] = [
    'response' => (new ReflectionFunction('response'))->getFileName(),
    'config' => (new ReflectionFunction('config'))->getFileName(),
];
$out['helpers']['response_is_think'] = str_contains($out['helpers']['response'], 'topthink/framework');
$out['helpers']['config_is_think'] = str_contains($out['helpers']['config'], 'topthink/framework');

// ---- 应用根：config/ 由本探针写出；xhprof.php 是**包内真实配置文件的副本** ----
@mkdir($appRoot . '/config', 0777, true);
@mkdir($appRoot . '/runtime', 0777, true);
file_put_contents($appRoot . '/config/app.php', "<?php\nreturn ['app_debug' => false];\n");
file_put_contents($appRoot . '/config/log.php', "<?php\nreturn ['default' => 'file', 'channels' => ['file' => ['type' => 'file', 'path' => " . var_export($appRoot . '/runtime/log', true) . ']]];' . "\n");

$fileStore = var_export($appRoot . '/runtime/cache', true);
$stores = "['file' => ['type' => 'file', 'path' => {$fileStore}]]";
if ($mode === 'full' || $mode === 'altprefix') {
    // redis 驱动 + 自己的前缀（think 封装层的键前缀，与适配器的裸键是两个命名空间）
    $stores = "['file' => ['type' => 'file', 'path' => {$fileStore}],\n        'redis' => ['type' => 'redis', 'host' => " . var_export($redisHost, true) . ", 'port' => {$redisPort}, 'password' => '', 'select' => 0, 'timeout' => 2, 'persistent' => false, 'prefix' => " . var_export($prefix . ':', true) . ']]';
} elseif ($mode === 'filedriver') {
    // store 名字叫 redis，驱动其实是 file：适配器的 handler() 解析会拿到 null
    $stores = "['file' => ['type' => 'file', 'path' => {$fileStore}],\n        'redis' => ['type' => 'file', 'path' => {$fileStore}]]";
}
file_put_contents($appRoot . '/config/cache.php', "<?php\nreturn ['default' => 'file', 'stores' => {$stores}];\n");

$pkgConfig = (string) file_get_contents($pkgConfigDir . '/xhprof.php');
$pkgConfig = str_replace("'key_prefix' => 'xhprof'", "'key_prefix' => " . var_export($prefix, true), $pkgConfig);
$pkgConfig = str_replace("'/xhprof-assets'", var_export($assetsUrl, true), $pkgConfig);
file_put_contents($appRoot . '/config/xhprof.php', $pkgConfig);
// 替换必须真的生效，否则下面所有 key 都落在别的命名空间上（比如把别人 xhprof:* 的键删了）
$written = (string) file_get_contents($appRoot . '/config/xhprof.php');
$out['fixture_ok'] = str_contains($written, var_export($prefix, true)) && str_contains($written, var_export($assetsUrl, true));

$app = new \think\App($appRoot);
$app->initialize();

// ---- 配置适配器：读的是包内真实配置文件（经门面 → think\Config）----
$cfg = new \ErikWang2013\Xhprof\Thinkphp\Adapter\ConfigAdapter();
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
    'sample_rate' => $cfg->get('xhprof.sample_rate'),
    'sample_rate_type' => get_debug_type($cfg->get('xhprof.sample_rate')),
    // think 的 Config::get($name, $default)：**值为 null 与键不存在一样**，都回落默认值
    'auth_token_with_default' => $cfg->get('xhprof.auth_token', 'FELL-BACK'),
    'missing_with_default' => $cfg->get('xhprof.nope', 'FELL-BACK'),
    'whole_array_is_array' => is_array($cfg->get('xhprof', null)),
];

\ErikWang2013\Xhprof\Core\Xhprof::bootstrap(
    new \ErikWang2013\Xhprof\Thinkphp\Adapter\RequestAdapter(new \think\Request()),
    new \ErikWang2013\Xhprof\Thinkphp\Adapter\ResponseAdapter(),
    $cfg,
    new \ErikWang2013\Xhprof\Thinkphp\Adapter\RedisAdapter(),
    new \ErikWang2013\Xhprof\Thinkphp\Adapter\LogAdapter()
);
$out['statics'] = [
    'key_prefix' => \ErikWang2013\Xhprof\Core\Xhprof::$key_prefix,
    'log_ttl' => \ErikWang2013\Xhprof\Core\Xhprof::$log_ttl,
    'time_limit' => \ErikWang2013\Xhprof\Core\Xhprof::$time_limit,
    'view_wtred' => \ErikWang2013\Xhprof\Core\Xhprof::$view_wtred,
    'log_num' => \ErikWang2013\Xhprof\Core\Xhprof::$log_num,
    'ignore_url_arr' => \ErikWang2013\Xhprof\Core\Xhprof::$ignore_url_arr,
];
$out['profiler_enabled'] = \ErikWang2013\Xhprof\Core\XhprofProfiler::isEnabled();

// ---- 门面 ↔ 真类：diff/真实侧选型的依据（L2 里再验一遍运行时链）----
$out['facade'] = [];
foreach (['Config' => 'config', 'Log' => 'log', 'Cache' => 'cache'] as $short => $accessor) {
    $facade = 'think\\facade\\' . $short;
    $out['facade'][$short] = [
        'is_facade_subclass' => is_subclass_of($facade, \think\Facade::class),
        'accessor' => (new ReflectionMethod($facade, 'getFacadeClass'))->invoke(null),
        'resolved_class' => get_class($app->make($accessor)),
        'call_static_declared_in' => (new ReflectionMethod($facade, '__callStatic'))->getDeclaringClass()->getName(),
    ];
}

// ---- 真 Request：raw 语义（query/body 分开看）+ 适配器口径 ----
$mkRequest = static function (string $url, string $method = 'GET', array $get = [], array $post = []): \think\Request {
    $r = new \think\Request();
    $r->setMethod($method);
    $r->setUrl($url);
    $r->withGet($get)->withPost($post)->withServer([
        'HTTP_HOST' => 'example.com:8080',
        'REQUEST_METHOD' => $method,
        'REMOTE_ADDR' => '9.9.9.9',
    ])->withHeader([
        'x-forwarded-for' => '1.2.3.4, 5.6.7.8',
        'accept-language' => 'en-US,en;q=0.9',
    ]);

    return $r;
};

$req = $mkRequest(
    '/list?page=2&run=legal&sort[]=wt&sort[]=mem',
    'POST',
    ['page' => '2', 'run' => 'legal', 'sort' => ['wt', 'mem']],
    ['run' => 'body-value', 'only_body' => 'from-body']
);
$reqAdapter = new \ErikWang2013\Xhprof\Thinkphp\Adapter\RequestAdapter($req);
$out['request'] = [
    // 真面：host() 带端口 / host(true) 去端口；param 合并里 body 赢 query
    'real_host' => $req->host(),
    'real_host_strict' => $req->host(true),
    'real_url' => $req->url(),
    'real_url_full' => $req->url(true),
    'real_get_run' => $req->get('run'),
    'real_post_run' => $req->post('run'),
    'real_param_run' => $req->param('run'),
    'real_param_keys' => array_keys($req->param()),
    'real_method' => $req->method(),
    'real_header_mixedcase' => $req->header('X-Forwarded-For'),
    'real_ip' => $req->ip(),
    // 适配器面
    'adapter_host' => $reqAdapter->host(),
    'adapter_uri' => $reqAdapter->uri(),
    'adapter_url' => $reqAdapter->url(),
    'adapter_get_run' => $reqAdapter->get('run'),
    'adapter_get_only_body' => $reqAdapter->get('only_body'),
    'adapter_all_keys' => array_keys($reqAdapter->all()),
    'adapter_all' => $reqAdapter->all(),
    'adapter_method' => $reqAdapter->method(),
    'adapter_header' => $reqAdapter->header('x-forwarded-for'),
    'adapter_header_absent' => $reqAdapter->header('x-nope'),
    'adapter_get_real_ip' => $reqAdapter->getRealIp(),
    'adapter_get_sort_is_array' => is_array($reqAdapter->get('sort')),
    'adapter_get_default' => $reqAdapter->get('nope', 'DEFAULT'),
];
// 无 Host 头（HTTP/1.0 或手写请求）：真包给空串，不是 localhost，也不抛
$noHost = new \think\Request();
$noHost->withServer([]);
$out['request']['no_host_real'] = $noHost->host();
$out['request']['no_host_real_strict'] = $noHost->host(true);
$out['request']['no_host_adapter'] = (new \ErikWang2013\Xhprof\Thinkphp\Adapter\RequestAdapter($noHost))->host();
$out['request']['ip_default'] = (new \think\Request())->ip();

// ---- 真 Response / ResponseAdapter ----
$r = response('');
$out['response'] = [
    'helper_class' => get_class($r),
    'initial_headers' => $r->getHeader(),
    'initial_content' => $r->getContent(),
    'initial_code' => $r->getCode(),
];
$same = $r->content('X');
$out['response']['content_in_place'] = $same === $r;
$out['response']['content_value'] = $r->getContent();
$h1 = $r->header(['Content-Type' => 'text/css']);
$out['response']['header_in_place'] = $h1 === $r;
$r->header(['Content-Type' => 'text/plain']);
$out['response']['header_same_key_twice'] = $r->getHeader('Content-Type');
$out['response']['header_is_string'] = is_string($r->getHeader('Content-Type'));
$out['response']['header_all_count'] = count($r->getHeader());
$c1 = $r->code(201);
$out['response']['code_in_place'] = $c1 === $r;
$out['response']['code_value'] = $r->getCode();
$out['response']['data_derived_content'] = response('abc')->getContent();

// 适配器：空构造走全局 response('')；三个 with* 就地改同一个对象
$ra = new \ErikWang2013\Xhprof\Thinkphp\Adapter\ResponseAdapter();
$out['response']['adapter_null_ctor_class'] = get_class($ra->send());
$r1 = $ra->withBody('BODY');
$r2 = $r1->withHeaders(['Content-Type' => 'text/plain']);
$r3 = $r2->withStatus(201);
$out['response']['adapter_chain_same_object'] = [$r1 === $ra, $r2 === $ra, $r3 === $ra];
$sent = $ra->send();
$out['response']['adapter'] = [
    'code' => $sent->getCode(),
    'content' => $sent->getContent(),
    'content_type' => $sent->getHeader('Content-Type'),
];
$ra2 = new \ErikWang2013\Xhprof\Thinkphp\Adapter\ResponseAdapter();
$ra2->withHeaders(['Content-Type' => 'text/css'])->withStatus(404);
$ra2->withHeaders(['Content-Type' => 'text/plain']);
$out['response']['adapter_headers_twice'] = $ra2->send()->getHeader('Content-Type');

// file()：真文件（读内容 + 钉类型）/ 缺文件（404 空体）
$cssReal = $repoRoot . '/src/html/css/xhprof.css';
$cssMd5 = md5((string) file_get_contents($cssReal));
$raFile = new \ErikWang2013\Xhprof\Thinkphp\Adapter\ResponseAdapter();
$raFile->file($cssReal);
$fileRes = $raFile->send();
$out['response']['file_css'] = [
    'code' => $fileRes->getCode(),
    'content_type' => $fileRes->getHeader('Content-Type'),
    'body_md5' => md5($fileRes->getContent()),
    'file_md5' => $cssMd5,
];
// file() 之后 core 还会 withHeaders(Cache-Control)/withStatus(200)——必须不毁掉已有的正文与类型
$raFile->withHeaders(['Cache-Control' => 'public, max-age=86400'])->withStatus(200);
$after = $raFile->send();
$out['response']['file_then_headers'] = [
    'cache_control' => $after->getHeader('Cache-Control'),
    'body_still_css' => md5($after->getContent()) === $cssMd5,
    'content_type_still_css' => $after->getHeader('Content-Type'),
];
$raMissing = new \ErikWang2013\Xhprof\Thinkphp\Adapter\ResponseAdapter();
$raMissing->file($repoRoot . '/src/html/css/nope.css');
$missingRes = $raMissing->send();
$out['response']['file_missing'] = ['code' => $missingRes->getCode(), 'content' => $missingRes->getContent()];

// ---- 入口类端到端：业务请求（四种模式都跑；降级模式下这条路就是 Core 兜底那两处 catch 的证据）
// full/altprefix 模式要先建裸连接并把命名空间清干净，业务请求写下的键才数得准。
$raw = null;
$wipe = null;
if ($mode === 'full' || $mode === 'altprefix') {
    $raw = new \Redis();
    $raw->connect($redisHost, $redisPort, 2.0);
    $wipe = static function () use ($raw, $prefix): void {
        $keys = $raw->keys($prefix . '*');
        if ($keys) {
            $raw->del(...$keys);   // 真 \Redis::del 至少一个参数（桩比它宽 —— 差异集里签了字的那条）
        }
    };
    if ($out['fixture_ok']) {
        $wipe();
        $out['keys_before'] = count($raw->keys($prefix . '*'));
    }
}

$mw = new \ErikWang2013\Xhprof\Thinkphp\Middleware();
$downstream = null;
$sentinel = response('DOWNSTREAM');
$bizReq = $mkRequest('/business?q=1');
$e2eError = null;
try {
    $passthrough = $mw->handle($bizReq, static function ($r) use (&$downstream, $sentinel, $bizReq) {
        $downstream = $r === $bizReq;

        return $sentinel;
    });
} catch (\Throwable $e) {
    $passthrough = null;
    $e2eError = get_class($e) . ': ' . $e->getMessage();
}
$out['e2e'] = [
    'business_error' => $e2eError,
    'passthrough_same_response' => $passthrough === $sentinel,
    'downstream_got_real_request' => $downstream,
];

// 资源短路：按**本次配置的前缀**一条、恒为**默认前缀**一条（两种配置下的组合正好钉住
// 「前缀取自配置」与「没有硬编码」）。报告页要 redis（列 run），只在 full 模式跑。
$assetReq = $mkRequest($assetsUrl . '/css/xhprof.css');
$assets = $mw->handle($assetReq, static fn () => response('NOPE'));
$out['e2e']['asset'] = [
    'code' => $assets->getCode(),
    'content_type' => $assets->getHeader('Content-Type'),
    'cache_control' => $assets->getHeader('Cache-Control'),
    'body_md5' => md5((string) $assets->getContent()),
];
$defaultAsset = $mw->handle($mkRequest('/xhprof-assets/css/xhprof.css'), static fn () => response('NOPE'));
$out['e2e']['default_prefix_asset'] = [
    'code' => $defaultAsset->getCode(),
    'content_type' => $defaultAsset->getHeader('Content-Type'),
    'body_md5' => md5((string) $defaultAsset->getContent()),
];
// 越界：`../../composer.json` 指向 <repo>/composer.json（真实存在、可读）。Core 有两道防线
// （'..' 黑名单 + realpath 必须在 assets 目录内），实测各自都够；这条验的是"结果不许越界"。
$traversal = $mw->handle($mkRequest($assetsUrl . '/../../composer.json'), static fn () => response('NOPE'));
$out['e2e']['traversal'] = ['code' => $traversal->getCode(), 'body' => (string) $traversal->getContent()];

if ($mode === 'full' || $mode === 'altprefix') {
    $out['e2e']['keys_after_one_run'] = count($raw->keys($prefix . '*'));
    $out['e2e']['run_id_list'] = $raw->lrange($prefix . ':run_id', 0, -1);
}
if ($mode === 'full') {
    $report = $mw->handle($mkRequest('/xhprof'), static fn () => response('NOPE'));
    $body = (string) $report->getContent();
    $out['e2e']['report'] = [
        'class' => get_class($report),
        'code' => $report->getCode(),
        'content_type' => $report->getHeader('Content-Type'),
        'cache_control' => $report->getHeader('Cache-Control'),
        'has_title' => str_contains($body, 'XHProf'),
        'has_run_id' => ($out['e2e']['run_id_list'][0] ?? '') !== '' && str_contains($body, (string) ($out['e2e']['run_id_list'][0] ?? '')),
        'len' => strlen($body),
    ];
    $out['e2e']['keys_after_report'] = count($raw->keys($prefix . '*'));

    // 适配器语义：真 store（think\cache\driver\Redis）+ 真 handler（phpredis \Redis）
    $redisAdapter = new \ErikWang2013\Xhprof\Thinkphp\Adapter\RedisAdapter();
    $out['redis'] = ['mget_empty' => $redisAdapter->mget([])];
    try {
        $store = \think\facade\Cache::store('redis');
        $out['redis']['store_class'] = get_class($store);
        $handler = $store->handler();
        $out['redis']['handler_class'] = is_object($handler) ? get_class($handler) : gettype($handler);
        $out['redis']['handler_is_redis'] = $handler instanceof \Redis;
        // TTL 只在 > 0 时透传（0 会被 phpredis 的 SETEX 拒绝且不发命令，写入静默丢失）
        $redisAdapter->set($prefix . ':k1', 'v1', 60);
        $out['redis']['raw_value'] = $raw->get($prefix . ':k1');
        $out['redis']['raw_ttl'] = $raw->ttl($prefix . ':k1');
        $redisAdapter->set($prefix . ':k2', 'v2');
        $out['redis']['raw_ttl_zero'] = $raw->ttl($prefix . ':k2');
        $out['redis']['get'] = $redisAdapter->get($prefix . ':k1');
        $out['redis']['incr'] = $redisAdapter->incr($prefix . ':n');
        $out['redis']['decr'] = $redisAdapter->decr($prefix . ':n');
        $out['redis']['lpush'] = [
            $redisAdapter->lPush($prefix . ':l', 'a'),
            $redisAdapter->lPush($prefix . ':l', 'b'),
        ];
        $out['redis']['lrange'] = $redisAdapter->lRange($prefix . ':l', 0, -1);
        $out['redis']['rpop'] = $redisAdapter->rPop($prefix . ':l');
        $out['redis']['mget'] = $redisAdapter->mget([$prefix . ':k1', $prefix . ':nope']);
        $out['redis']['del'] = $redisAdapter->del($prefix . ':k1', $prefix . ':l');
        // think 封装层写的键：**前缀 + 序列化**（与适配器的裸键裸值是两个命名空间，这正是
        // 适配器绕开 Cache 层直连 \Redis 的理由）
        \think\facade\Cache::store('redis')->set('thinkk', ['x' => 1]);
        $out['redis']['think_layer_raw_at_prefixed_key'] = $raw->get($prefix . ':thinkk');
        $out['redis']['adapter_reads_unprefixed'] = $redisAdapter->get('thinkk');
    } catch (\Throwable $e) {
        $out['redis']['error'] = get_class($e) . ': ' . $e->getMessage();
    }
}

// 收尾：探针自己写下的键自己删（命名空间是本次的 key_prefix）
if ($raw !== null && $out['fixture_ok']) {
    $wipe();
    $out['e2e']['keys_after_cleanup'] = count($raw->keys($prefix . '*'));
}

// ---- 降级边界：没配 redis store / store 叫 redis 但驱动不是 redis ----
if ($mode === 'noredis' || $mode === 'filedriver') {
    $ra2 = new \ErikWang2013\Xhprof\Thinkphp\Adapter\RedisAdapter();
    $out['fallback'] = ['ctor' => 'ok', 'mget_empty' => $ra2->mget([])];
    try {
        \think\facade\Cache::store('redis');
        $out['fallback']['store_call'] = 'no-throw';
    } catch (\Throwable $e) {
        $out['fallback']['store_call'] = get_class($e) . ': ' . $e->getMessage();
    }
    foreach ([
        'get' => static fn ($a) => $a->get('k'),
        'set' => static fn ($a) => $a->set('k', 'v'),
        'get_after_set' => static fn ($a) => $a->get('k'),
        // incr 用一个**没写过的**键：写过字符串再自增的 TypeError 是夹具造出来的
        'incr' => static fn ($a) => $a->incr('n'),
        'mget' => static fn ($a) => $a->mget(['k']),
        'lPush' => static fn ($a) => $a->lPush('k', 'v'),
    ] as $name => $fn) {
        try {
            $out['fallback'][$name] = ['ok' => $fn($ra2)];
        } catch (\Throwable $e) {
            $out['fallback'][$name] = ['throw' => get_class($e) . ': ' . $e->getMessage()];
        }
    }
    $out['fallback']['file_store_handler'] = var_export(\think\facade\Cache::store('file')->handler(), true);
    $logAdapter = new \ErikWang2013\Xhprof\Thinkphp\Adapter\LogAdapter();
    try {
        $logAdapter->error('probe-log', ['a' => 1]);
        $out['fallback']['log'] = 'ok';
    } catch (\Throwable $e) {
        $out['fallback']['log'] = get_class($e) . ': ' . $e->getMessage();
    }
    // Core 兜底的证据落在 think 的日志文件里（LogAdapter 真写进去了）
    $logBlob = '';
    foreach (glob($appRoot . '/runtime/log/*/*.log') ?: [] as $logFile) {
        $logBlob .= (string) file_get_contents($logFile);
    }
    $out['fallback']['log_blob'] = $logBlob;
}

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
PROBE);

    // full / altprefix 都要 redis；noredis / filedriver 不需要（它们要的就是"连不上/连不对"）。
    $modes = [
        'full' => ['prefix' => 'tp-full', 'assets' => '/xhprof-assets'],
        'altprefix' => ['prefix' => 'tp-alt', 'assets' => '/tp-assets'],
        'noredis' => ['prefix' => 'tp-nr', 'assets' => '/xhprof-assets'],
        'filedriver' => ['prefix' => 'tp-fd', 'assets' => '/xhprof-assets'],
    ];
    $probe = static function (string $mode) use ($probeFile, $modes, $repoRoot, $tmpBase): array {
        $run = contracts_run_php([
            $probeFile,
            $mode,
            contracts_dir() . '/vendor/autoload.php',
            $repoRoot,
            $repoRoot . '/src/Thinkphp/config',
            $tmpBase . '/app-' . $mode,
            $modes[$mode]['prefix'],
            $modes[$mode]['assets'],
            // 与 cases/Redis.php 同一口径：契约环的前提就是 127.0.0.1:6379 上有个真 Redis
            // （contracts.yml 里 services: redis）。不引入环境变量旋钮：本卡要测的正是"真连上"。
            '127.0.0.1',
            '6379',
        ]);

        return [json_decode(trim($run['stdout']), true), $run];
    };

    $results = [];
    $probeError = null;
    foreach (array_keys($modes) as $mode) {
        [$decoded, $run] = $probe($mode);
        if (!is_array($decoded)) {
            $probeError = "{$mode} 模式子进程未输出合法 JSON（exit {$run['code']}）："
                . trim($run['stderr'] !== '' ? $run['stderr'] : $run['stdout']);
            break;
        }
        $results[$mode] = $decoded;
    }

    if ($probeError === null) {
        $full = $results['full'];
        // 正对照：干净子进程里 think 的助手真的赢了（否则下面所有结论都测错了对象）
        $expect('③ 子进程 response() 归 think 的 helper.php', $full['helpers']['response_is_think'], true);
        $expect('③ 子进程 config() 归 think 的 helper.php', $full['helpers']['config_is_think'], true);

        // —— ConfigAdapter：读的是**包内真实配置文件**（经门面 → think\Config）——
        $expect('③ 夹具：key_prefix/assets_url 替换生效', $full['fixture_ok'], true);
        $expect('③ ConfigAdapter 读到真文件里的 assets_url', $full['config']['assets_url'], '/xhprof-assets');
        $expect('③ ConfigAdapter 读到真文件里的 log_ttl（86400*7 由真文件算出）', $full['config']['log_ttl'], 604800);
        $expect('③ ConfigAdapter 读到真文件里的 time_limit', $full['config']['time_limit'], 0);
        $expect('③ ConfigAdapter 读到真文件里的 view_wtred', $full['config']['view_wtred'], 3);
        $expect('③ ConfigAdapter 读到真文件里的 log_num', $full['config']['log_num'], 1000);
        $expect('③ ConfigAdapter 读到真文件里的 key_prefix（本卡替换成隔离值）', $full['config']['key_prefix'], 'tp-full');
        $expect('③ ConfigAdapter 读到真文件里的 ignore_url_arr', $full['config']['ignore_url_arr'], ['/xhprof']);
        $expect('③ ConfigAdapter 读到真文件里的 enable', $full['config']['enable'], true);
        $expect('③ 真文件里 auth_token 就是 null', $full['config']['auth_token'], null);
        $expect('③ sample_rate 是真文件里的 float 1.0（JSON 之后类型会丢，这里钉住）', $full['config']['sample_rate_type'], 'float');
        $expect('③ 值为 null 的键会回落默认值（与缺失键同一条路）', $full['config']['auth_token_with_default'], 'FELL-BACK');
        $expect('③ 缺失键回落默认值', $full['config']['missing_with_default'], 'FELL-BACK');
        $expect('③ get("xhprof") 给整份数组（XhprofProfiler::bootstrap 要的形状）', $full['config']['whole_array_is_array'], true);

        // —— 配置 → bootstrap → 静态量 ——
        $expect('③ 真配置 enable:true → isEnabled()', $full['profiler_enabled'], true);
        $expect('③ bootstrap 把 key_prefix 灌进静态量', $full['statics']['key_prefix'], 'tp-full');
        $expect('③ bootstrap 把 log_ttl 灌进静态量', $full['statics']['log_ttl'], 604800);
        $expect('③ bootstrap 把 time_limit 灌进静态量', $full['statics']['time_limit'], 0);
        $expect('③ bootstrap 把 view_wtred 灌进静态量', $full['statics']['view_wtred'], 3);
        $expect('③ bootstrap 把 log_num 灌进静态量', $full['statics']['log_num'], 1000);
        $expect('③ bootstrap 把 ignore_url_arr 灌进静态量', $full['statics']['ignore_url_arr'], ['/xhprof']);

        // —— 门面 → 容器 → 真类（diff 的真实侧选型在这里拿到运行时证据）——
        foreach (['Config' => 'think\\Config', 'Log' => 'think\\Log', 'Cache' => 'think\\Cache'] as $short => $real) {
            $expect("③ think\\facade\\{$short} 是 Facade 子类", $full['facade'][$short]['is_facade_subclass'], true);
            $expect("③ think\\facade\\{$short} 解析到 {$real}", $full['facade'][$short]['resolved_class'], $real);
            $expect("③ think\\facade\\{$short} 的 __callStatic 声明在 think\\Facade", $full['facade'][$short]['call_static_declared_in'], 'think\\Facade');
        }
        $expect('③ facade 的容器标识（config/log/cache）', [
            $full['facade']['Config']['accessor'],
            $full['facade']['Log']['accessor'],
            $full['facade']['Cache']['accessor'],
        ], ['config', 'log', 'cache']);

        // —— Request：真面 vs 适配器面 ——
        $req = $full['request'];
        $expect('③ 真包 host() 带端口', $req['real_host'], 'example.com:8080');
        $expect('③ 真包 host(true) 去端口（适配器取这一档，契约 R-2）', $req['real_host_strict'], 'example.com');
        $expect('③ 适配器 host() = host(true)，不含端口', $req['adapter_host'], 'example.com');
        $expect('③ 真包 url() = path + query', $req['real_url'], '/list?page=2&run=legal&sort[]=wt&sort[]=mem');
        $expect('③ 真包 url(true) 带 scheme+host', $req['real_url_full'], 'http://example.com:8080/list?page=2&run=legal&sort[]=wt&sort[]=mem');
        $expect('③ 适配器 uri() 只含 path+query', $req['adapter_uri'], '/list?page=2&run=legal&sort[]=wt&sort[]=mem');
        $expect('③ 适配器 url() 是完整 URL', $req['adapter_url'], 'http://example.com:8080/list?page=2&run=legal&sort[]=wt&sort[]=mem');
        // query 与 body 同名时：真包 param() 让 **body 赢**（webman 是 query 赢——两家别抄串）
        $expect('③ 真包 get("run") 取 query', $req['real_get_run'], 'legal');
        $expect('③ 真包 post("run") 取 body', $req['real_post_run'], 'body-value');
        $expect('③ 真包 param("run") = body 赢 query', $req['real_param_run'], 'body-value');
        $expect('③ param() 合并了 query+body 两组键', $req['real_param_keys'], ['page', 'run', 'sort', 'only_body']);
        $expect('③ 适配器 get() 走 param()，body 赢', $req['adapter_get_run'], 'body-value');
        $expect('③ 只在 body 里的键适配器也看得到', $req['adapter_get_only_body'], 'from-body');
        $expect('③ 适配器 all() 与 param() 同集合', $req['adapter_all_keys'], ['page', 'run', 'sort', 'only_body']);
        $expect('③ all() 的值原样（数组参数不被摊平）', $req['adapter_all']['sort'], ['wt', 'mem']);
        $expect('③ 适配器 get() 取数组参数仍是数组', $req['adapter_get_sort_is_array'], true);
        $expect('③ 缺失键回落默认值', $req['adapter_get_default'], 'DEFAULT');
        $expect('③ 真包 method()', $req['real_method'], 'POST');
        $expect('③ 适配器 method()', $req['adapter_method'], 'POST');
        // header() 大小写不敏感（真包内部小写化 + `_`→`-`），缺头给 null
        $expect('③ 真包 header() 大小写不敏感', $req['real_header_mixedcase'], '1.2.3.4, 5.6.7.8');
        $expect('③ 适配器 header() 同样小写化取值', $req['adapter_header'], '1.2.3.4, 5.6.7.8');
        $expect('③ 适配器 header() 缺头给 null', $req['adapter_header_absent'], null);
        // ip()：真包只看 REMOTE_ADDR（**不信 XFF**），适配器 getRealIp() 就是 ip()
        $expect('③ 真包 ip() = REMOTE_ADDR（XFF 头在场也不看）', $req['real_ip'], '9.9.9.9');
        $expect('③ 适配器 getRealIp() = ip()', $req['adapter_get_real_ip'], '9.9.9.9');
        $expect('③ 无 REMOTE_ADDR 时真包给 0.0.0.0', $req['ip_default'], '0.0.0.0');
        // 无 Host 头：真包给空串（不是 localhost、不抛）
        $expect('③ 无 Host 头：真包 host() 空串', $req['no_host_real'], '');
        $expect('③ 无 Host 头：真包 host(true) 空串', $req['no_host_real_strict'], '');
        $expect('③ 无 Host 头：适配器同样空串', $req['no_host_adapter'], '');

        // —— Response：真面 vs 适配器面 ——
        $resp = $full['response'];
        $expect('③ response("") 给的是 think\\response\\Html（基类是 abstract）', $resp['helper_class'], 'think\\response\\Html');
        // 真包 init() 第三步 contentType() 天生带一个默认头；这是与桩的一处**已知**差异（桩头表初始为空）
        $expect('③ 真响应的初始头表带默认 Content-Type', $resp['initial_headers'], ['Content-Type' => 'text/html; charset=utf-8']);
        $expect('③ 初始正文空串', $resp['initial_content'], '');
        $expect('③ 初始状态码 200', $resp['initial_code'], 200);
        $expect('③ content() 就地改（返回 $this）', $resp['content_in_place'], true);
        $expect('③ content() 后取到新内容', $resp['content_value'], 'X');
        $expect('③ header() 就地改', $resp['header_in_place'], true);
        // array_merge：同名头再设一次是**覆盖**（webman 那边会并成数组）
        $expect('③ header() 同名键覆盖，不并成数组', $resp['header_same_key_twice'], 'text/plain');
        $expect('③ 覆盖后取到的仍是字符串', $resp['header_is_string'], true);
        $expect('③ 表里只有一个同名头', $resp['header_all_count'], 1);
        $expect('③ code() 就地改', $resp['code_in_place'], true);
        $expect('③ code() 后取到新状态码', $resp['code_value'], 201);
        $expect('③ response("abc") 的内容由 data 推导', $resp['data_derived_content'], 'abc');
        $expect('③ 适配器空构造 = response("") 的 Html', $resp['adapter_null_ctor_class'], 'think\\response\\Html');
        $expect('③ withBody/withHeaders/withStatus 就地改同一个响应对象', $resp['adapter_chain_same_object'], [true, true, true]);
        $expect('③ 适配器链后的状态码', $resp['adapter']['code'], 201);
        $expect('③ 适配器链后的正文', $resp['adapter']['content'], 'BODY');
        $expect('③ 适配器链后的 Content-Type', $resp['adapter']['content_type'], 'text/plain');
        $expect('③ 同名头再设一次仍是覆盖（不是 merge_recursive）', $resp['adapter_headers_twice'], 'text/plain');
        // file()：内容真读出来 + 类型钉住；之后 core 的 withHeaders/withStatus 不能毁掉它
        $expect('③ file() 命中：状态码 200', $resp['file_css']['code'], 200);
        $expect('③ file() 命中：Content-Type = text/css', $resp['file_css']['content_type'], 'text/css');
        $expect('③ file() 命中：正文就是文件内容', $resp['file_css']['body_md5'], $resp['file_css']['file_md5']);
        $expect('③ file() 之后再挂 Cache-Control 仍保留正文', $resp['file_then_headers']['body_still_css'], true);
        $expect('③ file() 之后再挂头不换类型', $resp['file_then_headers']['content_type_still_css'], 'text/css');
        $expect('③ file() 之后 Cache-Control 挂上了', $resp['file_then_headers']['cache_control'], 'public, max-age=86400');
        $expect('③ file() 缺文件：404 空体（不是异常、不是 500）', [$resp['file_missing']['code'], $resp['file_missing']['content']], [404, '']);

        // —— 中间件端到端 ——
        foreach ($modes as $mode => $info) {
            $e2e = $results[$mode]['e2e'];
            $expect("③ [{$mode}] 业务请求没抛（降级模式下这是 Core 兜底 catch 的证据）", $e2e['business_error'], null);
            $expect("③ [{$mode}] 下游响应原样返回", $e2e['passthrough_same_response'], true);
            $expect("③ [{$mode}] 下游拿到的是同一个 Request 对象", $e2e['downstream_got_real_request'], true);
            $expect("③ [{$mode}] 夹具隔离前缀生效", $results[$mode]['fixture_ok'], true);
        }

        // 资源短路：配置前缀命中 + 默认前缀是否被接管，两种配置互为对照
        $cssMd5 = md5((string) file_get_contents($repoRoot . '/src/html/css/xhprof.css'));
        foreach (['full', 'altprefix'] as $mode) {
            $assets = $results[$mode]['e2e']['asset'];
            $expect("③ [{$mode}] 配置前缀下的 css 命中", [$assets['code'], $assets['content_type'], $assets['body_md5']], [200, 'text/css', $cssMd5]);
            $expect("③ [{$mode}] 资源带 Cache-Control", $assets['cache_control'], 'public, max-age=86400');
            $expect(
                "③ [{$mode}] `..` 越界：200 空响应（composer.json 没被送出去）",
                [$results[$mode]['e2e']['traversal']['code'], $results[$mode]['e2e']['traversal']['body']],
                [200, '']
            );
        }
        $expect(
            '③ full：assets_url 就是默认值时，"配置前缀"与"默认前缀"两条 URI 等价',
            $results['full']['e2e']['default_prefix_asset'],
            ['code' => 200, 'content_type' => 'text/css', 'body_md5' => $cssMd5]
        );
        // 非默认前缀：配置前缀被接管、**默认前缀不再被接管**（否则就是硬编码复发）
        $expect(
            '③ altprefix：assets_url 改成 /tp-assets 后，默认前缀的资源请求**落回业务**（未被接管）',
            $results['altprefix']['e2e']['default_prefix_asset'],
            ['code' => 200, 'content_type' => 'text/html; charset=utf-8', 'body_md5' => md5('NOPE')]
        );
        $expect('③ altprefix：适配器读到非默认 assets_url', $results['altprefix']['config']['assets_url'], '/tp-assets');

        // —— 一次采样在 Redis 上留什么（full 模式；3 个键 = run_id 列表 + request_log + xhprof_log）——
        $fullE2e = $results['full']['e2e'];
        $expect('③ 采样前命名空间是干净的（夹具自证）', $results['full']['keys_before'], 0);
        $expect('③ 一次业务请求留下恰好 3 个键', $fullE2e['keys_after_one_run'], 3);
        $expect('③ run_id 列表里有 1 条', count($fullE2e['run_id_list']), 1);
        $expect('③ run_id 形如 16 位 hex', (bool) preg_match('/^[0-9a-f]{16}$/', (string) $fullE2e['run_id_list'][0]), true);
        // 报告页/资源都不采样（Core 的短路在 xhprofStart 之前）
        $expect('③ 报告页不产生新的键', $fullE2e['keys_after_report'], 3);
        $expect('③ 收尾后命名空间清空（本卡自己写下的东西自己删）', $fullE2e['keys_after_cleanup'], 0);
        $expect('③ altprefix 模式同样只留 3 个键', $results['altprefix']['e2e']['keys_after_one_run'], 3);
        $expect('③ altprefix 收尾后清空', $results['altprefix']['e2e']['keys_after_cleanup'], 0);

        // 报告页：短路在采样之前 + 头钉死 + 正文里有刚跑出来的 run_id
        $report = $fullE2e['report'];
        $expect('③ 报告页状态码 200', $report['code'], 200);
        $expect('③ 报告页正文是 HTML 字符串（走 report() 的 withBody 一路）', $report['len'] > 1000, true);
        $expect('③ 报告页有标题', $report['has_title'], true);
        $expect('③ 报告页正文里能找到刚采样的 run_id', $report['has_run_id'], true);
        $expect('③ 报告页 Content-Type 显式钉住（think 的 Html 默认不是这一个）', $report['content_type'], 'text/html; charset=UTF-8');
        $expect('③ 报告页 Cache-Control: no-cache, private', $report['cache_control'], 'no-cache, private');

        // —— RedisAdapter：真 store + 真 handler 的语义 ——
        $redis = $full['redis'];
        $expect('③ mget([]) 短路成 []（phpredis 会回 false，方法声明是 array）', $redis['mget_empty'], []);
        $expect('③ Cache::store("redis") 给的是 think\\cache\\driver\\Redis', $redis['store_class'], 'think\\cache\\driver\\Redis');
        $expect('③ handler() 是真 phpredis 的 \\Redis', [$redis['handler_class'], $redis['handler_is_redis']], ['Redis', true]);
        // 裸键裸值：直连 \Redis，不经 think 的 prefix/serialize 层
        $expect('③ 适配器写的键在裸 phpredis 里同名可读（无前缀、无序列化）', $redis['raw_value'], 'v1');
        $expect('③ TTL > 0 透传', $redis['raw_ttl'], 60);
        $expect('③ TTL 不传 = 无过期（-1），不会被误设成 0 秒', $redis['raw_ttl_zero'], -1);
        $expect('③ get() 往返', $redis['get'], 'v1');
        $expect('③ incr()/decr()', [$redis['incr'], $redis['decr']], [1, 0]);
        $expect('③ lPush() 返回列表长度', $redis['lpush'], [1, 2]);
        $expect('③ lRange() 头插顺序', $redis['lrange'], ['b', 'a']);
        $expect('③ rPop() 尾部弹出', $redis['rpop'], 'a');
        $expect('③ mget() 保留 phpredis 的 false 占位（缺键不是 null）', $redis['mget'], ['v1', false]);
        $expect('③ del() 返回删除条数', $redis['del'], 2);
        // think 封装层：前缀 + 序列化（两个命名空间互不可见，这就是绕开它的理由）
        $expect('③ think 封装层写的是**序列化后**的值（前缀化的键）', $redis['think_layer_raw_at_prefixed_key'], 'a:1:{s:1:"x";i:1;}');
        $expect('③ 适配器读不到 think 封装层的前缀键（未经封装层直读）', $redis['adapter_reads_unprefixed'], false);

        // —— 降级边界 A：没配 redis store（think 的应用配了别的 store）——
        $noRedis = $results['noredis']['fallback'];
        $expect('③ [noredis] 适配器构造不抛（延迟解析的原因）', $noRedis['ctor'], 'ok');
        $expect('③ [noredis] mget([]) 仍是 []（短路发生在触 store 之前）', $noRedis['mget_empty'], []);
        $expect('③ [noredis] Cache::store("redis") 抛 InvalidArgumentException', $noRedis['store_call'], 'InvalidArgumentException: Store [redis] not found.');
        foreach (['get', 'set', 'get_after_set', 'incr', 'mget', 'lPush'] as $op) {
            // 适配器**只吞解析阶段的异常**，调用期的异常原样上抛 —— 上游由 Core 的两处 \Throwable 兜底
            $expect("③ [noredis] {$op}() 上抛 store 不存在的异常", $noRedis[$op]['throw'] ?? null, 'InvalidArgumentException: Store [redis] not found.');
        }
        $expect('③ [noredis] file store 的 handler() 是 null（think 的非 redis 驱动没有原生句柄）', $noRedis['file_store_handler'], 'NULL');
        $expect('③ [noredis] LogAdapter 照样能用', $noRedis['log'], 'ok');

        // —— 降级边界 B：store 名叫 redis，驱动是 file ——
        $fileDriver = $results['filedriver']['fallback'];
        $expect('③ [filedriver] Cache::store("redis") 不抛（名字在、驱动是 file）', $fileDriver['store_call'], 'no-throw');
        $expect('③ [filedriver] 回退到框架 Cache 封装：set 成功', $fileDriver['set'], ['ok' => true]);
        $expect('③ [filedriver] 回退读回（封装层自己的命名空间）', $fileDriver['get_after_set'], ['ok' => 'v']);
        $expect('③ [filedriver] 回退 incr', $fileDriver['incr'], ['ok' => 1]);
        // 列表族走 handler()，而非 redis 驱动的 handler() 是 null —— 这条边界是**已知**的，
        // 由 Core\XhprofProfiler::stop() 的 \Throwable 兜底接住（日志里有原文，下面两条钉住）
        $expect('③ [filedriver] mget() 撞上 handler()=null 的 Error', $fileDriver['mget'], ['throw' => 'Error: Call to a member function mget() on null']);
        $expect('③ [filedriver] lPush() 同上', $fileDriver['lPush'], ['throw' => 'Error: Call to a member function lPush() on null']);

        // —— 兜底链的**文件级**证据：Core 的 catch 把原因写进了 think 的日志（经 LogAdapter）——
        $expectContains(
            '③ [noredis] Core 的 save_run 兜底把原因写进日志',
            $results['noredis']['fallback']['log_blob'],
            'Xhprof save_run failed: Store [redis] not found.'
        );
        $expectContains(
            '③ [filedriver] Core 的 save_run 兜底把原因写进日志',
            $results['filedriver']['fallback']['log_blob'],
            'Xhprof save_run failed: Call to a member function lPush() on null'
        );
        // LogAdapter 的消息格式：`消息 + ' ' + json_encode($context)`
        $expectContains('③ LogAdapter 把 context 拼进消息尾部', $results['noredis']['fallback']['log_blob'], 'probe-log {"a":1}');
    } else {
        $checks++;
        $failures[] = $probeError;
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
        'detail' => 'L1 真包签名/门面链 + L0 桩↔真包差异集（冻结 ' . count(THINKPHP_EXPECTED_DIFFS) . ' 条）'
            . ' + L2 真 topthink/framework '
            . (\Composer\InstalledVersions::getPrettyVersion('topthink/framework') ?? '?')
            . " 语义，共 {$checks} 项断言通过："
            . 'host() 带端口 vs host(true) 去端口（适配器取后者）、param() 合并 body 赢 query（与 webman 相反）、'
            . 'ip() 只看 REMOTE_ADDR、url()/url(true) 与 uri() 口径、content()/header()/code() 就地改且同名头覆盖、'
            . 'file() 的 404 空体与 subsequent withHeaders 不毁正文、'
            . '干净子进程里真 helper 驱动的 ConfigAdapter/bootstrap/四模式中间件（业务放行 / 报告页 / 资源前缀跟随 / `..` 越界），'
            . 'RedisAdapter 的裸键裸值 + TTL 透传 + mget false 占位，'
            . '以及两条降级边界（没配 redis store 时上抛由 Core 兜底并落日志 / store 名为 redis 但驱动为 file 时列表族撞 null handler）',
        'assertions' => $checks,
        'skips' => 0,
    ];
};
