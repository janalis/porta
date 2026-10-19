<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Global temporal ranks and hierarchical cross-axis tracks keep lane ownership independent of edges. */
final class SwimlanePlacement
{
    /** @var array<array-key, GraphNode> */
    private array $cards = [];
    /** @var array<array-key, GraphNode> */
    private array $headers = [];
    /** @var array<array-key, list<string>> */
    private array $own = [];
    /** @var array<array-key, list<string>> */
    private array $children = [];
    /** @var array<array-key, int> */
    private array $depth = [];
    /** @var list<string> */
    private array $roots = [];
    /** @var array<array-key, array{float, float}> */
    private array $padding = [];
    /** @var array<array-key, float> */
    private array $ownSize = [];
    /** @var array<array-key, float> */
    private array $offsets = [];
    /** @var array<array-key, float> */
    private array $inner = [];
    /** @var array<array-key, float> */
    private array $outer = [];
    /** @var array<array-key, float> */
    private array $starts = [];
    /** @var array<array-key, float> */
    private array $cross = [];
    private GraphLayout $ranked;
    private readonly bool $horizontal;
    private float $titleWidth = 0.0;
    private float $titleHeight = 0.0;
    private float $axisOffset = 0.0;
    /** @var array<int, GraphNode> */
    private array $nodes = [];
    /** @var array<array-key, GraphCluster> */
    private array $frames = [];
    /** @var array<array-key, float> */
    private array $baseMin = [];

    public function __construct(
        private readonly MermaidGraph $source,
        private readonly ?string $implicit,
    ) {
        $this->horizontal = \in_array($source->direction, ['LR', 'RL'], strict: true);
    }

    /** @return \Generator<int, null, mixed, MermaidPlacement> */
    public function place(): \Generator
    {
        $preparation = $this->rank();
        yield from $preparation;
        $this->ranked = $preparation->getReturn();
        foreach (\array_reverse(\array_keys($this->source->groups)) as $name) {
            $work = $this->measure((string) $name);
            yield from $work;
        }
        $position = 2.0;
        foreach ($this->roots as $name) {
            $work = $this->track($name, $position);
            yield from $work;
            $position += $this->outer[$name] + 6;
        }
        $work = $this->expand($position);
        yield from $work;

        return $work->getReturn();
    }

    /** @return \Generator<int, null, mixed, GraphLayout> */
    private function rank(): \Generator
    {
        $spacing = MermaidLoopSpace::measure($this->source);
        yield from $spacing;
        $this->padding = $spacing->getReturn();
        $groups = $this->groupItems();
        yield from $groups;
        [$items, $edges, $start, $end] = $groups->getReturn();
        foreach ($this->source->nodes as $name => $record) {
            $name = (string) $name;
            $owner = $record['groups'][\count($record['groups']) - 1];
            $this->own[$owner][] = $name;
            [$width, $height] = MermaidNodeMetrics::measure($this->source, $name);
            $id = \count($this->cards);
            $this->cards[$name] = $this->source->styles->decorate(new GraphNode($id, $name, $record['label'], 0.0, 0.0, $width, $height, null, null, dashed: false));
            [$px, $py] = $this->padding[$name] ?? [0.0, 0.0];
            $items[$name] = new GraphNode($id, $name, $record['label'], 0.0, 0.0, $width + (2 * $px), $height + (2 * $py), null, null, dashed: false);
            $edges[] = ['from' => $start[$owner], 'to' => $name, 'length' => 0];
            $edges[] = ['from' => $name, 'to' => $end[$owner], 'length' => 0];
            yield null;
        }
        foreach ($this->source->edges as $edge) {
            if ($edge['from'] !== $edge['to']) {
                $edges[] = ['from' => $end[$edge['from']] ?? $edge['from'], 'to' => $start[$edge['to']] ?? $edge['to'], 'length' => MermaidGraphEdges::rankSpan($edge['arrow'])];
            }
            yield null;
        }
        $maxDepth = \max([0, ...$this->depth]);
        $nested = \max([0.0, ...\array_map(fn (GraphNode $header): float => 0 === $this->depth[$header->name] ? 0.0 : $header->w, $this->headers)]);
        $axisPadding = \max([0.0, ...\array_map(fn (array $padding): float => $padding[$this->horizontal ? 0 : 1], $this->padding)]);
        $this->axisOffset = ($this->horizontal ? $this->titleWidth + ($nested / 2) : $this->titleHeight) + $axisPadding + 8;
        $work = new RankedBoxes()->place($items, $edges, $this->source->direction, $this->source->rankGap + ($this->horizontal ? 0 : 6 * $maxDepth));
        yield from $work;

        return $work->getReturn();
    }

    /** @return \Generator<int, null, mixed, array{array<array-key, GraphNode>, list<array{from: string, to: string, length: int}>, array<array-key, string>, array<array-key, string>}> */
    private function groupItems(): \Generator
    {
        $items = [];
        $edges = [];
        $start = [];
        $end = [];
        foreach ($this->source->groups as $name => $group) {
            $name = (string) $name;
            $index = \count($start);
            $start[$name] = '@lane:start:'.$index;
            $end[$name] = '@lane:end:'.$index;
            $items[$start[$name]] = new GraphNode(-(2 * $index) - 1, $start[$name], '', 0.0, 0.0, 0.0, 0.0, null, null, dashed: false);
            $items[$end[$name]] = new GraphNode(-(2 * $index) - 2, $end[$name], '', 0.0, 0.0, 0.0, 0.0, null, null, dashed: false);
            $edges[] = ['from' => $start[$name], 'to' => $end[$name], 'length' => 0];
            $this->depth[$name] = null === $group['parent'] ? 0 : $this->depth[$group['parent']] + 1;
            if (null === $group['parent']) {
                $this->roots[] = $name;
            }
            if (null !== $group['parent']) {
                $this->children[$group['parent']][] = $name;
                $edges[] = ['from' => $start[$group['parent']], 'to' => $start[$name], 'length' => 0];
                $edges[] = ['from' => $end[$name], 'to' => $end[$group['parent']], 'length' => 0];
            }
            $wrap = MermaidWrap::text($group['label'], width: 24);
            yield from $wrap;
            $label = $wrap->getReturn();
            [$width, $height] = $this->source->styles->measureCluster($name, $label);
            $this->headers[$name] = new GraphNode(-$index - 1, $name, '' === $label ? $name : $label, 0.0, 0.0, $width, $height, null, null, dashed: false, drawLabel: '' === $label ? '' : null);
            if (0 === $this->depth[$name]) {
                $this->titleWidth = \max($this->titleWidth, $width);
                $this->titleHeight = \max($this->titleHeight, $height);
            }
            yield null;
        }

        return [$items, $edges, $start, $end];
    }

    /** @return \Generator<int, null, mixed, null> */
    private function measure(string $name): \Generator
    {
        $bins = [];
        foreach ($this->own[$name] ?? [] as $id) {
            $node = $this->ranked->nodes[$this->cards[$id]->id];
            $reverse = \in_array($this->source->direction, ['RL', 'BT'], strict: true) ? 1 : -1;
            $base = $this->horizontal ? $node->x + (($reverse * $node->w) / 2) : $node->y + (($reverse * $node->h) / 2);
            $bins[\sprintf('%.6f', $base)][] = $id;
            yield null;
        }
        $own = 0.0;
        foreach ($bins as $ids) {
            $position = 0.0;
            foreach ($ids as $id) {
                $node = $this->ranked->nodes[$this->cards[$id]->id];
                $extent = $this->horizontal ? $node->h : $node->w;
                $this->offsets[$id] = $position + ($extent / 2);
                $position += $extent + 6;
                yield null;
            }
            $own = \max($own, $position - 6);
        }
        $this->ownSize[$name] = $own;
        $body = $own;
        foreach ($this->children[$name] ?? [] as $child) {
            $body += ($body > 0 ? 6 : 0) + $this->outer[$child];
            yield null;
        }
        $header = $this->horizontal && $this->depth[$name] > 0 ? $this->headers[$name]->h + 4 : 0.0;
        $minimum = $this->horizontal ? $this->headers[$name]->h : $this->headers[$name]->w;
        $this->inner[$name] = \max(8.0, $minimum + 8, $body + $header + 8);
        $this->outer[$name] = $this->inner[$name] + (2 * ($this->padding[$name][$this->horizontal ? 1 : 0] ?? 0.0));

        return null;
    }

    /** @return \Generator<int, null, mixed, null> */
    private function track(string $name, float $position): \Generator
    {
        $this->starts[$name] = $position + ($this->padding[$name][$this->horizontal ? 1 : 0] ?? 0.0);
        $header = $this->horizontal && $this->depth[$name] > 0 ? $this->headers[$name]->h + 4 : 0.0;
        $position = $this->starts[$name] + $header + 4;
        foreach ($this->own[$name] ?? [] as $id) {
            $this->cross[$id] = $position + $this->offsets[$id];
            yield null;
        }
        $position += $this->ownSize[$name];
        $occupied = $this->ownSize[$name] > 0;
        foreach ($this->children[$name] ?? [] as $child) {
            $position += $occupied ? 6 : 0;
            $work = $this->track($child, $position);
            yield from $work;
            $position += $this->outer[$child];
            $occupied = true;
        }

        return null;
    }

    /** @return \Generator<int, null, mixed, MermaidPlacement> */
    private function expand(float $crossExtent): \Generator
    {
        foreach ($this->cards as $name => $node) {
            $placed = $this->ranked->nodes[$node->id];
            $owner = $this->source->nodes[$name]['groups'][\count($this->source->nodes[$name]['groups']) - 1];
            $axis = ($this->horizontal ? $placed->x : $placed->y) + $this->axisOffset + ($this->horizontal ? 0 : 6 * ($this->depth[$owner] + 1));
            $this->nodes[$node->id] = $this->horizontal ? $node->at($axis, $this->cross[$name]) : $node->at($this->cross[$name], $axis);
            yield null;
        }
        foreach (\array_reverse(\array_keys($this->source->groups)) as $name) {
            $work = $this->frame((string) $name);
            yield from $work;
        }
        $end = ($this->horizontal ? $this->ranked->width : $this->ranked->height) + $this->axisOffset + (6 * \max([0, ...$this->depth])) + 16;
        foreach ($this->frames as $frame) {
            $end = \max($end, ($this->horizontal ? $frame->x1 : $frame->y1) + 8);
        }
        $endpoints = [];
        foreach ($this->cards as $name => $node) {
            $endpoints[$name] = $this->nodes[$node->id];
        }
        $clusters = [];
        foreach ($this->source->groups as $name => $group) {
            $name = (string) $name;
            $frame = $this->frames[$name];
            if (null === $group['parent']) {
                $frame = new GraphCluster(
                    $name,
                    '',
                    $this->horizontal ? 2.0 : $frame->x0,
                    $this->horizontal ? $frame->y0 : 2.0,
                    $this->horizontal ? $end : $frame->x1,
                    $this->horizontal ? $frame->y1 : $end,
                    $frame->color,
                    labelPos: null,
                    fillColor: $frame->fillColor,
                    textColor: $frame->textColor,
                    strokeWidth: $frame->strokeWidth,
                    dashed: $frame->dashed,
                    font: $frame->font,
                    fontScale: $frame->fontScale,
                    dashes: $frame->dashes,
                );
            }
            $this->frames[$name] = $frame;
            $clusters[] = $frame;
            $header = $this->headers[$name];
            $endpoints[$name] = new GraphNode(
                $header->id,
                $name,
                $header->label,
                ($frame->x0 + $frame->x1) / 2,
                ($frame->y0 + $frame->y1) / 2,
                $frame->x1 - $frame->x0,
                $frame->y1 - $frame->y0,
                color: null,
                fillColor: null,
                dashed: false,
            );
            if ($name !== $this->implicit) {
                $this->nodes[$header->id] = $this->header($name, $frame);
            }
            yield null;
        }
        $width = $this->horizontal ? $end + 4 : $crossExtent;
        $height = $this->horizontal ? $crossExtent : $end + 4;

        return new MermaidPlacement(new GraphLayout($width, $height, $this->nodes, edges: [], clusters: $clusters), $endpoints, directions: []);
    }

    /** @return \Generator<int, null, mixed, null> */
    private function frame(string $name): \Generator
    {
        $minimum = \INF;
        $maximum = -\INF;
        $base = \INF;
        foreach ($this->own[$name] ?? [] as $id) {
            $node = $this->nodes[$this->cards[$id]->id];
            $ranked = $this->ranked->nodes[$node->id];
            $padding = $this->padding[$id][$this->horizontal ? 0 : 1] ?? 0.0;
            $minimum = \min($minimum, ($this->horizontal ? $node->x - ($node->w / 2) : $node->y - ($node->h / 2)) - $padding);
            $maximum = \max($maximum, ($this->horizontal ? $node->x + ($node->w / 2) : $node->y + ($node->h / 2)) + $padding);
            $base = \min($base, $ranked->y - ($node->h / 2));
            yield null;
        }
        foreach ($this->children[$name] ?? [] as $child) {
            $frame = $this->frames[$child];
            $padding = $this->padding[$child][$this->horizontal ? 0 : 1] ?? 0.0;
            $minimum = \min($minimum, ($this->horizontal ? $frame->x0 : $frame->y0) - $padding);
            $maximum = \max($maximum, ($this->horizontal ? $frame->x1 : $frame->y1) + $padding);
            $base = \min($base, $this->baseMin[$child]);
            yield null;
        }
        $this->baseMin[$name] = \is_finite($base) ? $base : 4.0;
        $minimum = \is_finite($minimum) ? $minimum - 4 : $this->axisOffset + 4;
        $maximum = \is_finite($maximum) ? $maximum + 4 : $minimum + 8;
        if ($this->horizontal) {
            $width = \max($maximum - $minimum, $this->headers[$name]->w + 4);
            $center = ($maximum + $minimum) / 2;
            $minimum = $center - ($width / 2);
            $maximum = $center + ($width / 2);
        }
        if (!$this->horizontal) {
            $minimum = $this->baseMin[$name] + $this->axisOffset + (6 * $this->depth[$name]);
        }
        // Unstyled lanes take a color theme's palette slots in lane order, else the cluster roles.
        $slot = (int) -$this->headers[$name]->id - 1;
        $this->frames[$name] = $this->source->styles->decorateCluster(
            new GraphCluster(
                $name,
                '',
                $this->horizontal ? $minimum : $this->starts[$name],
                $this->horizontal ? $this->starts[$name] : $minimum,
                $this->horizontal ? $maximum : $this->starts[$name] + $this->inner[$name],
                $this->horizontal ? $this->starts[$name] + $this->inner[$name] : $maximum,
                color: MermaidColorSlots::stroke(null, $slot),
                labelPos: null,
                fillColor: MermaidColorSlots::fill(null, $slot),
                font: GraphFont::Bold,
            ),
        );

        return null;
    }

    private function header(string $name, GraphCluster $frame): GraphNode
    {
        $header = $this->headers[$name];
        $root = 0 === $this->depth[$name];
        $x = $this->horizontal && $root ? $frame->x0 + ($this->titleWidth / 2) : ($frame->x0 + $frame->x1) / 2;
        $y = $this->horizontal && $root ? ($frame->y0 + $frame->y1) / 2 : $frame->y0 + ($header->h / 2) + 1;

        return new GraphNode(
            $header->id,
            $name,
            $header->label,
            $x,
            $y,
            $this->horizontal && $root ? $this->titleWidth : $frame->x1 - $frame->x0,
            $header->h,
            color: null,
            fillColor: null,
            dashed: false,
            drawLabel: $header->drawLabel,
            textColor: $frame->textColor ?? '@titleColor',
            font: $frame->font,
            fontScale: $frame->fontScale,
        );
    }
}
