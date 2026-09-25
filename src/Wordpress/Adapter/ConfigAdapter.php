<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Wordpress\Adapter;

use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;

/**
 * WordPress 配置适配器：默认值来自包内 `src/Wordpress/config/xhprof.php`，
 * 用户显式传入的数组覆盖默认值。
 *
 * WordPress 的插件参数要读数据库（`#__extensions` 之于 Joomla 的同类问题），
 * 而配置读取发生在每个请求上，所以这里读包内文件而不是站点设置。
 */
class ConfigAdapter implements ConfigInterface
{
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $userConfig 覆盖包内默认值的用户配置
     */
    public function __construct(array $userConfig = [])
    {
        $file = dirname(__DIR__) . '/config/xhprof.php';
        // 必须用 require 而非 require_once：require_once 第二次返回 true 而不是数组，
        // (array) true 会变成 [true] —— 多实例（长驻进程/多站点）下静默丢掉全部默认值。
        $defaults = is_file($file) ? (array) require $file : [];

        // R-7：合并用 array_replace，不要 array_replace_recursive——后者对 ignore_url_arr
        // 这类列表键逐下标合并，用户写 ['/admin'] 会得到 ['/admin', '/旧值2', ...] 静默失配。
        $this->config = ['xhprof' => array_replace($defaults, $userConfig)];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // R-6：get('xhprof') 取整块（XhprofProfiler::bootstrap()），
        // get('xhprof.assets_url') 取叶子（Xhprof::index()）。同一条点号路径两者都满足。
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
