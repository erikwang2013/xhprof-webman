<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core;

/**
 * Redis 直连适配器的共享实现。redis() 返回静态门面类名（Webman/Laravel）
 * 或连接实例（Hyperf），call_user_func 同时支持两种形态。
 */
trait RedisAdapterTrait
{
    abstract protected function redis(): mixed;

    public function get(string $key): mixed
    {
        return call_user_func([$this->redis(), 'get'], $key);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): mixed
    {
        return call_user_func([$this->redis(), 'set'], $key, $value, (int) $ttl);
    }

    public function mget(array $keys): array
    {
        return call_user_func([$this->redis(), 'mget'], $keys);
    }

    public function incr(string $key): int
    {
        return call_user_func([$this->redis(), 'incr'], $key);
    }

    public function lPush(string $key, mixed $value): int
    {
        return call_user_func([$this->redis(), 'lpush'], $key, $value);
    }

    public function rPop(string $key): mixed
    {
        return call_user_func([$this->redis(), 'rpop'], $key);
    }

    public function lRange(string $key, int $start, int $end): array
    {
        return call_user_func([$this->redis(), 'lrange'], $key, $start, $end);
    }

    public function del(string ...$keys): int
    {
        return call_user_func([$this->redis(), 'del'], ...$keys);
    }

    public function decr(string $key): int
    {
        return call_user_func([$this->redis(), 'decr'], $key);
    }
}
