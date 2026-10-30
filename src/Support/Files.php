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
     * Whether $path, or a folder between $root and it, is a symbolic link.
     *
     * What Porta keeps of a project, its `.porta/`, comes with a clone like
     * the rest of it, and a link there aims Porta's own writes anywhere:
     * `.porta/session.json -> ~/.bashrc` would have the session written over
     * the user's shell, on a quit they never thought of as saving. Porta
     * makes no link there, so a write of its own that would pass one is not
     * made. $root itself, and what is above it, are the user's to have linked.
     */
    public static function throughLink(string $root, string $path): bool
    {
        for ($at = $path; \str_starts_with($at, $root.'/'); $at = \dirname($at)) {
            if (\is_link($at)) {
                return true;
            }
        }

        return false;
    }

    /**
     * $path with its `.` and `..` resolved, symlinks too — or null when
     * nothing is there.
     *
     * `realpath()` where it can, and it cannot inside a phar: it answers false
     * for every `phar://` path, so a caller that trusts it alone treats each
     * page of the bundled docs as missing — the help browser refused every
     * link in the phar for exactly that reason. A path behind a stream
     * wrapper is resolved by its segments instead (a wrapper has no symlinks
     * to follow), and must still exist.
     */
    public static function resolve(string $path): ?string
    {
        $real = \realpath($path);
        $wrapped = [];

        return match (true) {
            false !== $real => $real,
            // Absolute behind the wrapper, as every phar path Porta builds from
            // __DIR__ is: a relative one's leading `..` has nothing to cancel.
            1 !== \preg_match('~^([a-z][a-z0-9+.-]*://)(/.*)$~i', $path, $wrapped) => null,
            default => self::resolveWrapped($wrapped[1], $wrapped[2]),
        };
    }

    /** $path behind the $wrapper prefix, resolved by its segments; null when nothing is there. */
    private static function resolveWrapped(string $wrapper, string $path): ?string
    {
        /** @var list<string> $segments */
        $segments = [];
        foreach (\explode('/', $path) as $segment) {
            if ('..' === $segment) {
                \array_pop($segments);
            }
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                continue;
            }
            $segments[] = $segment;
        }
        $resolved = $wrapper.'/'.\implode('/', $segments);

        return \file_exists($resolved) ? $resolved : null;
    }

    /** {@see resolve()}, keeping $path as given when nothing is there to resolve. */
    public static function canonical(string $path): string
    {
        return self::resolve($path) ?? $path;
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
