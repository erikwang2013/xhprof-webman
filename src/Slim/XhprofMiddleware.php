<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Slim;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;
use ErikWang2013\Xhprof\Core\StaticController as CoreStaticController;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Slim\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Slim\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Slim\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Slim\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Slim\Adapter\ResponseAdapter;

/**
 * Slim 4 入口。
 *
 * 挂载（**必须最后 add** —— Slim 的中间件栈是 LIFO，后 add 的在更外层、先执行；
 * 且必须晚于 addRoutingMiddleware()，否则报告页 /xhprof 在路由里不存在，
 * 请求根本到不了这里）：
 *
 *     $app->addRoutingMiddleware();
 *     $app->addErrorMiddleware(true, true, true);
 *     $app->add(new XhprofMiddleware(
 *         $app->getResponseFactory(),
 *         require __DIR__ . '/../config/xhprof.php',   // 可选，省略用包内默认值
 *         new RedisAdapter(new \Redis()),              // 可选，省略则惰性建连
 *         new LogAdapter($psrLogger),                  // 可选，省略则静默丢弃
 *     ));
 *
 * 为什么不写 `$app->add(XhprofMiddleware::class)`：类名字符串路径由 Slim 的
 * CallableResolver 解析，它只会执行 `new XhprofMiddleware($container)`，
 * 即**只传容器这一个参数**（没有容器时传 null）——构造函数收不到响应工厂，
 * 会在**请求期**（不是启动期）抛 TypeError。详见 tools/contracts/cases/Slim.php
 * 里钉住的实测结论。
 */
class XhprofMiddleware implements MiddlewareInterface
{
    /** 报告页路径，硬编码（与其它框架入口一致）。 */
    public const REPORT_PATH = '/xhprof';

    private ResponseFactoryInterface $responseFactory;

    /** @var array<string, mixed> */
    private array $config;

    private CacheInterface $cache;

    private LoggerInterface $logger;

    /**
     * @param array<string, mixed> $config 用户配置，与包内 src/Slim/config/xhprof.php 合并
     */
    public function __construct(
        ResponseFactoryInterface $responseFactory,
        array $config = [],
        ?CacheInterface $cache = null,
        ?LoggerInterface $logger = null
    ) {
        $this->responseFactory = $responseFactory;
        $this->config = $config;
        // 都兜到非 null：Xhprof::bootstrap() 只在参数非 null 时赋值，传 null 会
        // 在长驻进程里留下上一个请求的适配器。
        $this->cache = $cache ?? new RedisAdapter();
        $this->logger = $logger ?? new LogAdapter();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $req = new RequestAdapter($request);
        $res = new ResponseAdapter($this->responseFactory->createResponse());

        // 1) 先 bootstrap —— 必须在 isEnabled() 之前！
        //    否则长驻进程里会读到上一个请求的配置（XhprofProfiler::$config 是静态的）
        $cfg = new ConfigAdapter($this->config);
        Xhprof::bootstrap($req, $res, $cfg, $this->cache, $this->logger);

        $path = (string) (parse_url($req->uri(), PHP_URL_PATH) ?: '');

        // 2) 报告页短路（在 xhprofStart 之前，所以报告页自己不产生采样数据）
        if ($path === self::REPORT_PATH) {
            $html = Xhprof::index();
            if (!is_string($html)) {
                // index() 内部走了 deny()（例如 auth_token 不匹配）：
                // 它已经 withStatus()->withBody()->send() 过，把它直接交还给 Slim。
                return $html;
            }
            // no-cache：报告是即时数据；也避免「匿名 + ?token=xxx」访问被 HTTP 缓存
            // （反代 / 页面缓存）留存副本。两个字面量与 Drupal 控制器里的 $headers
            // 一致（六框架同形）。
            // Content-Type 必须显式给：PSR-7 响应不带默认值，Slim\ResponseEmitter 也不补，
            // 缺了它浏览器会按纯文本渲染报告页。WordPress 入口类同此处理。
            return $res
                ->withStatus(200)
                ->withHeaders(['Cache-Control' => 'no-cache, private', 'Content-Type' => 'text/html; charset=UTF-8'])
                ->withBody($html)
                ->send();
        }

        // 3) 静态资源短路
        $assetsUrl = (string) $cfg->get('xhprof.assets_url', '/xhprof-assets');
        if ($assetsUrl !== '' && str_starts_with($path, rtrim($assetsUrl, '/') . '/')) {
            return CoreStaticController::serve($req, $res)->send();
        }

        // 4) 守卫 + 采样
        $enabled = XhprofProfiler::isEnabled() && extension_loaded('xhprof');
        if ($enabled) {
            Xhprof::xhprofStart();
        }

        try {
            return $handler->handle($request);
        } finally {
            // 业务抛异常时也必须 stop —— 否则采样状态泄漏到同一进程的下一个请求
            if ($enabled) {
                Xhprof::xhprofStop();
            }
        }
    }
}
