<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Collapse is a semantic projection: hidden endpoints map to their outermost summary. */
final class AgentflowLayout
{
    /** Pinned `KIND_SLOT` by resolved native shape; every other shape is a task (slot 1). */
    private const array KINDS = ['subroutine' => 0, 'diamond' => 2, 'lean-right' => 3, 'lin-doc' => 4, 'hexagon' => 6];

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(AgentflowDiagram $diagram): \Generator
    {
        $graph = clone $diagram->graph;
        $hidden = [];
        foreach ($graph->groups as $id => $group) {
            /** @var list<string> $ancestors */
            $ancestors = [];
            $parent = $group['parent'];
            while (null !== $parent) {
                \array_unshift($ancestors, $parent);
                $parent = $graph->groups[$parent]['parent'];
            }
            foreach ($ancestors as $ancestor) {
                if ('collapsed' !== ($diagram->metadata[$ancestor]['view'] ?? null)) {
                    continue;
                }
                $hidden[$id] = $ancestor;
                break;
            }
            yield null;
        }
        foreach ($graph->nodes as $id => $node) {
            foreach ($node['groups'] as $group) {
                if ('collapsed' !== ($diagram->metadata[$group]['view'] ?? null)) {
                    continue;
                }
                $hidden[$id] = $hidden[$group] ?? $group;
                break;
            }
            yield null;
        }
        foreach ($graph->groups as $id => $group) {
            if (\array_key_exists($id, $hidden)) {
                unset($graph->groups[$id]);
                yield null;
                continue;
            }
            if ('collapsed' === ($diagram->metadata[$id]['view'] ?? null)) {
                /** @var list<string> $ancestors */
                $ancestors = [];
                $parent = $group['parent'];
                while (null !== $parent) {
                    \array_unshift($ancestors, $parent);
                    $parent = $graph->groups[$parent]['parent'];
                }
                $graph->nodes[$id] = ['label' => $group['label'], 'origin' => $group['origin'], 'offset' => $group['offset'], 'groups' => $ancestors];
                $graph->shapes[$id] = 'rounded';
                unset($graph->groups[$id]);
            }
            yield null;
        }
        foreach ($hidden as $id => $ancestor) {
            unset($graph->nodes[$id]);
        }
        foreach ($graph->edges as &$edge) {
            $from = $hidden[$edge['from']] ?? $edge['from'];
            $to = $hidden[$edge['to']] ?? $edge['to'];
            if ($from === $to && $edge['from'] !== $edge['to']) {
                // Retain the index for linkStyle, but never draw a collapse-induced loop.
                $edge['arrow'] = '~~~';
            }
            $edge['from'] = $from;
            $edge['to'] = $to;
        }
        unset($edge);
        $work = new FlowchartLayout()->layout($graph);
        yield from $work;

        $scene = $work->getReturn();
        $nodes = [];
        foreach ($scene->graph->nodes as $key => $node) {
            // Pinned `KIND_SLOT`: color themes paint each node kind from its own palette slot.
            $slot = self::KINDS[$scene->shapes[$key] ?? ''] ?? 1;
            $nodes[$key] = $node->painted($node->color ?? '@slot:stroke:'.$slot, $node->fillColor ?? '@slot:fill:'.$slot, $node->textColor);
            yield null;
        }
        $order = \array_flip(\array_map(\strval(...), \array_keys($graph->groups)));
        $clusters = [];
        foreach ($scene->graph->clusters as $cluster) {
            // Containers cycle the slots above the seven kind slots, in declaration order.
            $slot = '@slot:%s:'.($order[$cluster->name] ?? 0).':7';
            $clusters[] = $cluster->painted($cluster->color ?? \sprintf($slot, 'stroke'), $cluster->fillColor ?? \sprintf($slot, 'fill'), $cluster->textColor);
            yield null;
        }
        $elements = $scene->elements;
        $shapes = $scene->shapes;
        $id = -1;
        foreach ($scene->graph->clusters as $cluster) {
            [$width, $height] = MermaidText::box($cluster->label, $cluster->fontScale);
            [$x, $y] = $cluster->labelPos ?? [($cluster->x0 + $cluster->x1) / 2, $cluster->y0 + 2];
            $nodes[$id] = new GraphNode($id, $cluster->name, $cluster->label, $x, $y, $width, $height - 4, color: null, fillColor: null, dashed: false, drawLabel: '');
            $elements[$id] = $graph->groups[$cluster->name]['origin'];
            $shapes[$id] = 'text';
            --$id;
            yield null;
        }

        return new MermaidScene(
            new GraphLayout($scene->graph->width, $scene->graph->height, $nodes, $scene->graph->edges, $clusters),
            $scene->origins,
            $scene->legend,
            $elements,
            $shapes,
        );
    }
}
