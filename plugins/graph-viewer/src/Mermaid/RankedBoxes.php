<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Cycle-safe ranked boxes, also used for opaque child subgraph frames. */
final class RankedBoxes
{
    /** Reserved lanes for indexed edges spanning ranks; expansion turns them into route waypoints. */
    public const string LANE = "\0edge:";

    /**
     * @param array<array-key, GraphNode> $items
     * @param list<array{from: string, to: string, length?: int, index?: int}> $edges
     * @return \Generator<int, null, mixed, GraphLayout>
     */
    public function place(array $items, array $edges, string $direction, float $rankGap = 8.0, float $nodeGap = 6.0, float $padding = 4.0): \Generator
    {
        $work = MermaidRanks::resolve(\array_keys($items), $edges);
        yield from $work;
        $ranks = $work->getReturn();
        $levels = [];
        $horizontal = \in_array($direction, ['LR', 'RL'], strict: true);
        foreach ($items as $id => $item) {
            $levels[$ranks[$id]][] = $item;
            yield null;
        }
        // Like dagre's dummy chains, a forward edge across ranks occupies every rank it passes.
        $lane = 0;
        foreach ($edges as $edge) {
            $index = $edge['index'] ?? null;
            for ($rank = $ranks[$edge['from']] + 1; null !== $index && $rank < $ranks[$edge['to']]; ++$rank) {
                // Two units across the rank keep a spline clear of its neighbors' corners.
                [$width, $height] = $horizontal ? [0.0, 2.0] : [2.0, 0.0];
                $levels[$rank][] = new GraphNode(\PHP_INT_MIN + $lane++, self::LANE.$index, '', x: 0.0, y: 0.0, w: $width, h: $height, color: null, fillColor: null, dashed: false);
                yield null;
            }
        }
        \ksort($levels);
        $nodes = [];
        $levelPosition = $padding;
        $previousRank = -1;
        foreach ($levels as $rank => $level) {
            $levelPosition += \max(0, $rank - $previousRank - 1) * $rankGap;
            $previousRank = $rank;
            $along = $padding;
            $extent = 0.0;
            foreach ($level as $node) {
                [$x, $y] = $horizontal ? [$levelPosition + ($node->w / 2), $along + ($node->h / 2)] : [$along + ($node->w / 2), $levelPosition + ($node->h / 2)];
                $nodes[$node->id] = new GraphNode($node->id, $node->name, $node->label, $x, $y, $node->w, $node->h, null, null, dashed: false);
                $along += ($horizontal ? $node->h : $node->w) + $nodeGap;
                $extent = \max($extent, $horizontal ? $node->w : $node->h);
                yield null;
            }
            foreach ($level as $node) {
                if (\str_starts_with($node->name, self::LANE)) {
                    // A lane spans its rank band, so routes never cut through a neighbor's lower half.
                    $placed = $nodes[$node->id];
                    $nodes[$node->id] = $horizontal
                        ? new GraphNode($node->id, $node->name, '', $levelPosition + ($extent / 2), $placed->y, $extent, $node->h, null, null, dashed: false)
                        : new GraphNode($node->id, $node->name, '', $placed->x, $levelPosition + ($extent / 2), $node->w, $extent, null, null, dashed: false);
                }
                yield null;
            }
            $levelPosition += $extent + $rankGap;
        }
        $width = \max([8.0, ...\array_map(static fn (GraphNode $node): float => $node->x + ($node->w / 2) + $padding, $nodes)]);
        $height = \max([8.0, ...\array_map(static fn (GraphNode $node): float => $node->y + ($node->h / 2) + $padding, $nodes)]);
        $nodes = $this->orient($nodes, $direction, $width, $height);

        return new GraphLayout($width, $height, $nodes, [], []);
    }

    /** @param array<int, GraphNode> $nodes
     * @return array<int, GraphNode>
     */
    private function orient(array $nodes, string $direction, float $width, float $height): array
    {
        return \array_map(static fn (GraphNode $node): GraphNode => new GraphNode(
            $node->id,
            $node->name,
            $node->label,
            'RL' === $direction ? $width - $node->x : $node->x,
            'BT' === $direction ? $height - $node->y : $node->y,
            $node->w,
            $node->h,
            null,
            null,
            dashed: false,
        ), $nodes);
    }
}
