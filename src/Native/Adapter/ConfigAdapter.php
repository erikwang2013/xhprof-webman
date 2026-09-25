<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Native\Adapter;

use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;

/**
 * 原生 PHP 配置适配器：默认值来自包内 `src/Native/config/xhprof.php`，
 * 调用方传给入口类的那一行里的数组覆盖默认值。
 *
 * 原生应用没有「框架配置容器」可读（更没有插件参数表那样的数据库来源），
 * 所以配置就是入口文件里那个数组——这也是十一家里唯一一个必须**显式**传配置的形态：
 * 不传就是包内默认值，与另外十家不传时同值。
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
        // (array) true 会变成 [true] —— 多实例（同一个进程里多次构造）下静默丢掉全部默认值。
        $defaults = is_file($file) ? (array) require $file : [];

        // R-7：合并用 array_replace，不要 array_replace_recursive——后者对 ignore_url_arr
        // 这类列表键逐下标合并，用户写 ['/health'] 会得到 ['/health', '/旧值2', ...] 静默失配。
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
