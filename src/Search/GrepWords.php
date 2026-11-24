<?php

declare(strict_types=1);

namespace Porta\Search;

/**
 * Where grep -w, run in C, prints every line the pattern's whole word
 * matches — a prefilter that drops no hit. Without one, a Whole Word search
 * for a short word has grep print most of the project for the filter.
 *
 * grep's word is not the pattern's: `$foo` is a whole word to grep -w, never
 * to `\b(?:\$foo)\b`, and in C.UTF-8 GNU grep 3.11 -w missed 4446 lines
 * `\bfoo\b` /u matches, a code point beside `foo` (combining marks glibc
 * counts as letters). In C, a word is ASCII's word, as /u's is on ASCII,
 * and every other byte is no word: BSD and GNU grep -w missed none of those
 * lines (every code point swept, 2026-11-24). So: an ASCII literal with a
 * word character at each end. Under -i, C folds ASCII only, where /iu folds
 * `ſ` to `s` and `K` to `k`: not for an `s` or a `k`.
 */
final class GrepWords
{
    /** Whether grep -w in C holds every line $query's whole-word pattern matches. */
    public static function holdThePatterns(string $query, SearchOptions $options): bool
    {
        return $options->wholeWord && !$options->regex && 1 === \preg_match('/^\w(?:[\x20-\x7E]*\w)?$/D', $query) && ($options->caseSensitive || 1 !== \preg_match('/[sk]/i', $query));
    }
}
