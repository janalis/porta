<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Composite routes use full frames while selectable headers retain their compact bounds. */
final class MermaidFrameRoutes
{
    /** @param array<int, array-key> $frames
     * @return \Generator<int, null, mixed, GraphLayout>
     */
    public static function layout(GraphLayout $graph, array $frames): \Generator
    {
        $clusters = [];
        foreach ($graph->clusters as $cluster) {
            $clusters[$cluster->name] = $cluster;
        }
        $bounds = $graph->nodes;
        foreach ($bounds as $id => $node) {
            if (!\array_key_exists($id, $frames)) {
                continue;
            }
            $cluster = $clusters[$frames[$id]];
            $bounds[$id] = new GraphNode(
                $id,
                $node->name,
                $node->label,
                ($cluster->x0 + $cluster->x1) / 2,
                ($cluster->y0 + $cluster->y1) / 2,
                $cluster->x1 - $cluster->x0,
                $cluster->y1 - $cluster->y0,
                null,
                null,
                dashed: false,
            );
            yield null;
        }
        $routing = MermaidCardRoutes::layout(new GraphLayout($graph->width, $graph->height, $bounds, $graph->edges, $graph->clusters));
        yield from $routing;
        $routed = $routing->getReturn();

        return new GraphLayout($routed->width, $routed->height, $graph->nodes, $routed->edges, $graph->clusters);
    }
}
