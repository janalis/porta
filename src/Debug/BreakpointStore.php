<?php

declare(strict_types=1);

namespace Porta\Debug;

/**
 * Line breakpoints, keyed by absolute file path with 0-based lines. Each
 * breakpoint optionally carries a condition, a hit-count condition and/or a log
 * message (all DAP pass-through strings; a breakpoint with a log message is a
 * logpoint — it logs to the debug console instead of pausing). Owned by the
 * debug controller (not the buffers), so breakpoints survive closing and
 * reopening a file. Persisted across restarts via {@see DebugStatePersistence}
 * (`.porta/debug.json`).
 */
final class BreakpointStore
{
    /** @var array<string, array<int, array{condition: ?string, hitCondition: ?string, logMessage: ?string}>> path => line => breakpoint */
    private array $breakpoints = [];

    /** Toggle a breakpoint; returns whether one is now set on the line. A re-toggle clears its details too. */
    public function toggle(string $path, int $line): bool
    {
        if (($this->breakpoints[$path][$line] ?? null) !== null) {
            unset($this->breakpoints[$path][$line]);
            if ([] === $this->breakpoints[$path]) {
                unset($this->breakpoints[$path]);
            }

            return false;
        }
        $this->breakpoints[$path][$line] = ['condition' => null, 'hitCondition' => null, 'logMessage' => null];

        return true;
    }

    /** Set (or clear, with nulls) a line's condition/hit-count/log-message — creating the breakpoint when the line has none. */
    public function setDetails(string $path, int $line, ?string $condition, ?string $hitCondition, ?string $logMessage): void
    {
        $this->breakpoints[$path][$line] = ['condition' => $condition, 'hitCondition' => $hitCondition, 'logMessage' => $logMessage];
    }

    /** @return array{condition: ?string, hitCondition: ?string, logMessage: ?string}|null null when the line has no breakpoint */
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

    /** @return list<int> the file's lines whose breakpoint carries a condition/hit-count (◆), ascending */
    public function conditionalLinesFor(string $path): array
    {
        $lines = array_keys(array_filter(
            $this->breakpoints[$path] ?? [],
            static fn (array $breakpoint): bool => null !== $breakpoint['condition'] || null !== $breakpoint['hitCondition'],
        ));
        sort($lines);

        return $lines;
    }

    /** @return list<int> the file's logpoint lines (a breakpoint carrying a log message, ◇), ascending */
    public function logpointLinesFor(string $path): array
    {
        $lines = array_keys(array_filter(
            $this->breakpoints[$path] ?? [],
            static fn (array $breakpoint): bool => null !== $breakpoint['logMessage'],
        ));
        sort($lines);

        return $lines;
    }

    /** @return list<array{line: int, condition: ?string, hitCondition: ?string, logMessage: ?string}> the file's breakpoints, ascending — the DAP payload shape */
    public function breakpointsFor(string $path): array
    {
        $breakpoints = [];
        foreach ($this->linesFor($path) as $line) {
            $breakpoints[] = ['line' => $line, ...$this->breakpoints[$path][$line]];
        }

        return $breakpoints;
    }

    /** @return array<string, list<array{line: int, condition: ?string, hitCondition: ?string, logMessage: ?string}>> every file's breakpoints */
    public function all(): array
    {
        $all = [];
        foreach (array_keys($this->breakpoints) as $path) {
            $all[$path] = $this->breakpointsFor($path);
        }

        return $all;
    }

    /**
     * Follow a renamed file, keeping its breakpoints and their conditions.
     * Pure: the caller hands over canonical paths, because by the time this
     * runs `$from` is gone from disk and cannot be resolved.
     */
    public function rename(string $from, string $to): void
    {
        if (!\array_key_exists($from, $this->breakpoints)) {
            return;
        }
        $this->breakpoints[$to] = $this->breakpoints[$from];
        unset($this->breakpoints[$from]);
    }

    public function clear(): void
    {
        $this->breakpoints = [];
    }
}
