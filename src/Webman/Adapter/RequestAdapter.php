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
        // 与 all() 共用一份合并结果 —— 同一个契约不能因为访问方式不同而给不同答案。
        // webman 的 all() 是 `get() + post()`（query 胜出），而 Request::get() 只读 query：
        // 参数只出现在 body 里时 get(k) 给 default、all()[k] 给值。Xhprof::index() 两条路
        // 都用（前者做白名单校验、后者渲染），两个答案就是「校验一个值、渲染另一个值」。
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function all(): array
    {
        // post() 里的键 query 没有时才补上（webman 自己的 `+` 合并顺序即 query 优先）。
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
