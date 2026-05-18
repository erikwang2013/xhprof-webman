<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Hyperf;

use Hyperf\HttpServer\Contract\RequestInterface as HyperfRequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HyperfResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
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

        Xhprof::bootstrap(
            $req,
            $res,
            new ConfigAdapter($container->get(ConfigInterface::class)),
            new RedisAdapter($container->get(Redis::class)),
            new LogAdapter($container->get(LoggerInterface::class))
        );

        $enabled = XhprofProfiler::isEnabled() && extension_loaded('xhprof');
        if ($enabled) {
            Xhprof::xhprofStart();
        }

        $response = $handler->handle($request);

        if ($enabled) {
            Xhprof::xhprofStop();
        }

        return $response;
    }
}
