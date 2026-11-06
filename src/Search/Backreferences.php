<?php

declare(strict_types=1);

namespace Porta\Search;

/**
 * A regex replacement's references expanded from ONE match's groups, as
 * `preg_replace()` expands them — which has no way to do that for a match it
 * did not pick itself. A token-scoped replace rewrites only the occurrences
 * the scope approves, so it splices each one by hand; splicing the raw
 * replacement wrote `bar$1` into the file.
 *
 * The grammar is PCRE's replacement syntax as PHP reads it: `\n`, `$n` and
 * `${n}` for n of one or two digits, a reference past the last group (or to
 * one that did not take part) empty, and a backslash before `\` or `$` making
 * that character literal. Anything else is copied as it stands.
 */
final class Backreferences
{
    /** @param array<array-key, string> $groups the match's text, group 0 first (a named group under its name too) */
    public static function expand(string $replacement, array $groups): string
    {
        return (string) \preg_replace_callback(
            '/\\\\([\\\\$])|[\\\\$](\d{1,2})|\$\{(\d{1,2})\}/',
            static fn (array $reference): string => match (true) {
                '' !== $reference[1] => $reference[1],
                '' !== ($reference[2] ?? '') => $groups[(int) $reference[2]] ?? '',
                default => $groups[(int) ($reference[3] ?? 0)] ?? '',
            },
            $replacement,
        );
    }
}
