<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Hyperf;

use Hyperf\HttpServer\Contract\RequestInterface as HyperfRequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HyperfResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use ErikWang2013\Xhprof\Core\SamplingGuard;
use ErikWang2013\Xhprof\Core\StaticController as CoreStaticController;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Hyperf\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Hyperf\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Hyperf\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Hyperf\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Hyperf\Adapter\LogAdapter;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\Redis;
use Psr\Log\LoggerInterface;

/**
 * Hyperf 入口类（全局中间件）。
 *
 * 报告页与静态资源由本中间件自服务：不注册 Controller、不注册路由。用户此前照 README
 * 注册的 `/xhprof` 与 `/xhprof-assets/{path:.+}` 会被本中间件遮蔽（中间件先跑），
 * 不会报错；资源前缀从 `xhprof.assets_url` 读，改配置不需要同步改路由文件。
 *
 * 短路返回的必须是**响应对象**而不是字符串：从中间件里 return 字符串等于交给
 * `CoreMiddleware::transferToResponse()`，它无条件加 `content-type: text/plain`
 * （README 的 Hyperf 一节记着同一件事），浏览器会把报告页按纯文本渲染。
 */
class Middleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $container = ApplicationContext::getContainer();

        $req = new RequestAdapter($container->get(HyperfRequestInterface::class));
        $res = new ResponseAdapter($container->get(HyperfResponseInterface::class));

        // 必须先声明协程环境：否则 bootstrap() 会把适配器写进进程共享的静态属性，
        // 并发协程之间互相覆盖（详见 Xhprof::markHyperfContext() 注释）。
        Xhprof::markHyperfContext();

        Xhprof::bootstrap(
            $req,
            $res,
            new ConfigAdapter($container->get(ConfigInterface::class)),
            new RedisAdapter($container->get(Redis::class)),
            new LogAdapter($container->get(LoggerInterface::class))
        );

        // 报告页 / 资源短路，都在 xhprofStart 之前，且不采样。必须在 bootstrap 之后：
        // 短路前缀从配置读，而配置是 bootstrap 才写进协程 Context 的。
        $path = self::pathOnly($req->uri());
        // 报告页路径硬编码，与 Xhprof::$ignore_url_arr 的默认值同字面量
        if ($path === '/xhprof') {
            return self::report($res);
        }
        $assetsPrefix = self::assetsPrefix();
        if ($assetsPrefix !== '' && str_starts_with($path, $assetsPrefix)) {
            return CoreStaticController::serve($req, $res)->send();
        }

        // 缺 ext-xhprof / ext-redis 时报一句并跳过采样（SamplingGuard 见 Core）；判断顺序
        // 不可换：available() 短路在前，enable=false 时才不会把"缺扩展"这件事吞掉。
        $enabled = SamplingGuard::available() && XhprofProfiler::isEnabled();
        if ($enabled) {
            Xhprof::xhprofStart();
        }

        try {
            return $handler->handle($request);
        } finally {
            if ($enabled) {
                Xhprof::xhprofStop();
            }
        }
    }

    /**
     * 报告页：index() 返回字符串就自己造响应，返回 PSR-7 响应对象就原样交还。
     *
     * 后者是 deny()（鉴权失败 / run|source 参数非法）的返回值：它已经
     * withStatus()->withBody()->send() 过，再包一层会得到第二个响应对象（正文会丢）。
     */
    private static function report(ResponseAdapter $res): ResponseInterface
    {
        $html = Xhprof::index();
        if (is_string($html)) {
            // no-cache：报告是即时数据，也避免「匿名 + ?token=xxx」访问被中间缓存留副本。
            // Content-Type 必须显式给：Hyperf 的响应不带默认值，而 CoreMiddleware 对
            // **字符串**返回值加的正是 text/plain（本包绕开它的唯一方式就是这个头）。
            // 两个字面量与 Drupal 控制器及另外五家入口类一致（十框架同形）。
            $res->withStatus(200)
                ->withHeaders(['Cache-Control' => 'no-cache, private', 'Content-Type' => 'text/html; charset=UTF-8'])
                ->withBody($html);
        }

        // Hyperf\HttpServer\Response 实现 Psr\Http\Message\ResponseInterface
        // （tools/contracts 那份真包源码：class Response implements PsrResponseInterface, ResponseInterface）
        return $res->send();
    }

    /**
     * assets_url 归一化成**带尾斜杠**的前缀；配成空串 = 不启用资源短路（返回空串，调用方必须判空）。
     *
     * 与 `Core\StaticController::uriPrefix()` 同一套归一化（先取原串 → 非字符串/空串视为不启用
     * → 否则 rtrim 掉尾斜杠再补一个 '/'），并且**同源**：两边都读 `Xhprof::getConfig()`
     * （Hyperf 下它是协程 Context 里那份），所以本中间件认下的资源路径与 serve() 认下的是同一批。
     * 前缀必带尾斜杠是为了不让 `/xhprof-assets-nope` 被当成资源。
     */
    private static function assetsPrefix(): string
    {
        $cfg = Xhprof::getConfig();
        $assetsUrl = $cfg !== null ? $cfg->get('xhprof.assets_url', '/xhprof-assets') : '/xhprof-assets';
        if (!is_string($assetsUrl) || $assetsUrl === '') {
            return '';
        }

        return rtrim($assetsUrl, '/') . '/';
    }

    /** 去掉 query 的路径（uri() 给的是「路径+query」，R-1：不含 scheme/host）。 */
    private static function pathOnly(string $uri): string
    {
        $pos = strpos($uri, '?');

        return $pos === false ? $uri : substr($uri, 0, $pos);
    }
}
