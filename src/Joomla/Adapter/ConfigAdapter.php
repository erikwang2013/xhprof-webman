<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Joomla\Adapter;

use Joomla\Registry\Registry;
use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;

/**
 * 包内 config/xhprof.php + 用户覆盖项 → 契约 ConfigInterface。
 *
 * 刻意**不**读插件参数（#__extensions.params）：那要读数据库，而这份配置在每个
 * 请求（含被采样请求）上都会被读到。
 */
class ConfigAdapter implements ConfigInterface
{
    private Registry $registry;

    /**
     * @param array<string, mixed> $packageConfig 包内 src/Joomla/config/xhprof.php 的内容
     * @param array<string, mixed> $userConfig    用户显式传入的覆盖项
     */
    public function __construct(array $packageConfig = [], array $userConfig = [])
    {
        // 契约 R-7：必须 array_replace，不能 array_replace_recursive。
        // 后者对 ignore_url_arr 这类列表键逐下标合并，用户写 ['/admin'] 而包内是
        // ['/xhprof'] 时会得到 ['/admin']（看似对），但包内一旦有多项就变成
        // ['/admin', '/包内第 2 项', ...] 的静默失配。整体替换才是「用户说了算」。
        $this->registry = new Registry([
            'xhprof' => array_replace($packageConfig, $userConfig),
        ]);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->registry->get($key, $default);

        // Registry 把对象节点存成 stdClass：get('xhprof') 返回的是 stdClass 而不是数组，
        // 而 XhprofProfiler::bootstrap() 拿到它之后直接 $pluginConfig['ignore_url_arr'] ——
        // 对 stdClass 做下标访问是 Error（不是 warning），bootstrap 里没有 catch，
        // 结果就是每个请求 500。契约 R-6 要求 get('xhprof') 与 get('xhprof.assets_url')
        // 双形态都可用，故这里把对象节点归一化成数组。
        //
        // **?? / isset() / empty() 都救不了**：这三种写法对 stdClass 的下标访问一样抛
        // Error（PHP 8.3.7 实测：$o['k'] ?? $d、isset($o['k'])、empty($o['k']) 全抛
        // "Cannot use object of type stdClass as array"）。只有先转数组才行。
        //
        // 只做一层：Registry 的 bindData 对数字键（列表）本就保留数组形态，
        // 9 个配置项里没有更深的对象节点。
        return $value instanceof \stdClass ? (array) $value : $value;
    }
}
