<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Docs;

use ErikWang2013\Xhprof\Core\I18n\I18n;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * src/ 里写下的**字面** i18n key 必须真的在中文源词表里。
 *
 * 为什么这是独立一条闸：`I18n::t/plain/html/has()` 对不认识的 key 的约定是
 * **原样返回 key 本身**（src/Core/I18n/I18n.php，刻意如此——报告页显示一行
 * `report.titel` 好过白屏或 500）。于是把 `report.title` 打成 `report.titel`
 * 的后果是页面上原样印出 key 名，而既有的几条闸一条都看不见它：
 *   - I18nTest 的键集闸只比**词表之间**（词表 13 份全对、只有代码里的引用打错字，
 *     它全绿）；
 *   - 回退/值类闸比较的是「取到的值」，找不到 key 时 t() 返回的是 key 名本身，
 *     是个非空字符串，非空断言照样过；
 *   - README/SVG 那几条闸根本不进 src/。
 *
 * 判据：token_get_all 扫 src 下全部 PHP 文件（框架适配目录也在内），取
 * `I18n::{t,plain,html,has,raw}('…')` 参数里的字符串字面量：
 *   - 后面紧跟着 `)`/`,`/`:` 的 → 整个字符串就是这个 key，必须存在于中文源词表；
 *   - 后面跟着 `.` 的 → 「前缀 + 运行时后缀」（如 `'col.' . $stat`），没有哪一条
 *     词表条目能装下它，走 DYNAMIC_PREFIXES 显式白名单（精确前缀，逐个带注释）；
 *   - 其它形态一律当问题报出来——失败方向选「吵」，不选「悄悄漏过」。
 *
 * 边界（写清楚，别让这条闸显得比实际强）：key 从**变量**来的时候扫不到——
 * 例如 XhprofDisplay.php 的 runs.dt.* 映射表（`I18n::t($catalog_key)`，键名写在
 * 一张普通数组里再经变量传进去），那里打错字这条闸看不见。本闸只管字面量。
 *
 * 审计（2026-10-04 落地时实测）：124 个 PHP 文件、102 处调用、101 处字面引用、
 * 81 个去重 key，缺失 0；动态前缀恰好 3 个（col./diffcol./unit.）。下面的下限
 * 断言是为了让「扫描器悄悄坏掉」不表现为一片绿。
 */
class I18nKeyReferenceTest extends TestCase
{
    /**
     * 参数是词表 key 的方法。escapeHtml()/escapePlain() **不在**内：它俩收的是原始
     * 文案（词表取出来的值，或测试里的危险串），任何字符串都是合法输入，不是 key。
     *
     * `raw` 现在还没有这个方法（I18n 只有 t/plain/html/has），列在这里是为了它
     * 出现的那天同一条闸就覆盖它。
     */
    private const KEY_METHODS = ['t', 'plain', 'html', 'has', 'raw'];

    /**
     * 「前缀 + 运行时后缀」形态的白名单（精确到前缀，不是正则）：新出现一个动态前缀
     * 必须来这里登记并写明后缀从哪来，而不是悄悄溜过去；没再被用到的条目也会被点名
     * 删除（一条守着空气的白名单会让人以为它在守着什么）。
     *
     *   col.     XhprofDisplay::col_text()        后缀 = $stats 的统计项名
     *   diffcol. XhprofDisplay::stat_description() 同上，diff 模式
     *   unit.    XhprofDisplay::profiler_report()  后缀 = $possible_metrics 的单位名；
     *            调用点先用 has() 判过存在，词表没有的就原样输出单位，不是错键
     */
    private const DYNAMIC_PREFIXES = ['col.', 'diffcol.', 'unit.'];

    /** src/ 下全部 PHP 文件，排序保证失败信息顺序稳定。 @return list<string> */
    private static function srcFiles(): array
    {
        $root = dirname(__DIR__, 3) . '/src';
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    /** 相对仓库根的路径：失败信息别太长，且 /tmp 副本上跑红证时仍然可读。 */
    private static function relative(string $path): string
    {
        return str_replace(dirname(__DIR__, 3) . '/', '', $path);
    }

    #[Test]
    public function everyLiteralKeyInSrcExistsInTheChineseSourceCatalog(): void
    {
        $files = self::srcFiles();
        $this->assertGreaterThanOrEqual(100, count($files), 'src/ 下扫到的 PHP 文件少于 100，夹具或文件发现逻辑已失效');

        $report = ['keys' => [], 'prefixes' => [], 'unclassified' => []];
        foreach ($files as $file) {
            $part = self::scanKeys((string) file_get_contents($file), self::relative($file));
            $report['keys'] = array_merge_recursive($report['keys'], $part['keys']);
            $report['prefixes'] = array_merge_recursive($report['prefixes'], $part['prefixes']);
            $report['unclassified'] = array_merge($report['unclassified'], $part['unclassified']);
        }

        // 扫描器坏掉时下面每条断言都会平凡通过——先钉住它确实看见了东西
        $this->assertGreaterThanOrEqual(50, count($report['keys']), '扫到的去重 key 少于 50 个，扫描器可能已失效');
        $this->assertGreaterThanOrEqual(
            70,
            array_sum(array_map('count', $report['keys'])),
            '字面 key 引用少于 70 处，扫描器可能已失效'
        );

        $problems = self::problemsFor($report);
        $this->assertSame(
            [],
            $problems,
            "src/ 里的字面 i18n key / 动态前缀对不上中文源词表（I18n 找不到 key 时会把 key 名原样印到页面上）：\n- "
                . implode("\n- ", $problems)
        );
    }

    /**
     * 扫描一段 PHP 源码里的 I18n 字面 key。纯函数：证明测试拿内存里的伪造源码喂它，
     * 不必落盘改 src（src 是别的 agent 正在写的地方）。
     *
     * @return array{keys:array<string,list<string>>, prefixes:array<string,list<string>>, unclassified:list<string>}
     */
    private static function scanKeys(string $code, string $label): array
    {
        $tokens = token_get_all($code);
        $n = count($tokens);
        $keys = [];
        $prefixes = [];
        $unclassified = [];

        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || !in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            // 类名以 I18n 结尾即可：`I18n::`、`Foo\I18n::`、`\X\Y\I18n::` 都算
            $seg = explode('\\', $t[1]);
            if (end($seg) !== 'I18n') {
                continue;
            }
            $colon = self::nextSignificant($tokens, $i + 1);
            if ($colon < 0 || !is_array($tokens[$colon]) || $tokens[$colon][0] !== T_DOUBLE_COLON) {
                continue;
            }
            $method = self::nextSignificant($tokens, $colon + 1);
            if ($method < 0 || !is_array($tokens[$method]) || $tokens[$method][0] !== T_STRING
                || !in_array($tokens[$method][1], self::KEY_METHODS, true)) {
                continue;
            }
            $open = self::nextSignificant($tokens, $method + 1);
            if ($open < 0 || $tokens[$open] !== '(') {
                continue;
            }

            // 走到配对的 ')'，收下参数里的字符串字面量。**不限深度**：三元与嵌套括号里的
            // key（XhprofDisplay.php 的 pc.* 那处）同样要收——只看第一层会把它们整片漏掉。
            $depth = 0;
            for ($j = $open + 1; $j < $n; $j++) {
                $tok = $tokens[$j];
                if ($tok === '(') {
                    $depth++;
                    continue;
                }
                if ($tok === ')') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                    continue;
                }
                if (!is_array($tok) || $tok[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }

                $key = self::literalValue($tok[1]);
                $where = $label . ':' . $tok[2];
                $after = self::nextSignificant($tokens, $j + 1);
                $next = $after < 0 ? '文件结束' : (is_array($tokens[$after]) ? token_name($tokens[$after][0]) : $tokens[$after]);

                if ($next === '.') {
                    $prefixes[$key][] = $where;
                } elseif (in_array($next, [')', ',', ':'], true)) {
                    $keys[$key][] = $where;
                } else {
                    $unclassified[] = sprintf(
                        "%s 的 '%s' 后面是 %s——既不像完整 key（`)`/`,`/`:` 结尾）也不像拼接前缀（`.`）",
                        $where,
                        $key,
                        $next
                    );
                }
            }
        }

        return ['keys' => $keys, 'prefixes' => $prefixes, 'unclassified' => $unclassified];
    }

    /**
     * 判据本体：把扫描结果对词表与白名单核一遍，返回**全部**问题（不是第一条）。
     * 与扫描分成两个纯函数，证明测试才能拿伪造的扫描结果喂同一个判据。
     *
     * @param array{keys:array<string,list<string>>, prefixes:array<string,list<string>>, unclassified:list<string>} $report
     * @return list<string>
     */
    private static function problemsFor(array $report): array
    {
        $catalog = I18n::catalogOf(I18n::FALLBACK);   // FALLBACK 就是源语言 zh_CN
        $problems = $report['unclassified'];

        foreach ($report['keys'] as $key => $wheres) {
            if (!array_key_exists($key, $catalog)) {
                $problems[] = sprintf("未知 key '%s' —— 出现在 %s", $key, implode('、', $wheres));
            }
        }

        foreach ($report['prefixes'] as $prefix => $wheres) {
            if (!in_array($prefix, self::DYNAMIC_PREFIXES, true)) {
                $problems[] = sprintf(
                    "没登记过的动态前缀 '%s' —— 出现在 %s（确实由「前缀 + 运行时后缀」拼成的话，加进 DYNAMIC_PREFIXES 并写明后缀从哪来）",
                    $prefix,
                    implode('、', $wheres)
                );
            }
        }

        foreach (self::DYNAMIC_PREFIXES as $prefix) {
            if (!array_key_exists($prefix, $report['prefixes'])) {
                $problems[] = sprintf("DYNAMIC_PREFIXES 里的 '%s' 在扫描结果里没再出现——删掉这条白名单", $prefix);
            }
        }

        return $problems;
    }

    /** 下一个有效 token 的下标（跳过空白与注释），没有则 -1。 */
    private static function nextSignificant(array $tokens, int $i): int
    {
        for (; $i < count($tokens); $i++) {
            $t = $tokens[$i];
            if (is_array($t) && ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
                continue;
            }
            return $i;
        }
        return -1;
    }

    /** T_CONSTANT_ENCAPSED_STRING 的字面值（单双引号都按 PHP 的转义规则还原）。 */
    private static function literalValue(string $raw): string
    {
        $body = substr($raw, 1, -1);
        return $raw[0] === "'"
            ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body)
            : stripcslashes($body);
    }

    /**
     * 检查有效性的证明：同一套扫描器与判据，喂一段内存里的伪造源码——
     * 打错的 key 必须被点名，写对的 key 与白名单前缀不许被牵连，注释里的引用不是代码，
     * 没登记的前缀要点名，白名单里没人用的条目也要点名。
     *
     * 顺带钉住「不限深度」这条：三元与嵌套括号里的 key 一个都不能漏——漏了就是把
     * XhprofDisplay.php 里 pc.* 那处整片漏掉，而那种漏法是静默的。
     */
    #[Test]
    public function theScanGoesRedOnATypoAndStaysQuietOnWhitelistedPrefixes(): void
    {
        $fixture = <<<'PHP'
<?php
$a = I18n::plain('report.title');                                     // 完整 key
$b = I18n::html('report.titel');                                      // 打错一个字母
$c = I18n::plain($parent ? ($many ? 'pc.parentMany' : 'pc.parent')
                         : ($many ? 'pc.childMany' : 'pc.child'));    // 三元/嵌套括号里也是 key
$d = I18n::has('unit.' . $unit);                                      // 白名单前缀
$e = I18n::has('nope.' . $unit);                                      // 没登记的前缀
// $f = I18n::plain('comment.not.scanned');                           // 注释里的不算数
PHP;

        $report = self::scanKeys($fixture, 'fixture.php');

        $this->assertArrayNotHasKey('comment.not.scanned', $report['keys'], '注释里的引用被当成代码了');
        foreach (['report.title', 'report.titel', 'pc.parentMany', 'pc.parent', 'pc.childMany', 'pc.child'] as $key) {
            $this->assertArrayHasKey($key, $report['keys'], "扫描器漏掉了 '{$key}'");
        }
        $this->assertSame(['unit.', 'nope.'], array_keys($report['prefixes']), '动态前缀的识别不对');
        $this->assertSame([], $report['unclassified'], '夹具里没有形态可疑的写法，不该有 unclassified');

        $problems = implode("\n", self::problemsFor($report));
        $this->assertStringContainsString("未知 key 'report.titel'", $problems, '打错的 key 必须被点名');
        $this->assertStringNotContainsString("未知 key 'report.title'", $problems, '写对的 key 被误报了');
        $this->assertStringContainsString("动态前缀 'nope.'", $problems, '没登记的前缀必须被点名');
        $this->assertStringNotContainsString("动态前缀 'unit.'", $problems, '白名单里的前缀被误报了');
        $this->assertStringContainsString("'col.'", $problems, '夹具没用到、白名单里却还留着的条目应当被点名要求删除');
    }
}
