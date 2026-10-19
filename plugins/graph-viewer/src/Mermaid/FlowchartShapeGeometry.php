<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphNode;

/** Bounded sampled curves share their silhouettes with source picking and edge intersections. */
final class FlowchartShapeGeometry
{
    /** @return list<array{float, float}> */
    public static function points(GraphNode $node, string $shape): array
    {
        $cap = self::cap($node);
        $points = match ($shape) {
            'sm-circ', 'fr-circ', 'f-circ', 'cross-circ' => self::arc(0.5, 0.5, 0.5, 0.5, 0, 360),
            'hourglass' => [[0.0, 0.0], [1.0, 0.0], [0.0, 1.0], [1.0, 1.0]],
            'bolt' => [[1.0, 0.0], [0.0, 0.55], [0.6, 0.55], [0.0, 1.0], [1.0, 0.45], [0.4, 0.45]],
            'notch-pent' => [[0.1, 0.0], [0.9, 0.0], [1.0, 0.2], [1.0, 1.0], [0.0, 1.0], [0.0, 0.2]],
            'folder' => [[0.0, 0.0], [0.38, 0.0], [0.38, 0.18], [1.0, 0.18], [1.0, 1.0], [0.0, 1.0]],
            'delay' => [[0.0, 0.0], ...self::arc(1 - $cap, 0.5, $cap, 0.5, -90, 180), [0.0, 1.0]],
            'curv-trap' => [[0.0, 0.5], [$cap / 2, 0.0], ...self::arc(1 - $cap, 0.5, $cap, 0.5, -90, 180), [$cap / 2, 1.0]],
            'h-cyl' => [...self::arc($cap / 2, 0.5, $cap / 2, 0.5, 90, 180), ...self::arc(1 - ($cap / 2), 0.5, $cap / 2, 0.5, -90, 180)],
            'lin-cyl' => [...self::arc(0.5, 0.1, 0.5, 0.1, 180, 180), ...self::arc(0.5, 0.9, 0.5, 0.1, 0, 180)],
            'bucket' => [...self::arc(0.5, 0.12, 0.5, 0.12, 180, 180), ...self::arc(0.5, 0.9, 0.36, 0.1, 0, 180)],
            'doc', 'tag-doc', 'docs' => self::wave('doc'),
            'flag' => self::wave('flag'),
            'bow-rect' => self::stored(),
            'person' => self::person($node),
            default => [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0]],
        };

        return self::project($node, $points);
    }

    public static function cap(GraphNode $node): float
    {
        return \min(0.4, $node->h / (2 * \max(0.1, $node->w)));
    }

    /** @param list<array{float, float}> $points
     * @return list<array{float, float}>
     */
    public static function project(GraphNode $node, array $points): array
    {
        return \array_map(static fn (array $point): array => [$node->x + (($point[0] - 0.5) * $node->w), $node->y + (($point[1] - 0.5) * $node->h)], $points);
    }

    /** @return list<array{float, float}> */
    public static function arc(float $x, float $y, float $rx, float $ry, float $start, float $sweep): array
    {
        $points = [];
        for ($index = 0; $index <= 24; ++$index) {
            $angle = (($start + (($sweep * $index) / 24)) * \M_PI) / 180;
            $points[] = [$x + ($rx * \cos($angle)), $y + ($ry * \sin($angle))];
        }

        return $points;
    }

    /** @return list<array{float, float}> */
    private static function wave(string $shape): array
    {
        $points = [];
        for ($index = 0; $index <= 32; ++$index) {
            $x = $index / 32;
            $points[] = [$x, 'flag' === $shape ? 0.1 + (0.1 * \sin(2 * \M_PI * $x)) : 0.0];
        }
        for ($index = 32; $index >= 0; --$index) {
            $x = $index / 32;
            $points[] = [$x, 0.9 + (0.1 * \sin(2 * \M_PI * $x))];
        }

        return $points;
    }

    /** @return list<array{float, float}> */
    private static function stored(): array
    {
        $points = [];
        for ($index = 0; $index <= 24; ++$index) {
            $y = $index / 24;
            $points[] = [0.1 - (0.1 * \sin(\M_PI * $y)), $y];
        }
        for ($index = 24; $index >= 0; --$index) {
            $y = $index / 24;
            $points[] = [1 - (0.1 * \sin(\M_PI * $y)), $y];
        }

        return $points;
    }

    /** @return list<array{float, float}> */
    private static function person(GraphNode $node): array
    {
        $radius = self::headBand($node) / 1.73;
        $rx = $radius / $node->w;
        $ry = $radius / $node->h;
        $top = self::headBand($node) / $node->h;
        $corner = \min($node->w * 0.177, ($node->h - self::headBand($node)) * 0.45);
        $cx = $corner / $node->w;
        $cy = $corner / $node->h;

        return [
            ...self::arc(0.5, $ry, $rx, $ry, 133, 274),
            [1 - $cx, $top],
            [1.0, $top + $cy],
            [1.0, 1 - $cy],
            [1 - $cx, 1.0],
            [$cx, 1.0],
            [0.0, 1 - $cy],
            [0.0, $top + $cy],
            [$cx, $top],
        ];
    }

    public static function personHead(float $width): float
    {
        return \min(5.6, \max(1.6, $width * 0.23));
    }

    public static function headBand(GraphNode $node): float
    {
        return 1.73 * \min(self::personHead($node->w), $node->h / 3, $node->w / 2);
    }
}
