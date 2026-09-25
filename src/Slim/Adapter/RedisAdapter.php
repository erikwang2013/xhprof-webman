<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Slim\Adapter;

use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\RedisAdapterTrait;

/**
 * Slim 没有 Redis 门面，所以由调用方注入 phpredis 连接。
 * 不注入时惰性建连 —— 构造函数刻意不碰 ext-redis，没装扩展也不会在建适配器时就炸。
 */
class RedisAdapter implements CacheInterface
{
    use RedisAdapterTrait;

    private mixed $redis;

    public function __construct(mixed $redis = null)
    {
        $this->redis = $redis;
    }

    protected function redis(): mixed
    {
        // 不注入时连本机，与 Drupal/Symfony/Yii3 三家直连适配器同一条默认路径：
        // 显式给 1s 连接超时——不给的话若目标主机的 SYN 被丢（防火墙），phpredis
        // 会按内核默认重试两分钟，一个性能工具的旁路存储不该拖垮业务请求。
        if ($this->redis === null) {
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379, 1.0);
            $this->redis = $redis;
        }
        return $this->redis;
    }
}
