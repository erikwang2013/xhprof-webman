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
        // 走 setex 而不是 set($k,$v,$ttl)：Webman/Laravel 底层是 illuminate 的
        // PhpRedisConnection，其 set() 第三个参数是「过期单位字符串」(EX/PX/NX…)
        // ——签名 set($key,$value,$expireResolution=null,$expireTTL=null,$flag=null)，
        // 传 int 会被拼成 [null, 604800 => null]，phpredis 只识别字符串键，
        // 于是静默退化为普通 SET，永不失效（log_ttl 形同虚设）。
        // setex 在四个框架底层都是原生 phpredis，语义统一。
        // 另外 TTL 为 0 时 phpredis 会报 "EXPIRE can't be < 1" 且**不发送命令**
        // （实测服务端零字节），故仅在 > 0 时带过期。
        $ttl = (int) $ttl;
        return $ttl > 0
            ? call_user_func([$this->redis(), 'setex'], $key, $ttl, $value)
            : call_user_func([$this->redis(), 'set'], $key, $value);
    }

    public function mget(array $keys): array
    {
        // phpredis 对空数组直接返回 false 且不发送任何命令（实测服务端零字节），
        // 而本方法声明 : array，透传会抛 TypeError。
        // 触发路径真实：全新安装时 list_runs() 先 lRange 得到空列表，再 mget([])。
        if (!$keys) return [];
        return (array) call_user_func([$this->redis(), 'mget'], $keys);
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
