<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * One caret with its selection anchor. A bare point (no selection) has its
 * anchor coincident with the cursor (`anchorLine === line && anchorCol === col`);
 * otherwise the anchor is the fixed end of the selection. Used for the secondary
 * carets in {@see MultiCaret} and, via the caret hooks, threaded through the undo
 * snapshot and the renderer. Replaces the former array{line, col, anchorLine,
 * anchorCol} shape.
 */
final readonly class Caret
{
    public function __construct(
        public int $line,
        public int $col,
        public int $anchorLine,
        public int $anchorCol,
    ) {}

    /** A bare point caret at ($line, $col) with no selection. */
    public static function point(int $line, int $col): self
    {
        return new self($line, $col, $line, $col);
    }

    /** Whether the caret spans a real selection (its anchor differs from the cursor). */
    public function hasSelection(): bool
    {
        return $this->anchorLine !== $this->line || $this->anchorCol !== $this->col;
    }

    /**
     * The selection in document order — [startLine, startCol, endLine, endCol],
     * anchor and cursor swapped as needed. A point caret yields a zero-width
     * range at its own position.
     *
     * @return array{int, int, int, int}
     */
    public function orderedRange(): array
    {
        $a = [$this->anchorLine, $this->anchorCol];
        $b = [$this->line, $this->col];
        [$start, $end] = $a <= $b ? [$a, $b] : [$b, $a];

        return [$start[0], $start[1], $end[0], $end[1]];
    }
}
