<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Webman;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

use ErikWang2013\Xhprof\Core\SamplingGuard;
use ErikWang2013\Xhprof\Core\StaticController as CoreStaticController;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Webman\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Webman\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Webman\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Webman\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Webman\Adapter\LogAdapter;

/**
 * Webman 入口类（全局中间件）。
 *
 * 报告页与静态资源由本中间件自服务：不注册控制器、不注册路由。用户此前照 README
 * 在 config/route.php 里注册的那两条路由被本中间件遮蔽（中间件先跑），不会报错；
 * 资源前缀从 `xhprof.assets_url` 读，改配置不需要同步改路由文件。
 *
 * 注意不要用 `Webman\StaticController`（那是给用户路由用的包装）：它自己 new 一个
 * 响应对象，而这里必须复用 `$res` —— 报告页那条链接下来的 `$res->send()` 读的就是
 * 同一个对象（deny() 已经就地改过它）。
 */
class XhprofMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $req = new RequestAdapter($request);
        $res = new ResponseAdapter(response(''));

        // 1) 先 bootstrap，必须在 isEnabled() 与**短路判定**之前：短路前缀从配置读，
        //    而 XhprofProfiler::$config 是静态的，常驻进程里不先写就会读到上一个请求的配置。
        Xhprof::bootstrap($req, $res, new ConfigAdapter(), new RedisAdapter(), new LogAdapter());

        // 2) 报告页 / 资源短路，都在 xhprofStart 之前，且不采样
        $path = self::pathOnly($req->uri());
        // 报告页路径硬编码，与 Xhprof::$ignore_url_arr 的默认值同字面量
        if ($path === '/xhprof') {
            $html = Xhprof::index();
            if (is_string($html)) {
                // no-cache：报告是即时数据，也避免「匿名 + ?token=xxx」访问被中间缓存留副本。
                // Content-Type 必须显式给：workerman 的响应不带默认值，缺了浏览器按纯文本渲染。
                // 两个字面量与 Drupal 控制器及其余入口类一致（十一家同形）。
                $res->withStatus(200)
                    ->withHeaders(['Cache-Control' => 'no-cache, private', 'Content-Type' => 'text/html; charset=UTF-8'])
                    ->withBody($html);
            }
            // index() 走 deny()（403/400/500）时已经就地改过 $res 并 send 过，原样交还
            return $res->send();
        }
        $assetsPrefix = self::assetsPrefix();
        if ($assetsPrefix !== '' && str_starts_with($path, $assetsPrefix)) {
            return CoreStaticController::serve($req, $res)->send();
        }

        // 3) 缺扩展时 available() 已经记录"性能采样已跳过"，故必须连它一起判断：
        // 若仍启动采样，既白付采样开销，落库也必然失败——日志与实际行为自相矛盾。
        // 判断与告警都收在 Core\SamplingGuard（十一家入口共用一份，含缺哪个扩展的文案）。
        $enabled = SamplingGuard::available() && XhprofProfiler::isEnabled();

        if ($enabled) {
            Xhprof::xhprofStart();
        }

        try {
            return $handler($request);
        } finally {
            if ($enabled) {
                Xhprof::xhprofStop();
            }
        }
    }

    /**
     * assets_url 归一化成**带尾斜杠**的前缀；配成空串 = 不启用资源短路（返回空串，调用方必须判空）。
     *
     * 与 `Core\StaticController::uriPrefix()` 同一套归一化（先取原串 → 非字符串/空串视为不启用
     * → 否则 rtrim 掉尾斜杠再补一个 '/'），并且**同源**：两边都是 bootstrap 之后读
     * `Xhprof::getConfig()`。分叉的后果不是报错而是**静默**：报告页 CSS/JS 全空、业务路由也拿不到
     * 那些路径。前缀必带尾斜杠是为了不让 `/xhprof-assets-nope` 被当成资源。
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
