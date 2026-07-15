<?php

declare(strict_types=1);

namespace Porta\Debug;

/**
 * The watch expressions, in insertion order. Values are not stored here —
 * they are per-stop, per-frame evaluation results owned by the debug panel.
 * In-memory only, like {@see BreakpointStore}.
 */
final class WatchStore
{
    /** @var list<string> */
    private array $expressions = [];

    public function add(string $expression): void
    {
        $this->expressions[] = $expression;
    }

    public function remove(int $index): void
    {
        if (isset($this->expressions[$index])) {
            array_splice($this->expressions, $index, 1);
        }
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->expressions;
    }
}
