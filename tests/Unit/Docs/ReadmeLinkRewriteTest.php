<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Docs;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// 语言/文档清单与生成器同源：从 lib.php 派生，而不是在测试里再写一份字面量。
require_once dirname(__DIR__, 3) . '/tools/i18n/lib.php';

/**
 * generate.php 链接改写契约里新加的一条：源里以 **`docs/i18n/<本语种>/`** 开头的
 * 链接，在本语种产物里必须写成相对产物目录的短路径（`docs/i18n/en/images/x.png`
 * → `images/x.png`）。
 *
 * 为什么源里写全路径：每份源文（tools/i18n/readme/<lang>.md）在 GitHub 上也是给人
 * 读的，`docs/i18n/ko/images/…` 从仓库根看是对的；只有产物 README 住在
 * `docs/i18n/<lang>/` 里，那个前缀才是多余的——目标就在隔壁。而「别的语种」的
 * 路径不是本文件的邻居：`docs/i18n/ko/README.md` 里的 en 截图必须继续写成仓库根
 * 相对的长路径（`../../../docs/i18n/en/images/…`），剥前缀会把它指到
 * `docs/i18n/ko/en/images/…` 这个不存在的地方。
 *
 * 同一份夹具跑两门语言（en、ko），角色对调：各自的**本语种**那行必须变短、**对方**
 * 那行必须保持长路径。只测一门语言的话，「规则键的是当前语种」与「规则把某个写死的
 * 语种当特例」这两种实现分不开——对调那一轮就是判别性输入。
 *
 * 端到端跑真实 generate.php，输入根（I18N_INPUT_ROOT）与输出根（I18N_OUT_ROOT）
 * 都指向 .selftest/ 下的本进程草稿（selftest.php 的既有做法），交付树一个字节不碰。
 * 输出根深度刻意与 docs/i18n 相同（`.selftest/<name>` 一个斜杠 +1），所以断言里的
 * 长路径与真实产物逐字相同，不是「按深度推导出来的近似」。
 */
class ReadmeLinkRewriteTest extends TestCase
{
    private const SCRATCH_TOP = I18N_REPO . '/.selftest';

    /**
     * 合成夹具：刻意不复制任何真实源文。真实源里的图片行今后会从 `docs/images/…`
     * 改成 `docs/i18n/<lang>/…`（这正催生了本规则），把夹具钉在今天某份源文的形状上，
     * 改源的那天夹具就失效了。这里只要把三种改写各自的判别性输入都摆进去。
     */
    private const FIXTURE = "# Link rewrite fixture\n\n"
        . "![own screenshot](docs/i18n/en/images/runs-list.png)\n\n"
        . "![other screenshot](docs/i18n/ko/images/runs-list.png)\n\n"
        . "![pet](docs/images/pet.svg)\n\n"
        . "![architecture](docs/images/architecture.svg)\n\n"
        . "[pet again](./docs/images/pet.svg)\n";

    /** @return array{0:int,1:string} 退出码与合并后的输出 */
    private static function generate(string $lang, string $in, string $out): array
    {
        $lines = [];
        $rc = 0;
        exec('I18N_INPUT_ROOT=' . escapeshellarg($in) . ' I18N_OUT_ROOT=' . escapeshellarg($out)
            . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(I18N_DIR . '/generate.php')
            . ' --lang=' . escapeshellarg($lang) . ' 2>&1', $lines, $rc);
        return [$rc, implode("\n", $lines)];
    }

    #[Test]
    public function aLocalesOwnDeliveredPathsLoseTheirPrefixAndNothingElseDoes(): void
    {
        $scratch = self::SCRATCH_TOP . '/link-rewrite-' . getmypid();
        $in = $scratch . '/in';
        // 输出根就是草稿目录本身（深度 3，与 docs/i18n 相同），不再多套一层：
        // 多一层就让每条断言里的 `../../../` 变成 `../../../../`，而「与真实产物
        // 逐字相同」正是这组断言想要的。
        $out = $scratch;
        mkdir($in . '/glossary', 0755, true);
        mkdir($in . '/readme', 0755, true);
        try {
            foreach (['en', 'ko'] as $lang) {
                // 真实词表原样复制：夹具的正文是合成的，但语言有没有 notice、
                // 词表全不全由交付的词表说了算，不在测试里另写一份。
                copy(I18N_DIR . '/glossary/' . $lang . '.json', $in . '/glossary/' . $lang . '.json');
                file_put_contents($in . '/readme/' . $lang . '.md', self::FIXTURE);
            }
            $this->assertSame(1,
                substr_count((string) file_get_contents($in . '/readme/en.md'), 'docs/i18n/en/images/runs-list.png'),
                '夹具里没有本语种那条路径，规则有没有生效将无从判断');

            [$rc, $log] = self::generate('en', $in, $out);
            $this->assertSame(0, $rc, "generate.php --lang=en 非零退出：\n$log");
            $en = (string) file_get_contents($out . '/en/README.md');

            // 本语种：前缀剥掉，就是同目录的邻居
            $this->assertStringContainsString('![own screenshot](images/runs-list.png)', $en);
            $this->assertStringNotContainsString('docs/i18n/en/', $en,
                '产物里还留着本语种前缀——长路径在 docs/i18n/en/README.md 里先是冗余，产物根一挪就是错的');
            // 别的语种：不归这条规则管，仍是仓库根相对的长路径
            $this->assertStringContainsString(
                '![other screenshot](../../../docs/i18n/ko/images/runs-list.png)', $en,
                '别的语种的路径被误改了——它相对本文件不是邻居，长路径才是对的');

            // 回归：原有两条 `docs/(.+)` 改写逐字不变（共享资源写长路径、翻译图写 ./images/）
            $this->assertStringContainsString('![pet](../../../docs/images/pet.svg)', $en);
            $this->assertStringContainsString('![architecture](./images/architecture.svg)', $en);
            $this->assertStringContainsString('[pet again](../../../docs/images/pet.svg)', $en,
                '`./docs/…` 这种带点斜杠的写法在原有改写里是同一个前缀，行为必须逐字不变');

            // 角色对调。同一份夹具，换成 ko 当主语：短路径与长路径必须正好互换，
            // 没有这一轮，「规则键的是当前语种」和「规则只认写死的 en」分不开。
            [$rc, $log] = self::generate('ko', $in, $out);
            $this->assertSame(0, $rc, "generate.php --lang=ko 非零退出：\n$log");
            $ko = (string) file_get_contents($out . '/ko/README.md');

            $this->assertStringContainsString('![other screenshot](images/runs-list.png)', $ko);
            $this->assertStringNotContainsString('docs/i18n/ko/', $ko);
            $this->assertStringContainsString(
                '![own screenshot](../../../docs/i18n/en/images/runs-list.png)', $ko,
                'en 的路径在 ko 产物里被误改短了');
        } finally {
            exec('rm -rf ' . escapeshellarg($scratch));
            // rmdir 只删空目录：并发跑的另一个测试还有草稿在时，这里删不动，也不该删动。
            @rmdir(self::SCRATCH_TOP);
        }
    }
}
