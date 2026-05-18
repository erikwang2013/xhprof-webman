<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Webman\Adapter;

use support\Redis;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;

class RedisAdapter implements CacheInterface
{
    public function get(string $key): mixed
    {
        return Redis::get($key);
    }

    public function set(string $key, mixed $value): bool
    {
        return Redis::set($key, $value);
    }

    public function incr(string $key): int
    {
        return Redis::incr($key);
    }

    public function lPush(string $key, mixed $value): int
    {
        return Redis::lPush($key, $value);
    }

    public function rPop(string $key): mixed
    {
        return Redis::rpop($key);
    }

    public function lRange(string $key, int $start, int $end): array
    {
        return Redis::lrange($key, $start, $end);
    }

    public function del(string ...$keys): int
    {
        return Redis::del(...$keys);
    }

    public function decr(string $key): int
    {
        return Redis::decr($key);
    }
}
