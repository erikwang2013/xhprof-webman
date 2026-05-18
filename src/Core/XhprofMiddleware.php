<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core;

use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;

class XhprofMiddleware
{
    public static function processProfiling(
        RequestInterface $request,
        callable $handler,
        callable $responseFactory
    ): mixed {
        Xhprof::bootstrap($request, null, null, null, null);

        if (XhprofProfiler::isEnabled()) {
            Xhprof::xhprofStart();
        }

        $response = $handler($request);

        if (XhprofProfiler::isEnabled()) {
            Xhprof::xhprofStop();
        }

        return $response;
    }

    protected static function wrapRequest($nativeRequest): RequestInterface
    {
        throw new \BadMethodCallException('Must be overridden by framework adapter');
    }
}
