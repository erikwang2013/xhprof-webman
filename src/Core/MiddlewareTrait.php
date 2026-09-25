<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core;

use Closure;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\SamplingGuard;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;

/**
 * Laravel/Thinkphp 共享的采样包裹逻辑：适配器注入由子类通过
 * xhprofAdapters() 提供（两个框架的 Adapter 类同名但属于不同命名空间）。
 *
 * 报告页与静态资源由本 trait 自服务（不注册控制器、不注册路由）——两家的响应构造
 * 差异收在各自的 `Adapter\ResponseAdapter` 里（Laravel 造 Illuminate\Http\Response，
 * Thinkphp 造 think\Response），所以下面这段短路代码只写一次，改一处两家都生效。
 */
trait MiddlewareTrait
{
    abstract protected function xhprofAdapters($request): array;

    protected function runXhprof($request, Closure $next)
    {
        [$req, $res, $config, $cache, $logger] = $this->xhprofAdapters($request);

        // 1) 先 bootstrap，必须在 isEnabled() 与**短路判定**之前：报告页/资源前缀都从配置读，
        //    而 XhprofProfiler::$config 是静态的，常驻进程里不先写就会读到上一个请求的配置。
        Xhprof::bootstrap($req, $res, $config, $cache, $logger);

        // 2) 报告页 / 资源短路，都在 xhprofStart 之前，且不采样。
        //    报告页路径硬编码，与 Xhprof::$ignore_url_arr 的默认值同字面量；这里不能声明
        //    常量 —— 常量进 trait 是 PHP 8.2 才有的语法，本包下限 8.0（CI 在 8.0/8.1 上跑 php -l）。
        $path = self::pathOnly($req->uri());
        if ($path === '/xhprof') {
            return self::report($res);
        }
        $assetsPrefix = self::assetsPrefix();
        if ($assetsPrefix !== '' && str_starts_with($path, $assetsPrefix)) {
            return StaticController::serve($req, $res)->send();
        }

        // 3) 守卫：缺 ext-xhprof / ext-redis 时报一句并跳过采样（SamplingGuard 见 Core）。
        //    判断顺序不可换：available() 短路在前，enable=false 时才不会把"缺扩展"吞掉。
        $enabled = SamplingGuard::available() && XhprofProfiler::isEnabled();
        if ($enabled) {
            Xhprof::xhprofStart();
        }

        // 4) try/finally 保证 stop：业务抛异常时采样状态也要被清理并落库
        try {
            return $next($request);
        } finally {
            if ($enabled) {
                Xhprof::xhprofStop();
            }
        }
    }

    /**
     * 报告页：index() 返回字符串就自己造响应，返回响应对象就原样交还。
     *
     * 后者是 deny()（鉴权失败 / run|source 参数非法）的返回值：它已经
     * withStatus()->withBody()->send() 过，再包一层会得到第二个响应对象（正文会丢）。
     * 与 Yii3/Slim/Joomla 入口类同此处理。
     */
    private static function report(ResponseInterface $res): mixed
    {
        $html = Xhprof::index();
        if (!is_string($html)) {
            return $html;
        }

        // no-cache：报告是即时数据；也避免「匿名 + ?token=xxx」访问被页面缓存留存副本。
        // Content-Type 必须显式给（Laravel 的 ResponseHeaderBag 只补 text/html，Thinkphp
        // 的 Html 响应给的是 text/html; charset=utf-8，两家的默认值都不等于这一个）。
        // 两个字面量与 Drupal 控制器及其余入口类一致（十一家同形）。
        return $res
            ->withStatus(200)
            ->withHeaders(['Cache-Control' => 'no-cache, private', 'Content-Type' => 'text/html; charset=UTF-8'])
            ->withBody($html)
            ->send();
    }

    /**
     * assets_url 归一化成**带尾斜杠**的前缀；配成空串 = 不启用资源短路（返回空串，调用方必须判空）。
     *
     * 与 `StaticController::uriPrefix()` 同一套归一化（先取原串 → 非字符串/空串视为不启用
     * → 否则 rtrim 掉尾斜杠再补一个 '/'），并且**同源**：两边都是 bootstrap 之后读
     * `Xhprof::getConfig()`，所以「本中间件认下的资源路径」与「serve() 认下的资源路径」
     * 是同一批。分叉的后果不是报错而是**静默**：报告页 CSS/JS 全空，或资源请求被业务路由
     * 当成 404。前缀必带尾斜杠是为了不让 `/xhprof-assets-nope` 被当资源（那是业务路径）。
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

    /**
     * 去掉 query 的路径。uri() 返回的是「路径+query」（R-1：不含 scheme/host），
     * 所以只切问号即可；`/xhprof?run=xxx` 也必须落到报告页上。
     */
    private static function pathOnly(string $uri): string
    {
        $pos = strpos($uri, '?');

        return $pos === false ? $uri : substr($uri, 0, $pos);
    }
}
