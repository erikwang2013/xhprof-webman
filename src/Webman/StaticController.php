<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Webman;

use Webman\Http\Request;
use Webman\Http\Response;
use ErikWang2013\Xhprof\Core\StaticController as CoreStaticController;
use ErikWang2013\Xhprof\Webman\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Webman\Adapter\ResponseAdapter;

class StaticController
{
    public static function serve(Request $request): Response
    {
        $req = new RequestAdapter($request);
        $res = new ResponseAdapter(new Response(200));
        $result = CoreStaticController::serve($req, $res);
        return $result->send();
    }
}
