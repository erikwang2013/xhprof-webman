<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Core;

use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\RedisAdapterTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 记录底层调用参数的假 Redis，形态刻意模仿 phpredis 的真实边界行为：
 *  - mget([]) 返回 false，且不发送任何命令
 *  - TTL < 1 被 SETEX 拒绝，返回 false，同样不发命令
 * 两者均已用本机 phpredis + mock RESP 服务端实测确认（服务端收到 0 字节）。
 */
class RecordingRedis
{
    public array $calls = [];

    public function get(string $key): mixed
    {
        $this->calls[] = ['get', $key];
        return null;
    }

    public function set(string $key, mixed $value): mixed
    {
        // 真实 phpredis 的带过期写入走 setex；set() 不应收到 TTL
        // （在 illuminate 下第三参是「过期单位字符串」，传 int 会被静默忽略）。
        // 记 func_num_args() 以便断言 trait 没有多传参数。
        $this->calls[] = ['set', $key, $value, func_num_args()];
        return true;
    }

    public function setex(string $key, int $ttl, mixed $value): bool
    {
        $this->calls[] = ['setex', $key, $ttl, $value];
        return true;
    }

    public function mget(array $keys): mixed
    {
        $this->calls[] = ['mget', $keys];
        return $keys ? array_fill(0, count($keys), null) : false;
    }
}

class RedisAdapterTraitProbe implements CacheInterface
{
    use RedisAdapterTrait;

    public function __construct(private object $backend)
    {
    }

    protected function redis(): mixed
    {
        return $this->backend;
    }
}

class RedisAdapterTraitTest extends TestCase
{
    /**
     * 旧实现把空数组透传给 phpredis，后者返回 false，而本方法声明 : array
     * → TypeError。触发路径真实：全新安装时 list_runs() 先 lRange 得到空列表。
     */
    #[Test]
    public function mgetWithEmptyKeyListReturnsArrayWithoutTouchingBackend(): void
    {
        $backend = new RecordingRedis();
        $cache = new RedisAdapterTraitProbe($backend);

        self::assertSame([], $cache->mget([]));
        self::assertSame([], $backend->calls, '空 key 列表不应向 Redis 发出任何命令');
    }

    /**
     * 契约的 TTL 默认值是 null，而 (int) null === 0。旧实现把它当 TTL 传下去，
     * phpredis 以 "EXPIRE can't be < 1" 拒绝并静默丢弃写入——键存在、数据永久缺失。
     */
    #[Test]
    public function setOmitsTtlWhenNotPositive(): void
    {
        $backend = new RecordingRedis();
        $cache = new RedisAdapterTraitProbe($backend);

        self::assertTrue($cache->set('k', 'v'));
        self::assertSame(['set', 'k', 'v', 2], $backend->calls[0], 'null TTL 不应多传参数');

        self::assertTrue($cache->set('k', 'v', 0));
        self::assertSame(['set', 'k', 'v', 2], $backend->calls[1], '0 同样视为"不设过期"');

        self::assertTrue($cache->set('k', 'v', 60));
        self::assertSame(['setex', 'k', 60, 'v'], $backend->calls[2], '正数 TTL 必须走 setex');
    }
}
