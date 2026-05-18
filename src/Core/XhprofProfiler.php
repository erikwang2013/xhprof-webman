<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core;

use ErikWang2013\Xhprof\Core\XhprofLib\Utils\XHProfRunsDefault;

class XhprofProfiler
{
    public static function init(): void
    {
        date_default_timezone_set('PRC');
        if (!extension_loaded('xhprof')) {
            Xhprof::$response->withBody('请安装xhprof扩展');
            return;
        }
        if (!extension_loaded('redis')) {
            Xhprof::$response->withBody('请安装redis扩展');
            return;
        }
    }

    public static function start(): void
    {
        xhprof_enable(XHPROF_FLAGS_NO_BUILTINS + XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY);
    }

    public static function stop(): void
    {
        $xhprof_data = xhprof_disable();
        XHProfRunsDefault::save_run($xhprof_data, "xhprof_foo");
    }

    public static function bootstrap(): void
    {
        $config = Xhprof::$config;
        if ($config === null) {
            return;
        }
        $pluginConfig = $config->get('xhprof', []);
        Xhprof::$ignore_url_arr = $pluginConfig['ignore_url_arr'] ?? ['/test'];
        Xhprof::$time_limit = (int) ($pluginConfig['time_limit'] ?? 0);
        Xhprof::$log_num = (int) ($pluginConfig['log_num'] ?? 1000);
        Xhprof::$view_wtred = (int) ($pluginConfig['view_wtred'] ?? 3);
    }

    public static function isEnabled(): bool
    {
        if (Xhprof::$config === null) {
            return false;
        }
        $config = Xhprof::$config->get('xhprof', []);
        return (bool) ($config['enable'] ?? false);
    }
}
