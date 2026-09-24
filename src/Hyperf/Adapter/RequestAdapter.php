<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Hyperf\Adapter;

use Hyperf\HttpServer\Request;
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
        return $this->request->input($key, $default);
    }

    public function all(): array
    {
        return $this->request->all();
    }

    public function method(): string
    {
        return $this->request->getMethod();
    }

    public function header(string $name): ?string
    {
        return $this->request->header($name);
    }

    public function host(): string
    {
        // Hyperf\HttpServer\Request 既没有 getHost()，其 Contract\RequestInterface
        // 也未声明该方法，且类上没有 __call 兜底——直接调用会抛
        // "Call to undefined method"。必须走 PSR-7。
        // 该方法由 XHProfRunsDefault::_saveToRedis() 在每个被采样请求上调用，
        // 旧实现会让 Hyperf 应用的每个请求都在 finally 里 500。
        return $this->request->getUri()->getHost();
    }

    public function uri(): string
    {
        return $this->request->getRequestUri();
    }

    public function url(): string
    {
        return $this->request->url();
    }

    public function getRealIp(): string
    {
        $params = $this->request->getServerParams();
        if (!empty($params['x-forwarded-for'])) {
            return trim(explode(',', $params['x-forwarded-for'])[0]);
        }
        if (!empty($params['x-real-ip'])) {
            return $params['x-real-ip'];
        }
        return $params['remote_addr'] ?? '127.0.0.1';
    }
}
