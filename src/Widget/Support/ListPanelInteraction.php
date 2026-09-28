<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

use Porta\Ui\ListSelection;

/**
 * The shared pointer/selection glue of the list-shaped bottom panels
 * (results, debug): wheel scrolling that moves the selection, hover tinting
 * and click activation, all addressed through the widget-relative row of the
 * last render. The consuming widget supplies its selection, row count, header
 * height and row activation through the abstract hooks.
 *
 * The wheel moves the selection but the hover does not, and the split is the
 * rule rather than an accident: the wheel is deliberate input, exactly like
 * pressing ↓, while hover is incidental pointer motion that must never own
 * state the keyboard is also driving. See {@see HoverableList}.
 */
trait ListPanelInteraction
{
    use HoverTrait;

    abstract public function invalidate(): void;

    abstract protected function selection(): ListSelection;

    abstract protected function rowCount(): int;

    /** How many chrome rows precede the first list row in the rendered panel. */
    abstract protected function headerRowCount(): int;

    /** Activate the display row at $index (jump / expand / collapse — widget-specific). */
    abstract protected function activateRow(int $index): void;

    /** The project-relative file the row at $index names, or null for a row that names none. */
    abstract protected function rowPath(int $index): ?string;

    /**
     * The file the row under $relRow names, for its right-click menu: the one
     * the row would open. Null past the rows or on a row that names no file.
     */
    public function pathAt(int $relRow): ?string
    {
        $index = $this->rowIndexAt($relRow);

        return null === $index ? null : $this->rowPath($index);
    }

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

    /** Tint the row under the pointer (widget-relative row); returns whether it changed. */
    public function hoverRow(int $relRow): bool
    {
        return $this->setHovered($this->rowIndexAt($relRow));
    }

    /**
     * A click selects AND activates the row under the pointer — the first
     * click, deliberately: these lists are tools whose rows exist to be jumped
     * to. See {@see \Porta\Input\ClickCounter} for why multiplicity is the
     * editor's alone.
     */
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
