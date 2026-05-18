<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Thinkphp\Adapter;

use think\Request;
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
        return $this->request->param($key, $default);
    }

    public function all(): array
    {
        return $this->request->param();
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
        return $this->request->host();
    }

    public function uri(): string
    {
        return $this->request->url();
    }

    public function url(): string
    {
        return $this->request->url(true);
    }

    public function getRealIp(): string
    {
        return $this->request->ip();
    }
}
