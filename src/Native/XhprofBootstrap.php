<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Native;

use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\SamplingGuard;
use ErikWang2013\Xhprof\Core\StaticController;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Native\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Native\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Native\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Native\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Native\Adapter\ResponseAdapter;

/**
 * 原生 PHP（无框架 Web 应用）入口类。
 *
 * 用法只有一行——放在应用入口文件（front controller，例如 `public/index.php`）的顶部：
 *
 *     \ErikWang2013\Xhprof\Native\XhprofBootstrap::start();
 *
 * 要改配置就把数组传进这一行（键集与另外十家的 config/xhprof.php 相同）：
 *
 *     \ErikWang2013\Xhprof\Native\XhprofBootstrap::start(['enable' => true, 'auth_token' => 'xxx']);
 *
 * **名字的由来**：原生 PHP 既没有中间件管线、也没有插件/扩展注册表，别家的
 * `XhprofMiddleware` / `XhprofPlugin` / `Extension\Xhprof` / `XhprofListener` 这类
 * 「宿主框架的扩展点」在这里**不存在**；这一行做的是「请求最开始装好适配器 → 判定短路
 * → 起采样」，这正是 bootstrap 的定义，所以叫它 Bootstrap。与 `Core\Xhprof::bootstrap()`
 * （写入五个适配器）不是一回事：那是本方法内部第三步做的事。
 *
 * **采样窗口 = 本行 → 进程 shutdown**（`register_shutdown_function`）。结构性限制照实说：
 * 窗口不包含入口文件里本行**之前**的代码（composer autoload、前端控制器的引导），
 * 也不包含本行之后、但由别的进程/扩展做的事（php-fpm 的请求解析、nginx 侧处理）。
 * 收窄范围靠配置 `ignore_url_arr`（`isIgnore()` 对 `uri()` 子串匹配，不改代码生效）。
 *
 * 报告页 `/xhprof` 与资源前缀（`assets_url`）在 `xhprofStart()` **之前**短路：两条路径
 * 都不采样、不落库，也不需要应用注册任何路由——原生应用本来也没有路由层。
 */
final class XhprofBootstrap
{
    /** 报告页路径，硬编码（与 Xhprof::$ignore_url_arr 的默认值一致，十一家同字面量）。 */
    private const REPORT_PATH = '/xhprof';

    private const DEFAULT_ASSETS_URL = '/xhprof-assets';

    private RequestInterface $request;

    private ResponseInterface $response;

    private ConfigInterface $config;

    private CacheInterface $cache;

    private LoggerInterface $logger;

    /**
     * 是否已停止（初值 true = 当前没有采样在跑）。
     *
     * 没有它，显式 `stop()` 与 shutdown 回调会各调一次 `xhprof_disable()`，第二次返回空数据，
     * 而 XHProfRunsDefault::save_run() 不会因此提前返回：照样 lPush 一个 run_id、照样写
     * request_log（wt/mu 全 0）、xhprof_log 里写的是 `serialize(null) === 'N;'`（非空字符串，
     * !empty 判真）——报告列表里凭空多一条没有数据的 run。已实测：无采样时调一次
     * `Xhprof::xhprofStop()` 就会多出一条。XhprofProfiler::stop() 自身没有幂等保护，
     * 所以这道守卫只能由入口类持有（与 WordPress / Joomla 两家入口同形）。
     */
    private bool $stopped = true;

    /**
     * 构造参数全部可选。`$cache` / `$logger` 留出注入点：站点若用非默认 Redis 连接
     * （配 `redis` 子数组或直接注入实例）或自定义日志出口可替换；单测也靠它注入
     * 内存版 CacheInterface 来断言「有没有落库」。
     *
     * @param array<string, mixed> $config 覆盖包内 `src/Native/config/xhprof.php` 的默认值
     */
    public function __construct(array $config = [], ?CacheInterface $cache = null, ?LoggerInterface $logger = null)
    {
        $this->request = new RequestAdapter();
        $this->response = new ResponseAdapter();
        $this->config = new ConfigAdapter($config);

        // 连接参数与另外十家的 Redis 适配器同名同默认（host/port/password/database/timeout），
        // 只在用户配置里出现——包内默认配置文件没有 `redis` 键（键集仍是那十个）。
        $redisOptions = $this->config->get('xhprof.redis', []);
        $this->cache = $cache ?? new RedisAdapter(is_array($redisOptions) ? $redisOptions : []);
        $this->logger = $logger ?? new LogAdapter();
    }

    /**
     * 在应用入口文件顶部调用这一行，返回本次请求的入口实例。
     *
     * 返回实例而不是 void：报告页/资源路径上本方法内部会 `exit`，正常业务路径上调用方
     * 一般不需要它；留这个返回值是为了「同一进程里要提前停表」的场景（例如应用自己
     * 在响应之后还有一段不打算采样的工作）与测试。也正因为要能拿到它，`stop()` 是 public。
     *
     * @param array<string, mixed> $config
     */
    public static function start(array $config = [], ?CacheInterface $cache = null, ?LoggerInterface $logger = null): self
    {
        $entry = new self($config, $cache, $logger);
        $entry->run();

        return $entry;
    }

    /**
     * 幂等止点：显式调用与 shutdown 回调竞争时只落库一次。
     */
    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        Xhprof::xhprofStop();
    }

    private function run(): void
    {
        // 1) 先 bootstrap：必须在 isEnabled() 与报告页判断之前。XhprofProfiler::$config
        //    是静态的，常驻进程里晚一步就会读到上一个请求的配置；报告页也要靠这里的
        //    request/response 适配器才渲染得出来。
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);

        // 2) 报告页 / 静态资源短路（在 xhprofStart() 之前，两条路径都不采样）
        if ($this->intercept()) {
            // 原生 PHP 没有框架的响应层：回调/中间件返回不代表请求结束，不 exit 的话
            // 入口文件后面那些应用代码会接着跑，把自己的输出叠在报告页（或资源）后面。
            // **这条 exit 的代价写在这里**：它终止本次请求的余下流程——本行之后的路由、
            // 容器引导、会话启动，以及应用自己注册的「请求结束」逻辑（日志、DB 收尾）
            // 都不会执行。PHP 仍会运行 shutdown 回调并把 ob_* 缓冲区刷出（此时缓冲区里
            // 只可能有应用在本行之前写下的内容，正常为空）。报告页与资源这两条路径本来
            // 就不该进应用——「短路」的代价就是这个，由用户在这一行里显式选择。
            exit;
        }

        // 3) 守卫：缺 ext-xhprof / ext-redis 时报一句并跳过采样（SamplingGuard 见 Core）。
        //    判断顺序不可换：available() 短路在前，enable=false 时才不会把"缺扩展"吞掉。
        if (!SamplingGuard::available() || !XhprofProfiler::isEnabled()) {
            return;
        }

        // 4) 起采样，并把止点挂到进程 shutdown 上。
        Xhprof::xhprofStart();
        $this->stopped = false;

        // 原生 PHP 里没有「框架钩子」可挂，`register_shutdown_function` 就是 finally 的位置：
        // 正常结束、exit、未捕获的 Error/异常都会到达（已实测）；SIGKILL / OOM killer
        // 不会——那两种情况下采样状态随进程一起消失，不存在残留给下一个请求的问题。
        // 注册排在前面的会先跑，这里只有一个回调，顺序无关。
        register_shutdown_function([$this, 'stop']);
    }

    /**
     * 报告页 / 静态资源短路，返回 true 表示本次请求已被处理、调用方应立即终止请求。
     *
     * 判路径只看 `uri()` 的 path 部分（`$_SERVER['REQUEST_URI']` 只含 path+query）。
     */
    private function intercept(): bool
    {
        $path = self::pathOnly($this->request->uri());
        if ($path === '') {
            return false;
        }

        if ($path === self::REPORT_PATH) {
            $html = Xhprof::index();
            // 鉴权失败时 index() 已经用响应适配器发过 403 并返回 null，不能再补发一次 200。
            if (is_string($html)) {
                // no-cache：报告是即时数据；也避免「匿名 + ?token=xxx」访问被页面缓存
                // （应用的缓存层 / 反代）留存副本。两个字面量与 Drupal 控制器里的
                // $headers 一致（六框架同形）。反代在本包之外，这条头是唯一能告诉
                // 缓存层「别存」的信号。
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

    /**
     * assets_url 归一化成**带尾斜杠**的前缀；配成空串视为不启用资源短路。
     *
     * 四条规则与 `Core\StaticController::uriPrefix()` 逐字同源（先取原始串再 rtrim、空串
     * 与非字符串都不启用、否则补一个尾斜杠）：本类按配置决定「要不要接管」，`serve()`
     * 按配置决定「认不认这条路径」，两处一旦分叉就是**空 body 的 200**（资源静默消失，
     * 报告页无样式无脚本）。同源的实现保证是「都读 bootstrap 后的 `Xhprof::getConfig()`」，
     * 而 bootstrap 就在短路之前（run() 第 1 步）。
     *
     * 之所以能这样读：Core 的 uriPrefix() 是 private，十一家入口类都各自归一化一次，
     * 语义由本仓库的用例（每家的 assets 边界表）钉住。
     */
    private function assetsPrefix(): string
    {
        $assetsUrl = $this->config->get('xhprof.assets_url', self::DEFAULT_ASSETS_URL);
        if (!is_string($assetsUrl) || $assetsUrl === '') {
            return '';
        }

        return rtrim($assetsUrl, '/') . '/';
    }

    /** 去掉 query 的路径；`$uri` 只含 path+query（R-1），所以交给 parse_url 切。 */
    private static function pathOnly(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }
}
