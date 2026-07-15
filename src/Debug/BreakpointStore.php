<?php

declare(strict_types=1);

namespace Porta\Debug;

/**
 * Line breakpoints, keyed by absolute file path with 0-based lines. Each
 * breakpoint optionally carries a condition and/or a hit-count condition
 * (both DAP pass-through strings). Owned by the debug controller (not the
 * buffers), so breakpoints survive closing and reopening a file. Persisted
 * across restarts via {@see DebugStatePersistence} (`.porta/debug.json`).
 */
final class BreakpointStore
{
    /** @var array<string, array<int, array{condition: ?string, hitCondition: ?string}>> path => line => breakpoint */
    private array $breakpoints = [];

    /** Toggle a breakpoint; returns whether one is now set on the line. A re-toggle clears conditions too. */
    public function toggle(string $path, int $line): bool
    {
        if (isset($this->breakpoints[$path][$line])) {
            unset($this->breakpoints[$path][$line]);
            if ([] === $this->breakpoints[$path]) {
                unset($this->breakpoints[$path]);
            }

            return false;
        }
        $this->breakpoints[$path][$line] = ['condition' => null, 'hitCondition' => null];

        return true;
    }

    /** Set (or clear, with nulls) a line's conditions — creating the breakpoint when the line has none. */
    public function setCondition(string $path, int $line, ?string $condition, ?string $hitCondition): void
    {
        $this->breakpoints[$path][$line] = ['condition' => $condition, 'hitCondition' => $hitCondition];
    }

    /** @return array{condition: ?string, hitCondition: ?string}|null null when the line has no breakpoint */
    public function conditionAt(string $path, int $line): ?array
    {
        return $this->breakpoints[$path][$line] ?? null;
    }

    /** @return list<int> the file's breakpoint lines, ascending */
    public function linesFor(string $path): array
    {
        $lines = array_keys($this->breakpoints[$path] ?? []);
        sort($lines);

        return $lines;
    }

    /** @return list<int> the file's lines whose breakpoint carries any condition, ascending */
    public function conditionalLinesFor(string $path): array
    {
        $lines = array_keys(array_filter(
            $this->breakpoints[$path] ?? [],
            static fn (array $breakpoint): bool => null !== $breakpoint['condition'] || null !== $breakpoint['hitCondition'],
        ));
        sort($lines);

        return $lines;
    }

    /** @return list<array{line: int, condition: ?string, hitCondition: ?string}> the file's breakpoints, ascending — the DAP payload shape */
    public function breakpointsFor(string $path): array
    {
        $breakpoints = [];
        foreach ($this->linesFor($path) as $line) {
            $breakpoints[] = ['line' => $line, ...$this->breakpoints[$path][$line]];
        }

        return $breakpoints;
    }

    /** @return array<string, list<array{line: int, condition: ?string, hitCondition: ?string}>> every file's breakpoints */
    public function all(): array
    {
        $all = [];
        foreach (array_keys($this->breakpoints) as $path) {
            $all[$path] = $this->breakpointsFor($path);
        }

        return $all;
    }

    public function clear(): void
    {
        $this->breakpoints = [];
    }
}
