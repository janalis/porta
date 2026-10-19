<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphNode;

/** People and queues retain distinct silhouettes alongside the shared database cylinder. */
final class C4Symbols
{
    /** @return list<GraphEdge> */
    public static function frame(GraphCluster $frame, string $macro, int $id): array
    {
        $dx = 'Node_L' === $macro ? -2.0 : 2.0;
        $side = $dx < 0 ? $frame->x0 : $frame->x1;
        $lines = [
            [[$frame->x0, $frame->y0], [$frame->x0 + $dx, $frame->y0 - 2], [$frame->x1 + $dx, $frame->y0 - 2], [$frame->x1, $frame->y0]],
            [[$side + $dx, $frame->y0 - 2], [$side + $dx, $frame->y1 - 2], [$side, $frame->y1]],
        ];
        $edges = [];
        foreach ($lines as $points) {
            $edges[] = new GraphEdge(
                tail: $id,
                head: $id,
                points: $points,
                arrowTip: null,
                minX: \min(\array_column($points, 0)),
                minY: \min(\array_column($points, 1)),
                maxX: \max(\array_column($points, 0)),
                maxY: \max(\array_column($points, 1)),
                color: $frame->color ?? '#444444',
                label: null,
                labelPos: null,
                dashed: false,
                decoration: true,
            );
        }

        return $edges;
    }

    /** @return list<MermaidMark> */
    public static function person(GraphNode $node): array
    {
        $left = $node->x - ($node->w / 2);
        $right = $node->x + ($node->w / 2);
        $top = $node->y - ($node->h / 2);
        $bottom = $node->y + ($node->h / 2);

        return [
            new MermaidMark(
                'polygon',
                [[$left + 0.8, $top + 4], [$right - 0.8, $top + 4], [$right, $top + 4.8], [$right, $bottom], [$left, $bottom], [$left, $top + 4.8]],
                stroke: $node->color ?? '#333333',
                fill: $node->fillColor ?? '#08427b',
                strokeWidth: $node->strokeWidth,
            ),
            new MermaidMark('polygon', self::ellipse($node->x, $top + 2, rx: 1.7, ry: 1.7), stroke: $node->color ?? '#333333', fill: $node->fillColor ?? '#08427b', strokeWidth: $node->strokeWidth),
        ];
    }

    /** @return list<MermaidMark> */
    public static function queue(GraphNode $node): array
    {
        $left = $node->x - ($node->w / 2);
        $right = $node->x + ($node->w / 2);
        $top = $node->y - ($node->h / 2);
        $bottom = $node->y + ($node->h / 2);

        return [
            new MermaidMark(
                'polygon',
                [[$left + 1, $top], [$right - 1, $top], [$right - 1, $bottom], [$left + 1, $bottom]],
                stroke: $node->color ?? '#333333',
                fill: $node->fillColor ?? '#438dd5',
                strokeWidth: $node->strokeWidth,
            ),
            new MermaidMark(
                'polygon',
                self::ellipse($left + 1, $node->y, rx: 1.0, ry: $node->h / 2),
                stroke: $node->color ?? '#333333',
                fill: $node->fillColor ?? '#438dd5',
                strokeWidth: $node->strokeWidth,
            ),
            new MermaidMark(
                'polygon',
                self::ellipse($right - 1, $node->y, rx: 1.0, ry: $node->h / 2),
                stroke: $node->color ?? '#333333',
                fill: $node->fillColor ?? '#438dd5',
                strokeWidth: $node->strokeWidth,
            ),
        ];
    }

    /** @return list<array{float, float}> */
    private static function ellipse(float $x, float $y, float $rx, float $ry): array
    {
        $points = [];
        for ($i = 0; $i <= 32; ++$i) {
            $angle = (2 * \M_PI * $i) / 32;
            $points[] = [$x + ($rx * \cos($angle)), $y + ($ry * \sin($angle))];
        }

        return $points;
    }
}
