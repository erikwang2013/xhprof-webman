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

        $onDisk = [];
        foreach (glob($root . '/docs/i18n/*/README.md') ?: [] as $f) {
            $lang = basename(dirname($f));
            if ($lang === 'en') {
                continue;   // 基线目录，不是语言条目
            }
            $onDisk[] = $lang;
        }
        sort($onDisk);
        $this->assertNotEmpty($onDisk, 'docs/i18n/ 下没有任何译文，夹具已失效');

        preg_match_all('#\(\./docs/i18n/([a-z]+)/README\.md\)#', $switcher, $m);
        $linked = $m[1];
        sort($linked);

        $this->assertSame(
            $onDisk,
            $linked,
            '切换器列出的语言与 docs/i18n/ 下实际存在的译文不一致'
        );

        // 中文原版与英文版各有一条链接，且都从根 README 出发
        $this->assertStringContainsString('[English](./README.EN.md)', $switcher);
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
