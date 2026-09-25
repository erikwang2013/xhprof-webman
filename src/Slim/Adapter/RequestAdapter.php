<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Slim\Adapter;

use Psr\Http\Message\ServerRequestInterface;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;

/**
 * 直接适配 PSR-7 的 ServerRequestInterface —— 不依赖 Slim 的任何类。
 * Slim 把请求交给中间件时就是 PSR-7 对象，所以我们只需要 PSR-7 语义。
 */
class RequestAdapter implements RequestInterface
{
    private ServerRequestInterface $request;

    public function __construct(ServerRequestInterface $request)
    {
        $this->request = $request;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // 与 all() 共用 params() —— 同一个契约不能因为访问方式不同而给不同答案。
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

    public function header(string $name): ?string
    {
        // R-3：PSR-7 的 getHeaderLine() 缺省返回 ''，契约要求缺省返回 null。
        // 直接用 '' 会在 XHProfRunsDefault 的 `!empty($http)` 处侥幸无事，
        // 但契约是 null，且 `: string` 的方法绝不能返回 null。
        $line = $this->request->getHeaderLine($name);
        return $line === '' ? null : $line;
    }

    public function host(): string
    {
        // R-2：只取 host，不含端口。PSR-7 的 getHost() 天然不含端口
        // （端口在 getPort() 里），所以这里不需要再剥。
        return $this->request->getUri()->getHost();
    }

    public function uri(): string
    {
        // R-1：只返回 path + query，绝不含 scheme/host。
        // XhprofLib::isIgnore() 对它做 strpos 子串匹配：返回绝对 URL 会让 host
        // 叫 xhprof.* 的站点每个请求都被误判为需忽略。
        $uri = $this->request->getUri();
        $query = $uri->getQuery();
        return $uri->getPath() . ($query === '' ? '' : '?' . $query);
    }

    public function url(): string
    {
        // 完整 URL（XhprofDisplay::xhprof_include_js_css() 用 dirname(url()) 兜底推资源目录）。
        // PSR-7 的 __toString() 就是 RFC 3986 串回，绝对/相对取决于请求怎么造的。
        return (string) $this->request->getUri();
    }

    public function getRealIp(): string
    {
        $params = $this->request->getServerParams();

        // 两套键名都要认：由 $_SERVER 造出来的 PSR-7 请求是 HTTP_X_FORWARDED_FOR，
        // 而 Swoole/Hyperf 一类协程服务器给的是小写 x-forwarded-for。
        // 只看一套会让另一类部署静默退化成 127.0.0.1。
        $forwarded = $params['HTTP_X_FORWARDED_FOR'] ?? $params['x-forwarded-for'] ?? null;
        if (is_string($forwarded) && $forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }

        $realIp = $params['HTTP_X_REAL_IP'] ?? $params['x-real-ip'] ?? null;
        if (is_string($realIp) && $realIp !== '') {
            return $realIp;
        }

        // R-3：`: string` 方法必须给出 string，缺省兜底 127.0.0.1（与 Hyperf/ThinkPHP 同）。
        $remote = $params['REMOTE_ADDR'] ?? $params['remote_addr'] ?? null;
        return is_string($remote) && $remote !== '' ? $remote : '127.0.0.1';
    }

    /**
     * query 与 body 的合并，**query 优先**。
     *
     * get() 与 all() 都必须走这一处。此前 get() 是 query→body 逐级回退、all() 是
     * `array_replace(query, body)`（body 胜出），于是 `?run=<合法>` + POST `run=<任意>`
     * 时 get('run') 给合法值、all()['run'] 给任意值。Xhprof::index() 恰好两条路都用：
     * 前者做白名单校验（xhprof_valid_run_id），后者交给 displayXHProfReport() 渲染 ——
     * 结果就是「校验合法值、渲染非法值」，外层校验被绕过。合并顺序写两遍就会再漂，
     * 所以只留这一份（与 Yii3 适配器同语义）。
     */
    private function params(): array
    {
        $params = $this->request->getQueryParams();
        $body = $this->request->getParsedBody();
        if (is_array($body)) {
            $params += $body;  // += 不覆盖已有键 → query 胜出，body 只补 query 没有的键
        }

        return $params;
    }
}
