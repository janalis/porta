<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;

/**
 * The pinned message markers beyond the filled arrowhead, scaled from their SVG marker paths
 * (0.08 native units a pixel) and placed along the message's last segment: the cross, the
 * open-arrow chevron, solid and stick half-arrows above or below the line, and the central
 * connection dots on the lifelines.
 */
final class SequenceArrowheads
{
    /**
     * Marker outlines in units along and across the arrival direction, from the lifeline:
     * `[filled, retreat, paths]`, where the line stops `retreat` units short of the lifeline.
     */
    private const array HEADS = [
        'x' => [false, 0.32, [[[-0.8, -0.4], [0.0, 0.4]], [[0.0, -0.4], [-0.8, 0.4]]]],
        ')' => [true, 0.56, [[[0.08, 0.0], [-1.36, 0.96], [-0.56, 0.0], [-1.36, -0.96]]]],
        '|\\' => [true, 0.32, [[[-0.15, 0.06], [-0.95, -0.58], [-0.95, 0.06]]]],
        '|/' => [true, 0.32, [[[-0.15, -0.06], [-0.95, -0.06], [-0.95, 0.58]]]],
        '\\\\' => [false, 0.08, [[[-0.68, -0.56], [-0.12, 0.0]]]],
        '//' => [false, 0.08, [[[-0.68, 0.56], [-0.12, 0.0]]]],
    ];

    /**
     * The arrow as it reads leaving the sender: a reversed half-arrow (`/|-`) is its forward
     * twin (`-|/`) drawn from the receiver.
     */
    public static function forward(string $arrow): string
    {
        return \str_starts_with($arrow, '/') || \str_starts_with($arrow, '\\') ? \strrev($arrow) : $arrow;
    }

    /**
     * The line stopped short of a marker, and the marker's decorations.
     *
     * @param list<array{float, float}> $points at least two
     * @return array{list<array{float, float}>, list<GraphEdge>}
     */
    public static function head(array $points, string $arrow, float $strokeWidth): array
    {
        $kind = \array_find_key(self::HEADS, static fn (array $head, string $suffix): bool => \str_ends_with($arrow, $suffix));
        if (null === $kind) {
            return [$points, []];
        }
        [$filled, $retreat, $paths] = self::HEADS[$kind];
        $last = \count($points) - 1;
        [$along, $across] = self::frame($points[$last - 1], $points[$last]);
        $decorations = [];
        foreach ($paths as $path) {
            $placed = \array_values(\array_map(static fn (array $point): array => self::placed($points[$last], $along, $across, $point), $path));
            $decorations[] = self::decoration($placed, $filled ? $placed : null, $strokeWidth);
        }
        $points[$last] = self::placed($points[$last], $along, $across, [-$retreat, 0.0]);

        return [$points, $decorations];
    }

    /**
     * A central connection: a dot five pinned pixels wide on the lifeline.
     *
     * @param array{float, float} $at
     */
    public static function dot(array $at): GraphEdge
    {
        $circle = [];
        for ($step = 0; $step < 16; ++$step) {
            $angle = (2 * \M_PI * $step) / 16;
            $circle[] = [$at[0] + (0.4 * \cos($angle)), $at[1] + (0.4 * \sin($angle))];
        }

        return self::decoration($circle, $circle, strokeWidth: 0.0);
    }

    /**
     * @param array{float, float} $from
     * @param array{float, float} $to
     * @return array{array{float, float}, array{float, float}} the unit direction and its clockwise normal
     */
    private static function frame(array $from, array $to): array
    {
        $length = \max(1.0e-9, \hypot($to[0] - $from[0], $to[1] - $from[1]));
        $along = [($to[0] - $from[0]) / $length, ($to[1] - $from[1]) / $length];

        return [$along, [-$along[1], $along[0]]];
    }

    /**
     * @param array{float, float} $origin
     * @param array{float, float} $along
     * @param array{float, float} $across
     * @param array{float, float} $offset
     * @return array{float, float}
     */
    private static function placed(array $origin, array $along, array $across, array $offset): array
    {
        return [$origin[0] + ($along[0] * $offset[0]) + ($across[0] * $offset[1]), $origin[1] + ($along[1] * $offset[0]) + ($across[1] * $offset[1])];
    }

    /**
     * @param list<array{float, float}> $points
     * @param ?list<array{float, float}> $ribbon the filled outline of a solid marker
     */
    private static function decoration(array $points, ?array $ribbon, float $strokeWidth): GraphEdge
    {
        $xs = \array_column($points, 0);
        $ys = \array_column($points, 1);

        return new GraphEdge(
            0,
            0,
            $points,
            null,
            \min($xs),
            \min($ys),
            \max($xs),
            \max($ys),
            null,
            null,
            null,
            dashed: false,
            ribbon: $ribbon,
            strokeWidth: $strokeWidth,
            decoration: true,
        );
    }
}
