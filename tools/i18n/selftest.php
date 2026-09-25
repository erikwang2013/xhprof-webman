<?php
declare(strict_types=1);

/**
 * tools/i18n/selftest.php — prove that the checks actually fail.
 *
 *   php tools/i18n/selftest.php
 *
 * A check that has never been seen failing is not a check.  check.php and
 * generate.php are the gate every translated locale has to pass, so each of
 * their failure paths is exercised here against a throwaway locale, and the
 * exit status is asserted — not the wording of the message.
 *
 * Nothing is written with assert(): this box runs zend.assertions=-1, so an
 * assert() would be compiled out and the whole file would "pass" by doing
 * nothing.  Every case is an explicit comparison.
 *
 * The throwaway locale is zz.  It is written outside docs/i18n/ — that is the
 * delivery directory, and anything that globs it (the parity test, the checker,
 * a reader) must see deliverables only.  A locale that lives for three seconds
 * is not one.
 *
 * The repo is left exactly as it was found, via a shutdown handler that also
 * runs when a case throws.
 */

require __DIR__ . '/lib.php';

const SRC_LANG = 'en';
const TMP_LANG = 'zz';

/**
 * Scratch output root.  Its *depth* matters: locale READMEs link back to the
 * repository root with a computed number of `../`, so a scratch root has to sit
 * as far below the repo root as docs/i18n does.  Here that is four levels.
 */
const SCRATCH_OUT = I18N_REPO . '/.selftest/root/i18n';
const SCRATCH_TOP = I18N_REPO . '/.selftest';

$backup = sys_get_temp_dir() . '/i18n-selftest-' . getmypid();
$pass = 0;
$fail = 0;

/** Run a subcommand, optionally pointed at the scratch output root. */
function run(string $args, bool $scratch = false): array
{
    $out = [];
    $rc = 0;
    $env = $scratch ? 'I18N_OUT_ROOT=' . escapeshellarg(SCRATCH_OUT) . ' ' : '';
    exec($env . 'php ' . escapeshellarg(I18N_DIR . '/generate.php') . " $args 2>&1", $out, $rc);
    return [$rc, implode("\n", $out)];
}

function run_check(string $args, bool $scratch = false): array
{
    $out = [];
    $rc = 0;
    $env = $scratch ? 'I18N_OUT_ROOT=' . escapeshellarg(SCRATCH_OUT) . ' ' : '';
    exec($env . 'php ' . escapeshellarg(I18N_DIR . '/check.php') . " $args 2>&1", $out, $rc);
    return [$rc, implode("\n", $out)];
}

/** Run generate.php against an arbitrary output root. */
function run_root(string $root, string $args): array
{
    $out = [];
    $rc = 0;
    exec('I18N_OUT_ROOT=' . escapeshellarg($root) . ' php '
        . escapeshellarg(I18N_DIR . '/generate.php') . " $args 2>&1", $out, $rc);
    return [$rc, implode("\n", $out)];
}

function ok(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok    $name\n";
    } else {
        $fail++;
        echo "  FAIL  $name" . ($detail === '' ? '' : "  ($detail)") . "\n";
    }
}

/** Path inside the scratch tree: the localised artefacts for TMP_LANG. */
function tmp(string $rel = ''): string
{
    return SCRATCH_OUT . '/' . TMP_LANG . ($rel === '' ? '' : "/$rel");
}

// ---------------------------------------------------------------------------
// snapshot everything this script can disturb
// ---------------------------------------------------------------------------

$restore = static function () use ($backup): void {
    $en = I18N_DIR . '/glossary/' . SRC_LANG . '.json';
    if (is_file("$backup/" . SRC_LANG . '.json')) {
        copy("$backup/" . SRC_LANG . '.json', $en);
    }
    exec('rm -rf ' . escapeshellarg(SCRATCH_TOP));
    foreach ([I18N_DIR . '/glossary/' . TMP_LANG . '.json',
              I18N_DIR . '/readme/' . TMP_LANG . '.md'] as $p) {
        if (is_file($p)) {
            unlink($p);
        }
    }
    if (is_dir("$backup/docs")) {
        exec('rm -rf ' . escapeshellarg(I18N_REPO . '/docs/i18n/' . SRC_LANG));
        exec('cp -r ' . escapeshellarg("$backup/docs") . ' ' . escapeshellarg(I18N_REPO . '/docs/i18n/' . SRC_LANG));
    }
    exec('rm -rf ' . escapeshellarg($backup));
};
register_shutdown_function($restore);

mkdir($backup, 0755, true);
copy(I18N_DIR . '/glossary/' . SRC_LANG . '.json', "$backup/" . SRC_LANG . '.json');
if (is_dir(I18N_REPO . '/docs/i18n/' . SRC_LANG)) {
    exec('cp -r ' . escapeshellarg(I18N_REPO . '/docs/i18n/' . SRC_LANG) . ' ' . escapeshellarg("$backup/docs"));
}

// Build the scratch root as a faithful mirror: the language switcher in every
// generated README links to each sibling locale, and check.php resolves those
// links against the filesystem, so the siblings have to be reachable there.
mkdir(SCRATCH_OUT, 0755, true);
foreach ((array) glob(I18N_REPO . '/docs/i18n/*', GLOB_ONLYDIR) as $dir) {
    $code = basename($dir);
    if ($code !== TMP_LANG) {
        symlink($dir, SCRATCH_OUT . '/' . $code);
    }
}

$en = json_decode((string) file_get_contents(I18N_DIR . '/glossary/' . SRC_LANG . '.json'), true, 512, JSON_THROW_ON_ERROR);

/**
 * Write a zz glossary derived from en, optionally with a notice, a direction,
 * and per-key text overrides.
 *
 * @param array<string,string> $overrides
 */
$makeGlossary = static function (?string $notice, string $dir = 'ltr', array $overrides = []) use ($en): void {
    $g = $en;
    foreach ($overrides as $k => $v) {
        $g[$k] = $v;
    }
    $g['_meta'] = ['lang' => TMP_LANG, 'name' => 'Self-test', 'dir' => $dir, 'notice' => $notice,
        'note' => 'throwaway locale, written and deleted by selftest.php'];
    file_put_contents(I18N_DIR . '/glossary/' . TMP_LANG . '.json',
        json_encode($g, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
};

const NOTICE = '> **Machine translation.** This document was translated automatically and has not been reviewed by a native speaker.';

// ---------------------------------------------------------------------------

echo "== the baseline really does pass ==\n";
[, $out] = run_check('--lang=' . SRC_LANG . ' --quiet');
ok('check.php --lang=en exits 0', str_contains($out, 'RESULT: PASS'), $out);

echo "\n== the overflow check reports overflow ==\n";
$g = $en;
$g['architecture.entry.10'] = 'a label far too long for a badge box even after wrapping and shrinking';
file_put_contents(I18N_DIR . '/glossary/' . SRC_LANG . '.json',
    json_encode($g, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

[$rc, $out] = run('--lang=' . SRC_LANG);
ok('generate.php refuses to write an unfittable diagram', $rc !== 0 && str_contains($out, 'SKIPPED'), "exit=$rc");
ok('generate.php names the run it could not fit', str_contains($out, 'OVERFLOW architecture.entry.10'), $out);

// The generator refuses, so force the bad text onto disk: this is the state a
// translator would be in if they had edited the SVG by hand.
$path = I18N_REPO . '/docs/i18n/' . SRC_LANG . '/images/architecture.svg';
$d = i18n_load_svg($path);
$t = i18n_text_nodes($d)[9];
while ($t->firstChild) {
    $t->removeChild($t->firstChild);
}
$t->appendChild($d->createTextNode($g['architecture.entry.10']));
i18n_write_svg($d, $path);

[$rc, $out] = run_check('--lang=' . SRC_LANG);
ok('check.php exits non-zero on an overflowing run', $rc !== 0, "exit=$rc");
ok('check.php reports the overflow with a negative slack', str_contains($out, 'overflow -'), $out);
ok('the summary line does not claim ok for that diagram', !str_contains($out, 'ok    architecture: 82 text runs'), $out);

copy("$backup/" . SRC_LANG . '.json', I18N_DIR . '/glossary/' . SRC_LANG . '.json');
run('--lang=' . SRC_LANG);

echo "\n== the machine-translation notice is enforced ==\n";
$makeGlossary(null);
[$rc, $out] = run('--lang=' . TMP_LANG, true);
ok('generate.php rejects a translated locale with no notice', $rc !== 0 && str_contains($out, 'notice is required'), "exit=$rc");

$g = $en;
$g['_meta'] = ['lang' => SRC_LANG, 'name' => 'English', 'dir' => 'ltr', 'notice' => NOTICE];
file_put_contents(I18N_DIR . '/glossary/' . SRC_LANG . '.json',
    json_encode($g, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
[$rc, $out] = run('--lang=' . SRC_LANG);
ok('generate.php rejects a notice on a source language', $rc !== 0 && str_contains($out, 'source language'), "exit=$rc");
copy("$backup/" . SRC_LANG . '.json', I18N_DIR . '/glossary/' . SRC_LANG . '.json');
run('--lang=' . SRC_LANG);

$makeGlossary(NOTICE);
@mkdir(I18N_DIR . '/readme', 0755, true);
file_put_contents(I18N_DIR . '/readme/' . TMP_LANG . '.md',
    "# Throwaway\n\n![Architecture](docs/images/architecture.svg)\n\n[Lifecycle](docs/images/lifecycle.svg)\n");
[$rc, $out] = run('--lang=' . TMP_LANG, true);
ok('generate.php accepts a translated locale that carries a notice', $rc === 0, $out);
ok('the notice is written into the SVG <desc>',
    str_contains((string) file_get_contents(tmp('images/architecture.svg')),
        'not been reviewed by a native speaker'), $out);

[$rc, $out] = run_check('--lang=' . TMP_LANG, true);
ok('check.php passes a well-formed translated locale', $rc === 0, $out);

// Now break each thing the notice check is supposed to catch.
$path = tmp('images/architecture.svg');
$d = i18n_load_svg($path);
$desc = i18n_meta_node($d, 'desc');
while ($desc->firstChild) {
    $desc->removeChild($desc->firstChild);
}
$desc->appendChild($d->createTextNode('no notice here'));
i18n_write_svg($d, $path);
[$rc, $out] = run_check('--lang=' . TMP_LANG, true);
ok('check.php catches a <desc> without the notice', $rc !== 0 && str_contains($out, 'desc> does not carry'), $out);
run('--lang=' . TMP_LANG, true);

$rm = tmp('README.md');
file_put_contents($rm, preg_replace('/^> \*\*Machine translation.*$/m', '', (string) file_get_contents($rm)));
[$rc, $out] = run_check('--lang=' . TMP_LANG, true);
ok('check.php catches a README without the notice', $rc !== 0 && str_contains($out, 'above the first heading'), $out);
run('--lang=' . TMP_LANG, true);

echo "\n== the link check reports a broken link ==\n";
$rm = tmp('README.md');
file_put_contents($rm, str_replace('(./images/lifecycle.svg)', '(./images/no-such-diagram.svg)',
    (string) file_get_contents($rm)));
[$rc, $out] = run_check('--lang=' . TMP_LANG, true);
ok('check.php catches a relative link that does not resolve',
    $rc !== 0 && str_contains($out, 'does not resolve'), $out);
run('--lang=' . TMP_LANG, true);

echo "\n== the rtl path: swapped anchors and pinned ltr runs ==\n";
// One run is given strong RTL text so the swap branch is exercised too; every
// other run in zz is Latin and must come out pinned the other way.
$RTL_TEXT = '不含引导与插件加载 — العربية';
$makeGlossary(NOTICE, 'rtl', ['lifecycle.axis.56' => $RTL_TEXT]);
run('--lang=' . TMP_LANG, true);
$outSvg = (string) file_get_contents(tmp('images/architecture.svg'));
ok('a rtl locale sets direction on the root', str_contains($outSvg, 'direction="rtl"'));

// The invariant is about the *effective* anchor.  Text-anchor defaults to
// "start", so counting written attributes would have passed while 160 runs
// silently kept the wrong side; compare effective to effective instead.
//
// Both docs are walked on purpose: architecture is all-Latin, so it exercises
// the pinned-ltr branch only, and the swapped branch lives in lifecycle, where
// the fixture put the one run with RTL text.  A run count would have passed
// with either branch dead, so the counts are asserted too.
$wrong = 0;
$nPinned = 0;
$nSwapped = 0;
foreach (['architecture', 'lifecycle'] as $doc) {
    $tplNodes = i18n_text_nodes(i18n_load_svg(I18N_DIR . "/templates/$doc.svg"));
    foreach (i18n_text_nodes(i18n_load_svg(tmp("images/$doc.svg"))) as $i => $t) {
        $srcAnchor = $tplNodes[$i]->getAttribute('text-anchor') ?: 'start';
        if ($t->getAttribute('direction') === 'ltr') {
            // Pinned ltr runs are not compensated: they must stay on the source's side.
            $nPinned++;
            if (($t->getAttribute('text-anchor') ?: 'start') !== $srcAnchor) {
                $wrong++;
            }
            continue;
        }
        $nSwapped++;
        $want = match ($srcAnchor) {
            'start' => 'end',
            'end'   => 'start',
            default => 'middle',
        };
        if (($t->getAttribute('text-anchor') ?: 'start') !== $want) {
            $wrong++;
        }
    }
}
ok("every run is anchored as rtl requires ($nPinned pinned ltr, $nSwapped swapped)",
    $wrong === 0, "$wrong wrong");
ok('...and the fixture exercised both branches', $nPinned > 0 && $nSwapped > 0,
    "$nPinned pinned, $nSwapped swapped");

// ...and the checker is the gate for it, so it must fail when a run is put on
// the wrong side, not merely when the attributes look odd.
$d = i18n_load_svg(tmp('images/architecture.svg'));
$t = i18n_text_nodes($d)[0];
$t->setAttribute('text-anchor', 'end');     // node 0 is pinned ltr; end is the rtl side
i18n_write_svg($d, tmp('images/architecture.svg'));
[$rc, $out] = run_check('--lang=' . TMP_LANG, true);
ok('check.php catches a run anchored on the side rtl resolves to',
    $rc !== 0 && str_contains($out, 'not anchored on the side rtl requires'), $out);
run('--lang=' . TMP_LANG, true);

// -- bidi: a Latin run left under the document's rtl direction ---------------
// The anchor check cannot see this one.  Characters stay in the right order in
// the file and the geometry is right; it is the renderer that reorders the
// edge neutrals, so `serve()` comes out `()serve`.  Measured in ar: 20 runs.
$d = i18n_load_svg(tmp('images/lifecycle.svg'));
$unpinned = 0;
foreach (i18n_text_nodes($d) as $t) {
    if (!i18n_has_rtl(i18n_flat_text($t)) && $t->getAttribute('direction') === 'ltr') {
        $t->removeAttribute('direction');
        $unpinned++;
    }
}
ok("the fixture really had pinned ltr runs to unpin", $unpinned > 0, "$unpinned unpinned");
i18n_write_svg($d, tmp('images/lifecycle.svg'));
[$rc, $out] = run_check('--lang=' . TMP_LANG, true);
ok('check.php catches Latin runs left for bidi to reorder',
    $rc !== 0 && str_contains($out, 'are not pinned direction="ltr"'), $out);

run('--lang=' . TMP_LANG, true);
[$rc, $out] = run_check('--lang=' . TMP_LANG, true);
ok('...and they pass again once the generator re-pins them', $rc === 0, $out);

echo "\n== the overlap check reports a collision ==\n";
// The source collides nowhere, so an intersection is always something a
// translation introduced — which is why it fails rather than warns.  Neither
// a collision nor a sub-pixel near-miss can be produced by the generator, so
// both are synthesised by sliding one run along its own baseline.  #55
// "shutdown" is end-anchored and shares a row with #54 "plugins_loaded", both
// inside the same lane band, so sliding it left lands the ink exactly where
// the test wants it without leaving either run's container — the fixture then
// has exactly one finding, and the verdict under test is the only thing in it.
$makeGlossary(NOTICE);
run('--lang=' . TMP_LANG, true);
$path = tmp('images/lifecycle.svg');

$nudge = static function (float $overlap) use ($path): void {
    $d = i18n_load_svg($path);
    $nodes = i18n_text_nodes($d);
    $a = $nodes[53];                       // #54, start-anchored
    $b = $nodes[54];                       // #55, end-anchored
    $la = (float) $a->getAttribute('x');
    $b->setAttribute('x', (string) ($la + i18n_node_width($a) + i18n_node_width($b) - $overlap));
    i18n_write_svg($d, $path);
};

$nudge(0.5);
[$rc, $out] = run_check('--lang=' . TMP_LANG, true);
ok('a sub-pixel intersection is reported as a warning, not a failure',
    str_contains($out, 'warn  lifecycle#54 & lifecycle#55: ink overlaps 0.50px'), $out);
ok('...and it does not fail the gate', $rc === 0, "exit=$rc\n$out");

// --quiet must not hide it: a run that prints a warning count while showing
// nothing but its verdict reads as all-clear to whoever scrolls past.
[$rc, $out] = run_check('--lang=' . TMP_LANG . ' --quiet', true);
ok('--quiet still prints the warning', str_contains($out, 'warn  lifecycle#54'), $out);
ok('--quiet does hide the passing lines', !str_contains($out, 'ok    '), $out);

$nudge(2.5);
[$rc, $out] = run_check('--lang=' . TMP_LANG, true);
ok('an intersection of a pixel or more is a failure',
    $rc !== 0 && str_contains($out, 'FAIL  lifecycle#54 & lifecycle#55: ink overlaps 2.5px'), $out);

run('--lang=' . TMP_LANG, true);
[$rc, $out] = run_check('--lang=' . TMP_LANG, true);
ok('...and passes again once the runs are back where the generator put them', $rc === 0, $out);

// -- overlap: a collision the width model invented --------------------------
// Both runs above are Latin, which the model measures exactly, so the FAIL is
// earned.  For a script it has no measured table for, the run may be charged
// ink it does not have and "collide" with a neighbour it never touches —
// measured on this tree: Cyrillic over-charged by 25-44%.  Same collision,
// one run in such a script: the finding must still be reported, and must not
// fail the gate.
$makeGlossary(NOTICE, 'ltr', ['lifecycle.axis.56' => 'исключает загрузку плагинов']);
run('--lang=' . TMP_LANG, true);
$pathAr = tmp('images/lifecycle.svg');

// #56 is end-anchored on the lane-title row; #52 "WordPress" is start-anchored
// there and is a copy key, so its width is fixed.  Slide #56 back along its own
// baseline until the two inks intersect.
$d = i18n_load_svg($pathAr);
$nodes = i18n_text_nodes($d);
$a = $nodes[51];                           // #52 "WordPress", start-anchored
$b = $nodes[55];                           // #56, end-anchored
$b->setAttribute('x', (string) ((float) $a->getAttribute('x')
    + i18n_node_width($a) + i18n_node_width($b) - 12.0));
i18n_write_svg($d, $pathAr);

[$rc, $out] = run_check('--lang=' . TMP_LANG, true);
ok('a collision in an approximate script is reported',
    str_contains($out, 'lifecycle#52 & lifecycle#56: model says ink overlaps'), $out);
ok('...and it names the uncertainty and the way to settle it',
    str_contains($out, 'per-script estimate, not a measurement')
    && str_contains($out, 'calibrate.php'), $out);
ok('...and it does not fail the gate', $rc === 0, "exit=$rc\n$out");
run('--lang=' . TMP_LANG, true);

// ---------------------------------------------------------------------------
echo "\n== a pair that does not touch, but is one reword away ==\n";

// The FAIL above answers "is this tree broken?".  Whoever is about to reword a
// sentence needs the other question — "is this tree one longer sentence from
// broken?" — and a pair 1.4px clear fails tomorrow on a change nobody had
// reason to think was risky.  Same fixture as the sub-pixel case, #54/#55 on
// one baseline, but pulled *apart* instead of overlapped: a near miss cannot be
// produced by the generator either.
$makeGlossary(NOTICE);
run('--lang=' . TMP_LANG, true);

$nudge(-8.0);
[$rc, $out] = run_check('--lang=' . TMP_LANG, true);
ok('a pair 8px apart is reported as a near miss',
    str_contains($out, 'near-miss threshold')
    && str_contains($out, 'lifecycle#54 & lifecycle#55'), $out);
ok('...and the warning carries the number, not just a verdict',
    str_contains($out, 'clearance 8.0px, under the 20px near-miss threshold'), $out);
ok('...and it does not fail the gate', $rc === 0, "exit=$rc\n$out");

[$rc, $out] = run_check('--lang=' . TMP_LANG . ' --quiet', true);
ok('...and --quiet still prints it', str_contains($out, 'near-miss threshold'), $out);

// The other side of the threshold.  Without this case the three above would
// pass just as well on a check that warns about every pair in the diagram.
//
// Asserted on #54/#55 by name, not on the absence of any warning: this fixture
// has four structural near-misses of its own (the drawn gap between adjacent
// boxes, 16-18px), so "no near-miss warnings at all" is false whatever this
// case does, and asserting it would have been a test of nothing.
$nudge(-40.0);
[$rc, $out] = run_check('--lang=' . TMP_LANG, true);
ok('a pair 40px apart is not reported',
    !str_contains($out, 'lifecycle#54 & lifecycle#55'), $out);

// The height dimension has no threshold of its own, on purpose: a band does not
// grow when a sentence is reworded, only when it wraps, so a threshold on it
// would fire on the row pitch (125 warnings per locale at 20px, 1070 of 1125
// measured vertical).  What it can say is printed instead, on the line that
// already reports the diagram, so it is visible on every run.
run('--lang=' . TMP_LANG, true);
[$rc, $out] = run_check('--lang=' . TMP_LANG, true);
ok('the tightest vertical clearance is measured and printed',
    preg_match('/no two overlap; tightest vertical clearance -?\d+\.\dpx'
        . ' \(lifecycle#\d+ & lifecycle#\d+, model band\)/', $out) === 1, $out);

// ---------------------------------------------------------------------------
echo "\n== a refused locale must not present as a green gate ==\n";

// generate.php refuses by writing nothing, so the diagrams from the last run
// that succeeded stay on disk.  This case reproduces that state — refuse the
// generator, leave the disk alone — and expects the gate to say so.
//
// Measured while writing this case, and worth recording because it is less
// alarming than it sounds: the state was *not* silent before.  The glossary-vs-
// file comparison further down notices that the stale run's text no longer
// matches the glossary, and reddens — verified by disabling the probe and
// re-running this fixture.  What it says is "file text does not match glossary
// ("supported" vs "很长的标签…")", which reads as someone hand-editing the SVG;
// the actual cause is that the translation no longer fits and the generator
// refused to write it.  So the probe's contribution is the diagnosis, not the
// redness, and the assertions below are on the diagnosis for that reason — the
// exit status alone would pass without it.
//
// The residue it does cover on its own: a refusal whose stale file still
// matches the glossary, so there is no mismatch to notice.
$makeGlossary(NOTICE);
run('--lang=' . TMP_LANG, true);
$stale = file_get_contents(tmp('images/architecture.svg'));

$makeGlossary(NOTICE, 'ltr', ['architecture.entry.10' => str_repeat('很长的标签', 40)]);
[$rc, $out] = run('--lang=' . TMP_LANG, true);
ok('the fixture really does make the generator refuse', $rc !== 0, "exit=$rc\n$out");

[$rc, $out] = run_check('--lang=' . TMP_LANG, true);
ok('check.php fails when the generator would refuse',
    $rc !== 0 && str_contains($out, 'the generator refuses this locale'), "exit=$rc\n$out");
ok('...quoting the generator rather than re-deriving the verdict',
    str_contains($out, 'OVERFLOW architecture.entry.10'), $out);
ok('...and the diagram it refuses to bless is the stale one, untouched',
    file_get_contents(tmp('images/architecture.svg')) === $stale);

run('--lang=' . TMP_LANG, true);

// ---------------------------------------------------------------------------
echo "\n== a dry run writes nothing ==\n";

// check.php runs `generate.php --dry-run` on every invocation, so a dry run
// that writes is a gate that mutates the tree it is inspecting — and the
// scratch root mirrors docs/i18n with symlinks, so a write could land in a real
// locale.  It used to: the SVG loop was guarded by $dry and the README write
// was not, so a "dry" run still rewrote README.md with identical bytes and only
// the mtime showed it.  Comparing content would not have caught that, which is
// why the mtime is in the fingerprint.
$fingerprint = static function (string $dir): string {
    $h = [];
    if (!is_dir($dir)) {
        return 'absent';
    }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    ) as $f) {
        $h[$f->getPathname()] = [$f->getMTime(), sha1_file($f->getPathname())];
    }
    ksort($h);
    return sha1(serialize($h));
};
$watched = [I18N_REPO . '/docs/i18n', SCRATCH_OUT . '/' . TMP_LANG];
$before = array_map($fingerprint, $watched);

run('--lang=' . TMP_LANG . ' --dry-run', true);
run('--lang=' . SRC_LANG . ' --dry-run');

ok('a dry run changes no file under docs/i18n, mtimes included',
    array_map($fingerprint, $watched) === $before);

// ---------------------------------------------------------------------------
echo "\n== an output root outside the repository is refused ==\n";

// The locale READMEs link back to the repo root with a computed number of
// `../`, so a root outside the repo cannot be described in relative terms at
// all.  An earlier version of i18n_out_depth() answered "depth 1" for such a
// root instead of refusing — it subtracted the repo prefix from a path that did
// not have it, got an empty string, and returned a number.  generate.php then
// wrote `../README.md` where `../../../README.md` belongs, into every locale,
// over a green exit code.  A wrong answer that looks like an answer is the
// failure mode this case exists to prevent, so it asserts both halves: the
// refusal, and that the refusal comes before anything reaches the disk.
$outside = sys_get_temp_dir() . '/i18n-selftest-outside-' . getmypid();
exec('rm -rf ' . escapeshellarg($outside));
mkdir($outside, 0755, true);
$makeGlossary('机器翻译，未经母语者复核', 'ltr');

[$rc, $out] = run_root($outside, '--lang=' . TMP_LANG);
ok('a root outside the repository is refused',
    $rc === 2 && str_contains($out, 'must be inside the repository'), "exit=$rc\n$out");
ok('...and nothing is written before it refuses',
    glob($outside . '/*') === [], 'wrote: ' . implode(', ', (array) glob($outside . '/*')));
exec('rm -rf ' . escapeshellarg($outside));

// ---------------------------------------------------------------------------
printf("\n%s  %d passed, %d failed\n", $fail ? 'SELFTEST: FAIL' : 'SELFTEST: PASS', $pass, $fail);
exit($fail ? 1 : 0);
