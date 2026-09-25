<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Slim\Adapter;

use Psr\Log\LoggerInterface as PsrLoggerInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;

/**
 * 桥到 PSR-3。Slim 自身不提供 logger，所以这里允许不传 —— 不传就是静默丢弃。
 * （不能把 null 透传给 Xhprof::bootstrap()：bootstrap 只在非 null 时赋值，
 * 长驻进程里会留下上一个请求的 logger。）
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
        $this->logger?->error($message, $context);
    }
}
