<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Joomla\Adapter;

use Joomla\Input\Input;
use Joomla\Uri\UriHelper;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;

/**
 * Joomla\Input\Input → 契约 RequestInterface。
 *
 * Joomla 没有「请求对象」：$app->getInput() 拿到的是一个包装 $_REQUEST 的 Input，
 * 没有 headers/host/url 的概念，这些只能从 Input 的 server 子对象（包装 $_SERVER）里取。
 *
 * 注意 Input 的 __get 是**魔法属性且会缓存**：首次访问 $input->server 时把 $_SERVER
 * 快照成一个新 Input，之后再改 $_SERVER 不会反映到已缓存的 server 上。Joomla 请求里
 * $_SERVER 在插件运行前就绪，故无影响；测试里必须先摆好 $_SERVER 再访问。
 */
class RequestAdapter implements RequestInterface
{
    private Input $input;

    public function __construct(Input $input)
    {
        $this->input = $input;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // 第三个参数不能省：Joomla\Input\Input::get() 默认过滤器是 'cmd'，它把
        // [^A-Z0-9_.-] 全删掉——auth_token='a+b/c=' 会被读成 'abc'，hash_equals 永不相等，
        // 报告页对任何含特殊字符的 token 恒返回 403。'raw' 不过滤，与其余 9 个框架的
        // 适配器（webman input() / laravel input() / think param()）口径一致；
        // 参数合法性由 Core\Xhprof::index() 自己的白名单校验负责。
        return $this->input->get($key, $default, 'raw');
    }

    public function all(): array
    {
        // Input 的 $data 是 protected，没有原始数据访问器，getArray() 是唯一的全量枚举入口。
        // 但 getArray() 把每个值当成「过滤器名」再清洗一遍，未知过滤器退化为 cleanString
        // （XSS 过滤），与上面 get() 的 'raw' 口径不一致。故只用它枚举 key，再用同一个
        // 过滤器读值，保证 all()[k] 恒等于 get(k)。
        $all = [];
        foreach (array_keys($this->input->getArray()) as $key) {
            $all[$key] = $this->input->get($key, null, 'raw');
        }
        return $all;
    }

    public function method(): string
    {
        // Input::getMethod() 内部是 strtoupper($this->server->getCmd('REQUEST_METHOD'))：
        // REQUEST_METHOD 缺失时 strtoupper(null) 在 PHP 8.1+ 触发 deprecation 且返回 ''，
        // 而本方法声明 : string 不能把 ''/null 漏出去（契约 R-3 同类事故：每个请求 500）。
        $server = $this->input->server;
        if (!$server->exists('REQUEST_METHOD')) {
            return 'GET';
        }
        $method = (string) $this->input->getMethod();
        return $method === '' ? 'GET' : $method;
    }

    public function header(string $name): ?string
    {
        $server = $this->input->server;
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (!$server->exists($key)) {
            // Content-Type / Content-Length 在 CGI 里不带 HTTP_ 前缀
            $key = strtoupper(str_replace('-', '_', $name));
            if (!$server->exists($key)) {
                return null;
            }
        }
        // 契约 R-3：无值返回 null，绝不返回 '' 或非字符串
        $value = (string) $server->get($key, '', 'string');
        return $value === '' ? null : $value;
    }

    public function host(): string
    {
        $server = $this->input->server;
        $host = (string) $server->get('HTTP_HOST', '', 'string');
        if ($host === '') {
            $host = (string) $server->get('SERVER_NAME', '', 'string');
        }
        if ($host === '') {
            return 'localhost';
        }
        // 契约 R-2：host() 不含端口。CGI 的 HTTP_HOST 带端口，用 Joomla 自己的
        // UTF-8 安全 parse_url 拆一次，端口落在 'port' 上，host 天然剥掉。
        $parts = UriHelper::parse_url('http://' . $host);
        return is_array($parts) && isset($parts['host']) ? (string) $parts['host'] : $host;
    }

    public function uri(): string
    {
        // 契约 R-1：只返回 path+query，绝不含 scheme/host。
        // REQUEST_URI 本身就是这个形态，但反向代理可能把它写成绝对 URL（或缺省为 ''），
        // 故统一归一化一次。
        // 刻意不用 new Joomla\Uri\Uri：它在 parse_url 返回 false 时抛 RuntimeException，
        // 而 REQUEST_URI 是攻击者可影响的输入（例如 'http:///x' 就让 parse_url 返回 false），
        // 抛异常 = 每个请求 500——正是契约 R-3 注释里记录过的事故形态。
        // UriHelper::parse_url() 是同一个包里的无异常版本，返回 false 而不是抛。
        $requestUri = (string) $this->input->server->get('REQUEST_URI', '', 'string');
        if ($requestUri === '') {
            return '/';
        }
        $parts = UriHelper::parse_url($requestUri);
        if (!is_array($parts)) {
            return '/';
        }
        $path = (string) ($parts['path'] ?? '');
        $query = (string) ($parts['query'] ?? '');
        return ($path === '' ? '/' : $path) . ($query === '' ? '' : '?' . $query);
    }

    public function url(): string
    {
        return $this->scheme() . '://' . $this->host() . $this->uri();
    }

    public function getRealIp(): string
    {
        // 与 Hyperf 适配器同一套优先级：X-Forwarded-For 首段 > X-Real-IP > REMOTE_ADDR。
        $server = $this->input->server;
        $forwarded = (string) $server->get('HTTP_X_FORWARDED_FOR', '', 'string');
        if ($forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }
        $real = (string) $server->get('HTTP_X_REAL_IP', '', 'string');
        if ($real !== '') {
            return $real;
        }
        $remote = (string) $server->get('REMOTE_ADDR', '', 'string');
        return $remote === '' ? '127.0.0.1' : $remote;
    }

    private function scheme(): string
    {
        // CGI 约定：$_SERVER['HTTPS'] 非空且不是 'off' 即 HTTPS
        $https = strtolower((string) $this->input->server->get('HTTPS', '', 'cmd'));
        return ($https !== '' && $https !== 'off') ? 'https' : 'http';
    }
}
