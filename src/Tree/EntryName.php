<?php

declare(strict_types=1);

namespace Porta\Tree;

/**
 * Whether a name typed into the tree's prompts names an entry where it was
 * typed, rather than somewhere else.
 *
 * A rename takes one plain name: `../x` or `sub/x` would MOVE the file, out of
 * its folder, under the guise of renaming it. New File and New Folder may name
 * a path below the folder (`sub/x.php` makes `sub/` too), never one above it.
 * `.` and `..` are no entry's own name, and a NUL ends the path at the system
 * call, so it would quietly name a different file.
 */
final class EntryName
{
    /** One name, as a rename takes it. */
    public static function isPlain(string $name): bool
    {
        return !\str_contains($name, '/') && self::isBelow($name);
    }

    /** A path that stays below the folder it is typed in. */
    public static function isBelow(string $name): bool
    {
        if (\str_contains($name, "\0")) {
            return false;
        }
        $segments = \explode('/', $name);

        return [] === \array_intersect($segments, ['.', '..']);
    }
}
