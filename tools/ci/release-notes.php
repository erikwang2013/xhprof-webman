#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 从 conventional commits 生成 release notes / CHANGELOG 段落。
 *
 * 为什么需要它：GitHub 的 `gh release create --generate-notes` 只列**合并的 PR** 与
 * 新贡献者；本仓直推 main（178 个提交只有 2 次 merge），于是 v3.7.0 / v3.8.0 的
 * Release 正文都只有一行 compare 链接 —— 等于没有正文（`gh release view` 实测）。
 * 提交信息本身写得很足（本仓纪律：rationale 落在提交里），把它们按 conventional
 * commits 分组就是一份真实的变更日志；顺带把同一份段落插进 CHANGELOG.md。
 *
 * 用法（在仓库里跑，git 命令以 cwd 为准）：
 *   php tools/ci/release-notes.php --from=v3.7.0 --to=v3.8.0 [--version=v3.8.0]
 *                                 [--date=YYYY-MM-DD] [--repo=owner/repo]
 *                                 [--changelog=CHANGELOG.md]
 *   php tools/ci/release-notes.php --selftest
 * notes 写到 stdout；带 --changelog= 时把同一份插进该文件的标记行下方（见下）。
 * compare 链接是 `<from>...<version>` —— 用**版本名**不是 --to 的 ref：CI 在 tag 推送
 * 之前生成 notes（那一刻 tag 还不存在，--to 只能是 HEAD），链接必须落在即将存在的
 * tag 名上，否则 Release 页的 compare 会随 main 前进而漂移。
 * 退出码：0 成功；1 参数/范围/文件错误；2 用法错误。
 *
 * 分组与过滤（写下来，别让"漏了什么"是沉默的）：
 *   * 分组顺序 = TYPE_GROUPS 的顺序；带 `!` 或正文含 `BREAKING CHANGE` 的单独进
 *     「破坏性变更」；不认识的 type 与非规范提交进「其他」。
 *   * `chore(release:*)` 与 `chore(changelog)` 是发版杂务（本次版本号自己抬自己），
 *     不进 notes；过滤条数打到 stderr（数字能看，过滤不是静默的）。
 *   * merge 提交不列（--no-merges）：本仓的 merge 是同步性质的，没有正文可读。
 */

/** 分组顺序即输出顺序；值同时是段落标题。 */
const TYPE_GROUPS = [
    'feat' => '新增',
    'fix' => '修复',
    'perf' => '性能',
    'refactor' => '重构',
    'docs' => '文档',
    'test' => '测试',
    'build' => '构建',
    'ci' => 'CI',
    'chore' => '杂务',
];
const BREAKING_LABEL = '破坏性变更';
const OTHER_LABEL = '其他';

/** 发版杂务的 scope：本文件/工作流自己产生的提交，进 notes 就是自己说自己。 */
const PLUMBING_SCOPES = ['release', 'changelog'];

/** CHANGELOG.md 的头部与插入标记：标记是**机器契约**，段落的插入点只认它。 */
const CHANGELOG_HEADER = <<<'MD'
# 更新日志

本文件按 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/) 的组织方式（版本新→旧、
分组列出），但**段落由机器生成**：`tools/ci/release-notes.php` 从 conventional commits
分组渲染，`release.yml` 在发版时插入到下面的标记行下方 —— 同一份内容也是该次 Release
的正文。人工**不要**改既往条目的文本（机器只插入、不改写历史）；新版本由发版流程写入。

最初两节（v3.7.0 / v3.8.0）启用本机制之前就已发布（当时的 Release 正文只有 GitHub 自动
生成的一行 compare 链接），2026-10-04 由同一工具按同一规则回溯生成。

MD;
const CHANGELOG_MARKER = '<!-- CHANGELOG:INSERT：新段落插在这一行下面（保持新→旧） -->';

function out(string $s): void
{
    fwrite(STDOUT, $s);
}

function info(string $s): void
{
    fwrite(STDERR, $s . "\n");
}

function fail(string $msg): void
{
    fwrite(STDERR, '[release-notes] ' . $msg . "\n");
    exit(1);
}

/**
 * 跑一条 git 命令（cwd 即仓库）。返回 [退出码, stdout]。
 * stderr 并进 stdout：这里要的是"命令失败时人能看到原因"，不是干净管道。
 */
function git(string $args): array
{
    $lines = [];
    $code = 0;
    exec('git ' . $args . ' 2>&1', $lines, $code);

    return [$code, implode("\n", $lines)];
}

function requireCommit(string $ref, string $flag): void
{
    [$code] = git('rev-parse --verify --quiet ' . escapeshellarg($ref) . '^{commit}');
    if ($code !== 0) {
        fail("{$flag}={$ref} 不是一个能解析到提交的 ref（本地有 fetch 到吗？tag 用 git fetch --tags 取）");
    }
}

/**
 * 范围内的提交：[['sha'=>短 sha, 'subject'=>..., 'body'=>...], ...]（新→旧，git log 默认序）。
 * 记录分隔符用 %x1e / 字段分隔符 %x1f：提交正文里出现换行是常态，按行切会碎。
 */
function commitsInRange(string $from, string $to): array
{
    [$code, $raw] = git('log --no-merges --format=' . escapeshellarg('%H%x1f%s%x1f%b%x1e') . ' '
        . escapeshellarg($from . '..' . $to));
    if ($code !== 0) {
        fail("git log {$from}..{$to} 失败：{$raw}");
    }
    $commits = [];
    foreach (explode("\x1e", $raw) as $record) {
        $record = trim($record, "\n");
        if ($record === '') {
            continue;
        }
        $parts = explode("\x1f", $record, 3);
        if (count($parts) < 2) {
            continue;   // 形态不对（理论上不会）：宁可跳过也不炸在半途
        }
        $commits[] = [
            'sha' => substr($parts[0], 0, 7),
            'subject' => trim($parts[1]),
            'body' => rtrim($parts[2] ?? ''),
        ];
    }

    return $commits;
}

/**
 * 解析 conventional commit 头：`type(scope)!: 描述`。
 * scope 允许逗号（本仓有 `chore(tools,ci):`），非规范提交返回 null。
 *
 * @return array{type: string, scope: string, breaking: bool, desc: string}|null
 */
function parseSubject(string $subject): ?array
{
    if (preg_match('/^([a-zA-Z]+)(?:\(([^)]*)\))?(!)?:\s*(.+)$/u', $subject, $m) !== 1) {
        return null;
    }

    return [
        'type' => strtolower($m[1]),
        'scope' => $m[2] ?? '',
        'breaking' => ($m[3] ?? '') === '!',
        'desc' => $m[4],
    ];
}

function isPlumbing(?array $parsed): bool
{
    return $parsed !== null && $parsed['type'] === 'chore' && in_array($parsed['scope'], PLUMBING_SCOPES, true);
}

/**
 * 分组：[标签 => 条目列表]，标签顺序 = 破坏性变更（若有）→ TYPE_GROUPS → 其他（若有）。
 * 返回 [分组, 被过滤的条数]。
 *
 * @param array<int, array{sha: string, subject: string, body: string}> $commits
 * @return array{0: array<string, array<int, array{parsed: ?array, sha: string, subject: string, body: string}>>, 1: int}
 */
function groupCommits(array $commits): array
{
    $groups = [];
    $filtered = 0;
    foreach ($commits as $c) {
        $parsed = parseSubject($c['subject']);
        if (isPlumbing($parsed)) {
            $filtered++;
            continue;
        }
        $breaking = $parsed !== null
            && ($parsed['breaking'] || preg_match('/^BREAKING[ -]CHANGE:/mu', $c['body']) === 1);
        $label = $breaking ? BREAKING_LABEL
            : ($parsed === null || !isset(TYPE_GROUPS[$parsed['type']]) ? OTHER_LABEL : $parsed['type']);
        $groups[$label][] = ['parsed' => $parsed, 'sha' => $c['sha'], 'subject' => $c['subject'], 'body' => $c['body']];
    }
    // 排序：破坏性变更最前、其他最后，其余按 TYPE_GROUPS；没出现的组不占位。
    $order = array_merge([BREAKING_LABEL], array_keys(TYPE_GROUPS), [OTHER_LABEL]);
    uksort($groups, static function (string $a, string $b) use ($order): int {
        return array_search($a, $order, true) <=> array_search($b, $order, true);
    });

    return [$groups, $filtered];
}

/** 一条条目：`- **scope**：描述（sha）` + 正文按块引用缩进。 */
function renderEntry(array $entry): string
{
    $p = $entry['parsed'];
    $desc = $p['desc'] ?? $entry['subject'];
    $scope = ($p['scope'] ?? '') !== '' ? '**' . $p['scope'] . '**：' : '';
    $md = '- ' . $scope . $desc . '（' . $entry['sha'] . '）';
    if ($entry['body'] !== '') {
        foreach (explode("\n", $entry['body']) as $line) {
            $md .= "\n  > " . rtrim($line);
        }
    }

    return $md;
}

/**
 * @param array<string, array<int, array>> $groups
 */
function renderNotes(string $version, string $date, array $groups, string $compareUrl): string
{
    $md = "## {$version} — {$date}\n";
    foreach ($groups as $label => $entries) {
        $title = isset(TYPE_GROUPS[$label]) ? "{$label}（" . TYPE_GROUPS[$label] . '）' : $label;
        $md .= "\n### {$title}\n\n";
        foreach ($entries as $e) {
            $md .= renderEntry($e) . "\n";
        }
    }
    $md .= "\n**完整变更**：{$compareUrl}\n";

    return $md;
}

/** owner/repo：CI 里用 GITHUB_REPOSITORY（单一来源），本地从 remote 推。 */
function detectRepo(?string $flag): string
{
    if ($flag !== null && $flag !== '') {
        return $flag;
    }
    $env = getenv('GITHUB_REPOSITORY');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    [$code, $url] = git('remote get-url origin');
    if ($code === 0 && preg_match('#(?:github\.com[:/])([^/]+/[^/]+?)(?:\.git)?$#', trim($url), $m) === 1) {
        return $m[1];
    }
    fail('推不出 owner/repo（origin remote 不指向 github？用 --repo= 给一个）');
}

/**
 * 把段落插进 CHANGELOG.md 的标记行下方（保持新→旧）。
 * 文件不存在 → 用 CHANGELOG_HEADER 建（含标记）；标记找不到 → 报错退出，
 * **不做**"退化成追加到文件尾"这种聪明事：那会让顺序悄悄反过来，没人看得出来。
 */
function insertIntoChangelog(string $path, string $section): void
{
    if (!is_file($path)) {
        file_put_contents($path, CHANGELOG_HEADER . CHANGELOG_MARKER . "\n\n" . $section);
        info("{$path} 不存在：已用头部模板创建并写入本次段落");

        return;
    }
    $text = file_get_contents($path);
    // 幂等：同一版本号的段落只许有一份。发版流程重跑、或人工照 release.yml 注释里的命令
    // 补写时再跑一次，重复插入会让 main 上出现两节同名版本（版本号就是这份文件的键）。
    // 跳过要打到 stderr —— 静默跳过与静默重复一样坏。
    if (preg_match('/^## (\S+) —/m', $section, $vm) === 1
        && preg_match('/^## ' . preg_quote($vm[1], '/') . ' —/m', $text) === 1) {
        info("{$path}：已存在 {$vm[1]} 的段落，跳过插入（幂等）");

        return;
    }
    $lines = explode("\n", $text);
    $at = null;
    foreach ($lines as $i => $line) {
        if (trim($line) === CHANGELOG_MARKER) {
            $at = $i;
            break;
        }
    }
    if ($at === null) {
        fail("{$path} 里找不到标记行「" . CHANGELOG_MARKER . '」——不追加到文件尾（那会把新→旧顺序悄悄弄反），先修标记');
    }
    array_splice($lines, $at + 1, 0, ['', rtrim($section)]);
    file_put_contents($path, implode("\n", $lines));
    info("{$path}：本次段落已插到标记行下方");
}

function usage(): void
{
    fail('用法：--from=<ref> --to=<ref> [--version=] [--date=] [--repo=] [--changelog=] 或 --selftest');
}

// ---------------------------------------------------------------------------

/**
 * 自测：自建一个临时 git 仓库（夹具提交），对**同一套函数**断言分组/过滤/引用/边界；
 * 再对 CHANGELOG 插入路径断言顺序与"标记缺失即红"。做法与 tools/i18n/selftest.php 同：
 * 先证明检查会红，才值得信它的绿。
 */
function selftest(): void
{
    $dir = sys_get_temp_dir() . '/release-notes-selftest-' . getmypid();
    exec('rm -rf ' . escapeshellarg($dir));
    mkdir($dir, 0777, true);
    $old = getcwd();
    chdir($dir);
    $fail = 0;
    $n = 0;
    $assert = static function (bool $ok, string $what) use (&$fail, &$n): void {
        $n++;
        echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
        if (!$ok) {
            $fail++;
        }
    };
    try {
        git('init -q');
        $commit = static function (string $subject, string $body = '') use ($dir): void {
            file_put_contents($dir . '/f.txt', $subject);
            git('add f.txt');
            $args = 'commit -q --allow-empty -m ' . escapeshellarg($subject);
            if ($body !== '') {
                $args .= ' -m ' . escapeshellarg($body);
            }
            [$code] = git('-c user.name=t -c user.email=t@example.invalid ' . $args);
            if ($code !== 0) {
                fail('自测夹具提交失败：' . $subject);
            }
        };
        $commit('初始化夹具');   // 根提交：让下面的范围边界（HEAD~8）有得切
        $commit('feat(alpha): 功能甲', "第一行引子\n\n第二段也有");
        $commit('fix: 修乙');
        // 两条破坏性**分别只带一种信号**（`!` 不带正文标记；正文标记不带 `!`）——
        // 首版夹具给 `!` 那条也写了 BREAKING CHANGE 正文，于是"忽略 `!`"的变异体
        // 照样全绿（夹具没有判别力）；两种信号各钉一条，缺哪条哪条红。
        $commit('feat(beta)!: 破坏性丙', '旧的接口没了（没有任何 BREAKING 字样）');
        $commit('fix(gamma): 修伽马', "BREAKING CHANGE: 这个靠正文标记，标题上没有 `!`");
        $commit('chore: 杂务丁');
        $commit('docs: 文档戊');
        $commit('chore(release): 3.9.0');
        $commit('随便写的非规范提交');
        $commit('refactor: 重构己', 'contains BREAKING CHANGE: 中段不算');

        $commits = commitsInRange('HEAD~9', 'HEAD');
        $shaOf = static function (string $prefix, array $list): string {
            foreach ($list as $c) {
                if (strpos($c['subject'], $prefix) === 0) {
                    return $c['sha'];
                }
            }
            return '';
        };
        $assert(count($commits) === 9, '范围提交数 = 9（HEAD~9..HEAD，根提交在范围外）');
        [$groups, $filtered] = groupCommits($commits);
        $assert($filtered === 1, 'chore(release) 被过滤 1 条');
        $labels = array_keys($groups);
        $assert($labels === [BREAKING_LABEL, 'feat', 'fix', 'refactor', 'docs', 'chore', OTHER_LABEL],
            '分组顺序：破坏性变更 → feat → fix → refactor → docs → chore → 其他（实得：' . implode(',', $labels) . '）');
        $breakingShas = array_column($groups[BREAKING_LABEL], 'sha');
        sort($breakingShas);
        $expectBreaking = [$shaOf('feat(beta)!', $commits), $shaOf('fix(gamma)', $commits)];
        sort($expectBreaking);
        $assert($breakingShas === $expectBreaking,
            '两种破坏性信号（`!` / 正文 BREAKING CHANGE）各进「破坏性变更」');
        $assert(count($groups['feat']) === 1
                && $groups['feat'][0]['sha'] === $shaOf('feat(alpha)', $commits),
            'feat 组只剩不带 `!` 的那条');
        $assert(count($groups['fix']) === 1
                && $groups['fix'][0]['sha'] === $shaOf('fix: 修乙', $commits),
            'fix 组只剩不带正文标记的那条');
        $assert(count($groups[OTHER_LABEL]) === 1 && $groups[OTHER_LABEL][0]['parsed'] === null,
            '非规范提交进「其他」');
        $assert($groups['refactor'][0]['parsed'] !== null && strpos(renderEntry($groups['refactor'][0]), '> contains') !== false,
            '正文里中段的 BREAKING CHANGE 不算破坏性（只认行首），且正文以块引用渲染');
        $md = renderNotes('v9.9.9', '2026-01-01', $groups, 'https://github.com/o/r/compare/a...b');
        $assert(substr_count($md, '### ') === 7, '渲染出 7 个分组标题');
        $assert(strpos($md, 'chore(release): 3.9.0') === false, '被过滤的提交不出现在输出里');
        $assert(strpos($md, '**完整变更**：https://github.com/o/r/compare/a...b') !== false, 'compare 链接在正文里');
        $assert(strpos($md, '  > 旧的接口没了（没有任何 BREAKING 字样）') !== false
                && strpos($md, '  > BREAKING CHANGE: 这个靠正文标记') !== false,
            '两条破坏性的正文都按两空格 + 块引用缩进');

        // 边界：坏 ref / 空范围
        [$bad] = git('rev-parse --verify --quiet ' . escapeshellarg('no-such-ref-xyz') . '^{commit}');
        $assert($bad !== 0, '坏 ref 无法解析（requireCommit 会在这里红）');
        $assert(commitsInRange('HEAD', 'HEAD') === [], '空范围（from==to）解析为 0 条提交（主流程据此判红）');

        // CHANGELOG 插入：标记下方插入、新→旧、既有内容不动、标记缺失即红
        $cl = $dir . '/CHANGELOG.md';
        insertIntoChangelog($cl, renderNotes('v1.0.0', '2026-01-01', $groups, 'u1'));
        insertIntoChangelog($cl, renderNotes('v2.0.0', '2026-02-01', $groups, 'u2'));
        $text = file_get_contents($cl);
        $assert(strpos($text, CHANGELOG_HEADER) === 0, 'CHANGELOG 头部模板在最前');
        $p2 = strpos($text, '## v2.0.0');
        $p1 = strpos($text, '## v1.0.0');
        $assert($p2 !== false && $p1 !== false && $p2 < $p1, '两次插入后仍是新→旧（v2 在 v1 上面）');
        $assert(substr_count($text, CHANGELOG_MARKER) === 1, '标记只有一行');
        $assert(substr_count($text, '### feat（新增）') === 2, '第二次插入没有改写第一次的段落（两段都在）');
        // 幂等：同一版本号的段落再插一次是 no-op。发版流程重跑、或人工照 release.yml 注释
        // 里的命令补写时都要靠这条 —— 版本号是这份文件的键，两节同名版本就是数据损坏。
        insertIntoChangelog($cl, renderNotes('v2.0.0', '2026-02-01', $groups, 'u2'));
        $assert(file_get_contents($cl) === $text && substr_count(file_get_contents($cl), '## v2.0.0') === 1,
            '同一版本段重复插入是 no-op（不产生两节同名版本）');
        // 标记缺失必须红，且红的**理由**是标记缺失（不是顺带的空范围之类）——fail() 是 exit
        // 不是异常，只能在子进程里断言，理由看 stderr。
        $markerless = "# 无标记的文件\n";
        file_put_contents($cl, $markerless);
        $errFile = $dir . '/err.txt';
        $rc = 0;
        // --repo= 是必须的：夹具仓库没有 origin，detectRepo 会先红——首版断言就栽在这
        // （红的理由不对 = 假红），所以这里同时排除它，钉住"红在标记上"。
        exec('php ' . escapeshellarg(__FILE__) . ' --from=HEAD~8 --to=HEAD --repo=o/r --changelog=' . escapeshellarg($cl)
            . ' >/dev/null 2>' . escapeshellarg($errFile), $_, $rc);
        $err = (string) file_get_contents($errFile);
        $assert($rc === 1 && strpos($err, '找不到标记行') !== false && strpos($err, '推不出') === false,
            '目标文件缺标记行 → 退出码 1，理由是「找不到标记行」（不是别的）');
        $assert(file_get_contents($cl) === $markerless, '拒绝后文件一字节没动（没有退化成尾部追加）');
        // compare 链接写的是**版本名**，不是 --to 的 ref：CI 在 tag 推送之前生成 notes，
        // 那一刻 --to 只能是 HEAD，而链接必须落在即将存在的 tag 名上。这条断言走子进程
        // 的真主流程（只断言 URL 拼接函数的话，主流程传错参数照样绿）。
        $clMarked = $dir . '/CHANGELOG-marked.md';
        file_put_contents($clMarked, "# t\n" . CHANGELOG_MARKER . "\n");
        $outFile = $dir . '/out.txt';
        $rc3 = 0;
        exec('php ' . escapeshellarg(__FILE__) . ' --from=HEAD~8 --to=HEAD --version=v9.9.9 --repo=o/r --changelog=' . escapeshellarg($clMarked)
            . ' >' . escapeshellarg($outFile) . ' 2>/dev/null', $_, $rc3);
        $out = (string) file_get_contents($outFile);
        $assert($rc3 === 0
            && strpos($out, '**完整变更**：https://github.com/o/r/compare/HEAD~8...v9.9.9') !== false,
            'compare 链接用版本名（--to=HEAD 时链接仍落在版本 tag 上）');
    } finally {
        chdir($old);
        exec('rm -rf ' . escapeshellarg($dir));
    }

    echo "selftest: {$n} 断言，" . ($fail === 0 ? '0 失败' : "{$fail} 失败") . "\n";
    exit($fail === 0 ? 0 : 1);
}

// ---------------------------------------------------------------------------

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--selftest') {
        selftest();
    }
    if (preg_match('/^--([a-z-]+)=(.*)$/s', $arg, $m) !== 1) {
        usage();
    }
    $opts[$m[1]] = $m[2];
}

$from = $opts['from'] ?? '';
$to = $opts['to'] ?? 'HEAD';
if ($from === '') {
    usage();
}
requireCommit($from, '--from');
requireCommit($to, '--to');

$commits = commitsInRange($from, $to);
if ($commits === []) {
    fail("范围 {$from}..{$to} 内没有提交 —— 空的 release notes 是 bug 不是结果（版本算错/ref 指错）");
}
[$groups, $filtered] = groupCommits($commits);
if ($groups === []) {
    fail("范围 {$from}..{$to} 的 " . count($commits) . ' 个提交全是发版杂务，过滤后为空');
}

$repo = detectRepo($opts['repo'] ?? null);
$version = $opts['version'] ?? $to;
$date = $opts['date'] ?? gmdate('Y-m-d');
$section = renderNotes($version, $date, $groups, "https://github.com/{$repo}/compare/{$from}...{$version}");

info("范围 {$from}..{$to}：" . count($commits) . " 个提交，过滤发版杂务 {$filtered} 个，"
    . count($groups) . ' 个分组（' . implode('、', array_keys($groups)) . '）');

if (isset($opts['changelog'])) {
    insertIntoChangelog($opts['changelog'], $section);
}
out($section);
exit(0);
