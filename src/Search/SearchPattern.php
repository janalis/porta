<?php

declare(strict_types=1);

namespace Porta\Search;

/**
 * The one place search queries become PCRE patterns, so the in-file find bar
 * and the project-wide search stay semantically identical.
 */
final class SearchPattern
{
    public static function build(string $query, bool $regex, bool $wholeWord, bool $caseSensitive): string
    {
        $body = $regex ? $query : preg_quote($query, '/');
        if ($wholeWord) {
            $body = '\b(?:'.$body.')\b';
        }

        return '/'.$body.'/'.($caseSensitive ? '' : 'i');
    }
}
