<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/** The same ordered drawing operations feed SVG export and GD rasterization. */
final readonly class MermaidDrawing
{
    /** @param list<MermaidMark> $marks */
    public function __construct(
        public float $width,
        public float $height,
        public array $marks,
        public ?string $background = null,
    ) {}

    /**
     * A themed scene: unstyled elements take the family's theme roles, the neo look adds the
     * theme's drop shadow beneath filled nodes and frames, and the hand-drawn look sketches them.
     *
     * @return \Generator<int, null, mixed, self>
     */
    public static function scene(MermaidScene $scene): \Generator
    {
        $palette = $scene->palette;
        $work = null === $palette
            ? self::graph($scene->graph, $scene->shapes, $scene->background, runs: $scene->runs)
            : self::graph(
                $palette->paint($scene->graph),
                $palette->shapes($scene->shapes),
                $scene->background,
                $palette->caption(),
                $palette->shadow(),
                $palette->roles->frameShadows,
                $scene->runs,
                $palette->sketch(),
            );
        yield from $work;

        return $work->getReturn();
    }

    /**
     * @param array<int, string> $shapes
     * @param array{float, float, string}|null $shadow pixel offsets and color beneath filled outlines
     * @param array<string, list<list<array{string, GraphFont}>>> $runs styled Markdown labels by element
     * @param ?MermaidSketch $sketch the hand-drawn look, which roughens outlines, frames and edges
     * @return \Generator<int, null, mixed, self>
     */
    // @mago-expect lint:no-boolean-flag-parameter -- Whether frames cast the node shadow is the one fact a family's neo styles change.
    public static function graph(
        GraphLayout $graph,
        array $shapes = [],
        ?string $background = null,
        ?string $caption = null,
        ?array $shadow = null,
        bool $frameShadows = true,
        array $runs = [],
        ?MermaidSketch $sketch = null,
    ): \Generator {
        $marks = [];
        foreach ($graph->clusters as $clusterIndex => $cluster) {
            if (null !== $shadow && $frameShadows && 'none' !== $cluster->fillColor) {
                $marks[] = self::shadow(self::box($cluster), $shadow);
            }
            // Pinned hand-drawn frames hatch with 3px lines.
            \array_push($marks, ...$sketch?->shape(self::box($cluster), hachure: 0.3) ?? [self::box($cluster)]);
            $label = $cluster->labelPos;
            foreach (self::lines($cluster->label, $cluster->font, $runs['cluster:'.$clusterIndex] ?? null) as $index => $line) {
                $x = $label[0] ?? ($cluster->x0 + (($cluster->x1 - $cluster->x0) / 2));
                $y = null === $label ? $cluster->y0 + ((2 + (2 * $index)) * $cluster->fontScale) : $label[1] + (2 * $index * $cluster->fontScale);
                \array_push($marks, ...self::text($line, $x, $y, $cluster->textColor ?? '#333333', $cluster->fontScale));
            }
            yield null;
        }
        foreach ($graph->nodes as $node) {
            if ($node->background) {
                \array_push($marks, ...self::node($node, $shapes[$node->id] ?? 'rect', $shadow, $runs['node:'.$node->id] ?? null, $sketch));
            }
            yield null;
        }
        foreach ($graph->edges as $edge) {
            $line = new MermaidMark('polyline', $edge->points, stroke: $edge->color ?? '#333333', dashed: $edge->dashed, strokeWidth: $edge->strokeWidth, dashes: $edge->dashes);
            \array_push(
                $marks,
                ...null === $edge->ribbon
                    ? $sketch?->edge($line) ?? [$line]
                    : [new MermaidMark('polygon', $edge->ribbon, fill: $edge->color ?? '#333333', strokeWidth: 0.0, gradient: $edge->gradient)],
            );
            if (null !== $edge->arrowTip && \count($edge->points) >= 2) {
                $previous = $edge->points[\count($edge->points) - 2];
                $angle = \atan2($edge->arrowTip[1] - $previous[1], $edge->arrowTip[0] - $previous[0]);
                $baseX = $edge->arrowTip[0] - (0.9 * \cos($angle));
                $baseY = $edge->arrowTip[1] - (0.9 * \sin($angle));
                $marks[] = new MermaidMark(
                    'polygon',
                    [$edge->arrowTip, [$baseX - (0.4 * \sin($angle)), $baseY + (0.4 * \cos($angle))], [$baseX + (0.4 * \sin($angle)), $baseY - (0.4 * \cos($angle))]],
                    fill: $edge->color ?? '#333333',
                );
            }
            yield null;
        }
        foreach ($graph->nodes as $node) {
            if (!$node->background) {
                \array_push($marks, ...self::node($node, $shapes[$node->id] ?? 'rect', $shadow, $runs['node:'.$node->id] ?? null, $sketch));
            }
            yield null;
        }
        foreach ($graph->edges as $edgeIndex => $edge) {
            if (null !== $edge->label && '' !== $edge->label && null !== $edge->labelPos) {
                \array_push($marks, ...self::caption($edge, $edge->labelPos, $caption ?? '#ffffff', $runs['edge:'.$edgeIndex] ?? null));
            }
            yield null;
        }

        return new self(\max(1.0, $graph->width), \max(1.0, $graph->height), $marks, $background);
    }

    /**
     * @param array{float, float} $position
     * @param ?list<list<array{string, GraphFont}>> $runs
     * @return list<MermaidMark>
     */
    private static function caption(GraphEdge $edge, array $position, string $background, ?array $runs): array
    {
        $text = (string) $edge->label;
        $fontScale = $edge->fontScale;
        $lines = \explode("\n", $text);
        $halfWidth = ((\max(\array_map(AnsiUtils::visibleWidth(...), $lines)) * $fontScale) / 2) + 0.6;
        [$x, $y] = $position;
        $halfHeight = \count($lines) * $fontScale;
        $marks = [new MermaidMark(
            'polygon',
            [[$x - $halfWidth, $y - $halfHeight], [$x + $halfWidth, $y - $halfHeight], [$x + $halfWidth, $y + $halfHeight], [$x - $halfWidth, $y + $halfHeight]],
            fill: $background,
            strokeWidth: 0.0,
        )];
        foreach (self::lines($text, $edge->font, $runs) as $index => $line) {
            \array_push($marks, ...self::text($line, $x, $y + (((2 * $index) - (\count($lines) - 1) + 0.5) * $fontScale), $edge->textColor ?? '#333333', $fontScale));
        }

        return $marks;
    }

    /**
     * A label's lines as runs: the Markdown runs when the label has them, else each line in one face.
     *
     * @param ?list<list<array{string, GraphFont}>> $runs
     * @return list<list<array{string, GraphFont}>>
     */
    private static function lines(string $text, GraphFont $font, ?array $runs): array
    {
        return $runs ?? \array_map(static fn (string $line): array => [[$line, $font]], \explode("\n", $text));
    }

    /**
     * One centered line; a styled line places each run after the previous one, every bundled
     * face sharing the same advance.
     *
     * @param list<array{string, GraphFont}> $runs
     * @return list<MermaidMark>
     */
    private static function text(array $runs, float $x, float $y, string $color, float $fontScale): array
    {
        return MermaidRuns::centered($runs, $x, $y, $color, $fontScale);
    }

    /**
     * @param array{float, float, string}|null $shadow
     * @param ?list<list<array{string, GraphFont}>> $runs
     * @return list<MermaidMark>
     */
    private static function node(GraphNode $node, string $shape, ?array $shadow, ?array $runs, ?MermaidSketch $sketch): array
    {
        $marks = match (true) {
            \str_starts_with($shape, 'kanban-card:') => KanbanCards::marks($node, $shape, $runs),
            \str_starts_with($shape, 'uc-actor:') => UsecaseSymbols::actor($node, $shape, $runs),
            default => MermaidOutline::marks($node, $shape),
        };
        if (null !== $sketch) {
            $marks = \array_merge(...\array_map($sketch->shape(...), $marks));
        }
        if (null !== $shadow && [] !== $marks && 'polygon' === $marks[0]->kind && 'none' !== $marks[0]->fill) {
            \array_unshift($marks, self::shadow($marks[0], $shadow));
        }
        $canonical = MermaidOutline::canonical($shape);
        // A blank drawn label hides the text, styled or not: the shape draws its own.
        $lines = FlowchartShapes::hidesLabel($canonical) || '' === $node->drawLabel ? [] : self::lines($node->drawLabel ?? $node->label, $node->font, $runs);
        [$offsetX, $offsetY] = FlowchartShapes::labelOffset($canonical, $node);
        $baseline = 'point' === $shape ? $node->y + ($node->h / 2) + \count($lines) + 1 : $node->y;
        $baseline += ($offsetY * $node->h)
        + match ($canonical) {
            // Pinned triangles seat the label half a padding from their base.
            'tri' => ($node->h / 2) - (\count($lines) * $node->fontScale) - 1.0,
            'flip-tri' => (\count($lines) * $node->fontScale) + 1.0 - ($node->h / 2),
            default => 0.0,
        };
        [$angle, $x, $y] = self::rotation($shape) ?? [0.0, $node->x + ($offsetX * $node->w), $baseline];
        $texts = [];
        foreach ($lines as $index => $line) {
            \array_push($texts, ...self::text(
                $line,
                $x,
                $y + (((2 * $index) - (\count($lines) - 1) + 0.5) * $node->fontScale),
                $node->textColor ?? '#333333',
                $node->fontScale,
            ));
        }

        return [...$marks, ...(0.0 === $angle ? $texts : self::turned($texts, $angle, $x, $y))];
    }

    /**
     * A `rotated:<degrees>:<x>:<y>` label: pictures turn its text about that point, while cells
     * keep the node's level text.
     *
     * @return ?array{float, float, float}
     */
    private static function rotation(string $shape): ?array
    {
        $parts = \explode(':', $shape);

        return (
            'rotated' === $parts[0] && 4 === \count($parts) && \is_numeric($parts[1]) && \is_numeric($parts[2]) && \is_numeric($parts[3])
                ? [(float) $parts[1], (float) $parts[2], (float) $parts[3]]
                : null
        );
    }

    /**
     * Text marks turned rigidly about a point.
     *
     * @param list<MermaidMark> $texts
     * @return list<MermaidMark>
     */
    private static function turned(array $texts, float $angle, float $x, float $y): array
    {
        $radians = \deg2rad($angle);
        [$cos, $sin] = [\cos($radians), \sin($radians)];

        return \array_map(static function (MermaidMark $mark) use ($cos, $sin, $x, $y, $angle): MermaidMark {
            [$px, $py] = $mark->points[0];

            return new MermaidMark(
                'text',
                [[$x + (($px - $x) * $cos) - (($py - $y) * $sin), $y + (($px - $x) * $sin) + (($py - $y) * $cos)]],
                $mark->text,
                $mark->stroke,
                font: $mark->font,
                fontScale: $mark->fontScale,
                angle: $angle,
            );
        }, $texts);
    }

    /** @param array{float, float, string} $shadow pixel offsets at ten pixels per scene unit */
    private static function shadow(MermaidMark $outline, array $shadow): MermaidMark
    {
        [$dx, $dy, $color] = $shadow;

        return new MermaidMark(
            'polygon',
            \array_map(static fn (array $point): array => [$point[0] + ($dx / 10), $point[1] + ($dy / 10)], $outline->points),
            stroke: $color,
            fill: $color,
            strokeWidth: 0.0,
        );
    }

    private static function box(GraphCluster $cluster): MermaidMark
    {
        return new MermaidMark(
            'polygon',
            [[$cluster->x0, $cluster->y0], [$cluster->x1, $cluster->y0], [$cluster->x1, $cluster->y1], [$cluster->x0, $cluster->y1]],
            stroke: $cluster->color ?? '#333333',
            fill: $cluster->fillColor ?? '#f5f5f5',
            dashed: $cluster->dashed,
            dashes: $cluster->dashes,
            strokeWidth: $cluster->strokeWidth,
        );
    }
}
