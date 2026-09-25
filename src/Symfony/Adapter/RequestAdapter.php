<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Symfony\Adapter;

use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use Symfony\Component\HttpFoundation\Request;

class RequestAdapter implements RequestInterface
{
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // 不用 InputBag::get()：它对数组值抛 BadRequestException（?run[]=a 这类输入），
        // 而契约声明返回 mixed，调用点 Xhprof::index() 正是靠 is_string() 判断给 400 的。
        $params = $this->request->query->all() + $this->request->request->all();
        return $params[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->request->query->all() + $this->request->request->all();
    }

    public function method(): string
    {
        return $this->request->getMethod();
    }

    public function header(string $name): ?string
    {
        // HeaderBag::get() 缺省即返回 null，与契约的 ?string 一致
        return $this->request->headers->get($name);
    }

    public function host(): string
    {
        // getHost() 已按 RFC 952 小写并去掉端口（R-2）；无 Host 头时返回 ''，不会是 null
        return $this->request->getHost();
    }

    public function uri(): string
    {
        // 只含 path+query，绝不含 scheme/host（R-1：isIgnore() 对它做子串匹配）
        return $this->request->getRequestUri();
    }

    public function url(): string
    {
        return $this->request->getUri();
    }

    public function getRealIp(): string
    {
        // getClientIp() 声明 ?string：无 REMOTE_ADDR（CLI / 内部子请求）时返回 null，
        // 而本方法声明 : string —— strict_types 下返回 null 会 TypeError（R-3）。
        return (string) $this->request->getClientIp();
    }
}
