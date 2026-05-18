<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Hyperf\Adapter;

use Hyperf\Redis\Redis;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;

class RedisAdapter implements CacheInterface
{
    private Redis $redis;

    public function __construct(Redis $redis)
    {
        $this->redis = $redis;
    }

    public function get(string $key): mixed
    {
        return $this->redis->get($key);
    }

    public function set(string $key, mixed $value): mixed
    {
        return $this->redis->set($key, $value);
    }

    public function incr(string $key): int
    {
        return $this->redis->incr($key);
    }

    public function lPush(string $key, mixed $value): int
    {
        return $this->redis->lPush($key, $value);
    }

    public function rPop(string $key): mixed
    {
        return $this->redis->rPop($key);
    }

    public function lRange(string $key, int $start, int $end): array
    {
        return $this->redis->lRange($key, $start, $end);
    }

    public function del(string ...$keys): int
    {
        return $this->redis->del(...$keys);
    }

    public function decr(string $key): int
    {
        return $this->redis->decr($key);
    }
}
