<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Tests\Support\XhprofStaticsSnapshot;

/**
 * 快照 trait 自身的守门用例。
 *
 * 存在的理由：迁移前 10 个适配器测试类各自手抄了一份快照，其中 8 份漏了 `log_ttl`、
 * 10 份全漏了 `_hyperf` —— 少键不会立刻报错，只会在**同进程里后跑的别的类**中
 * 以假红/假绿露头（单跑哪个文件都是绿的，这正是当年漏掉的原因）。所以这里把
 * 「快照必须覆盖 `Xhprof` 的每个静态量」钉成一条本类当场可见的断言：
 * 从 trait 里删掉任何一个键，这里立刻变红，而不是等某个不相关的类在某天变红。
 */
class XhprofStaticsSnapshotTest extends TestCase
{
    use XhprofStaticsSnapshot;

    #[Test]
    public function snapshotCoversEveryStaticPropertyOfXhprof(): void
    {
        $keys = array_keys($this->snapshotXhprofStatics());

        $props = (new \ReflectionClass(Xhprof::class))->getProperties(\ReflectionProperty::IS_STATIC);
        $this->assertNotEmpty($props, '夹具失效：Xhprof 一个静态量都没有？');

        foreach ($props as $prop) {
            $this->assertContains(
                $prop->getName(),
                $keys,
                '静态量漏出快照，会跨用例泄漏：' . $prop->getName()
            );
        }

        // 私有静态、只能反射写：isEnabled() 的第二数据源
        $this->assertContains('profilerConfig', $keys);
    }

    #[Test]
    public function restorePutsBackLogTtlWhichBootstrapOverwrites(): void
    {
        $saved = $this->snapshotXhprofStatics();

        try {
            // 哨兵值取「进入本用例时的值 + 1」，故意不写字面量：写默认值等于断言环境，
            // 而这个断言要证明的是**还原**，与环境里恰好是多少无关。
            $sentinel = ((int) Xhprof::$log_ttl) + 1;
            Xhprof::$log_ttl = $sentinel;

            $this->restoreXhprofStatics($saved);

            $this->assertNotSame($sentinel, Xhprof::$log_ttl, 'trait 快照必须覆盖 log_ttl（bootstrap 会写它）');
            $this->assertSame($saved['log_ttl'], Xhprof::$log_ttl);
        } finally {
            $this->restoreXhprofStatics($saved);
        }
    }
}
