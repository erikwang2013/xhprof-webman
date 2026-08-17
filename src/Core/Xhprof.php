<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core;

use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;
use ErikWang2013\Xhprof\Core\XhprofLib\Display\XhprofDisplay;
use ErikWang2013\Xhprof\Core\XhprofLib\Utils\XHProfRunsDefault;

class Xhprof
{
    public static $time_limit = 0;
    public static $ignore_url_arr = ["/test"];
    public static $key_prefix = 'xhprof';
    public static $log_num = 1000;
    public static $view_wtred = 3;
    public static $ui_html = '';
    public static $symbol_lookup_url = "";

    public static ?RequestInterface $request = null;
    public static ?ResponseInterface $response = null;
    public static ?ConfigInterface $config = null;
    public static ?CacheInterface $cache = null;
    public static ?LoggerInterface $logger = null;

    private static bool $_hyperf = false;

    public static function getRequest(): RequestInterface
    {
        if (self::$_hyperf && class_exists(\Hyperf\Context\Context::class)) {
            return \Hyperf\Context\Context::get('xhprof.request');
        }
        return self::$request;
    }

    public static function getResponse(): ResponseInterface
    {
        if (self::$_hyperf && class_exists(\Hyperf\Context\Context::class)) {
            return \Hyperf\Context\Context::get('xhprof.response');
        }
        return self::$response;
    }

    public static function getCache(): CacheInterface
    {
        if (self::$_hyperf && class_exists(\Hyperf\Context\Context::class)) {
            return \Hyperf\Context\Context::get('xhprof.cache');
        }
        return self::$cache;
    }

    public static function getLogger(): LoggerInterface
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
            if (!is_string($rp) || $rp === '') continue;
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
        $params = $req->all();
        $echo_page = "<html lang=\"zh-CN\">";
        $assetsUrl = '';
        if ($cfg !== null) {
            $assetsUrl = $cfg->get('xhprof.assets_url', '');
        }
        if ($assetsUrl === '') {
            $assetsUrl = self::$ui_html ?: '/xhprof-assets';
        }
        $echo_page .= "<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>XHProf 性能分析报告</title>";
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
        XhprofProfiler::init();
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
            self::$response = new \ErikWang2013\Xhprof\Laravel\Adapter\ResponseAdapter(response());
            self::$config = new \ErikWang2013\Xhprof\Laravel\Adapter\ConfigAdapter();
            self::$cache = new \ErikWang2013\Xhprof\Laravel\Adapter\RedisAdapter();
            self::$logger = new \ErikWang2013\Xhprof\Laravel\Adapter\LogAdapter();
        } elseif (class_exists(\think\App::class)) {
            self::$request = new \ErikWang2013\Xhprof\Thinkphp\Adapter\RequestAdapter(app('request'));
            self::$response = new \ErikWang2013\Xhprof\Thinkphp\Adapter\ResponseAdapter(response());
            self::$config = new \ErikWang2013\Xhprof\Thinkphp\Adapter\ConfigAdapter();
            self::$cache = new \ErikWang2013\Xhprof\Thinkphp\Adapter\RedisAdapter();
            self::$logger = new \ErikWang2013\Xhprof\Thinkphp\Adapter\LogAdapter();
        } elseif (class_exists(\Hyperf\Framework\ApplicationContext::class)) {
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
