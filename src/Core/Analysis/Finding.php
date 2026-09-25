<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core\Analysis;

/**
 * 一条诊断结论。
 *
 * $title / $detail 是**纯文本**，不含 HTML——转义由渲染层统一负责，
 * 这样 Analyzer 的单测可以直接断言可读文本，转义也只需要在一处做对。
 *
 * $symbol 是**原始函数名**，仅用作方法详情页链接的参数值（渲染层经 http_build_query
 * 做 URL 编码），不要当文本渲染。
 *
 * $score 只在**同一条规则内**用于排序。不同规则的量纲（微秒 / 次数）不可比，
 * 不要跨规则比较。
 */
final class Finding
{
    /** 归因结论：回答"这次为什么慢" */
    public const SEVERITY_MAIN = 'main';

    /** 体检发现：写法/结构上的问题 */
    public const SEVERITY_SUPPLEMENT = 'supplement';

    public function __construct(
        public string $rule,
        public string $severity,
        public string $symbol,
        public string $title,
        public string $detail,
        public float $score,
    ) {
    }
}
