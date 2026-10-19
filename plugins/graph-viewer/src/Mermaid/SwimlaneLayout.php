<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphLayout;

/** Lane frames, compact source headers and cross-lane relationships share one native scene. */
final class SwimlaneLayout
{
    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(MermaidGraph $source): \Generator
    {
        $preparation = self::prepare($source);
        yield from $preparation;
        [$source, $implicit] = $preparation->getReturn();
        $work = new SwimlanePlacement($source, $implicit)->place();
        yield from $work;
        $placement = $work->getReturn();
        $graph = $placement->graph;
        $edges = [];
        $arrows = [];
        $legend = [];
        foreach ($source->edges as $index => $edge) {
            $legend[] = ['text' => $edge['from'].' '.$edge['arrow'].' '.$edge['to'].('' === $edge['label'] ? '' : ': '.$edge['label']), 'origin' => $edge['origin']];
            if (\str_contains($edge['arrow'], '~')) {
                continue;
            }
            $arrows[] = $edge['arrow'];
            $edges[] = $source->edgeStyles->decorate(
                MermaidGraphEdges::orthogonal(
                    $placement->endpoints[$edge['from']],
                    $placement->endpoints[$edge['to']],
                    $edge['label'],
                    $edge['arrow'],
                    $source->shapes[$edge['from']] ?? 'rect',
                    $source->shapes[$edge['to']] ?? 'rect',
                    $source->edgeStyles->curve($index, null),
                    $source->direction,
                ),
                $index,
            );
            yield null;
        }
        $frames = [];
        $elements = [];
        $shapes = [];
        foreach ($graph->nodes as $id => $node) {
            $group = $source->groups[$node->name] ?? null;
            $elements[$id] = null === $group ? $source->nodes[$node->name]['origin'] : $group['origin'];
            $shapes[$id] = null === $group ? $source->shapes[$node->name] ?? 'rect' : 'text';
            $legend[] = ['text' => $node->label, 'origin' => $elements[$id]];
            if (null !== $group) {
                $frames[$id] = $node->name;
            }
            yield null;
        }
        $routes = MermaidFrameRoutes::layout(new GraphLayout($graph->width, $graph->height, $graph->nodes, $edges, $graph->clusters), $frames);
        yield from $routes;
        $markers = MermaidLinkMarkers::decorate($routes->getReturn(), $arrows);
        yield from $markers;
        $bounds = MermaidCaptionBounds::layout($markers->getReturn());
        yield from $bounds;
        $graph = $bounds->getReturn();
        $origins = MermaidOrigins::rows($graph->height, 0);
        foreach ($graph->nodes as $id => $node) {
            for ($row = (int) (($node->y - ($node->h / 2)) / 2); $row <= (int) (($node->y + ($node->h / 2)) / 2); ++$row) {
                $origins[$row] = $elements[$id];
                yield null;
            }
        }

        return new MermaidScene($graph, $origins, $legend, $elements, $shapes);
    }

    /** @return \Generator<int, null, mixed, array{MermaidGraph, ?string}> */
    private static function prepare(MermaidGraph $source): \Generator
    {
        $graph = clone $source;
        $loose = [];
        foreach ($graph->nodes as $name => $node) {
            if ([] === $node['groups']) {
                $loose[] = $name;
            }
            yield null;
        }
        $implicit = null;
        if ([] !== $loose) {
            $name = '__swimlane_default__';
            while (\array_key_exists($name, $graph->nodes)) {
                $name .= '_';
            }
            if (!\array_key_exists($name, $graph->groups)) {
                $graph->groups[$name] = ['label' => '', 'origin' => 0, 'offset' => 0, 'parent' => null, 'direction' => $graph->direction, 'explicit' => false];
                $implicit = $name;
            }
            $ancestors = [$name];
            $parent = $graph->groups[$name]['parent'];
            while (null !== $parent) {
                \array_unshift($ancestors, $parent);
                $parent = $graph->groups[$parent]['parent'];
            }
            foreach ($loose as $id) {
                $graph->nodes[$id]['groups'] = $ancestors;
                yield null;
            }
        }

        return [$graph, $implicit];
    }
}
