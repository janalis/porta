<?php

declare(strict_types=1);

namespace Porta\Search;

/**
 * Whether grep -E reads a regex as PCRE does — what a grep without -P may be
 * handed. Under -E, GNU grep reads POSIX ERE, where `\d` is a `d`: `\d+`
 * found "ddd", and the list looked whole.
 *
 * An allow-list, since a deny-list missed one escape after another: ERE
 * reads the same only an escaped metacharacter outside brackets. Anything
 * else escaped is not counted the same: `\d` (glibc's ERE reads a `d`), `\t`,
 * `\<`, `\Q`, a backreference — and `\w \W \s \S \b \B`. glibc and musl
 * read those as PCRE does on ASCII, but not beyond (measured 2026-11-24: BSD
 * grep under C.UTF-8 finds no `\w` in a decomposed `é`, `²` or `Ⅰ`, no `\s`
 * in U+0085, where the `/u` pattern does), and POSIX leaves them undefined.
 * Nor is a backslash inside brackets (ERE: a backslash), a `(?` group or a
 * `(*` verb, a lazy or possessive quantifier, or a `{,n}` bound. Inside
 * brackets a `[:class:]`, `[.x.]` or `[=x=]` is read whole; a `[:` with no
 * `:]` before the next `]` is not counted the same (BSD grep refuses it).
 */
final class EreReading
{
    /** What may follow a backslash outside brackets and be read the same. */
    private const string SAME_ESCAPES = '.[]{}()*+?|^$\\/';

    /** Whether grep -E might read $regex otherwise than PCRE does. */
    public static function differs(string $regex): bool
    {
        $other = false;
        $inBrackets = false;
        $opened = 0; // where the open brackets' first member is: a `]` there is a member
        $length = \strlen($regex);
        for ($i = 0; $i < $length && !$other; ++$i) {
            $char = $regex[$i];
            $next = $regex[$i + 1] ?? '';
            if ('\\' === $char) {
                $other = $inBrackets || '' === $next || !\str_contains(self::SAME_ESCAPES, $next);
                ++$i; // the escaped character is not read again

                continue;
            }
            if ($inBrackets && '[' === $char && '' !== $next && \str_contains(':.=', $next)) {
                // `[:digit:]`: its `]` closes the class, not the brackets
                $close = \strpos($regex, $next.']', $i + 2);
                $other = false === $close || \strpos($regex, needle: ']', offset: $i + 2) !== ($close + 1);
                $i = (int) $close + 1;

                continue;
            }
            if ($inBrackets) {
                $inBrackets = ']' !== $char || $i === $opened;

                continue;
            }
            if ('[' === $char) {
                $inBrackets = true;
                $opened = $i + ('^' === $next ? 2 : 1);

                continue;
            }
            $other = ('(' === $char && ('?' === $next || '*' === $next)) || ('{' === $char && ',' === $next) || (\str_contains('*+?}', $char) && ('?' === $next || '+' === $next));
        }

        return $other;
    }
}
