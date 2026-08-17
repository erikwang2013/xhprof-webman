<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Thinkphp\Adapter;

use think\facade\Cache;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;

class RedisAdapter implements CacheInterface
{
    private ?\Redis $redis = null;

    public function __construct()
    {
        $handler = Cache::store('redis')->handler();
        if ($handler instanceof \Redis) {
            $this->redis = $handler;
        }
    }

    public function get(string $key): mixed
    {
        // 直连底层 \Redis，避免 Cache 层 serialize 导致与其它框架裸存格式不兼容
        if ($this->redis !== null) {
            return $this->redis->get($key);
        }
        // fallback: 该 store 非 redis 驱动时退回框架 Cache 封装
        return Cache::store('redis')->get($key);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): mixed
    {
        if ($this->redis !== null) {
            return $this->redis->set($key, $value, (int) $ttl);
        }
        return Cache::store('redis')->set($key, $value, (int) $ttl);
    }

    public function mget(array $keys): array
    {
        if ($this->redis !== null) {
            return $this->redis->mget($keys);
        }
        return Cache::store('redis')->handler()->mget($keys);
    }

    public function incr(string $key): int
    {
        if ($this->redis !== null) {
            return $this->redis->incr($key);
        }
        return Cache::store('redis')->inc($key);
    }

    public function lPush(string $key, mixed $value): int
    {
        if ($this->redis !== null) {
            return $this->redis->lPush($key, $value);
        }
        return Cache::store('redis')->handler()->lPush($key, $value);
    }

    public function rPop(string $key): mixed
    {
        if ($this->redis !== null) {
            return $this->redis->rPop($key);
        }
        return Cache::store('redis')->handler()->rPop($key);
    }

    public function lRange(string $key, int $start, int $end): array
    {
        if ($this->redis !== null) {
            return $this->redis->lRange($key, $start, $end);
        }
        return Cache::store('redis')->handler()->lRange($key, $start, $end);
    }

    public function del(string ...$keys): int
    {
        if ($this->redis !== null) {
            return $this->redis->del(...$keys);
        }
        return Cache::store('redis')->handler()->del(...$keys);
    }

    public function decr(string $key): int
    {
        if ($this->redis !== null) {
            return $this->redis->decr($key);
        }
        return Cache::store('redis')->handler()->decr($key);
    }
}
