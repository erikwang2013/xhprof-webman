<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Native\Adapter;

use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\RedisAdapterTrait;

/**
 * 原生 PHP 缓存适配器：phpredis 直连（composer.json 已硬性 require ext-redis）。
 *
 * R-8 的全部语义（lPush 返回长度、rPop 空列表不抛、mget([]) 返回 []、ttl <= 0 退化）
 * 由 Core\RedisAdapterTrait 保证，本类只负责给出连接。
 *
 * 返回**实例**而不是 \Redis::class：RedisAdapterTrait 的调用形式是
 * `call_user_func([$this->redis(), 'get'], …)`，给类名就是对实例方法做静态调用，
 * 而裸 phpredis 没有 __callStatic。后果不是降级而是缓存 100% 失败：每次落库都在
 * stop() 里抛，被 XhprofProfiler::stop() 的 catch 吞掉，表现是「请求正常、报告页永远没有数据」。
 *
 * 连接参数与 Yii3 的直连适配器**同名同默认**（`host` / `port` / `password` /
 * `database` / `timeout`），取自入口类那一行里配置的 `redis` 子数组：
 * `XhprofBootstrap::start(['redis' => ['host' => '10.0.0.5']])`。
 * 与 Yii3 一样，`redis` 不进包内默认配置文件（键集仍是那十个），只在用户配置里出现。
 */
class RedisAdapter implements CacheInterface
{
    use RedisAdapterTrait;

    private ?\Redis $client = null;

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
     * 懒连接：入口类在**每个请求**上、早于 enable 判断就构造本适配器（XhprofBootstrap::__construct），
     * 构造时建连就是每个请求一次握手——即使采样是关的，或者这个请求根本走不到落库。
     * 连不上时抛 RedisException，由 XhprofProfiler::stop() 的 Throwable 防线兜住
     * （一个性能工具的旁路存储不该拖垮业务请求）。
     *
     * 显式给 1s 连接超时：不给的话若目标主机的 SYN 被丢（防火墙），phpredis 会按内核
     * 默认重试两分钟。与 Drupal/Slim/Symfony/Yii3/WordPress 五家直连适配器同一条默认路径。
     */
    protected function redis(): \Redis
    {
        if ($this->client === null) {
            $client = new \Redis();
            $client->connect(
                (string) $this->options['host'],
                (int) $this->options['port'],
                (float) $this->options['timeout']
            );
            $password = (string) $this->options['password'];
            if ($password !== '') {
                $client->auth($password);
            }
            $database = (int) $this->options['database'];
            if ($database !== 0) {
                $client->select($database);
            }
            $this->client = $client;
        }

        return $this->client;
    }
}
