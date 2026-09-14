<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The anchor a keyboard column (rectangular) selection is built from.
 *
 * {@see MultiCaret::setColumnSelection()} has always been able to build the
 * rectangle — it is told all four coordinates and rebuilds every caret from
 * them. That suited the mouse, which knows where the drag began, and left the
 * feature reachable ONLY by Alt+Shift+drag. What a keyboard needs is this: an
 * anchor that survives keystrokes.
 *
 * Columns here are DISPLAY columns, and the caret's is remembered rather than
 * read back from the document. That is the "virtual column" every column editor
 * needs: stepping down through a short line would otherwise clamp the position
 * and the rectangle would narrow permanently as you passed a short line.
 */
final class ColumnSelection
{
    private ?int $anchorRow = null;
    private int $anchorCol = 0;
    private int $caretRow = 0;
    private int $caretCol = 0;

    public function isActive(): bool
    {
        return null !== $this->anchorRow;
    }

    /** Start a rectangle at the caret; both corners begin in the same place. */
    public function begin(int $row, int $displayCol): void
    {
        $this->anchorRow = $row;
        $this->anchorCol = $displayCol;
        $this->caretRow = $row;
        $this->caretCol = $displayCol;
    }

    public function cancel(): void
    {
        $this->anchorRow = null;
    }

    /**
     * Move the moving corner and report the whole rectangle.
     *
     * The row is clamped to the document; the column is NOT clamped to the
     * line, only to zero — see the virtual-column note above.
     *
     * @return array{0: int, 1: int, 2: int, 3: int} anchor row, anchor col, caret row, caret col
     */
    public function extend(int $rowDelta, int $colDelta, Document $document): array
    {
        $this->caretRow = $document->clampLine($this->caretRow + $rowDelta);
        $this->caretCol = \max(0, $this->caretCol + $colDelta);

        return [$this->anchorRow ?? $this->caretRow, $this->anchorCol, $this->caretRow, $this->caretCol];
    }
}
