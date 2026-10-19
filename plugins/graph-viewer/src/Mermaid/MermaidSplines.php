<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** d3-shape 3.2.0 spline coefficients; its ISC notice is in resources/licenses/d3-shape.txt. */
final class MermaidSplines
{
    /** @param non-empty-list<array{float, float}> $points */
    public static function natural(array $points, MermaidCurvePath $path): void
    {
        $count = \count($points);
        if ($count < 3) {
            MermaidCurves::linear($points, $path);

            return;
        }
        /** @var non-empty-list<float> $xs At least three points remain here. */
        $xs = \array_column($points, column_key: 0);
        /** @var non-empty-list<float> $ys */
        $ys = \array_column($points, column_key: 1);
        [$firstX, $secondX] = self::controls($xs);
        [$firstY, $secondY] = self::controls($ys);
        for ($index = 0; $index < ($count - 1); ++$index) {
            $path->cubic([$firstX[$index], $firstY[$index]], [$secondX[$index], $secondY[$index]], $points[$index + 1]);
        }
    }

    /**
     * @param non-empty-list<float> $values
     * @return array{list<float>, list<float>}
     */
    private static function controls(array $values): array
    {
        $count = \count($values) - 1;
        $a = \array_fill(0, $count, value: 1.0);
        $b = \array_fill(0, $count, value: 4.0);
        $r = \array_fill(0, $count, value: 0.0);
        $a[0] = 0.0;
        $b[0] = 2.0;
        $r[0] = $values[0] + (2 * $values[1]);
        for ($index = 1; $index < ($count - 1); ++$index) {
            $r[$index] = (4 * $values[$index]) + (2 * $values[$index + 1]);
        }
        $a[$count - 1] = 2.0;
        $b[$count - 1] = 7.0;
        $r[$count - 1] = (8 * $values[$count - 1]) + $values[$count];
        for ($index = 1; $index < $count; ++$index) {
            $ratio = $a[$index] / $b[$index - 1];
            $b[$index] -= $ratio;
            $r[$index] -= $ratio * $r[$index - 1];
        }
        $a[$count - 1] = $r[$count - 1] / $b[$count - 1];
        for ($index = $count - 2; $index >= 0; --$index) {
            $a[$index] = ($r[$index] - $a[$index + 1]) / $b[$index];
        }
        $b[$count - 1] = ($values[$count] + $a[$count - 1]) / 2;
        for ($index = 0; $index < ($count - 1); ++$index) {
            $b[$index] = (2 * $values[$index + 1]) - $a[$index + 1];
        }

        return [$a, $b];
    }

    /** @param non-empty-list<array{float, float}> $points */
    public static function catmull(array $points, MermaidCurvePath $path): void
    {
        $count = \count($points);
        if ($count < 3) {
            MermaidCurves::linear($points, $path);

            return;
        }
        for ($index = 0; $index < ($count - 1); ++$index) {
            $before = $points[\max(0, $index - 1)];
            $from = $points[$index];
            $to = $points[$index + 1];
            $after = $points[\min($count - 1, $index + 2)];
            $left = \hypot($from[0] - $before[0], $from[1] - $before[1]);
            $middle = \hypot($to[0] - $from[0], $to[1] - $from[1]);
            $right = \hypot($after[0] - $to[0], $after[1] - $to[1]);
            $first = $from;
            $second = $to;
            $h0 = \sqrt($left);
            $h1 = \sqrt($middle);
            $h2 = \sqrt($right);
            if ($h0 > 1e-12) {
                $weight = (2 * $left) + (3 * $h0 * $h1) + $middle;
                $divisor = 3 * $h0 * ($h0 + $h1);
                $first = [
                    (($from[0] * $weight) - ($before[0] * $middle) + ($to[0] * $left)) / $divisor,
                    (($from[1] * $weight) - ($before[1] * $middle) + ($to[1] * $left)) / $divisor,
                ];
            }
            if ($h2 > 1e-12) {
                $weight = (2 * $right) + (3 * $h2 * $h1) + $middle;
                $divisor = 3 * $h2 * ($h2 + $h1);
                $second = [
                    (($to[0] * $weight) + ($from[0] * $right) - ($after[0] * $middle)) / $divisor,
                    (($to[1] * $weight) + ($from[1] * $right) - ($after[1] * $middle)) / $divisor,
                ];
            }
            $path->cubic($first, $second, $to);
        }
    }

    /** @param non-empty-list<array{float, float}> $points */
    public static function monotone(array $points, MermaidCurvePath $path, MermaidCurve $curve): void
    {
        $unique = [];
        foreach ($points as $point) {
            $point = self::reflect($point, $curve);
            if (\array_last($unique) !== $point) {
                $unique[] = $point;
            }
        }
        $count = \count($unique);
        if ($count < 3) {
            foreach (\array_slice($unique, offset: 1) as $point) {
                $path->line(self::reflect($point, $curve));
            }

            return;
        }
        $tangents = \array_fill(0, $count, value: 0.0);
        for ($index = 1; $index < ($count - 1); ++$index) {
            $tangents[$index] = self::slope($unique[$index - 1], $unique[$index], $unique[$index + 1]);
        }
        $tangents[0] = self::endpoint($unique[0], $unique[1], $tangents[1]);
        $tangents[$count - 1] = self::endpoint($unique[$count - 2], $unique[$count - 1], $tangents[$count - 2]);
        for ($index = 0; $index < ($count - 1); ++$index) {
            $from = $unique[$index];
            $to = $unique[$index + 1];
            $dx = ($to[0] - $from[0]) / 3;
            $path->cubic(
                self::reflect([$from[0] + $dx, $from[1] + ($dx * $tangents[$index])], $curve),
                self::reflect([$to[0] - $dx, $to[1] - ($dx * $tangents[$index + 1])], $curve),
                self::reflect($to, $curve),
            );
        }
    }

    /** d3's `h || other < 0 && -0`: a zero step takes its signed zero from the neighboring step. */
    private static function divisor(float $step, float $other): float
    {
        if (0.0 !== $step) {
            return $step;
        }

        return $other < 0 ? -0.0 : 0.0;
    }

    /**
     * @param array{float, float} $before
     * @param array{float, float} $point
     * @param array{float, float} $after
     */
    private static function slope(array $before, array $point, array $after): float
    {
        $h0 = $point[0] - $before[0];
        $h1 = $after[0] - $point[0];
        $s0 = \fdiv($point[1] - $before[1], self::divisor($h0, $h1));
        $s1 = \fdiv($after[1] - $point[1], self::divisor($h1, $h0));
        $weighted = \fdiv(($s0 * $h1) + ($s1 * $h0), $h0 + $h1);
        if (\is_nan($s0) || \is_nan($s1) || \is_nan($weighted)) {
            return 0.0;
        }
        $slope = (($s0 < 0 ? -1 : 1) + ($s1 < 0 ? -1 : 1)) * \min(\abs($s0), \abs($s1), 0.5 * \abs($weighted));

        return \is_nan($slope) || 0.0 === $slope ? 0.0 : $slope;
    }

    /**
     * @param array{float, float} $from
     * @param array{float, float} $to
     */
    private static function endpoint(array $from, array $to, float $tangent): float
    {
        $dx = $to[0] - $from[0];

        return 0.0 === $dx ? $tangent : (((3 * ($to[1] - $from[1])) / $dx) - $tangent) / 2;
    }

    /**
     * @param array{float, float} $point
     * @return array{float, float}
     */
    private static function reflect(array $point, MermaidCurve $curve): array
    {
        return MermaidCurve::MonotoneY === $curve ? [$point[1], $point[0]] : $point;
    }
}
