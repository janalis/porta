<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Native block scenes keep explicit grid positions even when relationships cross scopes. */
final class BlockLayout
{
    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(BlockDiagram $source): \Generator
    {
        $work = new BlockPlacement($source)->layout();
        yield from $work;
        $graph = $work->getReturn();
        $rowOrigins = MermaidOrigins::rows($graph->height, 0);
        $endpoints = [];
        $shapes = [];
        $origins = [];
        $frames = [];
        $legend = [];
        foreach ($graph->nodes as $id => $node) {
            $endpoints[$node->name] = $node;
            $record = $source->graph->nodes[$node->name] ?? $source->graph->groups[$node->name];
            $origins[$id] = $record['origin'];
            for ($row = (int) (($node->y - ($node->h / 2)) / 2); $row <= (int) (($node->y + ($node->h / 2)) / 2); ++$row) {
                $rowOrigins[$row] = $record['origin'];
                yield null;
            }
            $shapes[$id] = $source->graph->shapes[$node->name] ?? 'rect';
            $legend[] = ['text' => $node->name.': '.$node->label, 'origin' => $record['origin']];
            if (\array_key_exists($node->name, $source->graph->groups)) {
                $frames[$id] = $node->name;
                $shapes[$id] = 'text';
            }
            yield null;
        }
        foreach ($graph->clusters as $frame) {
            $header = $endpoints[$frame->name];
            $endpoints[$frame->name] = new GraphNode(
                $header->id,
                $header->name,
                $header->label,
                ($frame->x0 + $frame->x1) / 2,
                ($frame->y0 + $frame->y1) / 2,
                $frame->x1 - $frame->x0,
                $frame->y1 - $frame->y0,
                color: null,
                fillColor: null,
                dashed: false,
            );
            yield null;
        }
        $edges = [];
        $arrows = [];
        foreach ($source->graph->edges as $edge) {
            if (\str_contains($edge['arrow'], '~')) {
                continue;
            }
            $arrows[] = $edge['arrow'];
            $edges[] = MermaidGraphEdges::edge(
                $endpoints[$edge['from']],
                $endpoints[$edge['to']],
                $edge['label'],
                $edge['arrow'],
                $source->graph->shapes[$edge['from']] ?? 'rect',
                $source->graph->shapes[$edge['to']] ?? 'rect',
            );
            $legend[] = ['text' => $edge['from'].' '.$edge['arrow'].' '.$edge['to'].('' === $edge['label'] ? '' : ': '.$edge['label']), 'origin' => $edge['origin']];
            yield null;
        }
        $routes = MermaidFrameRoutes::layout(new GraphLayout($graph->width, $graph->height, $graph->nodes, $edges, $graph->clusters), $frames);
        yield from $routes;
        $markers = MermaidLinkMarkers::decorate($routes->getReturn(), $arrows);
        yield from $markers;
        $graph = $markers->getReturn();

        return new MermaidScene($graph, $rowOrigins, $legend, $origins, $shapes);
    }
}
