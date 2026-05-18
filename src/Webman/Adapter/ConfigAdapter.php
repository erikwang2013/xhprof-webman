<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Webman\Adapter;

use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;

class ConfigAdapter implements ConfigInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        // Translate uniform key to webman-specific plugin config path
        $webmanKey = 'plugin.aaron-dev.xhprof.' . $key;
        return config($webmanKey, $default);
    }
}
