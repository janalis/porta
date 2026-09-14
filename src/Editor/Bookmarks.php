<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;
use Porta\Support\ProjectPaths;

/**
 * Bookmarked lines, keyed by absolute file path with 0-based lines — the
 * places you mean to come back to.
 *
 * Owned here rather than on the buffer, for {@see \Porta\Debug\BreakpointStore}'s
 * reason: a bookmark has to survive closing and reopening the file, and closing
 * a buffer must not quietly discard it. Persisted across restarts by
 * {@see \Porta\History\BookmarkStore} (`.porta/bookmarks.json`).
 */
#[AsIdeService]
final class Bookmarks
{
    /** @var array<string, array<int, true>> path => line => set */
    private array $marks = [];

    /** Toggle the line; returns whether it is bookmarked now. */
    public function toggle(string $path, int $line): bool
    {
        if (($this->marks[$path][$line] ?? null) !== null) {
            unset($this->marks[$path][$line]);
            if ([] === $this->marks[$path]) {
                unset($this->marks[$path]);
            }

            return false;
        }
        $this->marks[$path][$line] = true;

        return true;
    }

    /** @return list<int> the file's bookmarked lines, ascending */
    public function linesFor(string $path): array
    {
        $lines = \array_keys($this->marks[$path] ?? []);
        \sort($lines);

        return $lines;
    }

    /** @return array<string, list<int>> every bookmark, path-keyed, paths in insertion order */
    public function all(): array
    {
        $out = [];
        foreach (\array_keys($this->marks) as $path) {
            $out[$path] = $this->linesFor($path);
        }

        return $out;
    }

    /**
     * Follow a renamed file, keeping its marks. Pure: the caller hands over
     * canonical paths, because by the time this runs `$from` is gone from
     * disk and cannot be resolved.
     */
    public function rename(string $from, string $to): void
    {
        foreach (\array_keys($this->marks) as $marked) {
            $moved = ProjectPaths::moved($marked, $from, $to);
            if (null === $moved || $moved === $marked) {
                continue;
            }
            $this->marks[$moved] = $this->marks[$marked];
            unset($this->marks[$marked]);
        }
    }

    /**
     * Drop every mark on a deleted file — or on every file under a deleted
     * directory, which is why this walks the keys instead of unsetting one.
     *
     * {@see \Porta\History\BookmarkStore::load()} already skips a path whose
     * file has vanished, so this changes nothing about the NEXT session. It is
     * about this one: a bookmark on a file you just deleted is still in the
     * list, still in the gutter of a buffer that reopens, and Alt+2 still tries
     * to jump to it.
     */
    public function forget(string $path): void
    {
        foreach (\array_keys($this->marks) as $marked) {
            if (!ProjectPaths::isUnder($marked, $path)) {
                continue;
            }
            unset($this->marks[$marked]);
        }
    }

    /** @param array<string, list<int>> $marks */
    public function replaceAll(array $marks): void
    {
        $this->marks = [];
        foreach ($marks as $path => $lines) {
            foreach ($lines as $line) {
                $this->marks[$path][$line] = true;
            }
        }
    }
}
