<?php

declare(strict_types=1);

namespace Porta\Search;

/**
 * The one place search queries become PCRE patterns, so the in-file find bar
 * and the project-wide search stay semantically identical.
 */
final class SearchPattern
{
    public static function build(string $query, MatchMode $match, WordMode $word, CaseMode $case): string
    {
        $body = MatchMode::Regex === $match ? self::delimited($query) : \preg_quote($query, delimiter: '/');
        if (WordMode::WholeWord === $word) {
            $body = '\b(?:'.$body.')\b';
        }

        return '/'.$body.'/'.(CaseMode::Sensitive === $case ? '' : 'i');
    }

    /**
     * A regex query with its bare `/` escaped, since `/` delimits the pattern:
     * `src/Foo` is a slash to the user, not the end of the regex. An escape
     * pair is kept whole, so `\/` stays one escaped slash, and `\\/` (a
     * backslash, then a slash) gets its slash escaped.
     */
    private static function delimited(string $query): string
    {
        return (string) \preg_replace_callback('~\\\\.|/~s', static fn (array $m): string => '/' === $m[0] ? '\\/' : $m[0], $query);
    }

    /**
     * Whether a built pattern compiles, swallowing the warning a malformed one
     * raises: the find bar and Find in Files say "bad regex" rather than
     * searching for nothing, and the project search skips the scan.
     */
    public static function compiles(string $pattern): bool
    {
        \set_error_handler(static fn (): bool => true);
        $ok = \preg_match($pattern, subject: '');
        \restore_error_handler();

        return false !== $ok;
    }
}
