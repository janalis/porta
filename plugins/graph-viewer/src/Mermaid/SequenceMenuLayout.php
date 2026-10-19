<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphNode;
use Porta\Support\AnsiText;

/** Metadata panels follow the sequence, retaining statement origins and participant identity. */
final class SequenceMenuLayout
{
    /** @return \Generator<int, null, mixed, array{array<int, GraphNode>, array<int, int>, list<array{text: string, origin: int}>, float, float}> */
    public static function layout(SequenceDiagram $source, float $width, float $top, int $next): \Generator
    {
        $nodes = [];
        $origins = [];
        $legend = [];
        foreach ($source->menus as $actor => $items) {
            foreach ($items as $item) {
                $text = $actor.' '.$item['kind'].' '.$item['label'].': '.$item['value'];
                $display = \implode("\n", AnsiText::wrap($text, width: 116));
                [$w, $h] = MermaidText::box($display);
                $width = \max($width, $w + 4);
                $top += 4;
                $id = $next + \count($nodes);
                $nodes[$id] = new GraphNode($id, 'menu'.$id, $display, $width / 2, $top + ($h / 2), $w, $h, null, null, dashed: false);
                for ($row = (int) (($top - 2) / 2); $row < (int) (($top + $h + 2) / 2); ++$row) {
                    $origins[$row] = $item['origin'];
                    yield null;
                }
                $legend[] = ['text' => $text, 'origin' => $item['origin']];
                $top += $h;
                yield null;
            }
        }

        return [$nodes, $origins, $legend, $width, $top];
    }
}
