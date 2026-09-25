<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Webman;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

use ErikWang2013\Xhprof\Core\SamplingGuard;
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

        // 缺扩展时 available() 已经记录"性能采样已跳过"，故必须连它一起判断：
        // 若仍启动采样，既白付采样开销，落库也必然失败——日志与实际行为自相矛盾。
        // 判断与告警都收在 Core\SamplingGuard（十家入口共用一份，含缺哪个扩展的文案）。
        $enabled = SamplingGuard::available() && XhprofProfiler::isEnabled();

        if ($enabled) {
            Xhprof::xhprofStart();
        }

        try {
            return $handler($request);
        } finally {
            if ($enabled) {
                Xhprof::xhprofStop();
            }
        }
    }
}
