<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Joomla\Adapter;

use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\RedisAdapterTrait;

/**
 * 契约 CacheInterface → phpredis。
 *
 * 其余 10 家里，webman/laravel/thinkphp/hyperf 四家能从框架自己那里拿到 Redis 句柄
 * （两个静态门面、Cache::store('redis')、容器里的 Redis）；slim/symfony/yii3/drupal/
 * wordpress/native 六家同样直连 phpredis，但连接参数写死或走配置项。**Joomla 内核没有**
 * 可复用的 redis 句柄（既无 redis 缓存处理器也无 redis session handler），参数也只从
 * 环境变量取，所以这里直连 phpredis。
 *
 * 连接参数走环境变量，默认本机 6379：REDIS_HOST / REDIS_PORT / REDIS_PASSWORD / REDIS_DB。
 * 用环境变量而不是配置项，是为了不给 config/xhprof.php 增加新 key —— 11 份框架配置的
 * key 集必须一致（Wave 2 的 ConfigParityTest 会断言）。
 */
class RedisAdapter implements CacheInterface
{
    use RedisAdapterTrait;

    private ?\Redis $redis;

    public function __construct(?\Redis $redis = null)
    {
        $this->redis = $redis;
    }

    protected function redis(): mixed
    {
        if ($this->redis === null) {
            // 延迟连接：未被采样的请求（含静态资源）一次都不会碰 Redis。
            // 连接失败在 phpredis 里是 RedisException（Throwable），会被
            // Core\XhprofProfiler::stop() 的兜底接住并记日志——插件不该把业务请求打成 500。
            $redis = new \Redis();
            $redis->connect(
                (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
                (int) (getenv('REDIS_PORT') ?: 6379),
                1.0
            );
            $password = getenv('REDIS_PASSWORD');
            if (is_string($password) && $password !== '') {
                $redis->auth($password);
            }
            $db = getenv('REDIS_DB');
            if (is_string($db) && $db !== '') {
                $redis->select((int) $db);
            }
            $this->redis = $redis;
        }

        return $this->redis;
    }
}
