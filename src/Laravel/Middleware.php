<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Laravel;

use Closure;
use Illuminate\Http\Request;
use ErikWang2013\Xhprof\Core\MiddlewareTrait;
use ErikWang2013\Xhprof\Laravel\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\LogAdapter;

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
