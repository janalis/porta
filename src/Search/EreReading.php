<?php

declare(strict_types=1);

namespace Porta\Search;

/**
 * Whether grep -E reads a regex as PCRE does — what a grep without -P may be
 * handed. Under -E, GNU grep reads POSIX ERE, where `\d` is a `d`: `\d+`
 * found "ddd", and the list looked whole.
 *
 * An allow-list, since a deny-list missed one escape after another: ERE
 * reads the same only an escaped metacharacter, and `\w \W \s \S \b \B`
 * outside brackets (GNU's and BSD's ERE both). Anything else escaped (`\d`,
 * `\t`, `\<`, `\Q`, a backreference…), a backslash inside brackets (ERE: a
 * backslash), a `(?` group, a lazy or possessive quantifier, or a `{,n}`
 * bound is not counted the same.
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
            if ($inBrackets) {
                $inBrackets = ']' !== $char || $i === $opened;

                continue;
            }
            if ('[' === $char) {
                $inBrackets = true;
                $opened = $i + ('^' === $next ? 2 : 1);

                continue;
            }
            $other = ('(' === $char && '?' === $next) || ('{' === $char && ',' === $next) || (\str_contains('*+?}', $char) && ('?' === $next || '+' === $next));
        }

        return $other;
    }
}
