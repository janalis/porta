<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/** Self-relations leave room for both endpoint glyphs; captions contribute to the export bounds. */
final class MermaidCardRoutes
{
    /** @return \Generator<int, null, mixed, GraphLayout> */
    public static function layout(GraphLayout $graph): \Generator
    {
        $edges = [];
        $width = $graph->width;
        $height = $graph->height;
        foreach ($graph->edges as $edge) {
            $lines = \explode("\n", $edge->label ?? '');
            $halfWidth = (\max(\array_map(AnsiUtils::visibleWidth(...), $lines)) * $edge->fontScale) / 2;
            $halfHeight = \count($lines) * $edge->fontScale;
            $points = $edge->points;
            $tip = $edge->arrowTip;
            $position = $edge->labelPos ?? [0.0, 0.0];
            if ($edge->tail === $edge->head) {
                $node = $graph->nodes[$edge->tail];
                $points = self::loop($node);
                if (null !== $tip) {
                    $last = \count($points) - 1;
                    $tip = $points[$last];
                    $points[$last] = [$tip[0], $tip[1] + 0.5];
                }
                $position = [$node->x + ($node->w / 4) + 4, $node->y + ($node->h / 2) + 8 + $halfHeight];
            }
            $position = [\max($halfWidth + 2, $position[0]), \max($halfHeight + 2, $position[1])];
            $bounds = null === $tip ? $points : [...$points, $tip];
            $xs = \array_column($bounds, 0);
            $ys = \array_column($bounds, 1);
            $edges[] = new GraphEdge(
                $edge->tail,
                $edge->head,
                $points,
                $tip,
                \min($xs),
                \min($ys),
                \max($xs),
                \max($ys),
                $edge->color,
                $edge->label,
                $position,
                $edge->dashed,
                strokeWidth: $edge->strokeWidth,
                textColor: $edge->textColor,
                decoration: $edge->decoration,
                font: $edge->font,
                fontScale: $edge->fontScale,
                dashes: $edge->dashes,
            );
            $width = \max($width, \max($xs) + 4, $position[0] + $halfWidth + 2);
            $height = \max($height, \max($ys) + 4, $position[1] + $halfHeight + 2);
            yield null;
        }

        return new GraphLayout($width, $height, $graph->nodes, $edges, $graph->clusters);
    }

    /** @return list<array{float, float}> */
    private static function loop(GraphNode $node): array
    {
        $right = $node->x + ($node->w / 2);
        $bottom = $node->y + ($node->h / 2);

        return [[$right, $node->y], [$right + 8, $node->y], [$right + 8, $bottom + 6], [$node->x, $bottom + 6], [$node->x, $bottom]];
    }
}
