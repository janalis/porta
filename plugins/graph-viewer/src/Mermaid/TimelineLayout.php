<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Periods follow the timeline axis; their event chains follow its perpendicular. */
final readonly class TimelineLayout
{
    public function __construct(
        private ?MermaidPalette $palette = null,
        private ?MermaidOptions $options = null,
    ) {}

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(TimelineDiagram $source): \Generator
    {
        $nodes = [];
        $edges = [];
        $legend = [];
        $nodeOrigins = [];
        $elements = [];
        $top = 2.0;
        $metadata = $source->metadata;
        $metadata['title'] ??= ['label' => 'Timeline', 'origin' => $source->origin];
        foreach ($metadata as $kind => $item) {
            $work = $this->node(\count($nodes), $kind, $kind.': '.$item['label'], x: 2.0, y: $top);
            yield from $work;
            $node = $work->getReturn();
            $nodes[$node->id] = $node;
            $nodeOrigins[$node->id] = $item['origin'];
            $legend[] = ['text' => $kind.': '.$item['label'], 'origin' => $item['origin']];
            $top += $node->h + 4;
        }
        $cursor = 2.0;
        $previous = null;
        foreach ($this->items($source) as $item) {
            $horizontal = 'LR' === $source->direction;
            $x = $horizontal ? $cursor : 2.0;
            $y = $horizontal ? $top : $top + $cursor - 2.0;
            $work = $this->node(\count($nodes), $item['name'], $item['label'], $x, $y);
            yield from $work;
            $node = $work->getReturn();
            $nodes[$node->id] = $node;
            $nodeOrigins[$node->id] = $item['origin'];
            $legend[] = ['text' => $item['label'], 'origin' => $item['origin']];
            if (null !== $previous && $item['period']) {
                $edges[] = $this->edge($previous, $node, $source->direction);
            }
            if ($item['period']) {
                $previous = $node;
                $elements[$node->id] = $item['origin'];
            }
            $eventCursor = $horizontal ? $y + $node->h + 4 : $x + 44;
            $last = $node;
            $extent = $node->h;
            foreach ($item['events'] as $index => $event) {
                $eventX = $horizontal ? $x : $eventCursor;
                $eventY = $horizontal ? $eventCursor : $y;
                $work = $this->node(\count($nodes), $item['name'].'-event-'.$index, $event['label'], $eventX, $eventY);
                yield from $work;
                $eventNode = $work->getReturn();
                $nodes[$eventNode->id] = $eventNode;
                $nodeOrigins[$eventNode->id] = $event['origin'];
                $elements[$eventNode->id] = $event['origin'];
                $legend[] = ['text' => $item['label'].' : '.$event['label'], 'origin' => $event['origin']];
                $edges[] = $this->edge($last, $eventNode, $horizontal ? 'TD' : 'LR');
                $last = $eventNode;
                $eventCursor += $horizontal ? $eventNode->h + 4 : 44;
                $extent = \max($extent, $eventNode->h);
                yield null;
            }
            $cursor += $horizontal ? 44 : $extent + 4;
            yield null;
        }

        $scene = $this->scene($nodes, $edges, $nodeOrigins, $legend, $source->origin, $top);
        yield from $scene;
        $result = $scene->getReturn();
        $paint = new TimelinePaint($this->palette ?? MermaidPalette::defaults('timeline'));

        $multicolor = !($this->options ?? MermaidOptions::defaults())->flag('timeline', 'disableMulticolor');

        return new MermaidScene($paint->paint($result->graph, self::sections($source, $multicolor)), $result->origins, $result->legend, $elements);
    }

    /**
     * Each node's color index: a section's own number, its periods' and events' too; without
     * sections each period counts on.
     *
     * @return array<string, int>
     */
    // @mago-expect lint:no-boolean-flag-parameter -- Pinned `disableMulticolor` is the one switch between the two numberings.
    private static function sections(TimelineDiagram $source, bool $multicolor): array
    {
        $indices = [];
        foreach (\array_keys($source->sections) as $index) {
            $indices['section-'.$index] = (int) $index;
        }
        foreach ($source->periods as $number => $period) {
            // Without sections each period takes the next color, unless pinned `disableMulticolor`.
            $counted = $multicolor ? (int) $number : 0;
            $index = [] === $source->sections ? $counted : (int) ($period['section'] ?? 0);
            $indices['period-'.$number] = $index;
            foreach (\array_keys($period['events']) as $event) {
                $indices['period-'.$number.'-event-'.$event] = $index;
            }
        }

        return $indices;
    }

    /**
     * @param array<int, GraphNode> $nodes
     * @param list<GraphEdge> $edges
     * @param array<int, int> $nodeOrigins
     * @param list<array{text: string, origin: int}> $legend
     * @return \Generator<int, null, mixed, MermaidScene>
     */
    private function scene(array $nodes, array $edges, array $nodeOrigins, array $legend, int $header, float $top): \Generator
    {
        $width = 6.0;
        $height = $top;
        $origins = [0];
        foreach ($nodes as $node) {
            $width = \max($width, $node->x + ($node->w / 2) + 2);
            $height = \max($height, $node->y + ($node->h / 2) + 2);
            for ($row = (int) (($node->y - ($node->h / 2)) / 2); $row <= (int) (($node->y + ($node->h / 2)) / 2); ++$row) {
                $origins[$row] = $nodeOrigins[$node->id];
                yield null;
            }
        }
        $rows = MermaidOrigins::rows($height, $header);
        foreach ($origins as $row => $origin) {
            $rows[$row] = $origin;
        }

        return new MermaidScene(new GraphLayout($width, $height, $nodes, $edges, clusters: []), $rows, $legend);
    }

    /** @return \Generator<int, null, mixed, GraphNode> */
    private function node(int $id, string $name, string $text, float $x, float $y): \Generator
    {
        $work = MermaidWrap::text($text, width: 36);
        yield from $work;
        $label = $work->getReturn();
        [, $height] = MermaidText::box($label);

        return new GraphNode($id, $name, $label, $x + 20, $y + ($height / 2), w: 40.0, h: $height, color: null, fillColor: null, dashed: false);
    }

    private function edge(GraphNode $from, GraphNode $to, string $direction): GraphEdge
    {
        $horizontal = 'LR' === $direction;
        $start = [$from->x + ($horizontal ? $from->w / 2 : 0), $from->y + ($horizontal ? 0 : $from->h / 2)];
        $tip = [$to->x - ($horizontal ? $to->w / 2 : 0), $to->y - ($horizontal ? 0 : $to->h / 2)];
        $end = [$tip[0] - ($horizontal ? 0.5 : 0), $tip[1] - ($horizontal ? 0 : 0.5)];

        return new GraphEdge(
            $from->id,
            $to->id,
            [$start, $end],
            $tip,
            \min($start[0], $tip[0]),
            \min($start[1], $tip[1]),
            \max($start[0], $tip[0]),
            \max($start[1], $tip[1]),
            color: null,
            label: null,
            labelPos: null,
            dashed: false,
        );
    }

    /** @return \Generator<int, array{name: string, label: string, origin: int, period: bool, events: list<array{label: string, origin: int}>}> */
    private function items(TimelineDiagram $source): \Generator
    {
        $section = 0;
        foreach ($source->periods as $index => $period) {
            while (null !== $period['section'] && $section <= $period['section']) {
                $item = $source->sections[$section];
                yield ['name' => 'section-'.$section++, 'label' => 'section: '.$item['label'], 'origin' => $item['origin'], 'period' => false, 'events' => []];
            }
            yield ['name' => 'period-'.$index, 'label' => $period['label'], 'origin' => $period['origin'], 'period' => true, 'events' => $period['events']];
        }
        while ($section < \count($source->sections)) {
            $item = $source->sections[$section];
            yield ['name' => 'section-'.$section++, 'label' => 'section: '.$item['label'], 'origin' => $item['origin'], 'period' => false, 'events' => []];
        }
    }
}
