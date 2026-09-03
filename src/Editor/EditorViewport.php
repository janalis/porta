<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The render-produced viewport geometry that mouse hit-testing needs to map an
 * editor-relative click cell to a document position. The widget snapshots this
 * from its last-render fields and passes it to {@see EditorMouse} per call, so
 * the collaborator never reaches back into the widget for viewport state.
 */
final readonly class EditorViewport
{
    /** @param list<int> $stickyLines pinned sticky-scroll header lines occupying the top rows */
    public function __construct(
        public int $vScroll,
        public int $hScroll,
        public int $gutterWidth,
        /** Display column of the gutter's fold marker; negative when no gutter is drawn. */
        public int $foldCol,
        public int $stripeCol,
        public int $renderRows,
        public array $stickyLines,
        /**
         * The width of one visual row while soft wrap is on; null when it is
         * off. Hit-testing needs it for the same reason caret motion does — a
         * screen row is a SEGMENT then, not a line.
         */
        public ?int $wrapWidth = null,
        /** Rows of the top line hidden above the viewport; 0 unless wrapping. */
        public int $vScrollSegment = 0,
    ) {}
}
