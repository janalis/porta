<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Janalis\Viz\Layout\GraphLayout;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\MermaidScene;
use Porta\Support\AnsiText;

/** Unambiguous semantic IDs win; unique plain labels provide a conservative fallback. */
final class MermaidSvgMap
{
    /** @return \Generator<int, null, mixed, MermaidScene|null> */
    public static function project(string $svg, MermaidScene $source, float $width, float $height): \Generator
    {
        $work = MermaidSvgIndex::read($svg, $width, $height);
        yield from $work;
        $index = $work->getReturn();
        if (null === $index) {
            return null;
        }
        $labels = [];
        foreach ($source->selectionGraph->nodes as $node) {
            $label = AnsiText::oneLine($node->label);
            $labels[$label] = ($labels[$label] ?? 0) + 1;
            yield null;
        }
        $nodes = [];
        $origins = [];
        $taken = [];
        foreach ($source->elements as $id => $origin) {
            yield null;
            $node = $source->graph->nodes[$id];
            $label = AnsiText::oneLine($node->label);
            $boxes = $index->ids[$node->name] ?? (1 === $labels[$label] ? $index->labels[$label] ?? [] : []);
            // A repeated element, such as a mirrored sequence footer, never claims a box twice.
            if (1 !== \count($boxes) || \in_array($boxes[0], $taken, strict: true)) {
                continue;
            }
            $taken[] = $boxes[0];
            [$left, $top, $right, $bottom] = $boxes[0];
            $left = \max(0.0, $left);
            $top = \max(0.0, $top);
            $right = \min($width, $right);
            $bottom = \min($height, $bottom);
            if ($right <= $left || $bottom <= $top) {
                continue;
            }
            $nodes[$id] = new GraphNode($id, $node->name, $node->label, ($left + $right) / 2, ($top + $bottom) / 2, $right - $left, $bottom - $top, color: null, fillColor: null, dashed: false);
            $origins[$id] = $origin;
        }

        return [] === $nodes ? null : new MermaidScene(new GraphLayout($width, $height, $nodes, edges: [], clusters: []), origins: [], legend: [], elements: $origins);
    }
}
