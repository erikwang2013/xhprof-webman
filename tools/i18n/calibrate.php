<?php
declare(strict_types=1);

/**
 * tools/i18n/calibrate.php — measure the width model against a real renderer.
 *
 *   php tools/i18n/calibrate.php --lang=en
 *   php tools/i18n/calibrate.php --lang=en --font='DejaVu Sans'
 *   php tools/i18n/calibrate.php --rtl
 *
 * check.php trusts FontMetrics to predict how wide a run of text will be.
 * A prediction is only as good as the table behind it, and a table is only as
 * good as the font it was measured on.  This script re-measures, with headless
 * Chrome, the diagrams exactly as generate.php wrote them.
 *
 * The font question is not academic.  The source diagrams declare
 *
 *   -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue",
 *   Arial, "Noto Sans SC", "PingFang SC", "Microsoft YaHei", sans-serif
 *
 * so a Mac renders them in SF Pro and Windows in Segoe UI; on this build box
 * none of the first six families exist and fontconfig hands back 华康圆体W5
 * for the whole list.  The tables in lib.php were measured on that face, so
 * they are exact only for a reader whose font resolves the same way.  Pass
 * --font=<family> to re-measure against a specific face: the model is safe for
 * a face when its predicted width is >= the measured one, because then the
 * error is slack the layout does not need rather than overflow it cannot see.
 *
 * --rtl is the experiment behind the text-anchor swap in generate.php: it
 * renders the same string under direction:ltr and direction:rtl and prints
 * where each anchor actually puts the run.
 *
 * Chrome is required; everything else about this pipeline is PHP-only.
 */

require __DIR__ . '/lib.php';

const CHROME = 'google-chrome';

/** Render $html in headless Chrome and return the DOM dump. */
function chrome_dom(string $html): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'i18ncal');
    $file = $tmp . '.html';
    rename($tmp, $file);
    file_put_contents($file, $html);
    $cmd = CHROME . ' --headless=new --disable-gpu --no-sandbox --hide-scrollbars'
        . ' --virtual-time-budget=10000 --dump-dom ' . escapeshellarg('file://' . $file) . ' 2>/dev/null';
    $dom = (string) shell_exec($cmd);
    @unlink($file);
    return $dom;
}

/** Pull the JSON payload out of <pre id="out"> in a dumped DOM. */
function chrome_json(string $dom): array
{
    if (!preg_match('%<pre id="out">(.*?)</pre>%s', $dom, $m)) {
        fwrite(STDERR, "calibrate: Chrome produced no output — is " . CHROME . " installed?\n");
        exit(2);
    }
    return json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Wrap an SVG and a measurement script into a page Chrome can dump.
 *
 * @param string $svg     full SVG document
 * @param string $script  JS that fills #out with JSON
 * @param string $css     extra CSS, e.g. a font-family override
 */
function page(string $svg, string $script, string $css = ''): string
{
    $svg = preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg);
    return "<!doctype html><meta charset=\"utf-8\">\n<style>$css</style>\n$svg\n<pre id=\"out\"></pre>\n<script>$script</script>\n";
}

/** Per-<text> advance width and ink extent, in SVG user units. */
const MEASURE_JS = <<<'JS'
const out = [];
document.querySelectorAll('svg text').forEach((t) => {
  const spans = Array.from(t.querySelectorAll('tspan'));
  const nodes = spans.length ? spans : [t];
  let adv = 0, l = Infinity, r = -Infinity;
  for (const n of nodes) {
    adv = Math.max(adv, n.getComputedTextLength());
    const b = n.getBBox();
    l = Math.min(l, b.x);
    r = Math.max(r, b.x + b.width);
  }
  out.push({adv: adv, inkL: l, inkR: r});
});
document.getElementById('out').textContent = JSON.stringify(out);
JS;

// ---------------------------------------------------------------------------
// --rtl: where does text-anchor actually put a run?
// ---------------------------------------------------------------------------

if (in_array('--rtl', $argv, true)) {
    $probe = static fn(string $dir): string => sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 160" width="400" height="160"'
        . ' font-family="Arial" font-size="20" fill="#000"%s>'
        . '<text id="s" x="200" y="40" text-anchor="start">Sample</text>'
        . '<text id="m" x="200" y="80" text-anchor="middle">Sample</text>'
        . '<text id="e" x="200" y="120" text-anchor="end">Sample</text></svg>',
        $dir === '' ? '' : ' direction="' . $dir . '"'
    );
    $js = 'const o=[];["s","m","e"].forEach((id)=>{const b=document.getElementById(id).getBBox();'
        . 'o.push({id:id,l:+b.x.toFixed(2),r:+(b.x+b.width).toFixed(2),w:+b.width.toFixed(2)});});'
        . 'document.getElementById("out").textContent=JSON.stringify(o);';

    echo "== does text-anchor follow the inline base direction? ==\n";
    echo "   probe: one run at x=200, three anchors, measured with Chrome getBBox()\n\n";
    printf("   %-8s %-22s %-22s\n", 'anchor', 'direction=ltr (ink L..R)', 'direction=rtl (ink L..R)');
    $rows = [];
    foreach (['' => 'ltr', 'rtl' => 'rtl'] as $attr => $label) {
        $rows[$label] = [];
        foreach (chrome_json(chrome_dom(page($probe($attr), $js))) as $r) {
            $rows[$label][$r['id']] = $r;
        }
    }
    foreach (['s' => 'start', 'm' => 'middle', 'e' => 'end'] as $id => $anchor) {
        $a = $rows['ltr'][$id];
        $b = $rows['rtl'][$id];
        printf("   %-8s %-22s %-22s\n", $anchor,
            sprintf('%.1f..%.1f', $a['l'], $a['r']),
            sprintf('%.1f..%.1f', $b['l'], $b['r']));
    }
    printf("\n   width of the run: %.2fpx, x = 200\n", $rows['ltr']['s']['w']);
    $ltrStartRight = $rows['ltr']['s']['r'] > 200;
    $rtlStartRight = $rows['rtl']['s']['r'] > 200;
    printf("   start, ltr: run lies to the %s of x\n", $ltrStartRight ? 'right' : 'left');
    printf("   start, rtl: run lies to the %s of x   <- %s\n",
        $rtlStartRight ? 'right' : 'left',
        $ltrStartRight === $rtlStartRight ? 'SAME' : 'SWAPPED');
    printf("   middle is %s between the two directions.\n",
        abs($rows['ltr']['m']['l'] - $rows['rtl']['m']['l']) < 0.01 ? 'unchanged' : 'CHANGED');
    printf("\n   conclusion the generator encodes: under direction=\"rtl\", swap\n"
        . "   text-anchor start<->end; middle needs no compensation.\n");
    exit(0);
}

// ---------------------------------------------------------------------------
// Width calibration against the generated diagrams
// ---------------------------------------------------------------------------

$lang = 'en';
$font = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--lang=')) {
        $lang = substr($a, 7);
    }
    if (str_starts_with($a, '--font=')) {
        $font = substr($a, 7);
    }
}

$manifest = i18n_load_manifest();
$css = $font === null ? '' : sprintf("svg text, svg tspan { font-family: %s !important; }\n", $font);

printf("== width model vs Chrome getComputedTextLength() ==\n");
printf("   locale %s, font: %s\n\n", $lang, $font === null ? "the stack as authored" : "$font (forced)");

$totals = ['n' => 0, 'worst' => 0.0, 'worstKey' => '-', 'over' => 0, 'under' => 0, 'underWorst' => 0.0, 'underKey' => '-'];
$anyOverflow = false;

foreach (I18N_DOCS as $doc) {
    $path = i18n_out_dir($lang) . "/images/$doc.svg";
    if (!is_file($path)) {
        printf("   %-14s missing — run generate.php --lang=%s first\n", $doc, $lang);
        continue;
    }
    $file = (string) file_get_contents($path);
    $d = i18n_load_svg($path);
    $measured = chrome_json(chrome_dom(page($file, MEASURE_JS, $css)));

    $nodes = i18n_text_nodes($d);
    if (count($measured) !== count($nodes)) {
        printf("   %-14s node count %d measured vs %d in file — skipping\n", $doc, count($measured), count($nodes));
        continue;
    }

    $keysByIndex = [];
    foreach (i18n_doc_keys($manifest, $doc) as $key => $node) {
        if ($node['index'] !== null) {
            $keysByIndex[$node['index']] = $key;
        }
    }

    $maxAbs = 0.0;
    $maxKey = '-';
    $over = 0;
    $under = 0;
    $underWorst = 0.0;
    $underKey = '-';
    $minSlack = INF;
    $minKey = '-';
    $i = 0;
    foreach ($nodes as $t) {
        $i++;
        $key = $keysByIndex[$i] ?? "text#$i";
        // i18n_node_width, not width(flat_text): a wrapped run is several lines
        // and only the longest of them has to fit.  Flattening would join them
        // back into one impossible line and report a phantom over-estimate.
        $pred = i18n_node_width($t);
        $meas = $measured[$i - 1]['adv'];
        $err = $pred - $meas;
        if (abs($err) > $maxAbs) {
            $maxAbs = abs($err);
            $maxKey = $key;
        }
        if ($err < 0) {
            $under++;
            if ($err < $underWorst) {
                $underWorst = $err;
                $underKey = $key;
            }
        } else {
            $over++;
        }

        // Real overflow, measured: advance extent against the run's own box.
        // The anchor must be the *effective* one — generate.php has already
        // swapped text-anchor for rtl, and reading the raw attribute here would
        // mirror the extent to the wrong side of x and report an overflow of up
        // to a full run width on every rtl diagram.  Same reason i18n_container()
        // below resolves the anchor the same way.
        $x = (float) ($t->getAttribute('x') ?: 0);
        $anchor = i18n_effective_anchor($t);
        [$l, $r] = match ($anchor) {
            'middle' => [$x - $meas / 2, $x + $meas / 2],
            'end'    => [$x - $meas, $x],
            default  => [$x, $x + $meas],
        };
        $b = i18n_container($d, $t)['bounds'];
        $s = min($l - $b['x'], $b['x'] + $b['w'] - $r);
        if ($s < $minSlack) {
            $minSlack = $s;
            $minKey = $key;
        }
    }

    $totals['n'] += $i;
    $totals['over'] += $over;
    $totals['under'] += $under;
    if ($maxAbs > $totals['worst']) {
        $totals['worst'] = $maxAbs;
        $totals['worstKey'] = "$doc $maxKey";
    }
    if ($underWorst < $totals['underWorst']) {
        $totals['underWorst'] = $underWorst;
        $totals['underKey'] = "$doc $underKey";
    }
    $anyOverflow = $anyOverflow || $minSlack < 0;

    printf("   %-14s %3d runs  |  max |error| %.2fpx (%s)  |  %d over / %d under  |  min slack %.1fpx (%s)%s\n",
        $doc, $i, $maxAbs, $maxKey, $over, $under, $minSlack, $minKey,
        $minSlack < 0 ? '   <-- OVERFLOW AS RENDERED' : '');
}

printf("\n   %d runs: %d predicted wider than rendered, %d narrower.\n",
    $totals['n'], $totals['over'], $totals['under']);
printf("   largest absolute error  %.2fpx  (%s)\n", $totals['worst'], $totals['worstKey']);
if ($totals['under'] > 0) {
    printf("   worst under-estimate    %.2fpx  (%s)  <- error pointing at overflow\n",
        $totals['underWorst'], $totals['underKey']);
}
printf("\n   %s\n\n", $anyOverflow
    ? 'RESULT: FAIL — a run overflows its box as Chrome measures it'
    : 'RESULT: PASS — no run overflows its box as Chrome measures it');
exit($anyOverflow ? 1 : 0);
