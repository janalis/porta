<?php

declare(strict_types=1);

namespace Porta\Todo;

// @mago-expect lint:tagged-todo -- the tags named below are this plugin's subject matter, not a note to self.
/**
 * The comment tags {@see TodoScan} greps for, as a regex alternation.
 *
 * Pure and immutable now that the tag list is a PLUGIN setting: the scan reads
 * `tags` fresh on every run, so there is nothing to keep in step and no live
 * setter to forget to call. An empty or blank list falls back to the built-in
 * TODO|FIXME rather than grepping for nothing.
 */
final class TodoTags
{
    public const string DEFAULT = 'TODO,FIXME,HACK,XXX,BUG';

    private const array FALLBACK = ['TODO', 'FIXME'];

    /** The `TAG1|TAG2|…` alternation for a comma-separated tag list. */
    public static function pattern(string $tags): string
    {
        $parts = \array_values(\array_filter(
            \array_map(trim(...), \explode(',', $tags)),
            static fn (string $tag): bool => '' !== $tag,
        ));

        return \implode('|', [] === $parts ? self::FALLBACK : $parts);
    }
}
