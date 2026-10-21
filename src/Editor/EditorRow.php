<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * One content row of the editor, as {@see EditorRowWalk} finds it: the line it
 * belongs to and which of that line's rows it is. A line takes one row per
 * code lens above it, one per wrapped segment of its text, then one per block
 * row under it.
 */
final readonly class EditorRow
{
    public function __construct(
        public int $line,
        /** Code-lens rows above the line's text. */
        public int $lensRows,
        /** Rows the line's own text takes: one, or its wrapped segments. */
        public int $segments,
        /** Which of the line's rows this is, counting from its first lens. */
        public int $inLine,
    ) {}

    /** The text segment this row shows; null on a lens row or a block row. */
    public function segment(): ?int
    {
        $segment = $this->inLine - $this->lensRows;

        return $segment >= 0 && $segment < $this->segments ? $segment : null;
    }
}
