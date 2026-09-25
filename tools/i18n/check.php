<?php
declare(strict_types=1);

/**
 * tools/i18n/check.php — verify one locale's generated output.
 *
 *   php tools/i18n/check.php --lang=en
 *   php tools/i18n/check.php --lang=en --quiet      # hide the "ok" lines
 *
 * --quiet silences only the passing lines.  Warnings are actionable findings
 * that a translator has to judge, so they are never suppressed — a run that
 * hides them reads as all-clear while printing a warning count.
 *
 * Eight checks, all mechanical.  None of them can tell you whether a
 * translation is *good* — only whether it is structurally sound:
 *
 *   refusal   the generator would still accept this locale.  A refusal writes
 *             nothing, so without this the checks below run on a stale copy of
 *             the last successful render
 *   overflow  every text run fits the smallest <rect> containing its anchor
 *             point, or the canvas if no rect does
 *   crossing  no text run is drawn across a neighbouring badge/card (warning)
 *   overlap   no two runs collide, and nothing is within NEAR_MISS_PX of one
 *             — see the rule below
 *   skeleton  same element sequence, nesting depth and colours as the source
 *   wording   "copy" keys are byte-identical to the source; every "code" key
 *             still contains each token it was told to preserve
 *   links     every relative link and in-page anchor in the locale's README
 *             resolves on disk
 *   rtl       for an rtl locale: every run is on the side the source put it on,
 *             and no run is left for the bidi algorithm to reorder
 *
 * Two rules cannot be stated without saying what they rest on, because both are
 * only as good as the width model behind them, and the model is exact for
 * Latin and CJK and a per-script mean for everything else:
 *
 *   overlap   an intersection of ink fails — but only when both runs are in a
 *             script the model was measured against.  For an approximate script
 *             the run may be charged ink it does not have, "colliding" with a
 *             neighbour it never touches (measured on the worst run of each
 *             locale here: +54% Cyrillic, +72% Arabic, +85% Bengali), so those
 *             findings warn and do not fail the gate.
 *   rtl       a run with no strong RTL character must be pinned
 *             direction="ltr".  Left under the document's rtl direction, bidi
 *             moves its edge neutrals to the wrong end — `serve()` renders as
 *             `()serve`.  The anchor check cannot see that; this one can.
 *
 * See OVERLAP_FAIL_PX below and tools/i18n/README.md for the measurements.
 *
 * Exit 0 only if every hard check passes.
 */

require __DIR__ . '/lib.php';

$lang = null;
$quiet = in_array('--quiet', $argv, true);
foreach ($argv as $a) {
    if (str_starts_with($a, '--lang=')) {
        $lang = substr($a, 7);
    }
}
if ($lang === null) {
    fwrite(STDERR, "usage: php tools/i18n/check.php --lang=<code> [--quiet]\n");
    exit(2);
}

$manifest = i18n_load_manifest();
// Same guard, wording and exit code as generate.php: a locale with no glossary
// is not a locale, and `check.php --lang=zh` used to die in json_decode with a
// stack trace instead of saying so.  zh is a source language — the Chinese
// original is the root README.md — so there is nothing here to check.
$glossaryPath = i18n_glossary_path($lang);
if (!is_file($glossaryPath)) {
    fwrite(STDERR, "missing glossary: $glossaryPath\n");
    exit(2);
}
$glossary = json_decode((string) file_get_contents($glossaryPath), true, 512, JSON_THROW_ON_ERROR);
$meta = $glossary['_meta'] ?? [];
$notice = array_key_exists('notice', $meta) ? $meta['notice'] : 'default';
// Same sentence with the Markdown stripped, which is what has to appear in the
// SVG <desc> — generate.php writes exactly this form.
$noticePlain = is_string($notice) ? i18n_notice_plain($notice) : '';

/**
 * Overlap threshold, px.
 *
 * Measured basis: the untranslated originals in docs/images/ collide nowhere
 * across all 197 runs.  That measurement is exact for the scripts the model was
 * measured on, and those are the only scripts it proves anything about — the
 * originals are Chinese, so it says the rule holds for CJK, not that two runs
 * on one baseline can never be right.
 *
 * Softened twice, for two different reasons and in two different directions:
 *
 *   - sub-pixel.  The model is an estimate; two glyph boxes may meet without
 *     their ink meeting.  Below a pixel the check cannot tell a collision from
 *     a touch, so it warns.
 *   - approximate scripts.  For a script the model has no measured table for,
 *     the width is a mean that can be far too wide, so the collision may not
 *     exist in a renderer at all.  Those warn too — see the overlap block.
 *
 * So the FAIL is reserved for what the model can actually vouch for.
 */
const OVERLAP_FAIL_PX = 1.0;   // >= this much horizontal ink overlap fails

/**
 * Clearance below which two runs that do *not* touch still warn, px.
 *
 * The FAIL above answers "is this tree broken?".  It cannot answer "is this
 * tree one reworded sentence away from broken?" — and that is the question
 * that matters to whoever is about to edit a translation.  A pair 1.4px clear
 * passes today and fails tomorrow, silently, on a change nobody had reason to
 * think was risky.  This threshold makes that pair visible while it is still
 * safe to change.
 *
 * Measured across the twelve locales of this tree, it fires 4-15 times per
 * locale, and the fired pairs divide by axis:
 *
 *   - diagonal, 15-20px: the gap the layout leaves between adjacent boxes.
 *     Identical in every locale, because it is drawn, not translated.  These
 *     are listed so the number is not a surprise when a box is resized.
 *   - horizontal: the fragile set.  fr's lifecycle#37 & #38 sit 1.4px apart on
 *     one baseline — one reworded word from a collision, and nothing else in
 *     the pipeline would have said so.
 *
 * The vertical axis is deliberately *not* part of this threshold.  A band's
 * height does not move when a translation is reworded — it changes only by
 * wrapping to a new line, which grows it a whole line height and is caught by
 * the overlap rule itself.  A threshold on it fires on the row pitch instead:
 * at 20px that is ~125 warnings per locale, and 1070 of the 1125 measured were
 * vertical.  What the vertical axis can say is finer than a threshold, so it is
 * said instead by the row-pitch warning and the per-document readout below.
 *
 * The clearance is computed in the model's own units.  The measured ink table
 * (I18N_INK_DEFAULT) is deliberately not used to decide this: it applies a
 * script's worst case to every run, and rendering four of the pairs it flags in
 * ar showed every one of them clear by 1-4px with empty pixel rows between —
 * the model had the clearance right to within 0.4px and the table was wrong in
 * the same direction on all four.  A table that over-charges cannot set a
 * threshold without inventing findings; it is used only where its value is
 * labelled as what it is, a worst case.
 *
 * Overridable with I18N_NEAR_MISS_PX for tuning; 0 disables it.
 */
const NEAR_MISS_PX = 20.0;

$fail = 0;
$warn = 0;
$fails = [];
$warns = [];

function ok(string $s): void { global $quiet; if (!$quiet) { echo "  ok    $s\n"; } }
function bad(string $s): void { global $fail, $fails; $fail++; $fails[] = $s; echo "  FAIL  $s\n"; }
function warn(string $s): void { global $warn, $warns; $warn++; $warns[] = $s; echo "  warn  $s\n"; }

// ---------------------------------------------------------------------------
// -- would the generator refuse this locale? --------------------------------
// generate.php refuses by *writing nothing* and exiting non-zero, so a locale
// it refuses keeps the diagrams from the last run that succeeded.
//
// That state is reported below — the glossary-vs-file comparison reddens on the
// stale text — but it reports the wrong cause: "file text does not match
// glossary" reads as someone hand-editing the SVG, when the real cause is that
// the translation no longer fits and the generator declined to write it.  The
// cost of that misdiagnosis is what bn paid: a human diffing two locales by
// hand to find out why one diagram stopped matching its glossary.
//
// So ask the generator itself, and say what it says.  --dry-run is the one mode
// that cannot write (content and mtimes both verified untouched), it costs
// 0.09s, and it is the same code path the refusal comes from rather than a
// second implementation of the same rule that could drift from it.
if (!function_exists('exec')) {
    warn('cannot run generate.php --dry-run (exec() is disabled), so a refused locale would'
        . ' keep a stale diagram without this gate noticing');
} else {
    $genOut = [];
    $genRc = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(I18N_DIR . '/generate.php')
        . ' --lang=' . escapeshellarg($lang) . ' --dry-run 2>&1', $genOut, $genRc);
    if ($genRc !== 0) {
        // Only the lines that say *why*.  The tail of a dry run is otherwise
        // "would write <doc>" for every doc it is still happy with, which
        // buries the two lines that matter.
        $why = implode("\n         ", array_slice(array_values(array_filter(
            $genOut,
            static fn($l): bool => trim($l) !== '' && !preg_match('/^(would write|wrote)\b/', $l)
        )), -6));
        bad("the generator refuses this locale (exit $genRc) — every diagram below is a stale copy"
            . " from the last run that succeeded, so they prove nothing.  generate.php said:\n"
            . "         $why");
    }
}

// ---------------------------------------------------------------------------
echo "== generated diagrams ==\n";

foreach (I18N_DOCS as $doc) {
    $srcPath = I18N_REPO . "/docs/images/$doc.svg";
    $outPath = i18n_out_dir($lang) . "/images/$doc.svg";
    if (!is_file($outPath)) {
        bad("$doc: missing $outPath — run generate.php");
        continue;
    }
    $out = i18n_load_svg($outPath);
    $src = i18n_load_svg($srcPath);

    // -- machine-translation notice, on the diagram itself ------------------
    // A translated SVG is reachable without its README (raw URL, image view),
    // so the notice has to travel inside the file too.
    if ($noticePlain !== '') {
        $desc = i18n_meta_node($out, 'desc');
        $raw = $desc?->textContent ?? '';
        // Both sides normalised: the notice is authored in Markdown for the
        // README, but a <desc> is plain text, where a link renders as literal
        // syntax — and its target is written for the README's directory, so
        // from docs/i18n/<lang>/images/ it resolves one level short.
        $plain = i18n_notice_plain($raw);
        if (!str_contains($plain, $noticePlain)) {
            bad("$doc: <desc> does not carry the machine-translation notice");
        } elseif (str_contains($raw, '](')) {
            // Written before the notice was flattened.  Warning, not failure:
            // the file is correct in what it says, and each locale clears this
            // by regenerating — which only its own agent can do.
            warn("$doc: <desc> still carries the notice as Markdown — regenerate to drop the literal link");
        }
    }

    // -- overflow ----------------------------------------------------------
    $n = 0;
    $over = 0;
    $worst = ['slack' => INF, 'key' => '-'];
    foreach (i18n_text_nodes($out) as $i => $t) {
        $n++;
        $key = "{$doc}#" . ($i + 1);
        $cont = i18n_container($out, $t);
        $box = $cont['bounds'];
        $w = i18n_node_width($t);
        [$l, $r] = i18n_text_extent($t, $w);
        $s = min($l - $box['x'], $box['x'] + $box['w'] - $r);
        if ($s < $worst['slack']) {
            $worst = ['slack' => $s, 'key' => $key, 'text' => i18n_flat_text($t), 'cont' => $cont['label']];
        }
        if ($s < 0) {
            $over++;
            bad(sprintf('%s: overflow %.1fpx — "%s" needs %.1fpx in %s (%gx%g at %g,%g)',
                $key, $s, mb_substr(i18n_flat_text($t), 0, 44), $w,
                $cont['label'], $box['w'], $box['h'], $box['x'], $box['y']));
        }
        if (str_contains(i18n_flat_text($t), '{{')) {
            bad(sprintf('%s: unreplaced placeholder "%s"', $key, i18n_flat_text($t)));
        }
    }

    // -- crossing (warning): a run drawn across a neighbouring small box ----
    foreach (i18n_text_nodes($out) as $i => $t) {
        $key = "{$doc}#" . ($i + 1);
        $cont = i18n_container($out, $t);
        $w = i18n_node_width($t);
        [$l, $r] = i18n_text_extent($t, $w);
        $fs = i18n_font_size($t);
        $y = (float) ($t->getAttribute('y') ?: 0);
        foreach (i18n_rects($out) as $rect) {
            $b = i18n_rect_bounds($rect);
            if ($cont['rect'] !== null && $rect->isSameNode($cont['rect'])) {
                continue;
            }
            // Only discrete UI pieces, not the full-bleed background panels.
            if ($b['w'] > 250 || $b['w'] < 4 || $b['h'] < 4) {
                continue;
            }
            if ($y < $b['y'] || $y > $b['y'] + $b['h']) {
                continue;
            }
            // "Crosses" means the run straddles one of the box's vertical edges.
            // Mere containment is normal — a badge label sits inside its badge.
            $straddles = ($l < $b['x'] && $r > $b['x'])
                || ($l < $b['x'] + $b['w'] && $r > $b['x'] + $b['w']);
            if ($straddles) {
                warn(sprintf('%s: "%s" crosses a %gx%g box at %g,%g',
                    $key, mb_substr(i18n_flat_text($t), 0, 34), $b['w'], $b['h'], $b['x'], $b['y']));
            }
        }
    }

    // -- overlap: two runs sharing space they must not share ----------------
    // Any ink intersection fails; sub-pixel ones warn.  See OVERLAP_FAIL_PX
    // for the measured basis and README.md for the full argument.
    //
    // A collision is only as trustworthy as the width model behind it.  For a
    // script the model has never been measured against a real font, the width
    // is a per-script mean, and the mean can be far too wide — measured on this
    // tree, Cyrillic by 25-44%, Devanagari and Bengali by similar factors.  A
    // run charged too much ink "collides" with a neighbour it never touches, so
    // for those scripts the finding is reported but does not fail the gate.
    // FontMetrics::isApproximate() is the single source of that distinction and
    // generate.php does not need it: it only ever fits a run to its own box.
    $runs = [];
    foreach (i18n_text_nodes($out) as $i => $t) {
        $text = i18n_flat_text($t);
        $runs[] = [
            'key'    => "{$doc}#" . ($i + 1),
            'text'   => $text,
            'band'   => i18n_text_band($t),
            'ink'    => i18n_ink_band($t),
            'y'      => (float) ($t->getAttribute('y') ?: 0),
            'ext'    => i18n_text_extent($t, i18n_node_width($t)),
            'approx' => FontMetrics::isApproximate($text),
        ];
    }
    $nOverlap = 0;
    $nSub = 0;
    $nApprox = 0;
    $nNear = 0;
    $nInk = 0;
    $vWorst = INF;
    $vWorstPair = '-';
    // ?? and not ?: — the docblock below promises that 0 disables this, and
    // getenv('...') returns the string "0", which is falsy, so ?: would quietly
    // restore 20 and the off switch would not exist.
    $nmEnv = getenv('I18N_NEAR_MISS_PX');
    $nearPx = $nmEnv === false || $nmEnv === '' ? NEAR_MISS_PX : (float) $nmEnv;
    foreach ($runs as $i => $a) {
        foreach (array_slice($runs, $i + 1) as $b) {
            $h = min($a['ext'][1], $b['ext'][1]) - max($a['ext'][0], $b['ext'][0]);
            $v = min($a['band'][1], $b['band'][1]) - max($a['band'][0], $b['band'][0]);
            $pair = sprintf('%s & %s', $a['key'], $b['key']);
            $who = sprintf('"%s" x "%s"', mb_substr($a['text'], 0, 30), mb_substr($b['text'], 0, 30));
            $gap = abs($a['y'] - $b['y']);
            // Both axes must overlap; a run passing above or beside another
            // shares no ink however wide it is.  But "not touching" is not the
            // same as "safe" — that is the near-miss case below.
            if ($h <= 0 || $v <= 0) {
                if ($h > 0) {
                    // Stacked in one column and the model cleared them: -$v is
                    // the clearance it claims.  Its tightest member rides on the
                    // ok line below rather than getting a warning each, because
                    // this is the population the layout's row pitch produces and
                    // an alarm per pair would be ~125 lines per locale.
                    if (-$v < $vWorst) { $vWorst = -$v; $vWorstPair = $pair; }
                    // $vi > 0 means the script's *tallest measured* ink would
                    // reach past the gap the model left.  Read it as headroom,
                    // not as a collision: I18N_INK_DEFAULT applies the script's
                    // worst case to every run, so for ar this fires on rows whose
                    // glyphs are nowhere near that tall — pixel-checked on four
                    // of these pairs, all of which have empty rows between them
                    // and clear by 1-4px.  What is true is that the row pitch is
                    // too tight to *contain* the script's worst case, so a
                    // taller glyph in either row would collide and the width
                    // model would not see it coming.
                    $vi = min($a['ink'][1], $b['ink'][1]) - max($a['ink'][0], $b['ink'][0]);
                    if ($vi > 0) {
                        $nInk++;
                        warn(sprintf('%s: the row pitch leaves %.1fpx (baselines %.1fpx apart)'
                            . ' and this script\'s tallest measured ink reaches %.1fpx past that —'
                            . ' these two strings do not touch, but a taller glyph in either row'
                            . ' would, and the width model cannot see it — %s',
                            $pair, -$v, $gap, $vi, $who));
                    }
                } elseif ($nearPx > 0.0) {
                    // Side by side: the fragile axis.  A reworded sentence gets
                    // wider, so a small clearance here is one edit away from a
                    // collision, and unlike the vertical case it moves with
                    // every translation change.  Measured in model units: this
                    // is the model the rendered pairs agreed with, and the ink
                    // table's per-script worst case is not fit to set a
                    // threshold — see NEAR_MISS_PX.
                    $sep = sqrt($h ** 2 + max(0.0, -$v) ** 2);
                    if ($sep < $nearPx) {
                        $nNear++;
                        warn(sprintf('%s: %s clearance %.1fpx, under the %gpx near-miss threshold'
                            . ' — reword with room to spare — %s',
                            $pair, $v > 0 ? 'horizontal' : 'diagonal', $sep, $nearPx, $who));
                    }
                }
                continue;
            }
            if ($h < OVERLAP_FAIL_PX) {
                $nSub++;
                warn(sprintf('%s: ink overlaps %.2fpx, under the %gpx threshold (baselines %.1fpx apart) — %s',
                    $pair, $h, OVERLAP_FAIL_PX, $gap, $who));
            } elseif ($a['approx'] || $b['approx']) {
                $nApprox++;
                $which = $a['approx'] && $b['approx'] ? 'both runs are'
                    : ($a['approx'] ? 'the first run is' : 'the second run is');
                warn(sprintf('%s: model says ink overlaps %.1fpx (baselines %.1fpx apart), but %s'
                    . ' scored against a per-script estimate, not a measurement — run `php tools/i18n/calibrate.php'
                    . ' --lang=%s` and check the pair in a real renderer before rewording — %s',
                    $pair, $h, $gap, $which, $lang, $who));
            } else {
                $nOverlap++;
                bad(sprintf('%s: ink overlaps %.1fpx (baselines %.1fpx apart) — %s. Judged by the'
                    . ' width model, which is a table of glyph advances and is exact for these scripts'
                    . ' only on a font stack that resolves the way it was measured; confirm with'
                    . ' `php tools/i18n/calibrate.php --lang=%s` in a real renderer before rewording',
                    $pair, $h, $gap, $who, $lang));
            }
        }
    }
    if (!$nOverlap && !$nSub && !$nApprox && !$nInk) {
        // The tightest vertically-stacked pair rides along here rather than
        // getting a warning of its own: this number is a property of the row
        // pitch, not of any one sentence, so a per-pair alarm would be 125
        // lines of wallpaper per locale.  It is the model's clearance, not the
        // ink table's — the pixels agreed with the model to within 0.4px on
        // every pair that was rendered, and disagreed with the ink table's
        // worst-case figure on all of them.
        ok(sprintf('%s: %d text runs, no two overlap; tightest vertical clearance %.1fpx'
            . ' (%s, model band)',
            $doc, count($runs), $vWorst === INF ? 0.0 : $vWorst, $vWorstPair));
    }

    // -- skeleton ----------------------------------------------------------
    $sig = function (DOMDocument $d): array {
        $out = [];
        $walk = function (DOMNode $node, int $depth) use (&$walk, &$out): void {
            foreach ($node->childNodes as $c) {
                if ($c->nodeType !== XML_ELEMENT_NODE) {
                    continue;
                }
                if (strtolower($c->nodeName) === 'tspan') {
                    continue;   // wrapping adds tspans; that is layout, not structure
                }
                $out[] = sprintf('%s|%d|%s|%s|%s', strtolower($c->nodeName), $depth,
                    $c->getAttribute('fill'), $c->getAttribute('stroke'), $c->getAttribute('stroke-width'));
                $walk($c, $depth + 1);
            }
        };
        $walk($d->documentElement, 0);
        return $out;
    };
    $a = $sig($src);
    $b = $sig($out);
    if ($a === $b) {
        ok(sprintf('%s: skeleton identical (%d elements, colours match)', $doc, count($a)));
    } else {
        $where = 'length ' . count($a) . ' -> ' . count($b);
        foreach ($a as $k => $v) {
            if (($b[$k] ?? null) !== $v) {
                $where = "index $k: $v -> " . ($b[$k] ?? '(missing)');
                break;
            }
        }
        bad("$doc: skeleton differs ($where)");
    }

    // -- rtl: every run anchored on the swapped side -----------------------
    // text-anchor resolves against the inline base direction, so under
    // direction="rtl" the meanings of start and end trade places (measured —
    // see calibrate.php --rtl).  The generator compensates by swapping them;
    // this is the check that it really did, on the *effective* anchor rather
    // than the written attribute, because the default is "start".
    //
    // The one exemption is a run the generator pinned back to ltr: that run has
    // no strong RTL character, so it is not an RTL line and needs no
    // compensation — it must still be on the side the source put it on.
    if (($meta['dir'] ?? 'ltr') === 'rtl') {
        $srcNodes = i18n_text_nodes($src);
        $wrong = 0;
        $k = 0;
        $nLtr = 0;
        foreach (i18n_text_nodes($out) as $i => $t) {
            $k++;
            $srcAnchor = $srcNodes[$i]->getAttribute('text-anchor') ?: 'start';
            if ($t->getAttribute('direction') === 'ltr') {
                $nLtr++;
                if (($t->getAttribute('text-anchor') ?: 'start') !== $srcAnchor) {
                    $wrong++;
                }
                continue;
            }
            $want = match ($srcAnchor) {
                'start' => 'end',
                'end'   => 'start',
                default => 'middle',
            };
            if ($t->getAttribute('text-anchor') !== $want) {
                $wrong++;
            }
        }
        if ($wrong) {
            bad("$doc: $wrong of $k runs are not anchored on the side rtl requires"
                . " ($nLtr pinned direction=\"ltr\", the rest swapped)");
        } else {
            ok("$doc: all $k runs anchored on the side rtl requires ($nLtr pinned ltr, "
                . ($k - $nLtr) . ' swapped)');
        }

        // -- rtl: no run may be left for the bidi algorithm to reorder -------
        // A run with no strong RTL character, left under the document's rtl
        // direction, has its edge neutrals resolved to the paragraph direction:
        // `serve()` renders as `()serve`, `&& extension_` as `extension_&&`.
        // The anchor check above cannot see this — the geometry is right and the
        // characters are in the right order *in the file*.  Measured in ar: 20
        // runs, all copy keys.  So assert the generator's rule directly.
        $unmarked = 0;
        $examples = [];
        foreach (i18n_text_nodes($out) as $i => $t) {
            if (i18n_has_rtl(i18n_flat_text($t)) || $t->getAttribute('direction') === 'ltr') {
                continue;
            }
            $unmarked++;
            if (count($examples) < 3) {
                $examples[] = mb_substr(i18n_flat_text($t), 0, 24);
            }
        }
        if ($unmarked) {
            bad(sprintf('%s: %d run(s) with no RTL character are not pinned direction="ltr"'
                . ' — bidi reorders their edge neutrals (e.g. "%s")',
                $doc, $unmarked, implode('", "', $examples)));
        } else {
            ok("$doc: every run without an RTL character is pinned direction=\"ltr\"");
        }
    }

    // -- geometry sanity: nothing may leave the canvas ---------------------
    $canvas = i18n_canvas($out);
    foreach (i18n_text_nodes($out) as $i => $t) {
        $w = i18n_node_width($t);
        [$l, $r] = i18n_text_extent($t, $w);
        if ($l < $canvas['x'] - 0.01 || $r > $canvas['x'] + $canvas['w'] + 0.01) {
            bad(sprintf('%s#%d: run leaves the canvas (%.1f..%.1f, canvas %.0f..%.0f)',
                $doc, $i + 1, $l, $r, $canvas['x'], $canvas['x'] + $canvas['w']));
        }
    }

    // The summary line must not say "ok" next to a negative slack: on an
    // overflowing document it is a report, not a verdict.
    if ($over) {
        printf("  --    %s: %d text runs, %d overflowing, worst %.1fpx (%s)\n",
            $doc, $n, $over, $worst['slack'], $worst['key']);
    } else {
        ok(sprintf('%s: %d text runs, min slack %.1fpx (%s)',
            $doc, $n, $worst['slack'], $worst['key']));
    }
}

// ---------------------------------------------------------------------------
echo "== wording ==\n";

$copyBad = 0;
$codeBad = 0;
$checked = 0;
foreach (I18N_DOCS as $doc) {
    foreach (i18n_doc_keys($manifest, $doc) as $key => $node) {
        if (!array_key_exists($key, $glossary)) {
            bad("$key: missing from glossary");
            continue;
        }
        $val = $glossary[$key];
        $checked++;
        if ($node['class'] === 'copy' && $val !== $node['source']) {
            bad(sprintf('%s: is "copy" but changed: %s -> %s', $key, $node['source'], $val));
            $copyBad++;
        }
        if ($node['class'] === 'code') {
            foreach ($node['keep'] as $tok) {
                if (!str_contains($val, $tok)) {
                    bad(sprintf('%s: translation dropped required token "%s"', $key, $tok));
                    $codeBad++;
                }
            }
        }
    }
}
if (!$copyBad && !$codeBad) {
    $nCopy = 0;
    $nCode = 0;
    foreach (I18N_DOCS as $doc) {
        foreach (i18n_doc_keys($manifest, $doc) as $node) {
            if ($node['class'] === 'copy') { $nCopy++; }
            if ($node['class'] === 'code') { $nCode++; }
        }
    }
    ok(sprintf('%d keys checked: %d copy identical, %d code retain every required token', $checked, $nCopy, $nCode));
}

// ---------------------------------------------------------------------------
echo "== glossary vs generated files ==\n";

foreach (I18N_DOCS as $doc) {
    $outPath = i18n_out_dir($lang) . "/images/$doc.svg";
    if (!is_file($outPath)) {
        continue;
    }
    $out = i18n_load_svg($outPath);
    // index => key, so a generated run can be traced back to its glossary entry
    $byIndex = [];
    foreach (i18n_doc_keys($manifest, $doc) as $key => $node) {
        if ($node['index'] !== null) {
            $byIndex[$node['index']] = $key;
        }
    }
    $i = 0;
    $mismatch = 0;
    foreach (i18n_text_nodes($out) as $t) {
        $i++;
        $key = $byIndex[$i] ?? null;
        if ($key === null) {
            continue;
        }
        if (i18n_flat_text($t) !== trim((string) $glossary[$key])) {
            bad(sprintf('%s %s: file text does not match glossary ("%s" vs "%s")',
                $doc, $key, mb_substr(i18n_flat_text($t), 0, 30), mb_substr(trim((string) $glossary[$key]), 0, 30)));
            $mismatch++;
        }
    }
    if (!$mismatch) {
        ok("$doc: all $i runs match the glossary");
    }
}

// ---------------------------------------------------------------------------
echo "== machine-translation notice ==\n";

if ($lang === 'en') {
    // English is a source language of this project, not a machine translation
    // that nobody reviewed.  A notice here would be a false claim.
    if (is_string($notice) && trim($notice) !== '') {
        bad("glossary/$lang.json: _meta.notice is set, but en is a source language (README.EN.md) and must not carry one");
    } else {
        ok('glossary/en.json: no notice, as a source language should be');
    }
} elseif (!is_string($notice) || trim($notice) === '') {
    bad("glossary/$lang.json: _meta.notice is missing — a translated locale must state that it is a machine translation not reviewed by a native speaker");
} else {
    ok("glossary/$lang.json: notice declared (" . mb_substr($noticePlain, 0, 58) . '…)');
}

// ---------------------------------------------------------------------------
echo "== README links ==\n";

$readme = i18n_out_dir($lang) . "/README.md";
if (!is_file($readme)) {
    bad("missing $readme");
} else {
    $dir = dirname($readme);
    $md = (string) file_get_contents($readme);

    if ($noticePlain !== '') {
        $cut = preg_match('/^#/m', $md, $hm, PREG_OFFSET_CAPTURE) ? $hm[0][1] : strlen($md);
        // The README carries the notice as Markdown, so normalise it the same
        // way before comparing — otherwise the emphasis that is correct in a
        // .md file reads as a missing notice here.
        if (!str_contains(i18n_notice_plain(substr($md, 0, $cut)), $noticePlain)) {
            bad('README: the machine-translation notice does not appear above the first heading');
        } else {
            ok('README: machine-translation notice present above the first heading');
        }
    }

    $targets = [];
    if (preg_match_all('/\]\(([^)\s]+)\)/', $md, $m)) {
        foreach ($m[1] as $t) {
            $targets[] = ['target' => $t, 'kind' => 'markdown'];
        }
    }
    if (preg_match_all('/<img[^>]+src="([^"]+)"/i', $md, $m)) {
        foreach ($m[1] as $t) {
            $targets[] = ['target' => $t, 'kind' => 'img'];
        }
    }

    // GitHub-style heading slugs, for in-page anchors.
    $slugs = [];
    if (preg_match_all('/^#{1,6}\s+(.+)$/m', $md, $m)) {
        foreach ($m[1] as $h) {
            $s = mb_strtolower(trim($h), 'UTF-8');
            $s = preg_replace('/[^\p{L}\p{N}\p{M} _-]/u', '', $s);
            $s = preg_replace('/\s+/u', '-', $s);
            $slugs[$s] = true;
        }
    }

    $nRel = 0;
    $nAnchor = 0;
    $broken = 0;
    foreach ($targets as $t) {
        $target = $t['target'];
        if (preg_match('#^(https?:|mailto:|data:)#i', $target)) {
            continue;
        }
        if (str_starts_with($target, '#')) {
            $nAnchor++;
            $slug = rawurldecode(substr($target, 1));
            if (!isset($slugs[$slug])) {
                bad("$doc README: in-page anchor $target matches no heading");
                $broken++;
            }
            continue;
        }
        $nRel++;
        $path = rawurldecode(explode('#', $target)[0]);
        $abs = $dir . '/' . ltrim($path, '/');
        $real = realpath($abs);
        if ($real === false || !file_exists($real)) {
            bad("README: $target does not resolve (looked for $abs)");
            $broken++;
        } else {
            ok(sprintf('%-42s -> %s', $target, str_replace(I18N_REPO . '/', '', $real)));
        }
    }
    if (!$broken) {
        ok(sprintf('%s: %d relative link(s) and %d anchor(s) all resolve',
            str_replace(I18N_REPO . '/', '', $readme), $nRel, $nAnchor));
    }
}

// ---------------------------------------------------------------------------
printf("\n%s  %d failure(s), %d warning(s)\n", $fail ? 'RESULT: FAIL' : 'RESULT: PASS', $fail, $warn);
exit($fail ? 1 : 0);
