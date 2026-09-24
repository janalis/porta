<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The completion popup's last-rendered box geometry (editor-relative), handed
 * back by the renderer so mouse clicks and hovers can be hit-tested against
 * what is actually on screen.
 */
final readonly class CompletionBox
{
    public function __construct(
        public int $col,
        public int $width,
        public int $startRow,
        public int $count,
        public int $windowOffset,
        /**
         * The docblock pane beside the list, as [top, col, width, height] in
         * the same coordinates, or null when none is drawn.
         *
         * @var array{int, int, int, int}|null
         */
        public ?array $doc = null,
        /** How many wrapped rows the docblock can scroll before its last row shows. */
        public int $docMaxScroll = 0,
    ) {}

    /**
     * Whether a cell is on the popup at all — its frame or its docblock pane —
     * so a click there is the popup's rather than the code's beneath it.
     */
    public function covers(int $relRow, int $relCol): bool
    {
        $inList = $relRow >= ($this->startRow - 1) && $relRow <= ($this->startRow + $this->count) && $relCol >= ($this->col - 1) && $relCol <= ($this->col + $this->width);

        return $inList || $this->onDoc($relRow, $relCol);
    }

    /** Whether a cell is on the docblock pane, frame included. */
    public function onDoc(int $relRow, int $relCol): bool
    {
        if (null === $this->doc) {
            return false;
        }
        [$top, $col, $width, $height] = $this->doc;

        return $relRow >= $top && $relRow < ($top + $height) && $relCol >= $col && $relCol < ($col + $width);
    }

    /** Filtered-completion index at a widget-relative cell, or null when outside the box. */
    public function itemAt(int $relRow, int $relCol): ?int
    {
        if ($relCol < $this->col || $relCol >= ($this->col + $this->width)) {
            return null;
        }
        $i = $relRow - $this->startRow;

        return $i >= 0 && $i < $this->count ? $this->windowOffset + $i : null;
    }
}
