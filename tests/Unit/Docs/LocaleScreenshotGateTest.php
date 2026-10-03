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
use ErikWang2013\Xhprof\Tests\Support\XhprofStaticsSnapshot;
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
 * 第二条判据（清单）：每页**渲染出的 HTML** 的 sha1 钉在 screenshot-html-manifest.json
 * 里（26 页 = 13 语种 × 2 张，含 zh_CN 的两张根图——指纹与语言无关，它俩虽没有回落
 * 判据，同样受钉）。上一条用例问的是「今天的页面里有没有中文」，问不到「盘上的 PNG 是不是
 * 这份 HTML 拍的」：给一条译文改个措辞（键仍齐、无回落）就能让 26 张图集体过期而全绿，
 * 而 generate.php --verify 只 sweep README.md 与 images/*.svg，PNG 不在比对范围。
 * 清单由 tools/i18n/shots.php 拍照时按**同一个** page() 刷新，所以「清单对得上 ⇔ 截图
 * 拍的是当前 HTML」。清单同时钉页面引用到的 /xhprof-assets/*（JS/CSS/图标改了，
 * HTML 指纹看不见，但浏览器里的渲染会变）。**不钉 PNG 字节**：Chrome 与字体一漂，
 * 字节就变，正确性却可能分毫未动。
 *
 * 两个判据来源上的讲究：
 *  - 候选值先滤「含汉字」再当 needle。zh_CN 的 `runs.col.ip`='IP' 逐字出现在**所有**
 *    语种上，不滤就是 12 个语种全假红（同一条过滤见
 *    I18nTest::noLocaleShipsTheChineseStringItselfAsATranslation）。
 *  - 含占位符的值（`'显示 %s 帧；…'`）在页面上是 sprintf 之后的文本，逐字比会漏
 *    （事故里的 flame.note 正是这种），所以先按占位符切开、只拿不含占位符的**汉字块**
 *    当 needle。块不足 2 个汉字的不取：`'显示 _MENU_ 项结果'` 会切出 2 字块 `显示`，
 *    这类短块理论上有跨语言误报面（实测 12 语种 24 页 0 误报，含日语汉字页；2026-10-04
 *    新键 runs.status 第一次真踩到：ja 译文自带 `· 最新`）。
 *  - 所以扫描前还有一道**双向滤除**：块逐字出现在「本键在本语种词表里的值」中 ⇒ 豁免
 *    该块——本语种自己就这么写，出现它不构成回落签名（逐块判、非整值；flame.note 类
 *    占位符块在韩/日译文里逐字保留也是这条）。键缺 / 值空 ⇒ 无从豁免，块全数保留，
 *    那正是真回落要抓的形状；块被滤光的键自然退出扫描。
 */
final class LocaleScreenshotGateTest extends TestCase
{
    use XhprofStaticsSnapshot;

    /**
     * 拍照脚本打开的那条 run：列表里最慢的 /api/v1/report/export（6.85s，红行）。
     * public：tools/i18n/shots.php 的页表也由这里取（报告页的 URL 参数）。
     */
    public const REPORT_RUN = 'd4d4d4d4d4d4d4d4';

    /**
     * 渲染会经 `Xhprof::index()` → `I18n::setLocale()` 改全局静态（src/Core/Xhprof.php:230）。
     * 本文件一跑就是 12 个语种（末位 ja），红证用例又渲染 ko——不复位的话离开本文件时
     * 环境 locale 停在最后一个语种上，下游**自己不钉 locale** 的用例会跟着变语种。
     * 实测：`LocaleScreenshotGateTest + XhprofDisplayTest` 组合跑 = Failures: 8（渲染成韩语），
     * 单跑 XhprofDisplayTest 全绿——单跑发现不了，只有组合暴露。
     */
    private string $localeBefore = I18n::FALLBACK;

    /**
     * 本文件经真 `Xhprof::index()` 渲染（bootstrap 会写 `Xhprof::$request/$response/…` 静态量），
     * 走快照 trait 还原：没有它，随机顺序下这些静态量会带着本文件写过的值离开，
     * 下游按「干净环境」建前置条件的用例（如 DrupalTest 的 reportRouteWorksWithoutMiddleware）
     * 会在随机序里红——顺序依赖实测过（ci-r4 的种子序跑，差集恰 1 条）。
     *
     * @var array<string, mixed>
     */
    private array $staticsBefore = [];

    protected function setUp(): void
    {
        $this->localeBefore = I18n::locale();
        $this->staticsBefore = $this->snapshotXhprofStatics();
    }

    protected function tearDown(): void
    {
        I18n::setLocale($this->localeBefore);
        $this->assertSame($this->localeBefore, I18n::locale(),
            '渲染过 12 个语种之后必须把环境 locale 复位，否则下游用例跟着变语种');
        $this->restoreXhprofStatics($this->staticsBefore);
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

            // 判据本尊先跑：命中即报「哪条键回落成了哪个中文块」，正是事故的签名。
            // 先按本语种词表做双向滤除——ja 的 runs.status 译文自带 `· 最新`，
            // 那不是回落签名（同 I18nTest::assertNoHanCharacters 的按语种豁免）。
            $scoped = self::needlesExcludingOwnText($needles, I18n::catalogOf($code));
            self::assertNoChineseFallback("docs/i18n/{$code}/images/runs-list.png 对应的列表页", $list, $scoped);
            self::assertNoChineseFallback("docs/i18n/{$code}/images/run-report.png 对应的报告页", $report, $scoped);

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
                // 要拿装坏后的词表算豁免：path.title 值空 ⇒ 无从豁免，块全数保留
                self::needlesExcludingOwnText($needles, I18n::catalogOf('ko'))
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

    /**
     * 豁免规则自身的红证（双向）：共享块要能救假阳，又不能放过真回落。
     *
     * 正例用真表真值钉事件本体——zh_CN 的 runs.status 切出 `· 最新`，ja 的译文逐字
     * 就含它 ⇒ 豁免，同一段 ja 页面 0 命中；反例把豁免条件撤掉（本语种值不含这个块）
     * ⇒ 同一页面上必须仍旧命中。缺键 / 值空同属不豁免——那正是真回落的形状。
     */
    #[Test]
    public function sharedChineseBlocksInTheLocalesOwnTextAreNotFallbacks(): void
    {
        $chunks = self::chineseNeedles()['runs.status'] ?? [];
        self::assertContains('· 最新', $chunks, 'zh_CN 的 runs.status 不再切成「· 最新」——夹具前提变了，重写本对照');

        $jaOwn = I18n::catalogOf('ja')['runs.status'] ?? '';
        self::assertStringContainsString('· 最新', $jaOwn, 'ja 的 runs.status 不再含「· 最新」——夹具前提变了，重写本对照');

        // 正例：ja 自己写的块被豁免，页面上出现它是合法的（ja 页面 0 命中）
        $scoped = self::needlesExcludingOwnText(['runs.status' => $chunks], ['runs.status' => $jaOwn]);
        self::assertNotContains('· 最新', $scoped['runs.status'] ?? [], 'ja 自己写的「· 最新」被当成了回落签名——FP 回归');
        $jaPage = '<html lang="ja"><p>保存済み 12 件 · 最新 2026-10-04</p></html>';
        self::assertSame([], self::fallbackHits($jaPage, $scoped), 'ja 页面上的「· 最新」不该命中');

        // 反例①：豁免条件不成立（本语种值不含任何块）⇒ 同一页面上必须命中
        $noExempt = self::needlesExcludingOwnText(['runs.status' => $chunks], ['runs.status' => 'Stored %s / limit %s']);
        self::assertContains('runs.status="· 最新"', self::fallbackHits($jaPage, $noExempt),
            '块没被豁免、页面又出现了 zh_CN 的块——扫描必须报红，否则豁免规则把真回落也放过了');
        // 反例②：本语种缺键 / 值空同理，没有任何豁免
        self::assertContains('runs.status="· 最新"', self::fallbackHits($jaPage, self::needlesExcludingOwnText(['runs.status' => $chunks], [])),
            '本语种缺键时不该有任何豁免——那正是回落的形状');
    }

    /**
     * 指纹闸：26 张交付截图对应的页面，今天渲染出的 HTML 必须与清单逐条相符。
     *
     * 它管的正是上一条闸管不到的那一格：译文改了措辞（键齐、无回落）⇒ 截图过期而
     * 上一条全绿。清单是「照片拍的就是这份 HTML」唯一的锚。
     */
    #[Test]
    public function deliveredScreenshotsAreMadeFromTheCurrentHtml(): void
    {
        $manifest = self::manifest();
        $pages    = self::screenshotPages();

        // 清单与页表一一对应。少了页就不能只靠逐条比——没进清单的那页没人比，静默放行。
        self::assertSame(array_keys($pages), array_keys($manifest['html']),
            '清单的页集合与 screenshotPages() 对不上（改过语种表或手工编辑过清单）'
            . '——php tools/i18n/shots.php --regenerate-manifest 重出清单');

        $htmls = [];
        foreach ($pages as $key => $page) {
            $html = self::page($page['lang'], $page['params']);
            // 非空转：清单要是从一堆空页生成出来的，哈希当然对得上
            self::assertGreaterThan(1000, strlen($html), "{$key} 渲染出的 HTML 不足 1KB——夹具或渲染路径坏了");
            $htmls[$key] = $html;

            self::assertSame($manifest['html'][$key], sha1($html),
                "{$page['png']} 的页面 HTML 已变 → 用 php tools/i18n/shots.php 重拍 26 张"
                . '后重新生成清单（HTML 的 sha1 与 tests/Unit/Docs/screenshot-html-manifest.json 不符）');
        }

        // JS/CSS/图标不在 HTML 指纹里，但浏览器里渲染出来的截图会被它们改（src 正被人改的
        // 恰恰包括这两样）——引用的每一个都单独钉一份。
        $assets = self::assetDigests($htmls);
        self::assertGreaterThanOrEqual(5, count($assets), '26 页没引用到几个 /xhprof-assets/*——模板或夹具坏了');
        foreach ($assets as $rel => $digest) {
            self::assertSame($manifest['assets'][$rel] ?? null, $digest,
                "src/html/{$rel} 与清单不符（新引用或内容已改）——它改的是截图里浏览器渲染出的东西，"
                . 'HTML 指纹看不见；php tools/i18n/shots.php 重拍后重新生成清单');
        }
    }

    // ---------------- 交付页表与清单（shots.php 与两道判据共用的唯一来源） ----------------

    /**
     * 26 页 = 13 语种 × {列表, 报告}。键 "<code>/<page>" 同时是清单里的页标识与
     * shots.php 的输出文件名依据；zh_CN 的图落在 docs/images/（它是回落的源语种，
     * 没有 docs/i18n/zh_CN/ 目录）。
     *
     * shots.php 直接拿这张表：URL、渲染参数、输出路径三处不再各写一份——23:29:57 的
     * ar 截图与 23:30:06 的词表补齐之间差的 9 秒，就是这种手抄走样的形状。
     *
     * @return array<string, array{lang: string, params: array<string, string>, png: string}>
     */
    public static function screenshotPages(): array
    {
        $pages = [];
        foreach (I18n::AVAILABLE as $code) {
            $dir = $code === I18n::FALLBACK ? 'docs/images' : "docs/i18n/{$code}/images";
            $pages["{$code}/runs-list"] = [
                'lang'   => $code,
                'params' => [],
                'png'    => "{$dir}/runs-list.png",
            ];
            $pages["{$code}/run-report"] = [
                'lang'   => $code,
                'params' => ['run' => self::REPORT_RUN, 'source' => 'xhprof_foo'],
                'png'    => "{$dir}/run-report.png",
            ];
        }
        return $pages;
    }

    /**
     * $htmls 引用到的全部 /xhprof-assets/*（相对 src/html）的 sha1。按 26 页的**并集**
     * 收集：某一页新引一个文件，清单就得跟着长；文件不在盘上记 'missing'，比中即红。
     *
     * @param array<string, string> $htmls key => 渲染出的 HTML
     * @return array<string, string> rel => sha1（或 'missing'）
     */
    public static function assetDigests(array $htmls): array
    {
        $rels = [];
        foreach ($htmls as $html) {
            // 分隔符用 ~：PCRE 的定界符解析不认字符类里的 #，用 # 定界会在这里哑掉
            preg_match_all('~/xhprof-assets/([^\'"<>?#\s]+)~', $html, $m);
            foreach ($m[1] as $rel) {
                $rels[$rel] = true;
            }
        }
        ksort($rels);

        $digests = [];
        foreach (array_keys($rels) as $rel) {
            $file = dirname(__DIR__, 3) . '/src/html/' . $rel;
            $digests[$rel] = is_file($file) ? (string) sha1_file($file) : 'missing';
        }
        return $digests;
    }

    /** @return array{html: array<string, string>, assets: array<string, string>} */
    private static function manifest(): array
    {
        $path = __DIR__ . '/screenshot-html-manifest.json';
        self::assertFileExists($path, 'HTML 指纹清单不在——php tools/i18n/shots.php --regenerate-manifest');
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data['html'] ?? null, '清单缺 html 段');
        self::assertIsArray($data['assets'] ?? null, '清单缺 assets 段');
        return $data;
    }

    // ---------------- 渲染（与拍照同一条路径） ----------------

    /**
     * 真渲染一页：Fakes 注入 + `Xhprof::index()`。**这是交付图与两份判据共用的唯一
     * 渲染路径**——tools/i18n/shots.php 直接从本类调它，不再像 /tmp/shots/render.php
     * 那样手抄一份夹具：夹具漂移（闸背的书与照片不是同一份）在结构上不可能发生。
     *
     * public 就是为 shots.php 开的；它是纯函数（除全局 locale，由 tearDown 复位，
     * 工具侧则一页一进程外无副作用），不参与 PHPUnit 的用例发现。
     *
     * @param array<string, mixed> $params 查询参数（列表页为空，报告页给 run/source）
     */
    public static function page(string $lang, array $params): string
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

    /** 交付图的夹具：5 行列表 + 那条 6.85s run 的边表（page() 调用，故拍照工具用的是同一份） */
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

    /**
     * 双向滤除：块逐字出现在「本键在本语种词表里的值」里 ⇒ 本语种自己就这么写，
     * 页面上出现它不算回落签名（ja 的 runs.status 自带 `· 最新`；flame.note 类
     * 占位符块在韩/日译文里逐字保留同理）。逐**块**判、非整值——整值比对会把
     * 「本语种值里恰好含一个共享块」误当成「整条键没回落」。键缺 / 值空 ⇒ 无从
     * 豁免，块全数保留，那正是真回落要抓的形状；块被滤光的键自然退出扫描。
     *
     * @param array<string, list<string>> $needles chineseNeedles() 切出的块
     * @param array<string, mixed>        $own     本语种词表（I18n::catalogOf($code)）
     * @return array<string, list<string>>
     */
    private static function needlesExcludingOwnText(array $needles, array $own): array
    {
        $kept = [];
        foreach ($needles as $key => $chunks) {
            $value = $own[$key] ?? null;
            foreach ($chunks as $chunk) {
                if (is_string($value) && str_contains($value, $chunk)) {
                    continue;   // 本语种自己写的块，出现不是回落
                }
                $kept[$key][] = $chunk;
            }
        }
        return $kept;
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
