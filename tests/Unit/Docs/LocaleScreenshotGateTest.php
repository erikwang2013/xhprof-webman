<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Docs;

use ErikWang2013\Xhprof\Core\I18n\I18n;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeConfig;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeRequest;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeResponse;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 12 语种 × 2 张交付截图的回落闸：拍出来的页面里不许出现中文。
 *
 * 事故（2026-10-03）：docs/i18n/ar/images/{runs-list,run-report}.png 拍于 23:29:57，
 * 而 ar.php 补齐 6 个新键是 23:30:06——晚了 9 秒。缺键不报错：`t()` 回落 zh_CN 源，
 * 于是阿拉伯语文档里躺了两张「页面其余全阿拉伯语、唯独新加的几处是中文」的截图。
 * 键集一致性测试（I18nTest）那一刻当然是红的，但它管不到**产物**：已落盘的 PNG
 * 不会因为词表后来补齐就自己变干净。所以闸的判据直接落在渲染结果上——
 *
 *   非 zh 页面里出现任何**含汉字的 zh_CN 词表值**（逐字）⇒ 该处文案是回落的 ⇒ 拍不得。
 *
 * 两条用例：①12×2 全部真渲染（与拍照同一条路径：Fakes + `Xhprof::index()`）再扫描；
 * ②红证——借真实 ko 词表把一条值清空（正是事故的形状：值缺了 → 回落），同一条断言
 * 必须点名那一条键。红证只救得了「扫描器本身不会响」，救不了「夹具不渲染那些区块了」，
 * 所以①里另钉了非空转断言：本语种自己的值必须出现在页面上。
 *
 * 两个判据来源上的讲究：
 *  - 候选值先滤「含汉字」再当 needle。zh_CN 的 `runs.col.ip`='IP' 逐字出现在**所有**
 *    语种上，不滤就是 12 个语种全假红（同一条过滤见
 *    I18nTest::noLocaleShipsTheChineseStringItselfAsATranslation）。
 *  - 含占位符的值（`'显示 %s 帧；…'`）在页面上是 sprintf 之后的文本，逐字比会漏
 *    （事故里的 flame.note 正是这种），所以先按占位符切开、只拿不含占位符的**汉字块**
 *    当 needle。块不足 2 个汉字的不取：`'显示 _MENU_ 项结果'` 会切出 2 字块 `显示`，
 *    这类短块理论上有跨语言误报面（实测 12 语种 24 页 0 误报，含日语汉字页）。
 */
final class LocaleScreenshotGateTest extends TestCase
{
    /** 拍照脚本打开的那条 run：列表里最慢的 /api/v1/report/export（6.85s，红行） */
    private const REPORT_RUN = 'd4d4d4d4d4d4d4d4';

    /**
     * 渲染会经 `Xhprof::index()` → `I18n::setLocale()` 改全局静态（src/Core/Xhprof.php:230）。
     * 本文件一跑就是 12 个语种（末位 ja），红证用例又渲染 ko——不复位的话离开本文件时
     * 环境 locale 停在最后一个语种上，下游**自己不钉 locale** 的用例会跟着变语种。
     * 实测：`LocaleScreenshotGateTest + XhprofDisplayTest` 组合跑 = Failures: 8（渲染成韩语），
     * 单跑 XhprofDisplayTest 全绿——单跑发现不了，只有组合暴露。
     */
    private string $localeBefore = I18n::FALLBACK;

    protected function setUp(): void
    {
        $this->localeBefore = I18n::locale();
    }

    protected function tearDown(): void
    {
        I18n::setLocale($this->localeBefore);
        $this->assertSame($this->localeBefore, I18n::locale(),
            '渲染过 12 个语种之后必须把环境 locale 复位，否则下游用例跟着变语种');
    }

    #[Test]
    public function noDeliveredLocalePageFallsBackToChinese(): void
    {
        $needles = self::chineseNeedles();
        // 非空转：needle 表本身得是满的。词表加载坏了 / zh_CN 被清空时，扫描永远"干净"。
        self::assertGreaterThan(100, count($needles), 'zh_CN 的汉字 needle 太少——夹具或词表加载坏了');

        foreach (I18n::AVAILABLE as $code) {
            if ($code === I18n::FALLBACK) {
                continue;   // zh_CN 页面本来就该是中文
            }

            $list   = self::page($code, []);
            $report = self::page($code, ['run' => self::REPORT_RUN, 'source' => 'xhprof_foo']);

            // 语言协商：四级别里的 ?lang= 这一级确实生效了（ar 另钉镜像布局，照片的 dir=rtl 就是它）
            self::assertStringContainsString('<html lang="' . $code . '"', $list, "{$code} 列表页没协商到本语种");
            self::assertStringContainsString('<html lang="' . $code . '"', $report, "{$code} 报告页没协商到本语种");
            if ($code === 'ar') {
                self::assertStringContainsString('<html lang="ar" dir="rtl">', $list, 'ar 必须是镜像布局');
                self::assertStringContainsString('<html lang="ar" dir="rtl">', $report, 'ar 报告页必须是镜像布局');
            }

            // 判据本尊先跑：命中即报「哪条键回落成了哪个中文块」，正是事故的签名
            self::assertNoChineseFallback("docs/i18n/{$code}/images/runs-list.png 对应的列表页", $list, $needles);
            self::assertNoChineseFallback("docs/i18n/{$code}/images/run-report.png 对应的报告页", $report, $needles);

            // 非空转：这两页确实渲染出了本语种自己的新键文案（恰好是事故里回落的那几处）。
            // 放在扫描之后：夹具哪天不再渲染这些区块，扫描就是在空转，这两条是它的哨兵。
            self::assertOwnTextOnPage("{$code} 列表页", $list, $code, 'runs.aggregate');
            self::assertOwnTextOnPage("{$code} 报告页", $report, $code, 'path.title');
            self::assertOwnTextOnPage("{$code} 报告页", $report, $code, 'flame.title');
        }
    }

    /**
     * 红证：把 ko 的 `path.title` 清空，同一条断言必须在那一条键上响。
     *
     * 坏法是照 I18nTest::emptyOrMissingValuesFallBackToTheChineseSource 取的（值空 ⇒
     * `t()` 回落 zh_CN 源），也正是 23:30 前 ar.php 的真实状态。装的是内存里的
     * `$catalogs` 缓存（私有静态，反射写入、finally 还原），磁盘上的词表一个字不动。
     */
    #[Test]
    public function theGateFiresWhenOneLocaleValueGoesMissing(): void
    {
        $needles = self::chineseNeedles();
        $saved   = self::rawCatalogs();

        $broken = I18n::catalogOf('ko');     // 真实 ko 词表（数组按值返回，改它不动缓存）
        $broken['path.title'] = '';

        $caught = null;
        try {
            $all = $saved;
            $all['ko'] = $broken;            // 不能写 `$saved + [...]`：`+` 保留已有键，装不进去
            self::writeCatalogs($all);

            self::assertNoChineseFallback(
                'ko 报告页（path.title 已清空）',
                self::page('ko', ['run' => self::REPORT_RUN, 'source' => 'xhprof_foo']),
                $needles
            );
        } catch (AssertionFailedError $e) {
            $caught = $e;
        } finally {
            self::writeCatalogs($saved);
        }

        self::assertNotNull($caught, 'ko 的 path.title 已清空、页面必然回落中文源，闸却没响——扫描器失效了');
        self::assertStringContainsString('path.title', $caught->getMessage(),
            '闸响了，但没点名回落的那条键：' . $caught->getMessage());
    }

    // ---------------- 渲染（与拍照同一条路径） ----------------

    /**
     * 真渲染一页：Fakes 注入 + `Xhprof::index()`，与截图脚本（/tmp/shots/render.php，
     * 交付图的出处）走同一条路径、同一份夹具。夹具变了就得两处一起改，否则这条闸给
     * 那批图背的书就不成立了。
     *
     * @param array<string, mixed> $params 查询参数（列表页为空，报告页给 run/source）
     */
    private static function page(string $lang, array $params): string
    {
        Xhprof::$time_limit = 0;
        Xhprof::$ignore_url_arr = ['/xhprof'];
        Xhprof::$key_prefix = 'xhprof';
        Xhprof::$view_wtred = 3;
        Xhprof::$ui_html = '';

        Xhprof::bootstrap(
            new FakeRequest($params + ['lang' => $lang], ['uri' => '/xhprof', 'url' => 'http://127.0.0.1/xhprof']),
            new FakeResponse(),
            new FakeConfig([]),
            self::fixtureCache(),
            new FakeLogger()
        );

        $html = Xhprof::index();
        self::assertIsString($html, "{$lang} 的页面没渲染出 HTML（403/400 会返回 response 对象）");

        return $html;
    }

    /** 与拍照脚本逐字同源的夹具：5 行列表 + 那条 6.85s run 的边表 */
    private static function fixtureCache(): FakeCache
    {
        // 旧在前，lPush 前插 => 列表头 = 最新
        $listRows = [
            '91b7f4c2e8a05d63' => ['https://app.example.com/admin/dashboard',      'GET',  0.47, 5.61,  '10.0.0.15', 1790993550],
            '3d0a9f7e5c1b2846' => ['https://app.example.com/user/profile',         'GET',  0.31, 3.18,  '10.0.0.13', 1790993813],
            '7c9e4a1b8d2f6035' => ['https://app.example.com/order/list?page=2',    'GET',  0.84, 7.32,  '10.0.0.11', 1790993947],
            'f0e1d2c3b4a59687' => ['https://app.example.com/api/v1/order/create',  'POST', 1.92, 9.44,  '10.0.0.14', 1790994161],
            self::REPORT_RUN   => ['https://app.example.com/api/v1/report/export', 'GET',  6.85, 12.05, '10.0.0.12', 1790994256],
        ];

        // 数字与列表页 6.85s 那条 run 一致：总耗时 6,850,000μs；R1 命中
        // Connection::run() 78.8% 与 export() 11.7%；R5 命中 ReportBuilder::build() 48.3%。
        $reportData = [
            'main()' => ['ct' => 1, 'wt' => 6850000, 'mu' => 12632000, 'pmu' => 12632000],
            'main()==>App\Http\Controller::export()' => ['ct' => 1, 'wt' => 6700000, 'mu' => 12000000, 'pmu' => 12000000],
            'App\Http\Controller::export()==>App\Service\ReportBuilder::build()' => ['ct' => 1, 'wt' => 5900000, 'mu' => 11000000, 'pmu' => 11000000],
            'App\Service\ReportBuilder::build()==>Illuminate\Database\Connection::run()' => ['ct' => 412, 'wt' => 5400000, 'mu' => 4000000, 'pmu' => 4000000],
            'App\Service\ReportBuilder::build()==>App\Support\Formatter::rows()' => ['ct' => 412, 'wt' => 480000, 'mu' => 900000, 'pmu' => 900000],
            'App\Support\Formatter::rows()==>App\Support\Formatter::cell()' => ['ct' => 412, 'wt' => 320000, 'mu' => 600000, 'pmu' => 600000],
            'App\Service\ReportBuilder::build()==>Illuminate\Support\Collection::map()' => ['ct' => 1, 'wt' => 20000, 'mu' => 0, 'pmu' => 0],
        ];

        $cache = new FakeCache();
        foreach ($listRows as $runId => $row) {
            $cache->lPush('xhprof:run_id', $runId);
            $cache->set('xhprof:request_log:' . $runId, json_encode([
                'request_uri' => $row[0],
                'method'      => $row[1],
                'wt'          => $row[2],
                'mu'          => $row[3],
                'ip'          => $row[4],
                'create_time' => $row[5],
            ]));
        }
        $cache->set('xhprof:xhprof_log:' . self::REPORT_RUN, serialize($reportData));

        return $cache;
    }

    // ---------------- 判据 ----------------

    /**
     * zh_CN 词表里所有「含汉字的值的汉字块」= 回落签名 needle 表。
     *
     * @return array<string, list<string>> key => 不含占位符的汉字块（>= 2 个汉字）
     */
    private static function chineseNeedles(): array
    {
        $needles = [];
        foreach (I18n::catalogOf('zh_CN') as $key => $value) {
            if (!is_string($value) || preg_match('/\p{Han}/u', $value) !== 1) {
                continue;   // 不含汉字的值（'IP' 这类）逐字出现在哪个语种上都合法
            }
            // 占位符：sprintf（%s/%d/%%/%1$s）与 DataTables（_START_/_MENU_…）两种
            foreach (preg_split('/%[0-9]*[$]?[sdf%]|_[A-Z]+_/', $value) as $chunk) {
                $chunk = trim($chunk);
                if (preg_match_all('/\p{Han}/u', $chunk) >= 2) {
                    $needles[$key][] = $chunk;
                }
            }
        }
        return $needles;
    }

    /** @param array<string, list<string>> $needles */
    private static function assertNoChineseFallback(string $where, string $html, array $needles): void
    {
        $hits = self::fallbackHits($html, $needles);
        self::assertSame([], $hits,
            "{$where} 的渲染结果里出现了 zh_CN 词表的原文（含汉字）——该处文案在本语种词表里"
            . '缺失或值空了，t() 回落到了中文源；拍成截图就是中文。先补齐词表再拍。命中：'
            . implode('、', $hits));
    }

    /** @param array<string, list<string>> $needles @return list<string> */
    private static function fallbackHits(string $html, array $needles): array
    {
        // 语言切换器的 <select> 先摘掉：各语言的自称（中文/العربية/हिन्दी）是专有名词、
        // 按设计就该是汉字，不是漏翻（同 I18nTest::assertNoHanCharacters 的处理）。
        $scanned = (string) preg_replace('#<select class="xp-lang".*?</select>#s', '', $html);
        $decoded = html_entity_decode($scanned, ENT_QUOTES | ENT_HTML5);   // 转义形态（&amp; 等）也算

        $hits = [];
        foreach ($needles as $key => $chunks) {
            foreach ($chunks as $chunk) {
                if (str_contains($scanned, $chunk)
                    || str_contains($scanned, htmlspecialchars($chunk, ENT_QUOTES | ENT_SUBSTITUTE))
                    || str_contains($decoded, $chunk)) {
                    $hits[] = $key . '="' . $chunk . '"';
                    break;   // 一条键报一次就够，同键的其它块只会重复
                }
            }
        }
        return $hits;
    }

    /** 本语种自己的文案必须在页面上——否则夹具不再渲染那些区块，扫描就是在空转 */
    private static function assertOwnTextOnPage(string $where, string $html, string $code, string $key): void
    {
        $own = I18n::catalogOf($code)[$key] ?? '';
        self::assertNotSame('', $own, "{$code} 词表的 {$key} 是空值或缺键——这正是回落的形状");
        self::assertTrue(self::appearsIn($html, $own), "{$where} 上找不到本语种自己的 {$key} 文案“{$own}”");
    }

    private static function appearsIn(string $html, string $text): bool
    {
        return str_contains($html, $text)
            || str_contains($html, htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE))
            || str_contains(html_entity_decode($html, ENT_QUOTES | ENT_HTML5), $text);
    }

    // ---------------- 反射装卸词表（`$catalogs` 是私有静态，没有公开入口） ----------------

    /** @return array<string, array<string, mixed>> */
    private static function rawCatalogs(): array
    {
        return (new \ReflectionProperty(I18n::class, 'catalogs'))->getValue();
    }

    /** @param array<string, array<string, mixed>> $catalogs */
    private static function writeCatalogs(array $catalogs): void
    {
        (new \ReflectionProperty(I18n::class, 'catalogs'))->setValue(null, $catalogs);
    }
}
