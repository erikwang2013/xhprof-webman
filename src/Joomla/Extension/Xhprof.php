<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Joomla\Extension;

use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;
use ErikWang2013\Xhprof\Core\SamplingGuard;
use ErikWang2013\Xhprof\Core\StaticController;
use ErikWang2013\Xhprof\Core\Xhprof as CoreXhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Joomla\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Joomla\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Joomla\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Joomla\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Joomla\Adapter\ResponseAdapter;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\DispatcherInterface;
use Joomla\Event\EventInterface;
use Joomla\Event\Priority;
use Joomla\Event\SubscriberInterface;

/**
 * Joomla 4.4 / 5.x 系统插件：采样窗口 = onAfterInitialise → onAfterRespond。
 *
 * 报告页与静态资源在采样开始前短路输出，**零组件、零菜单项、零路由**：
 * 本插件不注册任何路由，报告页就是「请求路径恰好等于 /xhprof」这一个分支。
 */
final class Xhprof extends CMSPlugin implements SubscriberInterface
{
    /** 报告路径，硬编码（与其余 10 个框架一致） */
    private const REPORT_PATH = '/xhprof';

    private const DEFAULT_ASSETS_URL = '/xhprof-assets';

    /**
     * 是否已停止采样。初值 true 表示「当前没有采样在跑」。
     *
     * 没有它，onAfterRespond 与 shutdown 兜底会各调一次 xhprof_disable()，第二次返回
     * 空数据，而 XHProfRunsDefault::save_run() 不会因此提前返回：_saveToRedis() 照样
     * lPush 一个 run_id、照样写入 request_log（wt/mu 全 0），xhprof_log 里写入的是
     * serialize(null) === 'N;'（非空字符串，!empty 判真）。结果是报告列表里凭空多出
     * 一条没有数据的 run。已实测：无采样时调一次 Xhprof::xhprofStop() 就会多出一条。
     * XhprofProfiler::stop() 自身没有幂等保护（See src/Core/XhprofProfiler.php:18），
     * 所以这道守卫只能由入口类持有。
     *
     * 初值取 true 而不是 false：register_shutdown_function 的注册是**进程级**的，
     * 常驻进程里上一个请求注册的兜底回调会在进程退出时才跑。那一刻若还没有任何采样
     * 在跑（enable=false、报告页、资源路径），初值为 false 会让这枚陈旧回调落一条空 run。
     * 初值 true 让「没启动过采样」这件事本身可判，不依赖调用顺序。
     */
    private static bool $stopped = true;

    private ?CacheInterface $cache;

    private ?LoggerInterface $logger;

    /**
     * 签名对 4.4 与 5.x 都成立：
     *  - 4.4：`CMSPlugin::__construct(&$subject, $config = [])`，第一个必填且按引用
     *    （故这里必须传**变量**，不能传函数调用结果，否则 "Only variables should be
     *    passed by reference"），构造尾部 `setDispatcher($subject)` 带型别约束，
     *    所以 dispatcher 在 4.4 上实质必填。
     *  - 5.x：`CMSPlugin::__construct($config = [])`，内部识别第一个参数是不是
     *    DispatcherInterface（是则 setDispatcher，再取 func_get_arg(1) 当 config）。
     * 两种形态下 `parent::__construct($dispatcher, $config)` 都正确，故不分支。
     *
     * $cache / $logger 留出注入点（与 Slim / Symfony / WordPress 三个入口一致），
     * 站点若用非默认 Redis 连接或自定义日志出口可替换。
     */
    public function __construct(
        ?DispatcherInterface $dispatcher = null,
        array $config = [],
        ?CacheInterface $cache = null,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct($dispatcher, $config);
        $this->cache = $cache;
        $this->logger = $logger;
    }

    /**
     * 事件名用字面量，**不是** `Joomla\CMS\Event\Application\ApplicationEvents::*`：
     * 那个类在 Joomla 4.4/5.x 里不存在（4.4.0 / 5.0.0 / 5.3.0 / 5.4-dev 四个 ref 下
     * libraries/src/Event/Application/ 里都没有 ApplicationEvents.php，5.4-dev 全树
     * 8730 个条目零命中），写常量会让插件在装载时因「类找不到」直接致命错误。
     * `Joomla\Application\ApplicationEvents`（joomla/application 包）确实存在，但值形如
     * 'application.after_respond'，既没有 AFTER_INITIALISE，也不是插件事件名。
     *
     * 事件名的唯一权威是分发点，CMSApplication 用的就是这两个裸名（源码已核对，
     * 括号内为 5.4-dev libraries/src/Application/CMSApplication.php 行号）：
     *   initialise() 内        dispatchEvent('onAfterInitialise', new AfterInitialiseEvent('onAfterInitialise', …))  (:813)
     *   execute() 末尾（respond() 之后）dispatchEvent('onAfterRespond',  new AfterRespondEvent('onAfterRespond', …)) (:347)
     * 核心插件同样如此订阅（plugins/system/sef/src/Extension/Sef.php →
     * ['onAfterInitialise' => 'onAfterInitialise', 'onAfterRoute' => …]）。
     * `Joomla\Event\Dispatcher::addSubscriber()` 直接拿本数组的**键**当事件名注册
     * （Dispatcher.php:394），所以键必须是裸名。
     *
     * 优先级：AFTER_INITIALISE 用 NORMAL（0），与其它系统插件同档；AFTER_RESPOND 用
     * MIN（-3），让停止采样的动作在其它 onAfterRespond 监听器之后再跑，窗口尽量长。
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise' => ['onAfterInitialise', Priority::NORMAL],
            'onAfterRespond' => ['onAfterRespond', Priority::MIN],
        ];
    }

    public function onAfterInitialise(?EventInterface $event = null): void
    {
        $app = $this->getApplication();
        $req = new RequestAdapter($app->getInput());
        $res = new ResponseAdapter($app);
        $config = new ConfigAdapter(self::packageConfig(), self::siteConfig());

        // 顺序不可换：bootstrap 必须在 isEnabled() 之前，否则常驻运行时里
        // isEnabled() 读到的是上一个请求的配置（XhprofProfiler::$config 是静态的）。
        CoreXhprof::bootstrap($req, $res, $config, $this->cache ?? new RedisAdapter(), $this->logger ?? new LogAdapter());

        $path = self::pathOnly($req->uri());

        // ---- 报告页 / 静态资源短路：在 xhprofStart() 之前，一律不采样 ----
        if ($path === self::REPORT_PATH) {
            $html = CoreXhprof::index();
            // 鉴权失败时 index() 已经用响应适配器发过 403 并返回 null，不能再补发一次 200。
            if (is_string($html)) {
                // no-cache：报告是即时数据；也避免「匿名 + ?token=xxx」访问被 Joomla 的
                // System - Page Cache 插件（或反代）留存副本。两个字面量与 Drupal
                // 控制器里的 $headers 一致（六框架同形）。
                $res->withStatus(200)
                    ->withBody($html)
                    ->withHeaders(['Cache-Control' => 'no-cache, private', 'Content-Type' => 'text/html; charset=UTF-8'])
                    ->send();
            }

            // close() 是 exit()（AbstractApplication::close() 就是 exit($code)，CMS 从未
            // 覆写）：不 close 的话组件渲染会叠在报告页后面。
            $app->close();
            return;
        }

        $assetsPrefix = self::assetsPrefix($config);
        if ($assetsPrefix !== '' && str_starts_with($path, $assetsPrefix)) {
            StaticController::serve($req, $res)->send();
            $app->close();
            return;
        }

        // ---- 采样 ----
        // 缺 ext-xhprof / ext-redis 时报一句并跳过采样（SamplingGuard 见 Core）。
        // 判断顺序不可换：available() 短路在前，enable=false 时才不会把"缺扩展"吞掉。
        if (!SamplingGuard::available() || !XhprofProfiler::isEnabled()) {
            return;
        }

        CoreXhprof::xhprofStart();
        self::$stopped = false;

        // onAfterRespond 在异常路径下不保证送达（响应尚未产出就重抛、进程被终止），
        // 采样状态会泄漏到同一进程的下一个请求。兜底：PHP 关闭序列里再 stop 一次，
        // $stopped 保证与 onAfterRespond 竞争时也只落库一次。
        register_shutdown_function([self::class, 'stopSampling']);
    }

    public function onAfterRespond(?EventInterface $event = null): void
    {
        self::stopSampling();
    }

    /**
     * 幂等停止：onAfterRespond 与 register_shutdown_function 兜底都可能到达这里。
     */
    public static function stopSampling(): void
    {
        if (self::$stopped) {
            return;
        }
        self::$stopped = true;
        CoreXhprof::xhprofStop();
    }

    /** 包内默认配置 */
    private static function packageConfig(): array
    {
        $file = __DIR__ . '/../config/xhprof.php';
        $config = is_file($file) ? include $file : [];
        return is_array($config) ? $config : [];
    }

    /**
     * 站点根 xhprof.php 里的用户覆盖项（可选）。
     *
     * 读文件而不是读插件参数：插件参数在 #__extensions.params 里，要查数据库，
     * 而这段代码每个请求都跑一次。
     */
    private static function siteConfig(): array
    {
        if (!defined('JPATH_ROOT')) {
            return [];
        }
        $file = JPATH_ROOT . '/xhprof.php';
        if (!is_file($file)) {
            return [];
        }
        $config = include $file;
        return is_array($config) ? $config : [];
    }

    /** uri() 是 path+query，报告页/资源判断只看 path */
    private static function pathOnly(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) ? $path : '';
    }

    /**
     * assets_url 归一化成带尾斜杠的前缀；配成空串视为不启用资源短路。
     *
     * 口径与 `Core\StaticController::uriPrefix()` 及其余入口类一致（取原串 → 非字符串
     * 或空串视为不启用 → 否则 rtrim 掉尾斜杠再补一个 '/'）：Joomla 的短路判定必须与 Core 的
     * 服务判定同源，否则配了自定义前缀会出现「一边认是资源、另一边不认」。
     */
    private static function assetsPrefix(ConfigInterface $config): string
    {
        $assetsUrl = $config->get('xhprof.assets_url', self::DEFAULT_ASSETS_URL);
        if (!is_string($assetsUrl) || $assetsUrl === '') {
            return '';
        }

        return rtrim($assetsUrl, '/') . '/';
    }
}
