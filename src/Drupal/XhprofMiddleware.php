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
     * 静态资源前缀，与 Core\StaticController 内部那个私有常量一致
     * （Core 只读且常量是 private，只能在此重复；同上，测试拿文件系统的事实对照）。
     */
    private const ASSETS_PREFIX = '/xhprof-assets';

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

    /** 报告页或静态资源请求（路径与 StaticController 的解析方式一致；末尾斜杠容忍）。 */
    private function isReportOrAssets(Request $request): bool
    {
        // 用 request URI 而不是 getPathInfo()：StaticController::getPathFromRequest() 解析的
        // 就是这个（parse_url(uri(), PHP_URL_PATH)），两边用同一个来源才不会一个认定是资源、
        // 另一个不认。**已知边界**：Drupal 装在子目录（/sites/app/xhprof）时 URI 带 base path，
        // 这里匹配不上 → 只是回到「采样但不落库」的旧行为（默认 ignore_url_arr 兜住），
        // 与 assets_url 硬编码前缀是同一类限制（README 已记）。
        $path = rtrim((string) parse_url($request->getRequestUri(), PHP_URL_PATH), '/');

        return $path === self::REPORT_PATH || str_starts_with($path, self::ASSETS_PREFIX . '/');
    }
}
