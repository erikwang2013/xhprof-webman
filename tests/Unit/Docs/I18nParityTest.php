<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Docs;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// 切换器那一行的判据必须与生成器**同源**：另写一套正则迟早分叉，分叉的那一侧就是
// 没人守的那一侧（本仓库已有一次中英各写一套、结果英文那份无人守的教训）。
require_once dirname(__DIR__, 3) . '/tools/i18n/lib.php';

/**
 * 多语言 README 的结构一致性。
 *
 * 这里**只**断言骨架：标题数量与层级序列、代码围栏数、表格行数。**它不、也不能**
 * 断言译文是否读得通——没有任何脚本能判断含义、语域或自然度，本文件不假装可以。
 *
 * 存在的理由：12 份译文由独立 agent 从英文**源**译出，**一旦有人改了英文源而漏改
 * 某一份译文**，译文就会与源结构性脱节，而那种脱节在人工抽查里几乎发现不了
 * （每一份单看都自洽）。这条测试让它机械可发现。
 *
 * 基线读的是手写源，不是产物：
 *   * 中文源 = 仓库根 `README.md`；
 *   * 英文源 = `tools/i18n/readme/en.md`（**不是**产物 `docs/i18n/en/README.md`——
 *     产物经生成器改写：链接前缀 `../`、前面注入切换器，拿它当基线会造出大片假红）。
 *
 * 行数刻意**不**断言：各语言的机器翻译声明长短不同（1–2 行），行数天然有差。
 */
class I18nParityTest extends TestCase
{
    /** @return string[] 仓库根到各 README 的相对路径（中文源 + 英文源 + 12 份产物） */
    private static function readmes(): array
    {
        $root = dirname(__DIR__, 3);
        // 两份手写源排在前面，$files[0] 是基线；12 份产物由 glob 收进来。
        $files = [$root . '/README.md', $root . '/tools/i18n/readme/en.md'];
        foreach (glob($root . '/docs/i18n/*/README.md') ?: [] as $f) {
            $files[] = $f;
        }
        return $files;
    }

    /** @return array{0:int[],1:int,int} [标题层级序列, 围栏数, 表格行数] */
    private static function shape(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $levels = [];
        $fences = 0;
        $rows = 0;
        foreach ($lines as $line) {
            if (preg_match('/^(#{1,6}) /', $line, $m) === 1) {
                $levels[] = strlen($m[1]);
            }
            if (str_starts_with(ltrim($line), '```')) {
                $fences++;
            }
            if (str_starts_with(trim($line), '|')) {
                $rows++;
            }
        }
        return [$levels, $fences, $rows];
    }

    #[Test]
    public function allReadmesShareTheSameStructure(): void
    {
        $files = self::readmes();
        $this->assertGreaterThanOrEqual(14, count($files), '至少应有 2 份手写源 + 12 份产物');

        [$refLevels, $refFences, $refRows] = self::shape($files[0]);

        // 先钉住基线本身不是空的：结构全为空时，下面每一条「相等」都会平凡成立
        $this->assertGreaterThan(10, count($refLevels), '基线 README 的标题数异常，夹具可能已失效');
        $this->assertGreaterThan(10, $refFences, '基线 README 的围栏数异常，夹具可能已失效');

        foreach ($files as $f) {
            $rel = str_replace(dirname(__DIR__, 3) . '/', '', $f);
            [$levels, $fences, $rows] = self::shape($f);

            $this->assertSame(
                $refLevels,
                $levels,
                "{$rel} 的标题层级序列与基线不一致——译文与英文源结构性脱节了"
            );
            $this->assertSame($refFences, $fences, "{$rel} 的代码围栏数与基线不一致");
            $this->assertSame($refRows, $rows, "{$rel} 的表格行数与基线不一致");
        }
    }

    /**
     * 仓库根 README 的语言切换器必须**恰好**列出 `docs/i18n/` 下实际存在的语言条目，
     * 不多不少。
     *
     * 两条方向都要拦：写了不存在的语言 → 读者点进去是 404；加了目录却忘了挂链接
     * → 那份译文没有任何入口。后者尤其隐蔽，因为文件在盘上、测试全绿。
     *
     * 根那份切换器是全仓库唯一**手写**的（其余 12 份由生成器写），所以只有它需要这条
     * 测试。`en` 自英文 README 搬进 `docs/` 之后就是普通的一员：English 那一项指向
     * `./docs/i18n/en/README.md`，不再指向已删除的仓库根 `README.EN.md`。
     */
    #[Test]
    public function rootSwitcherListsExactlyTheLocalesThatExist(): void
    {
        $root = dirname(__DIR__, 3);
        $switcher = self::switcherLine($root . '/README.md');
        $onDisk = self::localeDirs($root);
        $this->assertNotEmpty($onDisk, 'docs/i18n/ 下没有任何语言条目，夹具已失效');

        $this->assertSame([], self::switcherProblems($switcher, $onDisk));
    }

    /**
     * 每一份产物的切换器必须**逐字**是语言表算出来的那一行。
     *
     * 判据与生成器**同源**（`i18n_language_switcher()`，见文件顶部 require）：另写一套
     * 正则迟早分叉，分叉的那一侧就是没人守的那一侧。这条接替了「英文根 README 的切换器
     * 与中文互为镜像」——英文 README 现在住在 `docs/i18n/en/` 里、由同一张表生成，
     * 镜像关系不再靠两份手写文档对齐，而靠这一行相等。
     *
     * 它同时是「产物落后于工具链」的探测器：语言表改了（加一门语言、改个自称）而某一份
     * 产物没重跑，这里就红——而读英文的（外国）读者恰恰是最依赖切换器的那批人。
     */
    #[Test]
    public function everyLocaleReadmeCarriesTheSwitcherTheLanguageTableDescribes(): void
    {
        $root = dirname(__DIR__, 3);
        $checked = 0;

        foreach (glob($root . '/docs/i18n/*/README.md') ?: [] as $f) {
            $lang = basename(dirname($f));
            // 前后各补一个换行再找整行：切换器既可能在第一行（无声明），也可能在
            // 声明之后（第 3 行），用「整行相等」把两种情况一起管住。
            $this->assertStringContainsString(
                "\n" . i18n_language_switcher($lang) . "\n",
                "\n" . (string) file_get_contents($f),
                "docs/i18n/{$lang}/README.md 的切换器不是语言表算出来的那一行——"
                . '要么产物落后于 tools/i18n/lib.php（重跑 generate.php --lang=' . $lang . '），要么有人手改了它'
            );
            $checked++;
        }

        // 没有这一句，docs/i18n/ 为空时本测试会平凡通过
        $this->assertGreaterThanOrEqual(12, $checked, '受检产物少于 12 份，夹具已失效');
    }

    /**
     * 切换器行的判据本体：列出的语言 = `docs/i18n/` 下实际存在的语言条目，自己那条
     * 加粗不链接，也不链自己。
     *
     * @param string[] $localeDirs docs/i18n/ 下实际存在的语言码（含 en）
     * @return list<string> 不合格之处；空数组 = 这一行是合格的
     */
    private static function switcherProblems(string $line, array $localeDirs): array
    {
        if ($line === '') {
            return ['找不到切换器那一行（含 `](./docs/i18n/` 与 ` · ` 的那一行）'];
        }

        $problems = [];

        preg_match_all('#\(\./docs/i18n/([a-z]+)/README\.md\)#', $line, $m);
        $linked = $m[1];
        sort($linked);
        if ($linked !== $localeDirs) {
            $problems[] = sprintf(
                '切换器列出的语言与 docs/i18n/ 下实际存在的条目不一致：漏了 [%s]，多了 [%s]',
                implode(', ', array_diff($localeDirs, $linked)),
                implode(', ', array_diff($linked, $localeDirs))
            );
        }

        if (!str_contains($line, '**中文**')) {
            $problems[] = '切换器没有把自己标成 **中文**（当前页应当是加粗、不链接的）';
        }
        if (str_contains($line, '(./README.md)')) {
            $problems[] = '切换器链到了自己（./README.md）';
        }

        return $problems;
    }

    /**
     * `docs/i18n/` 下实际存在的语言码，**含 `en`**：英文 README 搬进 `docs/` 之后，
     * 它和其余 11 份一样是切换器里的一个语言条目（`./docs/i18n/en/README.md`），
     * 不再是「仓库根那份英文 README 的影子」。
     *
     * @return list<string>
     */
    private static function localeDirs(string $root): array
    {
        $codes = [];
        foreach (glob($root . '/docs/i18n/*/README.md') ?: [] as $f) {
            $codes[] = basename(dirname($f));
        }
        sort($codes);
        return $codes;
    }

    /**
     * 取出根 README 里那一行切换器（它以加粗的当前页自称开头，含指向 docs/i18n/ 的链接）。
     *
     * 只用于仓库根那份手写切换器；产物那份由 `everyLocaleReadmeCarriesTheSwitcher…`
     * 逐字比对，不需要在这里找行。
     */
    private static function switcherLine(string $path): string
    {
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_contains($line, '](./docs/i18n/') && str_contains($line, ' · ')) {
                return $line;
            }
        }
        return '';
    }

    /**
     * 英文源里那些**不该被翻译**的内联代码标识符：反引号里、纯 ASCII、无空白，
     * 且至少含一个非字母数字字符——`Xhprof::bootstrap()`、`tools/contracts/`、
     * `framework-stubs.php` 都在这一档里。
     *
     * 为什么判据是这样：`::`、`/`、`(`、`.`、`_` 只出现在 API 名、路径、函数签名上，
     * 译文本该逐字照抄；而纯字母数字的串（`true`、`admin`）在译文里可能有合理写法，
     * 按词判会造出一堆假红。
     *
     * @return list<string> 去重后的标识符，按首次出现顺序
     */
    private static function invariantIdentifiers(string $md): array
    {
        preg_match_all('/`([^`\n]+)`/', $md, $m);

        $out = [];
        foreach ($m[1] as $tok) {
            if (preg_match('/^[\x20-\x7E]+$/', $tok) !== 1) {
                continue;   // 含非 ASCII —— 里面本来就有要翻译的东西
            }
            if (preg_match('/\s/', $tok) === 1) {
                continue;   // 带空格的整句/命令行，措辞可以不同
            }
            if (preg_match('/[^A-Za-z0-9]/', $tok) !== 1) {
                continue;   // 纯字母数字，见上面
            }
            $out[$tok] = true;
        }

        return array_keys($out);
    }

    /**
     * @param array<string, string> $translations 语言码 => 译文正文
     * @return array<string, list<string>> 语言码 => 英文源里有、这份译文里一次都没出现的标识符
     */
    private static function missingIdentifiers(string $sourceMd, array $translations): array
    {
        $identifiers = self::invariantIdentifiers($sourceMd);

        $missing = [];
        foreach ($translations as $lang => $body) {
            $gone = [];
            foreach ($identifiers as $tok) {
                if (!str_contains($body, $tok)) {
                    $gone[] = $tok;
                }
            }
            if ($gone !== []) {
                $missing[$lang] = $gone;
            }
        }

        return $missing;
    }

    /**
     * 英文**源**正文（手写的那份）。产物 `docs/i18n/en/README.md` **不是**它：产物的
     * 链接被生成器改写过（加 `../` 前缀）、前面还注入了切换器，拿产物当基线会造出
     * 大片假红。
     */
    private static function englishSource(string $root): string
    {
        return (string) file_get_contents($root . '/tools/i18n/readme/en.md');
    }

    /**
     * @return array<string, string> 语言码 => 产物正文（含 en：它的产物同样是生成出来的，
     *                             源码里的标识符一个都不能丢，理由见上面的用例）
     */
    private static function translations(string $root): array
    {
        $out = [];
        foreach (self::localeDirs($root) as $lang) {
            $out[$lang] = (string) file_get_contents($root . "/docs/i18n/{$lang}/README.md");
        }
        return $out;
    }

    /**
     * 英文源里的标识符必须在**每一份**译文里都还活着。
     *
     * **这条补的是一个真实漏掉的档**：现有的结构检查（标题序列/围栏数/表格行数）
     * 只数个数，看不出「整整一段被删掉了」——删掉一段话，标题、围栏、表格的计数
     * 往往一个不变，`tools/i18n/check.php` 的 SVG 术语表检查也管不到 README 正文。
     * 一份译文因此可以静默地少讲一整节，而读者看到的是一份「看起来完整」的文档。
     *
     * 判据取「英文源里那些语言无关的标识符一个都不能少」：段落没了，段里的 API 名、
     * 路径、函数签名多半跟着没了——而这些串在任何语言里都长一个样，丢了就是丢了。
     *
     * 边界（说清楚，别让这条检查显得比它实际强）：只带反引号标识符的段落丢了会被抓到；
     * 纯散文的段落丢了抓不到——没有任何脚本能判断「这段意思还在不在」。
     */
    #[Test]
    public function everyTranslationKeepsTheIdentifiersOfTheEnglishSource(): void
    {
        $root = dirname(__DIR__, 3);
        $source = self::englishSource($root);
        $identifiers = self::invariantIdentifiers($source);
        $this->assertGreaterThan(100, count($identifiers), '英文源里解析出的标识符少于 100 个，判据或夹具已失效');

        $translations = self::translations($root);
        $this->assertGreaterThanOrEqual(11, count($translations), '受检译文少于 11 份，夹具已失效');

        $missing = self::missingIdentifiers($source, $translations);
        $detail = [];
        foreach ($missing as $lang => $toks) {
            $detail[] = sprintf('%s（%d 个）: %s', $lang, count($toks), implode('、', array_slice($toks, 0, 5)));
        }

        $this->assertSame(
            [],
            $missing,
            "英文源里出现过、这些译文里一次都没出现的标识符——多半是整段漏译：\n" . implode("\n", $detail)
        );
    }

    /**
     * 检查有效性的证明：同一条函数、真实文档，在内存里删掉一个标识符 → 该语言必须被点名，
     * 没动过的语言不许被牵连；英文源新增一个标识符而 12 份译文都还没有 → 12 份全报。
     */
    #[Test]
    public function theIdentifierGateGoesRedWhenAParagraphIsDropped(): void
    {
        $root = dirname(__DIR__, 3);
        $source = self::englishSource($root);
        $translations = self::translations($root);
        $this->assertGreaterThanOrEqual(11, count($translations), '受检译文少于 11 份，夹具已失效');
        $this->assertSame([], self::missingIdentifiers($source, $translations), '真实译文必须先干净，否则下面的红说明不了任何事');

        // (1) 一份译文里整段漏译（等价于那个段落里的标识符全没了）
        $this->assertContains('Xhprof::bootstrap()', self::invariantIdentifiers($source), '英文源里没有这个标识符，判据或夹具已失效');
        $broken = $translations;
        $this->assertStringContainsString('Xhprof::bootstrap()', $broken['fr'], 'fr 里本来就没有这个标识符，夹具已失效');
        $broken['fr'] = str_replace('Xhprof::bootstrap()', 'Xhprof bootstrap', $broken['fr']);

        $missing = self::missingIdentifiers($source, $broken);
        $this->assertContains(
            'Xhprof::bootstrap()',
            $missing['fr'] ?? [],
            '译文里丢掉的标识符必须被点出来'
        );
        $this->assertArrayNotHasKey('de', $missing, '没动过的译文被误报了');

        // (2) 英文源新加了一个标识符、译文还没跟上（新章节刚写、译文还没重跑的样子）
        $grown = $source . "\n\n新增一句：`Xhprof::neverTranslatedYet()`。\n";
        $missing = self::missingIdentifiers($grown, $translations);
        $this->assertCount(count($translations), $missing, '英文源新增的标识符，每一份译文都该被点名');
        foreach ($missing as $lang => $toks) {
            $this->assertContains('Xhprof::neverTranslatedYet()', $toks, "{$lang} 没被点名");
        }
    }

    /**
     * 检查有效性的证明：拿**真实那一行**（仓库根那份手写的）在内存里改一处，
     * `switcherProblems()` 必须报出来；没改过的必须报空。
     *
     * 不落盘改 README.md：那是别的 agent 正在写的文件。
     */
    #[Test]
    public function theSwitcherGateGoesRedOnABrokenSwitcherLine(): void
    {
        $root = dirname(__DIR__, 3);
        $onDisk = self::localeDirs($root);
        $zh = self::switcherLine($root . '/README.md');

        // 夹具本身必须先合格，否则下面的红说明不了任何事
        $this->assertSame([], self::switcherProblems($zh, $onDisk));

        // (1) 少一条语言链接（新增一门语言时只改了另一份 README 的样子）
        $broken = preg_replace('# · \[한국어\]\(\./docs/i18n/ko/README\.md\)#u', '', $zh, 1, $n);
        $this->assertSame(1, $n, '切换器里找不到 한국어 那一条，夹具已失效');
        $problems = self::switcherProblems((string) $broken, $onDisk);
        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('ko', implode("\n", $problems), '漏掉的语言要出现在报错里');

        // (2) 链到自己（当前页那条本该是加粗的）
        $problems = self::switcherProblems(str_replace('**中文**', '[中文](./README.md)', $zh), $onDisk);
        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('自己', implode("\n", $problems));

        // (3) English 那一项还指着已删除的仓库根 README.EN.md —— 本次搬移最可能的
        //     残留形态：文件搬走了，链接没跟着改，读者点进去是 404。
        $stale = str_replace('[English](./docs/i18n/en/README.md)', '[English](./README.EN.md)', $zh);
        $this->assertNotSame($zh, $stale, '切换器里没有 English 那一条，夹具已失效');
        $problems = self::switcherProblems($stale, $onDisk);
        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('en', implode("\n", $problems), '漏掉的 en 要出现在报错里');
    }

    /**
     * 每一份产物 README 都必须自带语言切换器（`en` 也算一份：它是产物，不再是仓库根
     * 那份英文 README 的影子）。
     *
     * **这条补的是一个真实漏掉的档**：一份在「生成器开始注入切换器」之前产出的译文，
     * 它自身的 6 条链接（`./images/*.svg`、`../../../docs/*.png`…）照样解析得动，
     * `tools/i18n/check.php` 照样全绿——**但读者在这份译文里没有任何切换到别的语言的入口**。
     * `i18n-ru` 就是这样发现自己的交付落后了工具一个特性（靠起了一份 /tmp 副本做实验）。
     *
     * 判据用「至少 10 条指向兄弟语言的链接」而不是精确值：语言数量会增加，
     * 精确值会让新增一门语言时这条测试变成维护负担。（切换器那一行的**内容**由
     * `everyLocaleReadmeCarriesTheSwitcherTheLanguageTableDescribes` 逐字比对。）
     */
    #[Test]
    public function everyLocaleReadmeCarriesTheLanguageSwitcher(): void
    {
        $root = dirname(__DIR__, 3);
        $checked = 0;

        foreach (glob($root . '/docs/i18n/*/README.md') ?: [] as $f) {
            $lang = basename(dirname($f));
            $body = file_get_contents($f) ?: '';
            // 统计指向**其它**语言的链接：排除指向自身的那一条
            preg_match_all('#\(\.\./([a-z0-9-]+)/README\.md\)#', $body, $m);
            $siblings = array_filter($m[1], static fn(string $l): bool => $l !== $lang);

            $this->assertGreaterThanOrEqual(
                10,
                count($siblings),
                "docs/i18n/{$lang}/README.md 里指向其它语言的链接只有 "
                . count($siblings) . ' 条——它很可能是在切换器特性之前生成的（过期产物）'
            );
            $checked++;
        }

        // 没有这一句，docs/i18n/ 为空时本测试会平凡通过
        $this->assertGreaterThanOrEqual(12, $checked, '受检产物少于 12 份，夹具已失效');
    }
}
