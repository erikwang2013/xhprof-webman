<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Native\Adapter;

use ErikWang2013\Xhprof\Core\Contract\RequestInterface;

/**
 * 原生 PHP 请求适配器：只读超全局，不依赖任何框架对象。
 *
 * 数据来源就是 PHP 自己给的那几个：`$_SERVER['REQUEST_URI']` / `REQUEST_METHOD` /
 * `$_GET` / `$_POST` / `getallheaders()`（缺它时从 `$_SERVER` 还原，见 allHeaders()）。
 *
 * **`$_COOKIE` 刻意不参与 get()/all()**：报告页的 auth_token 是从这里读的
 * （Xhprof::index() → `$req->get('token')`），能从 Cookie 里读出来就是鉴权旁路；
 * 十家里 WordPress 对 `$_REQUEST` 也是同一条理由。Cookie 里没有本包需要的输入。
 */
class RequestAdapter implements RequestInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        // 显式取 $_GET / $_POST，刻意不用 $_REQUEST：$_REQUEST 的内容由 ini 的
        // request_order 决定，配成含 C 时会把 Cookie 混进来（见类注释）。
        if (array_key_exists($key, $_GET)) {
            return $_GET[$key];
        }
        if (array_key_exists($key, $_POST)) {
            return $_POST[$key];
        }

        return $default;
    }

    public function all(): array
    {
        // GET 覆盖 POST：与 get() 的优先级保持同一条规则。报告页的 run/source/token 都在
        // query 里（XhprofDisplay::show_nav() 拿这个数组拼链接），GET 为准才不会因为一个
        // 同名 POST 字段就把链接指的 run 换掉。
        // 用 `+` 而不是 array_merge(POST, GET)：后者虽然也是 GET 赢，但键序变成 POST 在前，
        // 拼出来的链接参数顺序会跟 URL 里不一致。
        return $_GET + array_diff_key($_POST, $_GET);
    }

    public function method(): string
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';

        return is_string($method) && $method !== '' ? strtoupper($method) : 'GET';
    }

    public function header(string $name): ?string
    {
        $key = strtoupper(str_replace('-', '_', $name));
        if ($key === '') {
            return null;
        }

        foreach (self::allHeaders() as $header => $value) {
            if (strtoupper(str_replace('-', '_', (string) $header)) !== $key) {
                continue;
            }

            // 缺省必须返回 null 而不是 ''：契约的 header() 声明 ?string，
            // 调用点 XHProfRunsDefault 用 !empty() 判空（x-forwarded-proto），
            // 两者都需要「无值」与「空值」可区分。
            return is_string($value) ? $value : null;
        }

        return null;
    }

    public function host(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? $_SERVER['SERVER_ADDR'] ?? '';
        if (!is_string($host) || $host === '') {
            return '';
        }

        // 契约要求只返回主机名、不含端口（R-2）。已知代价只有一处：列表页那行
        // request_uri 的**显示文本**不体现端口（它由 `host() . uri()` 拼成）。
        // 页面里的链接不受影响——统一由 XhprofLib::report_url() 生成相对 URL。十一家一致。
        $parsed = parse_url('http://' . $host, PHP_URL_HOST);

        return is_string($parsed) && $parsed !== '' ? $parsed : $host;
    }

    public function uri(): string
    {
        // R-1：只返回路径 + query，绝不含 scheme/host。$_SERVER['REQUEST_URI'] 天然
        // 就是这个形状——不要在这里拼 host，否则 host 叫 xhprof.* 的站点每个请求都会
        // 被 XhprofLib::isIgnore() 的子串匹配误判为「需忽略」。
        // 注意 REQUEST_URI 可能整个缺席（纯 CLI、部分内嵌 SAPI）：那时 uri() 是空串，
        // 而 XhprofLib::isIgnore() 对空 uri() 判定为「不落库」（见该方法的第二个 return）。
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        return is_string($uri) ? $uri : '';
    }

    public function url(): string
    {
        return $this->scheme() . '://' . $this->host() . $this->uri();
    }

    public function getRealIp(): string
    {
        // 反代场景：优先取 X-Forwarded-For 的第一段（客户端地址，后面是各级代理）。
        foreach (['x-forwarded-for', 'x-real-ip'] as $name) {
            $value = $this->header($name);
            if ($value !== null && $value !== '') {
                return trim(explode(',', $value)[0]);
            }
        }

        $value = $_SERVER['REMOTE_ADDR'] ?? null;

        // R-3：本方法声明 : string，strict_types 下返回 null 会 TypeError。
        return is_string($value) && $value !== '' ? $value : '127.0.0.1';
    }

    /**
     * url() 的 scheme。
     *
     * 口径与 WordPress 的 `is_ssl()` 一致（该判定在环里跑的是真 WP 源码）：`HTTPS` 为
     * `'on'`/`'1'` 即 https；否则**仅当 `HTTPS` 未设置**时看 `SERVER_PORT === '443'`。
     * 刻意不看 `X-Forwarded-Proto`：反代终止 TLS 的部署要在前段把 HTTPS 配好，否则
     * 报告页的绝对 URL 是 http。`url()` 的唯一消费者是 XhprofDisplay::xhprof_include_js_css()
     * 在**没配 assets_url**时用 `dirname(url())` 拼资源前缀——正常配置下用不到它。
     */
    private function scheme(): string
    {
        $https = $_SERVER['HTTPS'] ?? null;
        if (is_string($https) && ($https === 'on' || $https === '1')) {
            return 'https';
        }
        if ($https === null && (string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return 'https';
        }

        return 'http';
    }

    /**
     * 请求头表（头名 => 值）。`header()` 与 `getRealIp()` 共用这一张表。
     *
     * `getallheaders()` 只在部分 SAPI 存在：apache2handler / fpm / cli-server 有，
     * **纯 CLI 没有**（本机实测 `function_exists('getallheaders') === false`），
     * 而单测与缺扩展子进程驱动都跑在纯 CLI 上，所以缺它时从 `$_SERVER` 还原同一张表。
     * PHP 把绝大多数头放进 `HTTP_*`，只有这两个不带前缀（与 WordPress 适配器同一条）。
     *
     * @return array<string, mixed>
     */
    private static function allHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers) && $headers !== []) {
                return $headers;
            }
        }

        $out = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $out[substr($key, 5)] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
