<?php

declare(strict_types=1);

namespace Porta\Overlay\Region;

/**
 * A floating dropdown's screen box (the app menu or the context menu): the
 * one geometry the mouse router hit-tests and the RTL segmenter treats as its
 * own pane, previously threaded around as a four-key array shape.
 */
final readonly class DropdownRegion
{
    /**
     * @param bool $ltr    whether the box's content is left-to-right verbatim
     *                     (code-carrying modals like Find in Files) rather than
     *                     mirrored prose — the RTL segmenter honors it per run
     * @param bool $framed whether the box wears a {@see \Porta\Widget\Render\PopupFrame}:
     *                     its outermost row/column ring is border, not items
     * @param int  $first  the item its first item row shows — past 0 when a
     *                     list too tall for the screen has scrolled
     */
    public function __construct(
        public int $top,
        public int $col,
        public int $width,
        public int $count,
        public bool $ltr = false,
        public bool $framed = false,
        public int $first = 0,
    ) {}

    /**
     * A surface's box grown by its shadow bands — but only where they
     * actually paint (a box flush against the pane union's edge casts none
     * there), so the RTL segmenter never claims cells outside the surface.
     *
     * @param array{int, int, int, int} $bounds [top, left, bottom, right)
     */
    public static function shadowClamped(int $top, int $col, int $width, int $rows, array $bounds, bool $ltr = false): self
    {
        [, , $boundBottom, $boundRight] = $bounds;

        return new self($top, $col, \min($width + 1, $boundRight - $col), \min($rows + 1, $boundBottom - $top), ltr: $ltr);
    }

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
     * framed box — on its border cells: item $first + i sits i + 1 rows below
     * the top border, between the rails, so a 1-cell mis-click on the frame neither
     * runs nor dismisses anything.
     */
    public function itemAt(int $row, int $col): ?int
    {
        if (!$this->contains($row, $col)) {
            return null;
        }
        if (!$this->framed) {
            return $this->first + $row - $this->top;
        }
        $onBorderRow = $row === $this->top || $row === ($this->top + $this->count - 1);
        $onBorderCol = $col === $this->col || $col === ($this->col + $this->width - 1);

        return $onBorderRow || $onBorderCol ? null : $this->first + $row - $this->top - 1;
    }
}
