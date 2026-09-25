<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Core;

require_once __DIR__ . '/../../Fixtures/Fakes.php';

use ErikWang2013\Xhprof\Core\I18n\I18n;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofLib\Display\XhprofDisplay;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeConfig;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeRequest;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 报告页多语言的三件事，各有各的失败方式：
 *
 *  1. **语言协商**（四级优先级）：`?lang=` > 配置 `locale` > `Accept-Language` > 兜底中文。
 *     任何一级填了不认识的语言码都只是「跳过这一级」，最终必须落在一个能渲染的语言上。
 *  2. **兜底严密**：未知语言码、协商不出来、配置写错、词表文件缺失、key 不在词表里——
 *     报告页照常出，绝不抛异常、绝不留白。
 *  3. **12 份词表的结构一致性**：键集逐键相同（这是 11 个语言 agent 的验收判据，也是
 *     「某个语言漏翻一条」唯一的机械保障）。译文写没写、写得好不好，本文件**不**断言
 *     ——没有任何脚本能判断译文的语域与自然度，这里不假装可以。
 *
 * 另外钉住转义策略：词表值里**只有** `<br>` 是真标记，其余字符一律转义。
 */
class I18nTest extends TestCase
{
    private function langDir(): string
    {
        return dirname(__DIR__, 3) . '/src/Core/I18n/lang';
    }

    protected function setUp(): void
    {
        I18n::setLocale(I18n::FALLBACK);
    }

    protected function tearDown(): void
    {
        // 语言是静态的，会跨用例残留：本文件里有把语言设成 en/ko/ar 的用例，
        // 不还原就会让后面断言中文的用例莫名其妙地红。
        I18n::setLocale(I18n::FALLBACK);
        Xhprof::$request = null;
        Xhprof::$response = null;
        Xhprof::$config = null;
        Xhprof::$cache = null;
        Xhprof::$logger = null;
    }

    // ---------------- 1. 四级优先级 ----------------

    #[Test]
    public function urlLangBeatsConfigAndBrowser(): void
    {
        $req = new FakeRequest(
            ['lang' => 'ko'],
            ['headers' => ['accept-language' => 'fr-FR,fr;q=0.9']]
        );
        $cfg = new FakeConfig(['xhprof' => ['locale' => 'de']]);

        $this->assertSame('ko', I18n::resolve($req, $cfg), '?lang= 必须压过配置与 Accept-Language');
    }

    #[Test]
    public function configuredLocaleBeatsBrowser(): void
    {
        $req = new FakeRequest([], ['headers' => ['accept-language' => 'fr-FR,fr;q=0.9']]);
        $cfg = new FakeConfig(['xhprof' => ['locale' => 'de']]);

        $this->assertSame('de', I18n::resolve($req, $cfg), '配置的 locale 是管理员定的默认，该压过浏览器');
    }

    #[Test]
    public function browserLanguageIsUsedWhenUrlAndConfigSayNothing(): void
    {
        $req = new FakeRequest([], ['headers' => ['accept-language' => 'en-US,en;q=0.9,zh-CN;q=0.8']]);
        $cfg = new FakeConfig(['xhprof' => []]);

        $this->assertSame('en', I18n::resolve($req, $cfg), '没有 URL 与配置时按 Accept-Language 协商');
    }

    #[Test]
    public function chineseIsTheLastResort(): void
    {
        $req = new FakeRequest();
        $cfg = new FakeConfig(['xhprof' => []]);

        $this->assertSame('zh_CN', I18n::resolve($req, $cfg), '什么都没有时必须兜底中文');
        $this->assertSame('zh_CN', I18n::resolve(null, null), '连适配器都没有时也必须兜底中文');
    }

    #[Test]
    public function chineseIsAlsoTheLastResortWhenBrowserAsksForSomethingWeDoNotHave(): void
    {
        $req = new FakeRequest([], ['headers' => ['accept-language' => 'sv-SE,sv;q=0.9,fi;q=0.8']]);
        $cfg = new FakeConfig(['xhprof' => []]);

        $this->assertSame('zh_CN', I18n::resolve($req, $cfg));
    }

    // ---------------- 2. 兜底严密（不认识的一律跳过，绝不抛） ----------------

    #[Test]
    public function anUnknownCodeAtEveryLevelStillEndsInChinese(): void
    {
        $req = new FakeRequest(
            ['lang' => 'kl-GL'],
            ['headers' => ['accept-language' => 'xx-YY,qq;q=0.9']]
        );
        $cfg = new FakeConfig(['xhprof' => ['locale' => 'klingon']]);

        $this->assertSame('zh_CN', I18n::resolve($req, $cfg), '三级全不认识时回落到中文，而不是抛异常');
    }

    #[Test]
    public function anInvalidConfiguredLocaleDoesNotBlockTheBrowserLevel(): void
    {
        $req = new FakeRequest([], ['headers' => ['accept-language' => 'ja;q=0.8']]);
        $cfg = new FakeConfig(['xhprof' => ['locale' => 'not-a-language']]);

        $this->assertSame('ja', I18n::resolve($req, $cfg), '配置写错只该被跳过，不该吞掉浏览器协商');
    }

    #[Test]
    public function aNonStringConfiguredLocaleDoesNotBlowUp(): void
    {
        // 配置文件里手滑写成数组/数字/布尔（YAML 与 PHP 配置都容易出这种事）
        foreach ([['zh_CN'], 42, true, false, null, 3.14] as $bad) {
            $cfg = new FakeConfig(['xhprof' => ['locale' => $bad]]);
            $this->assertSame('zh_CN', I18n::resolve(new FakeRequest(), $cfg));
        }
    }

    #[Test]
    public function browserNegotiationHonoursQValuesAndSkipsWhatWeLack(): void
    {
        // sv 排第一但我们没有 → 跳过，取下一个我们有的（de 0.7 与 en 0.9 之间取 en）
        $req = new FakeRequest([], ['headers' => ['accept-language' => 'sv;q=1.0,en;q=0.9,de;q=0.7']]);
        $this->assertSame('en', I18n::resolve($req, new FakeConfig(['xhprof' => []])));
    }

    #[Test]
    public function qZeroMeansNeverUseIt(): void
    {
        $req = new FakeRequest([], ['headers' => ['accept-language' => 'de;q=0,en;q=0.5']]);
        $this->assertSame('en', I18n::resolve($req, new FakeConfig(['xhprof' => []])));

        // 只剩 q=0 的语言 → 协商不出结果 → 中文兜底
        $req = new FakeRequest([], ['headers' => ['accept-language' => 'de;q=0']]);
        $this->assertSame('zh_CN', I18n::resolve($req, new FakeConfig(['xhprof' => []])));
    }

    #[Test]
    public function wildcardAndGarbageHeadersAreSkipped(): void
    {
        $req = new FakeRequest([], ['headers' => ['accept-language' => '*']]);
        $this->assertSame('zh_CN', I18n::resolve($req, new FakeConfig(['xhprof' => []])));

        $req = new FakeRequest([], ['headers' => ['accept-language' => '*, en;q=0.3']]);
        $this->assertSame('en', I18n::resolve($req, new FakeConfig(['xhprof' => []])));

        foreach (['', '   ', ';;;', ',,,', 'x'] as $junk) {
            $req = new FakeRequest([], ['headers' => ['accept-language' => $junk]]);
            $this->assertSame('zh_CN', I18n::resolve($req, new FakeConfig(['xhprof' => []])));
        }
    }

    #[Test]
    public function regionAndScriptVariantsMapOntoTheAvailableCatalogs(): void
    {
        $cases = [
            'zh-TW' => 'zh_CN',      // 只有简体这一份中文，繁体也归到它
            'zh-HK' => 'zh_CN',
            'zh-Hans-CN' => 'zh_CN',
            'zh_cn' => 'zh_CN',
            'en-GB' => 'en',
            'pt-BR' => 'pt',
            'ko-KR' => 'ko',
            'ar-EG' => 'ar',
            'id-ID' => 'id',
            'EN' => 'en',
        ];
        foreach ($cases as $tag => $expected) {
            $this->assertSame($expected, I18n::normalize($tag), "{$tag} 应归一到 {$expected}");
        }

        $this->assertNull(I18n::normalize('sv'));
        $this->assertNull(I18n::normalize(''));
        $this->assertNull(I18n::normalize(null));
        $this->assertNull(I18n::normalize(['ko']));    // 非字符串只是解析不出来，不该抛
    }

    #[Test]
    public function anUnknownKeyNeverThrowsAndNeverRendersBlank(): void
    {
        I18n::setLocale('ko');
        $this->assertSame('no.such.key', I18n::t('no.such.key'), '词表里没有的 key 返回 key 本身');
        $this->assertSame('no.such.key', I18n::html('no.such.key'));
        $this->assertSame('no.such.key', I18n::plain('no.such.key'));
        $this->assertFalse(I18n::has('no.such.key'));
        $this->assertTrue(I18n::has('nav.home'));
    }

    #[Test]
    public function settingAnUnknownOrNonStringLocaleFallsBackInsteadOfThrowing(): void
    {
        foreach (['kl-GL', 'nonsense', '', null, 42, ['ko'], true] as $junk) {
            I18n::setLocale($junk);
            $this->assertSame('zh_CN', I18n::locale(), '未知语言码必须静默回落到中文');
            $this->assertSame('zh-CN', I18n::htmlLang());
        }
    }

    /**
     * 值空着 / 值写坏了 / 整条 key 漏了——三种残缺都回落到**中文源**，绝不渲染空白。
     *
     * 夹具是**真实的 en 词表**，只把几条 key 弄残：`load()` 返回的就是词表文件的
     * 原样（`return is_array($catalog) ? $catalog : []`），所以「文件里写着 `''`
     * 或 `null`」是运行时真会遇到的状态，这里只是把它手工造出来。用真词表而不是
     * 手搭的桩，是为了夹具的键集/`_meta`/其余值都与线上一致——桩最容易在这一点上
     * 比真货宽松，于是测出一条线上不存在的通路。
     *
     * 这条用例曾依赖「交付时还有词表没填」这个临时状态：12 份词表填满后它自动
     * skip 了，回退路径随之失去唯一覆盖，而 skip 是诚实的（写明了理由），所以
     * 绿着的一轮里没有任何东西提醒过谁。夹具自造之后，它与仓库处在哪个阶段无关。
     */
    #[Test]
    public function emptyBrokenOrMissingValuesFallBackToTheChineseSource(): void
    {
        $zh = I18n::catalogOf(I18n::FALLBACK);

        I18n::setLocale('en');            // 先定语言：setLocale() 会清掉已载入的词表
        $en = I18n::catalogOf('en');      // 再取真实词表当夹具

        // 五种坏值，各自是真实的坏法：没填、写坏类型、整条漏掉
        $broken = [
            'nav.home' => '',             // 译者还没填
            'nav.runs' => null,           // YAML/PHP 配置里手滑写成 null
            'search.button' => ['Search'],   // 值写成了数组
            'search.placeholder' => 42,      // 或数字
            'run.col.ip' => false,           // 或布尔
        ];
        foreach ($broken as $key => $value) {
            $en[$key] = $value;
        }
        unset($en['nav.symbol']);         // 整条 key 漏掉
        self::setLoadedCatalog($en);

        foreach ($broken as $key => $value) {
            $this->assertSame($zh[$key], I18n::t($key), "{$key} 的值是残缺的，必须回落中文源而不是渲染空白");
        }
        $this->assertSame($zh['nav.symbol'], I18n::t('nav.symbol'), '当前词表里没有这个 key，必须回落中文源');

        // 没被动过的 key 仍取译文。少了这句，把 t() 改成「一律返回中文源」也能全绿。
        $this->assertSame('Request Log', I18n::t('runs.title'));
        $this->assertSame('Method', I18n::t('runs.col.method'));

        // html()/plain() 只是 t() + 一层转义，回落的同样要过转义
        $this->assertSame(I18n::escapeHtml($zh['nav.home']), I18n::html('nav.home'));
        $this->assertSame(I18n::escapePlain($zh['nav.symbol']), I18n::plain('nav.symbol'));

        // has() 问的是「词表或源词表里有没有这个 key」：值坏掉、或只在源里，都算有
        $this->assertTrue(I18n::has('nav.home'));
        $this->assertTrue(I18n::has('nav.symbol'));
    }

    /**
     * 装一份「已载入的词表」。`t()` 读的是私有静态 `$catalog`，没有公开入口——
     * 从磁盘换语言只能换成 lang/ 下真实存在的那 12 份，而它们现在都是完整的。
     */
    private static function setLoadedCatalog(array $catalog): void
    {
        (new \ReflectionProperty(I18n::class, 'catalog'))->setValue(null, $catalog);
    }

    // ---------------- 3. 转义策略 ----------------

    #[Test]
    public function onlyLineBreaksSurviveEscaping(): void
    {
        $raw = '<script>alert(1)</script><br>a<b>&"\'<br/>b<br />c';

        $html = I18n::escapeHtml($raw);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, '<script> 必须变实体');
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&amp;', $html);
        $this->assertStringContainsString('&quot;', $html);
        $this->assertStringContainsString('&#039;', $html);
        $this->assertSame(3, substr_count($html, '<br>'), '三种写法的折行标记都要还原成真 <br>');

        $plain = I18n::escapePlain($raw);
        $this->assertStringNotContainsString('<br>', $plain, '纯文本上下文里不能留 <br>');
        $this->assertStringNotContainsString('<script', $plain);
        $this->assertStringContainsString('&lt;script&gt;', $plain);
    }

    #[Test]
    public function theSourceCatalogKeepsItsLineBreaksThroughHtml(): void
    {
        // 中文列头「调用<br>次数」必须原样带 <br> 出来（这是 html() 存在的唯一理由）
        $this->assertSame('调用<br>次数', I18n::html('col.ct'));
        $this->assertSame('调用 次数', I18n::plain('col.ct'), 'plain() 把折行折成空格，给 <td>/属性用');
    }

    // ---------------- 4. 12 份词表的结构一致性 ----------------

    /** @return list<string> lang/ 下的语言码（由文件名得来） */
    private function onDiskLocales(): array
    {
        $codes = [];
        foreach (glob($this->langDir() . '/*.php') ?: [] as $file) {
            $codes[] = basename($file, '.php');
        }
        sort($codes);
        return $codes;
    }

    #[Test]
    public function everyAvailableLocaleHasExactlyOneCatalogFileAndNoStrayFiles(): void
    {
        $expected = I18n::AVAILABLE;
        sort($expected);
        $this->assertSame($expected, $this->onDiskLocales(), 'lang/ 下的词表文件与 I18n::AVAILABLE 不一致');
    }

    #[Test]
    public function everyCatalogHasExactlyTheChineseKeySetInTheSameOrder(): void
    {
        $reference = I18n::catalogOf(I18n::FALLBACK);
        $this->assertGreaterThan(40, count($reference), '中文源词表太小，夹具可能已失效');
        $this->assertArrayHasKey('_meta', $reference);

        foreach (I18n::AVAILABLE as $code) {
            $catalog = I18n::catalogOf($code);
            $this->assertSame(
                array_keys($reference),
                array_keys($catalog),
                "{$code} 的键集/顺序与中文源不一致——漏翻、多翻或改了 key 名都会在这里红"
            );
        }
    }

    #[Test]
    public function everyCatalogDeclaresItsOwnIdentity(): void
    {
        foreach (I18n::AVAILABLE as $code) {
            $meta = I18n::catalogOf($code)['_meta'] ?? null;
            $this->assertIsArray($meta, "{$code} 缺少 _meta");
            $this->assertSame(
                strtolower(str_replace('_', '-', $code)),
                strtolower((string) ($meta['lang'] ?? '')),
                "{$code} 的 _meta.lang 与文件名不符（从别处复制词表时最容易忘改这一项）"
            );
            $this->assertNotSame('', trim((string) ($meta['name'] ?? '')), "{$code} 缺少 _meta.name（语言自称）");
            $this->assertContains($meta['dir'] ?? null, ['ltr', 'rtl'], "{$code} 的 _meta.dir 只能是 ltr/rtl");
        }

        // 阿拉伯语必须声明 rtl，否则整页方向都是错的
        $this->assertSame('rtl', I18n::catalogOf('ar')['_meta']['dir']);
        I18n::setLocale('ar');
        $this->assertSame('rtl', I18n::dir());
        $this->assertSame('ar', I18n::htmlLang());
    }

    #[Test]
    public function noLocaleShipsTheChineseStringItselfAsATranslation(): void
    {
        $zh = I18n::catalogOf(I18n::FALLBACK);
        $keys = 0;
        foreach (I18n::AVAILABLE as $code) {
            if ($code === I18n::FALLBACK) {
                continue;
            }
            foreach (I18n::catalogOf($code) as $key => $value) {
                if ($key === '_meta' || !is_string($value) || $value === '') {
                    continue;   // 空值 = 还没翻，由 t() 回落中文源，不算「拿中文冒充译文」
                }
                $source = (string) ($zh[$key] ?? '');
                if (preg_match('/\p{Han}/u', $source) !== 1) {
                    continue;   // 源文本本身没有汉字（IUser%、IP 这类标识），照抄是正常的
                }
                $this->assertNotSame(
                    $source,
                    $value,
                    "{$code} 的 {$key} 与中文源逐字相同——这是「拿中文当译文」，不是翻译"
                );
                $keys++;
            }
        }
        $this->assertGreaterThan(0, $keys, '一份译文都没有，检查夹具');
    }

    #[Test]
    public function readingOneCatalogDoesNotDependOnTheCurrentLocale(): void
    {
        // catalogOf() 每次都重新读文件，不该被 setLocale 影响
        I18n::setLocale('ja');
        $this->assertSame('ko', I18n::catalogOf('ko')['_meta']['lang']);
        $this->assertSame('en', I18n::catalogOf('en')['_meta']['lang']);
    }

    // ---------------- 5. 列头与字面量表不许走岔 ----------------

    #[Test]
    public function theColumnKeysAreExactlyTheLiteralDescriptionsMap(): void
    {
        $zh = I18n::catalogOf(I18n::FALLBACK);
        $colKeys = [];
        foreach (array_keys($zh) as $key) {
            if (str_starts_with((string) $key, 'col.')) {
                $colKeys[] = substr((string) $key, 4);
            }
        }
        $this->assertSame(array_keys(XhprofDisplay::$descriptions), $colKeys, 'col.* 键集与 $descriptions 的键集必须一一对应');
        $this->assertNotEmpty($colKeys);
    }

    #[Test]
    public function theChineseCatalogAndTheLiteralMapStayInLockstep(): void
    {
        // 改了一边不改另一边会在这里红——否则报告页的中文会「一半新一半旧」，
        // 而两种写法各自都自洽，人工抽查发现不了。
        I18n::setLocale('zh_CN');
        foreach (XhprofDisplay::$descriptions as $stat => $literal) {
            $this->assertSame($literal, I18n::html('col.' . $stat), "col.{$stat} 与 \$descriptions 的字面量不一致");
        }
    }

    #[Test]
    public function everyColumnKeyResolvesForEveryLocale(): void
    {
        // 任何语言的列头都不能渲染出 "col.xxx" 这种 key 名（I18n::t 的最后一级兜底）
        foreach (I18n::AVAILABLE as $code) {
            I18n::setLocale($code);
            foreach (array_keys(XhprofDisplay::$descriptions) as $stat) {
                $this->assertStringNotContainsString('col.', I18n::html('col.' . $stat), "{$code} 的 col.{$stat} 取不到文案");
            }
        }
    }

    // ---------------- 6. 报告页真的会说这门语言（入口级） ----------------

    /** 起一个最小可渲染的报告页环境，返回 index() 的 HTML */
    private function renderReport(array $params, array $config = [], array $headers = []): string
    {
        $cache = new FakeCache();
        $request = new FakeRequest($params, ['uri' => '/xhprof', 'headers' => $headers]);
        $response = new FakeResponse();
        $config = new FakeConfig(['xhprof' => $config]);

        Xhprof::$time_limit = 0;
        Xhprof::$ignore_url_arr = ['/xhprof'];
        Xhprof::$key_prefix = 'xhprof';
        Xhprof::$view_wtred = 3;
        Xhprof::$ui_html = '';
        Xhprof::bootstrap($request, $response, $config, $cache, new FakeLogger());

        $html = Xhprof::index();
        $this->assertIsString($html, 'index() 必须返回 HTML 字符串（403/400 会返回 response 对象）');

        return $html;
    }

    #[Test]
    public function theReportPageRendersInTheRequestedLanguage(): void
    {
        $html = $this->renderReport(['lang' => 'en']);

        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertStringContainsString('<title>XHProf Performance Report</title>', $html);
        $this->assertStringContainsString('Home', $html);
        $this->assertStringNotContainsString('性能分析报告', $html);
        // 首页整页（导航、品牌、请求记录表）不许再有没接线的中文
        $this->assertNoHanCharacters($html, '首页');
    }

    /** @return array<string, array<string, mixed>> 一份最小的 xhprof run 数据 */
    private function sampleRunData(): array
    {
        return [
            'main()' => ['ct' => 1, 'wt' => 100000, 'mu' => 2048],
            'main()==>foo()' => ['ct' => 1, 'wt' => 40000, 'mu' => 512],
            'main()==>bar()' => ['ct' => 1, 'wt' => 30000, 'mu' => 256],
            'foo()==>strlen()' => ['ct' => 2, 'wt' => 5000, 'mu' => 64],
        ];
    }

    /** 断言整页 HTML 里没有汉字——英文页里剩下汉字，就说明有文案没接线 */
    private function assertNoHanCharacters(string $html, string $where): void
    {
        if (preg_match('/\p{Han}/u', $html, $m, PREG_OFFSET_CAPTURE) === 1) {
            $from = max(0, $m[0][1] - 60);
            $this->fail("{$where} 的英文页里还有汉字：…" . substr($html, $from, 140) . '…');
        }
        // 命中不了才算通过也要有断言，否则这条检查在空转
        $this->assertSame(0, preg_match('/\p{Han}/u', $html), "{$where} 的英文页里还有汉字");
    }

    #[Test]
    public function aWholeRunReportIsTranslatedNotJustItsTitle(): void
    {
        $runId = 'a1a1a1a1a1a1a1a1';
        $cache = new FakeCache();
        $request = new FakeRequest(['run' => $runId, 'all' => 1, 'lang' => 'en'], ['uri' => '/xhprof']);
        $config = new FakeConfig(['xhprof' => []]);

        Xhprof::$time_limit = 0;
        Xhprof::$ignore_url_arr = ['/xhprof'];
        Xhprof::$key_prefix = 'xhprof';
        Xhprof::$view_wtred = 3;
        Xhprof::$ui_html = '';
        Xhprof::bootstrap($request, new FakeResponse(), $config, $cache, new FakeLogger());
        I18n::setLocale('en');   // 直接渲染时不经 index()，语言得自己设（各入口级用例已覆盖 index() 那条路）

        $cache->set('xhprof:request_log:' . $runId, json_encode([
            'request_uri' => '/order?x=1',
            'method' => 'GET',
            'wt' => 0.8,
            'mu' => 2.0,
            'ip' => '6.6.6.6',
            'create_time' => 1700000000,
        ]));

        $html = XhprofDisplay::profiler_single_run_report(
            ['run' => $runId, 'all' => 1],
            $this->sampleRunData(),
            'desc',
            null,
            'wt',
            $runId
        );

        // 表格列头、搜索框、请求信息行都在这一页里
        $this->assertStringContainsString('placeholder="Find function/method..."', $html);
        $this->assertStringContainsString('>Search</button>', $html);
        $this->assertStringContainsString('Request Method', $html);
        $this->assertStringContainsString('Source IP', $html);
        $this->assertStringContainsString('Total Function/Method Calls', $html);
        $this->assertStringContainsString('Incl. Wall<br>(microsec)', $html, '列头的 <br> 折行必须原样出来');
        $this->assertStringNotContainsString('&lt;br&gt;', $html, '折行标记被当成普通文本转义了');
        $this->assertNoHanCharacters($html, 'run 报告');
    }

    #[Test]
    public function theNavigationBreadcrumbIsTranslatedInEveryVariant(): void
    {
        Xhprof::bootstrap(
            new FakeRequest([], ['uri' => '/xhprof']),
            new FakeResponse(),
            new FakeConfig(['xhprof' => []]),
            new FakeCache(),
            new FakeLogger()
        );
        I18n::setLocale('en');

        foreach ([
            ['run' => 'a1a1a1a1a1a1a1a1'],
            ['run' => 'a1a1a1a1a1a1a1a1', 'symbol' => 'foo()'],
            [],
        ] as $params) {
            $nav = XhprofDisplay::show_nav($params);
            $this->assertStringContainsString('Home', $nav);
            $this->assertNoHanCharacters($nav, '导航');
        }

        $this->assertStringContainsString('Run Report', XhprofDisplay::show_nav(['run' => 'a1a1a1a1a1a1a1a1']));
        $this->assertStringContainsString('Method Details', XhprofDisplay::show_nav(['run' => 'a1a1a1a1a1a1a1a1', 'symbol' => 'foo()']));
        $this->assertStringContainsString('XHProf Performance Analysis', XhprofDisplay::show_nav([]));
    }

    #[Test]
    public function theReportPageFollowsTheConfiguredLocaleWithoutAUrlParameter(): void
    {
        $html = $this->renderReport([], ['locale' => 'de']);

        $this->assertStringContainsString('<html lang="de">', $html);
    }

    #[Test]
    public function theReportPageNegotiatesFromTheBrowserAndFallsBackToChinese(): void
    {
        $html = $this->renderReport([], [], ['accept-language' => 'ja,en;q=0.8']);
        $this->assertStringContainsString('<html lang="ja">', $html);

        // 未知语言码 + 协商不出来的浏览器：照常出页，中文兜底
        $html = $this->renderReport(['lang' => 'kl-GL'], [], ['accept-language' => 'sv-SE,sv;q=0.9']);
        $this->assertStringContainsString('<html lang="zh-CN">', $html);
        $this->assertStringContainsString('<title>XHProf 性能分析报告</title>', $html);
    }

    #[Test]
    public function aRightToLeftLocaleTurnsTheWholePageAround(): void
    {
        $html = $this->renderReport(['lang' => 'ar']);

        $this->assertStringContainsString('<html lang="ar" dir="rtl">', $html);
        $this->assertStringNotContainsString('<html lang="ar">', $html);
    }

    #[Test]
    public function aBrokenConfigValueCannotMakeTheReportPageFatal(): void
    {
        // 配置项与查询串写成数组（PHP 配置 / query 解析出意外类型是常事）也必须是
        // 「中文兜底 + 照常出页」，不是 500
        $html = $this->renderReport(['lang' => ['en']], ['locale' => ['ko']]);

        $this->assertStringContainsString('<html lang="zh-CN">', $html);
        $this->assertStringContainsString('<title>XHProf 性能分析报告</title>', $html);
    }
}
