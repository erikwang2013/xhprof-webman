<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Drupal\Adapter;

use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\RedisAdapterTrait;

class RedisAdapter implements CacheInterface
{
    use RedisAdapterTrait;

    private ?\Redis $client = null;

    protected function redis(): mixed
    {
        // R-9：语义全部由 RedisAdapterTrait 保证（lPush 返回列表长度、mget([]) 返回 []、
        // set() 的 ttl<=0 退化等），这里只负责给出 phpredis 实例。
        //
        // Drupal 核心不含 Redis，而本包 composer.json 硬依赖 ext-redis，故直接用
        // phpredis 的默认连接（127.0.0.1:6379，phpredis 在首条命令时才真正建连）。
        // 连不上时抛 RedisException，由 XhprofProfiler::stop() 的 catch 兜住并写日志——
        // 不会把本来健康的请求变成 500。要指定 host/端口或复用 redis 贡献模块的连接，
        // 覆写 xhprof.http_middleware 的 cache 参数注入自己的 CacheInterface 即可。
        //
        // 缓存实例：RedisAdapterTrait 的每个方法都会调 redis()，不缓存就是每次操作一次握手。
        // 显式给 1s 连接超时：不给的话若目标主机的 SYN 被丢（防火墙），会按内核默认重试
        // 两分钟——一个性能工具的旁路存储不该拖垮业务请求。
        // 判据是 `=== null`：注入一个**未连接**的实例不会走到这里，得由调用方自己 connect()，
        // 否则第一条命令的行为交给 phpredis 决定（各版本不一致，别依赖）。
        if ($this->client === null) {
            $client = new \Redis();
            $client->connect('127.0.0.1', 6379, 1.0);
            $this->client = $client;
        }
        return $this->client;
    }
}
