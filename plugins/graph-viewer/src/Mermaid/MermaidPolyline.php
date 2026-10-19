<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Measures along a routed polyline, whose curved stretches are many short segments. */
final class MermaidPolyline
{
    /** @param list<array{float, float}> $points */
    public static function length(array $points): float
    {
        $length = 0.0;
        for ($index = 1, $count = \count($points); $index < $count; ++$index) {
            $length += \hypot($points[$index][0] - $points[$index - 1][0], $points[$index][1] - $points[$index - 1][1]);
        }

        return $length;
    }

    /**
     * The point `$distance` along a polyline from its first point, and the points beyond it.
     *
     * @param list<array{float, float}> $points
     * @return array{array{float, float}, list<array{float, float}>}
     */
    public static function along(array $points, float $distance): array
    {
        $count = \count($points);
        for ($index = 1; $index < $count; ++$index) {
            [$from, $to] = [$points[$index - 1], $points[$index]];
            $step = \hypot($to[0] - $from[0], $to[1] - $from[1]);
            if ($step >= $distance && $step > 0.0) {
                $ratio = $distance / $step;

                return [[$from[0] + (($to[0] - $from[0]) * $ratio), $from[1] + (($to[1] - $from[1]) * $ratio)], \array_slice($points, $index)];
            }
            $distance -= $step;
        }

        return [$points[$count - 1], []];
    }
}
