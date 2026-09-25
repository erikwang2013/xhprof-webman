<?php
declare(strict_types=1);

/**
 * tools/i18n/extract.php — derive templates + the classification manifest from
 * the read-only source diagrams in docs/images/.
 *
 *   php tools/i18n/extract.php            # write templates, create classify.json if absent
 *   php tools/i18n/extract.php --force    # also overwrite classify.json (discards curation)
 *   php tools/i18n/extract.php --list     # print the classification for review, write nothing
 *
 * docs/images/*.svg are never modified.  The templates are byte-for-byte copies
 * except that translatable text becomes a {{key}} placeholder.
 *
 * Every <text> node is classified as exactly one of:
 *
 *   copy  — an identifier, namespace, method signature, number or PHP keyword.
 *           Must be reproduced unchanged.  Checked mechanically: out === source.
 *   code  — prose wrapped around code tokens.  Translate the prose; every token
 *           in "keep" must still be present verbatim.  Checked mechanically.
 *   text  — prose only.  Translate freely.  Nothing is checked but presence.
 *
 * The manifest is generated once and then curated: --force is required to
 * overwrite it, so hand corrections to a "keep" list survive re-runs.
 */

require __DIR__ . '/lib.php';

// ---------------------------------------------------------------------------
// Section slugs
//
// Keys are <doc>.<section>.<n>.  Sections come from the XML comments that
// already structure the source diagrams; only comments listed here start a new
// section, so incidental comments ("<!-- Laravel -->") do not fragment keys.
// ---------------------------------------------------------------------------

const SECTION_SLUGS = [
    '标题' => 'title',
    '图例' => 'legend',
    '① 框架入口层' => 'entry',
    '① → ②' => 'flow-entry-contract',
    '② 契约层' => 'contract',
    '② → ③' => 'flow-contract-core',
    '③ Core' => 'core',
    '③ → ④ 红色耦合箭头' => 'flow-core-coupling',
    '④ 两处耦合' => 'coupling',
    '管线 8 阶段' => 'pipeline',
    '短路说明 + 图例' => 'legend',
    '泳道：各框架采样起止点' => 'lanes',
    '阶段轴' => 'axis',
    '结论条' => 'conclusion',
    '表头' => 'header',
    '第 1 行：Core 只依赖 5 个契约' => 'row1',
    '第 2 行：入口类自服务报告页' => 'row2',
    '第 3 行：不抽共享层' => 'row3',
    '第 4 行：桩由独立验证环校验' => 'row4',
    '第 5 行：显式注入而非 autoDetect' => 'row5',
];

/**
 * Product names, framework names and bare code words that a translator must not
 * translate even though they sit inside Chinese prose.  Namespaced names,
 * calls and ::references are found by pattern; these are the ones that are not.
 */
const PROTECTED_WORDS = [
    'XHProf', 'Xhprof', 'Webman', 'webman', 'Laravel', 'ThinkPHP', 'Hyperf',
    'Yii3', 'Symfony', 'Slim', 'WordPress', 'Wordpress', 'Joomla', 'Drupal',
    'Core', 'Adapter', 'Redis', 'API', 'PHP', 'PSR-15', 'PSR-7',
    'HttpFoundation', 'HttpKernel', 'autoDetect', 'class_exists', 'parity',
    'import', 'shutdown', 'finally', 'handler', 'start', 'L2',
    // The layer markers ① ② ③ carry the diagram's ordering; a translated
    // heading without them loses the visual link to the layer it names.
    '①', '②', '③', '④',
];

/** Whole phrases that are code output, not prose. */
const PROTECTED_PHRASES = [
    'Call to undefined method',
];

/**
 * Geometry corrections applied to a template just after extraction.
 *
 * `docs/images/*.svg` is read-only, so a defect in the original layout cannot be
 * fixed at its source.  This table is the one sanctioned deviation between a
 * template and the diagram it came from.  Keep it tiny, and say why for each
 * entry: an undocumented line here is invisible in every generated file.
 *
 * @var array<string, array<int, array<string, string>>> doc => run # => attrs
 */
const LAYOUT_FIXES = [
    'lifecycle' => [
        // Run #56 "excludes bootstrap and plugin loading" annotates the left
        // edge of the WordPress lane.  It sat right-aligned at x=336 on y=417 —
        // the gutter between the framework sub-label column (x=44) and the lane
        // band (x=340) — where its room is 336 - 44 - w(#53): 172px in Chinese,
        // but only 123px in French, whose wording needs 200px.  The two runs
        // therefore overlapped in 10 of the 12 languages (19.9px in English,
        // 77.0px in French), and nothing auto-corrected it: the anchor point
        // lies outside every <rect>, so the generator measured its container as
        // the whole canvas and saw no problem.
        //
        // Moving it up to y=408 puts it on the lane-title row, where the space
        // from #52's right edge to x=336 is a constant 231px — #52 is "WordPress",
        // a copy key, so that edge is identical in every language.  Widths are
        // baseline-to-baseline: 104.7px to 336px.  The longest wording measured
        // is French at 199.6px, leaving 31.7px of clearance.
        56 => ['y' => '408'],
    ],
];

// ---------------------------------------------------------------------------

function has_cjk(string $s): bool
{
    return (bool) preg_match('/[\x{2E80}-\x{9FFF}\x{F900}-\x{FAFF}\x{FE30}-\x{FE4F}\x{FF00}-\x{FFEF}\x{3000}-\x{303F}]/u', $s);
}

/** Code tokens that must survive translation verbatim. @return string[] */
function protected_tokens(string $s): array
{
    $found = [];
    $patterns = [
        '/\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+\\\\?/',  // \Foo\Bar  Foo\Bar\
        '/[A-Za-z_][A-Za-z0-9_]*::[A-Za-z_][A-Za-z0-9_]*/',                   // Foo::bar
        '/[A-Za-z_][A-Za-z0-9_]*\([^()]*(?:\([^()]*\)[^()]*)*\)/',            // foo()  foo($a)  foo(bar())
        '/(?<![A-Za-z0-9_])[A-Za-z][A-Za-z0-9]*_[A-Za-z0-9_]+(?![A-Za-z0-9_])/', // snake_case
        '%/?[A-Za-z0-9_.\-]+(?:/[A-Za-z0-9_.\-]+)+/?%',                        // src/Core/  /xhprof-assets/
    ];
    foreach ($patterns as $p) {
        if (preg_match_all($p, $s, $m)) {
            foreach ($m[0] as $tok) {
                $found[] = $tok;
            }
        }
    }
    foreach (PROTECTED_WORDS as $w) {
        if (preg_match('/(?<![A-Za-z0-9_\\\\])' . preg_quote($w, '/') . '(?![A-Za-z0-9_])/', $s)) {
            $found[] = $w;
        }
    }
    foreach (PROTECTED_PHRASES as $ph) {
        if (str_contains($s, $ph)) {
            $found[] = $ph;
        }
    }
    // Drop tokens already covered by a longer match, e.g. "autoDetect()" inside
    // "Xhprof::autoDetect()".  Comparison is on an alphanumeric skeleton so that
    // punctuation differences ("index()" vs "Xhprof::index") still count as the
    // same token.  Keeping both is harmless but makes the translator's checklist
    // twice as long for no extra guarantee.
    $found = array_values(array_unique($found));
    // "list_runs()" and "list_runs" have the same skeleton — keep the longer
    // spelling only, otherwise the checklist names one token twice.
    $bySkeleton = [];
    foreach ($found as $tok) {
        $skel = preg_replace('/[^A-Za-z0-9]/', '', $tok);
        if (!isset($bySkeleton[$skel]) || strlen($tok) > strlen($bySkeleton[$skel])) {
            $bySkeleton[$skel] = $tok;
        }
    }
    $found = array_values($bySkeleton);
    $kept = [];
    foreach ($found as $tok) {
        $subsumed = false;
        foreach ($found as $other) {
            if ($other === $tok) {
                continue;
            }
            $skelTok = preg_replace('/[^A-Za-z0-9]/', '', $tok);
            $skelOther = preg_replace('/[^A-Za-z0-9]/', '', $other);
            if ($skelTok !== '' && $skelOther !== $skelTok && str_contains($skelOther, $skelTok)) {
                $subsumed = true;
                break;
            }
        }
        if (!$subsumed) {
            $kept[] = $tok;
        }
    }
    // Longest first, so the checklist reads most-specific-first.
    usort($kept, fn($a, $b) => strlen($b) <=> strlen($a));
    return $kept;
}

/** @return array{0:string,1:string[],2:string} class, keep, why */
function classify(string $s): array
{
    $trimmed = trim($s);
    if (!has_cjk($trimmed)) {
        return ['copy', [], 'no prose characters — identifier, signature, number or keyword'];
    }
    $keep = protected_tokens($trimmed);
    if ($keep) {
        return ['code', $keep, 'prose surrounding code tokens that must stay verbatim'];
    }
    return ['text', [], 'prose only — translate freely'];
}

// ---------------------------------------------------------------------------

function build_doc(string $doc): array
{
    $src = I18N_REPO . "/docs/images/$doc.svg";
    $xml = i18n_load_svg($src);

    $tpl = i18n_load_svg($src);
    $tplTexts = i18n_text_nodes($tpl);

    $nodes = [];
    $section = 'root';
    $n = 0;
    $textIndex = 0;

    // Walk the *source* document in order so comments drive section changes.
    $walker = new DOMXPath($xml);
    foreach ($walker->query('//comment() | //*[local-name()="text"]') as $node) {
        if ($node->nodeType === XML_COMMENT_NODE) {
            $body = trim((string) $node->nodeValue, "= \t\n\r");
            if (isset(SECTION_SLUGS[$body])) {
                $section = SECTION_SLUGS[$body];
            }
            continue;
        }
        /** @var DOMElement $node */
        $textIndex++;
        $n++;
        $key = "$doc.$section.$n";
        $source = i18n_flat_text($node);
        [$class, $keep, $why] = classify($source);

        $nodes[] = [
            'key'    => $key,
            'index'  => $textIndex,
            'class'  => $class,
            'source' => $source,
            'keep'   => $keep,
            'why'    => $why,
        ];

        // Placeholder goes into the template's same-numbered <text> node.
        $target = $tplTexts[$textIndex - 1] ?? null;
        if ($target === null) {
            throw new RuntimeException("template/source text count mismatch in $doc");
        }
        while ($target->firstChild) {
            $target->removeChild($target->firstChild);
        }
        $target->appendChild($tpl->createTextNode('{{' . $key . '}}'));
    }

    // <title> / <desc> are accessibility metadata rather than <text> nodes, but
    // they are read aloud and shown as tooltips, so they get translated too.
    foreach (['title' => 'title', 'desc' => 'desc'] as $tag => $slug) {
        $el = i18n_meta_node($xml, $tag);
        if ($el === null) {
            continue;
        }
        $key = "$doc.meta.$slug";
        [$class, $keep, $why] = classify($el->textContent);
        $nodes[] = [
            'key'    => $key,
            'index'  => null,
            'class'  => $class,
            'source' => $el->textContent,
            'keep'   => $keep,
            'why'    => $why . ' (SVG metadata: screen-reader/tooltip text)',
        ];
        $tgt = i18n_meta_node($tpl, $tag);
        while ($tgt->firstChild) {
            $tgt->removeChild($tgt->firstChild);
        }
        $tgt->appendChild($tpl->createTextNode('{{' . $key . '}}'));
    }

    // Documented geometry corrections last, so a re-extraction reproduces them.
    // A hand-edited template would be silently reverted by the next run of this
    // script, and the defect would come back in all 12 languages at once.
    $tplNodes = i18n_text_nodes($tpl);   // not $nodes: that is this file's own
    foreach (LAYOUT_FIXES[$doc] ?? [] as $index => $attrs) {   // node manifest
        $target = $tplNodes[$index - 1] ?? null;
        if ($target === null) {
            fwrite(STDERR, "LAYOUT_FIXES[$doc][$index]: no such text run in the template\n");
            continue;
        }
        foreach ($attrs as $name => $value) {
            $target->setAttribute($name, $value);
        }
    }

    $canvas = i18n_canvas($xml);
    i18n_write_svg($tpl, i18n_template_path($doc));

    return [
        'source'   => "docs/images/$doc.svg",
        'canvas'   => $canvas,
        'texts'    => $textIndex,
        'nodes'    => $nodes,
    ];
}

// ---------------------------------------------------------------------------

$force = in_array('--force', $argv, true);
$listOnly = in_array('--list', $argv, true);

$docs = [];
foreach (I18N_DOCS as $doc) {
    $docs[$doc] = build_doc($doc);
    if ($listOnly) {
        // restore nothing — templates are cheap to regenerate
    }
}

$manifest = [
    '_readme' => 'Generated by tools/i18n/extract.php from docs/images/*.svg. '
        . 'class: copy = reproduce unchanged; code = translate prose but keep every "keep" token verbatim; '
        . 'text = translate freely. See tools/i18n/README.md.',
    'docs' => $docs,
];

$counts = [];
foreach ($docs as $doc => $d) {
    foreach ($d['nodes'] as $n) {
        $counts[$n['class']] = ($counts[$n['class']] ?? 0) + 1;
    }
}

if ($listOnly) {
    foreach ($docs as $doc => $d) {
        printf("\n===== %s (%d text nodes, canvas %gx%g) =====\n", $doc, $d['texts'], $d['canvas']['w'], $d['canvas']['h']);
        foreach ($d['nodes'] as $n) {
            printf("%-34s %-5s %s%s\n", $n['key'], $n['class'], $n['source'],
                $n['keep'] ? '   << keep: ' . implode(' | ', $n['keep']) : '');
        }
    }
    printf("\nTotals: %s\n", json_encode($counts));
    exit(0);
}

$path = i18n_manifest_path();
if (is_file($path) && !$force) {
    $old = json_decode((string) file_get_contents($path), true);
    $oldCount = 0;
    foreach ($old['docs'] ?? [] as $d) {
        $oldCount += count($d['nodes'] ?? []);
    }
    $newCount = 0;
    foreach ($docs as $d) {
        $newCount += count($d['nodes']);
    }
    if ($oldCount !== $newCount) {
        fwrite(STDERR, "classify.json is stale ($oldCount nodes, source now has $newCount) — re-run with --force\n");
        exit(1);
    }
    echo "classify.json kept (curated). Templates rewritten.\n";
} else {
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    echo "classify.json written.\n";
}

$total = 0;
foreach ($docs as $doc => $d) {
    $c = ['copy' => 0, 'code' => 0, 'text' => 0];
    foreach ($d['nodes'] as $n) {
        $c[$n['class']]++;
    }
    $total += $d['texts'];
    printf("%-14s %3d text nodes -> templates/%s.svg   copy=%d code=%d text=%d\n",
        $doc, $d['texts'], $doc, $c['copy'], $c['code'], $c['text']);
}
printf("total <text>: %d\n", $total);
