<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * Shared row-count state for the resizable bottom panels (results, diff,
 * terminal). Each widget sets its own default in its constructor; the layout
 * controller drives resizes through {@see setVisibleRows()}.
 */
trait PanelRows
{
    private int $visibleRows;

    /** Satisfied by AbstractWidget. */
    abstract public function invalidate(): void;

    /** Resize the panel to $rows visible rows (clamped). */
    public function setVisibleRows(int $rows): void
    {
        $rows = max(2, min($rows, 200));
        if ($rows !== $this->visibleRows) {
            $this->visibleRows = $rows;
            $this->invalidate();
        }
    }

    public function visibleRows(): int
    {
        return $this->visibleRows;
    }
}
