<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Yii3\Adapter;

use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\RedisAdapterTrait;

/**
 * Yii3 核心不带 Redis 组件，也没有任何一个 yii-* 包是「事实标准」，所以这里直接
 * 用 phpredis（composer.json 已硬性依赖 ext-redis），不引入新的运行依赖。
 *
 * R-8 的全部语义（lPush 返回长度、rPop 空列表不抛、mget([]) 返回 []、
 * ttl <= 0 退化）由 Core\RedisAdapterTrait 保证，本类只负责给出连接。
 */
class RedisAdapter implements CacheInterface
{
    use RedisAdapterTrait;

    private ?\Redis $redis = null;

    /** @var array<string, mixed> */
    private array $options;

    /**
     * @param array<string, mixed> $options host / port / password / database / timeout
     */
    public function __construct(array $options = [])
    {
        $this->options = array_replace([
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
            'database' => 0,
            'timeout' => 1.0,
        ], $options);
    }

    /**
     * 延迟连接：ping 不通时抛 RedisException，而不是在构造适配器时抛。
     *
     * 触发路径真实：入口类在**每个请求**上、早于 enable 判断就构造本适配器；
     * 若在构造函数里连，Redis 挂掉会让整个应用每个请求 500，而不是只丢采样。
     * 真正取连接的调用点（落库）被 Core\XhprofProfiler::stop() 的兜底包住。
     */
    protected function redis(): mixed
    {
        if ($this->redis === null) {
            $redis = new \Redis();
            $redis->connect(
                (string) $this->options['host'],
                (int) $this->options['port'],
                (float) $this->options['timeout']
            );
            $password = (string) $this->options['password'];
            if ($password !== '') {
                $redis->auth($password);
            }
            $database = (int) $this->options['database'];
            if ($database !== 0) {
                $redis->select($database);
            }
            $this->redis = $redis;
        }

        return $this->redis;
    }
}
