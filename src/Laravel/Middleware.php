<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Laravel;

use Closure;
use Illuminate\Http\Request;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Laravel\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\LogAdapter;

class Middleware
{
    public function handle(Request $request, Closure $next)
    {
        $req = new RequestAdapter($request);
        $res = new ResponseAdapter(response(''));

        Xhprof::bootstrap($req, $res, new ConfigAdapter(), new RedisAdapter(), new LogAdapter());

        $enabled = XhprofProfiler::isEnabled() && extension_loaded('xhprof');
        if ($enabled) {
            Xhprof::xhprofStart();
        }

        $response = $next($request);

        if ($enabled) {
            Xhprof::xhprofStop();
        }

        return $response;
    }
}
