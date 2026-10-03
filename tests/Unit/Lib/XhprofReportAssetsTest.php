<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Lib;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 列表页静态资源（src/html 下的 JS/CSS）的锚点断言：没有浏览器可跑，
 * 按字面量钉住行为契约（与 XhprofDisplayTest 里的 JS 断言同款做法）。
 *
 * 这里只扫这两个静态文件，不 bootstrap 应用 —— 与语言词表等并发写者无关。
 */
class XhprofReportAssetsTest extends TestCase
{
    private const JS_PATH = '/src/html/js/xhprof_report.js';
    private const CSS_PATH = '/src/html/css/xhprof.css';

    private static function asset(string $rel): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . $rel);
        self::assertNotFalse($content, "读不到 $rel");

        return (string) $content;
    }

    /**
     * A4：run 链接带来的 requrl 预填列表页搜索框。
     * 判据按重要性：① 词必须经 table.search(...).draw() 进 —— DataTables 1.10.15 里
     * 这是会把词回填进**可见**搜索框的入口（search.dt 处理器），换成自己加一个不可见
     * 的过滤器就成了「表少了行但没人知道为什么」；② 值先解码 —— URL 里是
     * %3A%2F%2F 形态，解码前拿去过滤一个字都匹配不上（搜索框里还会显示乱码）。
     */
    #[Test]
    public function requrlPrefillsTheVisibleSearchBox(): void
    {
        $js = self::asset(self::JS_PATH);

        self::assertMatchesRegularExpression(
            '/table\.search\(cur_params\[["\']requrl["\']\]\)\.draw\(\);/',
            $js,
            'requrl 必须经 table.search(...).draw() 进搜索框（可见），而不是隐形过滤'
        );
        self::assertMatchesRegularExpression(
            '/decodeURIComponent\(/',
            $js,
            '查询串值要先解码，否则 %3A%2F%2F 形态的 requrl 一行都过滤不出来'
        );
    }

    /** D7：stateSave 开着 —— 刷新/返回后搜索词、排序、每页条数还在（默认 2 小时过期）。 */
    #[Test]
    public function dataTablesStateIsSaved(): void
    {
        self::assertMatchesRegularExpression(
            '/["\']stateSave["\']\s*:\s*true/',
            self::asset(self::JS_PATH),
            'DataTables 初始化缺少 stateSave: true'
        );
    }

    /**
     * D8：打印时藏掉导航/工具区/分页控件；卡片尽量不跨页；长表表头每页重复。
     * 断言在 **@media print 块内**做（文件尾部到结尾即该块），不是全文凡是出现就算。
     */
    #[Test]
    public function printStylesHideChromeAndRepeatTableHeaders(): void
    {
        $css = self::asset(self::CSS_PATH);
        $pos = strpos($css, '@media print');
        self::assertNotFalse($pos, '缺少 @media print 块');
        $print = substr($css, (int) $pos);

        foreach ([
            '.xp-nav',
            '.xp-runs-toolbar',
            'ul.xhprof_actions',
            '.dataTables_wrapper .dataTables_paginate',
        ] as $selector) {
            self::assertStringContainsString($selector, $print, "打印样式没藏 $selector");
        }
        self::assertMatchesRegularExpression('/break-inside:\s*avoid/', $print, '卡片缺少 break-inside: avoid');
        self::assertMatchesRegularExpression('/display:\s*table-header-group/', $print, '表头缺少跨页重复');
    }

    /**
     * D6b：色盲通道 —— 回归侧（红）数值前补 `+`，与改善侧 number_format 自带的 `-` 对称。
     * 只挂 vrbar：非数值格（N/A）在 PHP 侧已落回中性档 vbar（XhprofDisplay::get_print_class），
     * 不给改善侧（vgbar）加任何符号，否则两侧都带 `+` 就没有信息量了。
     */
    #[Test]
    public function regressionNumbersCarryAPlusSignBeyondColor(): void
    {
        $css = self::asset(self::CSS_PATH);

        self::assertMatchesRegularExpression(
            '/td\.vrbar::before\s*\{\s*content:\s*["\']\+["\'];\s*\}/',
            $css,
            '红格（回归）缺少非颜色通道的 `+`'
        );
        self::assertStringNotContainsString('td.vgbar::before', $css, '改善侧不该再加符号（已有 -）');
        // RTL（ar 页）：`+` 是 ::before 独立盒里的中性符，Chrome 会把它甩到数字右边
        // （实测渲染成 1,234+）；unicode-bidi: plaintext 才把它摆回数字左边。
        self::assertMatchesRegularExpression(
            '/td\.vrbar\s*\{[^}]*unicode-bidi:\s*plaintext/',
            $css,
            'vrbar 缺少 unicode-bidi: plaintext：RTL 下 `+` 会被排到数字右边'
        );
    }
}
