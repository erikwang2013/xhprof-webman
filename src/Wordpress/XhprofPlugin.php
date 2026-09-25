<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Wordpress;

use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\SamplingGuard;
use ErikWang2013\Xhprof\Core\StaticController;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Wordpress\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Wordpress\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Wordpress\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Wordpress\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Wordpress\Adapter\ResponseAdapter;

/**
 * WordPress 入口类，由 mu-plugin 引导文件 `wordpress/xhprof-webman.php` 实例化。
 *
 * 采样窗口：`plugins_loaded`（PHP_INT_MIN，最先执行）→ `shutdown`（PHP_INT_MAX，最后执行）。
 *
 * **结构性限制**：窗口不包含 `wp-settings.php` 的引导与插件加载本身。WordPress 没有
 * 「一次请求的完整包裹」这个概念——`plugins_loaded` 之后才轮到插件代码，`shutdown`
 * 是本次请求最后一个可挂的钩子。该阶段（加载所有插件、建库连接、编译主题）的开销
 * 采不到，这是 WordPress 的结构性限制，不是本实现的取舍。
 * 收窄范围靠配置里的 `ignore_url_arr`（`isIgnore()` 对 `uri()` 子串匹配，不改代码生效）。
 *
 * 报告页与静态资源不注册 rewrite 规则、不注册 REST 路由：在采样开始前判路径短路。
 */
class XhprofPlugin
{
    private const REPORT_PATH = '/xhprof';

    private RequestInterface $request;

    private ResponseInterface $response;

    private ConfigInterface $config;

    private CacheInterface $cache;

    private LoggerInterface $logger;

    /** shutdown 止点只允许执行一次（见 onPluginsLoaded() 里的说明）。 */
    private bool $stopped = false;

    /**
     * 构造参数全部可选：`new XhprofPlugin()` 是 mu-plugin 引导文件走的路径。
     * `$cache` / `$logger` 留出注入点，站点若用非默认 Redis 连接或自定义日志出口可替换。
     *
     * @param array<string, mixed> $config 覆盖包内 `src/Wordpress/config/xhprof.php` 的默认值
     */
    public function __construct(array $config = [], ?CacheInterface $cache = null, ?LoggerInterface $logger = null)
    {
        $this->request = new RequestAdapter();
        $this->response = new ResponseAdapter();
        $this->config = new ConfigAdapter($config);
        $this->cache = $cache ?? new RedisAdapter();
        $this->logger = $logger ?? new LogAdapter();
    }

    /** 由 mu-plugin 引导文件调用：把入口挂到 `plugins_loaded` 的最前面（PHP_INT_MIN）。 */
    public function register(): void
    {
        add_action('plugins_loaded', [$this, 'onPluginsLoaded'], PHP_INT_MIN);
    }

    public function onPluginsLoaded(): void
    {
        // 1) 先 bootstrap：必须在 isEnabled() 与报告页判断之前。XhprofProfiler::$config
        //    是静态的，长驻进程里晚一步就会读到上一个请求的配置；报告页也要靠这里的
        //    request/response 适配器才渲染得出来。
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        // 2) 报告页 / 静态资源短路（在 xhprofStart() 之前，两条路径都不采样）
        if ($this->intercept()) {
            // WordPress 不会因为回调返回就停下：不 exit 的话整站主题会叠在报告页后面。
            exit;
        }

        // 3) 守卫：缺 ext-xhprof / ext-redis 时报一句并跳过采样（SamplingGuard 见 Core）。
        //    判断顺序不可换：available() 短路在前，enable=false 时才不会把"缺扩展"吞掉。
        if (!SamplingGuard::available() || !XhprofProfiler::isEnabled()) {
            return;
        }

        // 4) 起采样，并注册止点。
        Xhprof::xhprofStart();

        // 止点用 WordPress 的 shutdown 动作，而不是 PHP 的 register_shutdown_function：
        // 该动作由 WP 自己注册的 shutdown 回调触发，异常与致命错误下同样会到达——这就是
        // WordPress 上「finally」的位置。
        // 时序（已对 WP 6.4.3 的 wp-settings.php 逐行核对）：WP 在 **:146** 就
        // `register_shutdown_function('shutdown_action_hook')`，而 mu-plugin 在 :396 才被
        // include、`plugins_loaded` 在 :506 才触发——即本回调运行时，shutdown 动作一定已经
        // 有了触发者，:396 之后任何阶段的致命错误都不会让止点落空。
        // PHP_INT_MAX 让它在其它 shutdown 回调之后运行，采到最完整的一段。
        // $stopped 守卫：第二次 xhprof_disable() 返回空数据，会被当成一次真实采样写进
        // Redis（报告列表里多一条没有 main() 帧的垃圾数据）。WP 自己只触发一次 shutdown，
        // 但别的插件/对象缓存 drop-in 也可能手动 do_action('shutdown') 做清理。
        add_action('shutdown', function (): void {
            if ($this->stopped) {
                return;
            }
            $this->stopped = true;
            Xhprof::xhprofStop();
        }, PHP_INT_MAX);
    }

    /**
     * 报告页 / 静态资源短路，返回 true 表示本次请求已被处理、调用方应立即终止请求。
     *
     * 判路径只看 `uri()` 的 path 部分（`$_SERVER['REQUEST_URI']` 只含 path+query，
     * 见 RequestAdapter::uri() 的 R-1 说明）。不注册 rewrite 规则、不注册 REST 路由。
     */
    private function intercept(): bool
    {
        $path = parse_url($this->request->uri(), PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return false;
        }

        if ($path === self::REPORT_PATH) {
            $html = Xhprof::index();
            // 鉴权失败时 index() 已经用响应适配器发过 403 并返回 null，不能再补发一次 200。
            if (is_string($html)) {
                // no-cache：报告是即时数据；也避免「匿名 + ?token=xxx」访问被页面缓存
                // （WP 的页面缓存插件 / 反代）留存副本。两个字面量与 Drupal 控制器
                // 里的 $headers 一致（六框架同形）。WordPress 页缓存在本包之外，
                // 这条头是唯一能告诉缓存层「别存」的信号。
                $this->response
                    ->withStatus(200)
                    ->withBody($html)
                    ->withHeaders(['Cache-Control' => 'no-cache, private', 'Content-Type' => 'text/html; charset=UTF-8'])
                    ->send();
            }

            return true;
        }

        $prefix = $this->assetsPrefix();
        if ($prefix !== '' && str_starts_with($path, $prefix)) {
            StaticController::serve($this->request, $this->response)->send();

            return true;
        }

        return false;
    }

    /** assets_url 归一化成带尾斜杠的前缀；配成空串视为不启用资源短路。 */
    private function assetsPrefix(): string
    {
        $assetsUrl = $this->config->get('xhprof.assets_url', '/xhprof-assets');
        if (!is_string($assetsUrl) || $assetsUrl === '') {
            return '';
        }

        return rtrim($assetsUrl, '/') . '/';
    }
}
