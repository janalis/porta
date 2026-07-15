<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Support\IntMath;

/**
 * Mouse hit-testing and selection for the code editor: maps an editor-relative
 * click cell to a document position (honouring the gutter, pinned sticky-scroll
 * headers, and fold-aware vertical scroll) and drives click/drag selection,
 * word/line select, Alt-click carets, column (rectangular) selection and the
 * error-stripe jump. The widget owns the viewport and passes it per call; effects
 * flow back through the injected invalidate / closeCompletion / centerOnLine
 * callbacks (the same collaborator pattern as {@see MultiCaret}/{@see SnippetSession}).
 */
final class EditorMouse
{
    private int $columnAnchorLine = 0;
    private int $columnAnchorDisplayCol = 0;

    /**
     * @param \Closure(): void    $invalidate      request a redraw
     * @param \Closure(): void    $closeCompletion dismiss any open completion popup
     * @param \Closure(int): void $centerOnLine    scroll the viewport to centre a line (stripe jump)
     */
    public function __construct(
        private readonly Document $document,
        private readonly FoldModel $foldModel,
        private readonly MultiCaret $multiCaret,
        private readonly \Closure $invalidate,
        private readonly \Closure $closeCompletion,
        private readonly \Closure $centerOnLine,
    ) {}

    /** Place the cursor from a click relative to the editor's top-left (0-based cells). */
    public function clickAt(int $relRow, int $relCol, EditorViewport $vp): void
    {
        if ($relCol >= $vp->stripeCol) {
            $this->stripeJump($relRow, $vp); // click in the error stripe → jump to that spot in the file

            return;
        }
        ['line' => $line, 'col' => $col] = $this->positionAt($relRow, $relCol, $vp);
        $this->document->setCursor($line, $col);
        ($this->closeCompletion)();
        ($this->invalidate)();
    }

    /** Begin a mouse selection: place the caret at the press and drop the anchor there. */
    public function beginSelectionAt(int $relRow, int $relCol, EditorViewport $vp): void
    {
        if ($relCol >= $vp->stripeCol) {
            $this->stripeJump($relRow, $vp); // press in the error stripe jumps rather than starting a selection

            return;
        }
        ['line' => $line, 'col' => $col] = $this->positionAt($relRow, $relCol, $vp);
        $this->document->setCursor($line, $col);
        $this->document->beginSelect();
        ($this->closeCompletion)();
        ($this->invalidate)();
    }

    /** Extend the selection to the pointer (drag / release / Shift+click), anchoring at the caret if needed. */
    public function extendSelectionTo(int $relRow, int $relCol, EditorViewport $vp): void
    {
        ['line' => $line, 'col' => $col] = $this->positionAt($relRow, $relCol, $vp);
        $this->document->beginSelect();
        $this->document->setCursor($line, $col, keepSelection: true);
        ($this->closeCompletion)();
        ($this->invalidate)();
    }

    /** Select the word under the pointer (double-click); places a bare caret when off a word. */
    public function selectWordAt(int $relRow, int $relCol, EditorViewport $vp): void
    {
        ['line' => $line, 'col' => $col] = $this->positionAt($relRow, $relCol, $vp);
        $range = $this->document->wordRangeAt($line, $col);
        if (null === $range) {
            $this->document->setCursor($line, $col);
            ($this->closeCompletion)();
            ($this->invalidate)();

            return;
        }
        $this->document->setSelectionRange($line, $range['startCol'], $line, $range['endCol']);
        ($this->closeCompletion)();
        ($this->invalidate)();
    }

    /** Select the whole line under the pointer (triple-click). */
    public function selectLineAt(int $relRow, int $relCol, EditorViewport $vp): void
    {
        ['line' => $line] = $this->positionAt($relRow, $relCol, $vp);
        $this->document->setSelectionRange($line, 0, $line, \strlen($this->document->getLine($line)));
        ($this->closeCompletion)();
        ($this->invalidate)();
    }

    /** Add a caret at the pointer, or remove the one already there (Alt+Click). */
    public function addOrRemoveCaretAt(int $relRow, int $relCol, EditorViewport $vp): void
    {
        ['line' => $line, 'col' => $col] = $this->positionAt($relRow, $relCol, $vp);
        $this->document->clearSelection();
        $this->multiCaret->toggleCaretAt($line, $col);
        ($this->closeCompletion)();
        ($this->invalidate)();
    }

    /** Begin a mouse column (rectangular) selection at the press (Alt+Shift+drag). */
    public function beginColumnSelectionAt(int $relRow, int $relCol, EditorViewport $vp): void
    {
        ['line' => $line, 'col' => $col, 'displayCol' => $displayCol] = $this->positionAt($relRow, $relCol, $vp);
        $this->columnAnchorLine = $line;
        $this->columnAnchorDisplayCol = $displayCol;
        $this->multiCaret->clear();
        $this->document->setCursor($line, $col);
        ($this->closeCompletion)();
        ($this->invalidate)();
    }

    /** Extend the column selection to the pointer, rebuilding the rectangle. */
    public function extendColumnSelectionTo(int $relRow, int $relCol, EditorViewport $vp): void
    {
        ['line' => $line, 'displayCol' => $displayCol] = $this->positionAt($relRow, $relCol, $vp);
        $this->multiCaret->setColumnSelection($this->columnAnchorLine, $this->columnAnchorDisplayCol, $line, $displayCol);
        ($this->closeCompletion)();
        ($this->invalidate)();
    }

    /**
     * Convert an editor-relative click cell to a document (line, byte-col),
     * honouring the gutter width, vertical scroll (with folds) and horizontal
     * scroll. Shared by the click and mouse-selection entry points.
     *
     * @return array{line: int, col: int, displayCol: int}
     */
    private function positionAt(int $relRow, int $relCol, EditorViewport $vp): array
    {
        $doc = $this->document;
        $stickyCount = \count($vp->stickyLines);
        // A click on a pinned sticky-scroll header row jumps to that header line.
        $line =
            $relRow >= 0 && $relRow < $stickyCount
                ? $vp->stickyLines[$relRow]
                : $this->foldModel->docLineAtRow($vp->vScroll, max(0, $relRow - $stickyCount)) ?? ($doc->getLineCount() - 1);
        $displayCol = max(0, $relCol - $vp->gutterWidth) + $vp->hScroll;

        return ['line' => $line, 'col' => $doc->byteColForDisplayCol($line, $displayCol), 'displayCol' => $displayCol];
    }

    /** Jump the caret to the file position a click at screen row $relRow in the error stripe maps to. */
    private function stripeJump(int $relRow, EditorViewport $vp): void
    {
        $lineCount = $this->document->getLineCount();
        $line = (int) ((($relRow + 0.5) * $lineCount) / max(1, $vp->renderRows));
        $line = IntMath::clamp($line, 0, $lineCount - 1);
        $this->document->setCursor($line, 0);
        ($this->centerOnLine)($line);
        ($this->closeCompletion)();
        ($this->invalidate)();
    }
}
