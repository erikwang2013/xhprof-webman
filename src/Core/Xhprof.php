<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core;

use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;
use ErikWang2013\Xhprof\Core\XhprofLib\Display\XhprofDisplay;

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

    public static function getRequest(): RequestInterface
    {
        return self::$request;
    }

    public static function getResponse(): ResponseInterface
    {
        return self::$response;
    }

    public static function index(): string
    {
        $run = self::$request->get('run');
        $wts = self::$request->get('wts');
        $symbol = self::$request->get('symbol');
        $sort = self::$request->get('sort');
        $run1 = self::$request->get('run1');
        $run2 = self::$request->get('run2');
        $source = self::$request->get('source');
        $params = self::$request->all();
        $echo_page = "<html lang=\"zh-CN\">";
        $assetsUrl = '';
        if (self::$config !== null) {
            $assetsUrl = self::$config->get('xhprof.assets_url', '');
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
            self::$response = $response;
            self::$config = $config;
            self::$cache = $cache;
            self::$logger = $logger;
        } else {
            self::autoDetect();
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
