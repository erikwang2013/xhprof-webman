<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Drupal\Adapter;

use Symfony\Component\HttpFoundation\Request;

/**
 * 静态资源路由专用：uri() 给**路由匹配到的那个路径**，其余一律沿用 RequestAdapter。
 *
 * 为什么需要它：Drupal 的路由是在**剥掉 base path 的 pathInfo** 上匹配的
 * （RequestContext::fromRequest() 用的就是它），而 getRequestUri() 带 base path。
 * 站点装在子目录时（文档根 = /sites/app、站点在 /sites/app/sub）：
 *     getRequestUri() = '/sub/xhprof-assets/css/xhprof.css'
 *     pathInfo        =    '/xhprof-assets/css/xhprof.css'
 * Core\StaticController::getPathFromRequest() 拿 uri() 反推资源相对路径，前缀对不上就
 * 返回 null，serve() 于是给「空 body 的 200」——报告页没样式没 JS，且不报任何错。
 * 改成用路由匹配的那个路径反推，反推的输入就与路由的输入是同一个了
 * （中间件的路径守卫同理，见 XhprofMiddleware::isReportOrAssets()）。
 *
 * 只覆盖 uri()，不动 RequestAdapter 本身：那一份与 Symfony\Adapter\RequestAdapter 是
 * 同一份实现的两个副本，AdapterParityTest 会逐字节比对（只允许 namespace 行不同）。
 */
class RoutedPathRequestAdapter extends RequestAdapter
{
    /** 父类的 $request 是 private，子类取不到，故自己再留一份；其余方法都走父类。 */
    private Request $request;

    public function __construct(Request $request)
    {
        parent::__construct($request);
        $this->request = $request;
    }

    public function uri(): string
    {
        return $this->request->getPathInfo();
    }
}
