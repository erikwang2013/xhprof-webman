<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Webman;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use ErikWang2013\Xhprof\Core\XhprofMiddleware as CoreMiddleware;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Webman\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Webman\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Webman\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Webman\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Webman\Adapter\LogAdapter;

class XhprofMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $req = new RequestAdapter($request);
        $res = new ResponseAdapter(response(''));

        Xhprof::bootstrap($req, $res, new ConfigAdapter(), new RedisAdapter(), new LogAdapter());

        $xhprof = XhprofProfiler::isEnabled();
        $extension = extension_loaded('xhprof');
        $redis = extension_loaded('redis');

        if (!$extension) {
            return response()->withBody('请安装xhprof扩展');
        }
        if (!$redis) {
            return response()->withBody('请安装redis扩展');
        }

        if ($xhprof && $extension) {
            Xhprof::xhprofStart();
        }

        $response = $handler($request);

        if ($xhprof && $extension) {
            Xhprof::xhprofStop();
        }

        return $response;
    }
}
