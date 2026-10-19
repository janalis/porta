<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphNode;

/** Transition lanes stay outside domain contents; direction terminates at the target header. */
final class CynefinRoutes
{
    public static function edge(GraphNode $from, GraphNode $to, float $laneY): GraphEdge
    {
        $fromSide = $from->x <= 110 ? -1 : 1;
        $toSide = $to->x <= 110 ? -1 : 1;
        $start = [$from->x + (($fromSide * $from->w) / 2), $from->y];
        $end = [$to->x + (($toSide * $to->w) / 2), $to->y];
        $fromX = $fromSide < 0 ? 2.0 : 218.0;
        $toX = $toSide < 0 ? 2.0 : 218.0;
        $points = [$start, [$fromX, $from->y], [$fromX, $laneY]];
        if ($fromX === $toX) {
            $other = 2.0 === $fromX ? 218.0 : 2.0;
            $points[] = [$other, $laneY];
            $points[] = [$other, $laneY + 2];
            $points[] = [$toX, $laneY + 2];
        }
        if ($fromX !== $toX) {
            $points[] = [$toX, $laneY];
        }
        $points[] = [$toX, $to->y];
        $points[] = $end;
        $xs = \array_column($points, column_key: 0);
        $ys = \array_column($points, column_key: 1);

        return new GraphEdge($from->id, $to->id, $points, $end, \min($xs), \min($ys), \max($xs), \max($ys), '@cynefin.arrowColor', null, null, dashed: false);
    }
}
