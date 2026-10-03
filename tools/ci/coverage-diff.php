#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 覆盖率闸门。一次调用跑两道闸，都读同一份 clover.xml（ci.yml 的覆盖率格产出）。
 *
 * 1) **差分闸门**：只审「本次变更新增/修改的 src/ 行」。
 *    行号来自 `git diff --unified=0 --diff-filter=ACMR <base> -- src`
 *    （或 --diff-file=FILE 直接喂一份统一 diff，自测/复现用，不碰 git）。
 *    某行在 clover 里有记录（= 可执行行）且 count=0 → 未覆盖，点名 `文件:行` 并非零退出。
 *    clover 里查不到的行（注释/空行/非 .php）不判定 —— 无法测量的行不设要求。
 *
 *    **刻意不用百分比阈值当这道闸门**：新增一个文件、或碰一下本就未覆盖的区域都会
 *    把总百分比拉低，那种闸门会无故变红；会无故变红的闸门会被忽略，顺带把真信号
 *    也带走。闸门只针对「你刚写的行」。
 *
 * 2) **崩落地板**：总行覆盖率 < FLOOR_PERCENT 非零退出。取值 = 现状 − 约 10 个百分点
 *    （实测 2026-10-03，本机 PHP 8.5 + xdebug：HEAD 96.73%、在制工作树 96.94%；
 *    CI 用 pcov，clover 里两者是同一套「语句」口径）。**只防崩落**：整个测试类被删、
 *    大块新代码一行不跑这类事故会被拦；日常小幅退步不拦，也不该拦。
 *
 * 用法：
 *   php tools/ci/coverage-diff.php --clover=test-reports/clover.xml --base="$BASE_SHA"
 *   php tools/ci/coverage-diff.php --clover=... --diff-file=/tmp/change.diff
 *   php tools/ci/coverage-diff.php --selftest        # 自检：造红/造绿各跑一遍
 *
 * `--base` 传空串（push 事件没有 PR base，workflow 原样传 `${{ github.event.pull_request.base.sha }}`）
 * 或全零 sha → **跳过**差分闸门（"没有可比基线"不是"通过"，日志里明写跳过），地板照跑。
 *
 * 退出码：0 全过；1 差分闸门红（新增行未覆盖）；2 崩落地板红；3 用法/输入错误。
 * 设了 GITHUB_STEP_SUMMARY 时把结论追加进 job 摘要。
 */

/** 崩落地板（%）。只防崩落，不防漂移：改这个数字应当是一次刻意的动作。 */
const FLOOR_PERCENT = 86.0;
const SRC_DIR = 'src/';

function out(string $line): void
{
    fwrite(STDOUT, $line . "\n");
}

function fail(string $msg): void
{
    fwrite(STDERR, '[coverage-diff] ' . $msg . "\n");
}

/**
 * clover 记的是运行时的绝对路径（CI 是 /home/runner/work/...），diff 记的是仓库相对路径，
 * 前缀不同。两边统一裁到 `src/` 起。取第一个 `/src/`：仓内没有嵌套的 src/ 目录
 * （tools/contracts、tools/i18n 下都没有，实测）。裁不出来（不在 src/ 下）→ null，不判定。
 */
function relPath(string $path): ?string
{
    $p = str_replace('\\', '/', $path);
    $i = strpos($p, '/' . SRC_DIR);
    if ($i !== false) {
        return substr($p, $i + 1);
    }

    return str_starts_with($p, SRC_DIR) ? $p : null;
}

/**
 * @return array{lines: array<string, array<int, bool>>, covered: int, statements: int}
 */
function loadClover(string $cloverPath): array
{
    if (!is_file($cloverPath)) {
        fail("clover 不存在：{$cloverPath}（覆盖率格没跑成？路径对不上？）");
        exit(3);
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->load($cloverPath)) {
        fail("clover 解析失败：{$cloverPath}");
        exit(3);
    }
    $xp = new DOMXPath($dom);

    $lines = [];
    foreach ($xp->query('/coverage/project//file') as $file) {
        $rel = relPath($file->getAttribute('name'));
        if ($rel === null) {
            continue;
        }
        foreach ($xp->query('line', $file) as $line) {
            $lines[$rel][(int) $line->getAttribute('num')] = ((int) $line->getAttribute('count')) > 0;
        }
    }

    // 项目级汇总 = <project> 的直接子 <metrics>（各 <file>/<package> 里的是分项）。
    $m = $xp->query('/coverage/project/metrics')->item(0);
    if ($m === null) {
        fail('clover 里没有项目级 <metrics> —— PHPUnit 的 clover 格式变了？闸门拒绝瞎猜。');
        exit(3);
    }
    $statements = (int) $m->getAttribute('statements');
    $covered = (int) $m->getAttribute('coveredstatements');
    if ($statements <= 0) {
        fail("clover 的 statements=0 —— 这份覆盖率不可信（没过滤到源码？空跑？），拒绝据此放行。");
        exit(3);
    }

    return ['lines' => $lines, 'covered' => $covered, 'statements' => $statements];
}

/**
 * 统一 diff → `仓库相对路径 => 新增行号列表`（只收 + 行；--unified=0 只给 + 行，
 * 但也兼容带上下文的 diff）。删除行、`\ No newline` 标记不动新行号。
 *
 * @return array<string, list<int>>
 */
function parseAddedLines(string $diff): array
{
    $files = [];
    $cur = null;
    $newLine = 0;

    foreach (explode("\n", $diff) as $raw) {
        if ($raw === '') {
            continue;
        }
        if (str_starts_with($raw, '+++ ')) {
            $p = trim(substr($raw, 4));
            $p = preg_replace('#^b/#', '', $p);
            if ($p === '/dev/null') {   // 删除文件（--diff-filter 已排除，防御性）
                $cur = null;
                continue;
            }
            $cur = relPath($p);
            continue;
        }
        if (str_starts_with($raw, '--- ')) {
            continue;
        }
        if (str_starts_with($raw, '@@ ')) {
            if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $raw, $m) !== 1) {
                fail("看不懂的 hunk 头，拒绝静默跳过：{$raw}");
                exit(3);
            }
            $newLine = (int) $m[1];
            continue;
        }
        if ($cur === null) {
            continue;
        }
        $c = $raw[0];
        if ($c === '+') {
            $files[$cur][] = $newLine;
            $newLine++;
        } elseif ($c === ' ' || $raw === '\\ No newline at end of file') {
            if ($c === ' ') {
                $newLine++;   // 上下文行（--unified=0 下不会有，兼容别的 diff 形式）
            }
        }
        // '-' 删除行：新行号不动。
    }

    return $files;
}

/**
 * 跑两道闸。返回 status 与结论行（结论行也打印到 stdout）。
 *
 * @return array{status: int, uncovered: list<string>, notes: list<string>}
 */
function runGate(string $cloverPath, ?string $diffText, string $baseLabel = ''): array
{
    $clover = loadClover($cloverPath);
    $uncovered = [];
    $notInClover = [];
    $measurable = 0;
    $unmeasurable = 0;
    $notes = [];

    $pct = 100 * $clover['covered'] / $clover['statements'];
    $floorRed = $pct < FLOOR_PERCENT;
    $notes[] = sprintf(
        '[崩落地板] 总行覆盖率 %.2f%%（%d/%d 语句）%s %.1f%% → %s',
        $pct,
        $clover['covered'],
        $clover['statements'],
        $floorRed ? '<' : '>=',
        FLOOR_PERCENT,
        $floorRed ? '红（大块代码失去覆盖？）' : 'ok'
    );

    if ($diffText === null) {
        $notes[] = '[差分闸门] 跳过（未执行）：没有可比基线 --base'
            . ($baseLabel !== '' ? "（base={$baseLabel}）" : '')
            . '。这是跳过，不是通过。';
    } else {
        $added = parseAddedLines($diffText);
        if ($added === []) {
            $notes[] = '[差分闸门] 变更为空：base 与当前树之间没有新增/修改的 src/ 行，无行可审。';
        }
        foreach ($added as $rel => $lineNums) {
            if (!isset($clover['lines'][$rel])) {
                $unmeasurable += count($lineNums);
                $notInClover[$rel] = count($lineNums);
                continue;
            }
            foreach ($lineNums as $n) {
                if (!array_key_exists($n, $clover['lines'][$rel])) {
                    $unmeasurable++;   // 注释/空行/大括号：不可测量，不设要求
                } elseif ($clover['lines'][$rel][$n]) {
                    $measurable++;
                } else {
                    $measurable++;
                    $uncovered[] = "{$rel}:{$n}";
                }
            }
        }
        if ($added !== []) {
            $notes[] = sprintf(
                '[差分闸门] 新增/修改的 src/ 行：可判定 %d 行（已覆盖 %d，**未覆盖 %d**），'
                . 'clover 无记录（注释/空行等）%d 行%s',
                $measurable,
                $measurable - count($uncovered),
                count($uncovered),
                $unmeasurable,
                $notInClover === [] ? '' : '；另有 ' . count($notInClover) . ' 个文件根本不在 clover 里（没被加载？）'
            );
            foreach ($uncovered as $where) {
                $notes[] = "  未覆盖  {$where}";
            }
            foreach (array_slice($notInClover, 0, 5, true) as $rel => $n) {
                $notes[] = "  clover 无此文件  {$rel}（{$n} 行无法判定）";
            }
        }
    }

    foreach ($notes as $n) {
        out($n);
    }

    if ($floorRed) {
        $status = 2;
    } elseif ($uncovered !== []) {
        $status = 1;
    } else {
        $status = 0;
    }

    $summary = getenv('GITHUB_STEP_SUMMARY');
    if (is_string($summary) && $summary !== '') {
        $text = "## 覆盖率\n\n";
        foreach ($notes as $n) {
            $text .= '- ' . trim($n) . "\n";
        }
        file_put_contents($summary, $text, FILE_APPEND);
    }

    return ['status' => $status, 'uncovered' => $uncovered, 'notes' => $notes];
}

/**
 * 自检：一个没红过的闸门等于没有闸门。这里用固定夹具把两道闸的**红**与**绿**
 * 各跑一遍（夹具里的 clover 路径刻意带无关前缀 /build/，顺带钉住 relPath 的裁剪）。
 */
function selftest(): int
{
    $dir = sys_get_temp_dir() . '/coverage-diff-selftest';
    @mkdir($dir);
    $cloverOk = $dir . '/clover.xml';
    $cloverLow = $dir . '/clover-low.xml';
    // 夹具的项目级总量（100/99）与文件级（2 行）刻意不一致：脚本只认项目级，
    // 这样"差分绿"的用例不会被地板干扰，而地板用例（下方 str_replace 成 100/1）必红。
    file_put_contents($cloverOk, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="0">
  <project timestamp="0" name="Clover Coverage">
    <file name="/build/src/Selftest.php">
      <line num="10" type="stmt" count="3"/>
      <line num="11" type="stmt" count="0"/>
      <metrics loc="20" ncloc="20" classes="0" methods="0" coveredmethods="0" conditionals="0" coveredconditionals="0" statements="2" coveredstatements="1" elements="2" coveredelements="1"/>
    </file>
    <metrics files="1" loc="20" ncloc="20" classes="0" methods="0" coveredmethods="0" conditionals="0" coveredconditionals="0" statements="100" coveredstatements="99" elements="100" coveredelements="99"/>
  </project>
</coverage>
XML);
    // 同结构，只有项目级 coveredstatements 掉到 1 → 地板必红。
    file_put_contents($cloverLow, str_replace(
        'statements="100" coveredstatements="99"',
        'statements="100" coveredstatements="1"',
        file_get_contents($cloverOk)
    ));

    $hunk = static fn (string $path, string $hunk): string =>
        "diff --git a/{$path} b/{$path}\n--- a/{$path}\n+++ b/{$path}\n{$hunk}\n";

    $cases = [
        // [用例名, clover, diff, 期望 exit]
        ['新增行未覆盖 → 红', $cloverOk, $hunk('src/Selftest.php', "@@ -0,0 +10,2 @@\n+covered\n+not covered"), 1],
        ['新增行已覆盖 → 绿', $cloverOk, $hunk('src/Selftest.php', "@@ -0,0 +10,1 @@\n+covered"), 0],
        ['新增注释行（clover 无记录）→ 绿', $cloverOk, $hunk('src/Selftest.php', "@@ -0,0 +50,1 @@\n+// comment"), 0],
        ['新文件不在 clover 里 → 绿但点名', $cloverOk, $hunk('src/New.php', "@@ -0,0 +1,2 @@\n+<?php\n+return 1;"), 0],
        ['非 src/ 路径不进闸门', $cloverOk, $hunk('tests/FooTest.php', "@@ -0,0 +1,1 @@\n+x"), 0],
        ['空 diff → 绿', $cloverOk, '', 0],
        ['地板：总覆盖率 1% → 红', $cloverLow, null, 2],
        ['无基线 → 跳过差分只看地板', $cloverOk, null, 0],
    ];

    $failed = 0;
    foreach ($cases as [$name, $clover, $diff, $want]) {
        $res = runGate($clover, $diff, $diff === null ? '' : 'selftest');
        if ($res['status'] !== $want) {
            fail("selftest 用例「{$name}」期望 exit {$want}，实得 {$res['status']}");
            $failed++;
        } else {
            out("selftest 用例通过：{$name}（exit {$res['status']}）");
        }
    }
    // 红用例还得点名到具体行，否则"红"本身可能是别的原因。
    $red = runGate($cloverOk, $hunk('src/Selftest.php', "@@ -0,0 +10,2 @@\n+covered\n+not covered"), 'selftest');
    if ($red['uncovered'] !== ['src/Selftest.php:11']) {
        fail('selftest：红用例没有点名 src/Selftest.php:11，实得 ' . json_encode($red['uncovered']));
        $failed++;
    }

    return $failed === 0 ? 0 : 1;
}

// ------------------------------- 入口 ---------------------------------

$opt = getopt('', ['clover:', 'base::', 'diff-file::', 'selftest', 'help']);

if (isset($opt['help'])) {
    out('用法：php tools/ci/coverage-diff.php --clover=<path> [--base=<ref> | --diff-file=<path>] | --selftest');
    exit(0);
}
if (isset($opt['selftest'])) {
    $code = selftest();
    out($code === 0 ? 'selftest ok' : 'selftest FAILED');
    exit($code);
}

$cloverPath = is_string($opt['clover'] ?? null) ? $opt['clover'] : 'test-reports/clover.xml';

$diffText = null;
$baseLabel = '';
if (is_string($opt['diff-file'] ?? null) && $opt['diff-file'] !== '') {
    $diffText = file_get_contents($opt['diff-file']);
    if ($diffText === false) {
        fail("读不了 diff 文件：{$opt['diff-file']}");
        exit(3);
    }
    $baseLabel = $opt['diff-file'];
} elseif (isset($opt['base']) && is_string($opt['base'])) {
    $base = trim($opt['base']);
    // 空串 = push 事件没有 PR base；全零 sha = 新分支的 github.event.before。都跳过。
    if ($base !== '' && ltrim($base, '0') !== '') {
        $root = trim((string) shell_exec('git rev-parse --show-toplevel 2>/dev/null'));
        if ($root === '') {
            fail('不在 git 仓库里，拿不到 --base 的 diff（自测可用 --diff-file）。');
            exit(3);
        }
        exec('git -C ' . escapeshellarg($root) . ' diff --unified=0 --diff-filter=ACMR '
            . escapeshellarg($base) . ' -- ' . SRC_DIR . ' 2>&1', $gitOut, $gitCode);
        $diffText = implode("\n", $gitOut);
        $baseLabel = $base;
        if ($gitCode !== 0) {
            fail("git diff 失败（base={$base}）：\n{$diffText}\n直传 PR base sha 时注意 checkout 需要 fetch-depth: 0，浅克隆里没有 base 提交。");
            exit(3);
        }
    }
}

$result = runGate($cloverPath, $diffText, $baseLabel);
exit($result['status']);
