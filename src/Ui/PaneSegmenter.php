<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Container\AsIdeService;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\Region\FloatingRegions;
use Porta\Overlay\State\FloatingLayer;
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
#[AsIdeService]
final readonly class PaneSegmenter
{
    public function __construct(
        private Renderer $renderer,
        private FloatingRegions $dropdowns,
        private FloatingLayer $layer = new FloatingLayer(),
    ) {}

    /**
     * What the floating regions are stamped from: the surfaces' own canvas,
     * one row per screen row. The panes beneath no longer carry them.
     *
     * @return list<string>
     */
    public function surfaces(int $columns, int $rows): array
    {
        return $this->layer->rows($columns, $rows);
    }

    /**
     * The pane runs for every row of the current frame.
     *
     * @return array<int, list<array{start: int, width: int, ltr: bool}>>
     */
    public function segment(ContainerWidget $root, int $columns, int $rows): array
    {
        $leaves = [];
        $this->collectLeaves($root, $leaves);

        $byRow = [];
        for ($row = 0; $row < $rows; ++$row) {
            $byRow[$row] = self::runsForRow($leaves, $row, $columns);
        }

        return $byRow;
    }

    /**
     * The floating regions covering each row — the overlay layer, kept apart
     * from the pane runs above.
     *
     * They used to be merged in, owning their columns outright, and that is
     * what tore the RTL settings screen: a toast over the right of the screen
     * TRUNCATED the pane beneath it, and reordering the shortened span
     * re-right-aligned the pane's whole row, sliding every label ~28 columns
     * left. A floating surface must hide what is under it and move nothing.
     *
     * @return array<int, list<array{start: int, width: int, ltr: bool, box?: array{int, int}}>>
     */
    public function segmentRegions(int $columns, int $rows): array
    {
        $regions = $this->dropdowns->regions();

        $byRow = [];
        for ($row = 0; $row < $rows; ++$row) {
            $byRow[$row] = self::regionRunsForRow($regions, $row, $columns);
        }

        return $byRow;
    }

    /**
     * The floating regions tiling one row, in paint order.
     *
     * @param list<DropdownRegion> $regions topmost last; each says whether it is verbatim
     *
     * @return list<array{start: int, width: int, ltr: bool, box?: array{int, int}}>
     */
    public static function regionRunsForRow(array $regions, int $row, int $columns): array
    {
        $columns = \max(0, $columns);
        $runs = [];
        foreach ($regions as $region) {
            if (!$region->coversRow($row)) {
                continue;
            }
            [$col, $width] = $region->columnsOn($row);
            $start = \max(0, $col);
            $end = \min($columns, $col + $width);
            if ($end <= $start) {
                continue;
            }
            $run = ['start' => $start, 'width' => $end - $start, 'ltr' => $region->ltr];
            // A row narrower than its box (a shadowed box's first row, its
            // band) says where the box is: a verbatim box moves as one block,
            // and each row keeps its place in it.
            $boxStart = \max(0, $region->col);
            $box = [$boxStart, \min($columns, $region->col + $region->width) - $boxStart];
            $runs[] = [$start, $end - $start] === $box ? $run : [...$run, 'box' => $box];
        }

        return $runs;
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
        // A hidden widget is never laid out: the view window's host is one,
        // and the region it holds is drawn floating, not as panes.
        if (null === $widget->getContext() || true === $widget->getStyle()?->getHidden()) {
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
     * (the later leaf), then contiguous same-owner columns are coalesced into
     * runs. Uncovered gaps become verbatim runs.
     *
     * Floating regions are deliberately NOT considered here — see
     * {@see segmentRegions()} for why letting them own columns broke the panes
     * underneath.
     *
     * @param list<array{rect: WidgetRect, ltr: bool}> $leaves paint order
     *
     * @return list<array{start: int, width: int, ltr: bool}>
     */
    public static function runsForRow(array $leaves, int $row, int $columns): array
    {
        $columns = \max(0, $columns);
        /** @var list<int|string> $owner -1 = uncovered gap (verbatim) */
        $owner = \array_fill(0, $columns, -1);
        $ltr = \array_fill(0, $columns, value: true);

        foreach ($leaves as $index => $leaf) {
            $rect = $leaf['rect'];
            if ($row < $rect->row || $row >= ($rect->row + $rect->rows)) {
                continue;
            }
            for ($col = \max(0, $rect->col), $end = \min($columns, $rect->col + $rect->columns); $col < $end; ++$col) {
                $owner[$col] = $index;
                $ltr[$col] = $leaf['ltr'];
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
