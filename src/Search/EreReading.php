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
 * `\<`, `\Q`, a backreference — and `\w \W \s \S \b \B`, which POSIX
 * leaves undefined. glibc's and musl's regcomp read those as PCRE does on
 * ASCII; past it they part: under C.UTF-8 BSD grep finds no `\w` in `²` or
 * `Ⅰ` and no `\s` in U+0085, where the `/u` pattern does (measured
 * 2026-11-24). A POSIX class is Unicode's under /u and the locale's in grep
 * (BSD grep: no `[[:alnum:]]` in `²`), so neither a `[:class:]` nor a
 * `[.x.]` or `[=x=]` counts the same. Nor does a backslash inside brackets
 * (ERE: a backslash), a `(?` group or a `(*` verb, a lazy or possessive
 * quantifier, or a `{` that opens no strict `{n}`, `{n,}` or `{n,m}`
 * (PCRE2 10.43+ reads `{,n}` and `{ 1 }` as bounds; ERE as text).
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
            if ($inBrackets) {
                $other = '[' === $char && '' !== $next && \str_contains(':.=', $next);
                $inBrackets = ']' !== $char || $i === $opened;

                continue;
            }
            if ('[' === $char) {
                $inBrackets = true;
                $opened = $i + ('^' === $next ? 2 : 1);

                continue;
            }
            // A `{` that opens no strict `{n}`, `{n,}` or `{n,m}`: PCRE2 10.43+
            // reads `{,n}` and `{ 1 }` as bounds, ERE as text (measured).
            $other =
                ('(' === $char && ('?' === $next || '*' === $next))
                || ('{' === $char && 1 !== \preg_match('/\G\{\d+(?:,\d*)?\}/', $regex, offset: $i))
                || (\str_contains('*+?}', $char) && ('?' === $next || '+' === $next));
        }

        return $other;
    }
}
