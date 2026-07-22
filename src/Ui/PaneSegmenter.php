<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Overlay\DropdownRegion;
use Porta\Overlay\FloatingRegions;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Splits each screen row of a rendered frame into the column runs owned by each
 * pane, for {@see \Porta\I18n\PaneReorder}. A run carries a base direction so a
 * code pane ({@see LtrPane}) is reordered as verbatim while chrome panes flip
 * right to left — strictly within their own column span.
 *
 * The renderer-querying walk is split from the pure column math so the math is
 * unit-testable without a live terminal.
 *
 * @phpstan-type Run array{start: int, width: int, ltr: bool}
 * @phpstan-type Leaf array{rect: WidgetRect, ltr: bool}
 */
final class PaneSegmenter
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly FloatingRegions $dropdowns,
    ) {}

    /**
     * The pane runs for every row of the current frame.
     *
     * @return array<int, list<array{start: int, width: int, ltr: bool}>>
     */
    public function segment(ContainerWidget $root, int $columns, int $rows): array
    {
        $leaves = [];
        $this->collectLeaves($root, $leaves);
        $regions = $this->dropdowns->regions();

        $byRow = [];
        for ($row = 0; $row < $rows; ++$row) {
            $byRow[$row] = self::runsForRow($leaves, $regions, $row, $columns);
        }

        return $byRow;
    }

    /**
     * Collect the leaf widgets and their rects in paint order (later paints over
     * earlier), skipping detached widgets whose rects are stale — mirroring
     * {@see \Porta\Input\HitTester::rect()}.
     *
     * @param list<array{rect: WidgetRect, ltr: bool}> $out
     */
    private function collectLeaves(AbstractWidget $widget, array &$out): void
    {
        if (null === $widget->getContext()) {
            return;
        }
        if ($widget instanceof ContainerWidget) {
            foreach ($widget->all() as $child) {
                $this->collectLeaves($child, $out);
            }

            return;
        }
        $rect = $this->renderer->getWidgetRect($widget);
        if (null !== $rect) {
            $out[] = ['rect' => $rect, 'ltr' => $widget instanceof LtrPane];
        }
    }

    /**
     * The pane runs tiling one row: each column is owned by the topmost pane
     * (later leaf, then any dropdown region on top), then contiguous same-owner
     * columns are coalesced into runs. Uncovered gaps become verbatim runs.
     *
     * @param list<array{rect: WidgetRect, ltr: bool}> $leaves  paint order
     * @param list<DropdownRegion>                     $regions topmost, always RTL
     *
     * @return list<array{start: int, width: int, ltr: bool}>
     */
    public static function runsForRow(array $leaves, array $regions, int $row, int $columns): array
    {
        $columns = max(0, $columns);
        /** @var list<int|string> $owner -1 = uncovered gap (verbatim) */
        $owner = array_fill(0, $columns, -1);
        $ltr = array_fill(0, $columns, value: true);

        foreach ($leaves as $index => $leaf) {
            $rect = $leaf['rect'];
            if ($row < $rect->row || $row >= ($rect->row + $rect->rows)) {
                continue;
            }
            for ($col = max(0, $rect->col), $end = min($columns, $rect->col + $rect->columns); $col < $end; ++$col) {
                $owner[$col] = $index;
                $ltr[$col] = $leaf['ltr'];
            }
        }
        foreach ($regions as $index => $region) {
            if (!$region->coversRow($row)) {
                continue;
            }
            for ($col = max(0, $region->col), $end = min($columns, $region->col + $region->width); $col < $end; ++$col) {
                $owner[$col] = 'd'.$index;
                $ltr[$col] = $region->ltr;
            }
        }

        $runs = [];
        for ($start = 0, $col = 1; $col <= $columns; ++$col) {
            if (!($col === $columns || $owner[$col] !== $owner[$start])) {
                continue;
            }

            $runs[] = ['start' => $start, 'width' => $col - $start, 'ltr' => $ltr[$start]];
            $start = $col;
        }

        return $runs;
    }
}
