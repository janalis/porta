<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

/**
 * Where a framed list's visible window sits in the whole: rows hidden above,
 * rows hidden below, and the selected row's place — what {@see ModalFrame}
 * draws as ▲/▼ in its padding and `3/40` in its bottom border, so a clipped
 * list never passes for a complete one.
 */
final readonly class ListOverflow
{
    public function __construct(
        public bool $above,
        public bool $below,
        /** 1-based: the selected row's place among the list's rows. */
        public int $selected,
        public int $total,
    ) {}

    /**
     * The window over $total rows that starts at $windowStart and shows $shown,
     * with the 0-based $selected row.
     */
    public static function of(int $windowStart, int $shown, int $selected, int $total): self
    {
        return new self($windowStart > 0, ($windowStart + $shown) < $total, $selected + 1, $total);
    }

    public function clipped(): bool
    {
        return $this->above || $this->below;
    }

    public function position(): string
    {
        return $this->selected.'/'.$this->total;
    }
}
