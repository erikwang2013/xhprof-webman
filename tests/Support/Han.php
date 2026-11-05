<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Support;

/**
 * Han ideograph detection for the translation guards.
 *
 * Uses explicit CJK ideograph ranges instead of PCRE's `\p{Han}`: on the
 * GitHub release runner (PHP 8.3.36) `\p{Han}` matches U+00B7 MIDDLE DOT,
 * which made the English page's " · " separators look like untranslated
 * Chinese (en footer.credit / the runs status bar both failed). The ranges
 * below cover CJK Unified Ideographs (Ext A/B–I, compatibility ideographs,
 * Ext G/H) and never match punctuation.
 */
final class Han
{
    public const REGEX = '/[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}\x{20000}-\x{2FA1F}\x{30000}-\x{323AF}]/u';

    public static function matches(string $text): bool
    {
        return preg_match(self::REGEX, $text) === 1;
    }
}
