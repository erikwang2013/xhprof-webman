<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Webman\Adapter;

use support\Log;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;

class LogAdapter implements LoggerInterface
{
    public function error(string $message, array $context = []): void
    {
        Log::error($message, $context);
    }
}
