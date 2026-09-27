<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Support\IntMath;
use Porta\Ui\Tooltip;

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
        /** Lens rows shift everything below them, so hit-testing counts them too; a hover reads the inlay hints. */
        private readonly DecorationStore $lenses,
        private readonly MultiCaret $multiCaret,
        private readonly \Closure $invalidate,
        private readonly \Closure $closeCompletion,
        private readonly \Closure $centerOnLine,
        /** @var \Closure(int, int): void runs the lens at (line, index) a click landed on */
        private readonly \Closure $runCodeLens,
    ) {}

    /** Place the cursor from a click relative to the editor's top-left (0-based cells). */
    public function clickAt(int $relRow, int $relCol, EditorViewport $vp): void
    {
        if ($relCol >= $vp->stripeCol) {
            $this->stripeJump($relRow, $vp); // click in the error stripe → jump to that spot in the file

            return;
        }
        ['line' => $line, 'col' => $col, 'lens' => $lens] = $this->positionAt($relRow, $relCol, $vp);
        $this->document->setCursor($line, $col);
        ($this->closeCompletion)();
        ($this->invalidate)();
        if (null !== $lens) {
            // The caret still moves to the line the lens describes — clicking
            // it is also a way of going there — and then the lens runs.
            ($this->runCodeLens)($line, $lens);
        }
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
        $this->document->setCursor($line, $col, selection: SelectionMode::Keep);
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
     * The document character under an editor-relative cell, for a hover to
     * ask about — or null when the cell shows none: the gutter, the stripe, a
     * lens row, past the end of the line or below the last one. Unlike a
     * click, which always lands SOMEWHERE, nothing is snapped here.
     *
     * @return array{line: int, col: int}|null
     */
    public function characterAt(int $relRow, int $relCol, EditorViewport $vp): ?array
    {
        $stickyCount = \count($vp->stickyLines);
        if ($relCol < $vp->gutterWidth || $relCol >= $vp->stripeCol || $relRow < 0) {
            return null;
        }
        $found = $relRow < $stickyCount ? [$vp->stickyLines[$relRow], 0] : $this->lineAtRow($relRow - $stickyCount, $vp);
        if (null === $found || $found[1] < 0) {
            return null;
        }
        ['line' => $line, 'col' => $col, 'displayCol' => $displayCol] = $this->positionAt($relRow, $relCol, $vp);

        return $displayCol < $this->document->lineDisplayWidth($line) ? ['line' => $line, 'col' => $col] : null;
    }

    /**
     * The hover card for the inlay hints on the word under a cell — asked only
     * while hints show as cards rather than in the line. A hint sits at an
     * edge of the word it annotates (`StatusTone` → "46 references"), so the
     * hints whose column is inside or at either end of that word are its.
     */
    public function inlayTooltipAt(int $relRow, int $relCol, EditorViewport $vp): ?Tooltip
    {
        $at = $this->characterAt($relRow, $relCol, $vp);
        $word = null === $at ? null : $this->document->wordRangeAt($at['line'], $at['col']);
        // wordRangeAt() also answers for the cell just PAST a word; a card
        // there would point at a space.
        if (null === $at || null === $word || $at['col'] >= $word['endCol']) {
            return null;
        }
        $labels = [];
        foreach ($this->lenses->inlayHintsAt($at['line']) as $hint) {
            if ($hint['col'] < $word['startCol'] || $hint['col'] > $word['endCol']) {
                continue;
            }
            $labels[] = \trim($hint['text']);
        }

        return [] === $labels ? null : new Tooltip('inlay:'.$at['line'].':'.$word['startCol'], \implode(' · ', $labels), $relRow, $relCol, $relCol + 1);
    }

    /**
     * Convert an editor-relative click cell to a document (line, byte-col),
     * honouring the gutter width, vertical scroll (with folds) and horizontal
     * scroll. Shared by the click and mouse-selection entry points.
     *
     * @return array{line: int, col: int, displayCol: int, lens: int|null}
     */
    private function positionAt(int $relRow, int $relCol, EditorViewport $vp): array
    {
        $doc = $this->document;
        $stickyCount = \count($vp->stickyLines);
        // A click on a pinned sticky-scroll header row jumps to that header line.
        $onSticky = $relRow >= 0 && $relRow < $stickyCount;
        // Below the last line a click lands on it: the pane's empty rows are
        // still the file's end.
        [$line, $segment] = $onSticky
            ? [$vp->stickyLines[$relRow], 0]
            : $this->lineAtRow(\max(0, $relRow - $stickyCount), $vp) ?? [$this->document->getLineCount() - 1, 0];
        // Wrapping forces hScroll to 0, so exactly one of these is ever non-zero.
        $displayCol = $segment < 0
            ? 0 // a lens row: the line it describes, at its start
            : \max(0, $relCol - $vp->gutterWidth) + $vp->hScroll + WrapLayout::offsetOf($segment, $vp->wrapWidth ?? 0);

        return ['line' => $line, 'col' => $doc->byteColForDisplayCol($line, $displayCol), 'displayCol' => $displayCol, 'lens' => $segment < 0 ? -$segment - 1 : null];
    }

    /**
     * The document line a content row shows, and which SEGMENT of it.
     *
     * Unwrapped this is `FoldModel::docLineAtRow()` and the segment is always
     * zero. Wrapped, a line holds the walk for as many rows as it needs, so the
     * walk here has to be the one the row assembler does — a click below a
     * wrapped line lands on the wrong one otherwise, and by more lines the
     * further down the pane it is.
     *
     * @return array{0: int, 1: int}|null line, and its segment — NEGATIVE when the row is a lens; null past the last line
     */
    private function lineAtRow(int $contentRow, EditorViewport $vp): ?array
    {
        $lastLine = $this->document->getLineCount() - 1;
        // No wrapping and no lenses: rows and lines run one to one, which is
        // the fold model's own arithmetic.
        if (null === $vp->wrapWidth && !$this->lenses->hasCodeLenses()) {
            $line = $this->foldModel->docLineAtRow($vp->vScroll, $contentRow);

            return null === $line ? null : [$line, 0];
        }

        $layout = new WrapLayout($this->document);
        $line = $vp->vScroll;
        // Negative: the top line's hidden rows are "above" the first row shown.
        $row = -$vp->vScrollSegment;
        // Bounded by the line count rather than by trusting nextVisibleLine()
        // to advance: a walk that cannot terminate structurally would need a
        // guard branch nothing can reach to test.
        for ($step = 0; $line <= $lastLine && $step <= $lastLine; ++$step) {
            $lensRows = \count($this->lenses->codeLensesAt($line));
            $rows = $lensRows + (null === $vp->wrapWidth ? 1 : $layout->rowsFor($line, $vp->wrapWidth));
            if ($contentRow < ($row + $rows)) {
                // A NEGATIVE segment says "this row is a lens", and WHICH one:
                // -1 is the first lens above the line, -2 the second. The
                // caller turns that into column 0, because a lens shows none
                // of the line's own columns, and into the index to run.
                $inLine = $contentRow - $row;

                // Content rows are their segment; a LENS row is encoded as
                // -(index + 1), so -1 is the first lens above the line and the
                // caller recovers the index without knowing how many there are.
                return [$line, $inLine < $lensRows ? -($inLine + 1) : $inLine - $lensRows];
            }
            $row += $rows;
            $line = $this->foldModel->nextVisibleLine($line);
        }

        return null;
    }

    /** Jump the caret to the file position a click at screen row $relRow in the error stripe maps to. */
    private function stripeJump(int $relRow, EditorViewport $vp): void
    {
        $lineCount = $this->document->getLineCount();
        $line = (int) ((($relRow + 0.5) * $lineCount) / \max(1, $vp->renderRows));
        $line = IntMath::clamp($line, 0, $lineCount - 1);
        $this->document->setCursor($line, 0);
        ($this->centerOnLine)($line);
        ($this->closeCompletion)();
        ($this->invalidate)();
    }
}
