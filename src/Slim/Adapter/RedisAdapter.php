<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Slim\Adapter;

use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\RedisAdapterTrait;

/**
 * Slim 没有 Redis 门面，所以由调用方注入 phpredis 连接。
 * 不注入时惰性建连 —— 构造函数刻意不碰 ext-redis，没装扩展也不会在建适配器时就炸。
 */
class RedisAdapter implements CacheInterface
{
    use RedisAdapterTrait;

    private mixed $redis;

    public function __construct(mixed $redis = null)
    {
        $this->redis = $redis;
    }

    protected function redis(): mixed
    {
        return $this->redis ??= new \Redis();
    }
}
