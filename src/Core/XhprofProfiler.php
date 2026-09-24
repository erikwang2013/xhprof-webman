<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core;

use ErikWang2013\Xhprof\Core\XhprofLib\Utils\XHProfRunsDefault;

class XhprofProfiler
{
    private static ?array $config = null;

    public static function start(): void
    {
        xhprof_enable(XHPROF_FLAGS_NO_BUILTINS + XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY);
    }

    public static function stop(): void
    {
        // xhprof_disable() 始终执行，保证采样状态被清理
        $xhprof_data = xhprof_disable();
        try {
            XHProfRunsDefault::save_run($xhprof_data, "xhprof_foo");
        } catch (\Throwable $e) {
            // 落库是尽力而为的旁路。中间件在 finally 里调用本方法，phpredis 在
            // 连接中断/认证失败/超时时会抛 RedisException——不加这道防线，
            // 一个本来健康的请求会变成 500，且业务异常会被顶替掉。
            Xhprof::getLogger()?->error('Xhprof save_run failed: ' . $e->getMessage());
        }
    }

    public static function bootstrap(): void
    {
        $config = Xhprof::getConfig();
        if ($config === null) {
            return;
        }
        $pluginConfig = $config->get('xhprof', []);
        self::$config = $pluginConfig;
        Xhprof::$ignore_url_arr = $pluginConfig['ignore_url_arr'] ?? ['/xhprof'];
        Xhprof::$time_limit = (int) ($pluginConfig['time_limit'] ?? 0);
        Xhprof::$log_num = (int) ($pluginConfig['log_num'] ?? 1000);
        Xhprof::$view_wtred = (int) ($pluginConfig['view_wtred'] ?? 3);
        Xhprof::$key_prefix = (string) ($pluginConfig['key_prefix'] ?? 'xhprof');
        Xhprof::$log_ttl = (int) ($pluginConfig['log_ttl'] ?? 86400 * 7);
    }

    public static function isEnabled(): bool
    {
        $config = self::$config;
        if ($config === null) {
            $cfg = Xhprof::getConfig();
            if ($cfg === null) {
                return false;
            }
            $config = $cfg->get('xhprof', []);
        }
        return (bool) ($config['enable'] ?? false);
    }
}
