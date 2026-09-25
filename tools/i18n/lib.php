<?php
declare(strict_types=1);

/**
 * tools/i18n/lib.php — shared helpers for the README + SVG translation pipeline.
 *
 * Used by extract.php / generate.php / check.php / calibrate.php.  No external
 * dependencies beyond ext-mbstring and ext-dom (both already loaded).
 */

const I18N_DIR   = __DIR__;
const I18N_REPO  = __DIR__ . '/../..';

/** Documents that get a translated SVG.  Matches docs/images/<doc>.svg. */
const I18N_DOCS = ['architecture', 'lifecycle', 'design'];

/**
 * Every README in the family, in the order the language switcher lists them:
 * the two source languages first, then the translations.
 *
 * `name` is the endonym — the language's own name for itself.  A switcher that
 * offered "Korean" to a Korean reader would be in the wrong language, and the
 * point of the line is to be readable by someone who cannot read the page they
 * are on.
 *
 * The second element is the file at the *repository root* that this locale's
 * README is a rendering of, or null when the locale's README lives *beside* the
 * others under the output root (`docs/i18n/<code>/README.md`).  `en` is null as
 * well: it is a source language, but since the English README moved into `docs/`
 * its file is a sibling, not a root file.  Paths are built from that rather than
 * stored, so changing where locales are written does not silently break 13 links.
 */
const I18N_LANGUAGES = [
    'zh' => ['中文',             'README.md'],
    'en' => ['English',          null],
    'ko' => ['한국어',            null],
    'ru' => ['Русский',          null],
    'de' => ['Deutsch',          null],
    'fr' => ['Français',         null],
    'es' => ['Español',          null],
    'pt' => ['Português',        null],
    'ar' => ['العربية',           null],
    'hi' => ['हिन्दी',             null],
    'bn' => ['বাংলা',            null],
    'id' => ['Bahasa Indonesia', null],
    'ja' => ['日本語',             null],
];

/** Lexically resolve `.` and `..` in an absolute path.  Path need not exist. */
function i18n_norm_path(string $p): string
{
    $out = [];
    foreach (explode('/', $p) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            array_pop($out);
            continue;
        }
        $out[] = $seg;
    }
    return '/' . implode('/', $out);
}

/**
 * Where generated locales are written.  Default `docs/i18n`; overridable so
 * selftest.php can put its throwaway locale somewhere that is not the delivery
 * directory — anything that globs `docs/i18n/` must see deliverables only, and
 * a locale that lives for three seconds is not one.
 *
 * Normalised, because everything that compares this against I18N_REPO needs the
 * `tools/i18n/../..` in the default to be gone rather than compared literally.
 */
function i18n_out_root(): string
{
    return i18n_norm_path(rtrim(getenv('I18N_OUT_ROOT') ?: I18N_REPO . '/docs/i18n', '/'));
}

/** The directory holding one locale's generated artefacts. */
function i18n_out_dir(string $lang): string
{
    return i18n_out_root() . '/' . $lang;
}

/**
 * Where a locale's *inputs* are read from: `glossary/$lang.json` and (for a
 * translation) `readme/$lang.md`.  Default `tools/i18n`; overridable so
 * selftest.php can exercise the source-language path against a copy, instead of
 * editing the delivered `glossary/en.json` and restoring it afterwards — a
 * restore is only as reliable as the shutdown handler that performs it.
 *
 * `!== false && !== ''` rather than `?:` on purpose: a root of "0" is falsy, and
 * quietly falling back to the delivered glossary is the exact substitution this
 * override exists to rule out.
 */
function i18n_input_root(): string
{
    $root = getenv('I18N_INPUT_ROOT');
    return $root === false || $root === '' ? I18N_DIR : i18n_norm_path(rtrim($root, '/'));
}

/**
 * How many `../` climb from a locale directory back to the repository root.
 * Derived rather than written out: a hardcoded `../../../` is one directory
 * move away from pointing every link in every locale at the wrong place.
 *
 * The output root has to be inside the repository for those relative links to
 * be expressible at all — the switcher points at the root README.md and at the
 * sibling en/README.md, and the README body at docs/*.png.  A root outside the repo
 * therefore cannot be described in relative terms, and the only honest response
 * is to refuse.  Returning a number anyway is worse than useless: an earlier
 * version subtracted the repo prefix from a path that did not have it, got an
 * empty string, and quietly answered "depth 1" — which rendered
 * `../README.md` where `../../../README.md` belonged and wrote it into every
 * locale, over a green exit code.  Fail loudly instead.
 */
function i18n_out_depth(): int
{
    $root = i18n_out_root();
    // I18N_REPO is `__DIR__/../..`, so compare normalised paths — otherwise a
    // scratch root that IS inside the repo looks like it is not.
    $repo = realpath(I18N_REPO) ?: I18N_REPO;
    if ($root !== $repo && !str_starts_with($root, $repo . '/')) {
        fwrite(STDERR, "I18N_OUT_ROOT must be inside the repository: I18N_REPO is $repo"
            . ", got $root.  The README's relative links cannot be written for a root outside it.\n");
        exit(2);
    }
    $rel = trim(substr($root, strlen($repo)), '/');
    return ($rel === '' ? 0 : substr_count($rel, '/') + 1) + 1;
}

/** One line of links to every other README, with $lang marked and unlinked. */
function i18n_language_switcher(string $lang): string
{
    $up = str_repeat('../', i18n_out_depth());
    $parts = [];
    foreach (I18N_LANGUAGES as $code => [$name, $rootFile]) {
        $href = $rootFile === null ? "../$code/README.md" : $up . $rootFile;
        $parts[] = $code === $lang ? "**$name**" : "[$name]($href)";
    }
    return implode(' · ', $parts);
}

/** Padding kept free on each side of a text run inside its container, in px. */
const I18N_PAD = 2.0;

/** Hard floor for automatic font shrinking, as a fraction of the source size. */
const I18N_MIN_SCALE = 0.75;

/** Line advance for wrapped runs, as a multiple of the font size. */
const I18N_LINE_HEIGHT = 1.25;

/**
 * How far a run's ink is assumed to reach above / below its baseline, in em.
 *
 * Deliberately generous: these decide whether two runs are stacked on top of
 * each other, and a collision missed here is a collision shipped.
 */
const I18N_BAND_ASCENT  = 0.8;
const I18N_BAND_DESCENT = 0.25;

/**
 * Measured ink reach above / below the baseline, in em, per script.
 *
 * The two constants above are what the model *assumes*.  These are what was
 * rasterised and counted: every distinct string in the twelve locales of this
 * tree, rendered alone at 20px in the diagrams' own font stack on an opaque
 * background, converted with rsvg-convert, ink = a pixel row whose channels sum
 * below 600.  Reproduce with the method in tools/i18n/README.md.
 *
 * They say the assumed band is right for Latin and CJK and too small for the
 * rest — 0.8+0.25 = 1.05em between two baselines is 0.05em conservative for
 * Latin and 0.50em optimistic for Arabic:
 *
 *   Latin, Cyrillic, Greek, ...   0.850 / 0.150   (the ① of "① Framework entry
 *                                  layer" is the tallest glyph in en/fr/de/ru)
 *   Arabic                        1.100 / 0.450   alef reaches 1.100em above,
 *                                  the jeem/meem tails 0.450em below
 *   Devanagari                    0.900 / 0.300
 *   Bengali                       0.850 / 0.250
 *   Hangul, kana, han             0.900 / 0.150
 *
 * Two reasons this is a table and not one coefficient.  First, the number a
 * single multiplier would need depends on which end you fit: the worst pair
 * here is 1.55/1.05 = 1.48x, which inflates every Latin pair by half an em,
 * while a Latin-fitted 0.95x hides exactly the script that overflows.  Second,
 * a *pair* has two scripts — the run above contributes its descent and the run
 * below its ascent — so the correction is a sum of two lookups, not a scale.
 *
 * Unlisted scripts keep the Latin numbers.  For a script taller than Latin that
 * is an under-estimate, and deliberately so: only the scripts in this tree have
 * been measured, and a guessed coefficient is the thing this table exists to
 * replace.  The numbers are also lower bounds — pixel rows quantise to 0.05em
 * and a faint antialiased edge can fall outside the darkness threshold.
 */
const I18N_INK_DEFAULT = [0.850, 0.150];

/** [start, end, em above baseline, em below] — measured, see I18N_INK_DEFAULT. */
const I18N_INK = [
    [0x0600, 0x06FF, 1.10, 0.45],  // Arabic
    [0x0750, 0x077F, 1.10, 0.45],  // Arabic Supplement
    [0xFB50, 0xFDFF, 1.10, 0.45],  // Arabic presentation forms A
    [0xFE70, 0xFEFF, 1.10, 0.45],  // Arabic presentation forms B
    [0x0900, 0x097F, 0.90, 0.30],  // Devanagari
    [0x0980, 0x09FF, 0.85, 0.25],  // Bengali
    [0x3040, 0x30FF, 0.90, 0.15],  // hiragana, katakana
    [0x3400, 0x4DBF, 0.90, 0.15],  // CJK extension A
    [0x4E00, 0x9FFF, 0.90, 0.15],  // CJK unified ideographs
    [0xAC00, 0xD7A3, 0.90, 0.15],  // Hangul syllables
];

// ---------------------------------------------------------------------------
// Font metrics
// ---------------------------------------------------------------------------

/**
 * Advance-width model, in em units.
 *
 * Design: an *estimate* that is deliberately biased slightly high, because the
 * failure that matters is shipping text that overflows its box.  Over-estimating
 * costs a few fractions of a point of font size; under-estimating ships a broken
 * diagram.
 *
 * - ASCII      : exact Arial/Helvetica/Liberation Sans advances (measured, see
 *                calibrate.php — the three are metric-compatible).
 * - CJK / wide : exactly 1.0 em.  True for every CJK font; not an estimate.
 * - combining  : 0.  Marks stack onto the preceding glyph.
 * - everything else: per-script mean measured against DejaVu Sans (a *wider*
 *                font than Arial), rounded up.  See calibrate.php for the
 *                measurements and for which scripts could not be measured here.
 */
final class FontMetrics
{
    private const ASCII = [
        ' ' => 0.2778, '!' => 0.2778, '"' => 0.3550, '#' => 0.5562, '$' => 0.5562,
        '%' => 0.8892, '&' => 0.6670, "'" => 0.1909, '(' => 0.3330, ')' => 0.3330,
        '*' => 0.3892, '+' => 0.5840, ',' => 0.2778, '-' => 0.3330, '.' => 0.2778,
        '/' => 0.2778, '0' => 0.5562, '1' => 0.5562, '2' => 0.5562, '3' => 0.5562,
        '4' => 0.5562, '5' => 0.5562, '6' => 0.5562, '7' => 0.5562, '8' => 0.5562,
        '9' => 0.5562, ':' => 0.2778, ';' => 0.2778, '<' => 0.5840, '=' => 0.5840,
        '>' => 0.5840, '?' => 0.5562, '@' => 1.0151, 'A' => 0.6670, 'B' => 0.6670,
        'C' => 0.7222, 'D' => 0.7222, 'E' => 0.6670, 'F' => 0.6108, 'G' => 0.7778,
        'H' => 0.7222, 'I' => 0.2778, 'J' => 0.5000, 'K' => 0.6670, 'L' => 0.5562,
        'M' => 0.8330, 'N' => 0.7222, 'O' => 0.7778, 'P' => 0.6670, 'Q' => 0.7778,
        'R' => 0.7222, 'S' => 0.6670, 'T' => 0.6108, 'U' => 0.7222, 'V' => 0.6670,
        'W' => 0.9439, 'X' => 0.6670, 'Y' => 0.6670, 'Z' => 0.6108, '[' => 0.2778,
        '\\' => 0.2778, ']' => 0.2778, '^' => 0.4693, '_' => 0.5562, '`' => 0.3330,
        'a' => 0.5562, 'b' => 0.5562, 'c' => 0.5000, 'd' => 0.5562, 'e' => 0.5562,
        'f' => 0.2778, 'g' => 0.5562, 'h' => 0.5562, 'i' => 0.2222, 'j' => 0.2222,
        'k' => 0.5000, 'l' => 0.2222, 'm' => 0.8330, 'n' => 0.5562, 'o' => 0.5562,
        'p' => 0.5562, 'q' => 0.5562, 'r' => 0.3330, 's' => 0.5000, 't' => 0.2778,
        'u' => 0.5562, 'v' => 0.5000, 'w' => 0.7222, 'x' => 0.5000, 'y' => 0.5000,
        'z' => 0.5000, '{' => 0.3340, '|' => 0.2598, '}' => 0.3340, '~' => 0.5840,
    ];

    /**
     * Arial Bold advances, same method as ASCII above.  Weight is not a scale
     * factor: Arial Bold 'W' is 0.9438 em, identical to regular, while 'A' grows
     * from 0.6670 to 0.7222.  A blanket multiplier would be wrong in both
     * directions, so bold gets its own table.
     */
    private const ASCII_BOLD = [
        ' ' => 0.2778, '!' => 0.3330, '"' => 0.4741, '#' => 0.5562, '$' => 0.5562, '%' => 0.8892,
        '&' => 0.7222, '\'' => 0.2378, '(' => 0.3330, ')' => 0.3330, '*' => 0.3892, '+' => 0.5840,
        ',' => 0.2778, '-' => 0.3330, '.' => 0.2778, '/' => 0.2778, '0' => 0.5562, '1' => 0.5562,
        '2' => 0.5562, '3' => 0.5562, '4' => 0.5562, '5' => 0.5562, '6' => 0.5562, '7' => 0.5562,
        '8' => 0.5562, '9' => 0.5562, ':' => 0.3330, ';' => 0.3330, '<' => 0.5840, '=' => 0.5840,
        '>' => 0.5840, '?' => 0.6108, '@' => 0.9751, 'A' => 0.7222, 'B' => 0.7222, 'C' => 0.7222,
        'D' => 0.7222, 'E' => 0.6670, 'F' => 0.6108, 'G' => 0.7778, 'H' => 0.7222, 'I' => 0.2778,
        'J' => 0.5562, 'K' => 0.7222, 'L' => 0.6108, 'M' => 0.8330, 'N' => 0.7222, 'O' => 0.7778,
        'P' => 0.6670, 'Q' => 0.7778, 'R' => 0.7222, 'S' => 0.6670, 'T' => 0.6108, 'U' => 0.7222,
        'V' => 0.6670, 'W' => 0.9438, 'X' => 0.6670, 'Y' => 0.6670, 'Z' => 0.6108, '[' => 0.3330,
        '\\' => 0.2778, ']' => 0.3330, '^' => 0.5840, '_' => 0.5562, '`' => 0.3330, 'a' => 0.5562,
        'b' => 0.6108, 'c' => 0.5562, 'd' => 0.6108, 'e' => 0.5562, 'f' => 0.3330, 'g' => 0.6108,
        'h' => 0.6108, 'i' => 0.2778, 'j' => 0.2778, 'k' => 0.5562, 'l' => 0.2778, 'm' => 0.8892,
        'n' => 0.6108, 'o' => 0.6108, 'p' => 0.6108, 'q' => 0.6108, 'r' => 0.3892, 's' => 0.5562,
        't' => 0.3330, 'u' => 0.6108, 'v' => 0.5562, 'w' => 0.7778, 'x' => 0.5562, 'y' => 0.5562,
        'z' => 0.5000, '{' => 0.3892, '|' => 0.2798, '}' => 0.3892, '~' => 0.5840,
    ];

    /**
     * Extra width for non-ASCII glyphs at bold weight.  Measured against both
     * Arial/Liberation Bold (1.09-1.11x) and DejaVu Sans Bold (1.14-1.15x); the
     * value taken is between the two, leaning high for safety.
     */
    private const BOLD_NON_ASCII = 1.12;

    /** Non-ASCII code points that actually occur in the diagrams, measured. */
    private const OVERRIDE = [
        0x00B7 => 0.34,  // ·  middle dot (measured 0.3331)
        0x2013 => 0.56,  // –  en dash
        0x2014 => 1.00,  // —  em dash (a full em in Arial, unlike the 0.62 default)
        0x2018 => 0.34, 0x2019 => 0.34, 0x201C => 0.34, 0x201D => 0.34,
        0x2026 => 1.00,  // …
        // →  measured 1.0000em here — the glyph comes from a fallback face, not
        // from the Latin one, and every fallback measured for it so far is
        // full-width.  0.60 was an Arial value and was wrong by 0.4em per arrow:
        // it is what calibrate.php caught on the first run.
        0x2192 => 1.00,
        0x2460 => 1.00, 0x2461 => 1.00, 0x2462 => 1.00, 0x2463 => 1.00, 0x2464 => 1.00,
    ];

    /** [start, end, em] — per-script means, measured against DejaVu Sans. */
    private const SCRIPTS = [
        [0x00A0, 0x024F, 0.70],  // Latin-1 Supplement, Latin Extended-A/B
        [0x0250, 0x02FF, 0.62],  // IPA, spacing modifiers
        [0x0300, 0x036F, 0.00],  // combining diacritics
        [0x0370, 0x03FF, 0.70],  // Greek
        [0x0400, 0x052F, 0.78],  // Cyrillic
        [0x0590, 0x05FF, 0.62],  // Hebrew
        [0x0600, 0x06FF, 0.62],  // Arabic
        [0x0700, 0x074F, 0.62],  // Syriac
        [0x0750, 0x077F, 0.62],  // Arabic Supplement
        [0x0900, 0x097F, 0.65],  // Devanagari
        [0x0980, 0x09FF, 0.62],  // Bengali
        [0x0A00, 0x0A7F, 0.62],  // Gurmukhi
        [0x0B80, 0x0BFF, 0.62],  // Tamil
        [0x0C00, 0x0C7F, 0.62],  // Telugu
        [0x0E00, 0x0E7F, 0.58],  // Thai
        [0x10A0, 0x10FF, 0.70],  // Georgian
        [0x1E00, 0x1EFF, 0.70],  // Latin Extended Additional (Vietnamese)
        [0x2000, 0x206F, 0.62],  // General punctuation
        [0x20A0, 0x20CF, 0.70],  // Currency symbols
        [0x2100, 0x214F, 0.70],  // Letterlike symbols
        [0x2190, 0x21FF, 0.60],  // Arrows
        [0x2200, 0x22FF, 0.70],  // Mathematical operators
        [0x2500, 0x257F, 0.70],  // Box drawing
        [0x25A0, 0x25FF, 0.85],  // Geometric shapes
        [0x2600, 0x26FF, 0.90],  // Miscellaneous symbols
        [0xFB50, 0xFDFF, 0.62],  // Arabic presentation forms A
        [0xFE70, 0xFEFF, 0.62],  // Arabic presentation forms B
    ];

    private const DEFAULT_EM = 0.62;

    /** Advance width of $text rendered at $fontSize, in px. */
    public static function width(string $text, float $fontSize, bool $bold = false): float
    {
        // SVG collapses leading/trailing whitespace on a text run unless
        // xml:space="preserve"; the browsers we target do the same.
        $text = trim($text);
        if ($text === '') {
            return 0.0;
        }
        $sum = 0.0;
        foreach (self::codepoints($text) as $cp) {
            $em = self::em($cp, $bold);
            // Bold does not widen CJK, nor any glyph that had to come from a
            // fallback face: those faces have no bold variant and the
            // synthesised one keeps the same advance.  Measured bold == regular
            // to 4 decimals for every OVERRIDE code point.  Scaling them anyway
            // cost 0.12em per glyph, which calibrate.php reported as a 0.9%
            // over-estimate on the two runs that use ② and —.
            if ($bold && $cp >= 0x7F && !self::isWide($cp) && !isset(self::OVERRIDE[$cp]) && $em > 0.0) {
                $em *= self::BOLD_NON_ASCII;
            }
            $sum += $em;
        }
        return $sum * $fontSize;
    }

    /** True if a font-weight attribute means "bold" for width purposes. */
    public static function isBold(string $weight): bool
    {
        return $weight !== '' && (int) $weight >= 600;
    }

    /** True if any code point falls outside the exactly-measured ASCII/CJK sets. */
    public static function isApproximate(string $text): bool
    {
        foreach (self::codepoints(trim($text)) as $cp) {
            if ($cp < 0x7F || self::isWide($cp) || isset(self::OVERRIDE[$cp])) {
                continue;
            }
            return true;
        }
        return false;
    }

    private static function em(int $cp, bool $bold = false): float
    {
        if ($cp >= 0x20 && $cp < 0x7F) {
            return $bold ? self::ASCII_BOLD[chr($cp)] : self::ASCII[chr($cp)];
        }
        if (isset(self::OVERRIDE[$cp])) {
            return self::OVERRIDE[$cp];
        }
        if ($cp === 0x200B || $cp === 0x200C || $cp === 0x200D || $cp === 0x200E || $cp === 0x200F || $cp === 0xFEFF) {
            return 0.0;
        }
        if ($cp < 0xA0) {
            return self::DEFAULT_EM;
        }
        if (self::isMark($cp)) {
            return 0.0;
        }
        if (self::isWide($cp)) {
            return 1.0;
        }
        foreach (self::SCRIPTS as [$lo, $hi, $em]) {
            if ($cp >= $lo && $cp <= $hi) {
                return $em;
            }
        }
        return self::DEFAULT_EM;
    }

    /** East Asian Wide / Fullwidth — rendered at one full em by every CJK font. */
    private static function isWide(int $cp): bool
    {
        return $cp >= 0x1100 && (
            $cp <= 0x115F                            // Hangul Jamo initial
            || $cp === 0x2329 || $cp === 0x232A
            || ($cp >= 0x2E80 && $cp <= 0x303E)      // CJK radicals, Kangxi, CJK punctuation
            || ($cp >= 0x3041 && $cp <= 0x33FF)      // kana, Hangul compat, CJK compat
            || ($cp >= 0x3400 && $cp <= 0x4DBF)      // CJK Ext A
            || ($cp >= 0x4E00 && $cp <= 0x9FFF)      // CJK Unified
            || ($cp >= 0xA000 && $cp <= 0xA4CF)      // Yi
            || ($cp >= 0xAC00 && $cp <= 0xD7A3)      // Hangul syllables
            || ($cp >= 0xF900 && $cp <= 0xFAFF)      // CJK compat ideographs
            || ($cp >= 0xFE10 && $cp <= 0xFE19)      // vertical forms
            || ($cp >= 0xFE30 && $cp <= 0xFE6F)      // CJK compat forms
            || ($cp >= 0xFF00 && $cp <= 0xFF60)      // fullwidth forms
            || ($cp >= 0xFFE0 && $cp <= 0xFFE6)
            || ($cp >= 0x1F300 && $cp <= 0x1FAFF)    // emoji
            || ($cp >= 0x20000 && $cp <= 0x3FFFD)    // CJK Ext B+
        );
    }

    private static function isMark(int $cp): bool
    {
        if (class_exists('IntlChar')) {
            $cat = IntlChar::getIntPropertyValue($cp, IntlChar::PROPERTY_GENERAL_CATEGORY);
            return in_array($cat, [
                IntlChar::CHAR_CATEGORY_NON_SPACING_MARK,
                IntlChar::CHAR_CATEGORY_ENCLOSING_MARK,
                IntlChar::CHAR_CATEGORY_FORMAT_CHAR,
            ], true);
        }
        // Fallback without ext-intl: the common combining blocks.
        return ($cp >= 0x0300 && $cp <= 0x036F)
            || ($cp >= 0x0483 && $cp <= 0x0489)
            || ($cp >= 0x0591 && $cp <= 0x05BD)
            || ($cp >= 0x0610 && $cp <= 0x061A)
            || ($cp >= 0x064B && $cp <= 0x065F)
            || ($cp >= 0x0900 && $cp <= 0x0903)
            || ($cp >= 0x093A && $cp <= 0x094F)
            || ($cp >= 0x0981 && $cp <= 0x0983)
            || ($cp >= 0x09BC && $cp <= 0x09CD)
            || ($cp >= 0x0E31 && $cp <= 0x0E3A)
            || ($cp >= 0x0E47 && $cp <= 0x0E4E);
    }

    /** @return int[] */
    /** Every code point of $s in order.  Public because i18n_ink_extents() reads the same table. */
    public static function codepoints(string $s): array
    {
        $out = [];
        $len = mb_strlen($s, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $out[] = mb_ord(mb_substr($s, $i, 1, 'UTF-8'), 'UTF-8');
        }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// SVG document helpers
// ---------------------------------------------------------------------------

function i18n_load_svg(string $path): DOMDocument
{
    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = true;
    $doc->formatOutput = false;
    if (!$doc->load($path)) {
        throw new RuntimeException("cannot parse SVG: $path");
    }
    return $doc;
}

/** @return DOMElement[] in document order */
function i18n_text_nodes(DOMDocument $doc): array
{
    $out = [];
    foreach ((new DOMXPath($doc))->query('//*[local-name()="text"]') as $n) {
        $out[] = $n;
    }
    return $out;
}

function i18n_meta_node(DOMDocument $doc, string $tag): ?DOMElement
{
    $n = (new DOMXPath($doc))->query("//*[local-name()='$tag']")->item(0);
    return $n instanceof DOMElement ? $n : null;
}

/** Text of a node with tspan children flattened onto one line. */
function i18n_flat_text(DOMElement $el): string
{
    if (!$el->getElementsByTagName('tspan')->length) {
        return $el->textContent;
    }
    $parts = [];
    foreach ($el->childNodes as $c) {
        $parts[] = $c->textContent;
    }
    return trim(implode(' ', array_filter(array_map('trim', $parts), fn($p) => $p !== '')));
}

function i18n_write_svg(DOMDocument $doc, string $path): void
{
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('cannot create ' . dirname($path));
    }
    $doc->save($path);
}

// ---------------------------------------------------------------------------
// Geometry
// ---------------------------------------------------------------------------

/** @return array{x:float,y:float,w:float,h:float} */
function i18n_rect_bounds(DOMElement $r): array
{
    return [
        'x' => (float) ($r->getAttribute('x') ?: 0),
        'y' => (float) ($r->getAttribute('y') ?: 0),
        'w' => (float) ($r->getAttribute('width') ?: 0),
        'h' => (float) ($r->getAttribute('height') ?: 0),
    ];
}

/** All <rect> elements with non-degenerate size. @return DOMElement[] */
function i18n_rects(DOMDocument $doc): array
{
    $out = [];
    foreach ((new DOMXPath($doc))->query('//*[local-name()="rect"]') as $r) {
        $b = i18n_rect_bounds($r);
        if ($b['w'] > 0 && $b['h'] > 0) {
            $out[] = $r;
        }
    }
    return $out;
}

/** @return array{x:float,y:float,w:float,h:float} canvas from width/height or viewBox */
function i18n_canvas(DOMDocument $doc): array
{
    $svg = $doc->documentElement;
    $w = (float) $svg->getAttribute('width');
    $h = (float) $svg->getAttribute('height');
    if ($w <= 0 || $h <= 0) {
        $vb = preg_split('/[\s,]+/', trim($svg->getAttribute('viewBox')));
        if (count($vb) === 4) {
            return ['x' => (float) $vb[0], 'y' => (float) $vb[1], 'w' => (float) $vb[2], 'h' => (float) $vb[3]];
        }
    }
    return ['x' => 0.0, 'y' => 0.0, 'w' => $w, 'h' => $h];
}

/** Attribute on the node, else on the nearest ancestor that sets it. */
function i18n_inherited(DOMElement $t, string $name): string
{
    for ($n = $t; $n instanceof DOMElement; $n = $n->parentNode) {
        if ($n->hasAttribute($name)) {
            return $n->getAttribute($name);
        }
    }
    return '';
}

function i18n_font_size(DOMElement $t): float
{
    $fs = i18n_inherited($t, 'font-size');
    return $fs === '' ? 16.0 : (float) rtrim($fs, 'px');
}

function i18n_is_bold(DOMElement $t): bool
{
    return FontMetrics::isBold(i18n_inherited($t, 'font-weight'));
}

/**
 * Rendered advance width of a node's current text, honouring font-size and
 * font-weight.  tspan children are summed; the tspan dx/dy offsets do not
 * affect the total width.
 */
function i18n_node_width(DOMElement $t): float
{
    $fs = i18n_font_size($t);
    $bold = i18n_is_bold($t);
    if (!$t->getElementsByTagName('tspan')->length) {
        return FontMetrics::width($t->textContent, $fs, $bold);
    }
    $lines = [];
    foreach ($t->childNodes as $c) {
        if ($c->nodeType === XML_ELEMENT_NODE || $c->nodeType === XML_TEXT_NODE) {
            $lines[] = $c->textContent;
        }
    }
    return max(array_map(fn($l) => FontMetrics::width($l, $fs, $bold), $lines ?: ['']));
}

/**
 * Horizontal extent of a text run: [left, right].
 *
 * Under `direction: rtl` the SVG spec resolves text-anchor against the inline
 * base direction, so "start" means the right-hand edge and "end" the left.
 * That is measured behaviour, not a guess — see calibrate.php --rtl.
 */
function i18n_text_extent(DOMElement $t, float $width): array
{
    $x = (float) ($t->getAttribute('x') ?: 0);
    return match (i18n_effective_anchor($t)) {
        'middle' => [$x - $width / 2, $x + $width / 2],
        'end'    => [$x - $width, $x],
        default  => [$x, $x + $width],
    };
}

/**
 * The machine-translation notice as plain prose, for the SVG <desc>.
 *
 * generate.php writes this into the diagram and check.php looks for it there,
 * so both must derive it the same way — they drifted apart once already, and
 * the checker caught it, which is the only reason it is now one function.
 */
function i18n_notice_plain(?string $notice): string
{
    if ($notice === null) {
        return '';
    }
    // Links become their label, and the target is dropped rather than kept as
    // a bare URL.  Two reasons: a <desc> is plain text, so a Markdown link is
    // unclickable syntax there ("[English](../en/README.md)" went into every
    // translated diagram verbatim); and the target is written for
    // the README's directory, so from docs/i18n/<lang>/images/ it resolves one
    // level short.  The label survives, which is the part that carries meaning.
    $t = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $notice);
    // then leading block markers, emphasis and code ticks, then whitespace
    return trim(preg_replace(
        '/\s+/u',
        ' ',
        preg_replace('/\*\*|__|`|\*/', '', preg_replace('/^[\s>*_#-]+/u', '', (string) $t))
    ));
}

/**
 * Vertical ink band of a text run, [$top, $bottom], in user units.
 *
 * An estimate like FontMetrics: ascender to descender, widened by the extra
 * lines a wrapped run occupies.  Used to decide whether two runs are stacked
 * on top of each other.
 */
function i18n_text_band(DOMElement $t): array
{
    return i18n_band((float) ($t->getAttribute('y') ?: 0), i18n_font_size($t), i18n_line_count($t));
}

/** How many lines a run occupies; wrapping splits it into one <tspan> per line. */
function i18n_line_count(DOMElement $t): int
{
    return max(1, $t->getElementsByTagName('tspan')->length);
}

/** @return array{0:float,1:float} ink band [$top, $bottom] for a baseline at $y. */
function i18n_band(float $y, float $fs, int $lines = 1): array
{
    return [$y - $fs * I18N_BAND_ASCENT, $y + ($lines - 1) * $fs * I18N_LINE_HEIGHT + $fs * I18N_BAND_DESCENT];
}

/**
 * The same band with the *measured* ink extents in place of the assumed ones.
 *
 * Only the outermost baselines are corrected: a wrapped run's interior lines
 * are edges of nothing, exactly as in i18n_band().
 *
 * A run takes the tallest script anywhere in it, because ink is a union — a
 * Latin identifier quoted inside Arabic prose still has Arabic ascenders
 * straddling it.  Correcting per character would need per-character positions,
 * which a <text> run does not carry.
 *
 * check.php reads this for its near-miss warning only.  It must not drive the
 * overlap FAIL, and the reason is in the I18N_INK_DEFAULT block: the numbers
 * come from one font stack on one machine, which is not a basis for failing
 * somebody else's translation.
 */
function i18n_ink_band(DOMElement $t): array
{
    [$above, $below] = i18n_ink_extents(i18n_flat_text($t));
    $fs = i18n_font_size($t);
    $y = (float) ($t->getAttribute('y') ?: 0);
    return [$y - $fs * $above,
            $y + (i18n_line_count($t) - 1) * $fs * I18N_LINE_HEIGHT + $fs * $below];
}

/** @return array{0:float,1:float} measured ink reach above / below the baseline, em. */
function i18n_ink_extents(string $text): array
{
    [$above, $below] = I18N_INK_DEFAULT;
    foreach (FontMetrics::codepoints($text) as $cp) {
        foreach (I18N_INK as [$lo, $hi, $a, $b]) {
            if ($cp >= $lo && $cp <= $hi) {
                $above = max($above, $a);
                $below = max($below, $b);
                break;
            }
        }
    }
    return [$above, $below];
}

/**
 * True if the string contains a character of strong right-to-left direction.
 *
 * A run of nothing but Latin letters, digits and neutrals (`(`, `)`, `\`, `&`)
 * has no strong character at all, so the bidi algorithm resolves its *edge*
 * neutrals against the paragraph direction.  Put such a run in an rtl document
 * and `serve()` renders as `()serve`: the parentheses are neutral, so they are
 * moved to the left edge of an rtl line.  Measured in `ar` — 20 of the 197 runs
 * were reordered this way, all of them `copy` keys.
 *
 * generate.php reads this to decide which runs need `direction="ltr"`;
 * check.php reads it to verify they all got it.  Both must use this one
 * predicate, or the two will disagree about which runs are exempt.
 */
function i18n_has_rtl(string $text): bool
{
    return (bool) preg_match(
        '/[\p{Arabic}\p{Hebrew}\p{Syriac}\p{Thaana}\p{NKo}\p{Samaritan}\p{Mandaic}\x{200F}\x{061C}]/u',
        $text
    );
}

/**
 * The anchor a run is *geometrically* laid out with, after resolving rtl.
 *
 * Under `direction: rtl` the SVG spec resolves text-anchor against the inline
 * base direction, so the meanings of start and end trade places: "start" puts
 * the run to the LEFT of x and "end" to the right.  Measured, not assumed —
 * `calibrate.php --rtl` renders the same run three ways in both directions.
 *
 * Every caller that asks "does this fit / which way does it grow" must use this
 * rather than the raw attribute, or it will reason about the wrong side of x.
 * "end" here always means the run lies to the left of x.
 *
 * A run carrying its own `direction="ltr"` is exempt: its inline base direction
 * is ltr, so start and end keep their ltr meanings and no swap applies to it.
 */
function i18n_effective_anchor(DOMElement $t): string
{
    $anchor = $t->getAttribute('text-anchor') ?: 'start';
    if (($t->getAttribute('direction') ?: i18n_inherited($t, 'direction')) !== 'rtl') {
        return $anchor;
    }
    return match ($anchor) {
        'start' => 'end',
        'end'   => 'start',
        default => $anchor,
    };
}

/**
 * The box a text run is laid out into: the smallest <rect> whose area contains
 * the run's anchor point.  Falls back to the canvas.
 *
 * Choosing the container by *anchor point* (not by "smallest rect containing the
 * whole run") is deliberate: an overflowing run is by definition not contained
 * by its own box, so containment cannot be the test that finds it.
 *
 * @return array{rect:?DOMElement,bounds:array,label:string}
 */
function i18n_container(DOMDocument $doc, DOMElement $t): array
{
    $x = (float) ($t->getAttribute('x') ?: 0);
    $y = (float) ($t->getAttribute('y') ?: 0);
    $best = null;
    $bestArea = INF;
    foreach (i18n_rects($doc) as $r) {
        $b = i18n_rect_bounds($r);
        if ($x < $b['x'] || $x > $b['x'] + $b['w'] || $y < $b['y'] || $y > $b['y'] + $b['h']) {
            continue;
        }
        $area = $b['w'] * $b['h'];
        if ($area < $bestArea) {
            $bestArea = $area;
            $best = ['rect' => $r, 'bounds' => $b, 'label' => 'rect'];
        }
    }
    if ($best === null) {
        return ['rect' => null, 'bounds' => i18n_canvas($doc), 'label' => 'canvas'];
    }
    return $best;
}

// ---------------------------------------------------------------------------
// Classification manifest
// ---------------------------------------------------------------------------

function i18n_manifest_path(): string
{
    return I18N_DIR . '/classify.json';
}

/** @return array<string,mixed> */
function i18n_load_manifest(): array
{
    $p = i18n_manifest_path();
    if (!is_file($p)) {
        throw new RuntimeException("missing $p — run: php tools/i18n/extract.php");
    }
    $m = json_decode((string) file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
    return $m;
}

function i18n_template_path(string $doc): string
{
    return I18N_DIR . "/templates/$doc.svg";
}

function i18n_glossary_path(string $lang): string
{
    return i18n_input_root() . "/glossary/$lang.json";
}

/** Every key in a document, in manifest order. @return array<string,array> */
function i18n_doc_keys(array $manifest, string $doc): array
{
    $out = [];
    foreach ($manifest['docs'][$doc]['nodes'] as $n) {
        $out[$n['key']] = $n;
    }
    return $out;
}
