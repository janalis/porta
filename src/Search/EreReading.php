<?php

declare(strict_types=1);

namespace Porta\Search;

/**
 * Whether grep -E reads a regex as PCRE does — what a grep without -P may be
 * handed. Under -E, GNU grep reads POSIX ERE, where `\d` is a `d`: `\d+`
 * found "ddd", and the list looked whole.
 *
 * An allow-list, since a deny-list missed one escape after another: ERE
 * reads the same only an escaped metacharacter, and `\w \W \s \S \b \B`,
 * outside brackets — measured against PCRE with glibc's and musl's regcomp
 * (GNU grep's, BusyBox on Alpine's) and BSD grep, 2026-11-24. Anything else
 * escaped is not counted the same: `\d` (glibc's ERE reads a `d`), `\t`,
 * `\<`, `\Q`, a backreference. Nor is a backslash inside brackets (ERE: a
 * backslash), a `(?` group or a `(*` verb, a lazy or possessive quantifier,
 * or a `{,n}` bound. A `[:class:]`, `[.x.]` or `[=x=]` inside brackets is
 * read whole, when its `:]` is the first `]` after it, as PCRE reads it;
 * else its `[` is a member.
 */
final class EreReading
{
    /** What may follow a backslash outside brackets and be read the same. */
    private const string SAME_ESCAPES = '.[]{}()*+?|^$\\/wWsSbB';

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
            $close = $inBrackets && '[' === $char && '' !== $next && \str_contains(':.=', $next) ? \strpos($regex, $next.']', $i + 2) : false;
            if (false !== $close && \strpos($regex, needle: ']', offset: $i + 2) === ($close + 1)) {
                // `[:digit:]`: its `]` closes the class, not the brackets
                $i = $close + 1;

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
