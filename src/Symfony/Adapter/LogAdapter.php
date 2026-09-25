<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Symfony\Adapter;

use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;
use Psr\Log\LoggerInterface as PsrLoggerInterface;

class LogAdapter implements LoggerInterface
{
    private ?PsrLoggerInterface $logger;

    /** 不传 logger 时静默（落库失败是旁路，不该因为没接日志而抛错） */
    public function __construct(?PsrLoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    public function error(string $message, array $context = []): void
    {
        $this->logger?->error($message, $context);
    }
}
