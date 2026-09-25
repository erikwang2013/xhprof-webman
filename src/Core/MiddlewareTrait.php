<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core;

use Closure;
use ErikWang2013\Xhprof\Core\SamplingGuard;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;

/**
 * Laravel/Thinkphp 共享的采样包裹逻辑：适配器注入由子类通过
 * xhprofAdapters() 提供（两个框架的 Adapter 类同名但属于不同命名空间）。
 */
trait MiddlewareTrait
{
    abstract protected function xhprofAdapters($request): array;

    protected function runXhprof($request, Closure $next)
    {
        [$req, $res, $config, $cache, $logger] = $this->xhprofAdapters($request);

        Xhprof::bootstrap($req, $res, $config, $cache, $logger);

        // 缺 ext-xhprof / ext-redis 时报一句并跳过采样（XhprofProfiler 的落库与报告页
        // 读缓存都依赖 ext-redis，缺了它只剩每请求一行失败日志）。判断顺序不可换：
        // available() 短路在前，enable=false 时才不会把"缺扩展"这件事吞掉。
        // Laravel/Thinkphp 两个入口共用本 trait，故这里改一处两家都生效。
        $enabled = SamplingGuard::available() && XhprofProfiler::isEnabled();
        if ($enabled) {
            Xhprof::xhprofStart();
        }

        try {
            return $next($request);
        } finally {
            if ($enabled) {
                Xhprof::xhprofStop();
            }
        }
    }
}
