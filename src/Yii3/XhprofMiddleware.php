<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Yii3;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;
use ErikWang2013\Xhprof\Core\StaticController;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Yii3\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Yii3\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Yii3\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Yii3\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Yii3\Adapter\ResponseAdapter;

/**
 * Yii3 入口类：PSR-15 中间件。
 *
 * 注册形状（已用真实 yiisoft/middleware-dispatcher 5.4 + yiisoft/di 1.4 验证，见
 * tools/contracts/cases/Yii3.php）：`withMiddlewares([...])` 是**实例方法**，
 * `MiddlewareDispatcher::__construct()` 只收 MiddlewareFactory 与 EventDispatcher，
 * 没有 `middlewares` 构造参数；数组里**第一位是最外层**（先执行、最晚结束）。
 *
 * 报告页与静态资源由本中间件自服务：不注册控制器、不注册路由。
 *
 * 不复用 Core\MiddlewareTrait：它的 runXhprof() 没有报告页短路，复用就得改那个
 * 共享文件（只读）。
 */
class XhprofMiddleware implements MiddlewareInterface
{
    /** 报告页路径，硬编码（与 Xhprof::$ignore_url_arr 的默认值一致）。 */
    private const REPORT_PATH = '/xhprof';

    private const DEFAULT_ASSETS_URL = '/xhprof-assets';

    private ResponseFactoryInterface $responseFactory;

    private ConfigAdapter $config;

    private CacheInterface $cache;

    private LoggerInterface $logger;

    /**
     * 后三个参数都是可选的，DI 容器解析不到时走反射默认值（已用真实 yiisoft/di 1.4 验证），
     * 所以 `withMiddlewares([XhprofMiddleware::class])` 这种最简写法是成立的。
     *
     * @param array<string, mixed>|null $config 覆盖 src/Yii3/config/xhprof.php 的同名字段；
     *                                          null（不传）即全部用默认值
     * @param CacheInterface|null $cache 已有缓存适配器时注入；不传则用 phpredis 直连
     *                                    （连接参数取 config 的 `redis` 子数组）。
     *                                    这个参数是必需的显式注入点：单测要用内存版
     *                                    CacheInterface 断言「是否落库」，而中间件不接受
     *                                    容器、也不该在测试里连真实 Redis。
     */
    public function __construct(
        ResponseFactoryInterface $responseFactory,
        ?array $config = null,
        ?CacheInterface $cache = null
    ) {
        $this->responseFactory = $responseFactory;
        $this->config = new ConfigAdapter($config ?? []);

        $redisOptions = $this->config->get('xhprof.redis', []);
        $this->cache = $cache ?? new RedisAdapter(is_array($redisOptions) ? $redisOptions : []);
        $this->logger = new LogAdapter();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $req = new RequestAdapter($request);
        $res = new ResponseAdapter($this->responseFactory);

        // 1) 先 bootstrap，必须在 isEnabled() 之前：XhprofProfiler::$config 是静态的，
        //    常驻进程里不先写就会读到上一个请求的配置。
        Xhprof::bootstrap($req, $res, $this->config, $this->cache, $this->logger);

        // 2) 报告页 / 资源短路，都在 xhprofStart 之前，且不采样
        $path = self::path($req->uri());
        if ($path === self::REPORT_PATH) {
            return $this->report($res);
        }
        if (str_starts_with($path, $this->assetsUrl() . '/')) {
            return $this->serveAssets($req, $res);
        }

        // 3) 守卫
        $enabled = XhprofProfiler::isEnabled() && extension_loaded('xhprof');
        if ($enabled) {
            Xhprof::xhprofStart();
        }

        // 4) try/finally 保证 stop：业务抛异常时采样状态也要被清理并落库
        try {
            return $handler->handle($request);
        } finally {
            if ($enabled) {
                Xhprof::xhprofStop();
            }
        }
    }

    private function report(ResponseAdapter $res): ResponseInterface
    {
        $html = Xhprof::index();

        // 鉴权失败 / run|source 参数非法时 Xhprof::index() 走的是 deny()，
        // 而 deny() 已经 withStatus()->withBody()->send() 过，返回值就是最终的
        // PSR-7 响应。再包一层会得到第二个响应对象（body 会丢）。
        if ($html instanceof ResponseInterface) {
            return $html;
        }

        // no-cache：报告是即时数据；也避免「匿名 + ?token=xxx」访问被页面缓存留存副本。
        // Content-Type 必须显式给：PSR-7 响应不带默认值，Yii3 的响应发送器也不补，
        // 缺了它浏览器会把报告页按纯文本渲染。与 Slim/WordPress 入口类同此处理。
        // 两个字面量与 Drupal 控制器里的 $headers 一致（六框架同形）。
        return $res
            ->withStatus(200)
            ->withHeaders(['Cache-Control' => 'no-cache, private', 'Content-Type' => 'text/html; charset=UTF-8'])
            ->withBody((string) $html)
            ->send();
    }

    private function serveAssets(RequestAdapter $req, ResponseAdapter $res): ResponseInterface
    {
        return StaticController::serve($req, $res)->send();
    }

    private function assetsUrl(): string
    {
        $assetsUrl = rtrim((string) $this->config->get('xhprof.assets_url', ''), '/');

        return $assetsUrl === '' ? self::DEFAULT_ASSETS_URL : $assetsUrl;
    }

    /**
     * 去掉 query 的路径。RequestAdapter::uri() 返回的已是「路径+query」，
     * 不含 scheme/host（R-1），所以这里只切问号。
     */
    private static function path(string $uri): string
    {
        $pos = strpos($uri, '?');

        return $pos === false ? $uri : substr($uri, 0, $pos);
    }
}
