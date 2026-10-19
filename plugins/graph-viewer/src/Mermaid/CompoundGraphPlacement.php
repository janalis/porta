<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Bottom-up scopes, then iterative expansion: nested frames never overlap their children. */
final class CompoundGraphPlacement
{
    /** @return \Generator<int, null, mixed, MermaidPlacement> */
    public function place(MermaidGraph $source): \Generator
    {
        $projection = $this->project($source);
        yield from $projection;
        [$crossing, $scopedEdges] = $projection->getReturn();
        $directions = ['' => $source->direction];
        foreach ($source->groups as $id => $group) {
            $id = (string) $id;
            $inherited = $directions[$group['parent'] ?? ''];
            $preference = $source->flowchart?->inheritedDirection();
            $directions[$id] = match (true) {
                $source->inheritDirectionsAcrossLinks && \array_key_exists($id, $crossing) => $inherited,
                $group['explicit'] => $group['direction'],
                true === $preference => $source->direction,
                false === $preference => 'TB',
                default => $inherited,
            };
            yield null;
        }
        $items = [];
        $nodeNumber = 0;
        foreach ($source->nodes as $id => $node) {
            [$width, $height] = MermaidNodeMetrics::measure($source, (string) $id);
            $owner = [] === $node['groups'] ? '' : $node['groups'][\count($node['groups']) - 1];
            $items[$owner][$id] = new GraphNode($nodeNumber++, (string) $id, $node['label'], 0.0, 0.0, $width, $height, null, null, dashed: false);
            yield null;
        }
        $reservation = MermaidLoopSpace::measure($source);
        yield from $reservation;
        $padding = $reservation->getReturn();
        $sizes = [];
        $scopes = [];
        $groupNumber = -1;
        foreach ([...\array_reverse(\array_keys($source->groups)), ''] as $scope) {
            $scope = (string) $scope;
            $scopeItems = $items[$scope] ?? [];
            \uasort(
                $scopeItems,
                static fn (GraphNode $a, GraphNode $b): int => (
                    ($source->nodes[$a->name]['offset'] ?? $source->groups[$a->name]['offset']) <=> ($source->nodes[$b->name]['offset'] ?? $source->groups[$b->name]['offset'])
                ),
            );
            foreach ($scopeItems as $id => $item) {
                $sizes[$id] = [$item->w, $item->h];
                [$px, $py] = $padding[$id] ?? [0.0, 0.0];
                $scopeItems[$id] = new GraphNode($item->id, $item->name, $item->label, x: 0.0, y: 0.0, w: $item->w + (2 * $px), h: $item->h + (2 * $py), color: null, fillColor: null, dashed: false);
                yield null;
            }
            $work = new RankedBoxes()->place($scopeItems, $scopedEdges[$scope] ?? [], $directions[$scope], $source->rankGap, $source->nodeGap, $source->padding);
            yield from $work;
            $scopes[$scope] = $work->getReturn();
            if ('' !== $scope) {
                $group = $source->groups[$scope];
                [$labelWidth, $labelHeight] = $source->styles->measureCluster($scope, $group['label']);
                $width = \max($scopes[$scope]->width, $labelWidth + 4);
                $height = $scopes[$scope]->height + $labelHeight + ($source->flowchart?->titleGap() ?? 0.0);
                $items[$group['parent'] ?? ''][$scope] = new GraphNode($groupNumber--, $scope, $group['label'], 0.0, 0.0, $width, $height, null, null, dashed: false);
            }
            yield null;
        }
        $work = $this->expand($source, $scopes, $directions, $sizes);
        yield from $work;

        return $work->getReturn();
    }

    /**
     * @return \Generator<int, null, mixed, array{array<array-key, true>, array<array-key, list<array{from: string, to: string, length: int, index: int}>>}>
     */
    private function project(MermaidGraph $source): \Generator
    {
        /** @var array<array-key, array<array-key, string>> $representatives */
        $representatives = [];
        foreach ([...\array_keys($source->nodes), ...\array_keys($source->groups)] as $id) {
            $nodeGroups = $source->nodes[$id]['groups'] ?? [];
            $nodeParent = [] === $nodeGroups ? null : $nodeGroups[\count($nodeGroups) - 1];
            $parent = \array_key_exists($id, $source->groups) ? $source->groups[$id]['parent'] : $nodeParent;
            $child = (string) $id;
            do {
                $representatives[$id][$parent ?? ''] = $child;
                $child = $parent;
                $parent = null === $parent ? null : $source->groups[$parent]['parent'];
                yield null;
            } while (null !== $child);
        }
        $crossing = [];
        $scopedEdges = [];
        foreach ($source->edges as $index => $edge) {
            foreach ($representatives[$edge['from']] as $scope => $from) {
                $to = $representatives[$edge['to']][$scope] ?? null;
                if (null !== $to && $from !== $to) {
                    $scopedEdges[$scope][] = ['from' => $from, 'to' => $to, 'length' => MermaidGraphEdges::rankSpan($edge['arrow']), 'index' => $index];
                }
                yield null;
            }
            foreach ([[$edge['from'], $edge['to']], [$edge['to'], $edge['from']]] as [$inside, $outside]) {
                if (\array_key_exists($inside, $source->nodes)) {
                    foreach ($representatives[$inside] as $scope => $representative) {
                        if ('' !== $scope && $outside !== (string) $scope && !\array_key_exists($scope, $representatives[$outside])) {
                            $crossing[$scope] = true;
                        }
                        yield null;
                    }
                }
                yield null;
            }
        }

        return [$crossing, $scopedEdges];
    }

    /**
     * @param array<array-key, GraphLayout> $scopes
     * @param array<array-key, string> $directions
     * @param array<array-key, array{float, float}> $sizes
     * @return \Generator<int, null, mixed, MermaidPlacement>
     */
    private function expand(MermaidGraph $source, array $scopes, array $directions, array $sizes): \Generator
    {
        $pending = [['', 0.0, 0.0]];
        $nodes = [];
        $clusters = [];
        $endpoints = [];
        $lanes = [];
        while ([] !== $pending) {
            [$scope, $dx, $dy] = \array_pop($pending);
            foreach ($scopes[$scope]->nodes as $node) {
                if (\str_starts_with($node->name, RankedBoxes::LANE)) {
                    $index = (int) \substr($node->name, \strlen(RankedBoxes::LANE));
                    $horizontal = \in_array($directions[$scope], ['LR', 'RL'], strict: true);
                    $sign = \in_array($directions[$scope], ['BT', 'RL'], strict: true) ? -1 : 1;
                    [$hx, $hy] = $horizontal ? [($sign * $node->w) / 2, 0.0] : [0.0, ($sign * $node->h) / 2];
                    $lanes[$index][] = [$node->x + $dx - $hx, $node->y + $dy - $hy];
                    $lanes[$index][] = [$node->x + $dx + $hx, $node->y + $dy + $hy];
                    yield null;
                    continue;
                }
                [$width, $height] = $sizes[$node->name];
                $placed = new GraphNode($node->id, $node->name, $node->label, $node->x + $dx, $node->y + $dy, $width, $height, null, null, dashed: false);
                $endpoints[$node->name] = $placed;
                if (\array_key_exists($node->name, $source->groups)) {
                    $left = $placed->x - ($placed->w / 2);
                    $top = $placed->y - ($placed->h / 2);
                    $clusters[] = new GraphCluster($placed->name, $placed->label, $left, $top, $left + $placed->w, $top + $placed->h, null, null);
                    $pending[] = [$node->name, $left, $top + $source->styles->measureCluster($node->name, $placed->label)[1] + ($source->flowchart?->titleGap() ?? 0.0)];
                    yield null;
                    continue;
                }
                $nodes[$placed->id] = $placed;
                yield null;
            }
        }

        return new MermaidPlacement(new GraphLayout($scopes['']->width, $scopes['']->height, $nodes, [], $clusters), $endpoints, $directions, $lanes);
    }
}
