<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Laravel;

use Illuminate\Support\ServiceProvider;

class XhprofServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/config/xhprof.php', 'xhprof'
        );
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config/xhprof.php' => config_path('xhprof.php'),
        ], 'xhprof-config');
    }
}
