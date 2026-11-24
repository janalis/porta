<?php

declare(strict_types=1);

namespace Porta\Search;

/**
 * A search's pattern run on one line, or one text, at a time — fit to each
 * (SearchPattern::fit() drops /u on a text that is not UTF-8). A pattern
 * that then does not compile matches nothing there: no limit was hit,
 * nothing was given up. Whether it compiles is asked once a pattern, not
 * once a line, so no error handler wraps each match. One instance a search.
 */
final class LineMatch
{
    /** @var array<string, bool> by pattern as fit() made it, whether it compiles */
    private array $compiles = [];

    /** Whether $pattern matches $line; null when the match could not finish (a backtrack, recursion or JIT stack limit). */
    public function in(string $pattern, string $line): ?bool
    {
        $fitted = SearchPattern::fit($pattern, $line);
        $matched = $this->compiles($fitted) ? \preg_match($fitted, $line) : 0;

        // A pattern that compiles fails on nothing but a limit.
        return false === $matched ? null : 1 === $matched;
    }

    /**
     * Every match of $pattern in $text with its byte offset; null when the
     * match could not finish.
     *
     * @return list<array{string, int}>|null
     */
    public function all(string $pattern, string $text): ?array
    {
        $fitted = SearchPattern::fit($pattern, $text);
        $matches = [];
        $count = $this->compiles($fitted) ? \preg_match_all($fitted, $text, $matches, \PREG_OFFSET_CAPTURE) : 0;

        // @mago-expect analysis:docblock-type-mismatch -- PREG_OFFSET_CAPTURE makes each match [text, offset]; mago types it as a string
        /** @var list<array{string, int}> $all */
        $all = $matches[0] ?? [];

        return false === $count ? null : $all;
    }

    private function compiles(string $fitted): bool
    {
        return $this->compiles[$fitted] ??= SearchPattern::compiles($fitted);
    }
}
