<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Symfony\Adapter;

use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;

class ConfigAdapter implements ConfigInterface
{
    /** @var array<string, array<string, mixed>> 包装一层 'xhprof' 根键，使点号查找同时满足两种取法 */
    private array $config;

    public function __construct(array $config = [])
    {
        // array_replace 而非 array_replace_recursive（R-7）：后者对 ignore_url_arr 这类
        // 列表键按下标合并，用户写 [] （什么都不忽略）会被默认值补回来。
        $defaults = require __DIR__ . '/../config/xhprof.php';
        $this->config = ['xhprof' => array_replace($defaults, $config)];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // 两种形态都要支持（R-6）：get('xhprof') 返回整块、get('xhprof.assets_url') 返回叶子
        $value = $this->config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
