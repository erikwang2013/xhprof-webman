<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core\Contract;

interface LoggerInterface
{
    public function error(string $message, array $context = []): void;
}
