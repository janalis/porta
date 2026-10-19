<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphNode;

/** Internal strokes distinguish the pinned commit types without creating graph edges. */
final class GitMarkers
{
    /**
     * A `git:TYPE:CUSTOM|color` shape: the commit type, its custom type, and the glyph color,
     * white until a palette resolves the layout's token.
     *
     * @return array{string, ?string, string}
     */
    public static function parse(string $shape): array
    {
        [$kind, $inner] = \array_pad(\explode('|', $shape, limit: 2), length: 2, value: '');
        [, $type, $custom] = \array_pad(\explode(':', $kind), length: 3, value: '');

        return [$type, '' === $custom ? null : $custom, \str_starts_with($inner, '#') ? $inner : '#ffffff'];
    }

    /** @return list<list<array{float, float}>> */
    public static function points(GraphNode $node, string $type, ?string $custom): array
    {
        $x = $node->x;
        $y = $node->y;

        return match ($custom ?? $type) {
            'REVERSE' => [[[$x - 1, $y - 1], [$x + 1, $y + 1]], [[$x - 1, $y + 1], [$x + 1, $y - 1]]],
            'HIGHLIGHT' => [[[$x - 1, $y - 1], [$x + 1, $y - 1], [$x + 1, $y + 1], [$x - 1, $y + 1], [$x - 1, $y - 1]]],
            'CHERRY_PICK' => [[[$x - 0.65, $y + 0.5], [$x, $y - 0.8], [$x + 0.65, $y + 0.5]], self::ring($x - 0.65, $y + 0.5, radius: 0.4), self::ring($x + 0.65, $y + 0.5, radius: 0.4)],
            'MERGE' => [self::ring($x, $y, radius: 1.3)],
            default => [],
        };
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

    /**
     * Pinned tag flags: one per tag line, pointing left, with a hole by the point.
     *
     * @return list<MermaidMark>
     */
    public static function tags(GraphNode $node, string $shape): array
    {
        $hole = \explode('|', $shape, limit: 2)[1] ?? '#333333';
        $left = $node->x - ($node->w / 2) + 0.8;
        $right = $node->x + ($node->w / 2);
        $top = $node->y - ($node->h / 2);
        $marks = [];
        for ($line = 0; $line < (int) \round($node->h / 2); ++$line) {
            $y = $top + 1 + (2 * $line);
            $marks[] = new MermaidMark(
                'polygon',
                [[$left - 0.8, $y], [$left, $y - 0.9], [$right, $y - 0.9], [$right, $y + 0.9], [$left, $y + 0.9]],
                stroke: $node->color ?? '#333333',
                fill: $node->fillColor ?? '#ffffde',
                strokeWidth: 0.1,
            );
            $marks[] = new MermaidMark('polygon', MermaidOutline::ellipse($left - 0.15, $y - 0.15, $left + 0.15, $y + 0.15), stroke: 'none', fill: $hole, strokeWidth: 0.0);
        }

        return $marks;
    }
}
