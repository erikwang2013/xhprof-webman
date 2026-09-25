<?php
declare(strict_types=1);

/**
 * tools/i18n/generate.php — render one language's diagrams from the templates.
 *
 *   php tools/i18n/generate.php --lang=en
 *   php tools/i18n/generate.php --lang=de --dry-run     # report, write nothing
 *
 * Input : tools/i18n/templates/<doc>.svg  +  tools/i18n/glossary/<lang>.json
 * Output: docs/i18n/<lang>/images/<doc>.svg
 *
 * Text that does not fit its box is refitted automatically, in this order:
 *
 *   1. shrink the font, down to 75% of the source size
 *   2. adjust the layout — widen the containing box, or slide a right/middle
 *      anchored run inwards, whichever the anchor allows, and only when that
 *      does not collide with a neighbouring box
 *   3. wrap onto extra lines using <tspan>, which keeps the node count and the
 *      tree shape identical to the source
 *
 * Shrink first because it is local and reversible.  Box growth second because
 * it is bounded by the neighbour check — the source diagrams are tight grids
 * and a widened card can collide.  Wrapping last because it needs vertical
 * room, which most of these single-line labels do not have.
 *
 * Anything still not fitting is reported and the run exits non-zero.  Overflow
 * is never written silently: a generated file that check.php rejects is a bug
 * in this script, not in the translation.
 */

require __DIR__ . '/lib.php';

// ---------------------------------------------------------------------------

const LINE_HEIGHT = I18N_LINE_HEIGHT;   // must match the band model in lib.php
const GROW_LIMIT = 0.25;    // a box may grow at most 25% of its own width

/**
 * Inset the generator aims for, in px.  check.php only requires the run to be
 * I18N_PAD inside its box; the generator deliberately leaves extra room because
 * the width model is an estimate, and for the scripts it could not be
 * calibrated against a real font (see calibrate.php) that margin is the only
 * thing between an estimate and a visibly overflowing diagram.
 */
const FIT_INSET = I18N_PAD + 3.0;

/**
 * @param array{x:float,y:float,w:float,h:float} $box
 * $bold must be threaded through: a bold run with any non-ASCII character
 * measures ~6% wider than the plain table says, and this function is the one
 * that decides whether the generator's shrink actually succeeded.  Omitting it
 * made `fits()` agree with a width that is not the width it renders at.
 */
function fits(string $text, float $fs, float $x, string $anchor, array $box, bool $bold = false): bool
{
    $w = FontMetrics::width($text, $fs, $bold);
    [$l, $r] = match ($anchor) {
        'middle' => [$x - $w / 2, $x + $w / 2],
        'end'    => [$x - $w, $x],
        default  => [$x, $x + $w],
    };
    return $l >= $box['x'] + FIT_INSET && $r <= $box['x'] + $box['w'] - FIT_INSET;
}

/** Slack in px between a run and its container (negative means overflow). */
function slack(DOMElement $t, array $box): float
{
    $w = i18n_node_width($t);
    [$l, $r] = i18n_text_extent($t, $w);
    return min($l - $box['x'], $box['x'] + $box['w'] - $r);
}

/** Rung 2: can $rect grow to the right by $by px without touching a neighbour? */
function grow_allowed(DOMDocument $doc, DOMElement $rect, float $by): float
{
    $b = i18n_rect_bounds($rect);
    if ($by <= 0) {
        return 0.0;
    }
    $by = min($by, $b['w'] * GROW_LIMIT);
    $limit = i18n_canvas($doc);
    $right = $b['x'] + $b['w'] + $by;
    foreach (i18n_rects($doc) as $other) {
        if ($other->isSameNode($rect) || $other->parentNode?->isSameNode($rect) || $rect->parentNode?->isSameNode($other)) {
            continue;
        }
        $o = i18n_rect_bounds($other);
        $vOverlap = $o['y'] < $b['y'] + $b['h'] && $o['y'] + $o['h'] > $b['y'];
        if (!$vOverlap) {
            continue;
        }
        if ($o['x'] >= $b['x'] + $b['w'] - 0.01 && $o['x'] < $right) {
            $right = $o['x'] - 1.0;
        }
    }
    $right = min($right, $limit['x'] + $limit['w']);
    return max(0.0, $right - ($b['x'] + $b['w']));
}

/**
 * Wrap $text to fit $avail px, on spaces when it has any, otherwise per
 * character (CJK and other scripts written without spaces).
 *
 * @return string[] lines, or [] if it cannot be split usefully
 */
function wrap(string $text, float $avail, float $fs, bool $bold): array
{
    $hasSpace = (bool) preg_match('/\s/u', trim($text));
    $units = $hasSpace
        ? preg_split('/(?<=\s)/u', trim($text), -1, PREG_SPLIT_NO_EMPTY)
        : preg_split('//u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    if (!$units || count($units) < 2) {
        return [];
    }
    $lines = [];
    $cur = '';
    foreach ($units as $u) {
        if ($cur !== '' && FontMetrics::width($cur . $u, $fs, $bold) > $avail) {
            $lines[] = rtrim($cur);
            $cur = ltrim($u);
        } else {
            $cur .= $u;
        }
    }
    if (trim($cur) !== '') {
        $lines[] = trim($cur);
    }
    return count($lines) > 1 ? $lines : [];
}

/** Vertical band a run occupies: [top, bottom] estimated from size and x-height. */
function vertical_band(float $y, float $fs, int $lines = 1): array
{
    return i18n_band($y, $fs, $lines);
}

/**
 * Refit every text node of one document.
 *
 * @return array<int,array{key:string,reason:string}> unfittable nodes
 */
function autofit(DOMDocument $doc, array $keysByIndex): array
{
    $failures = [];
    foreach (i18n_text_nodes($doc) as $i => $t) {
        $key = $keysByIndex[$i + 1] ?? "text#$i";
        $cont = i18n_container($doc, $t);
        $box = $cont['bounds'];
        if (slack($t, $box) >= FIT_INSET - I18N_PAD) {
            continue;
        }

        $fs0 = i18n_font_size($t);
        $bold = i18n_is_bold($t);
        $x = (float) ($t->getAttribute('x') ?: 0);
        // The geometric anchor, with rtl already resolved.  For an rtl locale
        // this script has by now swapped the attributes, so reading the raw
        // attribute here would ask "how much room is there to the right" of a
        // run that is in fact drawn to the left — which is how three runs that
        // fit perfectly well under ltr came out as OVERFLOW.
        $anchor = i18n_effective_anchor($t);
        $text = i18n_flat_text($t);
        // Horizontal room the anchor actually gives this run.  For "start" the
        // run must fit to the right of x; for "end", to the left.
        $room = match ($anchor) {
            'end'    => $x - $box['x'] - FIT_INSET,
            'middle' => $box['w'] - 2 * FIT_INSET,
            default  => $box['x'] + $box['w'] - FIT_INSET - $x,
        };

        // -- rung 1: shrink --------------------------------------------------
        $unit = FontMetrics::width($text, 1.0, $bold);   // width at 1px
        if ($unit > 0 && $room > 0) {
            $scale = $room / $unit / $fs0;               // fraction of original size
            if ($scale < 1.0) {
                $target = max($scale, I18N_MIN_SCALE);
                $fs1 = floor($fs0 * $target * 100) / 100;
                if ($fs1 < $fs0 && fits($text, $fs1, $x, $anchor, $box, $bold)) {
                    $t->setAttribute('font-size', rtrim(rtrim(sprintf('%.2f', $fs1), '0'), '.'));
                    continue;
                }
                if ($scale >= I18N_MIN_SCALE) {
                    // The arithmetic above should have matched; guard against a
                    // rounding surprise rather than silently falling through.
                    $fs1 = floor($fs0 * I18N_MIN_SCALE * 100) / 100;
                    if (fits($text, $fs1, $x, $anchor, $box, $bold)) {
                        $t->setAttribute('font-size', rtrim(rtrim(sprintf('%.2f', $fs1), '0'), '.'));
                        continue;
                    }
                }
            }
        }
        $fs = floor($fs0 * I18N_MIN_SCALE * 100) / 100;
        $t->setAttribute('font-size', rtrim(rtrim(sprintf('%.2f', $fs), '0'), '.'));

        // -- rung 2: adjust the layout --------------------------------------
        $need = FontMetrics::width($text, $fs, $bold) - $room;
        if ($anchor === 'start' && $cont['rect'] !== null) {
            $grow = grow_allowed($doc, $cont['rect'], $need);
            if ($grow > 0) {
                $b = i18n_rect_bounds($cont['rect']);
                $cont['rect']->setAttribute('width', (string) round($b['w'] + $grow, 1));
                $box = i18n_rect_bounds($cont['rect']);
                if (slack($t, $box) >= FIT_INSET - I18N_PAD) {
                    continue;
                }
            }
        }
        if ($anchor !== 'start' && $need > 0) {
            // Slide the run away from the edge it is pinned to, keeping it
            // inside the same box.
            $shift = $anchor === 'middle' ? $need / 2 : $need;
            $nx = $x - $shift;
            if ($nx >= $box['x'] + I18N_PAD || $anchor === 'end') {
                $t->setAttribute('x', (string) round($nx, 1));
                if (slack($t, $box) >= FIT_INSET - I18N_PAD) {
                    continue;
                }
            }
        }

        // -- rung 3: wrap ----------------------------------------------------
        $avail = match ($anchor) {
            'end'    => $x - $box['x'] - FIT_INSET,
            'middle' => $box['w'] - 2 * FIT_INSET,
            default  => $box['x'] + $box['w'] - FIT_INSET - $x,
        };
        $lines = wrap($text, $avail, $fs, $bold);
        if ($lines) {
            // Vertical room: stay inside the box and clear of siblings.
            $y = (float) ($t->getAttribute('y') ?: 0);
            [$top, $bottom] = vertical_band($y, $fs, count($lines));
            $ok = $top >= $box['y'] + I18N_PAD && $bottom <= $box['y'] + $box['h'] - I18N_PAD;
            if ($ok) {
                foreach (i18n_text_nodes($doc) as $other) {
                    if ($other->isSameNode($t)) {
                        continue;
                    }
                    $ob = i18n_container($doc, $other)['bounds'];
                    if ($ob['x'] !== $box['x'] || $ob['y'] !== $box['y']) {
                        continue;   // different box, no collision risk
                    }
                    $oy = (float) ($other->getAttribute('y') ?: 0);
                    if ($oy <= $y) {
                        continue;
                    }
                    if ($bottom > $oy - $fs * 0.8) {
                        $ok = false;
                        break;
                    }
                }
            }
            if ($ok) {
                $xAttr = $t->getAttribute('x');
                while ($t->firstChild) {
                    $t->removeChild($t->firstChild);
                }
                foreach ($lines as $n => $line) {
                    $tspan = $doc->createElementNS($doc->documentElement->namespaceURI, 'tspan');
                    $tspan->setAttribute('x', $xAttr);
                    if ($n > 0) {
                        $tspan->setAttribute('dy', (string) round($fs * LINE_HEIGHT, 1));
                    }
                    $tspan->appendChild($doc->createTextNode($line));
                    $t->appendChild($tspan);
                }
                continue;
            }
        }

        $failures[] = [
            'key' => $key,
            'reason' => sprintf(
                'needs %.1fpx, has %.1fpx after shrink to %.0f%% of %.2f and layout/wrap attempts; container=%s %.0fx%.0f',
                FontMetrics::width($text, $fs, $bold), $room, I18N_MIN_SCALE * 100, $fs0,
                $cont['label'], $box['w'], $box['h']
            ),
        ];
    }
    return $failures;
}

// ---------------------------------------------------------------------------

$lang = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--lang=')) {
        $lang = substr($a, 7);
    }
}
$dry = in_array('--dry-run', $argv, true);

if ($lang === null || !preg_match('/^[a-z]{2}(-[A-Za-z]{2,4})?$/', $lang)) {
    fwrite(STDERR, "usage: php tools/i18n/generate.php --lang=<code> [--dry-run]\n");
    exit(2);
}

// Before anything is written: a bad output root can only be reported, not
// worked around, and reporting it after the diagrams are on disk leaves a
// half-written locale behind.  i18n_out_depth() is where the root is validated.
i18n_out_depth();

$manifest = i18n_load_manifest();
$glossaryPath = i18n_glossary_path($lang);
if (!is_file($glossaryPath)) {
    fwrite(STDERR, "missing glossary: $glossaryPath\n");
    exit(2);
}
$glossary = json_decode((string) file_get_contents($glossaryPath), true, 512, JSON_THROW_ON_ERROR);
$meta = $glossary['_meta'] ?? [];
unset($glossary['_meta']);

$rtl = ($meta['dir'] ?? 'ltr') === 'rtl';
$failed = 0;

// The machine-translation notice is not optional for a translated locale.
// Language agents supply their own wording in _meta.notice; 'en' is a source
// language of this project (README.EN.md) and must not carry one.
$notice = $meta['notice'] ?? null;
if ($lang === 'en') {
    if ($notice !== null && $notice !== '') {
        fwrite(STDERR, "glossary/en.json: _meta.notice must be null — English is a source language, not a translation\n");
        exit(1);
    }
} elseif (!is_string($notice) || trim($notice) === '') {
    fwrite(STDERR, "glossary/$lang.json: _meta.notice is required and must be non-empty — every "
        . "translated locale carries a 'machine translation, not reviewed by a native speaker' notice\n");
    exit(1);
}
// Same sentence, stripped of Markdown, for the SVG <desc> element.
$noticePlain = i18n_notice_plain($notice);

// Validate the glossary against the manifest before touching any output: a
// half-translated glossary must not produce half-translated diagrams.
$expected = [];
foreach (I18N_DOCS as $doc) {
    $expected += array_fill_keys(array_keys(i18n_doc_keys($manifest, $doc)), $doc);
}
$missing = array_values(array_diff(array_keys($expected), array_keys($glossary)));
$extra = array_values(array_diff(array_keys($glossary), array_keys($expected)));
if ($missing || $extra) {
    foreach (array_slice($missing, 0, 40) as $k) {
        fwrite(STDERR, "glossary missing key: $k\n");
    }
    foreach (array_slice($extra, 0, 40) as $k) {
        fwrite(STDERR, "glossary has unknown key: $k\n");
    }
    fwrite(STDERR, sprintf("glossary/%s.json: %d missing, %d unknown (expected %d keys)\n",
        $lang, count($missing), count($extra), count($expected)));
    exit(1);
}

foreach (I18N_DOCS as $doc) {
    $tpl = i18n_load_svg(i18n_template_path($doc));
    $keysByIndex = [];

    // Substitute placeholders.  <text> nodes are matched by document order,
    // which is exactly how extract.php assigned their keys.
    foreach (i18n_text_nodes($tpl) as $i => $t) {
        $idx = $i + 1;
        $raw = $t->textContent;
        if (!preg_match('/^\{\{(.+)\}\}$/', trim($raw), $m)) {
            fwrite(STDERR, "template $doc text #$idx has no placeholder: " . substr($raw, 0, 40) . "\n");
            exit(1);
        }
        $keysByIndex[$idx] = $m[1];
        while ($t->firstChild) {
            $t->removeChild($t->firstChild);
        }
        $t->appendChild($tpl->createTextNode($glossary[$m[1]]));
    }
    foreach (['title', 'desc'] as $tag) {
        $el = i18n_meta_node($tpl, $tag);
        if ($el === null) {
            continue;
        }
        $m = [];
        if (!preg_match('/^\{\{(.+)\}\}$/', trim($el->textContent), $m)) {
            fwrite(STDERR, "template $doc <$tag> has no placeholder\n");
            exit(1);
        }
        while ($el->firstChild) {
            $el->removeChild($el->firstChild);
        }
        $el->appendChild($tpl->createTextNode($glossary[$m[1]]));
        // A translated diagram is a machine translation too, and it is reachable
        // on its own (raw URL, image view), so the notice travels with it.
        if ($tag === 'desc' && $noticePlain !== '') {
            $el->appendChild($tpl->createTextNode(' ' . $noticePlain));
        }
    }

    if ($rtl) {
        // text-anchor resolves against the inline base direction, so switching
        // to rtl swaps what "start" and "end" mean.  Without this compensating
        // swap every start-anchored label jumps left by its own width — measured
        // in calibrate.php --rtl.
        $tpl->documentElement->setAttribute('direction', 'rtl');
        foreach (i18n_text_nodes($tpl) as $t) {
            // A run with no strong RTL character is not an RTL sentence and must
            // not be laid out as one: under the document's rtl direction the
            // bidi algorithm resolves its edge neutrals to the *paragraph*
            // direction, so `serve()` renders as `()serve` — 20 of ar's runs
            // came out this way.  Pinning the run back to ltr is both the fix
            // and the cheaper branch: an ltr run needs no compensation, so its
            // anchor stays exactly where the source put it, and
            // i18n_effective_anchor() already reads a run's own direction first,
            // which leaves every geometry caller untouched.
            if (!i18n_has_rtl(i18n_flat_text($t))) {
                $t->setAttribute('direction', 'ltr');
                continue;
            }
            // The swap has to run on the *effective* anchor, not on the
            // attribute: text-anchor defaults to "start" and the source
            // diagrams almost never write it out — 160 of the 197 runs rely on
            // the default.  Swapping only the written attributes left those 160
            // runs to fall back to the rtl default, which puts them to the LEFT
            // of x instead of the right, i.e. outside their own box.
            $a = $t->getAttribute('text-anchor') ?: 'start';
            $t->setAttribute('text-anchor', match ($a) {
                'start' => 'end',
                'end'   => 'start',
                default => 'middle',
            });
        }
    }

    $failures = autofit($tpl, $keysByIndex);
    foreach ($failures as $f) {
        fwrite(STDERR, sprintf("OVERFLOW %s: %s\n", $f['key'], $f['reason']));
        $failed++;
    }

    $out = i18n_out_dir($lang) . "/images/$doc.svg";
    if ($failures) {
        // Do not leave an overflowing diagram on disk for someone to commit.
        printf("SKIPPED %-14s (not written, %d unfittable run(s))\n", $doc, count($failures));
    } elseif (!$dry) {
        i18n_write_svg($tpl, $out);
        printf("wrote   %-14s -> %s  (%d texts)\n", $doc,
            str_replace(I18N_REPO . '/', '', $out), count($keysByIndex));
    } else {
        printf("would write %-10s -> %s  (%d texts)\n", $doc,
            str_replace(I18N_REPO . '/', '', $out), count($keysByIndex));
    }
}

// ---------------------------------------------------------------------------
// README for the locale: link rewriting + machine-translation notice
// ---------------------------------------------------------------------------

$readmeSource = $lang === 'en'
    ? I18N_REPO . '/README.EN.md'
    : i18n_input_root() . "/readme/$lang.md";

if (!is_file($readmeSource)) {
    fwrite(STDERR, "missing README source: $readmeSource\n");
    exit(1);
}

$body = (string) file_get_contents($readmeSource);

/**
 * Rewrite root-relative asset links for a README that now lives in
 * <outroot>/<lang>/.  The three translated diagrams point at this locale's own
 * copies; every other docs/ asset still points at the original.
 */
$translated = array_fill_keys(I18N_DOCS, true);
$up = str_repeat('../', i18n_out_depth());
$rewrite = function (string $url) use ($translated, $up): string {
    if (preg_match('#^(?:\./)?docs/(.+)$#', $url, $m)) {
        $rest = $m[1];
        $base = basename($rest);
        if (isset($translated[pathinfo($base, PATHINFO_FILENAME)])
            && pathinfo($base, PATHINFO_EXTENSION) === 'svg'
            && dirname($rest) === 'images') {
            return './images/' . $base;
        }
        return $up . 'docs/' . $rest;
    }
    return $url;
};

// Markdown links/images
$body = preg_replace_callback(
    '/\]\(([^)\s]+)\)/',
    fn($m) => '](' . $rewrite($m[1]) . ')',
    $body
);
// Raw HTML src attributes
$body = preg_replace_callback(
    '/(<img[^>]+src=")([^"]+)(")/i',
    fn($m) => $m[1] . $rewrite($m[2]) . $m[3],
    $body
);

// The notice first, then the language switcher, then the translation.  Both
// belong above the first heading, where a reader who has landed in the wrong
// language will actually see them.
$body = ($notice !== null ? $notice . "\n\n" : '') . i18n_language_switcher($lang) . "\n\n" . $body;

$readmeOut = i18n_out_dir($lang);
if ($dry) {
    // --dry-run must not touch the tree: check.php calls it to ask "would the
    // generator refuse this locale?", and a gate that writes is not a gate.
    // The README was the leak here — the SVG loop below was guarded and this
    // was not, so a dry run still rewrote README.md (identical bytes, so only
    // the mtime gave it away).
    printf("would write %-10s -> %s/README.md  (%d bytes from %s)\n",
        'README', str_replace(I18N_REPO . '/', '', $readmeOut), strlen($body),
        str_replace(I18N_REPO . '/', '', $readmeSource));
} else {
    if (!is_dir($readmeOut)) {
        mkdir($readmeOut, 0755, true);
    }
    file_put_contents("$readmeOut/README.md", $body);
    printf("wrote   README         -> %s/README.md  (%d bytes from %s)\n",
        str_replace(I18N_REPO . '/', '', $readmeOut), strlen($body),
        str_replace(I18N_REPO . '/', '', $readmeSource));
}

exit($failed ? 1 : 0);
