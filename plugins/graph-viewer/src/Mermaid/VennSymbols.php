<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphNode;

/** Relative shape geometry survives front-matter titles, translation and viewport resizing. */
final class VennSymbols
{
    /** @return list<MermaidMark> */
    public static function marks(GraphNode $node, string $shape): array
    {
        /** @var array{string, numeric-string, numeric-string, string} $parts Generated shape payload: tag, alpha, alpha/visibility, optional polygon. */
        $parts = \explode(':', $shape);
        if ('venn-text' === $parts[0]) {
            $lines = '0' === $parts[2] ? [] : \explode("\n", $node->label);
            $marks = [];
            foreach ($lines as $index => $line) {
                $marks[] = new MermaidMark(
                    'text',
                    [[$node->x, $node->y + (2 * $index) - (\count($lines) - 1) + 0.5]],
                    $line,
                    stroke: VennColor::alpha($node->textColor ?? '#263238', (float) $parts[1]),
                    font: $node->font,
                );
            }

            return $marks;
        }
        $points = MermaidOutline::ellipse($node->x - ($node->w / 2), $node->y - ($node->h / 2), $node->x + ($node->w / 2), $node->y + ($node->h / 2));
        if ('venn-region' === $parts[0]) {
            /** @var list<array{float, float}> $relative Internal shape payloads contain only generated finite coordinate pairs. */
            $relative = \json_decode((string) \base64_decode($parts[3], strict: true), associative: true, flags: \JSON_THROW_ON_ERROR);
            $points = \array_map(static fn (array $point): array => [$node->x + $point[0], $node->y + $point[1]], $relative);
        }

        return [new MermaidMark(
            'polygon',
            $points,
            stroke: VennColor::alpha($node->color ?? 'none', (float) $parts[2]),
            fill: $node->fillColor ?? 'none',
            strokeWidth: $node->strokeWidth,
            fillOpacity: (float) $parts[1],
        )];
    }
}
