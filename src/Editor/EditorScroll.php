<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Support\IntMath;

/**
 * The editor's viewport scroll state: vertical/horizontal offsets, the rows
 * available for scrolled content, and the clamp math that keeps the caret
 * visible — including the sticky-scroll fixpoint, which was previously inline
 * field-twiddling inside the widget's render pass. Rebuilt per document, so a
 * buffer switch naturally resets the scroll.
 */
final class EditorScroll
{
    /** Cap on rows the sticky-scroll band may pin at the top of the viewport. */
    public const int MAX_STICKY_ROWS = 5;

    public private(set) int $vScroll = 0;

    /**
     * How many rows of the TOP line are hidden above the viewport — always 0
     * unless soft wrap is on, and the only way to scroll INTO a line taller
     * than the pane. Every plain `$vScroll` write resets it, because moving to
     * a different line means starting at that line's first row.
     */
    public private(set) int $vScrollSegment = 0;
    public private(set) int $hScroll = 0;
    public int $contentRows = 24; // rows available for scrolled content (viewport minus the sticky band)

    /** Wheel-scrolled away from the caret: the viewport is free until the caret moves. */
    private bool $detached = false;

    /** The caret position (document version, line, col) of the last attached render. */
    private ?int $lastVersion = null;
    private int $lastLine = 0;
    private int $lastCol = 0;

    /**
     * @param \Closure(): void $invalidate repaint request back into the widget
     */
    public function __construct(
        private readonly Document $document,
        private readonly FoldModel $foldModel,
        private readonly \Closure $invalidate,
    ) {}

    /** Record the content rows of the last render (viewport minus sticky band). */
    /**
     * The last rendered content width, kept for the same reason
     * {@see $contentRows} is: soft-wrap caret motion has to know how wide a
     * visual row is, and that is a render-time value nothing else retains.
     */
    public private(set) int $contentWidth = 0;

    /**
     * Rows one document line occupies — wrapped segments plus code-lens rows.
     * Null means one row per line, which is what every caller wants until soft
     * wrap or code vision is on, and keeps this arithmetic exactly as it was.
     *
     * @var (\Closure(int): int)|null
     */
    public ?\Closure $rowsForLine = null;

    public function setContentWidth(int $width): void
    {
        $this->contentWidth = \max(0, $width);
    }

    /** Centre the viewport on a line (the stripe-jump target). */
    public function centerOnLine(int $line): void
    {
        $this->detached = false;
        $this->vScroll = \max(0, $line - \intdiv($this->contentRows, num2: 2));
        $this->vScrollSegment = 0;
    }

    /** Centre the viewport on the caret line (after a jump). */
    public function centerCursor(): void
    {
        $this->centerOnLine($this->document->getCursorLine());
    }

    /**
     * Scroll the viewport by $delta rows, leaving the caret where it is — the
     * caret may scroll out of view (and the selection survives). The viewport
     * stays detached from the caret until the caret itself moves, when
     * {@see clampToCaret} snaps the view back to it.
     */
    public function scrollBy(int $delta): void
    {
        $max = \max(0, $this->document->getLineCount() - 1);
        $this->vScroll = IntMath::clamp($this->vScroll + $delta, 0, $max);
        $this->vScrollSegment = 0;
        $this->detached = true;
        ($this->invalidate)();
    }

    /**
     * Clamp the vertical scroll so the caret stays visible below the pinned
     * sticky-scroll band. Scrolling can change how many headers are pinned, so
     * iterate to a fixpoint (bounded — each pass adds at most one more sticky
     * row). $stickyCountAt reports the pinned-header count for a candidate
     * scroll position, so this never learns about the breadcrumb scanner.
     *
     * @param \Closure(int): int $stickyCountAt fn(vScroll) => pinned header count
     */
    public function clampToCaret(int $rows, \Closure $stickyCountAt): void
    {
        $doc = $this->document;
        $cursorLine = $doc->getCursorLine();
        $caretUnmoved = $doc->getVersion() === $this->lastVersion && $cursorLine === $this->lastLine && $doc->getCursorCol() === $this->lastCol;
        if ($this->detached && $caretUnmoved) {
            // Wheel-detached and the caret has not moved: keep the viewport
            // where the user put it, only snapped off fold interiors (folding
            // can change without a version bump; any TEXT change bumps the
            // version and re-attaches below). No revealCaretLine: an offscreen
            // caret must not unfold its region.
            $this->vScroll = $this->foldModel->snapVisible($this->vScroll);

            return;
        }
        $this->detached = false;
        $this->lastVersion = $doc->getVersion();
        $this->lastLine = $cursorLine;
        $this->lastCol = $doc->getCursorCol();
        $this->foldModel->revealCaretLine();
        $this->vScroll = $this->foldModel->snapVisible(IntMath::clamp($this->vScroll, 0, $this->document->getLineCount() - 1));
        for ($iter = 0; $iter <= self::MAX_STICKY_ROWS; ++$iter) {
            $stickyCount = $stickyCountAt($this->vScroll);
            $cursorRow = $this->caretRow($cursorLine);
            if ($cursorRow < 0) {
                $this->vScroll = $this->foldModel->snapVisible($cursorLine);
                $this->vScrollSegment = 0;

                continue;
            }
            if ($cursorRow > ($rows - $stickyCount - 1)) {
                $top = $this->topLineFor($cursorLine, $rows - $stickyCount - 1);
                if ($top === $this->vScroll && 0 === $this->vScrollSegment) {
                    // Nowhere above to scroll to: the caret is deep inside a
                    // line taller than the pane, so scroll INTO that line.
                    $this->vScrollSegment = \max(0, $cursorRow - ($rows - $stickyCount - 1));

                    break;
                }
                $this->vScroll = $top;
                $this->vScrollSegment = 0;

                continue;
            }
            break; // caret already visible below the pinned band
        }
    }

    /**
     * Shift the horizontal scroll so the caret's display column stays inside
     * the content area, keeping an 8-column margin when scrolling back left.
     * A render with a parked caret ($cursorMoved false) leaves a manual
     * horizontal scroll alone — moving/typing snaps the view back to the caret.
     */
    /**
     * The caret's SCREEN row from the top of the viewport, which is only the
     * same as its line offset while every line is one row tall. Wrapped, a
     * line above the caret contributes a row per segment; with code vision, a
     * row per lens — so counting lines put the caret off screen and left the
     * viewport there, which is what this fixes.
     */
    private function caretRow(int $cursorLine): int
    {
        $lineOffset = $this->foldModel->screenRowFor($this->vScroll, $cursorLine);
        if ($lineOffset < 0 || null === $this->rowsForLine) {
            return $lineOffset;
        }

        // A local, because the analyzer cannot hold a PROPERTY's narrowing
        // across the calls in this loop.
        $rowsFor = $this->rowsForLine;
        $row = 0;
        $line = $this->vScroll;
        for ($step = 0; $line < $cursorLine && $step <= $cursorLine; ++$step) {
            $row += $rowsFor($line);
            $line = $this->foldModel->nextVisibleLine($line);
        }

        // Plus the caret's own row WITHIN its line: the lens rows above it and
        // the segment its column falls in.
        return $row + $rowsFor($cursorLine) - $this->wrappedRows($cursorLine) + $this->caretSegment() - $this->vScrollSegment;
    }

    /** The top line that leaves the caret $budget rows down, counting rows. */
    private function topLineFor(int $cursorLine, int $budget): int
    {
        if (null === $this->rowsForLine) {
            return $this->foldModel->visibleLineAbove($cursorLine, $budget);
        }

        $rowsFor = $this->rowsForLine;
        $spent = $this->caretSegment() + ($rowsFor($cursorLine) - $this->wrappedRows($cursorLine));
        $line = $cursorLine;
        // Bounded by $step, so no guard is needed for visibleLineAbove() not
        // advancing — and a guard nothing can reach is not a guard.
        for ($step = 0; $step <= $cursorLine; ++$step) {
            $above = $this->foldModel->visibleLineAbove($line, 1);
            $cost = $rowsFor($above);
            if (($spent + $cost) > $budget) {
                break;
            }
            $spent += $cost;
            $line = $above;
        }

        return $this->foldModel->snapVisible($line);
    }

    /** Segments the caret's own line wraps into, excluding any lens rows. */
    private function wrappedRows(int $line): int
    {
        $width = $this->contentWidth;

        return $width <= 0 ? 1 : \max(1, (int) \ceil(\max(1, $this->document->lineDisplayWidth($line)) / $width));
    }

    /** Which wrapped segment of its own line the caret sits in. */
    private function caretSegment(): int
    {
        $width = $this->contentWidth;

        return $width <= 0 ? 0 : \intdiv($this->document->getCursorDisplayCol(), $width);
    }

    public function followCursor(int $cursorDisplayCol, int $contentWidth, CaretMotion $motion = CaretMotion::Moved): void
    {
        if (CaretMotion::Parked === $motion) {
            return;
        }
        if ($cursorDisplayCol < $this->hScroll) {
            $this->hScroll = \max(0, $cursorDisplayCol - 8);

            return;
        }
        if ($cursorDisplayCol > ($this->hScroll + $contentWidth - 1)) {
            $this->hScroll = $cursorDisplayCol - $contentWidth + 1;
        }
    }

    /** Scroll the view horizontally without touching the caret, clamped to [0, $max]. */
    public function scrollHBy(int $cols, int $max): void
    {
        $this->hScroll = IntMath::clamp($this->hScroll + $cols, 0, \max(0, $max));
        ($this->invalidate)();
    }
}
