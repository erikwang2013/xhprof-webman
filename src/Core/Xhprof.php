<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core;

use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;
use ErikWang2013\Xhprof\Core\I18n\I18n;
use ErikWang2013\Xhprof\Core\XhprofLib\Display\XhprofDisplay;
use ErikWang2013\Xhprof\Core\XhprofLib\Utils\XHProfRunsDefault;

class Xhprof
{
    public static $time_limit = 0;
    public static $ignore_url_arr = ["/xhprof"];
    public static $key_prefix = 'xhprof';
    public static $log_num = 1000;
    public static $log_ttl = 86400 * 7;
    public static $view_wtred = 3;
    public static $ui_html = '';
    public static $symbol_lookup_url = "";

    public static ?RequestInterface $request = null;
    public static ?ResponseInterface $response = null;
    public static ?ConfigInterface $config = null;
    public static ?CacheInterface $cache = null;
    public static ?LoggerInterface $logger = null;

    private static bool $_hyperf = false;

    /**
     * 声明当前运行在 Hyperf 协程环境，使 bootstrap() 把适配器写入协程 Context
     * 而不是共享的静态属性。
     *
     * 必须由 Hyperf 中间件在 bootstrap() 之前显式调用：autoDetect() 里那处赋值
     * 只在无参 bootstrap() 时才会执行，而 Hyperf 中间件始终传参，
     * 因此旧代码的 $_hyperf 在生产路径上恒为 false——协程隔离从未生效，
     * 常驻 worker 内并发协程会互相覆盖 request/response/cache 适配器。
     */
    public static function markHyperfContext(): void
    {
        self::$_hyperf = true;
    }

    public static function getRequest(): ?RequestInterface
    {
        if (self::$_hyperf && class_exists(\Hyperf\Context\Context::class)) {
            return \Hyperf\Context\Context::get('xhprof.request');
        }
        return self::$request;
    }

    public static function getResponse(): ?ResponseInterface
    {
        if (self::$_hyperf && class_exists(\Hyperf\Context\Context::class)) {
            return \Hyperf\Context\Context::get('xhprof.response');
        }
        return self::$response;
    }

    public static function getCache(): ?CacheInterface
    {
        if (self::$_hyperf && class_exists(\Hyperf\Context\Context::class)) {
            return \Hyperf\Context\Context::get('xhprof.cache');
        }
        return self::$cache;
    }

    public static function getLogger(): ?LoggerInterface
    {
        if (self::$_hyperf && class_exists(\Hyperf\Context\Context::class)) {
            return \Hyperf\Context\Context::get('xhprof.logger');
        }
        return self::$logger;
    }

    public static function getConfig(): ?ConfigInterface
    {
        if (self::$_hyperf && class_exists(\Hyperf\Context\Context::class)) {
            return \Hyperf\Context\Context::get('xhprof.config');
        }
        return self::$config;
    }

    public static function index(): mixed
    {
        // 报告页要读缓存，而所有 CacheInterface 实现最终都要 `new \Redis()`
        // （缺扩展时是 "Class Redis not found" 的 Fatal error，浏览器上就是一片白加
        // 一行栈）。装了什么比「坏了」更该说清楚，所以这里先给一句能读的提示。
        // 与各入口类的「缺扩展就跳过采样」是同一件事的两半：那一半管写，这一半管读。
        if (!extension_loaded('redis')) {
            return self::deny('500 xhprof: ext-redis is not installed, so the report page cannot read profile data.', 500);
        }

        $req = self::getRequest();
        $cfg = self::getConfig();
        // 鉴权：配置了 auth_token 后，报告页必须带 ?token=xxx 才能访问
        $authToken = $cfg !== null ? $cfg->get('xhprof.auth_token', null) : null;
        if ($authToken !== null && $authToken !== '' && !hash_equals((string) $authToken, (string) $req->get('token', ''))) {
            return self::deny('403 Forbidden', 403);
        }
        // run_id / source 白名单校验，防止任意 key 读取
        $run = $req->get('run');
        $run1 = $req->get('run1');
        $run2 = $req->get('run2');
        $source = $req->get('source');
        foreach ([$run, $run1, $run2] as $rp) {
            if ($rp === null || $rp === '') continue;
            if (!is_string($rp)) {
                return self::deny('400 Bad Request', 400);
            }
            foreach (explode(',', $rp) as $rid) {
                if (!XHProfRunsDefault::xhprof_valid_run_id($rid)) {
                    return self::deny('400 Bad Request', 400);
                }
            }
        }
        if ($source !== null && !XHProfRunsDefault::xhprof_valid_source($source)) {
            return self::deny('400 Bad Request', 400);
        }
        $wts = $req->get('wts');
        $symbol = $req->get('symbol');
        $sort = $req->get('sort');
        // 这三个也来自查询串，形态可以是数组（`?sort[]=wt`）。以前它们被原样透传，
        // 直到 `isset($arr[$array])` / `explode(",", $array)` 抛 TypeError → 500。
        // 而同一批参数里 `sort` 传非法**字符串**是被优雅处理的（回落 wt + 记日志），
        // 说明数组形态只是没人想到过。类型不对就是坏请求，与 run/source 同样 400。
        foreach ([$wts, $symbol, $sort] as $scalar_param) {
            if ($scalar_param !== null && !is_string($scalar_param)) {
                return self::deny('400 Bad Request', 400);
            }
        }
        $params = $req->all();
        // 报告页语言：?lang= > 配置 xhprof.locale > Accept-Language > 兜底中文。
        // 四级都拿不到认识的语言码时 resolve() 返回 zh_CN，绝不抛异常。
        I18n::setLocale(I18n::resolve($req, $cfg));
        $echo_page = '<html lang="' . I18n::htmlLang() . '"'
            . (I18n::dir() === 'rtl' ? ' dir="rtl"' : '') . '>';
        $assetsUrl = '';
        if ($cfg !== null) {
            $assetsUrl = $cfg->get('xhprof.assets_url', '');
        }
        if ($assetsUrl === '') {
            $assetsUrl = self::$ui_html ?: '/xhprof-assets';
        }
        $echo_page .= "<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>" . I18n::plain('report.title') . "</title>";
        $echo_page .= XhprofDisplay::xhprof_include_js_css($assetsUrl);
        $echo_page .= "</head>";
        $echo_page .= "<body>";
        $echo_page .= XhprofDisplay::displayXHProfReport(
            $params,
            $source,
            $run,
            $wts,
            $symbol,
            $sort,
            $run1,
            $run2
        );
        $echo_page .= "</body>";
        $echo_page .= "</html>";
        return $echo_page;
    }

    private static function deny(string $body, int $status): mixed
    {
        $res = self::getResponse();
        if ($res !== null) {
            return $res->withStatus($status)->withBody($body)->send();
        }
        http_response_code($status);
        return $body;
    }

    public static function xhprofStart(): void
    {
        // 扩展检查由各框架 middleware 负责，走到这里说明已通过
        XhprofProfiler::start();
    }

    public static function xhprofStop(): void
    {
        XhprofProfiler::stop();
    }

    public static function bootstrap(
        ?RequestInterface $request = null,
        ?ResponseInterface $response = null,
        ?ConfigInterface $config = null,
        ?CacheInterface $cache = null,
        ?LoggerInterface $logger = null
    ): void {
        if ($request !== null) {
            self::$request = $request;
            if ($response !== null) self::$response = $response;
            if ($config !== null) self::$config = $config;
            if ($cache !== null) self::$cache = $cache;
            if ($logger !== null) self::$logger = $logger;
        } else {
            self::autoDetect();
        }
        // Hyperf coroutine safety: store adapters in coroutine-local Context
        if (self::$_hyperf && class_exists(\Hyperf\Context\Context::class)) {
            \Hyperf\Context\Context::set('xhprof.request', self::$request);
            \Hyperf\Context\Context::set('xhprof.response', self::$response);
            \Hyperf\Context\Context::set('xhprof.config', self::$config);
            \Hyperf\Context\Context::set('xhprof.cache', self::$cache);
            \Hyperf\Context\Context::set('xhprof.logger', self::$logger);
        }
        XhprofProfiler::bootstrap();
    }

    private static function autoDetect(): void
    {
        if (class_exists(\Webman\App::class)) {
            self::$request = new \ErikWang2013\Xhprof\Webman\Adapter\RequestAdapter(request());
            self::$response = new \ErikWang2013\Xhprof\Webman\Adapter\ResponseAdapter(response());
            self::$config = new \ErikWang2013\Xhprof\Webman\Adapter\ConfigAdapter();
            self::$cache = new \ErikWang2013\Xhprof\Webman\Adapter\RedisAdapter();
            self::$logger = new \ErikWang2013\Xhprof\Webman\Adapter\LogAdapter();
        } elseif (class_exists(\Illuminate\Foundation\Application::class)) {
            self::$request = new \ErikWang2013\Xhprof\Laravel\Adapter\RequestAdapter(app('request'));
            // 必须传 ''，不能无参：Laravel 的 response() 在 func_num_args()===0 时
            // 返回的是 ResponseFactory 而非响应对象，而适配器里 `$response ?? response('')`
            // 拦不住它（工厂非 null）。此后 Xhprof::deny() 调 withStatus() 会命中
            // Macroable::__call 抛 BadMethodCallException —— 403/400 变成 500。
            self::$response = new \ErikWang2013\Xhprof\Laravel\Adapter\ResponseAdapter(response(''));
            self::$config = new \ErikWang2013\Xhprof\Laravel\Adapter\ConfigAdapter();
            self::$cache = new \ErikWang2013\Xhprof\Laravel\Adapter\RedisAdapter();
            self::$logger = new \ErikWang2013\Xhprof\Laravel\Adapter\LogAdapter();
        } elseif (class_exists(\think\App::class)) {
            self::$request = new \ErikWang2013\Xhprof\Thinkphp\Adapter\RequestAdapter(app('request'));
            self::$response = new \ErikWang2013\Xhprof\Thinkphp\Adapter\ResponseAdapter(response());
            self::$config = new \ErikWang2013\Xhprof\Thinkphp\Adapter\ConfigAdapter();
            self::$cache = new \ErikWang2013\Xhprof\Thinkphp\Adapter\RedisAdapter();
            self::$logger = new \ErikWang2013\Xhprof\Thinkphp\Adapter\LogAdapter();
        // 注意：Hyperf 3.x 没有 \Hyperf\Framework\ApplicationContext（该命名空间下
        // 只有 ApplicationFactory/Bootstrap/ConfigProvider/Event/Exception/Logger）。
        // 旧代码探测的是这个不存在的类，导致本分支永不命中，README 里无参
        // Xhprof::bootstrap() 的用法会直接抛 "Unsupported framework"。
        } elseif (class_exists(\Hyperf\Context\ApplicationContext::class)) {
            self::$_hyperf = true;
            $container = \Hyperf\Context\ApplicationContext::getContainer();
            self::$request = new \ErikWang2013\Xhprof\Hyperf\Adapter\RequestAdapter($container->get(\Hyperf\HttpServer\Request::class));
            self::$response = new \ErikWang2013\Xhprof\Hyperf\Adapter\ResponseAdapter($container->get(\Hyperf\HttpServer\Response::class));
            self::$config = new \ErikWang2013\Xhprof\Hyperf\Adapter\ConfigAdapter($container->get(\Hyperf\Contract\ConfigInterface::class));
            self::$cache = new \ErikWang2013\Xhprof\Hyperf\Adapter\RedisAdapter($container->get(\Hyperf\Redis\Redis::class));
            self::$logger = new \ErikWang2013\Xhprof\Hyperf\Adapter\LogAdapter($container->get(\Psr\Log\LoggerInterface::class));
        } else {
            throw new \RuntimeException('ErikWang2013\Xhprof: Unsupported framework. Use Xhprof::bootstrap() to inject adapters manually.');
        }
    }
}
