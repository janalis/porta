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
        $body = MatchMode::Regex === $match ? $query : preg_quote($query, delimiter: '/');
        if (WordMode::WholeWord === $word) {
            $body = '\b(?:'.$body.')\b';
        }

        return '/'.$body.'/'.(CaseMode::Sensitive === $case ? '' : 'i');
    }
}
