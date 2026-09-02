<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;

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
        $lines = array_keys($this->marks[$path] ?? []);
        sort($lines);

        return $lines;
    }

    /** @return array<string, list<int>> every bookmark, path-keyed, paths in insertion order */
    public function all(): array
    {
        $out = [];
        foreach (array_keys($this->marks) as $path) {
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
        if (!\array_key_exists($from, $this->marks)) {
            return;
        }
        $this->marks[$to] = $this->marks[$from];
        unset($this->marks[$from]);
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
