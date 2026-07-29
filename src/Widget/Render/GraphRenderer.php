<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Graph\GraphCluster;
use Porta\Graph\GraphEdge;
use Porta\Graph\GraphLayout;
use Porta\Graph\GraphNode;
use Porta\Image\ZoomPanModel;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;

/**
 * Rasterizes a {@see GraphLayout} into terminal lines — pure statics over
 * passed state (the render-tier rule). World coordinates are points, y-down;
 * the {@see ZoomPanModel} viewport maps 1 pt → `scale` columns × `scale`
 * half-rows (the image viewer's px = half-row convention gives the 2:1 cell
 * aspect correction for free). Draw order: cluster frames → edges (direction
 * glyphs, arrowheads) → nodes (boxed with centered labels, a single `▪` when
 * too small to box) → edge labels where they fit. Everything is culled
 * against the visible world rect (edges via their precomputed bbox), so a
 * several-thousand-node graph costs only its on-screen slice per frame.
 *
 * DOT-attributed colors render as truecolor SGR (they are content, like image
 * pixels — but gated on the palette having color at all); un-attributed
 * elements take theme roles.
 */
final class GraphRenderer
{
    /** @return list<string> exactly $rows lines of exactly $cols display columns */
    public static function render(GraphLayout $graph, ZoomPanModel $view, ?int $selected, int $cols, int $rows, Styles $styles): array
    {
        $grid = new GraphCellGrid($cols, $rows, $view, $styles);
        foreach ($graph->clusters as $cluster) {
            self::drawCluster($grid, $cluster);
        }
        foreach ($graph->edges as $edge) {
            self::drawEdge($grid, $edge);
        }
        foreach ($graph->nodes as $node) {
            self::drawNode($grid, $node, $selected);
        }
        foreach ($graph->edges as $edge) {
            // Arrowheads land ON the target node's border cell (dot clips the
            // spline at the node boundary), so they paint after the boxes.
            self::drawArrowTip($grid, $edge);
            self::drawEdgeLabel($grid, $edge);
        }

        return $grid->lines();
    }

    /** The node under a pane cell (topmost by draw order = last in id order), or null over empty space. */
    public static function nodeAt(GraphLayout $graph, ZoomPanModel $view, int $cols, int $rows, int $relRow, int $relCol): ?int
    {
        $grid = new GraphCellGrid($cols, $rows, $view, null);
        $hit = null;
        foreach ($graph->nodes as $node) {
            [$x0, $y0, $x1, $y1] = $grid->nodeBox($node);
            if ($relCol >= $x0 && $relCol <= $x1 && $relRow >= $y0 && $relRow <= $y1) {
                $hit = $node->id;
            }
        }

        return $hit;
    }

    private static function drawCluster(GraphCellGrid $grid, GraphCluster $cluster): void
    {
        if (!$grid->intersects($cluster->x0, $cluster->y0, $cluster->x1, $cluster->y1)) {
            return;
        }
        $x0 = $grid->colOf($cluster->x0);
        $x1 = $grid->colOf($cluster->x1);
        $y0 = $grid->rowOf($cluster->y0);
        $y1 = $grid->rowOf($cluster->y1);
        if (($x1 - $x0) < 2 || ($y1 - $y0) < 1) {
            return; // too small to frame at this zoom
        }
        $key = $grid->colorKey($cluster->color, Role::Dim);
        $grid->put($x0, $y0, '┌', $key);
        $grid->put($x1, $y0, '┐', $key);
        $grid->put($x0, $y1, '└', $key);
        $grid->put($x1, $y1, '┘', $key);
        for ($x = $x0 + 1; $x < $x1; ++$x) {
            $grid->put($x, $y0, '─', $key);
            $grid->put($x, $y1, '─', $key);
        }
        for ($y = $y0 + 1; $y < $y1; ++$y) {
            $grid->put($x0, $y, '│', $key);
            $grid->put($x1, $y, '│', $key);
        }
        if ('' !== $cluster->label) {
            $grid->text($x0 + 2, $y0, ' '.$cluster->label.' ', $key, $x1 - 1);
        }
    }

    private static function drawEdge(GraphCellGrid $grid, GraphEdge $edge): void
    {
        if (!$grid->intersects($edge->minX, $edge->minY, $edge->maxX, $edge->maxY)) {
            return;
        }
        $key = $grid->colorKey($edge->color, Role::Muted);
        $count = \count($edge->points);
        $dashPeriod = $edge->dashed ? 2 : 1;
        for ($i = 1; $i < $count; ++$i) {
            $grid->segment($edge->points[$i - 1], $edge->points[$i], $key, $dashPeriod);
        }
    }

    private static function drawArrowTip(GraphCellGrid $grid, GraphEdge $edge): void
    {
        if (null === $edge->arrowTip || !$grid->intersects($edge->minX, $edge->minY, $edge->maxX, $edge->maxY)) {
            return;
        }
        $last = $edge->points[\count($edge->points) - 1];
        $dx = $edge->arrowTip[0] - $last[0];
        $dy = $edge->arrowTip[1] - $last[1];
        $glyph = match (true) {
            abs($dx) >= abs($dy) && $dx >= 0 => '▸',
            abs($dx) >= abs($dy) => '◂',
            $dy >= 0 => '▾',
            default => '▴',
        };
        $grid->put($grid->colOf($edge->arrowTip[0]), $grid->rowOf($edge->arrowTip[1]), $glyph, $grid->colorKey($edge->color, Role::Muted));
    }

    private static function drawEdgeLabel(GraphCellGrid $grid, GraphEdge $edge): void
    {
        if (null === $edge->label || null === $edge->labelPos || !$grid->intersects($edge->minX, $edge->minY, $edge->maxX, $edge->maxY)) {
            return;
        }
        $label = explode("\n", $edge->label)[0];
        if ('' === $label) {
            return;
        }
        $col = $grid->colOf($edge->labelPos[0]) - intdiv(mb_strlen($label), num2: 2);
        $grid->text($col, $grid->rowOf($edge->labelPos[1]), $label, $grid->colorKey($edge->color, Role::Muted), null);
    }

    private static function drawNode(GraphCellGrid $grid, GraphNode $node, ?int $selected): void
    {
        if (!$grid->intersects($node->x - ($node->w / 2), $node->y - ($node->h / 2), $node->x + ($node->w / 2), $node->y + ($node->h / 2))) {
            return;
        }
        [$x0, $y0, $x1, $y1] = $grid->nodeBox($node);
        $key = $node->id === $selected ? $grid->roleKey(Role::SelectedRow) : $grid->colorKey($node->color, Role::Bright);
        if (($x1 - $x0) < 2 || ($y1 - $y0) < 1) {
            $grid->put(intdiv($x0 + $x1, num2: 2), intdiv($y0 + $y1, num2: 2), '▪', $key);

            return;
        }
        $grid->put($x0, $y0, '┌', $key);
        $grid->put($x1, $y0, '┐', $key);
        $grid->put($x0, $y1, '└', $key);
        $grid->put($x1, $y1, '┘', $key);
        $horizontal = $node->dashed ? '╌' : '─';
        $vertical = $node->dashed ? '╎' : '│';
        for ($x = $x0 + 1; $x < $x1; ++$x) {
            $grid->put($x, $y0, $horizontal, $key);
            $grid->put($x, $y1, $horizontal, $key);
        }
        for ($y = $y0 + 1; $y < $y1; ++$y) {
            $grid->put($x0, $y, $vertical, $key);
            $grid->put($x1, $y, $vertical, $key);
            for ($x = $x0 + 1; $x < $x1; ++$x) {
                $grid->put($x, $y, ' ', $key); // blank the interior so edges never show through a node
            }
        }
        $labelLines = explode("\n", $node->label);
        $innerRows = $y1 - $y0 - 1;
        $labelLines = \array_slice($labelLines, offset: 0, length: max(1, $innerRows));
        $firstRow = $y0 + 1 + intdiv(max(0, $innerRows - \count($labelLines)), num2: 2);
        foreach ($labelLines as $index => $line) {
            $width = $x1 - $x0 - 1;
            if (mb_strlen($line) > $width) {
                $line = $width > 1 ? mb_substr($line, start: 0, length: $width - 1).'…' : '…';
            }
            $col = $x0 + 1 + intdiv(max(0, $width - mb_strlen($line)), num2: 2);
            $grid->text($col, $firstRow + $index, $line, $key, $x1 - 1);
        }
    }
}
