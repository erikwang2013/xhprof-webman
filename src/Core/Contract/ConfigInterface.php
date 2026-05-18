<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core\Contract;

interface ConfigInterface
{
    public function get(string $key, mixed $default = null): mixed;
}
