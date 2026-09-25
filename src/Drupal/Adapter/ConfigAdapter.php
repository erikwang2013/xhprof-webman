<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Drupal\Adapter;

use Drupal\Core\Config\ConfigFactoryInterface;
use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;

class ConfigAdapter implements ConfigInterface
{
    /**
     * 配置对象名，对应 drupal/xhprof/config/install/xhprof.settings.yml。
     * 契约里的根键是 'xhprof'，Drupal 侧按惯例叫 'xhprof.settings'，故在此映射。
     */
    private const CONFIG_NAME = 'xhprof.settings';

    private ConfigFactoryInterface $configFactory;

    public function __construct(ConfigFactoryInterface $configFactory)
    {
        $this->configFactory = $configFactory;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // R-6：契约要求 get('xhprof') 返回整块数组、get('xhprof.assets_url') 返回叶子。
        // 而 config.factory->get() 只按「配置对象名」取一次，键路径要往里再走一层，
        // 故首段判作配置对象名，其余段交给 ConfigBase::get() 走 NestedArray（支持 a.b 点路径）。
        [$name, $path] = array_pad(explode('.', $key, 2), 2, null);
        $config = $this->configFactory->get($name === 'xhprof' ? self::CONFIG_NAME : $name);

        if ($path === null) {
            $data = $config->get();
            // 配置不存在时 Drupal 返回空配置对象（不抛异常）→ 让 $default 语义成立
            return $data === [] ? $default : $data;
        }

        return $config->get($path) ?? $default;
    }
}
