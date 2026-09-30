<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * Plain-file sibling of {@see JsonFile}. One method, one idiom: the
 * read-or-empty the search readers and decoders each hand-rolled as
 * `is_file($p) ? (string) file_get_contents($p) : ''`.
 */
final class Files
{
    /**
     * The size past which a file is treated as "too large" to open as
     * editable text — shared by every caller that caps a text-file read, so
     * the cap can't silently drift between them.
     */
    public const int MAX_TEXT_BYTES = 2 * 1024 * 1024;

    /**
     * The file's bytes, or '' when it does not exist — or vanished between
     * the check and the read: file_get_contents' false collapses to '' too,
     * which is the point. Callers treating '' as "nothing there" never need
     * the distinction.
     */
    public static function read(string $path): string
    {
        return \is_file($path) ? (string) \file_get_contents($path) : '';
    }

    /**
     * The file's bytes when it exists and is writable, or null otherwise —
     * the guard every in-place-edit caller needs before reading a file it
     * intends to overwrite.
     */
    /**
     * The text a file holds, for keeping before it goes: null for a
     * directory, a missing file, or one too large to be text.
     */
    public static function textOf(string $path): ?string
    {
        return \is_file($path) && (int) \filesize($path) <= self::MAX_TEXT_BYTES ? (string) \file_get_contents($path) : null;
    }

    public static function readWritable(string $path): ?string
    {
        return \is_file($path) && \is_writable($path) ? (string) \file_get_contents($path) : null;
    }

    /**
     * Each immediate subdirectory's $name file, in name order —
     * `glob("$dir/*\/$name")` as a directory walk.
     *
     * Because `glob()` cannot see into a phar: the bundled plugins live at
     * `phar://…/plugins/*\/plugin.php` when Porta runs as one, and a glob there
     * returns nothing — so the phar silently shipped without its plugins.
     * Hidden entries are skipped, as glob's `*` skips them; a missing $dir is
     * an empty list.
     *
     * @return list<string>
     */
    public static function childFiles(string $dir, string $name): array
    {
        $entries = \is_dir($dir) ? \scandir($dir) : false;
        $files = [];
        foreach (false !== $entries ? $entries : [] as $entry) {
            $file = $dir.'/'.$entry.'/'.$name;
            if (\str_starts_with($entry, '.') || !\is_file($file)) {
                continue;
            }
            $files[] = $file;
        }

        return $files;
    }
}
