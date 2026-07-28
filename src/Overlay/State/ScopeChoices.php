<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

/**
 * The find modal's Alt+S scope carousel: the choice labels and which one is
 * active. Translator-free — the owner supplies translated labels and its own
 * fallback for an empty list.
 */
final readonly class ScopeChoices
{
    /** @param list<string> $labels */
    public function __construct(
        public array $labels,
        public int $index = 0,
    ) {}

    /** The active label, or null when the choice list is empty. */
    public function label(): ?string
    {
        return $this->labels[$this->index] ?? null;
    }

    /** The next choice in the cycle (wraps; a no-op carousel of one is fine). */
    public function cycled(): self
    {
        return new self($this->labels, ($this->index + 1) % max(1, \count($this->labels)));
    }
}
