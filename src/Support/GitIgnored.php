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
        $ignored = static function (\SplFileInfo $entry) use ($slot, $matcher): bool {
            $slot->offsetSet(0, $entry);
            try {
                $matcher->rewind();

                return !$matcher->valid();
            } catch (\RuntimeException) {
                return false; // a .gitignore that cannot be read rules out nothing
            }
        };
        // A project inside a repository that ignores it — a scratch folder in
        // a home directory kept in git with `*` ignored: that repository's
        // rules are about its own tree, and would rule out the whole project.
        // Asked by its real path: the matcher resolves only paths BELOW the
        // root, and a /var spelling never matches rules found under /private/var.
        if ($ignored(new \SplFileInfo(Files::canonical($root)))) {
            return static fn (SplFileInfo $entry): bool => true;
        }

        return static fn (SplFileInfo $entry): bool => !$entry->isDir() || !$ignored($entry);
    }
}
