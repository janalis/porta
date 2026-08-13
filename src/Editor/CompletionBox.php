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
    ) {}

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
