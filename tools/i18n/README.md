# Translating this project's README and diagrams

Everything here exists to make one thing mechanical: **does the translated
output still fit, and does it still say the same code**? It cannot tell you
whether the translation reads well. No script in this directory can, and none
of them claims to.

## What is here

| file | what it does |
|---|---|
| `extract.php` | derives `templates/*.svg` and `classify.json` from `docs/images/*.svg`. Already run; only re-run it if the Chinese originals change. |
| `classify.json` | the 203 text nodes, each marked `copy`, `code` or `text`, with the tokens that must survive translation. Reviewable by hand — this is the file to argue with. |
| `templates/*.svg` | the originals with each translatable string replaced by `{{key}}`. |
| `glossary/<lang>.json` | one file per language. **This is what you write.** |
| `generate.php` | glossary → `docs/i18n/<lang>/images/*.svg` + `docs/i18n/<lang>/README.md`. |
| `check.php` | the gate. Structure, wording, links, overflow, collisions, text direction, and whether the generator would even accept the locale. |
| `calibrate.php` | re-measures the width model against real Chrome. Oracle, not a gate. |
| `selftest.php` | proves that `check.php` and `generate.php` actually fail when they should. Writes its scratch copies under the gitignored `.selftest/` at the repo root — never inside `docs/i18n/`, which is a delivery directory. |

## The loop

```sh
# 1. copy the English glossary and translate its values
cp tools/i18n/glossary/en.json tools/i18n/glossary/de.json

# 2. put your translated README body here (see "README" below)
$EDITOR tools/i18n/readme/de.md

# 3. render
php tools/i18n/generate.php --lang=de

# 4. check — exit 0 is the delivery criterion
php tools/i18n/check.php --lang=de
```

`generate.php` refuses to write a diagram it cannot fit, and exits 1. `check.php`
exits 1 on any hard failure. Both are safe to re-run; neither touches
`docs/images/`, `README.md` or `README.EN.md`.

## Glossary format

Flat JSON. `_meta` plus one entry per key:

```json
{
  "_meta": {
    "lang": "de",
    "name": "Deutsch",
    "dir": "ltr",
    "notice": "> **Maschinelle Übersetzung.** ...",
    "note": "who translated this, from what, and when"
  },
  "architecture.title.1": "…",
  "architecture.entry.5": "…"
}
```

* `notice` is **required** for every locale except `en`, and **forbidden** for
  `en`. The check is not on the wording — write your own sentence — only that
  the reader is told this is an unreviewed machine translation. It is written
  both above the first heading of the README and into every SVG's `<desc>`, so
  it survives being viewed as a bare image.
  Write it in Markdown like the rest of the README, including links if you
  want one. The `<desc>` gets the same sentence with the Markdown stripped:
  a `<desc>` is plain text, so `[label](url)` there renders as literal,
  unclickable syntax, and a link target written for the README's directory
  resolves one level short from `docs/i18n/<lang>/images/`. A link's *label*
  survives — that is the part that carries meaning.
* `dir` is `ltr` or `rtl`. Setting `rtl` changes how the generator writes
  `text-anchor` — see "Right-to-left" below.
* `en` is the source language of this repository (`README.EN.md`), not a
  translation, and must not carry a notice.
* Keys are `<doc>.<section>.<n>`, plus `<doc>.meta.title` / `<doc>.meta.desc`
  for the SVG title and description. `generate.php` rejects a glossary with a
  missing or unknown key before it writes anything, so run it early.

## The three classes, and what each demands of you

203 keys: **76 copy**, **57 code**, **70 text**.

| class | count | what it means |
|---|---|---|
| `copy` | 76 | Do not touch it. `check.php` compares these byte for byte against the source. They are already English, or they are diagram furniture like `new`, `supported`, `①`. |
| `code` | 57 | Translate the prose, but every token in the node's `keep` list must survive **verbatim**. `Xhprof::autoDetect()` does not become `Xhprof::automatischErkennen()`. |
| `text` | 70 | Translate freely. |

53 distinct protected tokens across the set — framework and product names
(`Webman`, `Slim`, `WordPress`, `HttpFoundation`), identifiers
(`list_runs()`, `bootstrap($req, $res, $cfg, $cache, $log)`), paths
(`src/Core/Contract/`), and one error string (`Call to undefined method`).

To see the classification rather than trust it:

```sh
php tools/i18n/extract.php --list
```

Editing `classify.json` by hand is supported — it is a curated file, and
`extract.php` will not overwrite it without `--force`. If you decide a node is
miscategorised, fix it there and say so in your report.

## Zero overflow is the delivery criterion

```sh
php tools/i18n/check.php --lang=de
```

The overflow check estimates the rendered width of every `<text>` run and
compares it against the smallest `<rect>` containing the run's anchor point (or
the canvas). `slack < 0` is a FAIL, printed with the raw numbers. **Your locale
is not delivered until that is zero for all three diagrams.**

The container is chosen by *anchor point*, not by "the smallest box containing
the whole run" — an overflowing run is by definition not contained by its own
box, so containment could never find it.

`generate.php` refits automatically, in this order:

1. shrink the font, **never below 75%** of the source size;
2. widen the containing box, at most 25%, and only when that does not collide
   with a neighbour;
3. wrap onto extra lines using `<tspan>`.

Only layout is touched. Nodes are not deleted, hierarchy is unchanged, colours
are unchanged — `check.php` compares the element sequence, depth and colours
against the original and fails on any difference.

At the 75% floor it stops and reports `OVERFLOW`. That is deliberate: a run
that will not fit at a readable size is a problem for a human, not something to
solve by shrinking further.

`generate.php` aims for a few px more clearance than `check.php` requires,
because the width model is an estimate and the checker should stay the honest
gate rather than be tuned to match the generator.

## Runs that collide with each other

`check.php` compares every pair of `<text>` runs against each other — a
translation can be inside its own box and still print over its neighbour.

Two runs are in collision when their estimated ink overlaps on **both** axes:
horizontally, and vertically. A run's vertical band reaches `0.8em` above its
baseline and `0.25em` below, extended by `1.25em` for each extra wrapped line;
runs on different rows therefore pass each other freely, however wide they are.

**A collision fails on the scripts the width model measures exactly, and warns
everywhere else.** The basis is measured, not chosen:

* The untranslated originals in `docs/images/` collide nowhere — **0 pairs
  across 197 runs**. Read that for what it is: the source deliberately puts
  *many* runs on a shared baseline (**284 pairs** do it, e.g. the four framework
  columns of `architecture` at `y=184`), so a shared baseline is not itself a
  defect and the originals are not a one-run-per-baseline design. All 284 are
  negative results — the measurement says the rule finds no collision among
  them, never that it would recognise one.
* With the layout corrected (below), the count is **0** in every locale the
  generator wrote. The smallest collision anywhere in the tree is 204.6px *as
  the model scores it*, and nothing lands between 0 and 204px, so the exact
  placement of the threshold is not doing any work today: it could sit anywhere
  in that empty band and give the same verdict.

The check earns its keep on the same defect class the fix below addresses — two
runs whose clearance depends on a *neighbour's* width, which no per-run fit can
see. The subtitles on `architecture#40/41` and `lifecycle#37/38` share one
baseline with a title on their left; the Chinese source leaves 466px and 528px
there, but the room a translation gets is `1156 - 44 - w(title) - w(subtitle)`,
so it shrinks twice as fast as either string grows. All eight cells below were
measured with Chrome against the generated files; each shows **the model's
number → the renderer's**, and the bold one is the row's tightest real
clearance:

| locale | `architecture#40+41` (model → Chrome) | `lifecycle#37+38` (model → Chrome) |
|---|---|---|
| `fr` | −134.6 → −138.6 | −1.4 → **−18.5** |
| `de` | **−18.7 → −22.4** | −158.2 → −160.5 |
| `ar` | −147.5 → −314.7 | −8.7 → **−295.4** |
| `ru` | +204.6 → −107.3 | +348.7 → **−55.3** |

A negative number is clearance. The `ru` row is why this rule now warns instead
of failing: the model scores its two subtitles at **+204.6px and +348.7px of
overlap**, and the renderer contradicts both with 107.3px and 55.3px of real
space between them. The subtitles are 2.0–2.2× the Chinese (English is 1.48×),
which is a long wording, but Cyrillic is charged 25–35% more than it occupies on
those two runs — and up to 54% on the worst Cyrillic run in the tree — so the
claimed failure is an artefact of the mean. Nothing in `ru` needs rewording.

The tight pairs are Latin, and they are tight under *both* instruments: `fr`'s
lifecycle pair has 18.5px of room and `de`'s architecture pair 22.4px, out of a
1112px span. Latin is also where the model is most nearly exact — it over-charges
`fr` by up to 11.2px per run and `de` by up to 6.3px, and every one of the four
Latin cells above errs in that same conservative direction. That is why those
two numbers can be trusted: a wording 5% wider than the French really does fail.
`ar`'s cells are means and should be read the way `calibrate.php --lang=ar`
reads them, from the right-hand number.

Treat any row where a title and a subtitle share a baseline as having very
little room, and check it with `calibrate.php` before deciding which way the
model is wrong.

Two things soften that, and they are not the same kind of concession.

The first is the last pixel: the model deals in glyph *boxes*, and two boxes may
meet without their *ink* meeting, so below `1px` the check cannot tell a
collision from a touch.

The second is the script. The model is exact for ASCII, CJK and overridden
tokens, and a per-script **mean** for everything else —
`FontMetrics::isApproximate()`, the same predicate the model itself uses. A mean
is an estimate with no guaranteed sign, and the `ru` rows above are one where it
pointed the wrong way by 312px. A collision scored on such a script is therefore
reported with the model's numbers and a pointer at `calibrate.php`, but it does
not fail the gate on its own:

| case | verdict |
|---|---|
| `>= 1px` ink overlap, both runs exactly modelled (ASCII / CJK / override) | **FAIL** |
| `>= 1px` ink overlap, either run on an approximate script | warn — model numbers + `run calibrate.php --lang=<lang>` |
| `> 0` and `< 1px` | warn, printed with its value |
| `<= 0`, or no vertical overlap | clear |

Both softenings are stated in the output rather than applied silently. The FAIL
line names the width model as its judge and sends you to `calibrate.php`, because
the model is a glyph-advance table measured on one font stack — exact for these
scripts on a machine that resolves the stack the way this repo does, and no more
than that.

A warning never fails the gate, and `--quiet` never hides one — a run that
prints a warning count while showing nothing but its verdict reads as
all-clear.

### Near misses

A collision check answers "is this tree broken?". It cannot answer the question
the person about to reword a sentence actually has — "is this tree one longer
sentence from broken?" — and `fr`'s `lifecycle#37 & #38`, which have **1.4px**
of room in the model, pass today and fail tomorrow on an edit nobody had reason
to think was risky. So two things below the threshold are reported too.

**Horizontal clearance under `NEAR_MISS_PX` (20px) warns, with the number.**
Measured across the twelve locales it fires 4–15 times each, and the fired pairs
divide by axis:

* the **diagonal** ones (15–20px) are the gap the layout leaves between adjacent
  boxes. Identical in every locale, because it is drawn rather than translated;
  they are listed so the number is not a surprise when a box is resized.
* the **horizontal** ones are the fragile set, and they move with every
  translation change. `fr`'s `lifecycle#37 & #38` are the tightest in the tree
  at 1.4px, with `ru`'s `lifecycle` column next at 15.1px.

The **vertical** axis is deliberately not part of that threshold. A run's band
does not grow when a sentence is reworded — only when it wraps to another line,
which adds a whole line height and is caught by the overlap rule itself. A
threshold there fires on the row pitch instead: at 20px that is ~125 warnings
per locale, and 1070 of the 1125 measured were vertical. What the vertical axis
can say is finer, so it is printed instead of warned: the line that already
reports each diagram now carries its tightest vertical clearance —

```
ok    architecture: 82 text runs, no two overlap; tightest vertical clearance 4.0px (architecture#56 & #57, model band)
```

— so the headroom is visible on every run, including the eleven locales where
nothing is close, without an alarm per pair.

### The height the band assumes, measured

The band's `0.8em` above the baseline and `0.25em` below are assumptions. They
were checked against real ink: every distinct string in the twelve locales,
rendered alone at 20px in the diagrams' own font stack on an opaque background,
converted with `rsvg-convert`, ink = a pixel row whose channels sum below 600.
The worst case per script, in em:

| script | tallest ink above | deepest ink below | band allows |
|---|---|---|---|
| Latin, Cyrillic, Greek | 0.850 | 0.150 | 0.800 / 0.250 |
| **Arabic** | **1.100** | **0.450** | " |
| Devanagari | 0.900 | 0.300 | " |
| Bengali | 0.850 | 0.250 | " |
| Hangul, kana, han | 0.900 | 0.150 | " |

So the `0.8 + 0.25 = 1.05em` the model allows between two baselines is **0.05em
conservative for Latin and 0.50em optimistic for Arabic**. The Latin number is
the `①` of `① Framework entry layer`, which is the tallest thing in `en`/`fr`/
`de`/`ru`; the Arabic one is an alef (1.100em above) and the tails of `ج`/`م`
(0.450em below). These are lower bounds — pixel rows quantise to 0.05em and a
faint antialiased edge can fall outside the darkness threshold.

**On the reported `1.32×`.** `i18n-bn` measured the worst ink height at 1.32×
the model's estimate (+3.5px) and asked for that coefficient. The comparison it
rests on is not available: Chrome's `getBBox()` height for a `<text>` is a
**layout box, not ink**. At font-size 20 it returns the same 22px (1.10em) for
`...`, `Hg` and `x` alike, so it cannot tell a glyph with a descender from one
without, and its ratio to the band measures the band's definition rather than
the glyphs. Asked of real ink, the overshoot does not collapse to one number:
+0.05em for Latin, +0.30/+0.20em for Arabic, 0 for Hangul. A factor fitted to
the worst case is `1.55/1.05 = 1.48×`, which inflates every Latin row by half an
em; a Latin-fitted factor hides exactly the script that overflows. The
correction is a per-script pair of numbers, not a multiplier.

**Where that table may be used.** Only where its value is labelled as what it
is — a worst case. It drives the row-pitch warning (below) and nothing else; it
deliberately does not set the near-miss threshold or the overlap verdict. The
reason is measured. Applied to `ar`'s stacked rows it flags 19 pairs as
exceeding the clearance available; four of them were rendered and **every one is
clear, with empty pixel rows between the two runs**:

| pair | model band | ink table | pixels |
|---|---|---|---|
| `lifecycle#14 & #15` | 3.0px clear | crosses by 2.3px | 3 empty rows (y=173–175) |
| `architecture#79 & #80` | 4.0px clear | crosses by 1.3px | 4 empty rows (y=756–759) |
| `lifecycle#9 & #10` | 3.4px clear | crosses by 1.5px | 3 empty rows (y=173–175) |
| `design#11 & #12` | 4.4px clear | crosses by 1.0px | 1 empty row (y=227) |

The model agreed with the pixels to within 0.4px on three of the four, and the
fourth (`design#11 & #12`, 4.4px claimed against 1px real) is where the model is
optimistic rather than the table being right. The table over-charges because it
credits every run with the tallest ink measured *anywhere in its script*:
`محوّلات` in `lifecycle#15` reaches 0.76em above its baseline, not the 1.100em it
is charged. That is the same failure mode as the `ru` width artefact above, one
axis over, and it is why the warning is worded as headroom — "these two strings
do not touch, but a taller glyph in either row would" — rather than as a
collision.

For `ar` the underlying structural fact is real and worth knowing: the row pitch
leaves 3.0–4.7px between baselines, and Arabic's measured worst case needs
1.55em (16.3px at `fs=10.5`). A taller glyph in one of those rows would collide
and the width model would not see it coming. Eleven of the twelve locales have
no such row; `ar` has nineteen.

### The source defect behind that threshold

`lifecycle#56` (`excludes bootstrap and plugin loading`) annotates the left edge
of the WordPress lane. It was right-aligned at `x=336` on `y=417` — the gutter
between the framework sub-label column at `x=44` and the lane band at `x=340` —
where its room is `336 - 44 - w(#53)`: 172px in Chinese, but only 123px in
French, whose wording needs 200px. It therefore **overlapped `#53` in 10 of the
12 languages**: 3.7px in German, 19.9px in English, 26.0px Bengali, 55.8px
Indonesian, 77.0px French. (The Bengali figure is a model mean; the rest are
Latin runs, which the model measures exactly.)

Nothing auto-corrected it, and nothing could: `#56`'s anchor point lies outside
every `<rect>`, so the generator measured its container as the whole canvas and
saw a run with room to spare. The generator fits runs to boxes; it has no notion
of run-against-run clearance.

The fix is a template change — `#56` moves up to `y=408`, the lane-title row —
recorded in `LAYOUT_FIXES` in `extract.php`, not hand-edited into
`templates/lifecycle.svg`, because `extract.php` rewrites templates on every run
and would silently revert a hand edit in all 12 languages at once. On that row
the space from `#52`'s right edge (`WordPress`, a `copy` key, so its width is
identical in every language) to `x=336` is a constant **231px**; the longest
wording measured is French at 199.6px, leaving 31.7px.

The separation from `#53`, though, no longer depends on width at all. The two
runs' vertical bands are **5.23px apart** in every locale — a function of
`y` and font size only — and the check needs overlap on *both* axes to call a
collision, so no wording can bring them together. Rendered in Chrome, `fr` still
crosses `#53`'s ink horizontally by 74.8px and is nonetheless clear; `ru` is
clear by 23.4px horizontally as well. Both instruments agree, and they agree for
a structural reason rather than a measured one. The `$bold` trap below is not
part of this story either way: neither `#53` nor `#56` is bold, so a fix to how
`fits()` passes that flag cannot move either run's width.

`selftest.php` synthesises the verdicts by sliding one run along its own
baseline — a `0.5px` intersection (warns, gate still passes), a `2.5px` one
(fails), and a `12px` one where a run's text is Russian and the width is a mean
(warns, naming the model, gate still passes) — and checks that `--quiet` still
shows the warnings.

## Right-to-left

Measured, not assumed. `php tools/i18n/calibrate.php --rtl` renders one run at
`x=200` in both directions:

```
   anchor   direction=ltr (ink L..R) direction=rtl (ink L..R)
   start    200.0..267.8           132.2..200.0
   middle   166.1..233.9           166.1..233.9
   end      132.2..200.0           200.0..267.8
```

**Conclusion: under `direction="rtl"`, `start` and `end` trade places.
`middle` is unaffected.** A run that was drawn to the right of `x` ends up to
the left of it unless something compensates.

So the generator compensates: for `dir: rtl` it sets `direction="rtl"` on the
root and swaps the *effective* anchor of each run, so every run keeps the
rectangle it had in the source. `check.php` enforces that swap.

### The compensation is conditional, and getting that wrong is invisible

Swapping the anchor of a run that has **no strong RTL character** — a run like
`serve()` — was a real defect in this generator, and it printed reversed text
rather than failing. Under `direction="rtl"` the bidi algorithm resolves a run's
edge neutrals (`(`, `)`, `\`, `&`) against the *paragraph* direction, so `serve()`
was laid out right-to-left and rendered as `()serve`. Nothing in the geometry
check could see it: the run is the same width either way, in the same box, at the
same baseline, and its anchors were perfectly consistent with the swap. It took a
renderer to notice.

The rule the generator now follows: **a run is swapped only if its text contains
a strong RTL character; every other run is pinned `direction="ltr"` and left
unswapped.** `i18n_has_rtl()` in `lib.php` is the single predicate behind both
sides — `generate.php` pins by it and `check.php` verifies by it — so the two
cannot drift apart and disagree about which runs are exempt.

Pinning beats the alternative. Inserting U+200E (LRM) at the run's edges would
force the same result, but it changes the run's text, and `check.php` compares
run text against the glossary **byte for byte**: twelve locales would have needed
their protected-token comparisons relaxed to accommodate seven invisible
characters. `direction="ltr"` reaches the same rendering through an attribute
`i18n_effective_anchor()` already reads, so no caller needed changing.

Measured on `ar` with an independent instrument — Chrome's
`getStartPositionOfChar()`, which reports where a character actually landed.
For a run with no strong RTL character, character 0 must sit to the left of the
last character:

| | before | after |
|---|---|---|
| `architecture` reversed runs | 12 | 0 |
| `lifecycle` reversed runs | 8 | 0 |

188 runs probed across the three diagrams; the 9 not probed are 8 single-digit
runs and one wrapped run, which that probe cannot measure. `check.php` now fails
on any run that is neither pinned nor genuinely RTL:

```
architecture: 42 run(s) with no RTL character are not pinned direction="ltr"
— bidi reorders their edge neutrals (e.g. "webman", "Webman\XhprofMiddleware", "Laravel")
```

`selftest.php` reproduces it by un-pinning those runs in a scratch copy.

Three things to know before you set `dir`:

* **The compensation is on the effective anchor, not the attribute.** 160 of the
  197 runs never write `text-anchor` at all and rely on the SVG default, which
  is `start`. Swapping only written attributes leaves those runs to fall back
  to the rtl default and jump out of their boxes. This was a real bug here; the
  selftest now covers it.
* **The layout is not mirrored.** Each run stays where it was; only the glyph
  order within the run changes. Diagram reading order is unchanged. Mirroring
  the whole diagram is a redesign, not a translation — do not do it here.
* **Digits, Latin identifiers and protected code tokens inside an rtl run
  reorder according to the bidi algorithm.** `PSR-15` and `src/Core/Contract/`
  should stay readable, but check them visually; nothing in this directory can
  verify that for you.

Let the generator do both the swap and the pin. Hand-editing either attribute
goes red: drop a `direction="ltr"` and the bidi check reports the run as
unpinned; add one to a run that genuinely needs the swap and the anchor check
reports it as anchored on the wrong side.

## Traps

**The width model is an estimate, and only exact for one font.** The diagrams
declare a font stack that resolves to different faces on different systems
(SF Pro on macOS, Segoe UI on Windows, Liberation Sans under this repo's font
config). Per-glyph measurement against the resolved face is exact — the ASCII
tables agree with Chrome to within 0.1% — but scripts the model has never been
measured on are per-script means. For any non-Latin script, run:

```sh
php tools/i18n/calibrate.php --lang=de
```

It reports, per run, the modelled width against Chrome's measured width, and
whether anything overflows *as actually rendered*. If it prints
`worst under-estimate` with a large negative number, the model is optimistic
for your script and you should tell the maintainers rather than trust the
checker.

**Han characters are exact at 1.0 em; Hangul is an upper bound.** Measured
with Chrome at `font-size:100` on the font stack the diagrams declare:
`中文` = 200.0px / 2 chars, `日本語` = 300.0px / 3 — exactly 1.0 em each, not an
estimate. `가나다` and `한국어` = 276.0px / 3 chars = **0.92 em**, because the
stack resolves to a proportional face here; the model charges them 1.0 em, so
it over-reports Korean by ~9%. That is the safe direction — the error is slack
the layout does not need, not overflow the checker cannot see — but it is not
an identity, and a Korean run the checker passes has ~9% less room in reality
than the numbers suggest. That is the reason the collisions section above is
script-dependent.

**The means do not all err in the same direction, and Arabic is the one to
watch.** `calibrate.php` over all 197 runs of each locale, on this build machine
— "wider/narrower" counts runs the model predicted wider or narrower than Chrome
measured them:

| locale | script | predicted wider / narrower | largest error | worst under-estimate |
|---|---|---|---|---|
| `fr` | Latin | 123 / 74 | 11.23px | −0.01px |
| `de` | Latin | 104 / 93 | 6.33px | −0.02px |
| `ko` | Hangul | 134 / 63 | 35.87px | −0.01px |
| `bn` | Bengali | 134 / 63 | 197.78px | −0.01px |
| `hi` | Devanagari | 134 / 63 | 151.94px | −0.01px |
| `ru` | Cyrillic | 134 / 63 | 249.07px | −0.01px |
| `ar` | Arabic | 133 / 64 | 185.75px | **−0.91px** |

Two things follow, and only the first is comfortable. The means are mostly
*conservative*: the non-Latin locales over-charge about two runs for every one
they under-charge, and their worst under-estimate is 0.01px — so on those
scripts the failure mode is a false collision alarm, not a missed overflow. That
is why a collision on an approximate script warns instead of failing the gate.

Arabic breaks the pattern in the one place that matters: it is the only locale
measured here whose worst under-estimate is not negligible, at **−0.91px**. It
is the only script on this machine where the model can be *optimistic* about a
run rather than merely wrong — which is also the origin of the "0.91px overflow"
in an earlier report, an artefact of the mean and not a real one. For `ar`, and
for any script not in this table, run `calibrate.php --lang=<lang>` and decide
from the rendered numbers: all seven locales above pass it, meaning no run
overflows its box as Chrome measures it.

These means were measured against whatever faces fontconfig happened to supply
here, which on this build machine were unusual ones, and nothing above transfers
to a machine with different coverage. Hebrew and Thai appear nowhere in the
table because no locale in the tree uses them yet: treat them as unmeasured, not
as conservative.

**Diacritics and combining marks are zero-width.** Correct for advance width,
but a string of stacked marks can be visually taller than the line box. Nothing
here checks height.

**Bold is a separate table, and every caller has to ask for it.** Non-ASCII bold
runs measure about 6% wider than the plain table, so a width computed without
`$bold = true` under-reports them. `generate.php`'s `fits()` did exactly that
until it was found: it was the one call site that dropped the flag, which meant
the generator *believed* it had left clearance it had not, in the one function
whose whole job is deciding whether a shrink succeeded. It hid a real overflow —
regenerating `bn` after the fix produced

```
OVERFLOW architecture.coupling.71: needs 248.9px, has 242.0px after shrink to
75% of 13.00 and layout/wrap attempts; container=rect 252x26
```

and `generate.php` refused to write that diagram, which is the correct
behaviour: a generator that cannot fit a run must say so, not ship it. If you
add a call to `FontMetrics::width()` anywhere, check whether the run can be
bold.

**English is not a clean baseline, and wider scripts overflow first.** The
English pilot is generated by the same autofit, and **8 of its runs are already
too wide at full size** — the generator shrinks them silently. Measured by
comparing `templates/<doc>.svg` against `docs/i18n/en/images/<doc>.svg`:

| key | font size | plain English |
|---|---|---|
| `architecture.entry.10/13/16/19` | 10.50 → 8.54 (81%) | `supported` ×4 |
| `architecture.coupling.71` | 13.00 → 10.20 (78%) | "The only two couplings to framewor…" |
| `lifecycle.pipeline.5` | 10.50 → 7.87 (**75%, the floor**) | "enters the framework requestpipeli…", **also wrapped to 2 lines** |
| `design.row3.27` | 11.00 → 10.31 (94%) | "(Symfony / Drupal) have the same s…" |
| `design.row4.38` | 11.00 → 9.83 (89%) | "otherwise the stub faithfully enco…" |

So the earlier wording here — "if a key is already near the floor in English,
expect it to fail" — understated it: `lifecycle.pipeline.5` is *on* the floor
already, at 75% **and** wrapped, which means the shrink rung and the wrap rung
are both spent. Any wording wider than the English one hits `OVERFLOW` there
with nothing left to try. The other seven have 6–25% in hand, so a wording no
wider than English is fine and a wider one is not. German is roughly 15–25%
wider than English for the same content; Finnish and Hungarian compound more.
Compare your translation against the English column above before assuming a
key has room, and plan a shorter wording — an abbreviation, a different
phrasing, a parenthetical moved out of the run — rather than making it fit by
force. The four `architecture.entry.*` boxes are the smallest: `已适配` costs
3 em, English `supported` costs about 4.6 em, and every language after that
pays more.

**A green check is not a good translation.** `check.php` verifies structure,
protected tokens, links and geometry. It has no opinion on meaning, register or
whether the sentence is natural. Say plainly in your report that the
translation is unreviewed — the notice you are required to add says exactly
that, to the reader.

**`--dry-run` means the tree is not touched, and that has to be true.** It is
what `check.php` calls to ask whether the generator would accept a locale, so a
dry run with a side effect is a gate that writes. It had one: the `$dry` guard
was on the SVG loop and not on the `README.md` write at the end, so a dry run
still rewrote the README with identical bytes — invisible to any content
comparison, visible only in the mtime. If you add a write to `generate.php`, put
it behind `$dry` and extend the fingerprint case in `selftest.php`, which
compares mtimes and hashes rather than content.

**A refusal is not the same as a check that ran.** `generate.php` declines a
locale by writing nothing and exiting 1, leaving the last good diagram on disk.
`check.php` now asks the generator directly, because the state used to be
reported with the wrong cause: the glossary-vs-file comparison reddens on the
stale text and says "file text does not match glossary", which reads as someone
hand-editing an SVG. The real cause is that the translation no longer fits.
Same red, different sentence — and the second sentence is the one that tells you
what to do.

## README

`docs/i18n/<lang>/README.md` is generated, not hand-written. Put your
translation of `README.EN.md` at `tools/i18n/readme/<lang>.md` and let
`generate.php` write the output, because it also rewrites every relative link
for the new location three levels down (the three diagrams point at your
locale's own copies, everything else still points at the original `docs/`) and
prepends the notice. `check.php` then `stat`s every relative link and resolves
every in-page anchor against the generated headings.

Do not edit `docs/i18n/<lang>/README.md` directly; the next `generate.php` run
overwrites it.

The link rewrite measures its depth rather than assuming it. `i18n_out_depth()`
counts the path from the repo root down to the output directory, so the `../`
prefixes stay correct when the tree is relocated — the selftest points
`I18N_OUT_ROOT` at a scratch directory, and a hardcoded `../../../` would have
made every scratch run fail on links that are fine in the real tree.

`I18N_OUT_ROOT` **must be inside the repository**, and anything else is refused
with exit 2 before a single file is written. Those links point at the root
`README.md`, `README.EN.md` and `docs/*.png`, so a root outside the repo cannot
be described in relative terms at all. The refusal matters more than it looks:
the version before it computed a depth by subtracting the repo prefix from a
path that did not have it, got an empty string, and answered `1` instead of
failing. That rendered `../README.md` where `../../../README.md` belongs and
wrote it into every locale over a green exit code — a wrong answer wearing the
shape of a right one. `selftest.php` now asserts both that such a root is
refused and that nothing reaches the disk first.

### Why `docs/i18n/en/` keeps a README

`en` is the pilot, and its four artefacts are not all the same kind of thing:

* `docs/i18n/en/images/*.svg` is the **width and overlap baseline** `check.php`
  measures every other locale against. It must stay.
* `docs/i18n/en/README.md` is a normal output of the same generator, and it is
  what a reader who opens `docs/i18n/en/` lands on. It has no
  machine-translation notice, because `check.php` forbids one on a source
  language — `en` translates `README.EN.md`, which is already English.
* The English entry in the switcher points at the **repo-root** `README.EN.md`,
  not at this copy, because the root file is the English README. That is why the
  parity test excludes `en` when it checks that the switcher lists exactly the
  locales on disk: `docs/i18n/en/` is a baseline directory, not a language entry.

Do not delete it as redundant. `check.php` requires `<output>/<lang>/README.md`
for every locale including `en`, so removing the file turns `check.php --lang=en`
red with `FAIL  missing …/docs/i18n/en/README.md` (measured, by deleting it), and
the only way back to green would be to exempt `en` from a check that currently
covers all twelve.

### The language switcher

Above the first heading, under the notice, `generate.php` writes one line
linking every README in the family — the Chinese original and the English
source at the repo root, plus all 11 translations:

```
[中文](../../../README.md) · [English](../../../README.EN.md) · [한국어](../ko/README.md) · …
```

The current page is marked `**like this**` and is not a link. Names are
endonyms — each language's name for itself, never an English or Chinese
exonym — because the reader who needs this line is by definition on a page they
cannot read. The table lives in `I18N_LANGUAGES` in `lib.php`; add a locale
there, not by editing 13 files. Every link is relative and `check.php` resolves
each one against the filesystem, so a missing sibling README is a failure, not
a dead link discovered by a reader.
