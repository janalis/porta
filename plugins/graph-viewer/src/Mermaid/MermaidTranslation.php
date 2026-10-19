<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphLayout;

/** Geometry translations move outlines, captions, markers and hit targets together. */
final class MermaidTranslation
{
    /** @return \Generator<int, null, mixed, GraphLayout> */
    public static function graph(GraphLayout $graph, float $horizontal, float $shift): \Generator
    {
        $nodes = [];
        foreach ($graph->nodes as $id => $node) {
            $nodes[$id] = $node->at($node->x + $horizontal, $node->y + $shift);
            yield null;
        }
        $edges = [];
        foreach ($graph->edges as $edge) {
            $edges[] = new GraphEdge(
                $edge->tail,
                $edge->head,
                self::points($edge->points, $horizontal, $shift),
                self::point($edge->arrowTip, $horizontal, $shift),
                $edge->minX + $horizontal,
                $edge->minY + $shift,
                $edge->maxX + $horizontal,
                $edge->maxY + $shift,
                $edge->color,
                $edge->label,
                self::point($edge->labelPos, $horizontal, $shift),
                $edge->dashed,
                null === $edge->ribbon ? null : self::points($edge->ribbon, $horizontal, $shift),
                $edge->strokeWidth,
                $edge->textColor,
                $edge->decoration,
                $edge->font,
                $edge->fontScale,
                gradient: $edge->gradient,
                dashes: $edge->dashes,
            );
            yield null;
        }
        $clusters = [];
        foreach ($graph->clusters as $cluster) {
            $clusters[] = new GraphCluster(
                $cluster->name,
                $cluster->label,
                $cluster->x0 + $horizontal,
                $cluster->y0 + $shift,
                $cluster->x1 + $horizontal,
                $cluster->y1 + $shift,
                $cluster->color,
                self::point($cluster->labelPos, $horizontal, $shift),
                $cluster->fillColor,
                $cluster->textColor,
                $cluster->strokeWidth,
                $cluster->dashed,
                $cluster->font,
                $cluster->fontScale,
                dashes: $cluster->dashes,
            );
            yield null;
        }

        return new GraphLayout($graph->width + $horizontal, $graph->height + $shift, $nodes, $edges, $clusters);
    }

    /** @param list<array{float, float}> $points
     * @return list<array{float, float}>
     */
    private static function points(array $points, float $horizontal, float $shift): array
    {
        return \array_map(static fn (array $point): array => [$point[0] + $horizontal, $point[1] + $shift], $points);
    }

    /** @param ?array{float, float} $point
     * @return ?array{float, float}
     */
    private static function point(?array $point, float $horizontal, float $shift): ?array
    {
        return null === $point ? null : [$point[0] + $horizontal, $point[1] + $shift];
    }
}
