<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Drupal\Controller;

use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\StaticController;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Drupal\Adapter\RoutedPathRequestAdapter;
use ErikWang2013\Xhprof\Drupal\Adapter\ResponseAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 报告页与静态资源（drupal/xhprof/xhprof.routing.yml，_controller: 'xhprof.controller:xxx'）。
 *
 * 这是六个框架里唯一用路由器提供报告页的：Drupal 要标准模块（用户拍板），
 * 其余五个框架在入口类里自服务短路。
 *
 * 适配器由 XhprofMiddleware（priority 1000 = 最外层）在本请求内 bootstrap 过，
 * 控制器的执行发生在中间件栈之内，所以这里直接复用 Xhprof::getResponse()。
 * 报告页请求自身不落库：URI 命中默认的 ignore_url_arr（['/xhprof']），
 * save_run() 的 isIgnore() 会直接返回 false——/xhprof-assets 同理。
 */
class XhprofController
{
    public function report(): Response
    {
        $result = Xhprof::index();

        // 鉴权（auth_token）或 run/source 白名单校验失败时，Xhprof::deny() 已经
        // 通过适配器返回了带状态码的响应对象，直接交给内核。
        if ($result instanceof Response) {
            return $result;
        }

        // no-cache：报告是即时数据；也避免「匿名 + ?token=xxx」访问被页面缓存留存副本。
        // Content-Type 显式钉住（与其余入口类同一个字面量）：Symfony 7.4 的
        // ResponseHeaderBag 不再自带 Content-Type 默认值，它由 prepare() 在 filterResponse()
        // 阶段补——那一步还会先看 request 的 format，我们不希望报告页的类型取决于第三方的推断。
        $headers = ['Cache-Control' => 'no-cache, private', 'Content-Type' => 'text/html; charset=UTF-8'];
        $response = Xhprof::getResponse();

        $sent = $response instanceof ResponseInterface
            ? $response->withStatus(200)->withBody((string) $result)->withHeaders($headers)->send()
            : null;

        // 中间件没跑（例如单测直接实例化控制器）时兜底，保证路由永远有响应而不是 500
        return $sent instanceof Response ? $sent : new Response((string) $result, 200, $headers);
    }

    public function assets(Request $request): Response
    {
        // 用 RoutedPathRequestAdapter（uri() = 路由匹配到的 pathInfo），不用 RequestAdapter：
        // 站点装在子目录时 getRequestUri() 带 base path，StaticController 反推资源相对路径
        // 时前缀对不上，会把每个资源请求都判成未命中 → 空 200，报告页无 CSS 无 JS。
        $sent = StaticController::serve(new RoutedPathRequestAdapter($request), new ResponseAdapter(new Response()))->send();

        // 未命中/非法路径时 StaticController 返回空 body（不抛异常），
        // BinaryFileResponse 由适配器 file() 在命中时给出。
        return $sent instanceof Response ? $sent : new Response('', 404);
    }
}
