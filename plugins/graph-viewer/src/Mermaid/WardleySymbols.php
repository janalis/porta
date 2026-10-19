<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphNode;

/** Sourcing overlays follow the pinned renderer rather than the older prose glyph descriptions. */
final class WardleySymbols
{
    /** @return list<MermaidMark> */
    public static function marks(GraphNode $node, string $shape): array
    {
        $parts = \explode(':', $shape);
        $strategy = $parts[1];
        if (\in_array($strategy, ['accelerator', 'deaccelerator'], strict: true)) {
            return [new MermaidMark('polygon', self::arrow($node, $strategy), stroke: '#64748b', fill: '#e2e8f0')];
        }
        $marks = [];
        if (\in_array($strategy, ['build', 'buy', 'outsource'], strict: true)) {
            $marks[] = new MermaidMark('polygon', self::ring($node->x, $node->y, radius: 0.8), stroke: $node->color ?? '#333333', fill: '#ffffff');
        }
        if ('market' === $strategy) {
            $centers = [[$node->x, $node->y - 1], [$node->x - 0.9, $node->y + 0.6], [$node->x + 0.9, $node->y + 0.6]];
            $marks[] = new MermaidMark('polyline', [...$centers, $centers[0]]);
            foreach ($centers as [$x, $y]) {
                $marks[] = new MermaidMark('polygon', self::ring($x, $y, radius: 0.5), fill: '#ffffff');
            }
        }
        if ('inertia' === $parts[2]) {
            $x = $node->x + ($node->w / 2) + 0.8;
            $marks[] = new MermaidMark('polyline', [[$x, $node->y - 1.5], [$x, $node->y + 1.5]]);
        }

        return $marks;
    }

    /** @return list<array{float, float}> */
    private static function ring(float $x, float $y, float $radius): array
    {
        $points = [];
        for ($i = 0; $i <= 24; ++$i) {
            $angle = (2 * \M_PI * $i) / 24;
            $points[] = [$x + ($radius * \cos($angle)), $y + ($radius * \sin($angle))];
        }

        return $points;
    }

    /** @return list<array{float, float}> */
    public static function arrow(GraphNode $node, string $kind): array
    {
        $left = $node->x - ($node->w / 2);
        $right = $node->x + ($node->w / 2);
        $top = $node->y - ($node->h / 2);
        $bottom = $node->y + ($node->h / 2);
        $points = [[$left, $top + 1], [$right - 2, $top + 1], [$right - 2, $top], [$right, $node->y], [$right - 2, $bottom], [$right - 2, $bottom - 1], [$left, $bottom - 1]];

        if ('deaccelerator' === $kind) {
            foreach ($points as $index => $point) {
                $points[$index] = [(2 * $node->x) - $point[0], $point[1]];
            }
        }

        return $points;
    }
}
