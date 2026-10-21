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
 * Scrolling stays in whole diff rows: a row taller than the pane shows its
 * first screenful.
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
     * shows, scrolled to $scroll; null past the last row.
     *
     * @return array{int, int}|null
     */
    public function at(int $scroll, int $visual): ?array
    {
        $at = null;
        $count = \count($this->heights);
        for ($row = $scroll; null === $at && $visual >= 0 && $row < $count; ++$row) {
            if ($visual < $this->heights[$row]) {
                $at = [$row, $visual];
            }
            $visual -= $this->heights[$row];
        }

        return $at;
    }

    /**
     * The scroll that shows $cursor in $visible rows, moving as little from
     * $scroll as it can: up to it when it is above, down until it fits when it
     * is below. A row taller than the pane is shown from its top.
     */
    public function reveal(int $scroll, int $cursor, int $visible): int
    {
        if ($cursor < $scroll) {
            return $cursor;
        }
        $used = \array_sum(\array_slice($this->heights, $scroll, $cursor - $scroll + 1));
        while ($scroll < $cursor && $used > $visible) {
            $used -= $this->heights[$scroll];
            ++$scroll;
        }

        return $scroll;
    }

    /**
     * The furthest scroll that still fills $visible rows: the last rows, as
     * many as fit — or the last row alone, from its top, when it is taller.
     */
    public function maxScroll(int $visible): int
    {
        $count = \count($this->heights);
        $scroll = $count;
        $used = 0;
        while ($scroll > 0 && ($used + $this->heights[$scroll - 1]) <= $visible) {
            --$scroll;
            $used += $this->heights[$scroll];
        }

        return \min($scroll, \max(0, $count - 1));
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
