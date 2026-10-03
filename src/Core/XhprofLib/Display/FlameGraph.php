<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core\XhprofLib\Display;

use ErikWang2013\Xhprof\Core\XhprofLib\Utils\XhprofLib;

/**
 * 服务端渲染的 SVG 火焰图：纯 PHP 拼内联 `<svg>`，零 JS、零系统依赖、零新 composer 依赖。
 *
 * 历史教训：被删掉的 Utils/CallGraph.php 用 `proc_open('dot')` 画图、还直接发 `header()`，
 * 于是"看报告"依赖宿主装了 Graphviz、渲染期还有裸 header。本类只**返回字符串**：
 * 不碰 HTTP、不落盘、不 exec。
 *
 * ── 输入 ──────────────────────────────────────────────────────────
 * xhprof 边表，与 XHProfRunsDefault::get_run() 返回同形：
 *   'main()'         => ['ct'=>…, 'wt'=>…, …]   // 无父键：主调的总量
 *   'main()==>foo()' => ['ct'=>…, 'wt'=>…, …]   // 父==>子 边
 * 解析一律走 XhprofLib::xhprof_parse_parent_child()，与统计层同源。
 *
 * ── 建树 ──────────────────────────────────────────────────────────
 * xhprof 把递归展开成 fib@1 / fib@2（同名不同深度的符号不同），所以"符号 = 节点"
 * 天然无环；仍防御性丢弃两类坏边（它们是数据异常，不是剪枝）：
 *   a==>a 自环；孩子的符号已在当前祖先链上（成环）。
 * 丢掉的权重计入 pruned_value，不静默吞（静默吞 = 火焰图撒谎）。
 * 数组键本身唯一，故"重复边"只可能以上面两种形态出现。
 * 根恒为 main()（与 xhprof 官方火焰图一致）；无父键里除 main() 外的符号
 * （正常数据不该有）当顶层调用挂根下，与 xhprof_compute_inclusive_times()
 * 把无父键计入该符号的口径一致。
 *
 * ── 宽度与剪枝 ────────────────────────────────────────────────────
 * 帧宽 ∝ 该边的指标值（inclusive，即子树总权重）；深度 = 调用深度，根在最下。
 * 帧预算 maxFrames 个（含根）：从"已保留节点的子边"里按值最大者优先取出
 * （SplPriorityQueue），取满即止。父的值恒 ≥ 子的值，故按值贪心等价于
 * "保留值最大的 N 棵子树"，且结果里每个帧的父都在（画得出来）。
 * 剪枝口径：**不进图的最上层子树价值之和**——预算耗尽时仍留在队列里的值
 * + 坏边丢弃值；子子帧不重复计。pruned_pct = 该和 / 根值。
 *
 * ── 输出 ──────────────────────────────────────────────────────────
 * 每帧 = `<title>`(名字) + `<rect>` + 可选 `<text>`；给了链接回调时容器是
 * `<a href>`，否则是 `<g>`。超窄帧不渲染文本（按与 mbstring 无关的列宽估算，
 * 见 textColumns()），防文本溢出帧外。所有文本节点与 `<title>` 里的函数名都
 * htmlspecialchars(ENT_QUOTES, 'UTF-8')。
 * 帧链接 HREF **原样**进 href 属性——与 XhprofDisplay 里其它内部链接同一契约：
 * report_url() 的返回值本来就是属性安全的（查询串已转义），这里再转义一次
 * 会把 `&` 变成 `&amp;amp;`，带 token/lang 的链接全断。回调必须遵守同一契约。
 * 颜色用报告页现有的 CSS 变量（--xp-orange / --xp-accent / --xp-text-muted，
 * 均带字面量兜底，脱离页面单独打开也不会黑掉），按 值/根值 分三档。
 *
 * ── 无 i18n 成本 ──────────────────────────────────────────────────
 * 本类不产任何文案：只有数字、颜色、结构与数据里的函数名。卡片标题 / 图例 /
 * 剪枝说明由 Display 侧用词表键渲染（所需键清单见接线说明；分档阈值已作为
 * 公开常量 SHARE_HOT / SHARE_WARM 导出，图例文案不必抄第二份数字）。
 *
 * ── Hyperf 协程 ───────────────────────────────────────────────────
 * 全部状态都在方法局部变量里，没有静态属性、没有 render-state 写入，
 * 两个协程并发调用互不影响（与 XhprofDisplay 的 set_render_state 那套无关）。
 * 图例要用的统计量由 renderWithStats() **随返回值**给，不落进程级状态。
 */
final class FlameGraph
{
    /** 默认帧预算（含根帧）。Display 只要引用这个常量，别抄数字。 */
    public const DEFAULT_MAX_FRAMES = 60;

    /** 分档：帧值 / 根值 ≥ 此值 → 最热的颜色（--xp-orange）。图例文案可直接引它。 */
    public const SHARE_HOT = 0.30;

    /** 分档：≥ 此值 → 次热（--xp-accent）；再低是最冷档（--xp-text-muted）。 */
    public const SHARE_WARM = 0.10;

    /** viewBox 名义宽度。SVG 按容器宽度整体缩放，这里只是坐标系刻度。 */
    private const VIEW_W = 1200.0;

    /** 每层高度（viewBox 单位）。 */
    private const ROW_H = 20.0;

    /** 帧矩形高度（比 ROW_H 少 2，留行距）。 */
    private const RECT_H = 18.0;

    /** 帧内文本字号。 */
    private const FONT_SIZE = 11.0;

    /**
     * 文本列宽估价：每个"列"（ASCII 记 1 列、全角记 2 列）约几像素。
     * 宁大勿小：估小了文本会溢出帧外（SVG 不裁剪），估大了只是个别帧少一行标签。
     * 仍是估算——一串全宽字母（W/M）会略微超；这是刻意取舍，见类头。
     */
    private const COL_W = 6.5;

    /** 文本左右留白合计（左 4 + 右 4）。 */
    private const TEXT_PAD = 8.0;

    /** 文本距帧左边缘。 */
    private const TEXT_X = 4.0;

    /** 帧间留白：矩形宽度减 1，露出底色当分隔线。 */
    private const GAP = 1.0;

    /**
     * 渲染火焰图，返回内联 `<svg>` 字符串。
     *
     * 数据不可渲染（空数组 / 没有 main() / 根值为 0，例如该指标本次未采集）时返回空串——
     * 调用方据此决定整张卡片要不要出。
     *
     * @param array         $runData    xhprof 边表（get_run() 同形）
     * @param string        $metric     宽度依据的指标键：wt / cpu / mu / pmu / ct / samples，缺键按 0
     * @param int           $maxFrames  帧预算（含根帧），<1 时按 1 收
     * @param callable|null $frameUrl   帧链接回调 `fn(string $symbol): ?string`，返回**属性安全**的
     *                                  URL（report_url() 的契约），返回 '' 或 null 表示该帧不带链接；
     *                                  传 null 则所有帧都不带链接
     */
    public static function render(
        array $runData,
        string $metric = 'wt',
        int $maxFrames = self::DEFAULT_MAX_FRAMES,
        ?callable $frameUrl = null
    ): string {
        return self::renderWithStats($runData, $metric, $maxFrames, $frameUrl)['svg'];
    }

    /**
     * 同 render()，另外随返回值给出图例要用的统计量——不进任何进程级状态
     * （Hyperf 常驻 worker 下静态属性会串协程，见类头）。
     *
     * @return array{svg: string, frames: int, max_frames: int, max_depth: int,
     *               total_value: float, pruned_value: float, pruned_pct: float}
     *   frames  实际画出的帧数（含根）；max_depth 最深帧深度（根 = 0）；
     *   total_value 根帧值（分档与占比的分母）；pruned_value / pruned_pct 见类头剪枝口径
     */
    public static function renderWithStats(
        array $runData,
        string $metric = 'wt',
        int $maxFrames = self::DEFAULT_MAX_FRAMES,
        ?callable $frameUrl = null
    ): array {
        $maxFrames = max(1, $maxFrames);
        $empty = array(
            'svg' => '',
            'frames' => 0,
            'max_frames' => $maxFrames,
            'max_depth' => 0,
            'total_value' => 0.0,
            'pruned_value' => 0.0,
            'pruned_pct' => 0.0,
        );
        if ($runData === array()) {
            return $empty;
        }

        // 一趟扫出：按父分组的边表 + 根值候选 + 根是否在场
        $childrenOf = array();
        $mainSeen = false;
        $droppedValue = 0.0; // 自环/成环丢掉的权重（不是剪枝）
        foreach ($runData as $key => $info) {
            if (!is_string($key) || !is_array($info)) {
                continue; // 坏行：形状不对的一律当没有，不抛
            }
            [$parent, $child] = XhprofLib::xhprof_parse_parent_child($key);
            if ($child === null || $child === '') {
                continue;
            }
            $value = self::metricValue($info, $metric);
            if ($parent === null) {
                // 无父键。'main()' 是主调**本身**，它的总量由下面单独取，不当边；
                // 其余符号（正常数据不该有）当顶层调用挂根下——与
                // xhprof_compute_inclusive_times() 把无父键计入该符号的口径一致。
                // 注意不能把 'main()' 归一成边：曾实测变成根的子帧 main()==>main()，
                // 整棵树多出一层、pruned_value 也被污染。
                if ($child === 'main()') {
                    $mainSeen = true;
                } else {
                    // 这条路径**不算** main() 在场：只有无父的其它符号而没有 main()
                    // 的数据按"无 main()"返回空串（root 只是挂靠点，不是数据里的符号）
                    $childrenOf['main()'][] = array('fn' => $child, 'value' => $value);
                }
                continue;
            }
            if ($parent === $child) {
                // 自环（a==>a）：xhprof_compute_inclusive_times() 对这种数据是报错返回，
                // 这里丢边保图——但权重记进"未显示"，不当它不存在
                $droppedValue += $value;
                continue;
            }
            if ($parent === 'main()') {
                $mainSeen = true;
            }
            $childrenOf[$parent][] = array('fn' => $child, 'value' => $value);
        }
        if (!$mainSeen) {
            return $empty;
        }

        // 根值：优先 main() 那条无父键的指标值；没有（或非正）就用子边之和兜底。
        // 正常数据里根值 ≥ 子边之和，差额是 main() 自身耗时，图上表现为根帧未被盖满的尾巴。
        $rootValue = 0.0;
        if (isset($runData['main()']) && is_array($runData['main()'])) {
            $rootValue = self::metricValue($runData['main()'], $metric);
        }
        if ($rootValue <= 0.0) {
            foreach ($childrenOf['main()'] ?? array() as $edge) {
                $rootValue += $edge['value'];
            }
        }
        if ($rootValue <= 0.0) {
            return $empty; // 该指标本次没采集：一片零宽的图没有意义
        }

        $kept = array();   // id => ['fn','value','depth','children'(id 集)]
        $kept[0] = array('fn' => 'main()', 'value' => $rootValue, 'depth' => 0, 'children' => array());
        $pushedValue = 0.0; // 入队过的值之和，见下面剪枝口径的记账
        $poppedValue = 0.0;
        $heap = new \SplPriorityQueue();
        foreach ($childrenOf['main()'] ?? array() as $edge) {
            $pushedValue += self::push($heap, $edge, 1, 0, array('main()' => true));
        }
        // 贪心取最大子树：父的值恒 ≥ 子的值，故先被取走的必是祖先，取满预算即剪枝
        while (!$heap->isEmpty() && count($kept) < $maxFrames) {
            $item = $heap->extract();
            $poppedValue += $item['value'];
            $id = count($kept);
            $kept[$id] = array('fn' => $item['fn'], 'value' => $item['value'], 'depth' => $item['depth'], 'children' => array());
            $kept[$item['parent']]['children'][] = $id;
            $anc = $item['anc'] + array($item['fn'] => true);
            foreach ($childrenOf[$item['fn']] ?? array() as $edge) {
                if (isset($anc[$edge['fn']])) {
                    $droppedValue += $edge['value']; // 成环/自环：祖先链上已有该符号
                    continue;
                }
                $pushedValue += self::push($heap, $edge, $item['depth'] + 1, $id, $anc);
            }
        }
        // 未显示 = 没被取走的（仍在队列里，即"最上层被剪子树"，子孙不重复计）+ 丢掉的坏边。
        // 还在队列里的值不用真去 drain（那是 O(n log n) 的整堆弹出）：每个入队项只有
        // "被取走" 或 "留在队列里" 两种命运，故队列残留 = 入队总和 − 出队总和。
        $prunedValue = ($pushedValue - $poppedValue) + $droppedValue;

        // 布局：子按值降序（平手按符号升序，保证同数据同输出）依次排开；
        // 子和 > 父宽时整体缩放——正常数据不会发生，坏数据不缩会叠到隔壁子树上去
        $x = array();
        $maxDepth = 0;
        self::layout($kept, 0, 0.0, self::VIEW_W, self::VIEW_W / $rootValue, $x, $maxDepth);

        $drawn = 0;
        $svg = self::emit($kept, $x, $maxDepth, $rootValue, $frameUrl, $drawn);

        return array(
            'svg' => $svg,
            'frames' => $drawn,
            'max_frames' => $maxFrames,
            'max_depth' => $maxDepth,
            'total_value' => $rootValue,
            'pruned_value' => $prunedValue,
            'pruned_pct' => $rootValue > 0.0 ? $prunedValue / $rootValue : 0.0,
        );
    }

    /**
     * 指标的防御性取值：非数字按 0；负值按 0（负宽矩形是无效 SVG；diff 增量不在本类输入范围内）；
     * INF/NaN（如 is_numeric 通过但上溢成 INF 的串）按 0——坐标里写出 "INF" 是一张废图。
     */
    private static function metricValue(array $info, string $metric): float
    {
        $v = $info[$metric] ?? null;
        if (!is_numeric($v)) {
            return 0.0;
        }
        $v = (float) $v;
        return is_finite($v) ? max(0.0, $v) : 0.0;
    }

    /**
     * 候选帧入队，返回实际入队的值（没入队则 0.0，供剪枝记账）。
     * 零值边不入队：宽度为 0 画不出东西，白占帧预算。祖先集随节点走，用于成环防御。
     * 优先级用 float 而不是 [值, 序号] 数组：实测数组比较的堆插入贵约 5 倍，
     * 而"同值先来先取"只是好看——同值帧的图上顺序由 layout() 的符号升序决定，
     * 与出队顺序无关。同值时的出队先后由 SPL 堆自己决定，同一进程、同一输入下确定。
     */
    private static function push(\SplPriorityQueue $heap, array $edge, int $depth, int $parent, array $anc): float
    {
        if ($edge['value'] <= 0.0) {
            return 0.0;
        }
        $heap->insert(
            array('fn' => $edge['fn'], 'value' => $edge['value'], 'depth' => $depth, 'parent' => $parent, 'anc' => $anc),
            $edge['value']
        );
        return $edge['value'];
    }

    /**
     * 递归布局（深度 ≤ maxFrames，无栈风险）：$out[$id] = ['x' => 左缘, 'w' => 宽, 'depth' => 层]。
     * 宽度一律 = 值 × $unit（$unit = 画布宽 / 根值，全程恒定）——比例只对根算一次，
     * 子树里不做逐层归一化；于是父帧矩形始终保持全宽、子帧铺在上面，
     * 没被任何子帧盖住的那段就是父的自身耗时。
     */
    private static function layout(array $kept, int $id, float $x, float $w, float $unit, array &$out, int &$maxDepth): void
    {
        $out[$id] = array('x' => $x, 'w' => $w, 'depth' => $kept[$id]['depth']);
        if ($kept[$id]['depth'] > $maxDepth) {
            $maxDepth = $kept[$id]['depth'];
        }
        $kids = $kept[$id]['children'];
        if ($kids === array()) {
            return;
        }
        usort($kids, static function (int $a, int $b) use ($kept): int {
            // 值降序，平手按符号升序：同输入必得同 SVG（测试与缓存都依赖这一点）
            return ($kept[$b]['value'] <=> $kept[$a]['value'])
                ?: strcmp($kept[$a]['fn'], $kept[$b]['fn']);
        });
        $sum = 0.0;
        foreach ($kids as $k) {
            $sum += $kept[$k]['value'] * $unit;
        }
        // 子帧自然宽之和超过父宽才缩；正常数据（子和 ≤ 父值）不缩，父尾部的自身耗时留着
        $scale = $sum > $w ? $w / $sum : 1.0;
        $cursor = $x;
        foreach ($kids as $k) {
            $kw = $kept[$k]['value'] * $unit * $scale;
            self::layout($kept, $k, $cursor, $kw, $unit, $out, $maxDepth);
            $cursor += $kw;
        }
    }

    /** 逐帧拼 SVG（顺序按 id，祖先恒先于后代，输出确定）；$drawn 回填真正画出的帧数。 */
    private static function emit(array $kept, array $x, int $maxDepth, float $rootValue, ?callable $frameUrl, int &$drawn): string
    {
        $drawn = 0;
        $height = ($maxDepth + 1) * self::ROW_H;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '
            . self::num(self::VIEW_W) . ' ' . self::num($height) . '" width="100%" style="display:block;height:auto">';
        foreach ($x as $id => $box) {
            $node = $kept[$id];
            $name = htmlspecialchars($node['fn'], ENT_QUOTES, 'UTF-8');
            $rectW = max(0.0, $box['w'] - self::GAP);
            if ($rectW <= 0.0) {
                continue; // 亚像素帧（值太小）画不出可见矩形，也就不叫"画出了"一帧
            }
            $drawn++;
            $y = $height - ($box['depth'] + 1) * self::ROW_H;
            $href = $frameUrl !== null ? $frameUrl($node['fn']) : null;
            $linked = is_string($href) && $href !== '';
            // <g>/<a> 容器是必须的：<title> 直接挂 <svg> 下会变成整张图的标题
            $svg .= $linked ? '<a href="' . $href . '">' : '<g>';
            $svg .= '<title>' . $name . '</title>';
            $svg .= '<rect x="' . self::num($box['x']) . '" y="' . self::num($y)
                . '" width="' . self::num($rectW) . '" height="' . self::num(self::RECT_H) . '"'
                . ' fill="' . self::fillColor($node['value'] / $rootValue) . '"'
                . ' stroke="var(--xp-surface, #ffffff)" stroke-width="1"/>';
            if (self::fits($node['fn'], $rectW)) {
                // 所有分档底色都是深色，文字固定白（对比度均 ≥ 4.5:1）；基线与矩形的
                // 垂直居中用算式而不是 dominant-baseline（后者老 Safari 支持不齐）：
                // 中心 + 0.35 字号 ≈ 该字的基线
                $svg .= '<text x="' . self::num($box['x'] + self::TEXT_X) . '" y="'
                    . self::num($y + self::ROW_H / 2 + self::FONT_SIZE * 0.35) . '"'
                    . ' font-size="' . self::num(self::FONT_SIZE) . '" fill="#ffffff">' . $name . '</text>';
            }
            $svg .= $linked ? '</a>' : '</g>';
        }
        return $svg . '</svg>';
    }

    /** 分档色：报告页现有 CSS 变量（带字面量兜底，脱离页面单独打开也不黑）。 */
    private static function fillColor(float $share): string
    {
        if ($share >= self::SHARE_HOT) {
            return 'var(--xp-orange, #bc4c00)';
        }
        if ($share >= self::SHARE_WARM) {
            return 'var(--xp-accent, #0969da)';
        }
        return 'var(--xp-text-muted, #57606a)';
    }

    /** 文本放得下吗：估算列宽 × 列宽 + 留白 ≤ 帧宽。放不下就整条不画（SVG 不裁剪文本）。 */
    private static function fits(string $text, float $rectW): bool
    {
        return self::textColumns($text) * self::COL_W + self::TEXT_PAD <= $rectW;
    }

    /**
     * 估算 UTF-8 文本的"列数"：ASCII 码点 1 列，其余（CJK 等）2 列。
     * 不用 mb_strwidth——composer.json 只 require 了 xhprof/redis 两个扩展，
     * src/ 不该凭空多出一个 mbstring 依赖。文本不是合法 UTF-8 时正则返回 false，
     * 退到按字节数估（只会把标签估得更保守）。
     */
    private static function textColumns(string $text): int
    {
        $total = preg_match_all('/./su', $text);
        if ($total === false) {
            return strlen($text);
        }
        $wide = (int) preg_match_all('/[^\x00-\x7F]/u', $text);
        return $total + $wide;
    }

    /**
     * viewBox 坐标的定点输出。用 number_format 而不是 `(string) $float`：
     * 后者受 ini precision 影响（同一份数据在不同机器的 SVG 会不一样），
     * 千分位分隔符显式给空串，别把 "1,200.00" 拼进坐标。
     */
    private static function num(float $v): string
    {
        return number_format($v, 2, '.', '');
    }
}
