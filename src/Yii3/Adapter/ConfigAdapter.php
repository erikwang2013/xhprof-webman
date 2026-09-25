<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Yii3\Adapter;

use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;

/**
 * Yii3 没有「全局配置」可取：PSR-15 中间件拿不到请求级的 config 服务，
 * 所以配置由入口类的构造参数传进来，这里只负责合并默认值 + 点号取值。
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
        return require dirname(__DIR__) . '/config/xhprof.php';
    }
}
