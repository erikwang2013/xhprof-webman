<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Hyperf\Adapter;

use Hyperf\Redis\Redis;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\RedisAdapterTrait;

class RedisAdapter implements CacheInterface
{
    use RedisAdapterTrait;

    private Redis $redis;

    public function __construct(Redis $redis)
    {
        $this->redis = $redis;
    }

    protected function redis(): Redis
    {
        return $this->redis;
    }
}
