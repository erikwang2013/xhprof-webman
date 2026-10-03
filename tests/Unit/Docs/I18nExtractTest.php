<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Docs;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// 路径与文档清单必须与生成器**同源**：I18N_DOCS、模板路径、manifest 路径各写一份
// 就等于把被测对象换成自己的影子，改了 lib.php 而这里不跟，测试会安静地继续绿。
require_once dirname(__DIR__, 3) . '/tools/i18n/lib.php';

/**
 * `extract.php --list` 的两条契约。两条都是「一跑就静默写坏全部 12 个语言」那类：
 *
 *  1. **`--list` 只读**。文件头写着 "write nothing"，实现里却是一个空分支
 *     （注释 "restore nothing"），真正的写在 `build_doc()` 末尾无条件执行——
 *     `--list` 会把三份模板重写一遍。今天恰好是字节相同的重写，但没人量过这件事；
 *     而只要源图变一点，它就不是了。
 *  2. **同一份源图派生同一批键**。walker 只在注释体与 `SECTION_SLUGS` **逐字**相等时切
 *     section，于是 `① 框架入口层（Native PHP 与 Yii2 …）` 这条带括注的注释不命中，
 *     其后的 6 个节点落回上一节、派生成 `coupling.83-88`；而模板与 12 份术语表都叫
 *     `entry.83-88`。节点数不变，`classify.json` 的守卫只比数量（`$oldCount !== $newCount`）
 *     也不拦，于是 `extract.php` 一跑就把三份模板改成所有术语表都对不上的键——
 *     一次影响全部 12 个语言，且 `generate.php` 取 `$glossary[$m[1]]` 时才会炸。
 *
 * 两条都在**子进程里跑真脚本**验证：派生逻辑只有 extract.php 里那一份，另写一份「同语义」
 * 的实现只能证明那份克隆是对的。
 *
 * 本文件只读仓库：`--list` 修好后不写任何文件（指纹连 mtime 一起比，字节相同的重写
 * 也躲不过——selftest 里 dry-run 那条就是这么抓到过一次），「检查会红」的用例则在
 * 内存里改 `--list` 的输出，不碰 `SECTION_SLUGS`、更不落盘。
 */
class I18nExtractTest extends TestCase
{
    /** 三份模板 + classify.json 的 `文件名 => [mtime, sha1]`，与 selftest 的指纹同构。 */
    private static function fingerprint(): array
    {
        $files = [i18n_manifest_path()];
        foreach (glob(I18N_DIR . '/templates/*.svg') ?: [] as $p) {
            $files[] = $p;
        }
        $h = [];
        foreach ($files as $f) {
            $h[basename($f)] = [(int) filemtime($f), (string) sha1_file($f)];
        }
        ksort($h);
        return $h;
    }

    /** @return array{0:int,1:string} 退出码与合并后的输出 */
    private static function runList(): array
    {
        $out = [];
        $rc = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(I18N_DIR . '/extract.php') . ' --list 2>&1', $out, $rc);
        return [$rc, implode("\n", $out)];
    }

    /**
     * `--list` 输出里的 key => class。行格式由 extract.php 的 printf 决定：
     * `%-34s %-5s <source>`；表头、Totals 行都匹配不上。
     *
     * @return array<string, string>
     */
    private static function derivedKeys(string $list): array
    {
        preg_match_all('/^(\S+)\s+(copy|code|text)\s/m', $list, $m, PREG_SET_ORDER);
        $keys = [];
        foreach ($m as $x) {
            $keys[$x[1]] = $x[2];
        }
        return $keys;
    }

    /**
     * 三份模板里的 `{{key}}` 占位符。同一 key 出现两次要报出来：生成器逐个
     * `$glossary[$m[1]]` 取值，重复的占位符意味着同一句话被写了两遍。
     *
     * @return array<string, int> key => 出现次数
     */
    private static function templateKeys(): array
    {
        $keys = [];
        foreach (I18N_DOCS as $doc) {
            $svg = (string) file_get_contents(i18n_template_path($doc));
            preg_match_all('/\{\{([^}]+)\}\}/', $svg, $m);
            foreach ($m[1] as $k) {
                $keys[$k] = ($keys[$k] ?? 0) + 1;
            }
        }
        return $keys;
    }

    /**
     * 三方键集比对：`--list` 的派生结果、模板占位符、classify.json。
     * 三者必须逐键相等——生成器一边按 manifest 校验术语表、一边按模板取词，
     * 两边的键名来自同一个字符串。
     *
     * @param array<string, string> $derived
     * @param array<string, int> $template
     * @param array<string, true> $manifest
     * @return list<string> 不一致之处；空数组 = 三方一致
     */
    private static function keySetProblems(array $derived, array $template, array $manifest): array
    {
        $problems = [];
        // 拼接而不是插值：`"$k，"` 会被 PHP 读成一个变量名——标识符允许 0x80-0xFF
        // 的字节，全角逗号也在内，于是 $k 变成未定义、消息里只剩半个变量名。
        foreach (array_diff_key($template, $derived) as $k => $_) {
            $problems[] = '模板有 {{' . $k . '}}，--list 没派生出来（源图改了注释/文案而 SECTION_SLUGS 没跟上？）';
        }
        foreach (array_diff_key($derived, $template) as $k => $_) {
            $problems[] = '--list 派生出 ' . $k . '，模板里没有';
        }
        foreach (array_diff_key($template, $manifest) as $k => $_) {
            $problems[] = '模板有 {{' . $k . '}}，classify.json 没记';
        }
        foreach (array_diff_key($manifest, $template) as $k => $_) {
            $problems[] = 'classify.json 记了 ' . $k . '，模板里没有';
        }
        foreach ($template as $k => $n) {
            if ($n > 1) {
                $problems[] = '{{' . $k . '}} 在模板里出现 ' . $n . ' 次';
            }
        }
        return $problems;
    }

    /** @return array<string, true> classify.json 的全部键（含 meta）。 */
    private static function manifestKeys(): array
    {
        $manifest = i18n_load_manifest();
        $keys = [];
        foreach (I18N_DOCS as $doc) {
            foreach (i18n_doc_keys($manifest, $doc) as $k => $_) {
                $keys[$k] = true;
            }
        }
        return $keys;
    }

    #[Test]
    public function extractListWritesNothingAndDerivesTheSameKeysAsTheTemplates(): void
    {
        $before = self::fingerprint();
        $this->assertGreaterThanOrEqual(4, count($before), '模板与 classify.json 没找齐，夹具已失效');

        [$rc, $list] = self::runList();
        $this->assertSame(0, $rc, "extract.php --list 非零退出：\n$list");
        $this->assertStringContainsString('Totals:', $list, '--list 没打印 Totals——它没跑完，下面的「没写盘」就是平凡成立');

        // (a) 只读：内容与 mtime 都必须原样。
        $this->assertSame($before, self::fingerprint(), '--list 动了模板或 classify.json（字节或 mtime 有变）');

        // (b) 派生键集 == 模板占位符 == classify.json。
        $derived = self::derivedKeys($list);
        $this->assertGreaterThan(150, count($derived), '解析出的派生键不到 150 个，输出格式或上面的正则已失效');
        $problems = self::keySetProblems($derived, self::templateKeys(), self::manifestKeys());
        $this->assertSame([], $problems, "extract.php 的派生结果与已提交的模板/classify.json 对不上：\n"
            . implode("\n", $problems));

        // 事故的具体形态：带括注的 `① 框架入口层（…）` 不命中 SECTION_SLUGS 时，
        // 这 6 个节点会派生成 coupling.*。按名字钉死，比只比集合更早指出是哪一段。
        foreach (range(83, 88) as $n) {
            $this->assertArrayHasKey("architecture.entry.$n", $derived, "entry.$n 没有派生出来");
            $this->assertArrayNotHasKey("architecture.coupling.$n", $derived, "coupling.$n 不该存在——带括注的注释又没命中 section 映射");
        }
    }

    /**
     * 检查有效性的证明：同一条比对函数、同一份真 `--list` 输出，只把一个 key 改成
     * 漂移后的名字（`entry.83` → `coupling.83`，即修复前那 6 个节点的真实形态），
     * 必须同时报出「模板有、派生没有」与「派生有、模板没有」。
     */
    #[Test]
    public function theKeySetGateGoesRedWhenASectionDerivesToTheWrongSlug(): void
    {
        [, $list] = self::runList();
        $derived = self::derivedKeys($list);
        $template = self::templateKeys();
        $manifest = self::manifestKeys();

        // 先钉住夹具本身是干净的：否则下面的红可能只是它一直红着。
        $this->assertSame([], self::keySetProblems($derived, $template, $manifest), '真实键集已经不一致，先修这个再看下面的证明');

        $drifted = $derived;
        $drifted['architecture.coupling.83'] = $drifted['architecture.entry.83'];
        unset($drifted['architecture.entry.83']);

        $problems = self::keySetProblems($drifted, $template, $manifest);
        $this->assertNotSame([], $problems, '键名漂移了而比对没报出来，这条检查是空转的');
        $this->assertStringContainsString('architecture.entry.83', implode("\n", $problems));
        $this->assertStringContainsString('architecture.coupling.83', implode("\n", $problems));
    }
}
