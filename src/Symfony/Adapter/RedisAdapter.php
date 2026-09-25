<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Symfony\Adapter;

use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\RedisAdapterTrait;

class RedisAdapter implements CacheInterface
{
    use RedisAdapterTrait;

    private mixed $redis;

    /**
     * @param mixed $redis phpredis 实例（或任何有同名方法的对象/类名）。传 null 则懒连接本机 6379。
     */
    public function __construct(mixed $redis = null)
    {
        $this->redis = $redis;
    }

    protected function redis(): mixed
    {
        // Symfony 没有内置 Redis 门面，也没有全应用统一的 redis 配置可读，所以默认连本机。
        // 生产环境请在 services.yaml 里把应用自己的 \Redis 实例注入本适配器
        // （或直接给 XhprofListener 注入任意 CacheInterface 实现）。
        // 懒连接：只有真的要落库时才建连，采样被 time_limit 跳过时零开销。
        // 判据是 `=== null`，所以注入的实例不会被这条路径接管：它必须由调用方自己 connect()，
        // 否则第一条命令的行为交给 phpredis 决定（各版本不一致，别依赖）。
        if ($this->redis === null) {
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379, 1.0);
            $this->redis = $redis;
        }
        return $this->redis;
    }
}
