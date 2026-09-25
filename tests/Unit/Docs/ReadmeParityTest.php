<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Docs;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 中英 README 的**结构**对照。
 *
 * 两份文档是同一份内容的两种语言，正文文字没法互比，但骨架可以：标题层级序列、
 * 代码块的数量与语言标记序列、表格的列数序列——这些都与语言无关。新章节只加在中文里、
 * 新增代码块忘了翻译、表格少一列，都会在这里红。
 *
 * 只比结构不比字数：翻译时句子长短不同是正常的，不该红。
 */
class ReadmeParityTest extends TestCase
{
    /**
     * 防「正则匹配不到 → 两个空列表相等 → 假绿」。
     * 数值取当前结构的下界（实测 22 个标题、25 个代码块、7 张表）。
     */
    private const MIN_HEADINGS = 20;

    private const MIN_FENCES = 20;

    private const MIN_TABLES = 5;

    private function path(string $file): string
    {
        return dirname(__DIR__, 3) . '/' . $file;
    }

    /** @return list<string> */
    private function lines(string $file): array
    {
        return file($this->path($file), FILE_IGNORE_NEW_LINES) ?: [];
    }

    /**
     * 标题层级序列，如 [2, 2, 3, 3, 2, ...]。文字不比（两种语言），只比层级与顺序。
     *
     * @return list<int>
     */
    private function headingLevels(string $file): array
    {
        $levels = [];
        foreach ($this->lines($file) as $line) {
            if (preg_match('/^(#{1,6})\s+\S/', $line, $m) === 1) {
                $levels[] = strlen($m[1]);
            }
        }

        return $levels;
    }

    /**
     * 逐个**开启**的代码块的语言标记（按出现顺序）。围栏必须成对闭合，否则断言失败。
     *
     * @return list<string>
     */
    private function fenceLanguages(string $file): array
    {
        $languages = [];
        $open = false;
        foreach ($this->lines($file) as $i => $line) {
            if (preg_match('/^```(.*)$/', $line, $m) !== 1) {
                continue;
            }
            if ($open) {
                $open = false;
                continue;
            }
            $open = true;
            $languages[] = trim($m[1]);
        }

        $this->assertFalse($open, "{$file} 第 " . (($i ?? 0) + 1) . ' 行附近：代码围栏没有闭合（``` 数量为奇数）');

        return $languages;
    }

    /**
     * 表格的列数序列（取分隔行 `|---|---|` 的列数）。
     *
     * @return list<int>
     */
    private function tableColumns(string $file): array
    {
        $columns = [];
        foreach ($this->lines($file) as $line) {
            if (preg_match('/^\|[\s:|-]+\|$/', $line) === 1) {
                $columns[] = substr_count($line, '|') - 1;
            }
        }

        return $columns;
    }

    #[Test]
    public function theTwoReadmesHaveTheSameHeadingStructure(): void
    {
        $zh = $this->headingLevels('README.md');
        $en = $this->headingLevels('README.EN.md');

        $this->assertGreaterThanOrEqual(self::MIN_HEADINGS, count($zh), '中文 README 的标题数异常少，先确认解析规则没失效');
        $this->assertSame($zh, $en, '中英 README 的标题层级序列不一致：新章节要两边都加，层级也要一致');
    }

    #[Test]
    public function theTwoReadmesHaveTheSameCodeBlocks(): void
    {
        $zh = $this->fenceLanguages('README.md');
        $en = $this->fenceLanguages('README.EN.md');

        $this->assertGreaterThanOrEqual(self::MIN_FENCES, count($zh), '中文 README 的代码块数异常少，先确认解析规则没失效');
        $this->assertSame(
            $zh,
            $en,
            '中英 README 的代码块（数量/顺序/语言标记）不一致：加了示例代码要两边都加，围栏后的语言标记也要一致'
        );
    }

    #[Test]
    public function theTwoReadmesHaveTheSameTableShapes(): void
    {
        $zh = $this->tableColumns('README.md');
        $en = $this->tableColumns('README.EN.md');

        $this->assertGreaterThanOrEqual(self::MIN_TABLES, count($zh), '中文 README 的表格数异常少，先确认解析规则没失效');
        $this->assertSame($zh, $en, '中英 README 的表格列数序列不一致：表格增删列要两边同步');
    }
}
