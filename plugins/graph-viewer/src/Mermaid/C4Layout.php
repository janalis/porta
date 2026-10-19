<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Nested role cards and frames share the same scene as their styled source-linked relationships. */
final readonly class C4Layout
{
    private MermaidOptions $options;

    public function __construct(?MermaidOptions $options = null)
    {
        $this->options = $options ?? MermaidOptions::defaults();
    }

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(C4Diagram $source): \Generator
    {
        $preparation = $this->elements($source);
        yield from $preparation;
        $elements = $preparation->getReturn();
        $placement = new C4Placement($source, $elements)->layout();
        yield from $placement;
        $graph = $placement->getReturn();
        $routing = $this->relations($source, $graph, $elements);
        yield from $routing;
        $graph = new GraphLayout($graph->width, $graph->height, $graph->nodes, $routing->getReturn(), $graph->clusters);
        $loops = MermaidFrameRoutes::layout($graph, \array_flip($elements->groups));
        yield from $loops;
        $offsets = $this->offsets($source, $loops->getReturn());
        yield from $offsets;
        $frames = $this->deploymentFrames($source, $offsets->getReturn(), $elements);
        yield from $frames;
        $bounds = MermaidCaptionBounds::layout($frames->getReturn());
        yield from $bounds;
        $graph = $bounds->getReturn();
        $rows = MermaidOrigins::rows($graph->height, $source->origin);
        foreach ($graph->nodes as $id => $node) {
            for ($row = (int) (($node->y - ($node->h / 2)) / 2); $row <= (int) (($node->y + ($node->h / 2)) / 2); ++$row) {
                $rows[$row] = $elements->origins[$id];
                yield null;
            }
        }
        $scene = new MermaidScene($graph, $rows, $elements->legend, $elements->origins, $elements->shapes, runs: $elements->runs);
        if (null !== $source->title) {
            $caption = MermaidTitle::apply($scene, $source->title);
            yield from $caption;

            return $caption->getReturn();
        }

        return $scene;
    }

    /** @return \Generator<int, null, mixed, C4Elements> */
    private function elements(C4Diagram $source): \Generator
    {
        $nodes = [];
        $ids = [];
        $groups = [];
        $shapes = [];
        $origins = [];
        $legend = $source->metadata;
        $runs = [];
        foreach ($source->nodes as $alias => $record) {
            $id = \count($nodes);
            $ids[$alias] = $id;
            $card = $this->card($id, (string) $alias, $record);
            yield from $card;
            [$nodes[$id], $shapes[$id], $runs['node:'.$id]] = $card->getReturn();
            $origins[$id] = $record['origin'];
            $legend[] = ['text' => $alias.': '.$nodes[$id]->label, 'origin' => $record['origin']];
            foreach (['sprite', 'tags', 'link', 'legendText', 'legendSprite'] as $key) {
                $value = $record['attributes'][$key] ?? '';
                if ('' !== $value) {
                    $legend[] = ['text' => $alias.' '.$key.': '.$value, 'origin' => $record['origin']];
                }
            }
            yield null;
        }
        foreach ($source->groups as $alias => $record) {
            $wrap = MermaidWrap::text(C4Styles::groupLabel($record['attributes']), width: 40);
            yield from $wrap;
            $label = $wrap->getReturn();
            [$scale] = MermaidFonts::configured($this->options, 'c4', 'boundary', default: 14.0);
            [$width, $height] = MermaidText::box($label, $scale);
            $id = \count($nodes);
            $groups[$alias] = $id;
            $nodes[$id] = new GraphNode(
                $id,
                (string) $alias,
                $label,
                $width / 2,
                $height / 2,
                $width,
                $height,
                color: null,
                fillColor: null,
                dashed: false,
                drawLabel: '',
                font: GraphFont::Bold,
                fontScale: $scale,
            );
            $origins[$id] = $record['origin'];
            $shapes[$id] = 'text';
            $legend[] = ['text' => $alias.': '.$label, 'origin' => $record['origin']];
            yield null;
        }
        $edgeLabels = [];
        $index = 0;
        foreach ($source->edges as $key => $record) {
            $attrs = $record['attributes'];
            $text = ('C4Dynamic' === $source->family ? ++$index.': ' : '').($attrs['label'] ?? '');
            foreach (['techn', 'descr'] as $field) {
                $value = $attrs[$field] ?? '';
                if ('' !== $value) {
                    $text .= "\n".('techn' === $field ? '['.$value.']' : $value);
                }
            }
            $wrap = MermaidWrap::text(MermaidText::decode($text), width: 32);
            yield from $wrap;
            $edgeLabels[$key] = $wrap->getReturn();
            $legend[] = ['text' => ($attrs['from'] ?? '').' → '.($attrs['to'] ?? '').' : '.$edgeLabels[$key], 'origin' => $record['origin']];
        }

        return new C4Elements($nodes, $ids, $groups, $shapes, $origins, $legend, $edgeLabels, $runs);
    }

    /**
     * A card: its name bold over its role and description, all at the pinned `<type>FontSize`,
     * the role and description at `<type>FontWeight`.
     *
     * @param array{macro: string, attributes: array<string, string>, parent: ?string, origin: int, statement: MermaidStatement} $record
     * @return \Generator<int, null, mixed, array{GraphNode, string, list<list<array{string, GraphFont}>>}>
     */
    private function card(int $id, string $alias, array $record): \Generator
    {
        [$name, $rest] = C4Styles::parts($record['macro'], $record['attributes']);
        $wrap = MermaidWrap::text($name, width: 40);
        yield from $wrap;
        $title = \explode("\n", $wrap->getReturn());
        $wrap = MermaidWrap::text($rest, width: 40);
        yield from $wrap;
        $body = \explode("\n", $wrap->getReturn());
        $label = \implode("\n", [...$title, ...$body]);
        [$scale, $face] = MermaidFonts::configured($this->options, 'c4', C4Styles::type($record['macro']), default: 14.0);
        [$width, $height] = MermaidText::box($label, $scale);
        $shape = self::shape($record['macro'], $record['attributes'], $record['statement']);
        // A person's head, and as pinned the 48 pixels a `$sprite` reserves, push the text down two rows each.
        $lead = ('c4-person' === $shape ? 2 : 0) + ('' === ($record['attributes']['sprite'] ?? '') ? 0 : 2);
        $height += 2 * $lead;
        $colors = C4Styles::colors($record['macro'], $record['attributes'], $record['statement'], $this->options);
        $node = new GraphNode(
            $id,
            $alias,
            $label,
            $width / 2,
            $height / 2,
            $width,
            $height,
            $colors['stroke'],
            $colors['fill'],
            dashed: false,
            drawLabel: 0 === $lead ? null : \str_repeat("\n", $lead).$label,
            textColor: $colors['text'],
            font: $face,
            fontScale: $scale,
        );
        $runs = [
            ...\array_fill(0, $lead, [['', $face]]),
            ...\array_map(static fn (string $line): array => [[$line, GraphFont::Bold]], $title),
            ...\array_map(static fn (string $line): array => [[$line, $face]], $body),
        ];

        return [$node, $shape, $runs];
    }

    /** @param array<string, string> $attributes */
    private static function shape(string $macro, array $attributes, MermaidStatement $statement): string
    {
        $custom = $attributes['shape'] ?? '';
        if ('' !== $custom) {
            return match ($custom) {
                'person', 'c4-person' => 'c4-person',
                'queue', 'c4-queue' => 'c4-queue',
                // Pinned C4 draws any registered flowchart shape.
                default => FlowchartShapes::known($custom) ? FlowchartShapes::canonical($custom) : throw $statement->error('Unknown C4 shape '.$custom),
            };
        }

        return match (true) {
            \str_starts_with($macro, 'Person') => 'c4-person',
            \str_contains($macro, 'Queue') => 'c4-queue',
            \str_contains($macro, 'Db') => 'cylinder',
            default => 'rounded',
        };
    }

    /** @return \Generator<int, null, mixed, list<GraphEdge>> */
    private function relations(C4Diagram $source, GraphLayout $graph, C4Elements $elements): \Generator
    {
        $edges = [];
        // Pinned `messageFontSize` and `messageFontWeight` set every relationship caption.
        [$scale, $face] = MermaidFonts::configured($this->options, 'c4', 'message', default: 12.0);
        foreach ($source->edges as $key => $record) {
            $attrs = $record['attributes'];
            $from = $this->endpoint($attrs['from'] ?? '', $graph, $elements);
            $to = $this->endpoint($attrs['to'] ?? '', $graph, $elements);
            $start = MermaidOutline::boundary($from, $elements->shapes[$from->id], $to->x, $to->y);
            $end = MermaidOutline::boundary($to, $elements->shapes[$to->id], $from->x, $from->y);
            if ('Rel_Back' === $record['macro']) {
                [$start, $end] = [$end, $start];
            }
            $label = $elements->edgeLabels[$key];
            $color = MermaidColor::parse('stroke', C4Styles::slot($attrs, key: 'lineColor', fallback: '#444444'), $record['statement'], family: 'C4');
            $textColor = MermaidColor::parse('color', C4Styles::slot($attrs, key: 'textColor', fallback: '#444444'), $record['statement'], family: 'C4');
            $position = [
                ($start[0] + $end[0]) / 2,
                ($start[1] + $end[1]) / 2,
            ];
            $edges[] = new GraphEdge(
                $from->id,
                $to->id,
                [$start, $end],
                $end,
                \min($start[0], $end[0]),
                \min($start[1], $end[1]),
                \max($start[0], $end[0]),
                \max($start[1], $end[1]),
                $color,
                $label,
                $position,
                dashed: false,
                textColor: $textColor,
                font: $face,
                fontScale: $scale,
            );
            if ('BiRel' === $record['macro']) {
                $edges[] = new GraphEdge(
                    $to->id,
                    $from->id,
                    [$end, $start],
                    $start,
                    \min($start[0], $end[0]),
                    \min($start[1], $end[1]),
                    \max($start[0], $end[0]),
                    \max($start[1], $end[1]),
                    $color,
                    null,
                    null,
                    dashed: false,
                    decoration: true,
                );
            }
            yield null;
        }

        return $edges;
    }

    private function endpoint(string $name, GraphLayout $graph, C4Elements $elements): GraphNode
    {
        if (\array_key_exists($name, $elements->ids)) {
            return $graph->nodes[$elements->ids[$name]];
        }
        $id = $elements->groups[$name];
        $cluster = \array_find($graph->clusters, static fn ($cluster): bool => $name === $cluster->name);
        \assert(null !== $cluster, description: 'Every authored C4 boundary has a placed frame');

        return new GraphNode(
            $id,
            $name,
            $cluster->label,
            ($cluster->x0 + $cluster->x1) / 2,
            ($cluster->y0 + $cluster->y1) / 2,
            $cluster->x1 - $cluster->x0,
            $cluster->y1 - $cluster->y0,
            $cluster->color,
            $cluster->fillColor,
            dashed: false,
        );
    }

    /** @return \Generator<int, null, mixed, GraphLayout> */
    private function offsets(C4Diagram $source, GraphLayout $graph): \Generator
    {
        $edges = [];
        foreach ($graph->edges as $edge) {
            $position = $edge->labelPos;
            if (!$edge->decoration && null !== $position) {
                $key = $graph->nodes[$edge->tail]->name."\0".$graph->nodes[$edge->head]->name;
                $record = $source->edges[$key];
                $position[0] += self::offset($record['attributes']['offsetX'] ?? '', $record['statement']);
                $position[1] += self::offset($record['attributes']['offsetY'] ?? '', $record['statement']);
            }
            $edges[] = new GraphEdge(
                tail: $edge->tail,
                head: $edge->head,
                points: $edge->points,
                arrowTip: $edge->arrowTip,
                minX: $edge->minX,
                minY: $edge->minY,
                maxX: $edge->maxX,
                maxY: $edge->maxY,
                color: $edge->color,
                label: $edge->label,
                labelPos: $position,
                dashed: $edge->dashed,
                ribbon: $edge->ribbon,
                strokeWidth: $edge->strokeWidth,
                textColor: $edge->textColor,
                decoration: $edge->decoration,
                font: $edge->font,
                fontScale: $edge->fontScale,
                dashes: $edge->dashes,
            );
            yield null;
        }

        return new GraphLayout($graph->width, $graph->height, $graph->nodes, $edges, $graph->clusters);
    }

    /** @return \Generator<int, null, mixed, GraphLayout> */
    private function deploymentFrames(C4Diagram $source, GraphLayout $graph, C4Elements $elements): \Generator
    {
        $edges = $graph->edges;
        foreach ($graph->clusters as $frame) {
            $macro = $source->groups[$frame->name]['macro'];
            if (!\in_array($macro, ['Deployment_Node', 'Node', 'Node_L', 'Node_R'], strict: true)) {
                continue;
            }
            \array_push($edges, ...C4Symbols::frame($frame, $macro, $elements->groups[$frame->name]));
            yield null;
        }

        return new GraphLayout($graph->width, $graph->height, $graph->nodes, $edges, $graph->clusters);
    }

    private static function offset(string $text, MermaidStatement $statement): float
    {
        $match = [];
        if (1 !== \preg_match('/^[\t ]*([+-]?\d+)/', $text, $match)) {
            return 0.0;
        }
        if (\abs((int) $match[1]) > 10_000) {
            throw $statement->error('C4 relationship offset exceeds its numeric limit');
        }

        return (int) $match[1] / 10.0;
    }
}
