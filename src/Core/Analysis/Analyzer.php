<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core\Analysis;

use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofLib\Utils\XhprofLib;

/**
 * 单次运行诊断。
 *
 * 入参都是 XhprofDisplay::profiler_report() 里已有的局部变量，
 * 不新增数据采集、不新增存储、不碰 HTML。
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
            self::safe(static fn(): array => self::ruleR4($raw_data)),
            self::safe(static fn(): array => self::ruleR5($symbol_tab, $totals)),
            self::safe(static fn(): array => self::ruleR6($symbol_tab))
        );

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
            if (!is_array($info) || !isset($info['excl_wt']) || !is_numeric($info['excl_wt'])) {
                continue;
            }
            $excl  = (float) $info['excl_wt'];
            $share = $excl / $total;
            if ($share < self::SHARE_THRESHOLD) {
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
                sprintf('%s 自身耗时 %s，占本次请求 %s', $h[1], self::ms($h[0]), self::pct($h[2])),
                '自身耗时不含子调用，是纯函数体开销',
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
            if (!is_array($info) || !isset($info['ct']) || !is_numeric($info['ct'])) {
                continue;
            }
            $ct = (float) $info['ct'];
            if ($ct < self::CALL_COUNT_THRESHOLD) {
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
                sprintf('%s 被调用 %s 次', $h[1], number_format($h[0])),
                '调用次数偏高，值得确认是否符合预期',
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
            $ct = isset($info['ct']) && is_numeric($info['ct']) ? (float) $info['ct'] : 0.0;
            if ($ct < self::EDGE_COUNT_THRESHOLD) {
                continue;
            }
            // 必须 (string)：PHP 会把 "123" 这类数组键转成 int，整型传给
            // xhprof_parse_parent_child 内部的 explode() 会抛 TypeError。
            // safe() 的粒度是整条规则，一个坏键会连带丢掉 R3 的全部有效结论。
            list($parent, $child) = XhprofLib::xhprof_parse_parent_child((string) $edge);
            if ($parent === null || $parent === '') {
                continue;   // 裸 main() 键没有父，不是边
            }
            if (!isset($symbol_tab[$child]['excl_wt']) || !is_numeric($symbol_tab[$child]['excl_wt'])) {
                continue;
            }
            $excl = (float) $symbol_tab[$child]['excl_wt'];
            if (($excl / $total) < self::EDGE_SHARE_THRESHOLD) {
                continue;
            }
            $wt = isset($info['wt']) && is_numeric($info['wt']) ? (float) $info['wt'] : 0.0;
            $hits[] = array($wt, (string) $parent, (string) $child, $ct);
        }
        usort($hits, static fn($a, $b) => $b[0] <=> $a[0]);

        $out = array();
        foreach ($hits as $h) {
            $out[] = new Finding(
                'R3',
                Finding::SEVERITY_MAIN,
                $h[2],
                sprintf('%s → %s 调用 %s 次，累计 %s', $h[1], $h[2], number_format($h[3]), self::ms($h[0])),
                '该调用关系占据了可观耗时',
                $h[0]
            );
        }
        return $out;
    }

    /**
     * R4：递归。
     * xhprof 把递归展开成 fib@1、fib@2 …，非递归调用没有 @ 后缀（如 main()==>fib）。
     * 判据是同名符号出现在 >= 2 个不同深度。
     */
    private static function ruleR4(array $raw_data): array
    {
        $depths = array();
        foreach (array_keys($raw_data) as $edge) {
            foreach (explode('==>', (string) $edge) as $sym) {
                if (preg_match('/^(.+)@(\d+)$/', $sym, $m) !== 1) {
                    continue;
                }
                $depths[$m[1]][(int) $m[2]] = true;
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
                // 符号置空：递归在 symbol_tab 里的键是 fib@1/fib@2，而详情页按 symbol=fib 查，
                // 必然"未找到"。spec 允许 symbol 为空（渲染层会跳过链接），这里就该为空。
                '',
                sprintf('检测到 %s() 递归，最大深度 %d', $h[1], $h[0]),
                '递归深度过大可能导致栈溢出或耗时呈指数增长',
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
            if (!is_array($info) || !isset($info['excl_pmu']) || !is_numeric($info['excl_pmu'])) {
                continue;
            }
            $pmu   = (float) $info['excl_pmu'];
            $share = $pmu / $total;
            if ($share < self::PMU_SHARE_THRESHOLD) {
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
                sprintf('%s 内存峰值 %s，占全局 %s', $h[1], self::bytes($h[0]), self::pct($h[2])),
                '峰值内存集中在单个函数，可优先核查其数据结构',
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
            if (!is_array($info) || !isset($info['excl_wt'], $info['wt'])) {
                continue;
            }
            if (!is_numeric($info['excl_wt']) || !is_numeric($info['wt'])) {
                continue;
            }
            $excl = (float) $info['excl_wt'];
            $incl = (float) $info['wt'];
            if ($excl <= $incl) {
                continue;
            }
            $hits[] = array($excl - $incl, (string) $fn, $excl, $incl);
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
                    '%s 自身耗时 %s 大于其总耗时 %s，差 %s',
                    $h[1],
                    number_format($h[2]) . 'μs',
                    number_format($h[3]) . 'μs',
                    number_format($h[0]) . 'μs'
                ),
                '自身耗时不应大于总耗时，采样数据或统计计算可能异常',
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
