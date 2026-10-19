<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/**
 * Line curves follow d3-shape 3.2.0 (ISC); rounded corners follow pinned Mermaid (MIT).
 * Their copyright and permission notices are in resources/licenses/.
 */
final class MermaidCurves
{
    /** @param list<array{float, float}> $points */
    public static function path(array $points, MermaidCurve $curve): MermaidCurvePath
    {
        if ([] === $points || (MermaidCurve::Rounded === $curve && \count($points) < 2)) {
            return new MermaidCurvePath();
        }
        $path = new MermaidCurvePath($points[0]);
        match ($curve) {
            MermaidCurve::Linear => self::linear($points, $path),
            MermaidCurve::Step, MermaidCurve::StepBefore, MermaidCurve::StepAfter => self::step($points, $path, $curve),
            MermaidCurve::BumpX, MermaidCurve::BumpY => self::bump($points, $path, $curve),
            MermaidCurve::Basis => self::basis($points, $path),
            MermaidCurve::Cardinal => self::cardinal($points, $path),
            MermaidCurve::Natural => MermaidSplines::natural($points, $path),
            MermaidCurve::CatmullRom => MermaidSplines::catmull($points, $path),
            MermaidCurve::MonotoneX, MermaidCurve::MonotoneY => MermaidSplines::monotone($points, $path, $curve),
            MermaidCurve::Rounded => self::rounded($points, $path),
        };

        return $path;
    }

    /** @param list<array{float, float}> $points */
    public static function linear(array $points, MermaidCurvePath $path): void
    {
        foreach (\array_slice($points, offset: 1) as $point) {
            $path->line($point);
        }
    }

    /** @param non-empty-list<array{float, float}> $points */
    private static function step(array $points, MermaidCurvePath $path, MermaidCurve $curve): void
    {
        $count = \count($points);
        for ($index = 1; $index < $count; ++$index) {
            $from = $points[$index - 1];
            $to = $points[$index];
            if (MermaidCurve::StepBefore === $curve) {
                $path->line([$from[0], $to[1]]);
                $path->line($to);
                continue;
            }
            $x = MermaidCurve::StepAfter === $curve ? $to[0] : ($from[0] + $to[0]) / 2;
            $path->line([$x, $from[1]]);
            $path->line([$x, $to[1]]);
        }
        $path->line($points[$count - 1]);
    }

    /** @param non-empty-list<array{float, float}> $points */
    private static function bump(array $points, MermaidCurvePath $path, MermaidCurve $curve): void
    {
        $count = \count($points);
        for ($index = 1; $index < $count; ++$index) {
            $from = $points[$index - 1];
            $to = $points[$index];
            $middle = [($from[0] + $to[0]) / 2, ($from[1] + $to[1]) / 2];
            $path->cubic(
                MermaidCurve::BumpX === $curve ? [$middle[0], $from[1]] : [$from[0], $middle[1]],
                MermaidCurve::BumpX === $curve ? [$middle[0], $to[1]] : [$to[0], $middle[1]],
                $to,
            );
        }
    }

    /** @param non-empty-list<array{float, float}> $points */
    private static function basis(array $points, MermaidCurvePath $path): void
    {
        $count = \count($points);
        if ($count < 3) {
            self::linear($points, $path);

            return;
        }
        $path->line([((5 * $points[0][0]) + $points[1][0]) / 6, ((5 * $points[0][1]) + $points[1][1]) / 6]);
        for ($index = 2; $index <= $count; ++$index) {
            $from = $points[$index - 2];
            $to = $points[$index - 1];
            $next = $points[\min($index, $count - 1)];
            $path->cubic(
                [((2 * $from[0]) + $to[0]) / 3, ((2 * $from[1]) + $to[1]) / 3],
                [($from[0] + (2 * $to[0])) / 3, ($from[1] + (2 * $to[1])) / 3],
                [($from[0] + (4 * $to[0]) + $next[0]) / 6, ($from[1] + (4 * $to[1]) + $next[1]) / 6],
            );
        }
        $path->line($points[$count - 1]);
    }

    /** @param non-empty-list<array{float, float}> $points */
    private static function cardinal(array $points, MermaidCurvePath $path): void
    {
        $count = \count($points);
        if ($count < 3) {
            self::linear($points, $path);

            return;
        }
        for ($index = 0; $index < ($count - 1); ++$index) {
            $from = $points[$index];
            $to = $points[$index + 1];
            $before = $points[\max(0, $index - 1)];
            $after = $points[\min($count - 1, $index + 2)];
            $path->cubic(
                0 === $index ? $from : [$from[0] + (($to[0] - $before[0]) / 6), $from[1] + (($to[1] - $before[1]) / 6)],
                ($index + 2) === $count ? $to : [$to[0] + (($from[0] - $after[0]) / 6), $to[1] + (($from[1] - $after[1]) / 6)],
                $to,
            );
        }
    }

    /** @param non-empty-list<array{float, float}> $points */
    private static function rounded(array $points, MermaidCurvePath $path): void
    {
        $count = \count($points);
        for ($index = 1; $index < ($count - 1); ++$index) {
            $before = $points[$index - 1];
            $point = $points[$index];
            $after = $points[$index + 1];
            $incoming = \hypot($point[0] - $before[0], $point[1] - $before[1]);
            $outgoing = \hypot($after[0] - $point[0], $after[1] - $point[1]);
            if ($incoming < 1e-6 || $outgoing < 1e-6) {
                $path->line($point);
                continue;
            }
            $from = [($point[0] - $before[0]) / $incoming, ($point[1] - $before[1]) / $incoming];
            $to = [($after[0] - $point[0]) / $outgoing, ($after[1] - $point[1]) / $outgoing];
            $angle = \acos(\max(-1.0, \min(1.0, ($from[0] * $to[0]) + ($from[1] * $to[1]))));
            if ($angle < 1e-5 || \abs(\M_PI - $angle) < 1e-5) {
                $path->line($point);
                continue;
            }
            $cut = \min(0.5 / \sin($angle / 2), $incoming / 2, $outgoing / 2);
            $path->line([$point[0] - ($from[0] * $cut), $point[1] - ($from[1] * $cut)]);
            $path->quadratic($point, [$point[0] + ($to[0] * $cut), $point[1] + ($to[1] * $cut)]);
        }
        $path->line($points[$count - 1]);
    }
}
