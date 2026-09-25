<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Drupal;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\SamplingGuard;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Drupal\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Drupal\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Drupal\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Drupal\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Drupal\Adapter\ResponseAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Drupal 的 http_middleware（xhprof.services.yml，priority 1000）。
 *
 * 与其他五个框架不同，报告页不在这里短路：Drupal 走标准模块 + 路由，
 * 由 XhprofController 提供 /xhprof 与 /xhprof-assets（见 drupal/xhprof/xhprof.routing.yml）。
 *
 * 串接方式（已对 drupal/core 10.0.7 与 11.4.7 源码复核）：内侧 kernel **由
 * StackedKernelPass 自动注入构造参数 0**，services.yml 里只写自己的额外参数。
 * 自己写 `@<id>.http_middleware_inner` 是错的——11.2 及以前没有这个别名（编译期
 * ServiceNotFoundException，整站起不来），11.3 起该别名由 pass 自己注入（会重复注入）。
 */
class XhprofMiddleware implements HttpKernelInterface
{
    /**
     * 报告页路径，与 drupal/xhprof/xhprof.routing.yml 里注册的路径**必须一致**
     * （两处都改才算改；测试与契约环都拿这两个来源互相对照）。
     */
    private const REPORT_PATH = '/xhprof';

    /**
     * `xhprof.assets_url` 的默认值，与 Core\StaticController::URI_PREFIX 及十份配置文件一致。
     *
     * 前缀本身**从配置读**（见 isReportOrAssets()），这个常量只是缺配置时的兜底。
     * 这里曾经镜像 Core 的私有常量 '/xhprof-assets' 并注释成「两处必须一致」——Core 改成
     * 读配置后那句话就反了：镜像硬编码反而制造了分叉（配了自定义前缀时守卫认不出资源请求、
     * 照常采样，而 StaticController 那边也不服务它们）。
     */
    private const DEFAULT_ASSETS_URL = '/xhprof-assets';

    private HttpKernelInterface $httpKernel;
    private ConfigFactoryInterface $configFactory;
    private LoggerChannelFactoryInterface $loggerFactory;
    private ?CacheInterface $cache;

    public function __construct(
        HttpKernelInterface $httpKernel,
        ConfigFactoryInterface $configFactory,
        LoggerChannelFactoryInterface $loggerFactory,
        ?CacheInterface $cache = null
    ) {
        $this->httpKernel = $httpKernel;
        $this->configFactory = $configFactory;
        $this->loggerFactory = $loggerFactory;
        $this->cache = $cache;
    }

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        // 子请求（ESI / fragment / forward() / Drupal 的内部渲染子请求）不参与采样：
        // 子请求在主请求中途产生并送达，若参与采样，最内层子请求结束时就会
        // xhprof_disable()，主请求后半段的全部调用丢失。
        if (HttpKernelInterface::MAIN_REQUEST !== $type) {
            return $this->httpKernel->handle($request, $type, $catch);
        }

        // 必须在 isEnabled() 之前 bootstrap：常驻进程（RoadRunner/Swoole）里
        // XhprofProfiler::$config 是静态的，不刷新会读到上一个请求的配置。
        Xhprof::bootstrap(
            new RequestAdapter($request),
            new ResponseAdapter(new Response()),
            new ConfigAdapter($this->configFactory),
            $this->cache ?? new RedisAdapter(),
            new LogAdapter($this->loggerFactory)
        );

        // 缺 ext-xhprof / ext-redis 时报一句并跳过采样（SamplingGuard 见 Core）。
        // 判断顺序不可换：available() 短路在前，enable=false 时才不会把"缺扩展"吞掉。
        $enabled = SamplingGuard::available() && XhprofProfiler::isEnabled();

        // 报告页与静态资源**只跳过采样，不短路响应**：响应仍由模块路由的 Controller 产生，
        // 短路会让 routing.yml + Controller 变成死代码（Drupal 是六家里唯一用模块路由的）。
        // 不跳的话，「打开报告」这个动作本身会被写进报告，而且用户把 ignore_url_arr 清空
        // （「什么都不过滤」）时每次刷新都会新增一条 run —— 那正是 ignore_url_arr 想表达
        // 的意图被配置覆盖掉的情形，六家里也只有 Drupal 会这样。
        if ($enabled && $this->isReportOrAssets($request)) {
            $enabled = false;
        }

        if ($enabled) {
            Xhprof::xhprofStart();
        }

        // try/finally：业务抛异常也必须 stop，否则采样状态泄漏到下一个请求
        try {
            return $this->httpKernel->handle($request, $type, $catch);
        } finally {
            if ($enabled) {
                Xhprof::xhprofStop();
            }
        }
    }

    /** 报告页或静态资源请求（路径与 StaticController 的解析来源一致；末尾斜杠容忍）。 */
    private function isReportOrAssets(Request $request): bool
    {
        // 用 getPathInfo()：这正是 Drupal 路由**实际匹配**的路径
        // （RequestContext::fromRequest() 拿的就是它），base path 已被剥掉。
        // 不能用 getRequestUri()：站点装在子目录时（文档根 = /sites/app、站点在
        // /sites/app/sub）URI 带着 base path（/sub/xhprof），这里匹配不上 → 报告页与资源
        // 请求照常被采样，用户把 ignore_url_arr 清空（「什么都不过滤」）时每次刷新报告都会
        // 多出一条 run —— 而「打开报告」这个动作本身被写进报告正是那个配置想避免的事。
        // 与 StaticController 的来源也必须继续一致：资源路由那边交给它的是同一个 pathInfo
        // （Adapter\RoutedPathRequestAdapter），否则就是「一边认是资源、另一边不认」，
        // 那半边会让子目录下的 CSS/JS 静默变空。
        $path = rtrim($request->getPathInfo(), '/');
        if ($path === self::REPORT_PATH) {
            return true;
        }

        // 前缀从配置归一化，口径与 Core\StaticController::uriPrefix() 及另外 5 家入口类
        // （Slim/Symfony/WordPress/Joomla/Yii3 的短路前缀）完全一致：先取原始串再 rtrim
        // （先 rtrim 再判空会把 '/' 归成空串），空串/非字符串 = 不启用资源短路、一个都不认。
        // 必须与 StaticController 同源，否则「守卫认的」与「serve() 认的」是两批路径。
        // 已知边界：Drupal 的资源路由写死在 xhprof.routing.yml（'/xhprof-assets/{file}'），
        // 不会跟着 assets_url 走 —— 配了自定义前缀时资源请求落不到控制器上（空 200）。
        $cfg = Xhprof::getConfig();
        $assetsUrl = $cfg !== null
            ? $cfg->get('xhprof.assets_url', self::DEFAULT_ASSETS_URL)
            : self::DEFAULT_ASSETS_URL;
        if (!is_string($assetsUrl) || $assetsUrl === '') {
            return false;
        }

        return str_starts_with($path, rtrim($assetsUrl, '/') . '/');
    }
}
