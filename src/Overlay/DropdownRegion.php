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
     * @param bool $ltr    whether the box's content is left-to-right verbatim
     *                     (code-carrying modals like Find in Files) rather than
     *                     mirrored prose — the RTL segmenter honors it per run
     * @param bool $framed whether the box wears a {@see \Porta\Widget\Render\PopupFrame}:
     *                     its outermost row/column ring is border, not items
     */
    public function __construct(
        public readonly int $top,
        public readonly int $col,
        public readonly int $width,
        public readonly int $count,
        public readonly bool $ltr = false,
        public readonly bool $framed = false,
    ) {}

    /** Whether the screen cell sits inside the box (borders included). */
    public function contains(int $row, int $col): bool
    {
        return $this->coversRow($row) && $col >= $this->col && $col < ($this->col + $this->width);
    }

    /** Whether the box spans screen row $row (borders included). */
    public function coversRow(int $row): bool
    {
        return $row >= $this->top && $row < ($this->top + $this->count);
    }

    /**
     * Item index at the screen cell, or null outside the box and — on a
     * framed box — on its border cells: item i sits one row below the top
     * border, between the rails, so a 1-cell mis-click on the frame neither
     * runs nor dismisses anything.
     */
    public function itemAt(int $row, int $col): ?int
    {
        if (!$this->contains($row, $col)) {
            return null;
        }
        if (!$this->framed) {
            return $row - $this->top;
        }
        $onBorderRow = $row === $this->top || $row === ($this->top + $this->count - 1);
        $onBorderCol = $col === $this->col || $col === ($this->col + $this->width - 1);

        return $onBorderRow || $onBorderCol ? null : $row - $this->top - 1;
    }
}
