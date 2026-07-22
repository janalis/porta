<?php

declare(strict_types=1);

namespace Porta\Overlay;

/**
 * A floating dropdown's screen box (the app menu or the context menu): the
 * one geometry the mouse router hit-tests and the RTL segmenter treats as its
 * own pane, previously threaded around as a four-key array shape.
 */
final class DropdownRegion
{
    /**
     * @param bool $ltr whether the box's content is left-to-right verbatim
     *                  (code-carrying modals like Find in Files) rather than
     *                  mirrored prose — the RTL segmenter honors it per run
     */
    public function __construct(
        public readonly int $top,
        public readonly int $col,
        public readonly int $width,
        public readonly int $count,
        public readonly bool $ltr = false,
    ) {}

    /** Whether the screen cell sits inside the box. */
    public function contains(int $row, int $col): bool
    {
        return $this->coversRow($row) && $col >= $this->col && $col < ($this->col + $this->width);
    }

    /** Whether the box spans screen row $row. */
    public function coversRow(int $row): bool
    {
        return $row >= $this->top && $row < ($this->top + $this->count);
    }

    /** Row offset of a screen row inside the box (the dropdown item index). */
    public function rowAt(int $row): int
    {
        return $row - $this->top;
    }
}
