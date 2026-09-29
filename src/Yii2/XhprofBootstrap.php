<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Yii2;

use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;
use ErikWang2013\Xhprof\Core\SamplingGuard;
use ErikWang2013\Xhprof\Core\StaticController;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Yii2\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Yii2\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Yii2\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Yii2\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Yii2\Adapter\ResponseAdapter;
use yii\base\Application as BaseApplication;
use yii\base\BootstrapInterface;
use yii\base\Event;
use yii\web\Application as WebApplication;

/**
 * Yii2 入口类：一个 `yii\base\BootstrapInterface`，在 `config/web.php` 的 bootstrap 数组里注册：
 *
 *     'bootstrap' => [
 *         [
 *             'class' => \ErikWang2013\Xhprof\Yii2\XhprofBootstrap::class,
 *             'config' => ['auth_token' => 'xxx'],   // 可选，键集见 src/Yii2/config/xhprof.php
 *         ],
 *     ],
 *
 * 报告页 `/xhprof` 与静态资源前缀（`assets_url`，默认 `/xhprof-assets`）由本类自服务：
 * **不需要注册控制器、不需要注册路由、不需要改 urlManager**。
 *
 * **名字的由来**：Yii2 没有 PSR-15 中间件，官方扩展点在 `yii\base\BootstrapInterface`
 * ——「应用引导时挂上钩子」，所以叫 Bootstrap。与 `Core\Xhprof::bootstrap()`（写入五个适配器）
 * 不是一回事：那是本类 `onBeforeRequest()` 内部第一步做的事。
 *
 * **采样窗口 = `EVENT_BEFORE_REQUEST` → `EVENT_AFTER_REQUEST`**。注意两者的位置：
 * `EVENT_AFTER_REQUEST` 在响应**发出之前**触发（`base/Application.php:384`），
 * 所以「发送响应」本身不在采样窗口内。
 *
 * **异常路径必须靠 shutdown 兜底**：`Application::run()` 只捕获 `ExitException`
 * （`base/Application.php:393`），业务抛出的其它异常会让 `EVENT_AFTER_REQUEST`
 * **永不触发**——采样状态会泄漏到下一个请求。故起表时同时注册一次进程级的
 * `register_shutdown_function`（与 Symfony / Joomla 两家入口同形）。
 *
 * **控制台应用零影响**：`yii\console\Application` 不覆写 `run()`，所以 CLI 命令（cron、
 * 迁移、队列）**也会**触发 `EVENT_BEFORE_REQUEST`；而控制台应用根本没有 `web` 请求组件。
 * `bootstrap()` 里那道 `instanceof WebApplication` 就是为这件事存在的。
 */
class XhprofBootstrap implements BootstrapInterface
{
    /** 报告页路径，硬编码（与 Xhprof::$ignore_url_arr 的默认值一致，十二家同字面量）。 */
    private const REPORT_PATH = '/xhprof';

    private const DEFAULT_ASSETS_URL = '/xhprof-assets';

    /**
     * 用户配置。**公有属性**是刻意的：Yii2 的 DI（`di/Container.php:389` 的 `build()`）
     * 对不实现 `yii\base\Configurable` 的类，是**构造之后**把定义里的其余键逐个赋给公有属性
     * （`foreach ($config as $name => $value) $object->$name = $value;`），所以
     * `['class' => ..., 'config' => [...]]` 走的是属性而不是构造参数——
     * 这也是 `$config` 只能在 `onBeforeRequest()` 里惰性读取的原因。
     * 保持构造函数全部可选，`'bootstrap' => [XhprofBootstrap::class]` 这种只写类名的形态才成立。
     *
     * @var array<string, mixed>
     */
    public array $config = [];

    /** 注入点：站点若用非默认 Redis 连接可传实例；不传则按 `redis` 子数组直连 phpredis。 */
    public ?CacheInterface $cache = null;

    /** 注入点：自定义日志出口（缺省 `error_log()`，见 LogAdapter）。 */
    public ?LoggerInterface $logger = null;

    /**
     * 是否已停止（初值 true = 当前没有采样在跑）。
     *
     * 与服务端无关的三条竞争都要它兜住：`EVENT_AFTER_REQUEST` 与 shutdown 兜底各调一次、
     * 以及常驻进程里上一个请求注册的兜底回调跑到本请求。二次 `xhprof_disable()` 返回空数据，
     * 而 `XHProfRunsDefault::save_run()` 不会因此提前返回：照样 lPush 一个 run_id、照样写
     * request_log（wt/mu 全 0）、xhprof_log 里写的是 `serialize(null) === 'N;'`（非空字符串，
     * !empty 判真）——报告列表里凭空多一条没有数据的 run。`XhprofProfiler::stop()` 自身没有
     * 幂等保护，这道守卫只能由入口类持有（与 Symfony / Joomla / Native / WordPress 四家同形）。
     *
     * 初值取 true 而不是 false：shutdown 注册是**进程级**的，常驻进程（RoadRunner/Swoole）
     * 里上一个请求留下的回调会在进程退出时才跑，那一刻若还没有采样在跑（enable=false、
     * 报告页、资源路径），初值 false 会让这枚陈旧回调落一条空 run。
     */
    private static bool $stopped = true;

    /**
     * shutdown 兜底每进程只注册一次：常驻进程里按请求注册会无限堆积，
     * 且旧回调读的是**当前**静态状态，一次注册就够（与 Symfony 入口同一条）。
     */
    private static bool $shutdownRegistered = false;

    /**
     * @param array<string, mixed> $config 覆盖包内 `src/Yii2/config/xhprof.php` 的默认值
     */
    public function __construct(array $config = [], ?CacheInterface $cache = null, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    /**
     * 应用引导阶段：只在 Web 应用上挂两个钩子，不做别的。
     *
     * 这里刻意**不**构造适配器、不读配置——`bootstrap()` 阶段 `Yii::$app` 还在初始化，
     * 而配置属性是构造之后才由 DI 赋上的（见 `$config` 的注释）。
     *
     * @param \yii\base\Application $app
     */
    public function bootstrap($app): void
    {
        if (!$app instanceof WebApplication) {
            return;
        }

        $app->on(BaseApplication::EVENT_BEFORE_REQUEST, [$this, 'onBeforeRequest']);
        $app->on(BaseApplication::EVENT_AFTER_REQUEST, [$this, 'onAfterRequest']);
    }

    public function onBeforeRequest(Event $event): void
    {
        /** @var WebApplication $app */
        $app = $event->sender;

        $request = new RequestAdapter($app->getRequest());
        $response = new ResponseAdapter($app->getResponse());
        $config = new ConfigAdapter($this->config);

        // 连接参数与另外十一家的 Redis 适配器同名同默认（host/port/password/database/timeout），
        // 只在用户配置里出现——包内默认配置文件没有 `redis` 键（键集仍是那十个）。
        $redisOptions = $config->get('xhprof.redis', []);
        $cache = $this->cache ?? new RedisAdapter(is_array($redisOptions) ? $redisOptions : []);
        $logger = $this->logger ?? new LogAdapter();

        // 1) 先 bootstrap，必须在 isEnabled() 与**短路判定**之前：报告页/资源前缀都从配置读，
        //    而 XhprofProfiler::$config 是静态的，常驻进程里不先写就会读到上一个请求的配置。
        Xhprof::bootstrap($request, $response, $config, $cache, $logger);

        // 2) 报告页 / 资源短路，都在 xhprofStart 之前，且不采样。
        $path = self::pathOnly($request->uri());
        if ($path === self::REPORT_PATH) {
            $this->serveReport($app, $response);

            // 走不到这里：`end()` 在生产下 `exit`，在 YII_ENV_TEST 下抛 ExitException。
            // 写出来是为了「万一它返回了」也不会掉进下面的采样分支。
            return;
        }
        $prefix = self::assetsPrefix($config);
        if ($prefix !== '' && str_starts_with($path, $prefix)) {
            $app->end(0, StaticController::serve($request, $response)->send());

            return;
        }

        // 3) 守卫：缺 ext-xhprof / ext-redis 时报一句并跳过采样（SamplingGuard 见 Core）。
        //    判断顺序不可换：available() 短路在前，enable=false 时才不会把"缺扩展"吞掉。
        if (!SamplingGuard::available() || !XhprofProfiler::isEnabled()) {
            return;
        }

        // 4) 起采样。起表也走幂等守卫：进程里重复引导（bootstrap 数组写了两遍、
        //    或常驻进程里上一个请求的采样还没关）时不能二次 xhprof_enable()。
        self::startSampling();
    }

    /**
     * 请求结束（响应发出**之前**触发）：止点。幂等，二次调用无害。
     */
    public function onAfterRequest(Event $event): void
    {
        self::stopSampling();
    }

    /**
     * 报告页：`Xhprof::index()` 返回字符串就自己补响应头，返回响应对象就原样交还。
     *
     * 后者是 `deny()`（鉴权失败 / run|source 参数非法）的返回值：它已经用**同一个适配器**
     * `withStatus()->withBody()->send()` 过，状态与正文都写进了应用响应对象，
     * 这里再包一层会让 403 被 200 覆盖。其余入口类同此处理。
     */
    private function serveReport(WebApplication $app, ResponseAdapter $response): void
    {
        $html = Xhprof::index();
        if (is_string($html)) {
            // no-cache：报告是即时数据；也避免「匿名 + ?token=xxx」访问被页面缓存留存副本。
            // 两个字面量与 Drupal 控制器及其余入口类一致（十二家同形）。
            $response
                ->withStatus(200)
                ->withHeaders(['Cache-Control' => 'no-cache, private', 'Content-Type' => 'text/html; charset=UTF-8'])
                ->withBody($html);
        }

        // `end()` 会触发 EVENT_AFTER_REQUEST（本类此刻没在采样，守卫直接返回）→ 发送响应
        // → `exit(0)`；`YII_ENV_TEST` 下改为抛 ExitException。两条分支在此汇合。
        $app->end(0, $response->send());
    }

    /**
     * 幂等起表：已在采样就什么都不做。
     */
    private static function startSampling(): void
    {
        if (!self::$stopped) {
            return;
        }

        Xhprof::xhprofStart();
        self::$stopped = false;
        self::registerShutdownStop();
    }

    /**
     * 幂等止点。`onAfterRequest()` 与 shutdown 兜底共用。
     */
    private static function stopSampling(): void
    {
        if (self::$stopped) {
            return;
        }
        self::$stopped = true;
        Xhprof::xhprofStop();
    }

    /**
     * 幂等注册 shutdown 兜底（每进程一次）。
     */
    private static function registerShutdownStop(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;
        register_shutdown_function(static function (): void {
            self::stopSampling();
        });
    }

    /**
     * assets_url 归一化成**带尾斜杠**的前缀；配成空串 = 不启用资源短路（返回空串，调用方必须判空）。
     *
     * 与 `Core\StaticController::uriPrefix()` 同一套归一化（先取原串 → 非字符串/空串视为不启用
     * → 否则 rtrim 掉尾斜杠再补一个 '/'），并且**同源**：这里读的就是第 1 步传进
     * `Xhprof::bootstrap()` 的那个 ConfigAdapter，`StaticController` 读的是同一份
     * （`Xhprof::getConfig()`），所以「本类认下的资源路径」与「serve() 认下的资源路径」
     * 是同一批。分叉的后果不是报错而是**静默**：报告页 CSS/JS 全空，或资源请求被业务路由
     * 当成 404。前缀必带尾斜杠是为了不让 `/xhprof-assets-nope` 被当资源（那是业务路径）。
     */
    private static function assetsPrefix(ConfigAdapter $config): string
    {
        $assetsUrl = $config->get('xhprof.assets_url', self::DEFAULT_ASSETS_URL);
        if (!is_string($assetsUrl) || $assetsUrl === '') {
            return '';
        }

        return rtrim($assetsUrl, '/') . '/';
    }

    /**
     * 去掉 query 的路径。`RequestAdapter::uri()` 返回的已是「路径+query」（R-1），
     * 不含 scheme/host，所以这里只切问号；`/xhprof?run=xxx` 也必须落到报告页上。
     */
    private static function pathOnly(string $uri): string
    {
        $pos = strpos($uri, '?');

        return $pos === false ? $uri : substr($uri, 0, $pos);
    }
}
