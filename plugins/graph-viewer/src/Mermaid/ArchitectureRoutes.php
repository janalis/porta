<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Orthogonal links leave the authored glyph/frame side before bending toward the destination. */
final class ArchitectureRoutes
{
    /** @return \Generator<int, null, mixed, GraphLayout> */
    public static function layout(ArchitectureDiagram $source, GraphLayout $graph): \Generator
    {
        $nodes = \array_column($graph->nodes, null, 'name');
        $frames = \array_column($graph->clusters, null, 'name');
        $edges = [];
        $arrows = [];
        foreach ($source->edges as $edge) {
            $from = $nodes[$edge['lhsId']];
            $to = $nodes[$edge['rhsId']];
            [$start, $exit] = self::port($source, $from, $edge['lhsDir'], $edge['lhsGroup'] ? $frames : []);
            [$end, $entry] = self::port($source, $to, $edge['rhsDir'], $edge['rhsGroup'] ? $frames : []);
            $points = self::path($start, $exit, $end, $entry, $edge['lhsDir'], $edge['rhsDir']);
            if ($from->id === $to->id) {
                $right = $from->x + ($from->w / 2) + 6;
                $top = $from->y - ($from->h / 2) - 6;
                $points = [$start, $exit, [$right, $exit[1]], [$right, $top], [$entry[0], $top], $entry, $end];
            }
            $points = self::distinct($points);
            $tip = $edge['rhsInto'] ? $end : null;
            $last = \count($points) - 1;
            if (null !== $tip) {
                $near = $points[$last - 1];
                $length = \max(1.0e-9, \hypot($near[0] - $end[0], $near[1] - $end[1]));
                $points[$last] = [$end[0] + ((($near[0] - $end[0]) * 0.5) / $length), $end[1] + ((($near[1] - $end[1]) * 0.5) / $length)];
            }
            $xs = \array_column([...$points, $end], 0);
            $ys = \array_column([...$points, $end], 1);
            $label = null === $edge['title'] ? null : MermaidText::decode($edge['title']);
            $position = self::caption($points);
            $edges[] = new GraphEdge($from->id, $to->id, $points, $tip, \min($xs), \min($ys), \max($xs), \max($ys), '@archEdgeColor', $label, null === $label ? null : $position, dashed: false);
            $arrows[] = ($edge['lhsInto'] ? '<' : '').'--'.($edge['rhsInto'] ? '>' : '');
            yield null;
        }
        $work = MermaidLinkMarkers::decorate(new GraphLayout($graph->width, $graph->height, $graph->nodes, $edges, $graph->clusters), $arrows);
        yield from $work;

        return $work->getReturn();
    }

    /** @param array<array-key, GraphCluster> $frames
     * @return array{array{float, float}, array{float, float}}
     */
    private static function port(ArchitectureDiagram $source, GraphNode $node, string $direction, array $frames): array
    {
        $item = $source->items[$node->name];
        $junction = 'junction' === $item['kind'];
        $radius = $junction ? 0.6 : 4.0;
        $x = $node->x;
        $y = $junction ? $node->y : $node->y - ($node->h / 2) + 4;
        [$dx, $dy] = match ($direction) {
            'L' => [-1.0, 0.0],
            'R' => [1.0, 0.0],
            'T' => [0.0, -1.0],
            default => [0.0, 1.0],
        };
        $point = [$x + ($dx * $radius), $y + ($dy * $radius)];
        $parent = $item['in'];
        if (null !== $parent && \array_key_exists($parent, $frames)) {
            $frame = $frames[$parent];
            $point = match ($direction) {
                'L' => [$frame->x0, $y],
                'R' => [$frame->x1, $y],
                'T' => [$x, $frame->y0],
                default => [$x, $frame->y1],
            };
        }

        return [$point, [$point[0] + ($dx * 3), $point[1] + ($dy * 3)]];
    }

    /** @param array{float, float} $start
     * @param array{float, float} $exit
     * @param array{float, float} $end
     * @param array{float, float} $entry
     * @return list<array{float, float}>
     */
    private static function path(array $start, array $exit, array $end, array $entry, string $left, string $right): array
    {
        $leftHorizontal = 'L' === $left || 'R' === $left;
        $rightHorizontal = 'L' === $right || 'R' === $right;
        $middle = match (true) {
            $leftHorizontal && $rightHorizontal => [[($exit[0] + $entry[0]) / 2, $exit[1]], [($exit[0] + $entry[0]) / 2, $entry[1]]],
            !$leftHorizontal && !$rightHorizontal => [[$exit[0], ($exit[1] + $entry[1]) / 2], [$entry[0], ($exit[1] + $entry[1]) / 2]],
            $leftHorizontal => [[$entry[0], $exit[1]]],
            default => [[$exit[0], $entry[1]]],
        };

        return [$start, $exit, ...$middle, $entry, $end];
    }

    /** @param list<array{float, float}> $points
     * @return list<array{float, float}>
     */
    private static function distinct(array $points): array
    {
        $result = [];
        foreach ($points as $point) {
            if (\array_last($result) === $point) {
                continue;
            }
            $result[] = $point;
        }

        return $result;
    }

    /** @param list<array{float, float}> $points
     * @return array{float, float}
     */
    private static function caption(array $points): array
    {
        $best = 0.0;
        $position = $points[0];
        $count = \count($points);
        for ($index = 1; $index < $count; ++$index) {
            $a = $points[$index - 1];
            $b = $points[$index];
            $length = \hypot($b[0] - $a[0], $b[1] - $a[1]);
            if ($length > $best) {
                $best = $length;
                $position = [($a[0] + $b[0]) / 2, ($a[1] + $b[1]) / 2];
            }
        }

        return $position;
    }
}
