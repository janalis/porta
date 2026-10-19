<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphLayout;

/** Large or offset captions grow and translate the same canvas as nodes and source hit targets. */
final class MermaidCaptionBounds
{
    /** @return \Generator<int, null, mixed, GraphLayout> */
    public static function layout(GraphLayout $graph): \Generator
    {
        $left = 0.0;
        $top = 0.0;
        $width = $graph->width;
        $height = $graph->height;
        foreach ($graph->edges as $edge) {
            if (null === $edge->labelPos || null === $edge->label) {
                continue;
            }
            [$w, $h] = MermaidText::box($edge->label, $edge->fontScale);
            $left = \min($left, $edge->labelPos[0] - ($w / 2) - 2);
            $top = \min($top, $edge->labelPos[1] - ($h / 2) - 2);
            $width = \max($width, $edge->labelPos[0] + ($w / 2) + 2);
            $height = \max($height, $edge->labelPos[1] + ($h / 2) + 2);
            yield null;
        }
        $work = MermaidTranslation::graph(new GraphLayout($width, $height, $graph->nodes, $graph->edges, $graph->clusters), -$left, -$top);
        yield from $work;

        return $work->getReturn();
    }
}
