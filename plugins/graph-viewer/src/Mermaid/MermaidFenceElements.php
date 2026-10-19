<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Janalis\Viz\Mermaid\MermaidOutline;
use Janalis\Viz\Mermaid\MermaidScene;
use Porta\Help\Fence\FenceElement;

/** Semantic silhouettes use image-relative coordinates, independent of the inline image's fitted size. */
final class MermaidFenceElements
{
    /** @return \Generator<int, null, mixed, list<FenceElement>> */
    public static function fromScene(MermaidScene $scene): \Generator
    {
        $elements = [];
        foreach ($scene->elements as $id => $origin) {
            $node = $scene->graph->nodes[$id];
            $points = MermaidOutline::points($node, $scene->shapes[$id] ?? 'rect');
            $polygon = \array_map(static fn (array $point): array => [
                \max(0.0, \min(1.0, $point[0] / $scene->graph->width)),
                \max(0.0, \min(1.0, $point[1] / $scene->graph->height)),
            ], $points);
            $elements[] = new FenceElement((string) $id, $node->label, $origin, $polygon);
            yield null;
        }

        return $elements;
    }
}
