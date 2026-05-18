<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Thinkphp\Adapter;

use think\facade\Log;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;

class LogAdapter implements LoggerInterface
{
    public function error(string $message, array $context = []): void
    {
        Log::error($message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE));
    }
}
