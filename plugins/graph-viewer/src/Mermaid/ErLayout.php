<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphLayout;

/** Ranked entity cards keep attribute order and explicit endpoint cardinalities. */
final class ErLayout
{
    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(ErDiagram $source): \Generator
    {
        $graph = $source->graph;
        $runs = [];
        foreach ($graph->nodes as $id => $node) {
            $work = ErCard::table($source->aliases[$id] ?? (string) $id, $source->attributes[$id] ?? []);
            yield from $work;
            $card = $work->getReturn();
            $graph->nodes[$id]['label'] = $card['label'];
            $graph->shapes[$id] = $card['shape'];
            $runs[(string) $id] = $card['runs'];
        }
        foreach ($graph->edges as $index => $edge) {
            $wrap = MermaidWrap::text($edge['label'], width: 32);
            yield from $wrap;
            $graph->edges[$index]['label'] = $wrap->getReturn();
            if (\in_array($graph->direction, ['TB', 'BT'], strict: true)) {
                $graph->rankGap = \max($graph->rankGap, (2 * (\substr_count($wrap->getReturn(), needle: "\n") + 1)) + 10.0);
            }
        }
        $layout = new RankedGraphLayout()->layout($graph);
        yield from $layout;
        $scene = $layout->getReturn();
        $routes = MermaidCardRoutes::layout($scene->graph);
        yield from $routes;
        $routed = $routes->getReturn();
        $nodes = [];
        $styled = [];
        foreach ($routed->nodes as $id => $node) {
            $nodes[$id] = $source->styles->decorate($node);
            // An authored font style sets the whole card.
            $face = $nodes[$id]->font;
            $styled['node:'.$id] = \array_map(static fn (array $line): array => \array_map(static fn (array $run): array => [$run[0], $face], $line), $runs[$node->name]);
            yield null;
        }
        $edges = $routed->edges;
        foreach ($routed->edges as $index => $edge) {
            $cardinality = $source->cardinalities[$index];
            foreach (ErMarkers::forEdge($edge, $cardinality['left'], $cardinality['right']) as $marker) {
                $edges[] = $marker;
                yield null;
            }
        }
        $legend = $source->metadata;
        foreach ($source->attributes as $attributes) {
            foreach ($attributes as $attribute) {
                $legend[] = ['text' => $attribute['text'], 'origin' => $attribute['origin']];
            }
            yield null;
        }

        return new MermaidScene(
            new GraphLayout($routed->width, $routed->height, $nodes, $edges, \array_map($source->styles->decorateCluster(...), $routed->clusters)),
            $scene->origins,
            [...$legend, ...$scene->legend],
            $scene->elements,
            $scene->shapes,
            runs: $styled,
        );
    }
}
