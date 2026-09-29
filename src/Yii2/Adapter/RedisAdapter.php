<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Yii2\Adapter;

use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\RedisAdapterTrait;

/**
 * Yii2 的缓存适配器：phpredis 直连（composer.json 已硬性依赖 ext-redis）。
 *
 * 为什么不走 `Yii::$app->redis` / `yii\redis\Connection`：那是可选的 `yiisoft/yii2-redis`
 * 扩展，不是 Yii2 核心组件，装了也不一定配；而本包已经在 composer 里硬性要求 ext-redis。
 * 与 Yii3 的同名适配器逐字同形。
 *
 * R-8 的全部语义（lPush 返回长度、rPop 空列表不抛、mget([]) 返回 []、ttl <= 0 退化）
 * 由 Core\RedisAdapterTrait 保证，本类只负责给出连接。
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
     * 延迟连接：连不上时抛 RedisException，而不是在构造适配器时抛。
     *
     * 触发路径真实：入口类在**每个**被采样请求上都会构造（`XhprofBootstrap::onBeforeRequest`），
     * 若在构造函数里连，Redis 挂掉会让整个应用每个请求 500，而不是只丢采样。
     * 真正取连接的调用点（落库）被 Core\XhprofProfiler::stop() 的兜底包住。
     *
     * 显式给 1s 连接超时：不给的话若目标主机的 SYN 被丢（防火墙），phpredis 会按内核
     * 默认重试两分钟。与 Drupal/Slim/Symfony/Yii3/WordPress/Native 六家同一条默认路径。
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
