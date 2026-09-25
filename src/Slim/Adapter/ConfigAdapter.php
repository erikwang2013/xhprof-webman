<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Slim\Adapter;

use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;

/**
 * Slim 没有自带配置容器，所以配置由调用方显式传入一个数组。
 * 传入的是 `xhprof` 那一块本身（与 src/Slim/config/xhprof.php 的形状一致），
 * 本类负责与包内默认值合并，并把它挂在 `xhprof.` 前缀下。
 */
class ConfigAdapter implements ConfigInterface
{
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $config 用户配置，覆盖包内默认值
     */
    public function __construct(array $config = [])
    {
        // R-7：必须用 array_replace 而不是 array_replace_recursive。
        // 后者对 ignore_url_arr 这类列表键会逐下标合并，用户写 ['/admin']
        // 会得到 ['/admin', '/旧值2', ...] 这种静默失配的结果。
        $this->config = array_replace(require __DIR__ . '/../config/xhprof.php', $config);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // R-6：get('xhprof') 必须返回整块，get('xhprof.assets_url') 必须返回叶子。
        // XhprofProfiler::bootstrap() 用前者、Xhprof::index() 用后者。
        $value = ['xhprof' => $this->config];
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
