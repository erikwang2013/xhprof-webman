<?php

declare(strict_types=1);

/**
 * 框架最小 stub，仅覆盖本包适配器/中间件实际调用的方法。
 * 仅测试进程加载，不参与生产运行。
 */

namespace support {
    class Redis
    {
        public static array $store = [];
        public static array $log = [];

        public static function reset(): void
        {
            self::$store = [];
            self::$log = [];
        }

        public static function get(string $key): mixed
        {
            self::$log[] = "get:$key";
            return self::$store[$key] ?? null;
        }

        public static function set(string $key, mixed $value, int $ttl = 0): mixed
        {
            self::$log[] = "set:$key";
            self::$store[$key] = $value;
            return $value;
        }

        public static function mget(array $keys): array
        {
            self::$log[] = 'mget';
            $out = [];
            foreach ($keys as $key) {
                $out[] = self::$store[$key] ?? null;
            }
            return $out;
        }

        public static function incr(string $key): int
        {
            self::$log[] = "incr:$key";
            self::$store[$key] = (int) (self::$store[$key] ?? 0) + 1;
            return self::$store[$key];
        }

        public static function decr(string $key): int
        {
            self::$log[] = "decr:$key";
            self::$store[$key] = (int) (self::$store[$key] ?? 0) - 1;
            return self::$store[$key];
        }

        public static function lPush(string $key, mixed $value): int
        {
            self::$log[] = "lpush:$key";
            self::$store[$key] ??= [];
            array_unshift(self::$store[$key], $value);
            return count(self::$store[$key]);
        }

        public static function rpop(string $key): mixed
        {
            self::$log[] = "rpop:$key";
            if (empty(self::$store[$key])) {
                return null;
            }
            return array_pop(self::$store[$key]);
        }

        public static function lrange(string $key, int $start, int $end): array
        {
            self::$log[] = "lrange:$key";
            return array_slice(self::$store[$key] ?? [], $start, $end - $start + 1);
        }

        public static function del(mixed ...$keys): int
        {
            self::$log[] = 'del';
            $n = 0;
            foreach ($keys as $key) {
                if (isset(self::$store[$key])) {
                    unset(self::$store[$key]);
                    $n++;
                }
            }
            return $n;
        }
    }

    class Log
    {
        public static array $errors = [];

        public static function reset(): void
        {
            self::$errors = [];
        }

        public static function error(string $message, array $context = []): void
        {
            self::$errors[] = $message;
        }
    }
}

namespace Webman {
    interface MiddlewareInterface
    {
        public function process(\Webman\Http\Request $request, callable $handler): \Webman\Http\Response;
    }
}

namespace Webman\Http {
    class Request
    {
        private array $params;
        private array $headers;
        private string $method;
        private string $host;
        private string $uri;
        private string $url;
        private string $ip;

        public function __construct(array $params = [], array $options = [])
        {
            $this->params = $params;
            $this->headers = $options['headers'] ?? [];
            $this->method = $options['method'] ?? 'GET';
            $this->host = $options['host'] ?? 'localhost';
            $this->uri = $options['uri'] ?? '/';
            $this->url = $options['url'] ?? 'http://localhost/';
            $this->ip = $options['ip'] ?? '127.0.0.1';
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->params[$key] ?? $default;
        }

        public function all(): array
        {
            return $this->params;
        }

        public function method(): string
        {
            return $this->method;
        }

        public function header(string $name): ?string
        {
            return $this->headers[$name] ?? null;
        }

        public function host(): string
        {
            return $this->host;
        }

        public function uri(): string
        {
            return $this->uri;
        }

        public function url(): string
        {
            return $this->url;
        }

        public function getRealIp(bool $safe_mode = false): string
        {
            return $this->ip;
        }
    }

    class Response
    {
        public int $status;
        public array $headers;
        public string $body;
        public ?string $filePath = null;

        public function __construct(int $status = 200, array $headers = [], string $body = '')
        {
            $this->status = $status;
            $this->headers = $headers;
            $this->body = $body;
        }

        public function withBody(string $body): self
        {
            $this->body = $body;
            return $this;
        }

        public function withHeaders(array $headers): self
        {
            $this->headers = array_merge($this->headers, $headers);
            return $this;
        }

        public function file(string $path): self
        {
            $this->filePath = $path;
            if (is_file($path)) {
                $this->body = (string) file_get_contents($path);
            }
            return $this;
        }
    }
}

namespace Illuminate\Support\Facades {
    class Redis
    {
        public static array $store = [];
        public static array $log = [];

        public static function reset(): void
        {
            self::$store = [];
            self::$log = [];
        }

        public static function __callStatic(string $name, array $args): mixed
        {
            self::$log[] = $name;
            $methods = [
                'get' => fn ($k) => self::$store[$k] ?? null,
                'set' => function ($k, $v, $ttl = 0) {
                    self::$store[$k] = $v;
                    return $v;
                },
                'mget' => function (array $keys) {
                    $out = [];
                    foreach ($keys as $k) {
                        $out[] = self::$store[$k] ?? null;
                    }
                    return $out;
                },
                'incr' => function ($k) {
                    self::$store[$k] = (int) (self::$store[$k] ?? 0) + 1;
                    return self::$store[$k];
                },
                'decr' => function ($k) {
                    self::$store[$k] = (int) (self::$store[$k] ?? 0) - 1;
                    return self::$store[$k];
                },
                'lpush' => function ($k, $v) {
                    self::$store[$k] ??= [];
                    array_unshift(self::$store[$k], $v);
                    return count(self::$store[$k]);
                },
                'rpop' => function ($k) {
                    if (empty(self::$store[$k])) {
                        return null;
                    }
                    return array_pop(self::$store[$k]);
                },
                'lrange' => function ($k, $s, $e) {
                    return array_slice(self::$store[$k] ?? [], $s, $e - $s + 1);
                },
                'del' => function (...$keys) {
                    $n = 0;
                    foreach ($keys as $k) {
                        if (isset(self::$store[$k])) {
                            unset(self::$store[$k]);
                            $n++;
                        }
                    }
                    return $n;
                },
            ];
            if (!isset($methods[$name])) {
                throw new \BadMethodCallException("Illuminate Redis stub: unsupported method $name");
            }
            return $methods[$name](...$args);
        }
    }

    class Log
    {
        public static array $errors = [];

        public static function reset(): void
        {
            self::$errors = [];
        }

        public static function __callStatic(string $name, array $args): void
        {
            self::$errors[] = $name . ': ' . $args[0];
        }
    }
}

namespace Illuminate\Http {
    class Request
    {
        private array $params;
        private array $headers;
        private string $method;
        private string $host;
        private string $uri;
        private string $url;
        private string $ip;

        public function __construct(array $params = [], array $options = [])
        {
            $this->params = $params;
            $this->headers = $options['headers'] ?? [];
            $this->method = $options['method'] ?? 'GET';
            $this->host = $options['host'] ?? 'localhost';
            $this->uri = $options['uri'] ?? '/';
            $this->url = $options['url'] ?? 'http://localhost/';
            $this->ip = $options['ip'] ?? '127.0.0.1';
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->params[$key] ?? $default;
        }

        public function all(): array
        {
            return $this->params;
        }

        public function method(): string
        {
            return $this->method;
        }

        public function header(string $name, mixed $default = null): ?string
        {
            return $this->headers[$name] ?? $default;
        }

        public function getHost(): string
        {
            return $this->host;
        }

        public function getRequestUri(): string
        {
            return $this->uri;
        }

        public function url(): string
        {
            return $this->url;
        }

        public function ip(): string
        {
            return $this->ip;
        }
    }

    class Response
    {
        public string $body;
        public array $headers = [];
        public int $status = 200;
        public ?string $filePath = null;

        public function __construct(string $body = '', int $status = 200, array $headers = [])
        {
            $this->body = $body;
            $this->status = $status;
            $this->headers = $headers;
        }

        public function withHeaders(array $headers): self
        {
            $this->headers = array_merge($this->headers, $headers);
            return $this;
        }

        public function setStatusCode(int $status): self
        {
            $this->status = $status;
            return $this;
        }

        public function file(string $path): self
        {
            $this->filePath = $path;
            return $this;
        }
    }
}

namespace Illuminate\Support {
    abstract class ServiceProvider
    {
        protected array $merged = [];
        protected array $published = [];

        protected function mergeConfigFrom(string $path, string $key): void
        {
            $this->merged[$key] = $path;
        }

        protected function publishes(array $paths, mixed $groups = null): void
        {
            $this->published[] = $paths;
        }
    }
}

namespace think\facade {
    class Config
    {
        public static array $data = [];

        public static function reset(): void
        {
            self::$data = [];
        }

        public static function get(string $key, mixed $default = null): mixed
        {
            $parts = explode('.', $key);
            $value = self::$data;
            foreach ($parts as $part) {
                if (!is_array($value) || !array_key_exists($part, $value)) {
                    return $default;
                }
                $value = $value[$part];
            }
            return $value;
        }
    }

    class Log
    {
        public static array $errors = [];

        public static function reset(): void
        {
            self::$errors = [];
        }

        public static function error(string $message): void
        {
            self::$errors[] = $message;
        }
    }

    class Cache
    {
        private static ?\think\CacheStore $store = null;

        public static function reset(): void
        {
            self::$store = null;
        }

        public static function store(string $name = 'redis'): \think\CacheStore
        {
            return self::$store ??= new \think\CacheStore();
        }

        public static function get(string $key): mixed
        {
            return self::store()->get($key);
        }

        public static function set(string $key, mixed $value, int $ttl = 0): mixed
        {
            return self::store()->set($key, $value, $ttl);
        }

        public static function inc(string $key): int
        {
            return self::store()->inc($key);
        }
    }
}

namespace think {
    class CacheStore
    {
        public array $data = [];
        public object $handler;

        public function __construct()
        {
            $this->handler = new ThinkFakeHandler();
        }

        public function setHandler(object $handler): void
        {
            $this->handler = $handler;
        }

        public function handler(): object
        {
            return $this->handler;
        }

        public function get(string $key): mixed
        {
            return $this->data[$key] ?? null;
        }

        public function set(string $key, mixed $value, int $ttl = 0): mixed
        {
            $this->data[$key] = $value;
            return $value;
        }

        public function inc(string $key): int
        {
            $this->data[$key] = (int) ($this->data[$key] ?? 0) + 1;
            return $this->data[$key];
        }
    }

    class ThinkFakeHandler
    {
        public array $data = [];

        public function mget(array $keys): array
        {
            $out = [];
            foreach ($keys as $key) {
                $out[] = $this->data[$key] ?? null;
            }
            return $out;
        }

        public function lPush(string $key, mixed $value): int
        {
            $this->data[$key] ??= [];
            array_unshift($this->data[$key], $value);
            return count($this->data[$key]);
        }

        public function rPop(string $key): mixed
        {
            if (empty($this->data[$key])) {
                return null;
            }
            return array_pop($this->data[$key]);
        }

        public function lRange(string $key, int $start, int $end): array
        {
            return array_slice($this->data[$key] ?? [], $start, $end - $start + 1);
        }

        public function del(mixed ...$keys): int
        {
            $n = 0;
            foreach ($keys as $key) {
                if (isset($this->data[$key])) {
                    unset($this->data[$key]);
                    $n++;
                }
            }
            return $n;
        }

        public function decr(string $key): int
        {
            $this->data[$key] = (int) ($this->data[$key] ?? 0) - 1;
            return $this->data[$key];
        }
    }

    class Request
    {
        private array $params;
        private array $headers;
        private string $method;
        private string $host;
        private string $uri;
        private string $url;
        private string $ip;

        public function __construct(array $params = [], array $options = [])
        {
            $this->params = $params;
            $this->headers = $options['headers'] ?? [];
            $this->method = $options['method'] ?? 'GET';
            $this->host = $options['host'] ?? 'localhost';
            $this->uri = $options['uri'] ?? '/';
            $this->url = $options['url'] ?? 'http://localhost/';
            $this->ip = $options['ip'] ?? '127.0.0.1';
        }

        public function param(string $key = '', mixed $default = null): mixed
        {
            if ($key === '') {
                return $this->params;
            }
            return $this->params[$key] ?? $default;
        }

        public function method(): string
        {
            return $this->method;
        }

        public function header(string $name, mixed $default = null): ?string
        {
            return $this->headers[$name] ?? $default;
        }

        public function host(): string
        {
            return $this->host;
        }

        public function url(bool $full = false): string
        {
            return $full ? $this->url : $this->uri;
        }

        public function ip(): string
        {
            return $this->ip;
        }
    }

    class Response
    {
        public string $body;
        public int $status;
        public array $headers = [];

        public function __construct(string $body = '', int $status = 200, array $headers = [])
        {
            $this->body = $body;
            $this->status = $status;
            $this->headers = $headers;
        }

        public function header(array $headers): self
        {
            $this->headers = array_merge($this->headers, $headers);
            return $this;
        }

        public function code(int $status): self
        {
            $this->status = $status;
            return $this;
        }
    }
}

namespace Hyperf\Contract {
    interface ConfigInterface
    {
        public function get(string $key, mixed $default = null): mixed;
    }
}

namespace Hyperf {
    class Config implements \Hyperf\Contract\ConfigInterface
    {
        private array $config;

        public function __construct(array $config = [])
        {
            $this->config = $config;
        }

        public function get(string $key, mixed $default = null): mixed
        {
            $parts = explode('.', $key);
            $value = $this->config;
            foreach ($parts as $part) {
                if (!is_array($value) || !array_key_exists($part, $value)) {
                    return $default;
                }
                $value = $value[$part];
            }
            return $value;
        }
    }
}

namespace Hyperf\HttpServer\Contract {
    interface RequestInterface
    {
        public function input(string $key, mixed $default = null): mixed;
        public function all(): array;
        public function getMethod(): string;
        public function header(string $name): ?string;
        public function getHost(): string;
        public function getRequestUri(): string;
        public function url(): string;
        public function getServerParams(): array;
    }

    interface ResponseInterface
    {
        public function withBody(mixed $body): self;
        public function withHeader(string $key, mixed $value): self;
        public function withStatus(int $status): self;
    }
}

namespace Hyperf\HttpServer {
    class Request implements \Hyperf\HttpServer\Contract\RequestInterface
    {
        private array $params;
        private array $headers;
        private array $server;
        private string $method;
        private string $host;
        private string $uri;
        private string $url;

        public function __construct(array $params = [], array $options = [])
        {
            $this->params = $params;
            $this->headers = $options['headers'] ?? [];
            $this->server = $options['server'] ?? [];
            $this->method = $options['method'] ?? 'GET';
            $this->host = $options['host'] ?? 'localhost';
            $this->uri = $options['uri'] ?? '/';
            $this->url = $options['url'] ?? 'http://localhost/';
        }

        public function input(string $key, mixed $default = null): mixed
        {
            return $this->params[$key] ?? $default;
        }

        public function all(): array
        {
            return $this->params;
        }

        public function getMethod(): string
        {
            return $this->method;
        }

        public function header(string $name): ?string
        {
            return $this->headers[$name] ?? null;
        }

        public function getHost(): string
        {
            return $this->host;
        }

        public function getRequestUri(): string
        {
            return $this->uri;
        }

        public function url(): string
        {
            return $this->url;
        }

        public function getServerParams(): array
        {
            return $this->server;
        }
    }

    class Response implements \Hyperf\HttpServer\Contract\ResponseInterface
    {
        public mixed $body = null;
        public array $headers = [];
        public int $status = 200;

        public function withBody(mixed $body): self
        {
            $this->body = $body;
            return $this;
        }

        public function withHeader(string $key, mixed $value): self
        {
            $this->headers[$key] = $value;
            return $this;
        }

        public function withStatus(int $status): self
        {
            $this->status = $status;
            return $this;
        }
    }
}

namespace Hyperf\HttpMessage\Stream {
    class SwooleStream
    {
        private string $content;

        public function __construct(string $content = '')
        {
            $this->content = $content;
        }

        public function __toString(): string
        {
            return $this->content;
        }
    }
}

namespace Hyperf\Redis {
    class Redis
    {
        public array $store = [];

        public function get(string $key): mixed
        {
            return $this->store[$key] ?? null;
        }

        public function set(string $key, mixed $value, int $ttl = 0): mixed
        {
            $this->store[$key] = $value;
            return $value;
        }

        public function mget(array $keys): array
        {
            $out = [];
            foreach ($keys as $key) {
                $out[] = $this->store[$key] ?? null;
            }
            return $out;
        }

        public function incr(string $key): int
        {
            $this->store[$key] = (int) ($this->store[$key] ?? 0) + 1;
            return $this->store[$key];
        }

        public function decr(string $key): int
        {
            $this->store[$key] = (int) ($this->store[$key] ?? 0) - 1;
            return $this->store[$key];
        }

        public function lPush(string $key, mixed $value): int
        {
            $this->store[$key] ??= [];
            array_unshift($this->store[$key], $value);
            return count($this->store[$key]);
        }

        public function rPop(string $key): mixed
        {
            if (empty($this->store[$key])) {
                return null;
            }
            return array_pop($this->store[$key]);
        }

        public function lRange(string $key, int $start, int $end): array
        {
            return array_slice($this->store[$key] ?? [], $start, $end - $start + 1);
        }

        public function del(mixed ...$keys): int
        {
            $n = 0;
            foreach ($keys as $key) {
                if (isset($this->store[$key])) {
                    unset($this->store[$key]);
                    $n++;
                }
            }
            return $n;
        }
    }
}

namespace Hyperf\Context {
    class Context
    {
        private static array $data = [];

        public static function reset(): void
        {
            self::$data = [];
        }

        public static function set(string $key, mixed $value): void
        {
            self::$data[$key] = $value;
        }

        public static function get(string $key, mixed $default = null): mixed
        {
            return self::$data[$key] ?? $default;
        }
    }

    class Container
    {
        public array $bindings = [];

        public function set(string $id, object $obj): void
        {
            $this->bindings[$id] = $obj;
        }

        public function get(string $id): object
        {
            if (!isset($this->bindings[$id])) {
                throw new \RuntimeException("No binding for $id");
            }
            return $this->bindings[$id];
        }
    }

    class ApplicationContext
    {
        private static ?Container $container = null;

        public static function reset(): void
        {
            self::$container = null;
        }

        public static function getContainer(): Container
        {
            return self::$container ??= new Container();
        }
    }
}

namespace Hyperf\Framework {
    class ApplicationContext
    {
        public static function getContainer(): \Hyperf\Context\Container
        {
            return \Hyperf\Context\ApplicationContext::getContainer();
        }
    }
}

namespace Psr\Log {
    interface LoggerInterface
    {
        public function error(string $message, array $context = []): void;
    }
}

namespace Psr\Http\Message {
    interface ServerRequestInterface
    {
    }

    interface ResponseInterface
    {
    }
}

namespace Psr\Http\Server {
    interface MiddlewareInterface
    {
        public function process(\Psr\Http\Message\ServerRequestInterface $request, \Psr\Http\Server\RequestHandlerInterface $handler): \Psr\Http\Message\ResponseInterface;
    }

    interface RequestHandlerInterface
    {
        public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface;
    }
}

namespace {
    /**
     * 全局辅助函数（webman/Laravel/ThinkPHP 均在全局命名空间提供同名 helper）。
     */
    function request(): \Webman\Http\Request
    {
        return new \Webman\Http\Request();
    }

    function base_path(): string
    {
        return \ErikWang2013\Xhprof\Tests\Stubs\Registry::$basePath;
    }

    function copy_dir(string $source, string $dest): bool
    {
        \ErikWang2013\Xhprof\Tests\Stubs\Registry::$copied[] = $source . ' => ' . $dest;
        return true;
    }

    function remove_dir(string $path): bool
    {
        \ErikWang2013\Xhprof\Tests\Stubs\Registry::$removed[] = $path;
        return true;
    }

    function config_path(string $path = ''): string
    {
        return \ErikWang2013\Xhprof\Tests\Stubs\Registry::$basePath . '/config/' . ltrim($path, '/');
    }
}

namespace ErikWang2013\Xhprof\Webman {
    function response(string $content = '', int $status = 200): \Webman\Http\Response
    {
        return new \Webman\Http\Response($status, [], $content);
    }
}

namespace ErikWang2013\Xhprof\Webman\Adapter {
    function response(string $content = '', int $status = 200): \Webman\Http\Response
    {
        return new \Webman\Http\Response($status, [], $content);
    }

    function config(?string $key = null): mixed
    {
        if ($key === null) {
            return \ErikWang2013\Xhprof\Tests\Stubs\Registry::$webmanConfig;
        }
        return \ErikWang2013\Xhprof\Tests\Stubs\Registry::resolve(
            \ErikWang2013\Xhprof\Tests\Stubs\Registry::$webmanConfig,
            $key
        );
    }
}

namespace ErikWang2013\Xhprof\Laravel {
    function response(string $content = '', int $status = 200): \Illuminate\Http\Response
    {
        return new \Illuminate\Http\Response($content, $status);
    }
}

namespace ErikWang2013\Xhprof\Laravel\Adapter {
    function response(string $content = '', int $status = 200): \Illuminate\Http\Response
    {
        return new \Illuminate\Http\Response($content, $status);
    }

    function config(string $key, mixed $default = null): mixed
    {
        return \ErikWang2013\Xhprof\Tests\Stubs\Registry::resolve(
            \ErikWang2013\Xhprof\Tests\Stubs\Registry::$laravelConfig,
            $key
        ) ?? $default;
    }
}

namespace ErikWang2013\Xhprof\Thinkphp {
    function response(string $content = '', int $status = 200): \think\Response
    {
        return new \think\Response($content, $status);
    }
}

namespace ErikWang2013\Xhprof\Thinkphp\Adapter {
    function response(string $content = '', int $status = 200): \think\Response
    {
        return new \think\Response($content, $status);
    }
}
