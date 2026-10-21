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
        /** Lens rows shift everything below them, so hit-testing counts them too. */
        private readonly DecorationStore $lenses,
        /** The hints drawn into a line move the text right of them; a hover card lists them. */
        private readonly InlayLines $inlays,
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
        $at = $this->pointAt($relRow, $relCol, $vp);
        $this->document->setCursor($at->line, $at->col);
        ($this->closeCompletion)();
        ($this->invalidate)();
        if (null !== $at->lens) {
            // The caret still moves to the line the lens describes — clicking
            // it is also a way of going there — and then the lens runs.
            ($this->runCodeLens)($at->line, $at->lens);
        }
    }

    /** Begin a mouse selection: place the caret at the press and drop the anchor there. */
    public function beginSelectionAt(int $relRow, int $relCol, EditorViewport $vp): void
    {
        if ($relCol >= $vp->stripeCol) {
            $this->stripeJump($relRow, $vp); // press in the error stripe jumps rather than starting a selection

            return;
        }
        $at = $this->pointAt($relRow, $relCol, $vp);
        $this->document->setCursor($at->line, $at->col);
        $this->document->beginSelect();
        ($this->closeCompletion)();
        ($this->invalidate)();
    }

    /** Extend the selection to the pointer (drag / release / Shift+click), anchoring at the caret if needed. */
    public function extendSelectionTo(int $relRow, int $relCol, EditorViewport $vp): void
    {
        $at = $this->pointAt($relRow, $relCol, $vp);
        $this->document->beginSelect();
        $this->document->setCursor($at->line, $at->col, selection: SelectionMode::Keep);
        ($this->closeCompletion)();
        ($this->invalidate)();
    }

    /** Select the word under the pointer (double-click); places a bare caret when off a word. */
    public function selectWordAt(int $relRow, int $relCol, EditorViewport $vp): void
    {
        $at = $this->pointAt($relRow, $relCol, $vp);
        $range = $this->document->wordRangeAt($at->line, $at->col);
        if (null === $range) {
            $this->document->setCursor($at->line, $at->col);
            ($this->closeCompletion)();
            ($this->invalidate)();

            return;
        }
        $this->document->setSelectionRange($at->line, $range['startCol'], $at->line, $range['endCol']);
        ($this->closeCompletion)();
        ($this->invalidate)();
    }

    /** Select the whole line under the pointer (triple-click). */
    public function selectLineAt(int $relRow, int $relCol, EditorViewport $vp): void
    {
        $line = $this->pointAt($relRow, $relCol, $vp)->line;
        $this->document->setSelectionRange($line, 0, $line, \strlen($this->document->getLine($line)));
        ($this->closeCompletion)();
        ($this->invalidate)();
    }

    /** Add a caret at the pointer, or remove the one already there (Alt+Click). */
    public function addOrRemoveCaretAt(int $relRow, int $relCol, EditorViewport $vp): void
    {
        $at = $this->pointAt($relRow, $relCol, $vp);
        $this->document->clearSelection();
        $this->multiCaret->toggleCaretAt($at->line, $at->col);
        ($this->closeCompletion)();
        ($this->invalidate)();
    }

    /** Begin a mouse column (rectangular) selection at the press (Alt+Shift+drag). */
    public function beginColumnSelectionAt(int $relRow, int $relCol, EditorViewport $vp): void
    {
        $at = $this->pointAt($relRow, $relCol, $vp);
        $this->columnAnchorLine = $at->line;
        $this->columnAnchorDisplayCol = $at->displayCol;
        $this->multiCaret->clear();
        $this->document->setCursor($at->line, $at->col);
        ($this->closeCompletion)();
        ($this->invalidate)();
    }

    /** Extend the column selection to the pointer, rebuilding the rectangle. */
    public function extendColumnSelectionTo(int $relRow, int $relCol, EditorViewport $vp): void
    {
        $at = $this->pointAt($relRow, $relCol, $vp);
        $this->multiCaret->setColumnSelection($this->columnAnchorLine, $this->columnAnchorDisplayCol, $at->line, $at->displayCol);
        ($this->closeCompletion)();
        ($this->invalidate)();
    }

    /**
     * The document character under an editor-relative cell, for a hover to
     * ask about — or null when the cell shows none: the gutter, the stripe, a
     * lens row, an inlay hint, past the end of the line or below the last
     * one. Unlike a click, which always lands SOMEWHERE, nothing is snapped
     * here.
     *
     * @return array{line: int, col: int}|null
     */
    public function characterAt(int $relRow, int $relCol, EditorViewport $vp): ?array
    {
        $at = $this->cellAt($relRow, $relCol, $vp);

        return (
            null === $at || null !== $at->hint || $at->displayCol >= $this->document->lineDisplayWidth($at->line)
                ? null
                : ['line' => $at->line, 'col' => $at->col]
        );
    }

    /**
     * The problems whose squiggle is under an editor-relative cell, worst
     * first, or null when there are none — the gutter, the stripe, a hint,
     * past the code and every cell without a squiggle say nothing.
     *
     * @return array{line: int, displayCol: int, diagnostics: non-empty-list<array{severity: DiagnosticSeverity, message: string, col: int, endCol?: int, code?: int|string, source?: string, tags?: list<int>, data?: mixed}>}|null
     */
    public function problemsAt(int $relRow, int $relCol, EditorViewport $vp): ?array
    {
        $at = $this->cellAt($relRow, $relCol, $vp);
        if (null === $at || null !== $at->hint) {
            return null;
        }
        $hits = DiagnosticHit::atDisplayCol($this->document, $at->line, $this->lenses->diagnostics[$at->line] ?? [], $at->displayCol);

        return [] === $hits ? null : ['line' => $at->line, 'displayCol' => $at->displayCol, 'diagnostics' => $hits];
    }

    /** The inlay hint drawn in an editor-relative cell, or null when the cell shows none. */
    public function inlayHintAt(int $relRow, int $relCol, EditorViewport $vp): ?InlayHint
    {
        return $this->cellAt($relRow, $relCol, $vp)?->hint;
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
        foreach ($this->inlays->shown($this->document, $at['line']) as $hint) {
            if ($hint->col < $word['startCol'] || $hint->col > $word['endCol']) {
                continue;
            }
            $labels[] = $hint->label;
        }

        return [] === $labels ? null : new Tooltip('inlay:'.$at['line'].':'.$word['startCol'], \implode(' · ', $labels), $relRow, $relCol, $relCol + 1);
    }

    /**
     * The document position an editor-relative cell shows, honouring the
     * gutter width, vertical scroll (with folds), horizontal scroll, soft wrap
     * and the inlay hints drawn into the line — a cell on a hint answers the
     * character the hint sits before. Every click, drag and hover goes through
     * here, so none of them can land somewhere the screen does not show.
     *
     * A click always lands SOMEWHERE: below the last line it is the last line,
     * on a pinned sticky header it is that header's line.
     */
    public function pointAt(int $relRow, int $relCol, EditorViewport $vp): EditorPoint
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
        if ($segment < 0) {
            // A lens row: the line it describes, at its start.
            return new EditorPoint($line, 0, 0, lens: -$segment - 1);
        }
        // A sticky header is drawn without hints; a content row with the ones
        // its line places. Wrapping forces hScroll to 0, so the row starts at
        // its segment's own screen column instead.
        $inlay = $onSticky ? InlayLine::none() : $this->inlays->forLine($doc, $line, $vp->wrapWidth);
        $rowStart = null === $vp->wrapWidth ? $vp->hScroll : $inlay->rowStart($segment, $vp->wrapWidth);
        ['displayCol' => $displayCol, 'hint' => $hint] = $inlay->hit(\max(0, $relCol - $vp->gutterWidth) + $rowStart);

        return new EditorPoint($line, $doc->byteColForDisplayCol($line, $displayCol), $displayCol, hint: $hint);
    }

    /**
     * {@see pointAt()} for a cell that shows the document — null for the
     * gutter, the stripe, a lens row or a row past the last line, where a
     * hover has nothing to ask about.
     */
    private function cellAt(int $relRow, int $relCol, EditorViewport $vp): ?EditorPoint
    {
        $stickyCount = \count($vp->stickyLines);
        $found = match (true) {
            $relCol < $vp->gutterWidth || $relCol >= $vp->stripeCol || $relRow < 0 => null,
            $relRow < $stickyCount => [$vp->stickyLines[$relRow], 0],
            default => $this->lineAtRow($relRow - $stickyCount, $vp),
        };

        return null === $found || $found[1] < 0 ? null : $this->pointAt($relRow, $relCol, $vp);
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
        // No wrapping and no lenses: rows and lines run one to one, which is
        // the fold model's own arithmetic.
        if (null === $vp->wrapWidth && !$this->lenses->hasExtraRows()) {
            $line = $this->foldModel->docLineAtRow($vp->vScroll, $contentRow);

            return null === $line ? null : [$line, 0];
        }

        $row = new EditorRowWalk($this->document, $this->foldModel, $this->lenses)->rows($contentRow + 1, $vp)[$contentRow] ?? null;

        // Content rows are their segment; a LENS row is encoded as
        // -(index + 1), so -1 is the first lens above the line and the
        // caller recovers the index without knowing how many there are.
        // A BLOCK row under the line is its last segment: a click there
        // puts the caret on the line the block is about.
        return null === $row ? null : [$row->line, $row->inLine < $row->lensRows ? -($row->inLine + 1) : \min($row->inLine - $row->lensRows, $row->segments - 1)];
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
