<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Docs;

use ErikWang2013\Xhprof\Core\I18n\I18n;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 语言清单的机械绑定：workflow 里手写的 `for lang in ...` 必须与 I18n::AVAILABLE 对齐。
 *
 * **两套清单不是同一个东西**，所以这里比的是"报告页的 locale 去掉源语言"：
 *   * 报告页有 13 个 locale（`I18n::AVAILABLE`），源语言是 `zh_CN`；
 *   * docs 有 12 个目录（`docs/i18n/<lang>/README.md`），**没有 zh_CN**——中文是源，
 *     源就是仓库根那份 `README.md` 本身，不需要也不应该再译一份。
 *     `en` 在 docs 里**有**目录，但它是生成产物（正文源是 `tools/i18n/readme/en.md`），
 *     不是译文；本测试对这条不敏感，两种身份都要求 `en` 出现在 `for lang` 列表里。
 * 于是 docs 门禁要跑的是「AVAILABLE 去掉 zh_CN」之后的 12 个。
 *
 * 存在的理由：`tools/i18n/check.php` 是唯一比对 SVG 文本与术语表的工具，而它的语言
 * 由命令行给出（`--lang=<code>`，没有"全跑"模式），workflow 里的 for 列表就是它的
 * 调用入口。这份列表一旦漏掉某个语言，那个语言的门禁**永远不会跑一次**，而
 * I18nTest（键集/回退）与 I18nParityTest（README 结构）都照样全绿——
 * 「新增第 14 门语言 = 该语言静默脱管」。本测试让手写清单与权威常量机械对齐。
 *
 * 扫的是**所有** workflow 而不只是 i18n.yml：release.yml 的发布门禁里还有一份同样的
 * for 列表，只守一处等于把洞挪到另一处（正是这条测试要防的那个失效模式）。
 */
class I18nWorkflowTest extends TestCase
{
    /**
     * workflow 里跑语言门禁的写法：`for lang in en ko ...; do`。
     *
     * 用 `*` 而不是 `+` 是刻意的：列表被清空成 `for lang in ; do` 时 `+` 匹配不到，
     * 会退化成"没找到任何循环"（理由错、指向差），`*` 则匹配出空列表、由下面的
     * assertSame 报出真正的原因。捕获组只允许非 `;` 字符，避免跨行吞掉后续内容。
     */
    private const LOOP_PATTERN = '/for lang in ([^;]*); do/';

    /**
     * @return array<string, string[]> 文件名 => 该文件里每一个 `for lang` 列表的字面文本
     */
    private static function loopLists(): array
    {
        $root = dirname(__DIR__, 3);
        $found = [];
        foreach (glob($root . '/.github/workflows/*.yml') ?: [] as $file) {
            $text = (string) file_get_contents($file);
            if (preg_match_all(self::LOOP_PATTERN, $text, $m) > 0) {
                $found[basename($file)] = $m[1];
            }
        }
        return $found;
    }

    #[Test]
    public function workflowLocaleListsMatchTheAvailableLocales(): void
    {
        // 源语言没有 docs 译文（见类注释），门禁跑的是 AVAILABLE 去掉 zh_CN。
        $expected = array_values(array_diff(I18n::AVAILABLE, [I18n::FALLBACK]));
        sort($expected);

        $lists = self::loopLists();

        // 先钉住入口本身在：i18n.yml 是语言门禁的**归属文件**（清单在那里定义，
        // release.yml 只是复用它）。少了这一条，只守"所有出现的列表都得对"会留下
        // 一个洞——把 i18n.yml 的循环改名/搬走后它不再被匹配到，而 release.yml 那
        // 份健康的副本仍让 foreach 通过，于是"门禁静默消失"照样绿。这正是本条测试
        // 要防的失效模式，不能让它自己变成那样。
        $this->assertArrayHasKey('i18n.yml', $lists, 'i18n.yml 里没有 `for lang in ...; do` 的循环——check.php 的入口被改名/搬走/删掉了？');
        $this->assertNotSame([], $expected, 'I18n::AVAILABLE 里除源语言外没有任何语言，夹具已失效');

        foreach ($lists as $file => $fileLists) {
            foreach ($fileLists as $index => $list) {
                $got = preg_split('/\s+/', trim($list), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                sort($got);

                $this->assertSame(
                    $expected,
                    $got,
                    sprintf(
                        '%s 里第 %d 个 `for lang` 列表与 I18n::AVAILABLE（去掉源语言 %s）不一致：'
                        . '漏掉的语言，check.php 永远不会为它跑一次',
                        $file,
                        $index + 1,
                        I18n::FALLBACK
                    )
                );
            }
        }
    }
}
