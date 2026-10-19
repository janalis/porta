<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphNode;

/** UML actor variants and business slashes use the pinned glyph geometry in native units. */
final class UsecaseSymbols
{
    /**
     * @param ?list<list<array{string, GraphFont}>> $runs the caption's styled lines
     * @return list<MermaidMark>
     */
    public static function actor(GraphNode $node, string $shape, ?array $runs = null): array
    {
        $parts = \explode(':', $shape);
        $variant = $parts[1];
        $cy = $node->y - ($node->h / 2) + 4.6;
        $marks = match ($variant) {
            'awesome' => self::awesome($node, $cy),
            'hollow' => self::hollow($node, $cy),
            'icon' => self::icon($node, $cy),
            default => self::normal($node, $cy),
        };
        if ('1' === $parts[2]) {
            $radius = 'hollow' === $variant ? 0.9 : 1.2;
            $centerY = $cy - ('hollow' === $variant ? 2.3 : 2.4);
            $dx = \cos(\M_PI / 3);
            $dy = -\sin(\M_PI / 3);
            $offset = $radius * 0.6;
            $half = $radius * 0.8;
            $cx = $node->x - ($offset * $dy);
            $y = $centerY + ($offset * $dx);
            $marks[] = self::line($node, [[$cx - ($half * $dx), $y - ($half * $dy)], [$cx + ($half * $dx), $y + ($half * $dy)]]);
        }
        $labelTop = $node->y - ($node->h / 2) + 8.2;
        $lines = $runs ?? \array_map(static fn (string $line): array => [[$line, $node->font]], \explode("\n", $node->label));
        foreach ($lines as $index => $line) {
            \array_push($marks, ...MermaidRuns::centered($line, $node->x, $labelTop + ((1 + (2 * $index)) * $node->fontScale), $node->textColor ?? '#253858', $node->fontScale));
        }

        return $marks;
    }

    /** @return list<MermaidMark> */
    private static function normal(GraphNode $node, float $cy): array
    {
        return [
            self::head($node, $cy - 2.4, radius: 1.2),
            self::line($node, [[$node->x, $cy - 1.2], [$node->x, $cy + 0.8]]),
            self::line($node, [[$node->x - 1.7, $cy - 0.5], [$node->x + 1.7, $cy - 0.5]]),
            self::line($node, [[$node->x - 1.5, $cy + 2.8], [$node->x, $cy + 0.8], [$node->x + 1.5, $cy + 2.8]]),
        ];
    }

    /** @return list<MermaidMark> */
    private static function hollow(GraphNode $node, float $cy): array
    {
        $points = [[-2.2, -1.0], [2.2, -1.0], [2.2, 0.0], [0.6, 0.0], [2.2, 1.7], [1.3, 2.8], [0.0, 1.3], [-1.3, 2.8], [-2.2, 1.7], [-0.6, 0.0], [-2.2, 0.0]];
        foreach ($points as &$point) {
            $point = [$node->x + $point[0], $cy + $point[1]];
        }
        unset($point);

        return [
            self::head($node, $cy - 2.3, radius: 0.9, fill: 'none'),
            new MermaidMark('polygon', $points, stroke: $node->color ?? '#253858', fill: 'none', dashed: $node->dashed, strokeWidth: $node->strokeWidth, dashes: $node->dashes),
        ];
    }

    /** @return list<MermaidMark> */
    private static function awesome(GraphNode $node, float $cy): array
    {
        $curves = [
            [[-2.4, 2.5], [-2.4, 0.7], [-1.4, -0.3], [0.0, -0.3]],
            [[0.0, -0.3], [1.4, -0.3], [2.4, 0.7], [2.4, 2.5]],
            [[2.4, 2.5], [2.4, 2.8], [2.1, 3.0], [1.8, 3.0]],
            [[1.8, 3.0], [-1.8, 3.0], [-2.1, 3.0], [-2.4, 2.5]],
        ];
        $points = [];
        foreach ($curves as [$start, $a, $b, $end]) {
            for ($step = 0; $step <= 12; ++$step) {
                $t = $step / 12;
                $u = 1 - $t;
                $x = (($u ** 3) * $start[0]) + (3 * ($u ** 2) * $t * $a[0]) + (3 * $u * ($t ** 2) * $b[0]) + (($t ** 3) * $end[0]);
                $y = (($u ** 3) * $start[1]) + (3 * ($u ** 2) * $t * $a[1]) + (3 * $u * ($t ** 2) * $b[1]) + (($t ** 3) * $end[1]);
                $points[] = [$node->x + $x, $cy + $y];
            }
        }

        return [
            self::head($node, $cy - 2.1, radius: 1.3),
            new MermaidMark('polygon', $points, stroke: $node->color ?? '#253858', fill: $node->fillColor ?? '#d9eafa', dashed: $node->dashed, strokeWidth: $node->strokeWidth, dashes: $node->dashes),
        ];
    }

    /** @return list<MermaidMark> */
    private static function icon(GraphNode $node, float $cy): array
    {
        return [self::head($node, $cy - 0.4, radius: 2.4), new MermaidMark('text', [[$node->x, $cy - 0.2]], '?', stroke: $node->textColor ?? '#253858', fontScale: 2.0)];
    }

    private static function head(GraphNode $node, float $cy, float $radius, ?string $fill = null): MermaidMark
    {
        return new MermaidMark(
            'polygon',
            MermaidOutline::ellipse($node->x - $radius, $cy - $radius, $node->x + $radius, $cy + $radius),
            stroke: $node->color ?? '#253858',
            fill: $fill ?? $node->fillColor ?? '#d9eafa',
            dashed: $node->dashed,
            strokeWidth: $node->strokeWidth,
            dashes: $node->dashes,
        );
    }

    /** @param list<array{float, float}> $points */
    private static function line(GraphNode $node, array $points): MermaidMark
    {
        return new MermaidMark('polyline', $points, stroke: $node->color ?? '#253858', dashed: $node->dashed, strokeWidth: $node->strokeWidth, dashes: $node->dashes);
    }

    /** @return list<MermaidMark> */
    public static function business(GraphNode $node): array
    {
        $marks = MermaidOutline::marks($node, 'ellipse');
        $rx = $node->w / 2;
        $ry = $node->h / 2;
        $start = \max(0.0, $rx - 1.9);
        $end = \max($start, $rx - 0.1);
        $y0 = $ry * \sqrt(\max(0.0, 1 - ($start / $rx) ** 2));
        $y1 = -$ry * \sqrt(\max(0.0, 1 - ($end / $rx) ** 2));
        $marks[] = self::line($node, [[$node->x + $start, $node->y + $y0], [$node->x + $end, $node->y + $y1]]);

        return $marks;
    }

    /** @return list<MermaidMark> */
    public static function table(GraphNode $node, string $shape): array
    {
        $parts = \explode(':', $shape);
        $rows = $parts[2];
        $marks = MermaidOutline::marks($node, 'rect');
        \array_push($marks, ...ErCard::dividers($node, 'er-card:'.$rows));
        $first = (int) \explode(',', $rows)[0];
        $top = $node->y - ($node->h / 2);
        $x = $node->x - ($node->w / 2) + 2 + (((int) $parts[1] + 1.5) * $node->fontScale);
        $marks[] = self::line($node, [[$x, $top + 2 + (((2 * $first) + 1) * $node->fontScale)], [$x, $node->y + ($node->h / 2)]]);
        $lines = \explode("\n", $node->label);
        for ($index = 0; $index < $first; ++$index) {
            $marks[] = new MermaidMark(
                'text',
                [[$node->x, $node->y + (((2 * $index) - (\count($lines) - 1) + 0.5) * $node->fontScale)]],
                $lines[$index],
                stroke: $node->textColor ?? '#253858',
                font: $node->font->overlay(['font-weight' => true]),
                fontScale: $node->fontScale,
            );
        }

        return $marks;
    }
}
