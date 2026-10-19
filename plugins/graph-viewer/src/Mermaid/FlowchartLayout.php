<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Shared ranked geometry gains flowchart endpoint semantics without changing UML family markers. */
final class FlowchartLayout
{
    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(MermaidGraph $source): \Generator
    {
        $work = new RankedGraphLayout()->layout($source);
        yield from $work;
        $scene = $work->getReturn();
        $arrows = [];
        foreach ($source->edges as $edge) {
            if (!\str_contains($edge['arrow'], '~')) {
                $arrows[] = $edge['arrow'];
            }
            yield null;
        }
        $markers = MermaidLinkMarkers::decorate($scene->graph, $arrows);
        yield from $markers;

        return new MermaidScene($markers->getReturn(), $scene->origins, $scene->legend, $scene->elements, $scene->shapes);
    }
}
