<?php

declare(strict_types=1);

namespace Porta\DiffPlugin\Widget;

use Porta\DiffPlugin\DiffDocument;
use Porta\DiffPlugin\DiffRowKind;
use Porta\Editor\WrapLayout;

/**
 * How many screen rows each diff row takes, and the walks the pane makes over
 * them: which row a screen row shows, how far to scroll to show the cursor,
 * where scrolling ends, how many rows a page holds.
 *
 * Every height is one until soft wrap breaks a long line, and with all of them
 * one each walk is the plain arithmetic the pane did before wrap existed — one
 * code path for both, so the unwrapped pane cannot drift from the wrapped one.
 * The pane's scroll is a diff row and how many of its screen rows are
 * scrolled away, as the editor's is under wrap: a row taller than the pane
 * scrolls through, and none of its text is out of reach.
 *
 * @phpstan-import-type Row from DiffViewRenderer
 */
final readonly class DiffRowHeights
{
    /** @param list<int> $heights screen rows per diff row, each at least one */
    private function __construct(
        private array $heights,
    ) {}

    /**
     * The heights of $rows at $width: a hunk header one row, a line as many
     * as its text breaks into at its column's body width, a side-by-side pair
     * the taller of its two halves. Unwrapped, or with no diff shown, all ones.
     *
     * @param list<Row> $rows
     */
    // @mago-expect lint:no-boolean-flag-parameter -- wrap is the setting this answers for, not a second job.
    public static function of(?DiffDocument $document, array $rows, int $width, bool $wrap): self
    {
        if (!$wrap || null === $document) {
            return new self(\array_fill(0, \count($rows), value: 1));
        }
        $heights = [];
        foreach ($rows as $row) {
            $heights[] = match ($row['kind']) {
                DiffRowKind::Hunk => 1,
                DiffRowKind::Line => self::lineRows($document, $row, null, $width),
                DiffRowKind::Pair => \max(self::lineRows($document, $row, DiffColumn::Old, $width), self::lineRows($document, $row, DiffColumn::New, $width)),
            };
        }

        return new self($heights);
    }

    /** @param Row $row */
    private static function lineRows(DiffDocument $document, array $row, ?DiffColumn $column, int $width): int
    {
        $line = DiffViewRenderer::lineAtRow($document, $row, $column);

        return null === $line ? 1 : WrapLayout::rowsForWidth(DiffViewRenderer::columns($line->text), DiffGeometry::bodyWidth($column, $width));
    }

    /** Screen rows diff row $row takes; one past the end. */
    public function height(int $row): int
    {
        return $this->heights[$row] ?? 1;
    }

    /**
     * The diff row and which of its screen rows the pane's $visual-th body row
     * shows, scrolled to $segment rows into row $scroll; null past the last row.
     *
     * @return array{int, int}|null
     */
    public function at(int $scroll, int $segment, int $visual): ?array
    {
        $offset = $this->offset($scroll) + $segment + $visual;

        return $visual >= 0 && $offset < \array_sum($this->heights) ? $this->position($offset) : null;
    }

    /**
     * The scroll that shows $cursor in $visible rows, moving as little from
     * $scroll/$segment as it can: up to its top when it starts above, down
     * until its last row shows when it ends below. A row taller than the pane
     * is shown from its top.
     *
     * @return array{int, int} the diff row on top, and its screen rows scrolled away
     */
    public function reveal(int $scroll, int $segment, int $cursor, int $visible): array
    {
        $top = $this->offset($scroll) + $segment;
        $cursorTop = $this->offset($cursor);
        $cursorBottom = $cursorTop + $this->height($cursor);

        return $this->position(match (true) {
            $cursorTop < $top => $cursorTop,
            $cursorBottom > ($top + $visible) => \min($cursorTop, $cursorBottom - $visible),
            default => $top,
        });
    }

    /**
     * $scroll/$segment moved $by screen rows, held between the top and the
     * furthest scroll that still fills $visible rows — so the last row's last
     * screen row is reachable however tall it is.
     *
     * @return array{int, int} the diff row on top, and its screen rows scrolled away
     */
    public function scrolled(int $scroll, int $segment, int $by, int $visible): array
    {
        $total = \array_sum($this->heights);

        return $this->position(\max(0, \min($this->offset($scroll) + $segment + $by, $total - $visible)));
    }

    /** The screen rows above diff row $row. */
    public function offset(int $row): int
    {
        return \array_sum(\array_slice($this->heights, offset: 0, length: \max(0, $row)));
    }

    /**
     * The diff row screen row $offset falls in, and how far into it.
     *
     * @return array{int, int}
     */
    private function position(int $offset): array
    {
        $row = 0;
        $count = \count($this->heights);
        while ($row < ($count - 1) && $offset >= $this->heights[$row]) {
            $offset -= $this->heights[$row];
            ++$row;
        }

        return [$row, \min($offset, $this->height($row) - 1)];
    }

    /**
     * How many diff rows from $scroll fit in $visible rows, at least one — a
     * page. Rows past the end count one each, as blank screen rows do.
     */
    public function fitting(int $scroll, int $visible): int
    {
        $fit = 0;
        $used = 0;
        while (($used + ($this->heights[$scroll + $fit] ?? 1)) <= $visible) {
            $used += $this->heights[$scroll + $fit] ?? 1;
            ++$fit;
        }

        return \max(1, $fit);
    }
}
