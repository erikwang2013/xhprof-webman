<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Hyperf;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'middlewares' => [
                'http' => [
                    \ErikWang2013\Xhprof\Hyperf\Middleware::class,
                ],
            ],
            'publish' => [
                [
                    'id' => 'xhprof',
                    'description' => 'XHProf config',
                    'source' => __DIR__ . '/config/xhprof.php',
                    'destination' => BASE_PATH . '/config/autoload/xhprof.php',
                ],
            ],
        ];
    }
}
