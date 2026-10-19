<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphNode;

/**
 * The pinned participant glyphs, scaled from the 65px actor box to the native header: the
 * stick actor, boundary, control, entity and database draw above their label, and message
 * text stands on its arrow without a box.
 */
final class SequenceSymbols
{
    /** Participant types drawn as a glyph above the label, and the pinned shapes of the boxed ones. */
    public const array SHAPES = [
        'actor' => 'seq-actor',
        'boundary' => 'seq-boundary',
        'control' => 'seq-control',
        'entity' => 'seq-entity',
        'database' => 'seq-database',
        'collections' => 'st-rect',
        'queue' => 'h-cyl',
    ];

    /** @return list<MermaidMark> */
    public static function marks(GraphNode $node, string $shape): array
    {
        $x = $node->x;
        $top = $node->y - ($node->h / 2);

        return match ($shape) {
            'seq-actor' => [
                self::outline($node, self::circle($x, $top + 0.9, 0.9)),
                self::line($node, [[$x, $top + 1.8], [$x, $top + 3.0]]),
                self::line($node, [[$x - 1.4, $top + 2.2], [$x + 1.4, $top + 2.2]]),
                self::line($node, [[$x - 1.3, $top + 4.2], [$x, $top + 3.0], [$x + 1.3, $top + 4.2]]),
            ],
            'seq-boundary' => [
                self::line($node, [[$x - 3.6, $top + 1.2], [$x - 3.6, $top + 3.2]]),
                self::line($node, [[$x - 3.6, $top + 2.2], [$x - 2.0, $top + 2.2]]),
                self::outline($node, self::circle($x, $top + 2.2, 2.0)),
            ],
            'seq-control' => [
                self::outline($node, self::circle($x, $top + 2.4, 2.0)),
                new MermaidMark(
                    'polygon',
                    [[$x + 0.35, $top + 0.4], [$x - 0.3, $top + 0.85], [$x - 0.15, $top + 0.4], [$x - 0.3, $top - 0.05]],
                    stroke: $node->color ?? '#333333',
                    fill: $node->color ?? '#333333',
                    strokeWidth: $node->strokeWidth,
                ),
            ],
            'seq-entity' => [
                self::outline($node, self::circle($x, $top + 2.0, 2.0)),
                self::line($node, [[$x - 2.0, $top + 4.1], [$x + 2.0, $top + 4.1]]),
            ],
            'seq-database' => self::database($node, $x, $top),
            // Message text has no outline.
            default => [],
        };
    }

    /** @return list<MermaidMark> a cylinder four units wide, its rim drawn over the body */
    private static function database(GraphNode $node, float $x, float $top): array
    {
        $rim = [];
        $body = [];
        for ($step = 0; $step <= 12; ++$step) {
            $angle = (\M_PI * $step) / 12;
            $rim[] = [$x - (2.0 * \cos($angle)), $top + 0.7 + (0.5 * \sin($angle))];
            $body[] = [$x + (2.0 * \cos($angle)), $top + 3.8 + (0.5 * \sin($angle))];
        }
        $back = \array_map(static fn (array $point): array => [$point[0], (2 * ($top + 0.7)) - $point[1]], $rim);

        return [self::outline($node, [...$back, ...$body]), self::line($node, $rim)];
    }

    /**
     * @param list<array{float, float}> $points
     */
    private static function outline(GraphNode $node, array $points): MermaidMark
    {
        return new MermaidMark('polygon', $points, stroke: $node->color ?? '#333333', fill: $node->fillColor ?? '#ececff', strokeWidth: $node->strokeWidth);
    }

    /**
     * @param list<array{float, float}> $points
     */
    private static function line(GraphNode $node, array $points): MermaidMark
    {
        return new MermaidMark('polyline', $points, stroke: $node->color ?? '#333333', strokeWidth: $node->strokeWidth);
    }

    /** @return list<array{float, float}> */
    private static function circle(float $x, float $y, float $radius): array
    {
        $points = [];
        for ($step = 0; $step < 24; ++$step) {
            $angle = (2 * \M_PI * $step) / 24;
            $points[] = [$x + ($radius * \cos($angle)), $y + ($radius * \sin($angle))];
        }

        return $points;
    }
}
