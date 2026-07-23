<?php

declare(strict_types=1);

namespace Porta\Overlay\Region;

/**
 * A rendered modal's box geometry, recorded at composite time for mouse
 * hit-testing — the one shape behind what were four ad-hoc per-picker arrays
 * ({@see \Porta\Overlay\State\OverlayHost}, the find-in-files modal) plus the
 * Welcome modal. Rows/columns are in whatever space the owner rendered in
 * (editor-relative for the pickers, absolute for the Welcome modal); the owner
 * hit-tests in that same space.
 *
 * The item window sits below $headerRows of chrome (the title bar, plus a
 * query row for search-shaped modals); a framed box's side borders shift its
 * content one column right, which {@see innerCol()} writes down once.
 */
final readonly class ModalBox
{
    /**
     * The close affordance's column span, measured back from the box's right
     * edge: the `×` and the space before it, leaving the corner glyph out.
     */
    public const int CLOSE_OFFSET = 2;

    public function __construct(
        public int $startRow,
        public int $col,
        public int $width,
        public int $rows,
        public int $headerRows,
        public int $windowStart,
        public int $count,
    ) {}

    /** Whether the cell sits anywhere inside the box, borders included. */
    public function contains(int $row, int $col): bool
    {
        return $row >= $this->startRow && $row < ($this->startRow + $this->rows) && $col >= $this->col && $col < ($this->col + $this->width);
    }

    /** Absolute item index at the cell, or null outside the item window. */
    public function itemAt(int $row, int $col): ?int
    {
        if ($col < $this->col || $col >= ($this->col + $this->width)) {
            return null;
        }
        $i = $row - ($this->startRow + $this->headerRows);
        if ($i < 0 || $i >= $this->count) {
            return null;
        }

        return $this->windowStart + $i;
    }

    /** The column inside a framed box's content, past the left rail and padding column. */
    public function innerCol(int $col): int
    {
        return $col - $this->col - 2;
    }

    /** The first item-window row — the row below the box's chrome. */
    public function windowTop(): int
    {
        return $this->startRow + $this->headerRows;
    }

    /** Whether the cell hits the title bar's close `×` (checked before any item mapping). */
    public function closeHit(int $row, int $col): bool
    {
        return $row === $this->startRow && $col >= ($this->col + $this->width - self::CLOSE_OFFSET - 1) && $col < ($this->col + $this->width - 1);
    }

    /** Whether the cell is the bottom-right corner — the resizable modals' grab. */
    public function resizeHit(int $row, int $col): bool
    {
        return $row === ($this->startRow + $this->rows - 1) && $col === ($this->col + $this->width - 1);
    }

    /** The box as a floating region, for the RTL segmenter and region hit-tests. */
    public function region(): DropdownRegion
    {
        return new DropdownRegion($this->startRow, $this->col, $this->width, $this->rows);
    }
}
