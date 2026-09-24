<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Thinkphp\Adapter;

use think\facade\Cache;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;

class RedisAdapter implements CacheInterface
{
    private ?\Redis $redis = null;
    private bool $resolved = false;

    /**
     * 延迟解析底层 \Redis 连接。
     *
     * 不能在构造函数里解析：ThinkPHP 的 Cache::store('redis') 在应用未配置
     * stores.redis 时会抛 InvalidArgumentException（6.1 源码链路：
     * Cache::store → Manager::driver → createDriver → Cache::resolveConfig
     * → getStoreConfig → throw "Store [redis] not found."）。
     * 而 Thinkphp\Middleware 在每个请求上、早于 enable 判断就构造本适配器，
     * 于是「Redis 扩展可用但没配 think 的 redis store」会让整个应用每个请求 500。
     *
     * 这里吞掉异常：真正需要 Redis 的调用点（落库）已被
     * Core\XhprofProfiler::stop() 的兜底包住，性能插件不该拖垮业务。
     */
    private function redis(): ?\Redis
    {
        if (!$this->resolved) {
            $this->resolved = true;
            try {
                $handler = Cache::store('redis')->handler();
                $this->redis = $handler instanceof \Redis ? $handler : null;
            } catch (\Throwable $e) {
                $this->redis = null;
            }
        }
        return $this->redis;
    }

    public function get(string $key): mixed
    {
        // 直连底层 \Redis，避免 Cache 层 serialize 导致与其它框架裸存格式不兼容
        if ($r = $this->redis()) {
            return $r->get($key);
        }
        // fallback: 该 store 非 redis 驱动时退回框架 Cache 封装
        return Cache::store('redis')->get($key);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): mixed
    {
        // 同 Core\RedisAdapterTrait：TTL 0 会被 phpredis 的 SETEX 拒绝且不发送命令，
        // 写入静默丢失，故仅在 > 0 时透传 TTL。
        $ttl = (int) $ttl;
        $ttlArg = $ttl > 0 ? [$ttl] : [];
        if ($r = $this->redis()) {
            return $r->set($key, $value, ...$ttlArg);
        }
        return Cache::store('redis')->set($key, $value, ...$ttlArg);
    }

    public function mget(array $keys): array
    {
        // 空数组时 phpredis 返回 false（不发命令），而本方法声明 : array
        if (!$keys) return [];
        if ($r = $this->redis()) {
            return (array) $r->mget($keys);
        }
        return (array) Cache::store('redis')->handler()->mget($keys);
    }

    public function incr(string $key): int
    {
        if ($r = $this->redis()) {
            return $r->incr($key);
        }
        return Cache::store('redis')->inc($key);
    }

    public function lPush(string $key, mixed $value): int
    {
        if ($r = $this->redis()) {
            return $r->lPush($key, $value);
        }
        return Cache::store('redis')->handler()->lPush($key, $value);
    }

    public function rPop(string $key): mixed
    {
        if ($r = $this->redis()) {
            return $r->rPop($key);
        }
        return Cache::store('redis')->handler()->rPop($key);
    }

    public function lRange(string $key, int $start, int $end): array
    {
        if ($r = $this->redis()) {
            return $r->lRange($key, $start, $end);
        }
        return Cache::store('redis')->handler()->lRange($key, $start, $end);
    }

    public function del(string ...$keys): int
    {
        if ($r = $this->redis()) {
            return $r->del(...$keys);
        }
        return Cache::store('redis')->handler()->del(...$keys);
    }

    public function decr(string $key): int
    {
        if ($r = $this->redis()) {
            return $r->decr($key);
        }
        return Cache::store('redis')->handler()->decr($key);
    }
}
