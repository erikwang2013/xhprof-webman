<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Fixtures;

use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;

/**
 * 内存版 Cache，模拟 Redis 语义（lPush 头部入列、rPop 尾部出列），并记录全部调用。
 */
class FakeCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

    /** @var array<string, array<int, mixed>> 列表，索引 0 为头部 */
    private array $lists = [];

    /** @var array<int, string> 调用记录 */
    public array $calls = [];

    public function reset(): void
    {
        $this->store = [];
        $this->lists = [];
        $this->calls = [];
    }

    public function get(string $key): mixed
    {
        $this->calls[] = "get:$key";
        return $this->store[$key] ?? null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): mixed
    {
        $this->calls[] = "set:$key";
        $this->store[$key] = $value;
        return $value;
    }

    public function mget(array $keys): array
    {
        $this->calls[] = 'mget';
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->store[$key] ?? null;
        }
        return $out;
    }

    public function incr(string $key): int
    {
        $this->calls[] = "incr:$key";
        $this->store[$key] = (int) ($this->store[$key] ?? 0) + 1;
        return $this->store[$key];
    }

    public function decr(string $key): int
    {
        $this->calls[] = "decr:$key";
        $this->store[$key] = (int) ($this->store[$key] ?? 0) - 1;
        return $this->store[$key];
    }

    public function lPush(string $key, mixed $value): int
    {
        $this->calls[] = "lPush:$key";
        $this->lists[$key] ??= [];
        array_unshift($this->lists[$key], $value);
        return count($this->lists[$key]);
    }

    public function rPop(string $key): mixed
    {
        $this->calls[] = "rPop:$key";
        if (empty($this->lists[$key])) {
            return null;
        }
        return array_pop($this->lists[$key]);
    }

    public function lRange(string $key, int $start, int $end): array
    {
        $this->calls[] = "lRange:$key";
        $list = $this->lists[$key] ?? [];
        $count = count($list);
        if ($start < 0) {
            $start = max(0, $count + $start);
        }
        if ($end < 0) {
            $end = $count + $end;
        }
        $end = min($count - 1, $end);
        if ($start > $end) {
            return [];
        }
        return array_slice($list, $start, $end - $start + 1);
    }

    public function del(string ...$keys): int
    {
        $this->calls[] = 'del';
        $n = 0;
        foreach ($keys as $key) {
            if (isset($this->store[$key])) {
                unset($this->store[$key]);
                $n++;
            }
            if (isset($this->lists[$key])) {
                unset($this->lists[$key]);
                $n++;
            }
        }
        return $n;
    }
}

class FakeRequest implements RequestInterface
{
    private array $params;
    private array $headers;
    private string $method;
    private string $host;
    private string $uri;
    private string $url;
    private string $ip;

    public function __construct(
        array $params = [],
        array $options = []
    ) {
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

    public function getRealIp(): string
    {
        return $this->ip;
    }
}

class FakeResponse implements ResponseInterface
{
    public string $body = '';
    public array $headers = [];
    public int $status = 200;
    public ?string $filePath = null;

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

    public function withStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function file(string $path): self
    {
        $this->filePath = $path;
        return $this;
    }

    public function send(): mixed
    {
        return $this;
    }
}

class FakeConfig implements ConfigInterface
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

class FakeLogger implements LoggerInterface
{
    /** @var array<int, string> */
    public array $errors = [];

    public function error(string $message, array $context = []): void
    {
        $this->errors[] = $message;
    }
}
