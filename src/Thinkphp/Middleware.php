<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Thinkphp;

use think\Request;
use Closure;
use ErikWang2013\Xhprof\Core\MiddlewareTrait;
use ErikWang2013\Xhprof\Thinkphp\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Thinkphp\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Thinkphp\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Thinkphp\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Thinkphp\Adapter\LogAdapter;

class Middleware
{
    use MiddlewareTrait;

    public function handle(Request $request, Closure $next)
    {
        return $this->runXhprof($request, $next);
    }

    protected function xhprofAdapters($request): array
    {
        return [
            new RequestAdapter($request),
            new ResponseAdapter(response('')),
            new ConfigAdapter(),
            new RedisAdapter(),
            new LogAdapter(),
        ];
    }
}
