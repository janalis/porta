<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphNode;

/**
 * Built-in cloud/resource icons use native paths; external packs retain a visible fallback.
 * Pinned icons are white strokes on an `#087ebf` square, whatever the theme; labels take the
 * node's theme text color over its background.
 */
final class ArchitectureSymbols
{
    private const string BOX = '#087ebf';
    private const string GLYPH = '#ffffff';

    /** @return list<MermaidMark> */
    public static function marks(GraphNode $node, string $shape): array
    {
        $parts = \explode(':', $shape);
        if ('arch-junction' === $parts[0]) {
            $color = $node->color ?? '#333333';

            return [new MermaidMark('polygon', MermaidOutline::ellipse($node->x - 0.6, $node->y - 0.6, $node->x + 0.6, $node->y + 0.6), stroke: $color, fill: $color)];
        }
        $group = 'arch-group' === $parts[0];
        $x = $group ? $node->x - ($node->w / 2) + 4 : $node->x;
        $y = $group ? $node->y : $node->y - ($node->h / 2) + 4;
        $icon = (string) \base64_decode($parts[1], strict: true);
        $text = (string) \base64_decode($parts[2] ?? '', strict: true);
        $marks = '' !== $text ? [self::box($x, $y), new MermaidMark('text', [[$x, $y + 0.5]], $text, stroke: self::GLYPH, fontScale: 2.0)] : self::tile($icon, $x, $y);
        $lines = \explode("\n", $node->label);
        foreach ($lines as $index => $line) {
            $labelX = $group ? $node->x + 4 : $node->x;
            $labelY = $group ? $node->y + (2 * $index) - (\count($lines) - 1) + 0.5 : $y + 6 + (2 * $index);
            [$textWidth] = MermaidText::box($line);
            $half = \max(0.5, ($textWidth - 3) / 2);
            $marks[] = new MermaidMark(
                'polygon',
                [[$labelX - $half, $labelY - 1.6], [$labelX + $half, $labelY - 1.6], [$labelX + $half, $labelY + 0.5], [$labelX - $half, $labelY + 0.5]],
                stroke: 'none',
                fill: $node->fillColor ?? '#ffffff',
            );
            $marks[] = new MermaidMark('text', [[$labelX, $labelY]], $line, stroke: $node->textColor ?? '#333333');
        }

        return $marks;
    }

    /**
     * A pinned icon tile, `$size` units square about its center: a built-in glyph, else the
     * unknown icon.
     *
     * @return list<MermaidMark>
     */
    public static function tile(string $icon, float $x, float $y, float $size = 8.0): array
    {
        $scale = $size / 8;

        return \array_map(
            static fn (MermaidMark $mark): MermaidMark => new MermaidMark(
                $mark->kind,
                \array_map(static fn (array $point): array => [$x + (($point[0] - $x) * $scale), $y + (($point[1] - $y) * $scale)], $mark->points),
                $mark->text,
                $mark->stroke,
                $mark->fill,
                $mark->dashed,
                $mark->strokeWidth * $scale,
                $mark->font,
                $mark->fontScale * $scale,
                dashes: $mark->dashes,
            ),
            [self::box($x, $y), ...self::icon($icon, $x, $y)],
        );
    }

    private static function box(float $x, float $y): MermaidMark
    {
        return new MermaidMark('polygon', [[$x - 4, $y - 4], [$x + 4, $y - 4], [$x + 4, $y + 4], [$x - 4, $y + 4]], stroke: 'none', fill: self::BOX);
    }

    /** @return list<MermaidMark> */
    private static function icon(string $icon, float $x, float $y): array
    {
        return match ($icon) {
            'server' => self::server($x, $y),
            'database', 'disk' => self::cylinder($x, $y, $icon),
            'internet' => self::globe($x, $y),
            'cloud' => self::cloud($x, $y),
            default => [self::polygon(MermaidOutline::ellipse($x - 3, $y - 3, $x + 3, $y + 3)), new MermaidMark('text', [[$x, $y + 0.8]], '?', stroke: self::GLYPH, fontScale: 2.0)],
        };
    }

    /** @return list<MermaidMark> */
    private static function server(float $x, float $y): array
    {
        $marks = [self::polygon([[$x - 3, $y - 4], [$x + 3, $y - 4], [$x + 3, $y + 4], [$x - 3, $y + 4]])];
        foreach ([-2.5, 0.0, 2.5] as $row) {
            $marks[] = self::line([[$x - 2, $y + $row], [$x + 1, $y + $row]]);
            $marks[] = self::polygon(MermaidOutline::ellipse($x + 1.6, $y + $row - 0.2, $x + 2.0, $y + $row + 0.2));
        }

        return $marks;
    }

    /** @return list<MermaidMark> */
    private static function cylinder(float $x, float $y, string $icon): array
    {
        $height = 'disk' === $icon ? 1.8 : 3.2;
        $bottom = MermaidOutline::ellipse($x - 3.5, $y + $height - 1.2, $x + 3.5, $y + $height + 1.2);
        $body = [[$x - 3.5, $y - $height], [$x + 3.5, $y - $height], [$x + 3.5, $y + $height], ...\array_slice($bottom, offset: 0, length: 25), [$x - 3.5, $y - $height]];

        return [self::polygon($body), self::polygon(MermaidOutline::ellipse($x - 3.5, $y - $height - 1.2, $x + 3.5, $y - $height + 1.2))];
    }

    /** @return list<MermaidMark> */
    private static function globe(float $x, float $y): array
    {
        return [
            self::polygon(MermaidOutline::ellipse($x - 3.5, $y - 3.5, $x + 3.5, $y + 3.5)),
            self::line(MermaidOutline::ellipse($x - 1.5, $y - 3.5, $x + 1.5, $y + 3.5)),
            self::line([[$x - 3.5, $y], [$x + 3.5, $y]]),
            self::line([[$x - 2.8, $y - 2], [$x + 2.8, $y - 2]]),
            self::line([[$x - 2.8, $y + 2], [$x + 2.8, $y + 2]]),
        ];
    }

    /** @return list<MermaidMark> */
    private static function cloud(float $x, float $y): array
    {
        $points = [];
        foreach ([[2.0, 0.0, 2.0, -\M_PI / 2, \M_PI / 2], [-2.0, 0.0, 2.0, \M_PI / 2, (3 * \M_PI) / 2], [-0.5, -1.4, 2.2, \M_PI, 2 * \M_PI]] as [$cx, $cy, $radius, $start, $end]) {
            for ($index = 0; $index <= 12; ++$index) {
                $angle = $start + ((($end - $start) * $index) / 12);
                $points[] = [$x + $cx + ($radius * \cos($angle)), $y + $cy + ($radius * \sin($angle))];
            }
        }

        return [self::polygon($points)];
    }

    /** @param list<array{float, float}> $points */
    private static function polygon(array $points): MermaidMark
    {
        return new MermaidMark('polygon', $points, stroke: self::GLYPH, fill: 'none');
    }

    /** @param list<array{float, float}> $points */
    private static function line(array $points): MermaidMark
    {
        return new MermaidMark('polyline', $points, stroke: self::GLYPH);
    }
}
