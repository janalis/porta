<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The primary selection's anchor and the range math over it. Split out of
 * {@see Document} so the buffer class is not also the selection authority: a
 * selection is a cursor-relative overlay (anchor..cursor), not text. The
 * cursor still lives on the Document and is passed in where the range needs it;
 * line operations that move text adjust the anchor through the shift* helpers.
 */
final class SelectionModel
{
    /** @var array{line: int, col: int}|null */
    private ?array $anchor = null;

    /** Anchor at the given position if no selection is active (shift+move start). */
    public function begin(int $line, int $col): void
    {
        $this->anchor ??= ['line' => $line, 'col' => $col];
    }

    public function clear(): void
    {
        $this->anchor = null;
    }

    /** Set an explicit anchor (select-all, structural selection). */
    public function set(int $line, int $col): void
    {
        $this->anchor = ['line' => $line, 'col' => $col];
    }

    /** The anchor's line, or null when no selection is active. */
    public function anchorLine(): ?int
    {
        return $this->anchor['line'] ?? null;
    }

    /** The anchor's column, or null when no selection is active. */
    public function anchorCol(): ?int
    {
        return $this->anchor['col'] ?? null;
    }

    /** Shift the anchor's line when text above/around it moves. No-op when inactive. */
    public function shiftLine(int $delta): void
    {
        if (null !== $this->anchor) {
            $this->anchor['line'] += $delta;
        }
    }

    /** Shift the anchor's column, clamped at the left margin. No-op when inactive. */
    public function shiftColClamped(int $delta): void
    {
        if (null !== $this->anchor) {
            $this->anchor['col'] = \max(0, $this->anchor['col'] + $delta);
        }
    }

    /**
     * The normalized selection range against the given cursor, or null when the
     * selection is empty (no anchor, or anchor === cursor).
     *
     * @return array{startLine: int, startCol: int, endLine: int, endCol: int}|null
     */
    public function range(int $cursorLine, int $cursorCol): ?array
    {
        if (null === $this->anchor) {
            return null;
        }
        /** @var list<int> $a */
        $a = [$this->anchor['line'], $this->anchor['col']];
        /** @var list<int> $b */
        $b = [$cursorLine, $cursorCol];
        if ($a === $b) {
            return null;
        }
        [$start, $end] = $a < $b ? [$a, $b] : [$b, $a];

        return ['startLine' => $start[0], 'startCol' => $start[1], 'endLine' => $end[0], 'endCol' => $end[1]];
    }

    /**
     * The raw anchor for an undo snapshot.
     *
     * @return array{line: int, col: int}|null
     */
    public function snapshot(): ?array
    {
        return $this->anchor;
    }

    /**
     * Restore the anchor from an undo snapshot.
     *
     * @param array{line: int, col: int}|null $anchor
     */
    public function restore(?array $anchor): void
    {
        $this->anchor = $anchor;
    }
}
