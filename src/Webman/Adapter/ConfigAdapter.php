<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Webman\Adapter;

use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;

class ConfigAdapter implements ConfigInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $first = 'plugin.aaron-dev.xhprof.' . array_shift($parts);
        $value = config($first);
        if ($value === null) {
            return $default;
        }
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
