<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core;

/**
 * 采样前置条件：`ext-xhprof` 与 `ext-redis` **缺一不可**。
 *
 * 为什么两个都要判：缺 ext-xhprof 时 start() 会因函数未定义而致命；缺 ext-redis 时
 * 采样照跑（白付开销），而 stop() 的落库必然失败——它被 XhprofProfiler::stop() 的
 * Throwable 防线兜住，于是变成每个请求一行日志、报告页永远没有新数据；更糟的是
 * Xhprof::index() 里要读缓存（`\Redis` 类不存在）会变成未捕获错误 → 报告页 500。
 * 两个扩展都是 composer.json 的 require，同时缺的部署只能是自己少装了。
 *
 * 收在一处而不是十一个入口各判一次：判断与告警的粒度必须十一家一致，而「一次告警」
 * 是**进程级**状态（见 $warned 注释），分散在多处就会变成每个入口各刷一遍。
 * 放 Core 而不是 MiddlewareTrait：trait 的静态方法要先有 using class 才调得到，
 * 而十一个入口里只有 Laravel/Thinkphp/Yii3 三个用这个 trait，其余八个（Webman/Hyperf/
 * Slim/Symfony/WordPress/Joomla/Drupal/Native）没有可用的宿主类。
 */
final class SamplingGuard
{
    /**
     * 每个进程只告警一次，粒度照抄 Webman 入口类原有的实现（它用 private static $warned）。
     *
     * 不按请求告警：常驻进程（Swoole/RoadRunner/FrankenPHP）里缺扩展是**部署期**的
     * 事实，每请求刷一行只会把日志淹掉。也不按入口类告警：十一个入口共享本进程，
     * 第一个缺扩展的请求已经说清楚了。
     */
    private static bool $warned = false;

    /**
     * 采样前置条件是否成立；缺扩展时告警（每进程一次）并返回 false。
     *
     * 调用方一律写成 `$enabled = SamplingGuard::available() && XhprofProfiler::isEnabled();`
     * ——`available()` 必须短路在前，否则 enable=false 时缺扩展就完全静默了，
     * 与 Webman 入口类「不管开没开都告警」的既有行为不一致。
     */
    public static function available(): bool
    {
        if (extension_loaded('xhprof') && extension_loaded('redis')) {
            return true;
        }

        self::warnMissingOnce();

        return false;
    }

    private static function warnMissingOnce(): void
    {
        if (self::$warned) {
            return;
        }
        self::$warned = true;

        $logger = Xhprof::getLogger();
        if (!extension_loaded('xhprof')) {
            $logger?->error('xhprof扩展未安装，性能采样已跳过');
        }
        if (!extension_loaded('redis')) {
            $logger?->error('redis扩展未安装，性能采样已跳过');
        }
    }
}
