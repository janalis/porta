<?php

declare(strict_types=1);

namespace Porta\Tree;

/**
 * A path in $dir for a copy of $source that nothing occupies — `name (2).ext`,
 * then `(3)`, the way Finder names a kept copy.
 *
 * The number goes before the extension so the copy still opens as what it
 * is: `Kernel (2).php` stays PHP. A folder, and a dotfile whose only dot
 * starts it, have no extension to protect, so the whole name is the stem:
 * `src (2)`, `.env (2)`. Which of those the copy is depends on the SOURCE —
 * the entry already holding the name may be of the other kind.
 */
final class FreeName
{
    public static function for(string $source, string $dir): string
    {
        $name = \basename($source);
        if (!self::taken($dir.'/'.$name)) {
            return $dir.'/'.$name;
        }
        $dot = \strrpos($name, needle: '.');
        $split = false !== $dot && 0 !== $dot && !\is_dir($source) ? $dot : \strlen($name);
        $stem = \substr($name, offset: 0, length: $split);
        $extension = \substr($name, $split);
        $n = 2;
        $candidate = \sprintf('%s/%s (%d)%s', $dir, $stem, $n, $extension);
        while (self::taken($candidate)) {
            $candidate = \sprintf('%s/%s (%d)%s', $dir, $stem, ++$n, $extension);
        }

        return $candidate;
    }

    /** A dangling symlink is not there to file_exists() and still holds its name. */
    private static function taken(string $path): bool
    {
        return \file_exists($path) || \is_link($path);
    }
}
