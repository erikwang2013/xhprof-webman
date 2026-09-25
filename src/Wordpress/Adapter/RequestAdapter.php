<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Wordpress\Adapter;

use ErikWang2013\Xhprof\Core\Contract\RequestInterface;

/**
 * WordPress 请求适配器：只读超全局，不依赖任何框架对象。
 *
 * WordPress 在 `wp-settings.php:512` 调用 `wp_magic_quotes()`，把 `$_GET` / `$_POST` /
 * `$_COOKIE` / `$_SERVER` 统统加上反斜杠（magic quotes 的历史包袱），而本适配器属于
 * 「读原始输入」的代码，所以取出的值都要 `wp_unslash()`。
 *
 * 时序（已对 WP 6.4.3 的 wp-settings.php 逐行核对）：`plugins_loaded` 在 :506 触发、
 * `wp_magic_quotes()` 在 :512——即**报告页判断跑在加反斜杠之前**，而采样数据在 `shutdown`
 * 时读到超全局（远在 :512 之后，已经带反斜杠）。所以同一份 `uri()` 在两个时点读到的
 * 原始值不同，`wp_unslash()` 必须留在里面：去掉它，报告列表里的 `request_uri` 链接会带上
 * 反斜杠。`uri()` 的错误后果最重——它既被 `XhprofLib::isIgnore()` 做子串匹配，又被写进报告列表。
 *
 * 已知上限：`header()` / `host()` 里的 `$_SERVER` 值未做 `wp_unslash()`——
 * 这些值只进入展示字段，不会参与路径判断或校验，暂不为它们付一次调用的代价。
 */
class RequestAdapter implements RequestInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        // 显式取 $_GET / $_POST，刻意不用 $_REQUEST：$_REQUEST 的内容由 ini 的
        // request_order 决定，配成含 C 时会把 Cookie 混进来——报告页的 auth_token
        // 是从这里读的，能从 Cookie 里读出来就是鉴权旁路。
        $get = wp_unslash($_GET);
        if (array_key_exists($key, $get)) {
            return $get[$key];
        }
        $post = wp_unslash($_POST);
        if (array_key_exists($key, $post)) {
            return $post[$key];
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
        $get = wp_unslash($_GET);
        $post = wp_unslash($_POST);

        return $get + array_diff_key($post, $get);
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

        // PHP 把这两个头放进 $_SERVER 时**不带** HTTP_ 前缀，其余头都带。
        if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
            $value = $_SERVER[$key] ?? null;
            if (is_string($value)) {
                return $value;
            }
        }

        $value = $_SERVER['HTTP_' . $key] ?? null;

        // 缺省必须返回 null 而不是 ''：契约的 header() 声明 ?string，
        // 调用点 XHProfRunsDefault 用 !empty() 判空，两者都需要「无值」与「空值」可区分。
        return is_string($value) ? $value : null;
    }

    public function host(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? $_SERVER['SERVER_ADDR'] ?? '';
        if (!is_string($host) || $host === '') {
            return '';
        }

        // 契约要求只返回主机名、不含端口（R-2）。已知代价：:8080 部署下列表页链接会坏，
        // 这是既有问题，十个框架一致，本次不修。
        $parsed = parse_url('http://' . $host, PHP_URL_HOST);

        return is_string($parsed) && $parsed !== '' ? $parsed : $host;
    }

    public function uri(): string
    {
        // R-1：只返回路径 + query，绝不含 scheme/host。$_SERVER['REQUEST_URI'] 天然
        // 就是这个形状——不要在这里拼 host，否则 host 叫 xhprof.* 的站点每个请求都会
        // 被 XhprofLib::isIgnore() 的子串匹配误判为「需忽略」。
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        return is_string($uri) ? (string) wp_unslash($uri) : '';
    }

    public function url(): string
    {
        return $this->scheme() . '://' . $this->host() . $this->uri();
    }

    public function getRealIp(): string
    {
        // 反代场景：优先取 X-Forwarded-For 的第一段（客户端地址，后面是各级代理）。
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $key) {
            $value = $_SERVER[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return trim(explode(',', $value)[0]);
            }
        }

        $value = $_SERVER['REMOTE_ADDR'] ?? null;

        // R-3：本方法声明 : string，strict_types 下返回 null 会 TypeError。
        return is_string($value) && $value !== '' ? $value : '127.0.0.1';
    }

    /** is_ssl() 走 WordPress 自己的判断，反代下由站点配置（HTTPS 常量 / X-Forwarded-Proto）决定。 */
    private function scheme(): string
    {
        return is_ssl() ? 'https' : 'http';
    }
}
