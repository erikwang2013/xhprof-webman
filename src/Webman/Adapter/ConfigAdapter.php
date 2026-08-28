<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Webman\Adapter;

use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;

class ConfigAdapter implements ConfigInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        return config('plugin.aaron-dev.xhprof.' . $key) ?? $default;
    }
}
