<?php

declare(strict_types=1);

namespace Porta\Support;

use Symfony\Component\Finder\Iterator\VcsIgnoredFilterIterator;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Prunes the directories git ignores from a Finder walk. Finder's
 * `ignoreVCSIgnored()` turns an ignored tree's files away one at a time and
 * still walks the whole tree to do it: in this repository, 47,000 files of
 * coverage report and test cache, 2.6 s inside a single slice of the
 * language servers' file sweep, on the UI loop.
 *
 * Asked of Finder's own matcher, so the rules are the ones `ignoreVCSIgnored()`
 * reads — every `.gitignore` from the repository root down, cached across the
 * walk. Nothing under a directory git ignores can be re-included, so a pruned
 * tree never held a file the filter would have kept.
 */
final class GitIgnored
{
    /**
     * For `Finder::filter($closure, prune: true)`, beside `ignoreVCSIgnored(true)`:
     * false for a directory git ignores. A file is left to the filter.
     *
     * @return \Closure(SplFileInfo): bool
     */
    public static function pruner(string $root): \Closure
    {
        // The matcher judges whatever its iterator holds: one entry, swapped
        // in per question, and kept when rewinding lands on it.
        $slot = new \ArrayIterator();
        $matcher = new VcsIgnoredFilterIterator($slot, $root);

        return static function (SplFileInfo $entry) use ($slot, $matcher): bool {
            if (!$entry->isDir()) {
                return true;
            }
            $slot->offsetSet(0, $entry);
            $matcher->rewind();

            return $matcher->valid();
        };
    }
}
