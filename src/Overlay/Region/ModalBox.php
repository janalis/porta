<?php

declare(strict_types=1);

namespace Porta\Overlay\Region;

/**
 * A rendered modal's box geometry, recorded at composite time for mouse
 * hit-testing — the one shape behind every floating window: the pickers
 * ({@see \Porta\Overlay\State\OverlayHost}), Find in Files, the Welcome modal
 * and the view windows. Rows and columns are screen cells; the owner
 * hit-tests in that same space.
 *
 * The item window sits below $headerRows of chrome (the title bar, plus a
 * query row for search-shaped modals); a framed box's side borders shift its
 * content one column right, which {@see innerCol()} writes down once.
 */
final readonly class ModalBox
{
    /**
     * The close affordance's column span, measured in from the box's edge on
     * its {@see CloseButton}: the `×` and the space beside it, leaving the
     * corner glyph out.
     */
    public const int CLOSE_OFFSET = 2;

    /**
     * $optionsRow and $footerRow are the find modal's two clickable chrome
     * rows in the owner's space (-1 = the box has none). They are passed, not
     * derived: both encode that modal's frame layout (ModalFrame chrome + the
     * tee rows), which this generic shape must not bake in.
     */
    public function __construct(
        public int $startRow,
        public int $col,
        public int $width,
        public int $rows,
        public int $headerRows,
        public int $windowStart,
        public int $count,
        public int $optionsRow = -1,
        public int $footerRow = -1,
        public CloseButton $closeButton = CloseButton::Standard,
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
        $first = $this->closeButton->leads() ? $this->col + 1 : $this->col + $this->width - self::CLOSE_OFFSET - 1;

        return $row === $this->startRow && $col >= $first && $col < ($first + self::CLOSE_OFFSET);
    }

    /**
     * The frame part under the cell: the × and the rest of the title bar, the
     * two side rails, the bottom border and the four corners, the content
     * inside, or outside the box altogether.
     */
    public function zoneAt(int $row, int $col): WindowZone
    {
        if (!$this->contains($row, $col)) {
            return WindowZone::Outside;
        }
        $left = $col === $this->col;
        $right = $col === ($this->col + $this->width - 1);

        return match (true) {
            $this->closeHit($row, $col) => WindowZone::Close,
            $row === $this->startRow => match (true) {
                $left => WindowZone::TopLeft,
                $right => WindowZone::TopRight,
                default => WindowZone::Title,
            },
            $row === ($this->startRow + $this->rows - 1) => match (true) {
                $left => WindowZone::BottomLeft,
                $right => WindowZone::BottomRight,
                default => WindowZone::Bottom,
            },
            $left => WindowZone::Left,
            $right => WindowZone::Right,
            default => WindowZone::Content,
        };
    }

    /** The box as a floating region, for the RTL segmenter and region hit-tests. */
    public function region(): DropdownRegion
    {
        return new DropdownRegion($this->startRow, $this->col, $this->width, $this->rows);
    }
}
