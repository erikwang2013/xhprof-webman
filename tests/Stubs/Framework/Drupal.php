<?php

declare(strict_types=1);

/**
 * Drupal 侧最小桩：只覆盖本包适配器/中间件实际调用的方法。
 * 签名与语义照抄 drupal/core 源码（已核 10.0.7 与 11.4.7）：
 *   - Drupal\Core\Config\ConfigFactoryInterface::get($name) / ImmutableConfig（ConfigBase::get() 的点路径语义）
 *   - Drupal\Core\Logger\LoggerChannelFactoryInterface::get($channel) → LoggerChannelInterface（PSR-3 的 error()）
 *
 * 只声明 `Drupal\*` 与我们自己的测试命名空间：
 *   - `Symfony\Component\HttpFoundation\*` 由 Symfony.php 独占（Drupal 的 Request/Response 就是 Symfony 的）。
 *     glob() 按字母序 require，Drupal.php 在 Symfony.php 之前——两边都声明会
 *     `Cannot declare class` 致命错误，整个测试套件都起不来。
 *   - `Psr\Log\LoggerInterface` 已由 framework-stubs.php 声明，这里不得重复。
 */

namespace Drupal\Core\Config {

    interface ConfigFactoryInterface
    {
        public function get($name);

        public function getEditable($name);
    }

    /**
     * 真实的 ImmutableConfig extends Config extends ConfigBase，只读。
     * 这里保留 ConfigBase::get() 的语义：空 key 返回整块，点路径走 NestedArray。
     */
    class ImmutableConfig
    {
        /** @var array<string, mixed> */
        private array $data;

        /**
         * @param array<string, mixed> $data
         */
        public function __construct(array $data = [])
        {
            $this->data = $data;
        }

        /**
         * @return mixed
         */
        public function get($key = '')
        {
            if (empty($key)) {
                return $this->data;
            }

            $value = $this->data;
            foreach (explode('.', $key) as $part) {
                if (!is_array($value) || !array_key_exists($part, $value)) {
                    return null;
                }
                $value = $value[$part];
            }

            return $value;
        }
    }
}

namespace Drupal\Core\Logger {

    interface LoggerChannelFactoryInterface
    {
        public function get($channel);
    }

    /** 真实的 LoggerChannelInterface extends Psr\Log\LoggerInterface，error() 来自 PSR-3。 */
    interface LoggerChannelInterface
    {
        public function error(string $message, array $context = []): void;
    }
}

namespace ErikWang2013\Xhprof\Tests\Stubs\Framework\Drupal {

    use Drupal\Core\Config\ConfigFactoryInterface;
    use Drupal\Core\Config\ImmutableConfig;
    use Drupal\Core\Logger\LoggerChannelFactoryInterface;
    use Drupal\Core\Logger\LoggerChannelInterface;

    /**
     * 假的 config.factory：按名字返回 ImmutableConfig。
     * 未注册的名字返回空配置——真实 ConfigFactory 对不存在的配置也是给空配置，不抛异常。
     */
    class FakeConfigFactory implements ConfigFactoryInterface
    {
        /** @var array<string, array<string, mixed>> */
        private array $configs;

        /** @var array<int, string> */
        public array $requested = [];

        /**
         * @param array<string, array<string, mixed>> $configs
         */
        public function __construct(array $configs = [])
        {
            $this->configs = $configs;
        }

        public function get($name)
        {
            $this->requested[] = (string) $name;
            return new ImmutableConfig($this->configs[$name] ?? []);
        }

        public function getEditable($name)
        {
            return $this->get($name);
        }
    }

    /** 假的 logger.factory，记录每个通道收到的错误。 */
    class FakeLoggerFactory implements LoggerChannelFactoryInterface
    {
        /** @var array<int, string> */
        public array $errors = [];

        /** @var array<int, string> */
        public array $channels = [];

        public function get($channel)
        {
            $this->channels[] = (string) $channel;
            return new FakeLoggerChannel($this);
        }
    }

    /** 通道只把错误写回工厂，测试里看 $factory->errors 即可。 */
    class FakeLoggerChannel implements LoggerChannelInterface
    {
        private FakeLoggerFactory $factory;

        public function __construct(FakeLoggerFactory $factory)
        {
            $this->factory = $factory;
        }

        public function error(string $message, array $context = []): void
        {
            $this->factory->errors[] = $message;
        }
    }
}
