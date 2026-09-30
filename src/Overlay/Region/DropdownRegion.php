<?php

declare(strict_types=1);

namespace Porta\Overlay\Region;

use Porta\I18n\BaseDirection;

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
     * @param ?array{int, int} $firstRow the first row's own [col, width), when it
     *                     is not the box's: a shadowed box's top row, which
     *                     casts no side shadow beside it
     * @param ?array{int, int} $lastRow the last row's own [col, width): a
     *                     shadow's band, offset from the box above it
     */
    public function __construct(
        public int $top,
        public int $col,
        public int $width,
        public int $count,
        public bool $ltr = false,
        public bool $framed = false,
        public int $first = 0,
        public ?array $firstRow = null,
        public ?array $lastRow = null,
    ) {}

    /**
     * A surface's box grown by its shadow bands — but only where they
     * actually paint (a box flush against the pane union's edge casts none
     * there), so the RTL segmenter never claims cells outside the surface.
     *
     * $shown is the direction the surface reaches the screen in: a mirrored
     * one casts its shadow to the left before the flip
     * ({@see \Porta\Widget\Render\ModalShadow::spans()}), so it is widened
     * to the left.
     *
     * @param array{int, int, int, int} $bounds [top, left, bottom, right)
     */
    public static function shadowClamped(int $top, int $col, int $width, int $rows, array $bounds, bool $ltr = false, BaseDirection $shown = BaseDirection::Ltr): self
    {
        [, $boundLeft, $boundBottom, $boundRight] = $bounds;
        $count = \min($rows + 1, $boundBottom - $top);
        // Claimed cell for cell: the top row is the box alone, the rows below
        // it the box and its side column, the band's row the band alone. A
        // rectangle also claimed the cell beside the title row and the one
        // under the box's far corner, where no surface paints, and a mirrored
        // frame stamps those from the floating layer: blank holes.
        if (BaseDirection::Rtl === $shown) {
            $left = \max($boundLeft, $col - 1);
            $side = $left < $col;

            return new self(
                $top,
                $left,
                $col + $width - $left,
                $count,
                ltr: $ltr,
                firstRow: $side ? [$col, $width] : null,
                lastRow: $count > $rows ? [$left, $col + $width - 1 - $left] : null,
            );
        }
        $claimed = \min($width + 1, $boundRight - $col);

        return new self(
            $top,
            $col,
            $claimed,
            $count,
            ltr: $ltr,
            firstRow: $claimed > $width ? [$col, $width] : null,
            lastRow: $count > $rows ? [$col + 1, $claimed - 1] : null,
        );
    }

    /** Whether the screen cell sits inside the box (borders included). */
    public function contains(int $row, int $col): bool
    {
        [$start, $width] = $this->columnsOn($row);

        return $this->coversRow($row) && $col >= $start && $col < ($start + $width);
    }

    /**
     * The [col, width) the box takes on $row: its own, or its first or last row's.
     *
     * @return array{int, int}
     */
    public function columnsOn(int $row): array
    {
        return match (true) {
            null !== $this->firstRow && $row === $this->top => $this->firstRow,
            null !== $this->lastRow && $row === ($this->top + $this->count - 1) => $this->lastRow,
            default => [$this->col, $this->width],
        };
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
