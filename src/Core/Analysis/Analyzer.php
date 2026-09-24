<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core\Analysis;

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

        return array();
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
