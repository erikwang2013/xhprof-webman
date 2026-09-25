<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Wordpress\Adapter;

use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\RedisAdapterTrait;

/**
 * WordPress 没有官方 Redis 封装（对象缓存的实现五花八门），直接用 phpredis 扩展：
 * 包本身已 require ext-redis，redis() 给出连好的 phpredis 实例。
 *
 * 返回**实例**而不是 \Redis::class：RedisAdapterTrait 的调用形式是
 * `call_user_func([$this->redis(), 'get'], …)`。给类名就是对实例方法做静态调用
 * ——Webman/Laravel 能用，是因为那两个返回的是字符串形式的**门面**
 * （\support\Redis / Illuminate\Support\Facades\Redis，有 __callStatic）；
 * 裸 phpredis 没有 __callStatic，PHP 直接抛
 * 「non-static method Redis::get() cannot be called statically」的 TypeError。
 * 后果不是降级而是**缓存 100% 失败**：每次落库都在 stop() 里抛，被
 * XhprofProfiler::stop() 的 catch 吞掉，表现是"请求正常、报告页永远没有数据"。
 */
class RedisAdapter implements CacheInterface
{
    use RedisAdapterTrait;

    private ?\Redis $client = null;

    protected function redis(): \Redis
    {
        // 懒连接：入口类每个请求都会 new 一个本适配器（XhprofPlugin:62），
        // 构造时建连就是每个请求一次握手——即使采样是关的。
        // 与 Drupal/Symfony/Yii3 三家的直连适配器同形，含显式 1s 连接超时：
        // 不给的话若目标主机的 SYN 被丢（防火墙），phpredis 会按内核默认重试两分钟，
        // 一个性能工具的旁路存储不该拖垮业务请求。连不上时抛 RedisException，
        // 同样由 XhprofProfiler::stop() 的 catch 兜住。
        if ($this->client === null) {
            $client = new \Redis();
            $client->connect('127.0.0.1', 6379, 1.0);
            $this->client = $client;
        }
        return $this->client;
    }
}
