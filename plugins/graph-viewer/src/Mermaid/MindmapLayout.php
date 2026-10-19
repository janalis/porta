<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/**
 * A two-sided mind map: the root in the middle, the first half of its branches growing to the
 * right and the rest to the left, each subtree stacked so siblings never overlap and a parent
 * centers on its children. The pinned default, cose-bilkent, is a force simulation; this keeps
 * its radiating shape deterministic and readable in terminal cells. Nodes keep the sizes the
 * ranked layout measured; edges join centers beneath the node outlines.
 */
final readonly class MindmapLayout
{
    /** Units between a parent's side and its children's. */
    private const float REACH = 6.0;
    /** Units between stacked siblings. */
    private const float GAP = 2.0;
    private const float MARGIN = 2.0;

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public static function arrange(MermaidGraph $source, MermaidScene $ranked): \Generator
    {
        $ids = [];
        foreach ($ranked->graph->nodes as $id => $node) {
            $ids[$node->name] = $id;
        }
        /** @var array<string, list<string>> $children */
        $children = [];
        foreach ($source->edges as $edge) {
            $children[$edge['from']][] = $edge['to'];
        }
        $root = (string) \array_key_first($source->nodes);
        $sizes = self::sizes($root, $children, $ranked->graph->nodes, $ids);
        yield from $sizes;
        $spans = $sizes->getReturn();
        $branches = $children[$root] ?? [];
        $right = \array_slice($branches, offset: 0, length: (int) \ceil(\count($branches) / 2));
        $left = \array_slice($branches, \count($right));
        $centers = [$root => [0.0, 0.0]];
        foreach ([[$right, 1.0], [$left, -1.0]] as [$side, $direction]) {
            $place = self::side($root, $side, $direction, $children, $ranked->graph->nodes, $ids, $spans, $centers);
            yield from $place;
            $centers = $place->getReturn();
        }

        return self::scene($source, $ranked, $ids, $centers);
    }

    /**
     * Each subtree's height: its own box, or its children stacked with gaps, whichever is taller.
     *
     * @param array<string, list<string>> $children
     * @param array<int, GraphNode> $nodes
     * @param array<string, int> $ids
     * @return \Generator<int, null, mixed, array<string, float>>
     */
    private static function sizes(string $root, array $children, array $nodes, array $ids): \Generator
    {
        $order = [];
        $pending = [$root];
        while ([] !== $pending) {
            $name = \array_pop($pending);
            $order[] = $name;
            \array_push($pending, ...$children[$name] ?? []);
            yield null;
        }
        $spans = [];
        foreach (\array_reverse($order) as $name) {
            $stacked = \array_sum(\array_map(static fn (string $child): float => $spans[$child], $children[$name] ?? []));
            $stacked += self::GAP * \max(0, \count($children[$name] ?? []) - 1);
            $spans[$name] = \max($nodes[$ids[$name]]->h, $stacked);
            yield null;
        }

        return $spans;
    }

    /**
     * One side of the root: branches stacked about the root's center, each subtree outward.
     *
     * @param list<string> $branches
     * @param array<string, list<string>> $children
     * @param array<int, GraphNode> $nodes
     * @param array<string, int> $ids
     * @param array<string, float> $spans
     * @param array<string, array{float, float}> $centers
     * @return \Generator<int, null, mixed, array<string, array{float, float}>>
     */
    private static function side(string $root, array $branches, float $direction, array $children, array $nodes, array $ids, array $spans, array $centers): \Generator
    {
        /** @var list<array{string, list<string>, float}> $pending parent, the children to stack, and the top of their stack */
        $pending = [[$root, $branches, -self::stack($branches, $spans) / 2]];
        while ([] !== $pending) {
            [$parent, $stack, $top] = \array_shift($pending);
            $parentNode = $nodes[$ids[$parent]];
            foreach ($stack as $child) {
                $node = $nodes[$ids[$child]];
                $x = $centers[$parent][0] + ($direction * (($parentNode->w / 2) + self::REACH + ($node->w / 2)));
                $centers[$child] = [$x, $top + ($spans[$child] / 2)];
                $grandchildren = $children[$child] ?? [];
                $pending[] = [$child, $grandchildren, $centers[$child][1] - (self::stack($grandchildren, $spans) / 2)];
                $top += $spans[$child] + self::GAP;
                yield null;
            }
        }

        return $centers;
    }

    /**
     * @param list<string> $names
     * @param array<string, float> $spans
     */
    private static function stack(array $names, array $spans): float
    {
        return \array_sum(\array_map(static fn (string $name): float => $spans[$name], $names)) + (self::GAP * \max(0, \count($names) - 1));
    }

    /**
     * The placed nodes shifted into the margin, center-to-center edges, and source rows.
     *
     * @param array<string, int> $ids
     * @param array<string, array{float, float}> $centers
     */
    private static function scene(MermaidGraph $source, MermaidScene $ranked, array $ids, array $centers): MermaidScene
    {
        $placed = [];
        foreach ($centers as $name => [$x, $y]) {
            $placed[$ids[$name]] = $ranked->graph->nodes[$ids[$name]]->at($x, $y);
        }
        $left = \min(\array_map(static fn (GraphNode $node): float => $node->x - ($node->w / 2), $placed));
        $top = \min(\array_map(static fn (GraphNode $node): float => $node->y - ($node->h / 2), $placed));
        $nodes = [];
        foreach ($placed as $id => $node) {
            $nodes[$id] = $node->at($node->x - $left + self::MARGIN, $node->y - $top + self::MARGIN);
        }
        \ksort($nodes);
        $width = \max(\array_map(static fn (GraphNode $node): float => $node->x + ($node->w / 2), $nodes)) + self::MARGIN;
        $height = \max(\array_map(static fn (GraphNode $node): float => $node->y + ($node->h / 2), $nodes)) + self::MARGIN;
        $edges = [];
        foreach ($source->edges as $edge) {
            [$from, $to] = [$nodes[$ids[$edge['from']]], $nodes[$ids[$edge['to']]]];
            $edges[] = new GraphEdge(
                $from->id,
                $to->id,
                [[$from->x, $from->y], [$to->x, $to->y]],
                null,
                \min($from->x, $to->x),
                \min($from->y, $to->y),
                \max($from->x, $to->x),
                \max($from->y, $to->y),
                null,
                null,
                null,
                dashed: false,
            );
        }
        $origins = MermaidOrigins::rows($height, $ranked->elements[$ids[(string) \array_key_first($source->nodes)]] ?? 0);
        foreach ($nodes as $id => $node) {
            for ($row = (int) \floor(($node->y - ($node->h / 2)) / 2); $row <= (int) \ceil(($node->y + ($node->h / 2)) / 2); ++$row) {
                $origins[$row] = $ranked->elements[$id];
            }
        }

        return new MermaidScene(new GraphLayout($width, $height, $nodes, $edges, clusters: []), $origins, $ranked->legend, $ranked->elements, $ranked->shapes);
    }
}
