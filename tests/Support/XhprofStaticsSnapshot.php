<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Support;

use ErikWang2013\Xhprof\Core\Xhprof;

/**
 * 进程级静态量的**快照 / 还原**：setUp 里先照单全收，tearDown 里原样放回。
 *
 * 为什么必须有：`Xhprof` 的这 12 个量是整个进程共享的，而各测试类都会改其中几个。
 * 只"清空自己动过的那几个"是不够的 —— 曾经的做法是 tearDown 里把 5 个适配器置 null，
 * 于是 setUp 改过的 `$ignore_url_arr` / `$ui_html` 等会原样漏给后面的用例：
 * 同一个进程里 Core 先跑、Adapter 后跑时，`DrupalTest` / `WebmanTest` 的前置条件断言
 * 就会读到别人留下的值而报假红（实测：`Core+Lib+Adapter` 顺序下 3 条红，
 * 单跑各文件全绿）。
 *
 * `_hyperf` 必须在名单里，而且只有反射能写（私有、无 setter、生产上刻意不可逆）：
 * 一旦被某个用例经 autoDetect / `markHyperfContext()` 置 true，Core 的 getter 就改读
 * 协程 Context，于是"直接写静态属性"的用例（以及后续所有类）全部错读 —— 这是同一处
 * 泄漏在三个测试类里被先后踩到的原因。**"两种模式都能跑"的写法不等于可以不还原**：
 * 自己能在两种模式下正确，和把进程留在另一种模式下，是两件事。
 *
 * 反射写法：静态属性的单参 `setValue($v)` 在 PHP 8.3 起已废弃，用双参 `setValue(null, $v)`；
 * `setAccessible(true)` 是给 PHP 8.0 的（8.1+ 是 no-op）。
 */
trait XhprofStaticsSnapshot
{
    /** @return array<string, mixed> */
    protected function snapshotXhprofStatics(): array
    {
        $hyperf = new \ReflectionProperty(Xhprof::class, '_hyperf');
        $hyperf->setAccessible(true);

        return [
            '_hyperf' => $hyperf->getValue(),
            'request' => Xhprof::$request,
            'response' => Xhprof::$response,
            'config' => Xhprof::$config,
            'cache' => Xhprof::$cache,
            'logger' => Xhprof::$logger,
            'time_limit' => Xhprof::$time_limit,
            'ignore_url_arr' => Xhprof::$ignore_url_arr,
            'log_num' => Xhprof::$log_num,
            'view_wtred' => Xhprof::$view_wtred,
            'key_prefix' => Xhprof::$key_prefix,
            'ui_html' => Xhprof::$ui_html,
            'symbol_lookup_url' => Xhprof::$symbol_lookup_url,
        ];
    }

    /** @param array<string, mixed> $s */
    protected function restoreXhprofStatics(array $s): void
    {
        $hyperf = new \ReflectionProperty(Xhprof::class, '_hyperf');
        $hyperf->setAccessible(true);
        $hyperf->setValue(null, $s['_hyperf']);

        Xhprof::$request = $s['request'];
        Xhprof::$response = $s['response'];
        Xhprof::$config = $s['config'];
        Xhprof::$cache = $s['cache'];
        Xhprof::$logger = $s['logger'];
        Xhprof::$time_limit = $s['time_limit'];
        Xhprof::$ignore_url_arr = $s['ignore_url_arr'];
        Xhprof::$log_num = $s['log_num'];
        Xhprof::$view_wtred = $s['view_wtred'];
        Xhprof::$key_prefix = $s['key_prefix'];
        Xhprof::$ui_html = $s['ui_html'];
        Xhprof::$symbol_lookup_url = $s['symbol_lookup_url'];
    }
}
