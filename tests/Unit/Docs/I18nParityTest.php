<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Docs;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 多语言 README 的结构一致性。
 *
 * 这里**只**断言骨架：标题数量与层级序列、代码围栏数、表格行数。**它不、也不能**
 * 断言译文是否读得通——没有任何脚本能判断含义、语域或自然度，本文件不假装可以。
 *
 * 存在的理由：13 份译文由 12 个独立 agent 从 `README.EN.md` 译出，**一旦有人改了
 * 英文源而漏改某一份译文**，译文就会与源结构性脱节，而那种脱节在人工抽查里
 * 几乎发现不了（每一份单看都自洽）。这条测试让它机械可发现。
 *
 * 行数刻意**不**断言：各语言的机器翻译声明长短不同（1–2 行），行数天然有差。
 */
class I18nParityTest extends TestCase
{
    /** @return string[] 仓库根到各 README 的相对路径 */
    private static function readmes(): array
    {
        $root = dirname(__DIR__, 3);
        $files = [$root . '/README.md', $root . '/README.EN.md'];
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
        $this->assertGreaterThanOrEqual(14, count($files), '至少应有 2 份根 README + 12 份译文');

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
     * 根 README 的语言切换器必须**恰好**列出实际存在的译文，不多不少。
     *
     * 两条方向都要拦：写了不存在的语言 → 读者点进去是 404；加了译文却忘了挂链接
     * → 那份译文没有任何入口。后者尤其隐蔽，因为文件在盘上、测试全绿。
     *
     * **`en` 目录除外**：`docs/i18n/en/` 是英文试点，同时充当 `tools/i18n/check.php`
     * 的**宽度/重叠基线**（它读 `docs/i18n/en/images/`）。切换器里 English 那一项指向
     * 仓库根的 `./README.EN.md`，所以这个目录不是「一个可切换的语言条目」。
     * 本测试第一次跑就红在这里——它当时抓到的正是这个未挂链接的目录，不是误报。
     */
    #[Test]
    public function rootSwitcherListsExactlyTheLocalesThatExist(): void
    {
        $root = dirname(__DIR__, 3);
        $switcher = self::switcherLine($root . '/README.md');
        $onDisk = self::localeDirs($root);
        $this->assertNotEmpty($onDisk, 'docs/i18n/ 下没有任何译文，夹具已失效');

        $this->assertSame([], self::switcherProblems($switcher, $onDisk, 'zh'));
    }

    /**
     * `README.EN.md` 那一行切换器必须与中文那份**互为镜像**。
     *
     * **这条补的是一个真实漏掉的档**：上面的用例只读 `README.md`，
     * `everyLocaleReadmeCarriesTheLanguageSwitcher` 又显式跳过 `en`（它是基线目录而非
     * 译文），于是**英文根 README 的切换器一个检查都没有**——新增一门语言时，
     * 只改中文那份、忘了英文那份，13 条链接里少一条，全部门禁照样绿，
     * 而读英文的（外国）读者恰恰是最依赖切换器的那批人。
     *
     * 判据与中文那份共用 `switcherProblems()`：列出的语言 = `docs/i18n/` 下实际存在的
     * 译文（不含 `en` 自己），另有指向另一份根 README 的入口，且不链自己。
     */
    #[Test]
    public function theEnglishRootSwitcherIsTheMirrorOfTheChineseOne(): void
    {
        $root = dirname(__DIR__, 3);
        $switcher = self::switcherLine($root . '/README.EN.md');
        $onDisk = self::localeDirs($root);
        $this->assertNotEmpty($onDisk, 'docs/i18n/ 下没有任何译文，夹具已失效');

        $this->assertSame([], self::switcherProblems($switcher, $onDisk, 'en'));
    }

    /**
     * 切换器行的判据本体（中文那份与英文那份**同一条规则**，两份 README 只是 `$selfCode`
     * 不同）：中英各写一套正则迟早分叉，而分叉的那一侧就是没人守的那一侧。
     *
     * @param string[] $localeDirs docs/i18n/ 下实际存在的语言码（不含 en）
     * @param string $selfCode 'zh' 或 'en'——这一行所在 README 自己代表的语言条目
     * @return list<string> 不合格之处；空数组 = 这一行是合格的
     */
    private static function switcherProblems(string $line, array $localeDirs, string $selfCode): array
    {
        if ($line === '') {
            return ['找不到切换器那一行（含 `](./docs/i18n/` 与 ` · ` 的那一行）'];
        }

        $selfRoot = $selfCode === 'zh' ? './README.md' : './README.EN.md';
        $otherEntry = $selfCode === 'zh' ? '[English](./README.EN.md)' : '[中文](./README.md)';
        $selfEndonym = $selfCode === 'zh' ? '**中文**' : '**English**';

        $problems = [];

        preg_match_all('#\(\./docs/i18n/([a-z]+)/README\.md\)#', $line, $m);
        $linked = $m[1];
        sort($linked);
        if ($linked !== $localeDirs) {
            $problems[] = sprintf(
                '切换器列出的语言与 docs/i18n/ 下实际存在的译文不一致：漏了 [%s]，多了 [%s]',
                implode(', ', array_diff($localeDirs, $linked)),
                implode(', ', array_diff($linked, $localeDirs))
            );
        }

        if (!str_contains($line, $otherEntry)) {
            $problems[] = "切换器里没有另一份根 README 的入口 {$otherEntry}";
        }
        if (!str_contains($line, $selfEndonym)) {
            $problems[] = "切换器没有把自己标成 {$selfEndonym}（当前页应当是加粗、不链接的）";
        }
        if (str_contains($line, "({$selfRoot})")) {
            $problems[] = "切换器链到了自己（{$selfRoot}）";
        }

        return $problems;
    }

    /**
     * `docs/i18n/` 下实际存在的语言码，**不含 `en`**：那个目录是英文试点，同时充当
     * `tools/i18n/check.php` 的宽度/重叠基线，切换器里 English 那一项指向仓库根的
     * `./README.EN.md`，所以它不是「一个可切换的语言条目」。
     *
     * @return list<string>
     */
    private static function localeDirs(string $root): array
    {
        $codes = [];
        foreach (glob($root . '/docs/i18n/*/README.md') ?: [] as $f) {
            $lang = basename(dirname($f));
            if ($lang !== 'en') {
                $codes[] = $lang;
            }
        }
        sort($codes);
        return $codes;
    }

    /** 取出根 README 里那一行切换器（它以加粗的当前页自称开头，含指向 docs/i18n/ 的链接） */
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

    /** @return array<string, string> 语言码 => 译文正文（不含 en：那是基线，不是译文） */
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
        $source = (string) file_get_contents($root . '/README.EN.md');
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
        $source = (string) file_get_contents($root . '/README.EN.md');
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
     * 检查有效性的证明：拿**真实那一行**在内存里改一处（去掉一条语言链接 / 删掉另一份根
     * README 的入口），`switcherProblems()` 必须报出来；没改过的两份必须报空。
     *
     * 不落盘改 README*.md：那是别的 agent 正在写的文件。
     */
    #[Test]
    public function theSwitcherGateGoesRedOnABrokenSwitcherLine(): void
    {
        $root = dirname(__DIR__, 3);
        $onDisk = self::localeDirs($root);
        $zh = self::switcherLine($root . '/README.md');
        $en = self::switcherLine($root . '/README.EN.md');

        // 夹具本身必须先合格，否则下面的红说明不了任何事
        $this->assertSame([], self::switcherProblems($zh, $onDisk, 'zh'));
        $this->assertSame([], self::switcherProblems($en, $onDisk, 'en'));

        // (1) 少一条语言链接（新增一门语言时只改了另一份 README 的样子）
        $broken = preg_replace('# · \[한국어\]\(\./docs/i18n/ko/README\.md\)#u', '', $en, 1, $n);
        $this->assertSame(1, $n, '英文切换器里找不到 한국어 那一条，夹具已失效');
        $problems = self::switcherProblems((string) $broken, $onDisk, 'en');
        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('ko', implode("\n", $problems), '漏掉的语言要出现在报错里');

        // (2) 少了另一份根 README 的入口（中英互跳的那一条）
        $problems = self::switcherProblems(str_replace('[中文](./README.md)', '[中文](./zh/README.md)', $en), $onDisk, 'en');
        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('[中文](./README.md)', implode("\n", $problems));

        // (3) 链到自己（当前页那条本该是加粗的）
        $problems = self::switcherProblems(str_replace('**中文**', '[中文](./README.md)', $zh), $onDisk, 'zh');
        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('自己', implode("\n", $problems));
    }

    /**
     * 每一份译文 README 都必须自带语言切换器。
     *
     * **这条补的是一个真实漏掉的档**：一份在「生成器开始注入切换器」之前产出的译文，
     * 它自身的 6 条链接（`./images/*.svg`、`../../../docs/*.png`…）照样解析得动，
     * `tools/i18n/check.php` 照样全绿——**但读者在这份译文里没有任何切换到别的语言的入口**。
     * `i18n-ru` 就是这样发现自己的交付落后了工具一个特性（靠起了一份 /tmp 副本做实验）。
     *
     * 判据用「至少 10 条指向兄弟语言的链接」而不是精确值：语言数量会增加，
     * 精确值会让新增一门语言时这条测试变成维护负担。
     */
    #[Test]
    public function everyLocaleReadmeCarriesTheLanguageSwitcher(): void
    {
        $root = dirname(__DIR__, 3);
        $checked = 0;

        foreach (glob($root . '/docs/i18n/*/README.md') ?: [] as $f) {
            $lang = basename(dirname($f));
            if ($lang === 'en') {
                continue;   // 基线目录，不是语言条目
            }
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
        $this->assertGreaterThanOrEqual(11, $checked, '受检译文少于 11 份，夹具已失效');
    }
}
