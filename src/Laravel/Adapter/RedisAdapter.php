<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Laravel\Adapter;

use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\RedisAdapterTrait;

class RedisAdapter implements CacheInterface
{
    use RedisAdapterTrait;

    protected function redis(): string
    {
        return \Illuminate\Support\Facades\Redis::class;
    }
}
