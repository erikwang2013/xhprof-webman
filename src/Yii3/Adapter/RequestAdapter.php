<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Yii3\Adapter;

use Psr\Http\Message\ServerRequestInterface;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;

class RequestAdapter implements RequestInterface
{
    private ServerRequestInterface $request;

    public function __construct(ServerRequestInterface $request)
    {
        $this->request = $request;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $params = $this->params();

        return array_key_exists($key, $params) ? $params[$key] : $default;
    }

    public function all(): array
    {
        return $this->params();
    }

    public function method(): string
    {
        return $this->request->getMethod();
    }

    /**
     * R-3：header 可以没有，但**绝不能给声明 `: string` 的方法返回 null**。
     * PSR-7 的 getHeaderLine() 缺省返回空串，这里把它归一成 null（契约允许 null），
     * 其余调用点（如 `$http . host() . uri()`）用 !empty() 判空，两种形态都安全。
     */
    public function header(string $name): ?string
    {
        $value = $this->request->getHeaderLine($name);

        return $value === '' ? null : $value;
    }

    /**
     * R-2：只返回 host，**不含端口**。UriInterface::getHost() 本身就不带端口，
     * 不能改成 getAuthority()（那会把 user:pass 与端口一起带进来）。
     */
    public function host(): string
    {
        return $this->request->getUri()->getHost();
    }

    /**
     * R-1：只返回 路径+query，**绝不含 scheme/host**。
     * XhprofLib::isIgnore() 对它做 strpos 子串匹配——返回绝对 URL 会让 host 里
     * 含 "xhprof" 的站点每个请求都被误判为需忽略；StaticController 还会对它
     * parse_url(..., PHP_URL_PATH)。
     */
    public function uri(): string
    {
        $uri = $this->request->getUri();
        $query = $uri->getQuery();
        $path = $uri->getPath();

        return $query === '' ? $path : $path . '?' . $query;
    }

    public function url(): string
    {
        return (string) $this->request->getUri();
    }

    /**
     * R-3：任何分支都返回 string（缺省 127.0.0.1），不返回 null。
     *
     * 头与 serverParams 两处都认：PSR-7 里 header 是权威来源，但 serverParams 是
     * 由 SAPI 直接填的（$_SERVER 的 HTTP_X_FORWARDED_FOR），谁先谁后取决于请求怎么造。
     * 只看一处会让另一类部署静默退化成 127.0.0.1。
     */
    public function getRealIp(): string
    {
        $params = $this->request->getServerParams();
        $forwarded = $this->header('x-forwarded-for') ?? ($params['HTTP_X_FORWARDED_FOR'] ?? null);
        if (is_string($forwarded) && $forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }

        $realIp = $this->header('x-real-ip') ?? ($params['HTTP_X_REAL_IP'] ?? null);
        if (is_string($realIp) && $realIp !== '') {
            return $realIp;
        }

        // CGI 约定是大写键；小写是部分实现的历史写法，一并认。
        $ip = $params['REMOTE_ADDR'] ?? $params['remote_addr'] ?? null;

        return is_string($ip) && $ip !== '' ? $ip : '127.0.0.1';
    }

    /**
     * query 优先，body 里独有的键补齐（与 Webman/Laravel 适配器的 input() 语义一致）。
     *
     * @return array<string, mixed>
     */
    private function params(): array
    {
        $params = $this->request->getQueryParams();
        $body = $this->request->getParsedBody();
        if (is_array($body)) {
            $params += $body;
        }

        return $params;
    }
}
