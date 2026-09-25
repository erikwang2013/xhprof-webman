<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Hyperf;

use Hyperf\HttpServer\Contract\RequestInterface as HyperfRequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HyperfResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use ErikWang2013\Xhprof\Core\SamplingGuard;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Hyperf\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Hyperf\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Hyperf\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Hyperf\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Hyperf\Adapter\LogAdapter;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\Redis;
use Psr\Log\LoggerInterface;

class Middleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $container = ApplicationContext::getContainer();

        $req = new RequestAdapter($container->get(HyperfRequestInterface::class));
        $res = new ResponseAdapter($container->get(HyperfResponseInterface::class));

        // 必须先声明协程环境：否则 bootstrap() 会把适配器写进进程共享的静态属性，
        // 并发协程之间互相覆盖（详见 Xhprof::markHyperfContext() 注释）。
        Xhprof::markHyperfContext();

        Xhprof::bootstrap(
            $req,
            $res,
            new ConfigAdapter($container->get(ConfigInterface::class)),
            new RedisAdapter($container->get(Redis::class)),
            new LogAdapter($container->get(LoggerInterface::class))
        );

        // 缺 ext-xhprof / ext-redis 时报一句并跳过采样（SamplingGuard 见 Core）；判断顺序
        // 不可换：available() 短路在前，enable=false 时才不会把"缺扩展"这件事吞掉。
        $enabled = SamplingGuard::available() && XhprofProfiler::isEnabled();
        if ($enabled) {
            Xhprof::xhprofStart();
        }

        try {
            return $handler->handle($request);
        } finally {
            if ($enabled) {
                Xhprof::xhprofStop();
            }
        }
    }
}
