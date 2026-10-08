<?php

declare(strict_types=1);

namespace Porta\Markdown;

/**
 * A half-open run of source bytes, [start, end). Every node of a parsed
 * document carries one, so whatever is drawn from the tree can be traced back
 * to — and later edited at — the exact text it came from.
 */
final readonly class SourceRange
{
    public function __construct(
        public int $start,
        public int $end,
    ) {}

    public function length(): int
    {
        return $this->end - $this->start;
    }

    public function contains(self $other): bool
    {
        return $other->start >= $this->start && $other->end <= $this->end;
    }

    /** The smallest range covering both. */
    public function union(self $other): self
    {
        return new self(\min($this->start, $other->start), \max($this->end, $other->end));
    }
}
