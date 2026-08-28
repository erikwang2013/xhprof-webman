<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core;

use Closure;
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

        $enabled = XhprofProfiler::isEnabled() && extension_loaded('xhprof');
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
