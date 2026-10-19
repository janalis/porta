<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphLayout;

/** Orthogonal regions have transparent frames and dashed separators inside their composite. */
final class StateRegions
{
    /** @return \Generator<int, null, mixed, GraphLayout> */
    public static function layout(GraphLayout $graph): \Generator
    {
        $clusters = [];
        $regions = [];
        foreach ($graph->clusters as $cluster) {
            if (!\str_starts_with($cluster->name, '@region:')) {
                $clusters[] = $cluster;
                yield null;
                continue;
            }
            $index = (int) \substr($cluster->name, (int) \strrpos($cluster->name, needle: ':') + 1);
            $parent = \substr($cluster->name, offset: 8, length: (int) \strrpos($cluster->name, needle: ':') - 8);
            $regions[$parent][$index] = $cluster;
            $clusters[] = new GraphCluster($cluster->name, '', $cluster->x0, $cluster->y0, $cluster->x1, $cluster->y1, 'none', null, fillColor: 'none');
            yield null;
        }
        $edges = $graph->edges;
        foreach ($regions as $parts) {
            \ksort($parts);
            $previous = null;
            foreach ($parts as $part) {
                if (null !== $previous) {
                    $edges[] = self::divider($previous, $part);
                }
                $previous = $part;
                yield null;
            }
        }

        return new GraphLayout($graph->width, $graph->height, $graph->nodes, $edges, $clusters);
    }

    private static function divider(GraphCluster $a, GraphCluster $b): GraphEdge
    {
        $horizontal = \abs($a->x0 + $a->x1 - ($b->x0 + $b->x1)) > \abs($a->y0 + $a->y1 - ($b->y0 + $b->y1));
        $x = $a->x0 <= $b->x0 ? ($a->x1 + $b->x0) / 2 : ($b->x1 + $a->x0) / 2;
        $y = $a->y0 <= $b->y0 ? ($a->y1 + $b->y0) / 2 : ($b->y1 + $a->y0) / 2;
        $points = $horizontal
            ? [[$x, \min($a->y0, $b->y0)], [$x, \max($a->y1, $b->y1)]]
            : [[\min($a->x0, $b->x0), $y], [\max($a->x1, $b->x1), $y]];

        return new GraphEdge(
            0,
            0,
            $points,
            null,
            \min(\array_column($points, 0)),
            \min(\array_column($points, 1)),
            \max(\array_column($points, 0)),
            \max(\array_column($points, 1)),
            '@stateBorder|nodeBorder',
            null,
            null,
            dashed: true,
            decoration: true,
        );
    }
}
