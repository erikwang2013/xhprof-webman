<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core\Analysis;

use ErikWang2013\Xhprof\Core\I18n\I18n;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofLib\Utils\XhprofLib;

/**
 * 单次运行诊断。
 *
 * 三份入参必须来自**同一次**运行。注意 diff 模式下 profiler_report() 会改写自己的
 * 局部变量（$symbol_tab/$totals 变成 run2-run1 的增量，而 $run1_data 仍是单 run 边表），
 * 那组混合值不可直接传入——调用点必须以 !$diff_mode 守卫。
 *
 * 入参取自 XhprofDisplay::profiler_report() 里已有的局部变量，不新增数据采集、
 * 不新增存储、不碰 HTML。
 *
 * 最重要的一条约定：analyze() 不得抛异常。诊断是旁路，
 * 它出问题只会让报告页多一块内容或没有这块内容，绝不能让整页 500。
 *
 * 组装时**不要跨规则按 score 排序**：各规则的 score 量纲不同
 * （微秒 / 调用次数 / 深度），混排没有意义。跨规则顺序见主区组装规则。
 */
final class Analyzer
{
    /** R1 自身耗时占请求总耗时 */
    public const SHARE_THRESHOLD = 0.10;

    /** R2 调用次数 */
    public const CALL_COUNT_THRESHOLD = 1000;

    /** R3 单条边的调用次数 */
    public const EDGE_COUNT_THRESHOLD = 500;

    /** R3 被调方自身耗时占比 */
    public const EDGE_SHARE_THRESHOLD = 0.05;

    /** R5 内存峰值占比 */
    public const PMU_SHARE_THRESHOLD = 0.30;

    /** 主区（归因）条数上限 */
    public const MAIN_LIMIT = 3;

    /** 补充区（体检）条数上限 */
    public const SUPPLEMENT_LIMIT = 3;

    /**
     * R6 的微秒量程上限（1e15 μs ≈ 31 年）。
     *
     * 守卫是给 `(int) round()` 用的：float 超出 int 范围时该转换是 UB，实测 1e20
     * 会印出负的时间（1e19 → -8,446,744,073,709,551,616μs）。31 年比任何真实请求
     * 都长几个数量级，越过它只可能是坏数据 —— 按"不产生结论"处理，不硬转。
     */
    private const MAX_US = 1.0e15;

    /**
     * R4 的递归深度量程上限。
     *
     * `(int)` 对超长数字串会**静默饱和**成 PHP_INT_MAX（实测 `@99999999999999999999`
     * 印成"最大深度 9223372036854775807"），不报错也不溢出，只是印出一个假数字。
     * 真实的 @N 是递归层数，百万层已经远超 PHP 栈能到的量级。
     */
    private const MAX_DEPTH = 1000000;

    /**
     * @param mixed $symbol_tab xhprof_compute_flat_info() 的结果
     * @param mixed $raw_data   原始 xhprof 边表
     * @param mixed $totals     总计（wt / pmu 等）
     * @return Finding[]
     */
    public static function analyze($symbol_tab, $raw_data, $totals): array
    {
        if (!is_array($symbol_tab) || $symbol_tab === array()) {
            return array();
        }

        $totals   = is_array($totals) ? $totals : array();
        $raw_data = is_array($raw_data) ? $raw_data : array();

        // ↑ 这两行归一化**不要**因为有了 safe() 就去掉。规则签名是 `array $raw_data`，
        //   在 strict_types 下传 false 会在调用边界抛 TypeError；safe() 虽然会把它兜成
        //   空结果（所以测试看不出差别），但那等于把输入契约寄托在"异常被吞掉"上面。
        //   归一化到入口一次，规则就能放心假设入参是数组。

        // 顺序是**刻意**的：R2 排在最后，会被主区组装按 MAIN_LIMIT 从尾部截断丢掉
        // （R1 是头部归因，R3 给出可操作调用关系，R2 只是兜底信号）。不要"顺手"排成 R1/R2/R3。
        //
        // 每条规则各自过 safe()：某条规则内部出错只让它自己产出空结果，
        // 不会连累其他规则，更不会冒泡成报告页 500。
        $main = array_merge(
            self::safe(static fn(): array => self::ruleR1($symbol_tab, $totals)),
            self::safe(static fn(): array => self::ruleR3($symbol_tab, $raw_data, $totals)),
            self::safe(static fn(): array => self::ruleR2($symbol_tab))
        );
        $supplement = array_merge(
            self::safe(static fn(): array => self::ruleR4($symbol_tab, $raw_data)),
            self::safe(static fn(): array => self::ruleR5($symbol_tab, $totals)),
            self::safe(static fn(): array => self::ruleR6($symbol_tab))
        );

        // 先按 symbol 去重再切片：R3 逐边产出，同一个热点函数被多个父函数调用时
        // 会有多条同 symbol 结论；先切片的话 MAIN_LIMIT 会被一个函数占满。
        // 各规则内已按 score 降序，故每组保留第一条即该规则内最严重的那条；
        // 跨规则碰撞（同一 symbol 同时命中 R1 与 R3）时由合并顺序决定，R1 优先。
        $seen = array();
        $deduped = array();
        foreach ($main as $f) {
            if (isset($seen[$f->symbol])) {
                continue;
            }
            $seen[$f->symbol] = true;
            $deduped[] = $f;
        }

        $main       = array_slice($deduped, 0, self::MAIN_LIMIT);
        $supplement = array_slice($supplement, 0, self::SUPPLEMENT_LIMIT);

        return array_merge($main, $supplement);
    }

    /**
     * R1：自身耗时占比。
     * 不排除 main()——它的自身耗时 = 总耗时里没被采集到的部分（内置函数、C 扩展），
     * 占比高本身就是有效归因；排除它反而会给出空白或错误的结论。
     */
    private static function ruleR1(array $symbol_tab, array $totals): array
    {
        $total = self::num($totals, 'wt');
        // is_finite 必须有：is_numeric(NAN) 为真、NAN <= 0 为假，会让每个占比都成 NAN，
        // 而 NAN < 阈值 恒为假 —— 最终渲染出"占本次请求 nan%"。
        if (!is_finite($total) || $total <= 0) {
            return array();
        }

        $hits = array();
        foreach ($symbol_tab as $fn => $info) {
            if (!is_array($info)) {
                continue;
            }
            // 被除数一侧的洞与 totals 一侧相同：is_numeric(NAN) 为真、NAN < 阈值 恒假，
            // 闸门会放行并印出「自身耗时 nanms，占本次请求 nan%」。逐项走 finiteNum()。
            $excl = self::finiteNum($info['excl_wt'] ?? null);
            if ($excl === null) {
                continue;
            }
            $share = $excl / $total;
            // 除法本身也可能溢出成 INF（极小分母配极大分子），INF < 阈值同样恒假。
            if (!is_finite($share) || $share < self::SHARE_THRESHOLD) {
                continue;
            }
            $hits[] = array($excl, (string) $fn, $share);
        }
        usort($hits, static fn($a, $b) => $b[0] <=> $a[0]);

        $out = array();
        foreach ($hits as $h) {
            $out[] = new Finding(
                'R1',
                Finding::SEVERITY_MAIN,
                $h[1],
                sprintf(I18n::t('diag.r1.title'), $h[1], self::ms($h[0]), self::pct($h[2])),
                I18n::t('diag.r1.detail'),
                $h[0]
            );
        }
        return $out;
    }

    /** R2：调用次数异常 */
    private static function ruleR2(array $symbol_tab): array
    {
        $hits = array();
        foreach ($symbol_tab as $fn => $info) {
            if (!is_array($info)) {
                continue;
            }
            // ct 是印刷量（number_format），NAN 会印成「called nan times」——同上走 finiteNum()
            $ct = self::finiteNum($info['ct'] ?? null);
            if ($ct === null || $ct < self::CALL_COUNT_THRESHOLD) {
                continue;
            }
            $hits[] = array($ct, (string) $fn);
        }
        usort($hits, static fn($a, $b) => $b[0] <=> $a[0]);

        $out = array();
        foreach ($hits as $h) {
            $out[] = new Finding(
                'R2',
                Finding::SEVERITY_MAIN,
                $h[1],
                sprintf(I18n::t('diag.r2.title'), $h[1], number_format($h[0])),
                I18n::t('diag.r2.detail'),
                $h[0]
            );
        }
        return $out;
    }

    /**
     * R3：单条边重复调用。
     *
     * 只陈述"调用 N 次"这个事实，不得写成"在循环里"——
     * 1200 次同样可能来自 1200 个不同调用点，profiler 数据无法区分。
     */
    private static function ruleR3(array $symbol_tab, array $raw_data, array $totals): array
    {
        $total = self::num($totals, 'wt');
        // is_finite 必须有：is_numeric(NAN) 为真、NAN <= 0 为假，会让每个占比都成 NAN，
        // 而 NAN < 阈值 恒为假 —— 最终渲染出"占本次请求 nan%"。
        if (!is_finite($total) || $total <= 0) {
            return array();
        }

        $hits = array();
        foreach ($raw_data as $edge => $info) {
            if (!is_array($info)) {
                continue;
            }
            // 先过闸门再解析：被闸门滤掉的边就不必解析键。两个判断相互独立，无语义变化。
            //
            // 收益来自闸门**有选择性**，不是来自它"便宜"——实测闸门
            // (isset+is_numeric+(float)) 与 explode 花费相当（0.84-0.96x）。
            // 记每条边闸门 g、解析 p、被滤比例 f，比值 = (p+g) / (g + p·(1-f))：
            //   f→1（边基本都被滤掉）时上限 1 + p/g ≈ 2x
            //   f→0（边大多能过闸门）时趋近 1x，等于白重排
            // 所以不要为这条优化预算 2x 以上的收益。
            $ct = self::finiteNum($info['ct'] ?? null);
            if ($ct === null || $ct < self::EDGE_COUNT_THRESHOLD) {
                continue;
            }
            // 必须 (string)：PHP 会把 "123" 这类数组键转成 int，整型传给
            // xhprof_parse_parent_child 内部的 explode() 会抛 TypeError。
            // safe() 的粒度是整条规则，一个坏键会连带丢掉 R3 的全部有效结论。
            list($parent, $child) = XhprofLib::xhprof_parse_parent_child((string) $edge);
            if ($parent === null || $parent === '') {
                continue;   // 裸 main() 键没有父，不是边
            }
            $excl = self::finiteNum($symbol_tab[$child]['excl_wt'] ?? null);
            if ($excl === null) {
                continue;
            }
            $share = $excl / $total;
            if (!is_finite($share) || $share < self::EDGE_SHARE_THRESHOLD) {
                continue;
            }
            // wt 印进标题（ms()），NAN/INF 会成 "nanms"。这里**不兜成 0.0**：
            // 边本身照常命中闸门，但它的耗时不详，印「0.0ms」是编出来的数字。
            $wt = self::finiteNum($info['wt'] ?? null);
            if ($wt === null) {
                continue;
            }
            $hits[] = array($wt, (string) $parent, (string) $child, $ct);
        }
        usort($hits, static fn($a, $b) => $b[0] <=> $a[0]);

        $out = array();
        foreach ($hits as $h) {
            $out[] = new Finding(
                'R3',
                Finding::SEVERITY_MAIN,
                $h[2],
                sprintf(I18n::t('diag.r3.title'), $h[1], $h[2], number_format($h[3]), self::ms($h[0])),
                I18n::t('diag.r3.detail'),
                $h[0]
            );
        }
        return $out;
    }

    /**
     * R4：递归。
     * xhprof 把递归展开成 fib@1、fib@2 …，非递归调用没有 @ 后缀（如 main()==>fib）。
     * 判据是同名符号出现在 >= 2 个不同深度。
     *
     * $symbol_tab 只参与"要不要给详情页链接"这一个决定，不参与判据——判据仍然只看边表。
     */
    private static function ruleR4(array $symbol_tab, array $raw_data): array
    {
        $depths = array();
        foreach (array_keys($raw_data) as $edge) {
            foreach (explode('==>', (string) $edge) as $sym) {
                if (preg_match('/^(.+)@(\d+)$/', $sym, $m) !== 1) {
                    continue;
                }
                $depth = (int) $m[2];
                // 量程守卫：超长数字串会被 (int) 静默饱和（20 位 → PHP_INT_MAX），
                // 差值/标题里的"最大深度 9223372036854775807"就是这么来的。丢弃该 token。
                if ($depth > self::MAX_DEPTH) {
                    continue;
                }
                $depths[$m[1]][$depth] = true;
            }
        }

        $hits = array();
        foreach ($depths as $name => $ds) {
            if (count($ds) < 2) {
                continue;
            }
            $hits[] = array(max(array_keys($ds)), (string) $name);
        }
        usort($hits, static fn($a, $b) => $b[0] <=> $a[0]);

        $out = array();
        foreach ($hits as $h) {
            $out[] = new Finding(
                'R4',
                Finding::SEVERITY_SUPPLEMENT,
                // 符号只在 symbol_tab 里**真有裸名**时才给。递归在 symbol_tab 里的键是
                // fib@1/fib@2，详情页却按 symbol=fib 查——真实递归数据里 xhprof 会同时
                // 记下非递归的裸名（main()==>fib 那条边的 fib），此时该详情页正常渲染；
                // 没有裸名时才必然"未找到"，那时（且只有那时）置空让渲染层跳过链接。
                // 代价：同一 symbol 可能同时出现在主区（R1/R3）与补充区——渲染层已把
                // 这种"两条结论各说各话、指向同一详情页"写成明确允许的行为。
                isset($symbol_tab[$h[1]]) ? $h[1] : '',
                sprintf(I18n::t('diag.r4.title'), $h[1], $h[0]),
                // R4 是唯一「标题 + 说明」都在词表里的规则：这句原先硬编码中文，
                // 于是 12 个语种的报告页上都印着中文（I18nTest 的夹具没有递归数据，
                // 所以那条「英文页不许有汉字」的用例抓不到它——现在有了专门的用例）。
                I18n::t('diag.r4.detail'),
                (float) $h[0]
            );
        }
        return $out;
    }

    /** R5：内存峰值占比 */
    private static function ruleR5(array $symbol_tab, array $totals): array
    {
        $total = self::num($totals, 'pmu');
        if (!is_finite($total) || $total <= 0) {
            return array();
        }

        $hits = array();
        foreach ($symbol_tab as $fn => $info) {
            if (!is_array($info)) {
                continue;
            }
            // 同 R1：NAN 会让「峰值内存 nanB，占全局 nan%」过关
            $pmu = self::finiteNum($info['excl_pmu'] ?? null);
            if ($pmu === null) {
                continue;
            }
            $share = $pmu / $total;
            if (!is_finite($share) || $share < self::PMU_SHARE_THRESHOLD) {
                continue;
            }
            $hits[] = array($pmu, (string) $fn, $share);
        }
        usort($hits, static fn($a, $b) => $b[0] <=> $a[0]);

        $out = array();
        foreach ($hits as $h) {
            $out[] = new Finding(
                'R5',
                Finding::SEVERITY_SUPPLEMENT,
                $h[1],
                sprintf(I18n::t('diag.r5.title'), $h[1], self::bytes($h[0]), self::pct($h[2])),
                I18n::t('diag.r5.detail'),
                $h[0]
            );
        }
        return $out;
    }

    /**
     * R6：计时倒挂探针。
     *
     * 判据是**同一符号内部** excl_wt > wt（自身耗时大于总耗时，逻辑上不可能）。
     * 不要改成"与父函数比较"——一个函数被多处调用时，父的 inclusive 时间
     * 并不覆盖所有来源，会产生大量误报。
     *
     * 健康数据下本规则永远不触发，它是数据完整性探针，不是常规发现。
     */
    private static function ruleR6(array $symbol_tab): array
    {
        $hits = array();
        foreach ($symbol_tab as $fn => $info) {
            if (!is_array($info)) {
                continue;
            }
            $exF = self::finiteNum($info['excl_wt'] ?? null);
            $inF = self::finiteNum($info['wt'] ?? null);
            if ($exF === null || $inF === null) {
                continue;
            }
            // 量程守卫：`(int)` 对越界 float 是 UB，实测 1e20/1e19 会印出负的时间
            // （「自身耗时 7,766,...μs 大于其总耗时 -8,446,...μs」）。取 abs 一并挡住负向越界，
            // 使下面的减法也不可能溢出。
            if (abs($exF) > self::MAX_US || abs($inF) > self::MAX_US) {
                continue;
            }
            // 三个微秒数必须来自**同一次舍入**：各自取整会让 excl=138.4 / wt=137.6
            // 渲染成「138μs 大于 138μs，差 1μs」，与当初弃用 ms() 是同一类自相矛盾。
            // 代价是亚 0.5μs 的倒挂被四舍五入抹平——有意忽略，这不该是探针报的量级。
            $ex = (int) round($exF);
            $in = (int) round($inF);
            if ($ex <= $in) {
                continue;
            }
            $hits[] = array((float) ($ex - $in), (string) $fn, (float) $ex, (float) $in);
        }
        usort($hits, static fn($a, $b) => $b[0] <=> $a[0]);

        $out = array();
        foreach ($hits as $h) {
            $out[] = new Finding(
                'R6',
                Finding::SEVERITY_SUPPLEMENT,
                $h[1],
                // 必须用微秒而不是 ms()：R6 抓的是亚毫秒级倒挂，
                // 而 ms() 保留 1 位小数会把 210µs 与 180µs 都变成 0.2ms，
                // 标题就成了自相矛盾的「0.2ms 大于 0.2ms」。
                sprintf(
                    I18n::t('diag.r6.title'),
                    $h[1],
                    number_format($h[2]) . 'μs',
                    number_format($h[3]) . 'μs',
                    number_format($h[0]) . 'μs'
                ),
                I18n::t('diag.r6.detail'),
                $h[0]
            );
        }
        return $out;
    }

    /** 字节 → 人类可读 */
    private static function bytes(float $b): string
    {
        if ($b >= 1048576) {
            return number_format($b / 1048576, 1) . 'MB';
        }
        if ($b >= 1024) {
            return number_format($b / 1024, 1) . 'KB';
        }
        return number_format($b) . 'B';
    }

    /** 从 totals 取数值：缺失/非数值一律当 0，其余原样返回（含负数） */
    private static function num(array $totals, string $key): float
    {
        return isset($totals[$key]) && is_numeric($totals[$key]) ? (float) $totals[$key] : 0.0;
    }

    /**
     * 从逐项数据里取一个**有限**数值：缺失 / 非数值 / NAN / ±INF 一律 null（＝该指标不可用）。
     *
     * is_numeric() 挡不住这一族坏值：它对 NAN 和 '1e400' 都返回 true，而 '1e400' 转 float
     * 得 INF。更要命的是 `NAN < 阈值` 与 `INF < 阈值` **恒为假**——所有闸门都是这个形状，
     * 于是坏值不是被拦下，而是**被放行**，最后印成「自身耗时 nanms，占本次请求 nan%」。
     * 可达性：serialize() 原样存 NAN，xhprof_compute_flat_info() 又用减法算 excl_*。
     *
     * totals 一侧的同名守卫是 ruleR1/ruleR3 里的 is_finite（见那里的注释）。
     */
    private static function finiteNum($v): ?float
    {
        if (!is_numeric($v)) {
            return null;
        }
        $f = (float) $v;
        return is_finite($f) ? $f : null;
    }

    /** 微秒 → 毫秒，保留 1 位小数 */
    private static function ms(float $us): string
    {
        return number_format($us / 1000, 1) . 'ms';
    }

    /** 比率 → 百分数串（含 % 号，与 ms() 一样自带单位） */
    private static function pct(float $ratio): string
    {
        return number_format($ratio * 100, 1) . '%';
    }

    /**
     * 单条规则的异常隔离。
     *
     * 输入守卫只能覆盖预想到的数据形态；规则内部的 bug（拼错数组键、
     * 意外的数值类型等）仍会逃逸。诊断是旁路，它的失败模式不该是
     * 整个报告页 500 —— 所以让规则各自失败，坏掉的那条产出空结果，
     * 其余规则照常。
     */
    private static function safe(callable $rule): array
    {
        try {
            return $rule();
        } catch (\Throwable $e) {
            // 静默失败 = 规则坏了却永远无人知晓，报告页只会永远显示"没发现问题"。
            // 旁路不等于无信号：沿用 XhprofProfiler::stop() 对同类情况的既有写法，
            // logger 未配置时 ?-> 使其成为 no-op。
            Xhprof::getLogger()?->error(
                'Analyzer rule failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
            );
            return array();
        }
    }
}
