<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Thinkphp\Adapter;

use think\facade\Config;
use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;

class ConfigAdapter implements ConfigInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}
