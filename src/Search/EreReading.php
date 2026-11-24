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
 * else escaped is not counted the same: `\d` (GNU's ERE reads a `d`), `\t`,
 * `\<`, `\Q`, a backreference, and `\w \s \b` too, GNU's and BSD's own
 * extensions that a grep without -P (BusyBox on musl) may not read — POSIX
 * leaves a backslash before an ordinary character undefined. Nor is a
 * backslash inside brackets (ERE: a backslash), a `(?` group or a `(*` verb,
 * a lazy or possessive quantifier, or a `{,n}` bound. A `[:class:]`,
 * `[.x.]` or `[=x=]` inside brackets is read whole.
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
                $i = false === $close ? $length : $close + 1;

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
