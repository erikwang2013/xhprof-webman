<?php
declare(strict_types=1);

namespace Aaron\Xhprof\Webman;

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
        $config=config('plugin.erikwang2013.xhprof.xhprof');
        $xhprof =$config['enable']?:false;
        $extension = extension_loaded('xhprof');
        Xhprof::$ignore_url_arr=$config['ignore_url_arr']?:"/test";
        Xhprof::$time_limit=$config['time_limit']?:0;
        Xhprof::$log_num=$config['log_num']?:1000;
        Xhprof::$view_wtred=$config['view_wtred']?:3;
        if ($xhprof && $extension) Xhprof::xhprofStart();
        $response = $next($request);
        if ($xhprof && $extension) Xhprof::xhprofStop();
        return $response;
    }
}
