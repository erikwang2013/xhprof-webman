<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Laravel;

use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\SamplingGuard;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Laravel\Adapter\CliRequestAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\LogAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\ResponseAdapter;

/**
 * Laravel 的 CLI 采样窗口（artisan 命令 / 定时任务 / 队列 worker 共用的一份）。
 *
 * 与 HTTP 入口（Middleware）的差别只有两点：
 *   1. 请求适配器换成 CliRequestAdapter —— 没有 HTTP 请求也要立得起来（见该类注释：
 *      Illuminate 的 Request 在 CLI 下 ip() 为 null，会撞上 RequestAdapter 的返回类型）；
 *   2. 窗口由调用方**显式开停**。CLI 没有「一次请求一次响应」的天然止点：queue worker
 *      是常驻进程（一个 `queue:work` 能跑几天），Symfony 入口那套「stop 挂在进程
 *      shutdown 上」的兜底在常驻形态下要么永不触发、要么把上一个任务的状态带进下一个
 *      （见 src/Symfony/XhprofListener.php:141-158 的注册守卫）。所以这里按**任务**开停：
 *      一次 start() 配一次 stop()。
 *
 * 谁在调用：包内 XhprofQueueListener（队列四个事件）；自定义 artisan 命令 / cron 脚本
 * 手动包一层（`XhprofCli::start(); try { ... } finally { XhprofCli::stop(); }`）。
 * 落库的 request_uri 由 Core 合成为 `cli:<脚本名>`（sample_cli=true 时）。
 */
final class XhprofCli
{
    /**
     * 嵌套深度：0 = 当前没有采样窗口。
     *
     * 为什么不是 bool：窗口可以嵌套，而且不是假想——`dispatchSync()` 会走 SyncQueue，
     * 同一个进程里先派 JobProcessing 再派 JobProcessed（Laravel 12:
     * vendor/laravel/framework/src/Illuminate/Queue/SyncQueue.php:129,133；Laravel 13
     * 同文件 :224,228 实测同形）。被队列任务同步派发的子任务因此会在外层窗口里再开一次
     * start()。bool 形态下子任务的 JobProcessed 就把**外层任务**的采样提前关掉了：报告里
     * 那条 run 只覆盖到子任务结束，外层任务的后半段静默丢失。带深度后内层的 stop 只把
     * 深度减回去，落库发生在最外层 stop —— 一个最外层窗口 = 一条 run。
     *
     * 与 Core 的分工：`XhprofProfiler::stop()` 自身对 `$running` 幂等（兜「没有配对 start
     * 的 stop」这一大类），这里这份计数兜的是**本入口自己的**配对语义——多出来的 stop
     * 不能误关别人还开着的窗口。
     */
    private static int $depth = 0;

    /**
     * 开一个采样窗口。可重复调用（嵌套），每次调用都要配对一次 stop()。
     *
     * `$request` 是注入点：默认用 CliRequestAdapter（无 HTTP 请求形态），单测与需要
     * 伪装成某个 URI 的场景可换掉；其余四个适配器与 HTTP 入口同一份（Laravel 的
     * config()/Redis/Log 门面在 console 与 queue 进程里都可用）。
     */
    public static function start(?RequestInterface $request = null): void
    {
        // bootstrap 必须在 isEnabled() 之前：XhprofProfiler::$config 是静态的，
        // 常驻 worker 里晚一步就读到上一个任务的配置（与中间件、Symfony 监听器同序）。
        // 每次 start 都重装：适配器都是无状态薄壳，重复构造的开销是微秒级，换来的是
        // 「任务之间连接被重连/配置被改」时不会用到陈旧引用。
        Xhprof::bootstrap(
            $request ?? new CliRequestAdapter(),
            new ResponseAdapter(),
            new ConfigAdapter(),
            new RedisAdapter(),
            new LogAdapter()
        );

        // 守卫顺序不可换（十二家同序）：available() 短路在前，enable=false 时才不会把
        // 「缺扩展」那句警告吞掉。enable=false 总开关在这里就返回——深度不增，配对的
        // stop() 也自然什么都不做（不会给别的窗口减深度）。
        if (!SamplingGuard::available() || !XhprofProfiler::isEnabled()) {
            return;
        }

        self::$depth++;
        // 真正的采样门槛还在 Core 的 XhprofProfiler::start()（sample_rate 抽签、
        // ignore_url_arr / sample_cli 判定）。抽签没中时 Core 不置 $running，
        // 配对的 stop() 会是无害的 no-op —— 这里不为它做特殊分支。
        Xhprof::xhprofStart();
    }

    /**
     * 关掉一个采样窗口（幂等：没有配对 start 的 stop 是 no-op）。
     */
    public static function stop(): void
    {
        if (self::$depth === 0) {
            return;
        }
        self::$depth--;
        if (self::$depth > 0) {
            // 内层结束，外层还开着：采样继续跑（正是嵌套场景要的行为）
            return;
        }
        Xhprof::xhprofStop();
    }
}
