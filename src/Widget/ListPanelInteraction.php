<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Ui\ListSelection;

/**
 * The shared pointer/selection glue of the list-shaped bottom panels
 * (results, debug): wheel scrolling that moves the selection, hover
 * highlighting and click activation, all addressed through the
 * widget-relative row of the last render. The consuming widget supplies its
 * selection, row count, header height and row activation through the
 * abstract hooks.
 */
trait ListPanelInteraction
{
    abstract public function invalidate(): void;

    abstract protected function selection(): ListSelection;

    abstract protected function rowCount(): int;

    /** How many chrome rows precede the first list row in the rendered panel. */
    abstract protected function headerRowCount(): int;

    /** Activate the display row at $index (jump / expand / collapse — widget-specific). */
    abstract protected function activateRow(int $index): void;

    /** Scroll the list by $delta rows (mouse wheel); moves the selection, the window follows. */
    public function scrollBy(int $delta): void
    {
        if ($delta < 0) {
            $this->selection()->moveUp(-$delta);
            $this->invalidate();

            return;
        }
        $this->selection()->moveDown($this->rowCount(), $delta);
        $this->invalidate();
    }

    /** Highlight the row under the pointer (widget-relative row); returns whether it changed. */
    public function hoverRow(int $relRow): bool
    {
        if ($this->selection()->selectIfChanged($this->rowIndexAt($relRow))) {
            $this->invalidate();

            return true;
        }

        return false;
    }

    /** A click selects and activates the row under the pointer. */
    public function handleClick(int $relRow): void
    {
        $index = $this->rowIndexAt($relRow);
        if (null === $index) {
            return;
        }
        $this->selection()->select($index);
        $this->activateRow($index);
    }

    /** Display-row index at the given widget-relative row, or null on the header/past the list. */
    private function rowIndexAt(int $relRow): ?int
    {
        return $this->selection()->rowIndexAt($relRow, $this->rowCount(), $this->headerRowCount());
    }
}
