<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Laravel;

use ErikWang2013\Xhprof\Core\Contract\RequestInterface;

/**
 * 队列采样监听器：**按消息**开停采样窗口（不是按 worker 进程）。
 *
 * 注册（放进应用的 AppServiceProvider::boot()，四行；README「CLI 与队列」一节同款）：
 *
 *     use Illuminate\Queue\Events\{JobProcessing, JobProcessed, JobFailed, JobExceptionOccurred};
 *     use ErikWang2013\Xhprof\Laravel\XhprofQueueListener;
 *
 *     Event::listen(JobProcessing::class, [XhprofQueueListener::class, 'onJobProcessing']);
 *     Event::listen(JobProcessed::class, [XhprofQueueListener::class, 'onJobProcessed']);
 *     Event::listen(JobFailed::class, [XhprofQueueListener::class, 'onJobFailed']);
 *     Event::listen(JobExceptionOccurred::class, [XhprofQueueListener::class, 'onJobExceptionOccurred']);
 *
 * 为什么逐个列事件而不是实现一个 subscribe 表：事件类名写在这里就要在包的源码里
 * 引用 Illuminate\Queue\Events\* 的类，而本仓的 phpstan 只认 tests/Stubs 里那份桩
 * （未声明的类 = class.notFound，不可忽略）。注册写在用户侧反而更稳：Laravel 换事件
 * 类名时用户的 boot() 会当场报错，而不是这里静默失效。
 *
 * 方法**不带参数**：事件对象（JobProcessing 等）只是被 PHP 允许的多余实参
 * （`$listener($event)` 多传的实参在用户态方法里被忽略），所以我们不需要引用它们的
 * 类型 —— 本监听器对事件内容没有任何兴趣，只看「来没来」。
 *
 * 四个事件的配对（对照 Laravel 12/13 的 Worker::process()，两版同形）：
 *   - JobProcessing          → start：任务开始执行前
 *   - JobProcessed           → stop ：正常跑完
 *   - JobExceptionOccurred   → stop ：任务抛异常（含随后 release 重试的路径，
 *                                      Worker::handleJobException 里在 release 之前就派发）
 *   - JobFailed              → stop ：单靠上面三个不够 —— 「上次已超时超限」的任务是
 *                                      先派 JobProcessing、**在 fire() 之前**就判定失败
 *                                      （markJobAsFailedIfAlreadyExceedsMaxAttempts），
 *                                      只有 JobFailed 会到；漏掉它窗口就要泄漏到下一个任务。
 * 重复的 stop 无害：XhprofCli::stop() 与 Core 的 XhprofProfiler::stop() 都是幂等的，
 * 失败路径上 JobFailed 与 JobExceptionOccurred 常常先后都到（例如超过最大重试次数）。
 *
 * 常驻 worker 里**不能**把止点挂到进程 shutdown：`queue:work` 的进程可能几天不退，
 * 采样状态会跨任务漂移（Symfony 入口那条 shutdown 守卫解决的正是同一个问题，
 * 见 src/Symfony/XhprofListener.php:141-158）。
 */
class XhprofQueueListener
{
    /** 传给 XhprofCli::start() 的请求适配器；null = 用默认的 CliRequestAdapter */
    private ?RequestInterface $request;

    public function __construct(?RequestInterface $request = null)
    {
        $this->request = $request;
    }

    public function onJobProcessing(): void
    {
        XhprofCli::start($this->request);
    }

    public function onJobProcessed(): void
    {
        XhprofCli::stop();
    }

    public function onJobFailed(): void
    {
        XhprofCli::stop();
    }

    public function onJobExceptionOccurred(): void
    {
        XhprofCli::stop();
    }
}
