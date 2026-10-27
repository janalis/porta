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

        // Unicode (`u`): ignoring case folds É to é as well as E to e, and
        // \w and \b know an accented letter — for a query that is UTF-8 at all.
        return '/'.$body.'/'.(CaseMode::Sensitive === $case ? '' : 'i').(\mb_check_encoding($query, encoding: 'UTF-8') ? 'u' : '');
    }

    /**
     * $pattern made fit for $subject: as built, or — for a subject that is not
     * UTF-8, which a Unicode pattern refuses outright — matched byte for byte,
     * so a Latin-1 line is still searched, its case folded only for ASCII.
     */
    public static function fit(string $pattern, string $subject): string
    {
        return \str_ends_with($pattern, 'u') && !\mb_check_encoding($subject, encoding: 'UTF-8') ? \substr($pattern, offset: 0, length: -1) : $pattern;
    }

    /**
     * A regex query with its bare `/` escaped, since `/` delimits the pattern:
     * `src/Foo` is a slash to the user, not the end of the regex. An escape
     * pair is kept whole, so `\/` stays one escaped slash, and `\\/` (a
     * backslash, then a slash) gets its slash escaped. Inside `\Q…\E`, where
     * every character is literal and `\/` would be two, the quote is closed
     * around the slash: `\Qa/b\E` is `\Qa\E\/\Qb\E`.
     */
    private static function delimited(string $query): string
    {
        return (string) \preg_replace_callback(
            '~\\\\Q.*?(?:\\\\E|$)|\\\\.|/~s',
            static fn (array $m): string => match (true) {
                '/' === $m[0] => '\\/',
                \str_starts_with($m[0], '\\Q') => \str_replace(search: '/', replace: '\\E\\/\\Q', subject: $m[0]),
                default => $m[0],
            },
            $query,
        );
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
