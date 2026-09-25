<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Docs;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// 源图与 classify.json 的比对必须与生成器**同源**：SVG 的实体解码、tspan 折叠、
// 命名空间前缀（local-name）这些语义只有 tools/i18n/lib.php 那一份实现是对的，
// 另写一份正则解析迟早在 `&amp;` 或 `<tspan>` 上分叉，然后给出假红或假绿。
require_once dirname(__DIR__, 3) . '/tools/i18n/lib.php';

/**
 * docs 产物的完整性：源图还是不是 classify.json 记下的样子、README 指的东西还在不在。
 *
 * 两条补的都是**「现有检查器看不见」的档**：
 *
 *  1. `tools/i18n/check.php` 的 copy 检查比的是 classify.json 里的**快照**（节点的
 *     `source` 字段），不是 `docs/images/*.svg` 的现况。于是「改源图上的文案、忘记重跑
 *     `extract.php`」是一条全绿的路径：快照与 12 份译文仍然一致，源图却已经换了文案，
 *     下一轮生成会把新文案当旧文案继续用。
 *  2. 根 README 的相对链接没有任何解析检查——check.php 里那段解析只对
 *     `docs/i18n/<lang>/README.md` 跑。删掉一张配图、挪一个章节标题之后，根 README
 *     指着不存在的东西，而所有门禁全绿（译文里的链接反倒查得比源文严）。
 *
 * 本文件**只读磁盘**：下面「检查会红」的用例都是在内存里改真实夹具（同一个函数、
 * 同一份真文档，只动一处），所以既证明了检查有效，也不会与正在重新生成 docs/ 的
 * agent 抢文件（`docs/i18n/*` 的 mtime 以分钟计）。
 */
class DocsIntegrityTest extends TestCase
{
    /** @return list<string> 源图里 `<text>` 节点的扁平文本，按文档顺序 */
    private static function sourceImageTexts(string $path): array
    {
        return array_map(
            static fn(\DOMElement $t): string => i18n_flat_text($t),
            i18n_text_nodes(i18n_load_svg($path))
        );
    }

    /**
     * 源图的文本与 classify.json 里的 `source` 逐 index 比对。
     *
     * 只认**带 index 的节点**：meta 节点（`<title>`/`<desc>`）不在图形里，index 为 null。
     * 节点数也要相等——源图多一个/少一个 `<text>` 而没人重跑 `extract.php`，是「快照落后
     * 于源图」的另一种形态（生成器按 index 取文案，错位就整篇串行）。
     *
     * @param array<string, mixed> $manifest classify.json
     * @param list<string> $texts 源图 `<text>` 节点的扁平文本
     * @return list<string> 不一致之处；空数组 = 源图与快照一致
     */
    private static function sourceSnapshotProblems(array $manifest, string $doc, array $texts): array
    {
        $indexed = [];
        foreach (i18n_doc_keys($manifest, $doc) as $key => $node) {
            if ($node['index'] !== null) {
                $indexed[$node['index']] = $node;
            }
        }

        $problems = [];
        if (count($texts) !== count($indexed)) {
            $problems[] = sprintf(
                '%s: 源图有 %d 个 <text> 节点，classify.json 记了 %d 个带 index 的节点',
                $doc,
                count($texts),
                count($indexed)
            );
        }
        foreach ($indexed as $index => $node) {
            // index 从 1 起（classify.json 与生成器都按这个口径）
            $actual = $texts[$index - 1] ?? null;
            if ($actual === null) {
                $problems[] = sprintf('%s#%d (%s): 源图里没有这个节点', $doc, (int) $index, $node['key']);
                continue;
            }
            if (trim($actual) !== trim((string) $node['source'])) {
                $problems[] = sprintf(
                    '%s#%d (%s): 源图是 "%s"，classify.json 记的是 "%s" —— 改了源图要重跑 tools/i18n/extract.php',
                    $doc,
                    (int) $index,
                    $node['key'],
                    $actual,
                    $node['source']
                );
            }
        }

        return $problems;
    }

    #[Test]
    public function theSourceImagesStillCarryTheTextRecordedInClassifyJson(): void
    {
        $root = dirname(__DIR__, 3);
        $manifest = i18n_load_manifest();

        $problems = [];
        $total = 0;
        foreach (I18N_DOCS as $doc) {
            $path = $root . '/' . $manifest['docs'][$doc]['source'];
            $this->assertFileExists($path, "$doc 的源图不在 classify.json 记的位置");
            $texts = self::sourceImageTexts($path);
            $this->assertGreaterThan(20, count($texts), "$doc 的源图里几乎没有 <text>，夹具或解析已失效");

            $problems = [...$problems, ...self::sourceSnapshotProblems($manifest, $doc, $texts)];
            $total += count($texts);
        }

        $this->assertGreaterThan(150, $total, '三张源图加起来不到 150 个 <text>，夹具已失效');
        $this->assertSame([], $problems, "源图与 classify.json 已经对不上了：\n" . implode("\n", $problems));
    }

    /**
     * 检查有效性的证明：同一条函数、同一份真源图，只在内存里改一个节点的文案
     * （以及删掉一个节点），必须报出来。
     *
     * 这正是缺口本身——改 `docs/images/*.svg` 上的文案而不重跑 `extract.php`，
     * **今天没有任何检查会红**。
     */
    #[Test]
    public function theSourceImageGateGoesRedWhenTheImageChangesWithoutExtract(): void
    {
        $root = dirname(__DIR__, 3);
        $manifest = i18n_load_manifest();
        $doc = I18N_DOCS[0];
        $texts = self::sourceImageTexts($root . '/' . $manifest['docs'][$doc]['source']);

        // 先钉住夹具本身是干净的：否则下面「改了要红」可能只是它一直红着
        $this->assertSame([], self::sourceSnapshotProblems($manifest, $doc, $texts), '真实源图与快照已经不一致，先修这个再看下面的证明');

        $edited = $texts;
        $edited[4] = '=== 内存里改掉的文案 ===';
        $problems = self::sourceSnapshotProblems($manifest, $doc, $edited);
        $this->assertNotSame([], $problems, '文案改了而快照没改，检查必须报出来');
        $this->assertStringContainsString(
            (string) self::keyOfIndex($manifest, $doc, 5),
            implode("\n", $problems),
            '报出来的必须是**改了的那一个**节点'
        );

        // 数量对不上（多/少一个 <text>）也要红
        $this->assertNotSame(
            [],
            self::sourceSnapshotProblems($manifest, $doc, array_slice($texts, 1)),
            '少了一个 <text> 节点而快照没变，检查必须报出来'
        );
    }

    /** classify.json 里某文档 index 对应的 key（index 从 1 起）。 */
    private static function keyOfIndex(array $manifest, string $doc, int $index): ?string
    {
        foreach (i18n_doc_keys($manifest, $doc) as $key => $node) {
            if ($node['index'] === $index) {
                return $key;
            }
        }
        return null;
    }

    /**
     * 一段 Markdown 里解析不动的目标。
     *
     * 规则与 `tools/i18n/check.php` 里译文 README 那段**逐条相同**：跳过
     * `http(s)`/`mailto:`/`data:`；`#anchor` 按 GitHub 式的标题 slug 比对；相对路径先
     * `rawurldecode` 再去掉 `#fragment`，相对 `$baseDir` 解析。根 README 只是从来没跑过
     * 这条规则而已——两处口径若分叉，译文查得比源文严就成了必然。
     *
     * @return array{broken: list<string>, rel: int, anchor: int}
     */
    private static function linkProblems(string $md, string $baseDir): array
    {
        $targets = [];
        if (preg_match_all('/\]\(([^)\s]+)\)/', $md, $m)) {
            foreach ($m[1] as $t) {
                $targets[] = $t;
            }
        }
        if (preg_match_all('/<img[^>]+src="([^"]+)"/i', $md, $m)) {
            foreach ($m[1] as $t) {
                $targets[] = $t;
            }
        }

        $slugs = [];
        if (preg_match_all('/^#{1,6}\s+(.+)$/m', $md, $m)) {
            foreach ($m[1] as $h) {
                $s = mb_strtolower(trim($h), 'UTF-8');
                $s = preg_replace('/[^\p{L}\p{N}\p{M} _-]/u', '', $s);
                $s = preg_replace('/\s+/u', '-', $s);
                $slugs[$s] = true;
            }
        }

        $broken = [];
        $rel = 0;
        $anchor = 0;
        foreach ($targets as $target) {
            if (preg_match('#^(https?:|mailto:|data:)#i', $target)) {
                continue;
            }
            if (str_starts_with($target, '#')) {
                $anchor++;
                if (!isset($slugs[rawurldecode(substr($target, 1))])) {
                    $broken[] = $target;
                }
                continue;
            }
            $rel++;
            $path = rawurldecode(explode('#', $target)[0]);
            $abs = $baseDir . '/' . ltrim($path, '/');
            $real = realpath($abs);
            if ($real === false || !file_exists($real)) {
                $broken[] = $target;
            }
        }

        return ['broken' => array_values(array_unique($broken)), 'rel' => $rel, 'anchor' => $anchor];
    }

    #[Test]
    public function everyLinkAndImageInTheRootReadmesResolves(): void
    {
        $root = dirname(__DIR__, 3);

        // 英文源 `tools/i18n/readme/en.md` 不在这里：手写源里只有它**有**产物，
        // 而 `check.php --lang=en` 会把产物里的每一条链接按文件系统解析一遍（缺文件即
        // FAIL），等于已经守着了。仓库根这份中文源没有任何产物，没有别的检查看得见它
        // ——这正是本用例存在的理由。
        foreach (['README.md'] as $file) {
            $r = self::linkProblems((string) file_get_contents($root . '/' . $file), $root);

            // 先钉住解析本身在干活：正则失效时下面那条「没有坏链接」会平凡成立
            $this->assertGreaterThan(10, $r['rel'], "$file 里解析出的相对链接少于 10 条，正则或夹具已失效");
            $this->assertGreaterThanOrEqual(1, $r['anchor'], "$file 里一个页内锚点都没有，锚点那条分支根本没被走到");

            $this->assertSame([], $r['broken'], "$file 里指向不存在的东西：" . implode('、', $r['broken']));
        }
    }

    /**
     * 检查有效性的证明：同一条函数、真实 README（读进内存），只改一处目标，
     * 必须只报出改掉的那一个。
     *
     * 不落盘改 README.md：那是别的 agent 正在写的文件，本仓库已有一次「两个 agent 同时
     * 改一个文件」的教训。内存里改同一个字节，证明的是同一条代码路径。
     */
    #[Test]
    public function theLinkGateGoesRedOnADeadTargetOrAnchor(): void
    {
        $root = dirname(__DIR__, 3);
        $md = (string) file_get_contents($root . '/README.md');

        $this->assertSame([], self::linkProblems($md, $root)['broken'], '真实 README 必须先干净，否则下面的红说明不了任何事');

        // (1) 配图指向不存在的文件
        $dead = preg_replace('#\]\(docs/images/run-report\.png\)#', '](docs/images/gone.png)', $md, 1, $n);
        $this->assertSame(1, $n, 'README.md 里找不到指向 run-report.png 的链接，夹具已失效');
        $this->assertSame(['docs/images/gone.png'], self::linkProblems((string) $dead, $root)['broken']);

        // (2) 锚点指向不存在的标题（`](#…` 出现的次数取第一处，只改一处）
        $dead = preg_replace('~\]\(#[^)]+\)~u', '](#no-such-heading)', $md, 1, $n);
        $this->assertSame(1, $n, 'README.md 里找不到页内锚点，夹具已失效');
        $this->assertSame(['#no-such-heading'], self::linkProblems((string) $dead, $root)['broken']);

        // (3) 外部链接不归这条检查管（否则上面两条会被一堆 http 目标淹掉）
        $ext = self::linkProblems("[a](https://example.com/gone.png) ![b](http://x/gone.svg) [c](mailto:no@one)", $root);
        $this->assertSame([], $ext['broken']);
        $this->assertSame(0, $ext['rel'], 'http/mailto 目标不该被当成相对路径去解析');
    }
}
