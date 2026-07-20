<?php

declare(strict_types=1);

namespace Porta\Search;

/**
 * The mutable set of comment tags {@see TodoScanner} greps for, as a regex
 * alternation. Seeded from the `todoTags` setting at boot and updated live when
 * the setting changes, so a re-scan (alt+8 / F5) picks up the new tags without
 * a restart. An empty/blank list falls back to the built-in TODO|FIXME.
 */
final class TodoTags
{
    private const array FALLBACK = ['TODO', 'FIXME'];

    private string $pattern;

    public function __construct(string $tags = 'TODO,FIXME,HACK,XXX,BUG')
    {
        $this->pattern = self::compile($tags);
    }

    /** Replace the tag list (comma-separated) with a fresh alternation. */
    public function set(string $tags): void
    {
        $this->pattern = self::compile($tags);
    }

    /** The `TAG1|TAG2|…` alternation to feed the search engine. */
    public function pattern(): string
    {
        return $this->pattern;
    }

    private static function compile(string $tags): string
    {
        $parts = array_values(array_filter(
            array_map(trim(...), explode(',', $tags)),
            static fn (string $tag): bool => '' !== $tag,
        ));

        return implode('|', [] === $parts ? self::FALLBACK : $parts);
    }
}
