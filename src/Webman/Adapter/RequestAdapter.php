<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Webman\Adapter;

use Webman\Http\Request;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;

class RequestAdapter implements RequestInterface
{
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->request->get($key, $default);
    }

    public function all(): array
    {
        return $this->request->all();
    }

    public function method(): string
    {
        return $this->request->method();
    }

    public function header(string $name): ?string
    {
        return $this->request->header($name);
    }

    public function host(): string
    {
        // workerman 的 host(bool $withoutPort = false): ?string 在无 Host 头时返回 null，
        // 而本文件是 strict_types=1，直接返回会抛 TypeError。
        // 调用点 _saveToRedis() 在每个被采样请求上都会取 host。
        return (string) $this->request->host();
    }

    public function uri(): string
    {
        return $this->request->uri();
    }

    public function url(): string
    {
        return $this->request->url();
    }

    public function getRealIp(): string
    {
        return $this->request->getRealIp(true);
    }
}
