<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Docs;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// 语言清单与文档清单必须与生成器**同源**：从 lib.php 派生而不是在测试里再写一份字面量，
// 否则被测对象就成了测试自己的影子——新增第 13 门语言时这里不跟，全量 --verify 少跑一门
// 而测试照样绿，正是这条契约要防的失效模式。
require_once dirname(__DIR__, 3) . '/tools/i18n/lib.php';

/**
 * `generate.php --verify` 的契约：交付树必须逐字节等于「重跑生成器会写出的东西」。
 *
 * 它补的是审计发现的那个洞——产物与源之间原本没有任何逐字比对：check.php 只看
 * 单份产物自身的结构，I18nParityTest 比的是形状（标题/围栏/表格行数/标识符存活），
 * 两者都看不见「有人手改了 docs/i18n/<lang>/README.md」或「改了
 * tools/i18n/readme/<lang>.md 却忘了重跑 generate.php」。
 *
 * 两条用例：①全量 --verify 现在必须绿，且**只读**（内容与 mtime 都不动——字节相同的
 * 重写也要露馅）；②它在该红时真的会红：临时手改一份真实产物（README 与一张 SVG 各一），
 * --verify 必须点名该文件与首个差异的字节/行号，还原后复绿。
 *
 * 备份与还原走 /tmp 副本，**不用 git checkout**：工作树里可能正躺着别人的在途修改，
 * checkout 会连它们一起抹掉；还原后按 sha1 核对字节，成功的"还原"不是感觉，是哈希。
 * 手改窗口只有几毫秒（写→跑一次单语言 --verify→还原，try/finally 保证异常也还原）。
 */
class GenerateVerifyTest extends TestCase
{
    /** @return array{0:int,1:string} 退出码与合并后的输出 */
    private static function verify(string ...$args): array
    {
        $out = [];
        $rc = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(I18N_DIR . '/generate.php')
            . ' --verify ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $rc);
        return [$rc, implode("\n", $out)];
    }

    /** @return string[] 交付语言：README 落在输出根下的那些（zh 是源，不在此列） */
    private static function deliveredLocales(): array
    {
        $langs = [];
        foreach (I18N_LANGUAGES as $code => [, $rootFile]) {
            if ($rootFile === null) {
                $langs[] = $code;
            }
        }
        return $langs;
    }

    /** 全树的 `路径 => [mtime, sha1]`——指纹连 mtime 一起比，字节相同的重写也躲不过。 */
    private static function fingerprint(): array
    {
        $h = [];
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(i18n_out_root(), \FilesystemIterator::SKIP_DOTS)
        ) as $f) {
            $h[$f->getPathname()] = [$f->getMTime(), (string) sha1_file($f->getPathname())];
        }
        ksort($h);
        return $h;
    }

    /**
     * 在 try/finally 里手改一份真实产物、跑一次单语言 --verify、无论成败都从 /tmp
     * 副本还原。字节核对（sha1）留给调用方：把断言放进 finally 会把 --verify 的
     * 真实输出与失败原因一起盖掉，红的现场就没了。
     *
     * @return array{0:int,1:string} 手改状态下的 --verify 结果
     */
    private static function verifyWhileEdited(string $rel, string $mutation): array
    {
        $path = I18N_REPO . '/' . $rel;
        $backup = sys_get_temp_dir() . '/genverify-' . getmypid() . '-' . basename($rel);
        file_put_contents($backup, (string) file_get_contents($path));
        try {
            file_put_contents($path, $mutation);
            return self::verify('--lang=en');
        } finally {
            file_put_contents($path, (string) file_get_contents($backup));
            @unlink($backup);
        }
    }

    #[Test]
    public function theDeliveredTreeIsByteIdenticalToWhatTheGeneratorWouldWrite(): void
    {
        $langs = self::deliveredLocales();
        $this->assertNotSame([], $langs, 'I18N_LANGUAGES 里没有交付语言，夹具已失效');

        $generated = count(I18N_DOCS) + 1;   // 每门语言由生成器写出的：三张图 + README
        // 交付树里每门语言还多两张本语种 UI 截图（runs-list / run-report）——不是本脚本
        // 生成的，但住在同一个目录里，指纹的普查口径得把它们算上。
        $shipped = $generated + 2;
        $before = self::fingerprint();
        $this->assertCount(count($langs) * $shipped, $before,
            'docs/i18n 的文件数不是「交付语言 × 6」（README + 3 SVG + 2 截图）——夹具或交付树已经不对了');

        [$rc, $out] = self::verify();   // 全量：不带 --lang
        $this->assertSame(0, $rc, "全量 generate.php --verify 非零退出：\n$out");
        $this->assertStringContainsString('RESULT: PASS', $out);
        // 派生出的语言清单漏一个，就少一整门语言的比对——按清单逐个点名，
        // 而不是只数总数。
        foreach ($langs as $code) {
            $this->assertStringContainsString("verify  $code ", $out, "全量 --verify 没有覆盖 $code");
        }
        $this->assertStringContainsString(
            sprintf('%d artefacts match', count($langs) * $generated), $out);

        // 只读：比较是"看"，不是"重写一遍再比"。字节相同的重写同样不许——
        // mtime 在指纹里。
        $this->assertSame($before, self::fingerprint(), '--verify 动了 docs/i18n（字节或 mtime 有变）');
    }

    #[Test]
    public function aHandEditedReadmeIsNamedWithTheFirstDifference(): void
    {
        $rel = 'docs/i18n/en/README.md';
        $path = I18N_REPO . '/' . $rel;
        $original = (string) file_get_contents($path);

        [$rc, $out] = self::verifyWhileEdited($rel, $original . "\nhand-edited line the generator would never write\n");

        $this->assertSame(sha1($original), (string) sha1_file($path), '从 /tmp 副本还原后字节对不上');
        $this->assertNotSame(0, $rc, "手改了产物而 --verify 绿了：\n$out");
        $this->assertStringContainsString($rel, $out, '红了但没点名文件');
        $this->assertMatchesRegularExpression('/first difference at byte \d+ \(line \d+\)/', $out);
        $this->assertStringContainsString('RESULT: FAIL', $out);

        // 还原之后必须复绿，否则上面那条红可能只是这棵树一直红着。
        [$rc, $out] = self::verify('--lang=en');
        $this->assertSame(0, $rc, "还原后 --verify 仍红：\n$out");
    }

    #[Test]
    public function aHandEditedSvgIsNamedWithTheFirstDifference(): void
    {
        $rel = 'docs/i18n/en/images/design.svg';
        $path = I18N_REPO . '/' . $rel;
        $original = (string) file_get_contents($path);

        // 追加一个注释：合法 XML、结构检查（check.php / I18nParityTest）完全看不见，
        // 正是 --verify 存在的理由。
        [$rc, $out] = self::verifyWhileEdited($rel, $original . "<!-- hand edit -->\n");

        $this->assertSame(sha1($original), (string) sha1_file($path), '从 /tmp 副本还原后字节对不上');
        $this->assertNotSame(0, $rc, "手改了 SVG 而 --verify 绿了：\n$out");
        $this->assertStringContainsString($rel, $out, '红了但没点名文件');
        $this->assertMatchesRegularExpression('/first difference at byte \d+ \(line \d+\)/', $out);

        [$rc, $out] = self::verify('--lang=en');
        $this->assertSame(0, $rc, "还原后 --verify 仍红：\n$out");
    }
}
