<?php
declare(strict_types=1);

namespace Erik\Xhprof\Webman;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * Class StaticFile
 * @package app\middleware
 */
class XhprofMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $xhprof = config('app.xhprof')['enable'];
        $extension = extension_loaded('xhprof');
        if ($xhprof && $extension) Xhprof::xhprofStart();
        $response = $next($request);
        if ($xhprof && $extension) Xhprof::xhprofStop();
        return $response;
    }
}
