<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Thinkphp;

use think\Request;
use Closure;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Thinkphp\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Thinkphp\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Thinkphp\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Thinkphp\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Thinkphp\Adapter\LogAdapter;

class Middleware
{
    public function handle(Request $request, Closure $next)
    {
        $req = new RequestAdapter($request);
        $res = new ResponseAdapter(response(''));

        Xhprof::bootstrap($req, $res, new ConfigAdapter(), new RedisAdapter(), new LogAdapter());

        $response = $next($request);

        if (XhprofProfiler::isEnabled() && extension_loaded('xhprof')) {
            Xhprof::xhprofStop();
        }

        return $response;
    }
}
