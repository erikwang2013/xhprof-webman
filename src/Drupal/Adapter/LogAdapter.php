<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Drupal\Adapter;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;

class LogAdapter implements LoggerInterface
{
    private LoggerChannelFactoryInterface $loggerFactory;

    public function __construct(LoggerChannelFactoryInterface $loggerFactory)
    {
        $this->loggerFactory = $loggerFactory;
    }

    public function error(string $message, array $context = []): void
    {
        // 固定通道名 'xhprof'，站点日志里按通道过滤（admin/reports/dblog）
        $this->loggerFactory->get('xhprof')->error($message, $context);
    }
}
