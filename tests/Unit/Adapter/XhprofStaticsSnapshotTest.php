<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ErikWang2013\Xhprof\Core\I18n\I18n;
use ErikWang2013\Xhprof\Core\SamplingGuard;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofLib\Display\XhprofDisplay;
use ErikWang2013\Xhprof\Core\XhprofLib\Utils\XHProfRunsDefault;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Tests\Support\XhprofStaticsSnapshot;

/**
 * 快照 trait 与全 Core 静态量的守门用例。
 *
 * 存在的理由：迁移前 10 个适配器测试类各自手抄了一份快照，其中 8 份漏了 `log_ttl`、
 * 10 份全漏了 `_hyperf` —— 少键不会立刻报错，只会在**同进程里后跑的别的类**中
 * 以假红/假绿露头（单跑哪个文件都是绿的，这正是当年漏掉的原因）。所以这里把
 * 「静态量必须登记」钉成一条本类当场可见的断言：遍历 `src/Core` 下**全部**类型，
 * 每个静态属性（含 private——它同样进程共享）要么被快照 trait 的键集覆盖
 * （`Xhprof` 的按属性名，其余按 TRAIT_KEYS_FOR 显式映射），要么写进下面的 EXEMPT
 * 表并给一行理由。新加一个静态量而不登记，这里立刻变红，而不是等某个不相关的类
 * 在某天变红。
 */
class XhprofStaticsSnapshotTest extends TestCase
{
    use XhprofStaticsSnapshot;

    /**
     * 豁免表：`类::属性` => 一行理由。
     *
     * 进表的都是「进程共享、但刻意不进快照」的：只读常量表，或每次渲染/构造都会被
     * 重写、值不因子例而异，或由专属用例自行归位（理由里点名谁是归位方）。
     */
    private const EXEMPT = [
        // XhprofDisplay：静态字面量表（src 内无写入方；I18nTest 按它的键集校验词表）
        XhprofDisplay::class . '::$descriptions' => '只读常量表：列名/单位字面量，词表 col.* 的键集与之 1:1',
        XhprofDisplay::class . '::$diff_descriptions' => '只读常量表：diff 列标题字面量，词表 diffcol.* 与之 1:1',
        XhprofDisplay::class . '::$sortable_columns' => '只读常量表：可排序列白名单',
        XhprofDisplay::class . '::$format_cbk' => '只读常量表：列格式化回调',

        // XhprofDisplay：按请求的渲染态。Hyperf 下经 set_render_state()/state() 走协程
        // Context，其余框架才落静态属性；每次渲染由 XhprofLib::init_metrics() 重写，
        // 用例按 set_render_state() 布置（不直接写静态，见 XhprofDisplayTest setUp）。
        XhprofDisplay::class . '::$sort_col' => '渲染态：本请求排序列，每次渲染重写',
        XhprofDisplay::class . '::$diff_mode' => '渲染态：本请求是否 diff 模式，每次渲染重写',
        XhprofDisplay::class . '::$display_calls' => '渲染态：是否显示调用数列，每次渲染重写',
        XhprofDisplay::class . '::$metrics' => '渲染态：本 run 的指标集，每次渲染重写',
        XhprofDisplay::class . '::$stats' => '渲染态：本请求的列清单，每次渲染重写',
        XhprofDisplay::class . '::$pc_stats' => '渲染态：去掉 I/E 列的列清单，每次渲染重写',
        XhprofDisplay::class . '::$totals' => '渲染态：本 run 合计（百分比分母），每次渲染重写',
        XhprofDisplay::class . '::$totals_1' => '渲染态：diff 报告 run1 合计，每次渲染重写',
        XhprofDisplay::class . '::$totals_2' => '渲染态：diff 报告 run2 合计，每次渲染重写',

        // XhprofDisplay：五个「每次写同一组字面量」的 CSS 片段
        XhprofDisplay::class . '::$vwbar' => '常量型：XhprofLib::init_metrics() 每次渲染写同一字面量（源码注释：故意不进协程隔离）',
        XhprofDisplay::class . '::$vbar' => '常量型：同 $vwbar',
        XhprofDisplay::class . '::$vbbar' => '常量型：同 $vwbar',
        XhprofDisplay::class . '::$vrbar' => '常量型：同 $vwbar',
        XhprofDisplay::class . '::$vgbar' => '常量型：同 $vwbar',

        // 其余 Core 类
        XHProfRunsDefault::class . '::$dir' => '存储目录：构造函数按 ini xhprof.output_dir 写入，全进程同值（上游设计）',
        I18n::class . '::$locale' => '当前语言：setLocale()/resolve() 写入；用例用后写回 I18n::FALLBACK 配平',
        I18n::class . '::$catalogs' => '词表懒加载缓存（catalogOf() 的 ??=）：值只由 lang/*.php 决定，进程内恒等',
        SamplingGuard::class . '::$warned' => '每进程一次的缺扩展告警位：需要它归零的用例自行反射重置（MissingExtensionParityTest）',
        XhprofProfiler::class . '::$running' => 'start/stop 幂等标记：XhprofProfilerTest 每例经 setProfilerRunning() 归位',
    ];

    /** 非 Xhprof 类、但由快照 trait 覆盖的静态量：`类::属性` => 快照键名（键名必须真实存在于键集） */
    private const TRAIT_KEYS_FOR = [
        XhprofProfiler::class . '::$config' => 'profilerConfig',
    ];

    #[Test]
    public function everyCoreStaticPropertyIsSnapshotOrExempt(): void
    {
        $keys = array_keys($this->snapshotXhprofStatics());

        $scanned = [];      // 实际扫到静态量的类（夹具自检：点名的五个类必须都在）
        $ids = [];          // 实际扫到的全部 `类::属性`
        $unregistered = []; // 既不在快照键集、也不在豁免表

        foreach (self::coreTypes() as $fqcn) {
            foreach ((new \ReflectionClass($fqcn))->getProperties(\ReflectionProperty::IS_STATIC) as $prop) {
                $decl = $prop->getDeclaringClass()->getName();
                $id = $decl . '::$' . $prop->getName();
                $ids[$id] = true;
                $scanned[$decl] = true;

                if ($decl === Xhprof::class && in_array($prop->getName(), $keys, true)) {
                    continue;
                }
                if (isset(self::TRAIT_KEYS_FOR[$id]) && in_array(self::TRAIT_KEYS_FOR[$id], $keys, true)) {
                    continue;
                }
                if (isset(self::EXEMPT[$id])) {
                    continue;
                }
                $unregistered[] = $id;
            }
        }

        $this->assertSame([], $unregistered, 'Core 静态量必须登记：加进 ' . XhprofStaticsSnapshot::class
            . ' 的键集（跨用例会泄漏的进程级状态），或写进本测试 EXEMPT 表并给一行理由');

        // 夹具自检：扫描面真的走到了点名的五个类（否则「全绿」只说明没扫到）
        foreach ([Xhprof::class, XhprofProfiler::class, SamplingGuard::class, I18n::class, XhprofDisplay::class] as $must) {
            $this->assertArrayHasKey($must, $scanned, '扫描面漏了类：' . $must);
        }

        // 反向自检：豁免表不许留幽灵条目（属性改名/删除后，豁免要跟着走）
        foreach (self::EXEMPT + self::TRAIT_KEYS_FOR as $id => $reason) {
            $this->assertArrayHasKey($id, $ids, '豁免表条目已不存在（属性改名或删除？）：' . $id);
        }
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

    /**
     * `src/Core` 下每个文件声明的类型 FQCN（PSR-4 路径映射）。
     *
     * 本仓库一文件一主类型（`I18n/lang/*.php` 是 `return` 数组的数据文件，不声明任何
     * 类型，跳过）。若某文件声明了类型却映射不到（放错目录、一文件两类型），直接 fail：
     * 静默跳过正是这个守卫要消灭的那类盲区。
     *
     * @return list<class-string>
     */
    private static function coreTypes(): array
    {
        $root = dirname(__DIR__, 3) . '/src/Core';
        $types = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $fqcn = 'ErikWang2013\\Xhprof\\Core\\' . str_replace(['/', '.php'], ['\\', ''], $relative);
            if (class_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn) || interface_exists($fqcn)) {
                $types[] = $fqcn;
                continue;
            }
            if (preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|trait|enum|interface)\s+\w+/m', (string) file_get_contents($file->getPathname())) === 1) {
                self::fail("src/Core/$relative 声明了类型，但按 PSR-4 路径推不出 FQCN —— 扫描面失效，守卫覆盖不到它");
            }
        }

        return $types;
    }
}
