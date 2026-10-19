<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphNode;

/** Exclusive-region centers maximize clearance from every contributing and excluded circle. */
final class VennRegion
{
    /** @param array<array-key, GraphNode> $circles
     * @param non-empty-list<string> $ids
     * @return \Generator<int, null, mixed, array{x: float, y: float, margin: float}>
     */
    public static function center(array $circles, array $ids): \Generator
    {
        $inside = \array_fill_keys($ids, value: true);
        $members = \array_intersect_key($circles, $inside);
        $left = \max(\array_map(static fn (GraphNode $circle): float => $circle->x - ($circle->w / 2), $members));
        $right = \min(\array_map(static fn (GraphNode $circle): float => $circle->x + ($circle->w / 2), $members));
        $top = \max(\array_map(static fn (GraphNode $circle): float => $circle->y - ($circle->h / 2), $members));
        $bottom = \min(\array_map(static fn (GraphNode $circle): float => $circle->y + ($circle->h / 2), $members));
        $best = ['x' => ($left + $right) / 2, 'y' => ($top + $bottom) / 2, 'margin' => -\PHP_FLOAT_MAX];
        for ($row = 0; $row <= 12; ++$row) {
            for ($column = 0; $column <= 12; ++$column) {
                $x = $left + ((($right - $left) * $column) / 12);
                $y = $top + ((($bottom - $top) * $row) / 12);
                $margin = self::margin($circles, $inside, $x, $y);
                if ($margin > $best['margin']) {
                    $best = ['x' => $x, 'y' => $y, 'margin' => $margin];
                }
            }
            yield null;
        }
        $step = \max(\abs($right - $left), \abs($bottom - $top)) / 12;
        for ($iteration = 0; $iteration < 24; ++$iteration) {
            $improved = false;
            foreach ([[-1, -1], [-1, 0], [-1, 1], [0, -1], [0, 1], [1, -1], [1, 0], [1, 1]] as [$dx, $dy]) {
                $x = $best['x'] + ($dx * $step);
                $y = $best['y'] + ($dy * $step);
                $margin = self::margin($circles, $inside, $x, $y);
                if ($margin > $best['margin']) {
                    $best = ['x' => $x, 'y' => $y, 'margin' => $margin];
                    $improved = true;
                }
            }
            if (!$improved) {
                $step /= 2;
            }
            yield null;
        }

        return $best;
    }

    /** @param array<array-key, GraphNode> $circles
     * @param array<array-key, true> $inside
     */
    private static function margin(array $circles, array $inside, float $x, float $y): float
    {
        $margin = \PHP_FLOAT_MAX;
        foreach ($circles as $id => $circle) {
            $distance = ($circle->w / 2) - \hypot($x - $circle->x, $y - $circle->y);
            $margin = \min($margin, \array_key_exists($id, $inside) ? $distance : -$distance);
        }

        return $margin;
    }

    /** @param array<array-key, GraphNode> $circles
     * @param non-empty-list<string> $ids
     * @return \Generator<int, null, mixed, list<array{float, float}>>
     */
    public static function polygon(array $circles, array $ids): \Generator
    {
        $polygon = null;
        foreach (\array_unique($ids) as $id) {
            $circle = $circles[$id];
            $clip = MermaidOutline::ellipse($circle->x - ($circle->w / 2), $circle->y - ($circle->h / 2), $circle->x + ($circle->w / 2), $circle->y + ($circle->h / 2));
            \array_pop($clip);
            if (null === $polygon) {
                $polygon = $clip;
                continue;
            }
            $count = \count($clip);
            for ($index = 0; $index < $count && [] !== $polygon; ++$index) {
                $polygon = self::clip($polygon, $clip[$index], $clip[($index + 1) % $count]);
                yield null;
            }
        }

        return $polygon ?? [];
    }

    /** @param list<array{float, float}> $polygon
     * @param array{float, float} $a
     * @param array{float, float} $b
     * @return list<array{float, float}>
     */
    private static function clip(array $polygon, array $a, array $b): array
    {
        $result = [];
        $previous = $polygon[\count($polygon) - 1];
        $wasInside = self::cross($a, $b, $previous) >= 0;
        foreach ($polygon as $point) {
            $inside = self::cross($a, $b, $point) >= 0;
            if ($inside !== $wasInside) {
                $denominator = (($b[0] - $a[0]) * ($point[1] - $previous[1])) - (($b[1] - $a[1]) * ($point[0] - $previous[0]));
                $bounded = ($denominator < 0 ? -1 : 1) * \max(1.0e-12, \abs($denominator));
                $ratio = \max(0.0, \min(1.0, -self::cross($a, $b, $previous) / $bounded));
                $result[] = [$previous[0] + (($point[0] - $previous[0]) * $ratio), $previous[1] + (($point[1] - $previous[1]) * $ratio)];
            }
            if ($inside) {
                $result[] = $point;
            }
            $previous = $point;
            $wasInside = $inside;
        }

        return $result;
    }

    /** @param array{float, float} $a
     * @param array{float, float} $b
     * @param array{float, float} $point
     */
    private static function cross(array $a, array $b, array $point): float
    {
        return (($b[0] - $a[0]) * ($point[1] - $a[1])) - (($b[1] - $a[1]) * ($point[0] - $a[0]));
    }
}
