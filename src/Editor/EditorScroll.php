<?php

declare(strict_types=1);

namespace Porta\Editor;

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

    private int $vScroll = 0;
    private int $hScroll = 0;
    private int $contentRows = 24; // rows available for scrolled content (viewport minus the sticky band)

    /**
     * @param \Closure(): void $invalidate repaint request back into the widget
     */
    public function __construct(
        private readonly Document $document,
        private readonly FoldModel $foldModel,
        private readonly \Closure $invalidate,
    ) {}

    public function vScroll(): int
    {
        return $this->vScroll;
    }

    public function hScroll(): int
    {
        return $this->hScroll;
    }

    public function contentRows(): int
    {
        return $this->contentRows;
    }

    /** Record the content rows of the last render (viewport minus sticky band). */
    public function setContentRows(int $rows): void
    {
        $this->contentRows = $rows;
    }

    /** Centre the viewport on a line (the stripe-jump target). */
    public function centerOnLine(int $line): void
    {
        $this->vScroll = max(0, $line - intdiv($this->contentRows, 2));
    }

    /** Centre the viewport on the caret line (after a jump). */
    public function centerCursor(): void
    {
        $this->centerOnLine($this->document->getCursorLine());
    }

    /**
     * Scroll the viewport by $delta rows without moving the cursor line
     * relationship (cursor is re-clamped into view on the next render).
     */
    public function scrollBy(int $delta): void
    {
        $doc = $this->document;
        $max = max(0, $doc->getLineCount() - 1);
        $this->vScroll = max(0, min($max, $this->vScroll + $delta));
        // Keep the cursor within the visible window so a later render does
        // not immediately scroll back.
        $line = max($this->vScroll, min($doc->getCursorLine(), $this->vScroll + $this->contentRows - 1));
        if ($line !== $doc->getCursorLine()) {
            $doc->setCursor($line, min($doc->getCursorCol(), \strlen($doc->getLine($line))));
        }
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
        $cursorLine = $this->document->getCursorLine();
        $this->foldModel->revealCaretLine();
        $this->vScroll = $this->foldModel->snapVisible(max(0, min($this->vScroll, $this->document->getLineCount() - 1)));
        for ($iter = 0; $iter <= self::MAX_STICKY_ROWS; ++$iter) {
            $stickyCount = $stickyCountAt($this->vScroll);
            $cursorRow = $this->foldModel->screenRowFor($this->vScroll, $cursorLine);
            if ($cursorRow < 0) {
                $this->vScroll = $this->foldModel->snapVisible($cursorLine);
            } elseif ($cursorRow > ($rows - $stickyCount - 1)) {
                $this->vScroll = $this->foldModel->visibleLineAbove($cursorLine, $rows - $stickyCount - 1);
            } else {
                break; // caret already visible below the pinned band
            }
        }
    }

    /**
     * Shift the horizontal scroll so the caret's display column stays inside
     * the content area, keeping an 8-column margin when scrolling back left.
     * A render with a parked caret ($cursorMoved false) leaves a manual
     * horizontal scroll alone — moving/typing snaps the view back to the caret.
     */
    public function followCursor(int $cursorDisplayCol, int $contentWidth, bool $cursorMoved = true): void
    {
        if (!$cursorMoved) {
            return;
        }
        if ($cursorDisplayCol < $this->hScroll) {
            $this->hScroll = max(0, $cursorDisplayCol - 8);
        } elseif ($cursorDisplayCol > ($this->hScroll + $contentWidth - 1)) {
            $this->hScroll = $cursorDisplayCol - $contentWidth + 1;
        }
    }

    /** Scroll the view horizontally without touching the caret, clamped to [0, $max]. */
    public function scrollHBy(int $cols, int $max): void
    {
        $this->hScroll = max(0, min($this->hScroll + $cols, max(0, $max)));
        ($this->invalidate)();
    }
}
