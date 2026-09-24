<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The signature popup's content: the label the server sent, and which slice of
 * it is the argument being typed.
 *
 * One value rather than two fields on {@see \Porta\Widget\CodeEditorWidget},
 * which is the codebase's widest class and already pins `too-many-properties`.
 * They are one fact anyway — an active range without its label means nothing —
 * so carrying them apart only invited them to drift.
 */
final readonly class SignatureHint
{
    /**
     * @param array{0: int, 1: int}|null                         $activeRange byte slice of $label holding the active argument
     * @param list<array{0: string, 1: array{0: int, 1: int}|null}> $overloads   every signature the server offered, each with its own active range; [] or one when there is no choice
     * @param int                                                $overload    which of them this is
     */
    public function __construct(
        public string $label,
        public ?array $activeRange = null,
        public array $overloads = [],
        public int $overload = 0,
    ) {}

    /** Whether there is another signature to show — a function with overloads. */
    public function hasOverloads(): bool
    {
        return \count($this->overloads) > 1;
    }

    /** The next or previous overload, wrapping. */
    public function cycled(int $delta): self
    {
        $count = \count($this->overloads);
        if ($count < 2) {
            return $this;
        }
        $at = ((($this->overload + $delta) % $count) + $count) % $count;

        return new self($this->overloads[$at][0], $this->overloads[$at][1], $this->overloads, $at);
    }

    /** `2/3` while there are overloads to choose between, else ''. */
    public function counter(): string
    {
        return $this->hasOverloads() ? ($this->overload + 1).'/'.\count($this->overloads) : '';
    }
}
