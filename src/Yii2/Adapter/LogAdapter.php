<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Yii2\Adapter;

use Psr\Log\LoggerInterface as PsrLoggerInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;

/**
 * 契约为准的日志适配器：可选注入 PSR-3 logger，缺省写 `error_log()`（与 Yii3 同形）。
 *
 * **刻意不走 `Yii::error()`**，两个理由：
 *  1. `Yii::error()`（`BaseYii.php:436`）在 `log` 组件尚未实例化时会现建一个**裸**
 *     `yii\log\Logger`，没有 targets 就把消息丢掉——而本适配器的调用点恰恰是
 *     `XhprofProfiler::stop()` 的兜底 catch 与 `SamplingGuard` 的缺扩展告警，
 *     正是「日志组件可能还没起来」的时刻。
 *  2. `Yii::error()` 需要全局 `Yii` 类存在，而这个适配器在单测里也要能独立跑。
 *
 * 要把告警接进 Yii2 的日志，注入一个 PSR-3 实现即可：`new LogAdapter($logger)`。
 */
class LogAdapter implements LoggerInterface
{
    private ?PsrLoggerInterface $logger;

    public function __construct(?PsrLoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    public function error(string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->error($message, $context);

            return;
        }

        // 与 Native/WordPress 两家同一条出口：落点由站点的 error_log ini 决定。
        error_log($context === [] ? $message : $message . ' ' . json_encode($context));
    }
}
