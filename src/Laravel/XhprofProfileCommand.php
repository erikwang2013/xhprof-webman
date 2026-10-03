<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Laravel;

use Illuminate\Console\Command;

/**
 * `php artisan xhprof:profile "migrate --force"`：把任意一条 artisan 命令包进采样窗口。
 *
 * 为什么是显式包一层，而不是自动采所有命令：artisan 的生命周期入口在框架里
 * （Kernel::handle），包的 ServiceProvider 拿不到「命令开始 / 结束」这两个时刻；
 * queue worker 那类常驻进程又必须按任务开停（见 XhprofQueueListener 的注释）。
 * xhprof:profile 给的是显式、可组合的窗口：想采哪条就在前面加一层 ——
 * 连 `xhprof:profile "queue:work"`（窗口覆盖整个 worker 进程）也是同一行代码。
 *
 * 内层命令行按**整串**收（引号包住整条）：走 Application::call() 的 StringInput 解析
 * （Illuminate/Console/Application.php:190），选项因此原样透传。用 Command::call() 不行
 * —— 它把整串当命令名交给 ArrayInput（Concerns/CallsCommands.php:64-70 的 runCommand），
 * `migrate --force` 会变成「名为 `migrate --force` 的命令」找不到。
 */
final class XhprofProfileCommand extends Command
{
    protected $signature = 'xhprof:profile {line : 要采样的命令整串（含选项），如 "migrate --force"}';

    protected $description = '在采样窗口内执行一条 artisan 命令';

    public function handle(): int
    {
        $application = $this->getApplication();

        // 命令没挂到 console 应用上（只出现在直接 new 出来的单测场景）：没有可解析命令行的
        // 应用，开窗口没有意义 —— 失败返回，不留一条空 run。
        if ($application === null) {
            return self::FAILURE;
        }

        XhprofCli::start();

        try {
            // 退出码原样穿回：外层命令的退出码 = 内层命令的退出码，CI/脚本里的判断不受影响。
            return $application->call($this->argument('line'));
        } finally {
            // 内层命令抛异常也要落库并关窗口（同队列监听器的失败路径）。
            XhprofCli::stop();
        }
    }
}
