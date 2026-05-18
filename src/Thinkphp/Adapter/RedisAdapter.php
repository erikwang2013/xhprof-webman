<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Thinkphp\Adapter;

use think\facade\Cache;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;

class RedisAdapter implements CacheInterface
{
    public function get(string $key): mixed
    {
        return Cache::store('redis')->get($key);
    }

    public function set(string $key, mixed $value): bool
    {
        return Cache::store('redis')->set($key, $value);
    }

    public function incr(string $key): int
    {
        return Cache::store('redis')->inc($key);
    }

    public function lPush(string $key, mixed $value): int
    {
        $redis = Cache::store('redis')->handler();
        return $redis->lPush($key, $value);
    }

    public function rPop(string $key): mixed
    {
        $redis = Cache::store('redis')->handler();
        return $redis->rPop($key);
    }

    public function lRange(string $key, int $start, int $end): array
    {
        $redis = Cache::store('redis')->handler();
        return $redis->lRange($key, $start, $end);
    }

    public function del(string ...$keys): int
    {
        $redis = Cache::store('redis')->handler();
        return $redis->del(...$keys);
    }

    public function decr(string $key): int
    {
        $redis = Cache::store('redis')->handler();
        return $redis->decr($key);
    }
}
