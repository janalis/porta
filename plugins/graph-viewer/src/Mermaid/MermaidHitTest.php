<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Janalis\Viz\Mermaid\MermaidOutline;
use Janalis\Viz\Mermaid\MermaidScene;
use Porta\GraphViewer\Render\GraphCellGrid;
use Porta\GraphViewer\Render\GraphRenderer;
use Porta\ImageViewer\ZoomPanModel;

/** Which element of a native scene a terminal cell shows, through the pane's zoom and pan. */
final class MermaidHitTest
{
    public static function nodeAt(MermaidScene $scene, ZoomPanModel $view, int $cols, int $rows, int $row, int $col): ?int
    {
        $rect = $view->visibleRect($cols, 2 * $rows);
        $scale = $view->effectiveScale($cols, 2 * $rows);
        $x = $rect['srcX'] + (($col + 0.5 - $rect['padCols']) / $scale);
        $y = $rect['srcY'] + (((2 * $row) + 1 - $rect['padHalfRows']) / $scale);

        $id = MermaidOutline::pieAt($scene, $x, $y) ?? self::markerAt($scene, $view, $cols, $rows, $row, $col) ?? GraphRenderer::nodeShownAt($scene->selectionGraph, $view, $cols, $rows, $row, $col);
        if (null === $id) {
            return null;
        }

        return 'point' === ($scene->shapes[$id] ?? 'rect') || MermaidOutline::contains($scene->graph->nodes[$id], $scene->shapes[$id] ?? 'rect', $x, $y) ? $id : null;
    }

    private static function markerAt(MermaidScene $scene, ZoomPanModel $view, int $cols, int $rows, int $row, int $col): ?int
    {
        $grid = new GraphCellGrid($cols, $rows, $view, null);
        $hit = null;
        $most = 0.0;
        foreach ($scene->selectionGraph->nodes as $node) {
            if ('point' !== ($scene->shapes[$node->id] ?? 'rect')) {
                continue;
            }
            $coverage = $grid->coverage($node, $col, $row);
            if ($coverage > 0.0 && $coverage >= $most) {
                [$hit, $most] = [$node->id, $coverage];
            }
        }

        return $hit;
    }
}
