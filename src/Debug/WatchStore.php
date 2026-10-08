<?php

declare(strict_types=1);

namespace Porta\Debug;

/**
 * The watch expressions, in insertion order. Values are not stored here —
 * they are per-stop, per-frame evaluation results owned by the debug panel.
 * Persisted across restarts via {@see DebugStatePersistence}, like
 * {@see BreakpointStore}.
 */
final class WatchStore
{
    /** @var list<string> */
    public private(set) array $expressions = [];

    public function add(string $expression): void
    {
        $this->expressions[] = $expression;
    }

    public function remove(int $index): void
    {
        if (null !== ($this->expressions[$index] ?? null)) {
            \array_splice($this->expressions, $index, length: 1);
        }
    }
}
