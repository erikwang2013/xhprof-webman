<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Laravel\Adapter;

use ErikWang2013\Xhprof\Core\Contract\RequestInterface;

/**
 * 没有 HTTP 请求的 CLI/队列场景用的请求适配器（artisan、定时任务、queue worker）。
 *
 * 为什么不复用 RequestAdapter（它包一个 Illuminate\Http\Request）：CLI 下
 * `Request::createFromGlobals()` 的两个实测结果都不合用——`getRequestUri()` 确实是
 * 空串（正是 Core 认的 CLI 形态），但 `ip()` 返回 **null**（没有 REMOTE_ADDR），而
 * `RequestAdapter::getRealIp(): string` 在 strict_types 下当场抛 TypeError，落库前
 * 一秒炸掉整条采样；容器里的 'request' 绑定在 console/queue 进程里也不保证存在
 * （CLI 没有请求生命周期）。所以这里给一份不依赖 HTTP 的常量实现，取 IP 的口径与
 * Native 的 RequestAdapter 一致（REMOTE_ADDR 缺席时 `'127.0.0.1'`，见
 * src/Native/Adapter/RequestAdapter.php:117-118 的 R-3）。
 *
 * `uri()` 返回空串是**契约**而不是随手填的默认值：Core 的 `XhprofLib::isIgnore()`
 * 以空 URI 判定「没有 HTTP 请求」（src/Core/XhprofLib/Utils/XhprofLib.php:550），
 * 据此按配置 `xhprof.sample_cli` 放行/忽略；放行时
 * `XHProfRunsDefault::_saveToRedis()`（同文件 :281-292）再合成 `cli:<脚本名>`
 * 作为落库的 request_uri。改成 `'/'` 之类会静默走 HTTP 分支——sample_cli=false
 * 也照采、列表页还会把 CLI 数据显示成一条根路径请求。
 */
class CliRequestAdapter implements RequestInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        // CLI 没有 query/body。保留参数是为了满足接口（报告页的 run/source 参数只在
        // HTTP 入口有意义）。
        return $default;
    }

    public function all(): array
    {
        return [];
    }

    public function method(): string
    {
        // 落库后显示在列表页「方法」列（XHProfRunsDefault::_saveToRedis() :270
        // 是全仓唯一读者）。'CLI' 比伪造一个 'GET' 诚实：列表里一眼认出命令行来源。
        return 'CLI';
    }

    public function header(string $name): ?string
    {
        // 无请求头 = 无 X-Xhprof-Token / x-forwarded-proto：按需触发采样在 CLI 下
        // 不生效（走 sample_rate 抽签），落库也不会拼出假的 scheme。
        return null;
    }

    public function host(): string
    {
        return '';
    }

    public function uri(): string
    {
        // 空串 = Core 的 CLI 判定入口，见类注释。这一条不能"顺手补个 /"。
        return '';
    }

    public function url(): string
    {
        return '';
    }

    public function getRealIp(): string
    {
        return '127.0.0.1';
    }
}
