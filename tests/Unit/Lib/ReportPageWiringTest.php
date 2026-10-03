<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Lib;

require_once __DIR__ . '/../../Fixtures/Fakes.php';

use ErikWang2013\Xhprof\Core\Analysis\Analyzer;
use ErikWang2013\Xhprof\Core\I18n\I18n;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofLib\Display\FlameGraph;
use ErikWang2013\Xhprof\Core\XhprofLib\Display\XhprofDisplay;
use ErikWang2013\Xhprof\Core\XhprofLib\Utils\XHProfRunsDefault;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeConfig;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeRequest;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeResponse;
use ErikWang2013\Xhprof\Tests\Support\XhprofStaticsSnapshot;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 报告页一批「连线」特性的端到端钉子（渲染整页、断言真产出的 HTML）：
 *   - A1 上次运行对比链接（raw URI 比对 + run= 必须删掉）
 *   - A2 JSON/CSV 导出链接（报告页有、函数详情页没有）
 *   - A5 火焰图色带图例（阈值来自 FlameGraph 常量） / A6 ?flamemetric= 白名单
 *   - B2 非数值单元格的中性色（`'N/A' <= 0` 在 PHP 8 是 false 的旧缺陷）
 *   - D9 数字分隔符随语言（报告页 HTML；导出与列表页刻意不随）
 *   - D6a scope="col" / tabindex="0"
 * 断言尽量走「页面上的产物」而不是调私有方法：这些特性全在拼字符串的那一层，
 * 单测助手函数证明不了链接真的进了 href、参数真的没被别的键盖掉。
 */
class ReportPageWiringTest extends TestCase
{
    use XhprofStaticsSnapshot;

    /** setUp 开工前的静态量快照（tearDown 原样放回） */
    private array $saved = [];

    /** I18n 的 locale 是纯静态、不在 XhprofStaticsSnapshot 覆盖范围里，手动复位 */
    private string $localeBefore = I18n::FALLBACK;

    protected FakeCache $cache;
    protected FakeRequest $request;

    protected function setUp(): void
    {
        $this->saved = $this->snapshotXhprofStatics();
        $this->localeBefore = I18n::locale();

        $this->cache = new FakeCache();
        $this->request = new FakeRequest([], ['uri' => '/xhprof', 'url' => 'http://xhprof.local/xhprof']);
        Xhprof::bootstrap($this->request, new FakeResponse(), new FakeConfig([]), $this->cache, new FakeLogger());
        if (class_exists(\Hyperf\Context\Context::class)) {
            \Hyperf\Context\Context::set('xhprof.request', $this->request);
            \Hyperf\Context\Context::set('xhprof.response', new FakeResponse());
            \Hyperf\Context\Context::set('xhprof.config', new FakeConfig([]));
            \Hyperf\Context\Context::set('xhprof.cache', $this->cache);
            \Hyperf\Context\Context::set('xhprof.logger', new FakeLogger());
        }
        Xhprof::$time_limit = 0;
        Xhprof::$ignore_url_arr = [];
        Xhprof::$key_prefix = 'xhprof';
        Xhprof::$log_num = 1000;
        Xhprof::$view_wtred = 3;
        Xhprof::$symbol_lookup_url = '';

        XhprofDisplay::set_render_state([
            'sort_col' => 'wt',
            'diff_mode' => false,
            'display_calls' => true,
            'metrics' => null,
            'stats' => [],
            'pc_stats' => [],
            'totals' => 0,
            'totals_1' => 0,
            'totals_2' => 0,
        ]);
        XhprofDisplay::$vwbar = 'class="vwbar"';
        XhprofDisplay::$vbar = 'class="vbar"';
        XhprofDisplay::$vbbar = 'class="vbbar"';
        XhprofDisplay::$vrbar = 'class="vrbar"';
        XhprofDisplay::$vgbar = 'class="vgbar"';
    }

    protected function tearDown(): void
    {
        I18n::setLocale($this->localeBefore);
        $this->restoreXhprofStatics($this->saved);
    }

    private function useRequest(FakeRequest $request): void
    {
        Xhprof::$request = $request;
        if (class_exists(\Hyperf\Context\Context::class)) {
            \Hyperf\Context\Context::set('xhprof.request', $request);
        }
    }

    private function sampleRunData(): array
    {
        return [
            'main()' => ['ct' => 1, 'wt' => 100000, 'mu' => 2048],
            'main()==>foo()' => ['ct' => 1, 'wt' => 40000, 'mu' => 512],
            'main()==>bar()' => ['ct' => 1, 'wt' => 30000, 'mu' => 256],
            'foo()==>strlen()' => ['ct' => 2, 'wt' => 5000, 'mu' => 64],
        ];
    }

    /**
     * 落一条 run：边表 + request_log + 列表指针。
     * FakeCache::lPush 是 unshift（表头 = 最新），所以**调用顺序即从旧到新**。
     */
    private function seedRun(string $id, array $data, string $rawUri, int $time): void
    {
        $this->cache->set('xhprof:xhprof_log:' . $id, serialize($data));
        $this->cache->set('xhprof:request_log:' . $id, json_encode([
            'request_uri' => $rawUri,
            'method' => 'GET',
            'wt' => 0.5,
            'mu' => 1.0,
            'ip' => '8.8.8.8',
            'create_time' => $time,
        ]));
        $this->cache->lPush('xhprof:run_id', $id);
    }

    /** 渲染单 run 报告整页（可选函数详情页 / 额外查询串参数）。 */
    private function renderSingle(string $runId, ?string $symbol = null, array $query = []): string
    {
        $params = array_merge(['run' => $runId, 'all' => 1], $query);
        $this->useRequest(new FakeRequest($params, ['uri' => '/xhprof', 'url' => 'http://xhprof.local/xhprof']));
        return XhprofDisplay::displayXHProfReport($params, 'xhprof_foo', $runId, null, $symbol, 'wt', null, null);
    }

    /** 页面上第一个文本**恰为** $text 的 <a> 的 href（已解码）；没有则 null。 */
    private static function hrefOf(string $html, string $text): ?string
    {
        if (!preg_match('/<a\s[^>]*href="([^"]*)"[^>]*>' . preg_quote($text, '/') . '<\/a>/u', $html, $m)) {
            return null;
        }
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    /** 页面里那段唯一的 <svg …>…</svg>（火焰图），没有则 null。 */
    private static function flameSvg(string $html): ?string
    {
        return preg_match('/<svg\b.*?<\/svg>/s', $html, $m) ? $m[0] : null;
    }

    /**
     * 火焰图几何指纹：帧矩形的宽度序列（占父帧宽度的百分比）。
     * **不能直接比整段 SVG**——帧链接的 href 里带着 `flamemetric=`，
     * 于是「几何一模一样、只是 URL 不同」也会通过「换了指标」的断言
     * （实测：把渲染指标写死成 wt 的变异体就是这么骗过第一版断言的）。
     */
    private static function flameGeometry(string $svg): array
    {
        preg_match_all('/width="([0-9.]+)"/', $svg, $m);
        return $m[1];
    }

    // ---------------------------------------------------------------- A1 ----

    /**
     * 同一 request_uri 的上一条 run 给出 diff 链接；uri 必须是**落库的原串**。
     *
     * 判别性来自诱饵：decoy 的 request_uri 存的是**解码后**的形态、且比 prev 更新。
     * 如果实现拿着页面上那份 urldecode 过的展示串去查（一个很自然的写法），
     * 命中的会是 decoy，本用例就红；只有逐字用原串才会选中 prev。
     */
    #[Test]
    public function previousRunLinkDiffsAgainstRawStoredUriAndDropsRunParam(): void
    {
        $rawUri = 'http://example.com/order?x=a%20b&y=1';
        $decoded = 'http://example.com/order?x=a b&y=1';
        $prev = 'b2b2b2b2b2b2b2b2';
        $decoy = 'c3c3c3c3c3c3c3c3';
        $cur = 'a1a1a1a1a1a1a1a1';

        // 从旧到新：prev(1000) → decoy(2000) → cur(3000)
        $this->seedRun($prev, $this->sampleRunData(), $rawUri, 1000);
        $this->seedRun($decoy, $this->sampleRunData(), $decoded, 2000);
        $this->seedRun($cur, $this->sampleRunData(), $rawUri, 3000);

        $html = $this->renderSingle($cur);

        $href = self::hrefOf($html, I18n::plain('run.previous'));
        self::assertNotNull($href, '同 URI 的上一条 run 存在时，报告页必须给出对比链接');
        self::assertStringContainsString('run1=' . $prev, $href, 'run1 必须是原串命中的那条');
        self::assertStringContainsString('run2=' . $cur, $href);
        self::assertStringNotContainsString($decoy, $href, '解码后的 uri 不能命中（逐字比对）');
        // run= 必须删掉：Xhprof.php 的派发里 `if ($run)` 先于 run1/run2，
        // 留着它点进去还是单跑页，链接等于骗人。
        // 注意是 `[?&]run=`：`run=` 只可能出现在查询串里，而 base 里的 run 恰好跟在 `?` 后面
        // （写成 `(^\?|&)` 时位置 0 是路径、`?` 处又匹配不到 `&`，整条断言会静默空过）。
        self::assertSame(0, preg_match('/[?&]run=[^&]*/', $href), "链接里不能带 run= 参数：$href");
    }

    #[Test]
    public function previousRunLinkHiddenWhenNoEarlierSameUriRunExists(): void
    {
        $cur = 'a1a1a1a1a1a1a1a1';
        $this->seedRun($cur, $this->sampleRunData(), 'http://example.com/only', 3000);

        $html = $this->renderSingle($cur);

        self::assertStringContainsString('main()', $html, '页面本身要正常渲染（对照：不是整页空）');
        self::assertNull(self::hrefOf($html, I18n::plain('run.previous')), '没有上一条 run 时不能显示链接');
        self::assertStringNotContainsString('run1=', $html);
        // 对照：两条链接的触发条件不同——「其他运行」只依赖 request_log 里的原串，
        // 与能否找到上一条 run 无关，这里（有 request_log、无上一条）它必须还在。
        self::assertNotNull(
            self::hrefOf($html, I18n::plain('run.otherRuns')),
            '没有上一条 run 时，「其他运行」链接照样要在'
        );
    }

    // ------------------------------------------------- A4 生产者（其他运行）----

    /**
     * 「该请求地址的其他运行」：回列表页 + `?requrl=`（列表页 JS 读它预填搜索框）。
     *
     * 判别性来自夹具里的 uri：带 `%xx` 与 `&`——若实现拿了页面上那份 urldecode 过的
     * 展示串，`requrl=` 的值就不再是 urlencode(原串)，本条红；若实现忘了摘 run/all
     * （默认 VIEW_PARAMS 干的事），链接会导回单跑页而不是列表页，第二条断言红。
     */
    #[Test]
    public function otherRunsLinkGoesToListPageCarryingTheRawUriUrlencoded(): void
    {
        $rawUri = 'http://example.com/order?x=a%20b&y=1';
        $cur = 'a1a1a1a1a1a1a1a1';
        $this->seedRun($cur, $this->sampleRunData(), $rawUri, 3000);

        $html = $this->renderSingle($cur);

        $href = self::hrefOf($html, I18n::plain('run.otherRuns'));
        self::assertNotNull($href, '单 run 报告页必须给出「该请求地址的其他运行」入口');
        // 参数值必须是**逐字原串**的 urlencode（report_url 内部走 http_build_query，
        // 这里用 urlencode 作为同一编码的独立算式；写死在期望里会掩盖双重编码）。
        self::assertStringContainsString(
            'requrl=' . urlencode($rawUri),
            $href,
            "requrl 必须是原串的 urlencode（不是展示串、也不是二次编码）：$href"
        );
        // 落在列表页：视图参数必须被默认 drop 表摘掉，否则 Xhprof.php 的 dispatch
        // （`if ($run)` 先于 `run1 && run2`）会把点进去的人又导回单跑页。
        self::assertSame(
            0,
            preg_match('/[?&](run|run1|run2|all)=/', $href),
            "列表页链接里不该留 run/run1/run2/all：$href"
        );
    }

    #[Test]
    public function previousRunLinkHiddenWhenRequestLogRowIsGone(): void
    {
        $cur = 'a1a1a1a1a1a1a1a1';
        // 边表在、request_log 不在（计数了、行过期）——首页那条路径上 request_info 是 []。
        $this->cache->set('xhprof:xhprof_log:' . $cur, serialize($this->sampleRunData()));
        $this->cache->lPush('xhprof:run_id', $cur);

        $html = $this->renderSingle($cur);

        self::assertStringContainsString('main()', $html);
        self::assertNull(self::hrefOf($html, I18n::plain('run.previous')));
        // 没有原串可带时不能给出「其他运行」链接：带空 requrl（或退化成裸列表页）
        // 的入口是在骗人——列表页会预填出一个不存在的搜索词。
        self::assertNull(self::hrefOf($html, I18n::plain('run.otherRuns')));
    }

    // ---------------------------------------------------------------- A2 ----

    #[Test]
    public function exportLinksOfferedOnWholeReportButNotOnSymbolPage(): void
    {
        $cur = 'a1a1a1a1a1a1a1a1';
        $this->seedRun($cur, $this->sampleRunData(), 'http://example.com/a', 3000);

        $single = $this->renderSingle($cur);
        $json = self::hrefOf($single, 'JSON');
        $csv = self::hrefOf($single, 'CSV');
        self::assertNotNull($json, '整份报告页要有 JSON 导出入口');
        self::assertNotNull($csv, '整份报告页要有 CSV 导出入口');
        // 导出要连 run 一起带走（导出端点按 run/run1/run2 选数据），token 随 base。
        self::assertStringContainsString('format=json', $json);
        self::assertStringContainsString('run=' . $cur, $json);

        // diff 视图同样给：入口的导出分支支持 run1/run2。
        $this->cache->set('xhprof:xhprof_log:d4d4d4d4d4d4d4d4', serialize($this->sampleRunData()));
        $params = ['run1' => $cur, 'run2' => 'd4d4d4d4d4d4d4d4'];
        $this->useRequest(new FakeRequest($params, ['uri' => '/xhprof']));
        $diff = XhprofDisplay::displayXHProfReport($params, 'xhprof_foo', null, null, null, 'wt', $cur, 'd4d4d4d4d4d4d4d4');
        $diffJson = self::hrefOf($diff, 'JSON');
        self::assertNotNull($diffJson, 'diff 视图也要有导出入口');
        self::assertStringContainsString('format=json', $diffJson);
        self::assertStringContainsString('run1=' . $cur, $diffJson);

        // 函数详情页不给：导出忽略 symbol，挂在这是误导。
        $symbol = $this->renderSingle($cur, 'foo()');
        self::assertStringContainsString('父/子报告', $symbol, '对照：确实渲染的是函数详情页');
        self::assertStringNotContainsString('format=json', $symbol);
        self::assertStringNotContainsString('format=csv', $symbol);
    }

    // ------------------------------------------------------------ A5/A6 ----

    #[Test]
    public function flameLegendThresholdsComeFromFlameGraphConstants(): void
    {
        $cur = 'a1a1a1a1a1a1a1a1';
        $this->seedRun($cur, $this->sampleRunData(), 'http://example.com/a', 3000);

        $html = $this->renderSingle($cur);
        self::assertNotNull(self::flameSvg($html), '对照：夹具要能画出火焰图，否则下面的图例断言全是空转');

        // 阈值不是抄来的数字：从常量算出来比对（常量改了、图例没跟，这里红）。
        $hot  = '≥' . number_format(FlameGraph::SHARE_HOT * 100, 0) . '%';
        $warm = '≥' . number_format(FlameGraph::SHARE_WARM * 100, 0) . '%';
        $cool = '&lt;' . number_format(FlameGraph::SHARE_WARM * 100, 0) . '%';
        self::assertStringContainsString($hot, $html);
        self::assertStringContainsString($warm, $html);
        self::assertStringContainsString($cool, $html);
        // 三档底色与 FlameGraph::fillColor() 同一组 CSS 变量（那里是真源）。
        foreach (['--xp-orange', '--xp-accent', '--xp-text-muted'] as $var) {
            self::assertStringContainsString($var, $html);
        }
    }

    #[Test]
    public function flameMetricParamSwitchesRenderedMetricAndFallsBackSafely(): void
    {
        $cur = 'a1a1a1a1a1a1a1a1';
        $this->seedRun($cur, $this->sampleRunData(), 'http://example.com/a', 3000);

        $default = $this->renderSingle($cur);
        $svgDefault = self::flameSvg($default);
        self::assertNotNull($svgDefault);
        self::assertStringContainsString('<b>' . I18n::plain('col.wt') . '</b>', $default, '默认指标是 wt');
        self::assertStringNotContainsString(I18n::plain('flame.muInclusive'), $default, 'wt 不带 mu 口径说明');

        // 切到 mu：几何真的换了（不是只换标签）、标签加粗换人、补 mu 口径说明。
        $muPage = $this->renderSingle($cur, null, ['flamemetric' => 'mu']);
        $svgMu = self::flameSvg($muPage);
        self::assertNotNull($svgMu, '切指标不能让整张卡片消失');
        $geomDefault = self::flameGeometry($svgDefault);
        $geomMu = self::flameGeometry($svgMu);
        self::assertNotSame(array(), $geomDefault, '对照：夹具确实画出了帧');
        self::assertNotSame($geomDefault, $geomMu, '换指标必须换几何（否则等于没切）');
        self::assertStringContainsString('<b>' . I18n::plain('col.mu') . '</b>', $muPage);
        self::assertStringContainsString(I18n::plain('flame.muInclusive'), $muPage);
        $wtLink = self::hrefOf($muPage, I18n::plain('col.wt'));
        self::assertNotNull($wtLink, '切走之后要有切回 wt 的链接');
        self::assertStringContainsString('flamemetric=wt', $wtLink);

        // 非法值（不在白名单）回落 wt，卡片照在。
        $bogus = $this->renderSingle($cur, null, ['flamemetric' => 'bogus']);
        self::assertNotNull(self::flameSvg($bogus));
        self::assertStringContainsString('<b>' . I18n::plain('col.wt') . '</b>', $bogus);

        // 数组形态 ?flamemetric[]=wt 同样是非法值（in_array 需要 is_string 先挡一道）。
        $arrayy = $this->renderSingle($cur, null, ['flamemetric' => ['wt']]);
        self::assertNotNull(self::flameSvg($arrayy));
        self::assertStringContainsString('<b>' . I18n::plain('col.wt') . '</b>', $arrayy);

        // 白名单里、但本次 run 没采集到的指标（夹具只有 wt/mu）：回落 wt 而不是画一张空图。
        $cpu = $this->renderSingle($cur, null, ['flamemetric' => 'cpu']);
        self::assertNotNull(self::flameSvg($cpu), '未采集的指标不能把卡片搞没');
        self::assertStringContainsString('<b>' . I18n::plain('col.wt') . '</b>', $cpu);
    }

    // ---------------------------------------------------------------- B2 ----

    /**
     * PHP 8 下 `'N/A' <= 0` 是 false（非数字串 vs int 走字典序），旧实现把
     * 「没数据」的单元格染成红色 VRBAR = 报成性能退化。非数值必须中性。
     */
    #[Test]
    public function nonNumericDiffCellGetsNeutralClass(): void
    {
        // 非 diff 的加粗格是「排序烈」的蓝（vbbar），与 diff 无关，照旧。
        self::assertSame('class="vbbar"', XhprofDisplay::get_print_class('N/A', true));

        // 方向语义（负 = 改善 = 绿）不动，这里反向钉住，防止「顺手修」把方向也改了；
        // 非数值必须先于方向判断落回中性。
        XhprofDisplay::set_render_state(['diff_mode' => true]);
        self::assertSame('class="vgbar"', XhprofDisplay::get_print_class(-5, true));
        self::assertSame('class="vrbar"', XhprofDisplay::get_print_class(5, true));
        self::assertSame('class="vbar"', XhprofDisplay::get_print_class('N/A', true));
    }

    /** 页面级：diff 的函数详情页里，run1 没有该符号 → per-call 行是 N/A，必须是中性色。 */
    #[Test]
    public function diffSymbolPageRendersNaCellsNeutralNotRed(): void
    {
        $r1 = 'a1a1a1a1a1a1a1a1';
        $r2 = 'd4d4d4d4d4d4d4d4';
        $d1 = ['main()' => ['ct' => 1, 'wt' => 100000, 'mu' => 2048]];
        $d2 = $this->sampleRunData();
        $this->cache->set('xhprof:xhprof_log:' . $r1, serialize($d1));
        $this->cache->set('xhprof:xhprof_log:' . $r2, serialize($d2));

        $params = ['run1' => $r1, 'run2' => $r2, 'symbol' => 'foo()'];
        $this->useRequest(new FakeRequest($params, ['uri' => '/xhprof']));
        $html = XhprofDisplay::displayXHProfReport($params, 'xhprof_foo', null, null, 'foo()', 'wt', $r1, $r2);

        self::assertStringContainsString('父/子报告', $html, '对照：渲染的是函数详情页');
        self::assertStringContainsString('>N/A</td>', $html, 'run1 无此符号 → per-call 行是 N/A');
        // 只判「N/A 这个格子」（`>N/A</td>`）：`>N/A%</td>` 是另一回事——那里的 class
        // 由**数值增量**算（35000 比 0），红是对的，只是百分比无定义才印 N/A%，
        // 不属 B2 的「非数值输入」。
        self::assertSame(0, preg_match('#<td[^>]*class="vrbar"[^>]*>N/A</td>#', $html), 'N/A 不能染成退化红');
        self::assertSame(0, preg_match('#<td[^>]*class="vgbar"[^>]*>N/A</td>#', $html), 'N/A 也不能染成改善绿');
        self::assertStringContainsString('class="vrbar"', $html, '对照：真正的增量行仍是红的（避免上面两条靠“整页没有 vrbar”空过）');
    }

    // ---------------------------------------------------------------- D9 ----

    #[Test]
    public function numberAndPercentFormatFollowLocaleSeparators(): void
    {
        // 默认（zh_CN）与 en 同形，钉住没改坏。
        self::assertSame('1,234,567.89', XhprofDisplay::xhprof_num_format(1234567.891, 2));
        self::assertSame('12.3%', XhprofDisplay::xhprof_percent_format(0.1234));

        I18n::setLocale('de');
        self::assertSame('1.234.567,89', XhprofDisplay::xhprof_num_format(1234567.891, 2));
        self::assertSame('12,3%', XhprofDisplay::xhprof_percent_format(0.1234));

        // fr / ru 的千位符是窄不换行空格 / 不换行空格（CLDR 实测值），不是点也不是逗号。
        I18n::setLocale('fr');
        self::assertSame("1\u{202F}234\u{202F}567,89", XhprofDisplay::xhprof_num_format(1234567.891, 2));
        I18n::setLocale('ru');
        self::assertSame("1\u{00A0}234\u{00A0}567,89", XhprofDisplay::xhprof_num_format(1234567.891, 2));
    }

    /** 报告页整页数字真的跟着语言走（不只是助手函数），且火焰图注的百分数用本地小数点。 */
    #[Test]
    public function reportPageNumbersFollowLocaleInRenderedHtml(): void
    {
        $cur = 'a1a1a1a1a1a1a1a1';
        $this->seedRun($cur, $this->sampleRunData(), 'http://example.com/a', 3000);

        $zh = $this->renderSingle($cur);
        self::assertStringContainsString('30,000', $zh, '对照：中文页仍是英式千位（与 en 同形）');
        self::assertStringContainsString('40.0%', $zh);

        I18n::setLocale('de');
        $de = $this->renderSingle($cur);
        self::assertStringContainsString('30.000', $de, '德语页的排除耗时要用点分组');
        self::assertStringContainsString('40,0%', $de, '百分比的小数点也要换');
        self::assertStringNotContainsString('>30,000<', $de, '同一页不能两种千位写法混着');
    }

    /**
     * 诊断区文案与同页表格同一套分隔符——Analyzer 曾用裸 number_format()，
     * de 下表格 `1.234.567` 而诊断 `1,234,567`，正是 D9 要消灭的同页混写。
     *
     * 判别性：ct=1234567 才印得出带分组的诊断句；断言钉在整句上而不是
     * 「页面上有没有这个数」——表格里本来就有同一个 ct，单数一个数核不出是诊断区印的。
     *
     * 夹具是为 analyze() 的语义挑的，不是随手编的：主区按**符号**去重（R1 先到先得，
     * MAIN_LIMIT=3），所以 R2/R3 要各自骑在一个 R1 不认领的符号上——
     *   hot()  自身占比 1%：低于 R1 的 10%、也低于 R3 的 5%，归 R2（ct 超阈值）独有；
     *   warm() 自身占比 7%：正好落在 R3 的 5%–10% 带里（R1 的 10% 闸门拦下 R1）；
     *   fat()  内存峰值占满：R5 走补充区（不参与主区去重），钉住 bytes()/pct()。
     * 若把 hot()/warm() 的耗时调大到 ≥10%，它们会被 R1 抢走，诊断区就不印这两句了。
     */
    #[Test]
    public function diagnosisNumbersFollowLocaleSeparators(): void
    {
        $data = [
            'main()' => ['ct' => 1, 'wt' => 1000000, 'mu' => 1572864, 'pmu' => 1572864],
            // hot()：1% 自身占比 + 7 位数调用量 → 只有 R2 报
            'main()==>hot()' => ['ct' => 1234567, 'wt' => 10000, 'mu' => 256, 'pmu' => 256],
            // warm()：7% 自身占比 + 7 位数调用量 → R3 报（R1 认领不了，R2 同符号那条会被去重丢掉）
            'main()==>warm()' => ['ct' => 1234567, 'wt' => 70000, 'mu' => 512, 'pmu' => 512],
            // fat()：内存峰值占满 → R5 报（走 bytes() 的 MB 分支，小数也要随语言）
            'main()==>fat()' => ['ct' => 3, 'wt' => 1000, 'mu' => 1572864, 'pmu' => 1572864],
            // fat_kb()：同走 R5，但落在 bytes() 的 KB 分支（两个分支各钉一条）
            'main()==>fat_kb()' => ['ct' => 3, 'wt' => 1000, 'mu' => 524288, 'pmu' => 524288],
        ];
        $this->seedRun('b2b2b2b2b2b2b2b2', $data, 'http://example.com/b', 3000);

        $zh = $this->renderSingle('b2b2b2b2b2b2b2b2');
        self::assertStringContainsString(
            sprintf(I18n::t('diag.r2.title'), 'hot()', '1,234,567'),
            $zh,
            '对照：中文诊断句是英式分组（与 en 同形）'
        );
        self::assertStringContainsString(
            sprintf(I18n::t('diag.r3.title'), 'main()', 'warm()', '1,234,567', '70.0ms'),
            $zh,
            '对照：中文诊断句的小数是点'
        );
        self::assertStringContainsString(
            sprintf(I18n::t('diag.r5.title'), 'fat()', '1.5MB', '100.0%'),
            $zh,
            '对照：中文诊断句的字节数也是英式'
        );
        self::assertStringContainsString(
            sprintf(I18n::t('diag.r5.title'), 'fat_kb()', '512.0KB', '33.3%'),
            $zh,
            '对照：KB 分支同理'
        );

        I18n::setLocale('de');
        $de = $this->renderSingle('b2b2b2b2b2b2b2b2');
        self::assertStringContainsString(
            sprintf(I18n::t('diag.r2.title'), 'hot()', '1.234.567'),
            $de,
            '德语诊断句的千位要随语言（与同页表格一致）'
        );
        self::assertStringContainsString(
            sprintf(I18n::t('diag.r3.title'), 'main()', 'warm()', '1.234.567', '70,0ms'),
            $de,
            '诊断句里的小数分隔符也要换'
        );
        self::assertStringContainsString(
            sprintf(I18n::t('diag.r5.title'), 'fat()', '1,5MB', '100,0%'),
            $de,
            'bytes()/pct() 的小数同样随语言'
        );
        self::assertStringContainsString(
            sprintf(I18n::t('diag.r5.title'), 'fat_kb()', '512,0KB', '33,3%'),
            $de,
            'bytes() 的 KB 分支同样随语言'
        );
        self::assertStringNotContainsString('1,234,567', $de, '同页不能表格点分组、诊断逗号分组混着');
        self::assertStringNotContainsString('70.0ms', $de, '同上：ms 的小数点');
        self::assertStringNotContainsString('1.5MB', $de, '同上：bytes() 的小数点');
        self::assertStringNotContainsString('512.0KB', $de, '同上：bytes() 的 KB 分支');
    }

    /**
     * R6 的三个微秒数也走同一套分隔符。
     *
     * 单独一条是因为 R6 在真实渲染路径上不可达：平铺表是「边表减子树」算出来的，
     * excl_wt 恒 ≤ wt（AnalyzerTest 里的 R6 用例也是合成表）——所以这里直接调
     * Analyzer::analyze()，不走页面。
     */
    #[Test]
    public function diagnosisR6NumbersFollowLocaleSeparators(): void
    {
        $tab = [
            'main()' => ['ct' => 1, 'wt' => 1000000, 'excl_wt' => 999000, 'mu' => 0, 'pmu' => 0, 'excl_mu' => 0, 'excl_pmu' => 0],
            // 计时倒挂：自身耗时大于总耗时（坏数据/时钟回拨才可能出现）
            'ghost()' => ['ct' => 2, 'wt' => 1234000, 'excl_wt' => 2345678, 'mu' => 0, 'pmu' => 0, 'excl_mu' => 0, 'excl_pmu' => 0],
        ];
        I18n::setLocale('de');

        $r6 = null;
        foreach (Analyzer::analyze($tab, [], ['wt' => 1000000]) as $f) {
            if ($f->rule === 'R6') {
                $r6 = $f;
            }
        }

        self::assertNotNull($r6, '夹具前提：合成表必须触发 R6');
        self::assertSame(
            sprintf(I18n::t('diag.r6.title'), 'ghost()', '2.345.678μs', '1.234.000μs', '1.111.678μs'),
            $r6->title,
            '德语下 R6 的三个微秒数都要点分组'
        );
    }

    /**
     * bytes() 的 B 分支（<1KB，0 位小数）也要千位分组——`1,005B` 是整条
     * 格式化链上唯一「0 位小数也看得见分组」的形态，其余分支要么有小数、
     * 要么数值太小。用合成表直接调（页面夹具里它和 MB/KB 分支的 totals 互相打架：
     * 抢 totals['pmu'] 的分母）。
     */
    #[Test]
    public function diagnosisBytesUnderOneKilobyteGetsGrouping(): void
    {
        $tab = ['tiny()' => ['ct' => 1, 'wt' => 10, 'excl_wt' => 10, 'mu' => 0, 'pmu' => 0, 'excl_mu' => 1005, 'excl_pmu' => 1005]];
        I18n::setLocale('de');

        $r5 = null;
        foreach (Analyzer::analyze($tab, [], ['wt' => 100, 'pmu' => 1005]) as $f) {
            if ($f->rule === 'R5') {
                $r5 = $f;
            }
        }

        self::assertNotNull($r5, '夹具前提：合成表必须触发 R5');
        self::assertSame(
            sprintf(I18n::t('diag.r5.title'), 'tiny()', '1.005B', '100,0%'),
            $r5->title,
            'B 分支的千位与百分比小数都要本地化'
        );
    }

    // ---------------------------------------------------------------- A3 ----

    /**
     * 列表页状态条：条数 / 上限 / 保留天数 / 最早–最新取真值，且与表格**同一趟取数**
     * （渲染期间 lRange 与 mget 各恰好一次——状态条若靠再调一次 runsOverview() 取数，
     * 列表页的 Redis 往返就翻倍，这两条计数断言就是钉那个的）。
     */
    #[Test]
    public function listPageStatusBarShowsRealCountsAndSharesTheSingleFetch(): void
    {
        Xhprof::$log_num = 7;
        Xhprof::$log_ttl = 86400 * 3;
        // 头新尾旧：A(300)、B(100)、C(200)——按「旧→新」种入
        $this->seedRun('c3c3c3c3c3c3c3c3', $this->sampleRunData(), 'http://x/c', 200);
        $this->seedRun('b2b2b2b2b2b2b2b2', $this->sampleRunData(), 'http://x/b', 100);
        $this->seedRun('a1a1a1a1a1a1a1a1', $this->sampleRunData(), 'http://x/a', 300);

        $this->cache->calls = [];   // 只看渲染期间的 Redis 往返
        $html = XHProfRunsDefault::list_runs();

        self::assertStringContainsString(
            sprintf(
                I18n::plain('runs.status'),
                3,
                7,
                3,
                date('Y-m-d H:i:s', 100),
                date('Y-m-d H:i:s', 300)
            ),
            $html,
            '状态条要印真实的条数/上限/保留天数；最早与最新取 mget 到的真实 create_time（与列表头尾无关）'
        );
        self::assertSame(
            1,
            count(array_keys($this->cache->calls, 'mget')),
            '表格与状态条必须共用一趟 mget：两次就是 Redis 往返翻倍'
        );
        self::assertSame(
            1,
            count(preg_grep('/^lRange:/', $this->cache->calls)),
            'lRange 同理只许一趟'
        );

        // 空索引：整条仍在（上限/保留天数仍有信息量），日期位退成 '-'，且空路径同样只花一趟。
        $this->cache->reset();
        $empty = XHProfRunsDefault::list_runs();
        self::assertStringContainsString(
            sprintf(I18n::plain('runs.status'), 0, 7, 3, '-', '-'),
            $empty,
            '空列表的状态条：条数 0、日期位 -'
        );
        self::assertSame(1, count(array_keys($this->cache->calls, 'mget')), '空路径也不能翻倍');
    }

    // --------------------------------------------------------------- D6a ----

    #[Test]
    public function listPageHeadersCarryScopeAndScrollWrapIsFocusable(): void
    {
        $this->seedRun('a1a1a1a1a1a1a1a1', $this->sampleRunData(), 'http://example.com/a', 3000);

        $html = XHProfRunsDefault::list_runs();

        self::assertSame(7, preg_match_all('/<th[ >]/', $html), '列表页表头 7 列');
        self::assertSame(7, substr_count($html, 'scope="col"'), '每一列都要 scope（含复选框那列）');
        self::assertStringContainsString('class="xp-table-wrap" tabindex="0"', $html, '横滚容器要能被键盘聚焦');
    }

    #[Test]
    public function reportPageTablesAreScopedAndScrollWrapIsFocusable(): void
    {
        $cur = 'a1a1a1a1a1a1a1a1';
        $this->seedRun($cur, $this->sampleRunData(), 'http://example.com/a', 3000);

        $html = $this->renderSingle($cur);

        $headers = preg_match_all('/<th[ >]/', $html);
        self::assertGreaterThan(0, $headers, '对照：页面里确实有表头');
        self::assertSame($headers, substr_count($html, 'scope="col"'), '报告页的 <th> 一个不漏都要 scope');
        self::assertGreaterThanOrEqual(1, substr_count($html, 'class="xp-table-wrap" tabindex="0"'));
    }

    // --------------------------------------------------------------- 页脚 ----

    /**
     * 页脚版权行：报告页最底部（`</body>` 前的最后一块）、URL 做成外链
     * （与导航条 GitHub 链接同式），列表页与单 run 页都要有。
     *
     * 整行写死在期望里是有意的：它就是写在 13 份词表里的那句（品牌+URL 语言无关、
     * 逐字同值），谁改了词表值或改了链接标记，本条红。
     */
    #[Test]
    public function footerCreditLineWithExternalLinkClosesBothReportPages(): void
    {
        $cur = 'a1a1a1a1a1a1a1a1';
        $this->seedRun($cur, $this->sampleRunData(), 'http://example.com/a', 3000);

        $line = '© erik · <a href="https://erik.xyz" target="_blank" rel="noopener">https://erik.xyz</a></div>';

        $single = $this->renderSingle($cur);
        self::assertStringContainsString($line, $single, '单 run 报告页必须有页脚版权行（URL 为外链）');
        self::assertStringEndsWith($line, $single, '页脚要在页面最底部（</body> 前的最后一块）');

        $params = ['all' => 1];
        $this->useRequest(new FakeRequest($params, ['uri' => '/xhprof', 'url' => 'http://xhprof.local/xhprof']));
        $list = XhprofDisplay::displayXHProfReport($params, 'xhprof_foo', null, null, null, 'wt', null, null);
        self::assertStringContainsString('请求记录', $list, '对照：确实渲染的是列表页');
        self::assertStringContainsString($line, $list, '列表页同样要有页脚');
        self::assertStringEndsWith($line, $list, '列表页的页脚也在最底部');
    }
}
