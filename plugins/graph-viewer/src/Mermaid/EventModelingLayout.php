<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Chronological cards occupy role lanes; returning to a lane always reserves readable space. */
final class EventModelingLayout
{
    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(EventModelingDiagram $source): \Generator
    {
        $resolve = EventModelingState::resolve($source);
        yield from $resolve;
        $state = $resolve->getReturn();
        $prepare = $this->cards($source);
        yield from $prepare;
        [$cards, $shapes] = $prepare->getReturn();
        $heights = [];
        $headers = [];
        $left = 8.0;
        foreach ($state->lanes as $lane => $label) {
            $wrap = MermaidWrap::text($label, width: 24);
            yield from $wrap;
            $label = $wrap->getReturn();
            [$width, $height] = MermaidText::box($label);
            $left = \max($left, $width + 6);
            $headers[$lane] = [$label, $width, $height];
            $heights[$lane] = $height + 6;
            yield null;
        }
        foreach ($cards as $index => $card) {
            $lane = $state->frameLanes[$index];
            $heights[$lane] = \max($heights[$lane], $card->h + 6);
            yield null;
        }
        $ys = [];
        $top = 4.0;
        foreach ($heights as $lane => $height) {
            $ys[$lane] = $top + ($height / 2);
            $top += $height + 2;
            yield null;
        }
        $nodes = [];
        $right = [];
        $previous = null;
        $width = $left + 8;
        foreach ($cards as $index => $card) {
            $lane = $state->frameLanes[$index];
            $x = null === $previous ? $left : $previous->x + ($previous->w / 2) - 7;
            $x = \max($left, ($right[$lane] ?? ($left - 2)) + 2, $x);
            $node = $card->at($x + ($card->w / 2), $ys[$lane]);
            $nodes[$index] = $node;
            $right[$lane] = $x + $card->w;
            $width = \max($width, $right[$lane] + 4);
            $previous = $node;
            yield null;
        }
        $clusters = [];
        foreach ($headers as $lane => [$label, $headerWidth, $headerHeight]) {
            $clusters[] = new GraphCluster(
                '@event-lane:'.$lane,
                '',
                x0: 0.0,
                y0: $ys[$lane] - ($heights[$lane] / 2),
                x1: $width,
                y1: $ys[$lane] + ($heights[$lane] / 2),
                color: '@emSwimlaneBackgroundStroke|=#f0f0f0',
                labelPos: null,
                fillColor: '@emSwimlaneBackgroundOdd|=#fafafa',
            );
            $id = -$lane - 1;
            $nodes[$id] = new GraphNode($id, '@event-lane:'.$lane, $label, 2 + ($headerWidth / 2), $ys[$lane], $headerWidth, $headerHeight, null, null, dashed: false, textColor: '@textColor');
            $shapes[$id] = 'text';
            yield null;
        }
        $edges = [];
        foreach ($state->relations as $relation) {
            $from = $nodes[$relation['from']];
            $to = $nodes[$relation['to']];
            $edges[] = MermaidGraphEdges::edge($from, $to, '', '-->', 'rect', 'rect');
            yield null;
        }
        $scene = $this->scene($source, new GraphLayout($width, \max(8.0, $top), $nodes, $edges, $clusters), $shapes);
        yield from $scene;

        return $scene->getReturn();
    }

    /** @param array<int, string> $shapes
     * @return \Generator<int, null, mixed, MermaidScene>
     */
    private function scene(EventModelingDiagram $source, GraphLayout $layout, array $shapes): \Generator
    {
        $bounds = MermaidCaptionBounds::layout($layout);
        yield from $bounds;
        $graph = $bounds->getReturn();
        $origins = MermaidOrigins::rows($graph->height, $source->origin);
        $elements = [];
        foreach ($source->frames as $index => $frame) {
            $node = $graph->nodes[$index];
            $elements[$index] = $frame['statement']->line;
            for ($row = (int) \floor(($node->y - ($node->h / 2)) / 2); $row <= (int) \ceil(($node->y + ($node->h / 2)) / 2); ++$row) {
                $origins[$row] = $frame['statement']->line;
                yield null;
            }
        }
        $legend = EventModelingLabels::legend($source);
        yield from $legend;
        $scene = new MermaidScene($graph, $origins, $legend->getReturn(), $elements, $shapes);
        if (\array_key_exists('title', $source->metadata)) {
            $title = $source->metadata['title'];
            $text = \preg_replace('/^\s*title[\t ]*/', replacement: '', subject: $title->text) ?? $title->text;
            $caption = MermaidTitle::apply($scene, new MermaidStatement($text, $title->offset, $title->line, $title->column));
            yield from $caption;

            return $caption->getReturn();
        }

        return $scene;
    }

    /** @return \Generator<int, null, mixed, array{list<GraphNode>, array<int, string>}> */
    private function cards(EventModelingDiagram $source): \Generator
    {
        $data = [];
        foreach ($source->dataEntities as $entity) {
            $data[$entity['name']] ??= $entity['dataBlockValue'];
            yield null;
        }
        $cards = [];
        $shapes = [];
        foreach ($source->frames as $index => $frame) {
            $parts = \explode('.', $frame['entityIdentifier']);
            $name = 2 === \count($parts) ? $parts[1] : $frame['entityIdentifier'];
            $raw = null !== $frame['dataReference'] && \array_key_exists($frame['dataReference'], $data) ? $data[$frame['dataReference']] : $frame['dataInlineValue'];
            $attributes = null === $raw ? [] : [['text' => EventModelingLabels::body($raw), 'origin' => $frame['statement']->line]];
            $label = ErCard::label($frame['name'].' · '.MermaidText::decode($name), $attributes);
            yield from $label;
            $text = $label->getReturn()['label'];
            [$width, $height] = MermaidText::box($text);
            [$fill, $stroke] = EventModelingLabels::colors($frame['modelEntityType']);
            $cards[] = new GraphNode($index, $frame['name'], $text, x: 0.0, y: 0.0, w: \max(12.0, $width), h: \max(6.0, $height), color: $stroke, fillColor: $fill, dashed: $frame['reset']);
            $shapes[$index] = $label->getReturn()['shape'];
            yield null;
        }

        return [$cards, $shapes];
    }
}
