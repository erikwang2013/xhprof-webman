<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core\Analysis;

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

        // ↑ 这两行归一化**不要**因为有了 safe() 就去掉：foreach 遍历非数组
        //   只发 PHP warning，不是 Throwable，safe() 根本不会介入；
        //   归一化到入口一次，比让 R3/R4 各自守着更稳。

        // 每条规则各自过 safe()：某条规则内部出错只让它自己产出空结果，
        // 不会连累其他规则，更不会冒泡成报告页 500。
        return array_merge(
            self::safe(static fn(): array => self::ruleR1($symbol_tab, $totals)),
            self::safe(static fn(): array => self::ruleR3($symbol_tab, $raw_data, $totals)),
            self::safe(static fn(): array => self::ruleR2($symbol_tab))
        );
    }

    /**
     * R1：自身耗时占比。
     * 不排除 main()——它的自身耗时 = 总耗时里没被采集到的部分（内置函数、C 扩展），
     * 占比高本身就是有效归因；排除它反而会给出空白或错误的结论。
     */
    private static function ruleR1(array $symbol_tab, array $totals): array
    {
        $total = self::num($totals, 'wt');
        if ($total <= 0) {
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
                sprintf('%s 自身耗时 %s，占本次请求 %s%%', $h[1], self::ms($h[0]), self::pct($h[2])),
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
        if ($total <= 0) {
            return array();
        }

        $hits = array();
        foreach ($raw_data as $edge => $info) {
            if (!is_array($info)) {
                continue;
            }
            list($parent, $child) = XhprofLib::xhprof_parse_parent_child($edge);
            if ($parent === null || $parent === '') {
                continue;   // 裸 main() 键没有父，不是边
            }
            $ct = isset($info['ct']) && is_numeric($info['ct']) ? (float) $info['ct'] : 0.0;
            if ($ct < self::EDGE_COUNT_THRESHOLD) {
                continue;
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

    /** 从 totals 取一个非负数值，缺失/非数值一律当 0 */
    private static function num(array $totals, string $key): float
    {
        return isset($totals[$key]) && is_numeric($totals[$key]) ? (float) $totals[$key] : 0.0;
    }

    /** 微秒 → 毫秒，保留 1 位小数 */
    private static function ms(float $us): string
    {
        return number_format($us / 1000, 1) . 'ms';
    }

    /** 比率 → 百分数，保留 1 位小数 */
    private static function pct(float $ratio): string
    {
        return number_format($ratio * 100, 1);
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
            return array();
        }
    }
}
