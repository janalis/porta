<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Side columns keep authored notes beside the diagram without covering state cards. */
final class StateNotes
{
    /** @param array<string, array{target: string, side: string}> $notes
     * @return \Generator<int, null, mixed, MermaidScene>
     */
    public static function layout(MermaidScene $scene, array $notes): \Generator
    {
        if ([] === $notes) {
            return $scene;
        }
        $byName = [];
        foreach ($scene->graph->nodes as $node) {
            $byName[$node->name] = $node;
        }
        $margins = ['left' => 0.0, 'right' => 0.0];
        foreach ($notes as $name => $note) {
            $margins[$note['side']] = \max($margins[$note['side']], $byName[$name]->w + 6);
            yield null;
        }
        $translation = MermaidTranslation::graph($scene->graph, $margins['left'], shift: 0.0);
        yield from $translation;
        $graph = $translation->getReturn();
        $nodes = $graph->nodes;
        $height = $graph->height;
        $bottom = ['left' => 0.0, 'right' => 0.0];
        \uasort($notes, static fn (array $a, array $b): int => $byName[$a['target']]->y <=> $byName[$b['target']]->y);
        foreach ($notes as $name => $note) {
            $node = $nodes[$byName[$name]->id];
            $target = $nodes[$byName[$note['target']]->id];
            $side = $note['side'];
            $x = 'left' === $side ? $margins['left'] / 2 : $graph->width + ($margins['right'] / 2);
            $y = \max($target->y, $bottom[$side] + ($node->h / 2) + 2);
            $bottom[$side] = $y + ($node->h / 2) + 4;
            $height = \max($height, $bottom[$side]);
            $nodes[$node->id] = new GraphNode(
                $node->id,
                $name,
                $node->label,
                $x,
                $y,
                $node->w,
                $node->h,
                $node->color,
                $node->fillColor,
                $node->dashed,
                strokeWidth: $node->strokeWidth,
                textColor: $node->textColor,
                font: $node->font,
                fontScale: $node->fontScale,
                dashes: $node->dashes,
            );
            yield null;
        }
        $connections = self::edges($graph, $nodes, $notes, $scene->shapes);
        yield from $connections;
        $edges = $connections->getReturn();
        $origins = \array_pad($scene->origins, \max(1, (int) \ceil($height / 2)), value: 0);
        foreach ($notes as $name => $note) {
            $node = $nodes[$byName[$name]->id];
            for ($row = (int) \floor(($node->y - ($node->h / 2)) / 2); $row <= (int) \ceil(($node->y + ($node->h / 2)) / 2); ++$row) {
                $origins[$row] = $scene->elements[$node->id];
                yield null;
            }
        }

        return new MermaidScene(new GraphLayout($graph->width + $margins['right'], $height, $nodes, $edges, $graph->clusters), $origins, $scene->legend, $scene->elements, $scene->shapes);
    }

    /** @param array<int, GraphNode> $nodes
     * @param array<string, array{target: string, side: string}> $notes
     * @param array<int, string> $shapes
     * @return \Generator<int, null, mixed, list<GraphEdge>>
     */
    private static function edges(GraphLayout $graph, array $nodes, array $notes, array $shapes): \Generator
    {
        $edges = [];
        foreach ($graph->edges as $edge) {
            $from = $nodes[$edge->tail] ?? null;
            $to = $nodes[$edge->head] ?? null;
            if ($edge->decoration || null === $from || null === $to || (!\array_key_exists($from->name, $notes) && !\array_key_exists($to->name, $notes))) {
                $edges[] = $edge;
                yield null;
                continue;
            }
            $points = self::connection($from, $to, $shapes);
            $edges[] = new GraphEdge(
                $from->id,
                $to->id,
                $points,
                null,
                \min(\array_column($points, 0)),
                \min(\array_column($points, 1)),
                \max(\array_column($points, 0)),
                \max(\array_column($points, 1)),
                $edge->color,
                null,
                null,
                dashed: true,
                decoration: true,
            );
            yield null;
        }

        return $edges;
    }

    /** @param array<int, string> $shapes
     * @return list<array{float, float}>
     */
    private static function connection(GraphNode $from, GraphNode $to, array $shapes): array
    {
        $left = $from->x < $to->x;
        $start = MermaidOutline::boundary($from, $shapes[$from->id], $left ? $from->x + $from->w : $from->x - $from->w, $from->y);
        $outside = $start[0] + ($left ? 2 : -2);
        $above = $to->y - ($to->h / 2) - 2;
        $end = MermaidOutline::boundary($to, $shapes[$to->id], $to->x, $above);

        return [$start, [$outside, $start[1]], [$outside, $above], [$to->x, $above], $end];
    }
}
