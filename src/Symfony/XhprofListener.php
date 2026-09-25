<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Symfony;

use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\StaticController;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Symfony\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Symfony\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Symfony\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Symfony\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Symfony\Adapter\ResponseAdapter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Symfony 入口：注册为 kernel.event_subscriber 即可，不需要控制器与路由。
 *
 * services.yaml:
 *   ErikWang2013\Xhprof\Symfony\XhprofListener:
 *     arguments: [{ enable: true, auth_token: 'xxx' }]   # 可选，缺省用包内 config/xhprof.php
 *     tags: [kernel.event_subscriber]
 */
class XhprofListener implements EventSubscriberInterface
{
    /** 报告页路径与 ignore_url_arr 默认值一致（Core\Xhprof 里硬编码的同一个路径） */
    private const REPORT_PATH = '/xhprof';

    /**
     * 采样是否已终止。xhprof_disable() 二次调用返回 null，不加这道守卫会把空采样
     * 写进 Redis（每个请求多一条空记录），并且二次 xhprof_enable() 的状态也会乱。
     */
    private static bool $stopped = true;

    /**
     * register_shutdown_function 每进程只注册一次：常驻进程（RoadRunner/Swoole）里
     * 按请求注册会无限堆积，且旧请求留下的回调会停掉新请求的采样。
     * 注册的闭包读的是**当前**静态状态，所以一次注册就够。
     */
    private static bool $shutdownRegistered = false;

    private ConfigInterface $config;
    private ?CacheInterface $cache;
    private ?LoggerInterface $logger;

    /**
     * @param array<string, mixed> $config 覆盖包内 config/xhprof.php 的键（见 ConfigAdapter）
     */
    public function __construct(array $config = [], ?CacheInterface $cache = null, ?LoggerInterface $logger = null)
    {
        $this->config = new ConfigAdapter($config);
        $this->cache = $cache ?? new RedisAdapter();
        $this->logger = $logger ?? new LogAdapter();
    }

    public static function getSubscribedEvents(): array
    {
        // kernel.request 10000：早于 RouterListener(32) / Firewall(8) / SessionListener(128)，
        // 路由与鉴权耗时也要采到；kernel.response -10000：晚于 ResponseListener(0)，
        // 等 Session/缓存头都改完再落库。
        return [
            KernelEvents::REQUEST => ['onRequest', 10000],
            KernelEvents::RESPONSE => ['onResponse', -10000],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        // ESI / fragment / forward() 产生的是子请求。子请求不参与采样——否则它自己的
        // kernel.response 会把主请求的采样提前 stop()，主请求后半段整段丢失（静默的数据丢失）。
        if (!$event->isMainRequest()) {
            return;
        }

        $req = new RequestAdapter($event->getRequest());
        $res = new ResponseAdapter(new Response());

        // 必须先 bootstrap（在 isEnabled() 之前）：XhprofProfiler::$config 是静态的，
        // 常驻进程里否则会读到上一个请求的配置。
        Xhprof::bootstrap($req, $res, $this->config, $this->cache, $this->logger);

        $uri = $req->uri();
        if ($this->pathOf($uri) === self::REPORT_PATH) {
            $this->serveReport($event, $req, $res);
            return;
        }

        // 静态资源：包内直接提供，不注册路由
        $assetsUrl = (string) $this->config->get('xhprof.assets_url', '/xhprof-assets');
        if ($assetsUrl !== '' && str_starts_with($this->pathOf($uri), $assetsUrl . '/')) {
            $served = StaticController::serve($req, $res)->send();
            if ($served instanceof Response) {
                $event->setResponse($served);
            }
            return;
        }

        if (!XhprofProfiler::isEnabled() || !extension_loaded('xhprof')) {
            return;
        }

        self::$stopped = false;
        self::registerShutdownStop();
        Xhprof::xhprofStart();
    }

    public function onResponse(ResponseEvent $event): void
    {
        // 同 onRequest：子请求的响应绝不能终止主请求的采样
        if (!$event->isMainRequest()) {
            return;
        }
        self::stopSampling();
    }

    /**
     * 幂等终止采样。onResponse() 与 shutdown 兜底共用；二次调用无害。
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
     * HttpKernel 在没人产出响应时会重抛（kernel.exception 无人处理），kernel.response
     * 不触发 —— 采样状态就此泄漏到下一个请求（FPM 下是下一个请求，RoadRunner 下是同一进程的后续请求）。
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

    /** 报告页：不经控制器，直接 setResponse 短路掉 HttpKernel（setResponse 会停止事件传播） */
    private function serveReport(RequestEvent $event, RequestInterface $req, ResponseAdapter $res): void
    {
        $body = Xhprof::index();
        if ($body instanceof Response) {
            // 鉴权失败 / 参数非法的分支：Xhprof::deny() 已经用适配器构造好 403/400
            $event->setResponse($body);
            return;
        }

        // no-cache：报告是即时数据；也避免「匿名 + ?token=xxx」访问被 HTTP 缓存留存副本。
        // Content-Type 必须自己显式设：`new Response($html, 200)` 构造后 Content-Type 是 **NULL**
        // （实测），要靠 FrameworkBundle 的 ResponseListener@0 调 prepare() 才补上 —— 那是三处
        // 脆弱依赖（@0 必须在位且顺序对 / 需要 symfony/mime / charset 取自监听器的构造参数，
        // 实测同一份 HTML 补出的是 'UTF-8' 还是 'utf-8' 取决于谁设的 charset）。
        // 显式设了以后 prepare() 原样保留（环里有断言）。与另外五个框架统一，
        // 两个字面量与 Drupal 控制器里的 $headers 一致（六框架同形）。
        /** @var Response $response */
        $response = $res->withStatus(200)
            ->withHeaders(['Cache-Control' => 'no-cache, private', 'Content-Type' => 'text/html; charset=UTF-8'])
            ->withBody((string) $body)
            ->send();
        $event->setResponse($response);
    }

    /** uri() 去掉 query 后的路径（Core\StaticController 也是这么解析的） */
    private function pathOf(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) ? $path : '';
    }
}
