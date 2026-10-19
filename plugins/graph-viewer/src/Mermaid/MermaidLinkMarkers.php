<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphLayout;

/** Flowchart and block links distinguish point, circular and cross endpoints on either end. */
final class MermaidLinkMarkers
{
    /** @param list<string> $arrows
     * @return \Generator<int, null, mixed, GraphLayout>
     */
    public static function decorate(GraphLayout $graph, array $arrows): \Generator
    {
        $edges = [];
        foreach ($graph->edges as $index => $edge) {
            $arrow = $arrows[$index];
            $left = $arrow[0];
            $right = $arrow[\strlen($arrow) - 1];
            $points = $edge->points;
            $last = \count($points) - 1;
            $tip = $edge->arrowTip;
            if (null !== $tip) {
                $points[$last] = $tip;
            }
            foreach ([[$left, 0, 1], [$right, $last, $last - 1]] as [$kind, $at, $next]) {
                if (!\in_array($kind, ['x', 'o', '<'], strict: true)) {
                    continue;
                }
                \array_push($edges, ...self::marker($edge, $points[$at], $points[$next], $kind));
                if ('<' !== $kind) {
                    $dx = $points[$next][0] - $points[$at][0];
                    $dy = $points[$next][1] - $points[$at][1];
                    $amount = \min(1.6, \hypot($dx, $dy) / 4) / \max(1.0e-9, \hypot($dx, $dy));
                    $points[$at] = [$points[$at][0] + ($dx * $amount), $points[$at][1] + ($dy * $amount)];
                }
            }
            if ('>' === $right) {
                $points[$last] = $edge->points[$last];
            }
            $edges[] = new GraphEdge(
                $edge->tail,
                $edge->head,
                $points,
                '>' === $right ? $tip : null,
                $edge->minX,
                $edge->minY,
                $edge->maxX,
                $edge->maxY,
                $edge->color,
                $edge->label,
                $edge->labelPos,
                $edge->dashed,
                strokeWidth: $edge->strokeWidth,
                textColor: $edge->textColor,
                font: $edge->font,
                fontScale: $edge->fontScale,
                dashes: $edge->dashes,
            );
            yield null;
        }

        return new GraphLayout($graph->width, $graph->height, $graph->nodes, $edges, $graph->clusters);
    }

    /** @param array{float, float} $at
     * @param array{float, float} $next
     * @return list<GraphEdge>
     */
    private static function marker(GraphEdge $edge, array $at, array $next, string $kind): array
    {
        if ('<' === $kind) {
            $dx = $next[0] - $at[0];
            $dy = $next[1] - $at[1];
            $length = \max(1.0e-9, \hypot($dx, $dy));
            $near = [$at[0] + ($dx / $length), $at[1] + ($dy / $length)];
            $base = [$at[0] + ((0.5 * $dx) / $length), $at[1] + ((0.5 * $dy) / $length)];

            return [new GraphEdge(
                $edge->head,
                $edge->tail,
                [$near, $base],
                $at,
                $edge->minX,
                $edge->minY,
                $edge->maxX,
                $edge->maxY,
                $edge->color,
                null,
                null,
                dashed: false,
                strokeWidth: 0.0,
                decoration: true,
            )];
        }
        $dx = $next[0] - $at[0];
        $dy = $next[1] - $at[1];
        $length = \max(1.0e-9, \hypot($dx, $dy));
        $paths = [];
        if ('x' === $kind) {
            $paths = [[[0.0, -0.7], [1.4, 0.7]], [[0.0, 0.7], [1.4, -0.7]]];
        }
        if ('o' === $kind) {
            $circle = [];
            for ($i = 0; $i <= 24; ++$i) {
                $angle = (2 * \M_PI * $i) / 24;
                $circle[] = [0.7 + (0.7 * \cos($angle)), 0.7 * \sin($angle)];
            }
            $paths[] = $circle;
        }
        $markers = [];
        foreach ($paths as $path) {
            $points = [];
            foreach ($path as [$along, $across]) {
                $points[] = [$at[0] + ((($dx * $along) - ($dy * $across)) / $length), $at[1] + ((($dy * $along) + ($dx * $across)) / $length)];
            }
            $markers[] = new GraphEdge(
                $edge->tail,
                $edge->head,
                $points,
                null,
                \min(\array_column($points, 0)),
                \min(\array_column($points, 1)),
                \max(\array_column($points, 0)),
                \max(\array_column($points, 1)),
                $edge->color,
                null,
                null,
                dashed: false,
                strokeWidth: $edge->strokeWidth,
                decoration: true,
            );
        }

        return $markers;
    }
}
