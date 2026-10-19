<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphNode;

/** Pinned blockArrow.ts direction factories, scaled to terminal scene bounds. */
final class BlockArrows
{
    /** @return list<array{float, float}> */
    public static function points(GraphNode $node, string $shape): array
    {
        $directions = [];
        foreach (\explode(',', \substr($shape, offset: 12)) as $name) {
            foreach (match ($name) {
                'x' => ['right', 'left'],
                'y' => ['up', 'down'],
                default => [$name],
            } as $direction) {
                $directions[$direction] = true;
            }
        }
        $key = \implode('|', \array_filter(['right', 'left', 'up', 'down'], static fn (string $name): bool => \array_key_exists($name, $directions)));
        $points = self::outline($key, $node->w, $node->h);
        $left = \min(\array_column($points, 0));
        $top = \min(\array_column($points, 1));
        $spanX = \max(1.0e-9, \max(\array_column($points, 0)) - $left);
        $spanY = \max(1.0e-9, \max(\array_column($points, 1)) - $top);

        return \array_map(static fn (array $point): array => [
            $node->x - ($node->w / 2) + ((($point[0] - $left) * $node->w) / $spanX),
            $node->y - ($node->h / 2) + ((($point[1] - $top) * $node->h) / $spanY),
        ], $points);
    }

    /** @return list<array{float, float}> */
    private static function outline(string $key, float $width, float $height): array
    {
        $midpoint = \min($height / 2, $width / 3);
        $padding = $height / 6;

        return match ($key) {
            'right|left|up|down' => [
                [0.0, 0.0],
                [$midpoint, 0.0],
                [$width / 2, 2 * $padding],
                [$width - $midpoint, 0.0],
                [$width, 0.0],
                [$width, -$height / 3],
                [$width + (2 * $padding), -$height / 2],
                [$width, (-2 * $height) / 3],
                [$width, -$height],
                [$width - $midpoint, -$height],
                [$width / 2, -$height - (2 * $padding)],
                [$midpoint, -$height],
                [0.0, -$height],
                [0.0, (-2 * $height) / 3],
                [-2 * $padding, -$height / 2],
                [0.0, -$height / 3],
            ],
            'right|left|up' => [[$midpoint, 0.0], [$width - $midpoint, 0.0], [$width, -$height / 2], [$width - $midpoint, -$height], [$midpoint, -$height], [0.0, -$height / 2]],
            'right|left|down' => [[0.0, 0.0], [$midpoint, -$height], [$width - $midpoint, -$height], [$width, 0.0]],
            'right|up|down' => [[0.0, 0.0], [$width, -$midpoint], [$width, -$height + $midpoint], [0.0, -$height]],
            'left|up|down' => [[$width, 0.0], [0.0, -$midpoint], [0.0, -$height + $midpoint], [$width, -$height]],
            'right|left' => [
                [$midpoint, 0.0],
                [$midpoint, -$padding],
                [$width - $midpoint, -$padding],
                [$width - $midpoint, 0.0],
                [$width, -$height / 2],
                [$width - $midpoint, -$height],
                [$width - $midpoint, -$height + $padding],
                [$midpoint, -$height + $padding],
                [$midpoint, -$height],
                [0.0, -$height / 2],
            ],
            'up|down' => [
                [$width / 2, 0.0],
                [0.0, -$padding],
                [$midpoint, -$padding],
                [$midpoint, -$height + $padding],
                [0.0, -$height + $padding],
                [$width / 2, -$height],
                [$width, -$height + $padding],
                [$width - $midpoint, -$height + $padding],
                [$width - $midpoint, -$padding],
                [$width, -$padding],
            ],
            'right|up' => [[0.0, 0.0], [$width, -$midpoint], [0.0, -$height]],
            'right|down' => [[0.0, 0.0], [$width, 0.0], [0.0, -$height]],
            'left|up' => [[$width, 0.0], [0.0, -$midpoint], [$width, -$height]],
            'left|down' => [[$width, 0.0], [0.0, 0.0], [$width, -$height]],
            default => self::single($key, $width, $height),
        };
    }

    /** @return list<array{float, float}> */
    private static function single(string $key, float $width, float $height): array
    {
        $midpoint = \min($height / 2, $width / 3);
        $padding = $height / 6;

        return match ($key) {
            'right' => [
                [$midpoint, -$padding],
                [$midpoint, -$padding],
                [$width - $midpoint, -$padding],
                [$width - $midpoint, 0.0],
                [$width, -$height / 2],
                [$width - $midpoint, -$height],
                [$width - $midpoint, -$height + $padding],
                [$midpoint, -$height + $padding],
                [$midpoint, -$height + $padding],
            ],
            'left' => [
                [$midpoint, 0.0],
                [$midpoint, -$padding],
                [$width - $midpoint, -$padding],
                [$width - $midpoint, -$height + $padding],
                [$midpoint, -$height + $padding],
                [$midpoint, -$height],
                [0.0, -$height / 2],
            ],
            'up' => [
                [$midpoint, -$padding],
                [$midpoint, -$height + $padding],
                [0.0, -$height + $padding],
                [$width / 2, -$height],
                [$width, -$height + $padding],
                [$width - $midpoint, -$height + $padding],
                [$width - $midpoint, -$padding],
            ],
            'down' => [
                [$width / 2, 0.0],
                [0.0, -$padding],
                [$midpoint, -$padding],
                [$midpoint, -$height + $padding],
                [$width - $midpoint, -$height + $padding],
                [$width - $midpoint, -$padding],
                [$width, -$padding],
            ],
            default => throw new \InvalidArgumentException('Unknown block arrow direction'),
        };
    }
}
