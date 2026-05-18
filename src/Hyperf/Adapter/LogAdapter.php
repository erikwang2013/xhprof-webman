<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Hyperf\Adapter;

use Psr\Log\LoggerInterface as PsrLoggerInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;

class LogAdapter implements LoggerInterface
{
    private PsrLoggerInterface $logger;

    public function __construct(PsrLoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }
}
