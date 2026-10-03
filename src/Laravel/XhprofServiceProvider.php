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

        // 命令随包自动注册：本 Provider 就是 Laravel 自动发现的那个（README 的安装段这么写），
        // 所以用户零配置拿到 `xhprof:profile`，不需要在自己的 AppServiceProvider 里再注册一遍。
        // 注册时机由 ServiceProvider::commands() 转交（Illuminate/Support/ServiceProvider.php:474-481
        // → Illuminate\Console\Application::starting()，闭包攒到控制台应用构建时的 bootstrap()）。
        $this->commands([XhprofProfileCommand::class]);
    }
}
