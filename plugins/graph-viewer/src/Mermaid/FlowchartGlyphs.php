<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphNode;

/** Modern shapes use the same authored paints for outlines and their internal symbols. */
final class FlowchartGlyphs
{
    /** @return list<MermaidMark> */
    public static function marks(GraphNode $node, string $shape): array
    {
        return match (true) {
            \in_array($shape, ['st-rect', 'docs'], strict: true) => self::stack($node, $shape),
            \in_array($shape, ['brace', 'brace-r', 'braces', 'datastore'], strict: true) => self::open($node, $shape),
            default => self::closed($node, $shape),
        };
    }

    /** @return list<MermaidMark> */
    private static function closed(GraphNode $node, string $shape): array
    {
        $fill = \in_array($shape, ['sm-circ', 'f-circ', 'fork'], strict: true) ? '#333333' : '#ececff';
        $marks = [new MermaidMark(
            'polygon',
            FlowchartShapeGeometry::points($node, $shape),
            stroke: $node->color ?? '#333333',
            fill: $node->fillColor ?? $fill,
            dashed: $node->dashed,
            strokeWidth: $node->strokeWidth,
            dashes: $node->dashes,
        )];
        \array_push($marks, ...self::details($node, $shape));

        return $marks;
    }

    /** @return list<MermaidMark> */
    private static function details(GraphNode $node, string $shape): array
    {
        $paths = match ($shape) {
            'lin-rect' => [[[0.08, 0.0], [0.08, 1.0]], [[0.12, 0.0], [0.12, 1.0]]],
            'div-rect' => [[[0.0, 0.78], [1.0, 0.78]]],
            'win-pane' => [[[0.0, 0.18], [1.0, 0.18]], [[0.12, 0.0], [0.12, 1.0]]],
            'cross-circ' => [[[0.15, 0.15], [0.85, 0.85]], [[0.15, 0.85], [0.85, 0.15]]],
            'tag-rect', 'tag-doc' => [[[0.72, 0.0], [1.0, 0.28], [0.72, 0.28], [0.72, 0.0]]],
            'h-cyl' => [FlowchartShapeGeometry::arc(FlowchartShapeGeometry::cap($node) / 2, 0.5, FlowchartShapeGeometry::cap($node) / 2, 0.5, -90, 360)],
            'lin-cyl' => [
                FlowchartShapeGeometry::arc(0.5, 0.1, 0.5, 0.1, 0, 180),
                FlowchartShapeGeometry::arc(0.5, 0.24, 0.5, 0.1, 0, 180),
                FlowchartShapeGeometry::arc(0.5, 0.38, 0.5, 0.1, 0, 180),
            ],
            'bucket' => [FlowchartShapeGeometry::arc(0.5, 0.12, 0.5, 0.12, 0, 360)],
            'browser' => [[[0.0, 0.22], [1.0, 0.22]], [[0.1, 0.1], [0.12, 0.1]], [[0.18, 0.1], [0.2, 0.1]], [[0.26, 0.1], [0.28, 0.1]]],
            'console' => [[[0.1, 0.1], [0.17, 0.16], [0.1, 0.22]], [[0.2, 0.22], [0.3, 0.22]]],
            default => [],
        };
        $marks = \array_map(static fn (array $path): MermaidMark => self::line($node, $path), $paths);
        if ('fr-circ' === $shape) {
            $marks[] = new MermaidMark(
                'polygon',
                FlowchartShapeGeometry::project($node, FlowchartShapeGeometry::arc(0.5, 0.5, 0.3, 0.3, 0, 360)),
                stroke: $node->color ?? '#333333',
                fill: $node->fillColor ?? '#333333',
                dashed: $node->dashed,
                strokeWidth: $node->strokeWidth,
                dashes: $node->dashes,
            );
        }

        return $marks;
    }

    /** @param list<array{float, float}> $points */
    private static function line(GraphNode $node, array $points): MermaidMark
    {
        return new MermaidMark(
            'polyline',
            FlowchartShapeGeometry::project($node, $points),
            stroke: $node->color ?? '#333333',
            fill: 'none',
            dashed: $node->dashed,
            strokeWidth: $node->strokeWidth,
            dashes: $node->dashes,
        );
    }

    /** @return list<MermaidMark> */
    private static function open(GraphNode $node, string $shape): array
    {
        if ('datastore' === $shape) {
            return [self::line($node, [[0.0, 0.0], [1.0, 0.0]]), self::line($node, [[0.0, 1.0], [1.0, 1.0]])];
        }
        /** @var list<array{float, float}> $points */
        $points = [
            [0.12, 0.0],
            [0.095, 0.005],
            [0.075, 0.02],
            [0.065, 0.04],
            [0.06, 0.08],
            [0.06, 0.35],
            [0.055, 0.4],
            [0.04, 0.45],
            [0.0, 0.5],
            [0.04, 0.55],
            [0.055, 0.6],
            [0.06, 0.65],
            [0.06, 0.92],
            [0.065, 0.96],
            [0.075, 0.98],
            [0.095, 0.995],
            [0.12, 1.0],
        ];
        $right = \array_map(static fn (array $point): array => [1 - $point[0], $point[1]], $points);

        return match ($shape) {
            'brace' => [self::line($node, $points)],
            'brace-r' => [self::line($node, $right)],
            default => [self::line($node, $points), self::line($node, $right)],
        };
    }

    /** @return list<MermaidMark> */
    private static function stack(GraphNode $node, string $shape): array
    {
        $marks = [];
        $points = FlowchartShapeGeometry::points($node, 'docs' === $shape ? 'doc' : 'rect');
        $left = $node->x - ($node->w / 2);
        $top = $node->y - ($node->h / 2);
        for ($index = 2; $index >= 0; --$index) {
            $dx = 0.05 * $index * $node->w;
            $dy = 0.05 * (2 - $index) * $node->h;
            $layer = \array_map(static fn (array $point): array => [$left + (($point[0] - $left) * 0.9) + $dx, $top + (($point[1] - $top) * 0.9) + $dy], $points);
            $marks[] = new MermaidMark(
                'polygon',
                $layer,
                stroke: $node->color ?? '#333333',
                fill: $node->fillColor ?? '#ececff',
                dashed: $node->dashed,
                strokeWidth: $node->strokeWidth,
                dashes: $node->dashes,
            );
        }

        return $marks;
    }
}
