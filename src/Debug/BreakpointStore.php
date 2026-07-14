<?php

declare(strict_types=1);

namespace Porta\Debug;

/**
 * Line breakpoints, keyed by absolute file path with 0-based lines. Owned by
 * the debug controller (not the buffers), so breakpoints survive closing and
 * reopening a file. In-memory only — not persisted across IDE restarts.
 */
final class BreakpointStore
{
    /** @var array<string, array<int, true>> path => set of 0-based lines */
    private array $breakpoints = [];

    /** Toggle a breakpoint; returns whether one is now set on the line. */
    public function toggle(string $path, int $line): bool
    {
        if (isset($this->breakpoints[$path][$line])) {
            unset($this->breakpoints[$path][$line]);
            if ([] === $this->breakpoints[$path]) {
                unset($this->breakpoints[$path]);
            }

            return false;
        }
        $this->breakpoints[$path][$line] = true;

        return true;
    }

    /** @return list<int> the file's breakpoint lines, ascending */
    public function linesFor(string $path): array
    {
        $lines = array_keys($this->breakpoints[$path] ?? []);
        sort($lines);

        return $lines;
    }

    /** @return array<string, list<int>> every file's breakpoint lines */
    public function all(): array
    {
        $all = [];
        foreach (array_keys($this->breakpoints) as $path) {
            $all[$path] = $this->linesFor($path);
        }

        return $all;
    }

    public function clear(): void
    {
        $this->breakpoints = [];
    }
}
