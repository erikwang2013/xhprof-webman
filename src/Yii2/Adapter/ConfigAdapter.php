<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Yii2\Adapter;

use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;

/**
 * Yii2 配置适配器：默认值来自包内 `src/Yii2/config/xhprof.php`，
 * 用户配置来自 `config/web.php` 里 bootstrap 数组定义的 `config` 键。
 *
 * 为什么是「bootstrap 数组定义」而不是 `Yii::$app->params['xhprof']`：与 Symfony 的
 * services.yaml arguments、Yii3 的 DI 构造参数同一个形状——一个显式的注册点，配置就写在
 * 它旁边；params 那条路要么再多一个「谁覆盖谁」的口径，要么在自动引导路径上隐形。
 */
class ConfigAdapter implements ConfigInterface
{
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $config 用户配置，整段覆盖同名的默认值
     */
    public function __construct(array $config = [])
    {
        $this->config = array_replace(self::defaults(), $config);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // 两种形态都必须支持：
        //  - 'xhprof'            → 整块数组（XhprofProfiler::bootstrap() 用）
        //  - 'xhprof.assets_url' → 叶子值（Xhprof::index() 用）
        if ($key === 'xhprof') {
            return $this->config;
        }

        $path = str_starts_with($key, 'xhprof.') ? substr($key, strlen('xhprof.')) : $key;
        $value = $this->config;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaults(): array
    {
        // 必须用 require 而非 require_once：require_once 第二次返回 true 而不是数组，
        // (array) true 会变成 [true] —— 多实例（同一个进程里多次构造）下静默丢掉全部默认值。
        return (array) require dirname(__DIR__) . '/config/xhprof.php';
    }
}
