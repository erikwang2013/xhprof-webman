<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Wordpress\Adapter;

use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\RedisAdapterTrait;

/**
 * WordPress 没有官方 Redis 封装（对象缓存的实现五花八门），直接用 phpredis 扩展：
 * 包本身已 require ext-redis，返回类名交给 trait 内部 call_user_func 调用。
 */
class RedisAdapter implements CacheInterface
{
    use RedisAdapterTrait;

    protected function redis(): string
    {
        return \Redis::class;
    }
}
