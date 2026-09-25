<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Yii3\Adapter;

use Psr\Log\LoggerInterface as PsrLoggerInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;

/**
 * 缺省走 error_log()：Yii3 的 PSR-3 实现由 `yiisoft/log` 提供，但本包不依赖它，
 * 而中间件的构造参数里也没有容器（见 XhprofMiddleware 的注释）。
 * 需要把告警接进框架日志时，直接 `new LogAdapter($psrLogger)` 使用本适配器。
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

        error_log($context === [] ? $message : $message . ' ' . json_encode($context));
    }
}
