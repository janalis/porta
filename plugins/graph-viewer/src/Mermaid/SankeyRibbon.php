<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;

/** A bounded smooth horizontal band and the centerline used by the terminal cell renderer. */
final class SankeyRibbon
{
    public static function edge(int $source, int $target, float $x0, float $y0, float $x1, float $y1, float $width, string $color): GraphEdge
    {
        $upper = [];
        $lower = [];
        $points = [];
        for ($step = 0; $step <= 16; ++$step) {
            $t = $step / 16;
            $x = $x0 + (($x1 - $x0) * $t * (1.5 - (1.5 * $t) + ($t * $t)));
            $y = $y0 + (($y1 - $y0) * $t * $t * (3 - (2 * $t)));
            $points[] = [$x, $y];
            $upper[] = [$x, $y - ($width / 2)];
            $lower[] = [$x, $y + ($width / 2)];
        }
        $polygon = [...$upper, ...\array_reverse($lower)];

        return new GraphEdge($source, $target, $points, null, $x0, \min(\array_column($polygon, 1)), $x1, \max(\array_column($polygon, 1)), $color, null, null, dashed: false, ribbon: $polygon);
    }

    /** A gradient link: the source's color into the target's, both at the links' 50% opacity. */
    public static function gradient(GraphEdge $ribbon, string $from, string $to): GraphEdge
    {
        return new GraphEdge(
            $ribbon->tail,
            $ribbon->head,
            $ribbon->points,
            null,
            $ribbon->minX,
            $ribbon->minY,
            $ribbon->maxX,
            $ribbon->maxY,
            $ribbon->color,
            null,
            null,
            dashed: false,
            ribbon: $ribbon->ribbon,
            gradient: [\substr($from, offset: 0, length: 7).'80', \substr($to, offset: 0, length: 7).'80'],
        );
    }
}
