<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Front matter captions remain outside selection while all existing geometry moves together. */
final class MermaidTitle
{
    /** @return \Generator<int, null, mixed, MermaidScene> */
    public static function apply(MermaidScene $scene, MermaidStatement $title, float $topMargin = 1.0): \Generator
    {
        $wrap = MermaidWrap::text($title->text, width: 116);
        yield from $wrap;
        $label = $wrap->getReturn();
        [$width, $height] = MermaidText::box($label);
        $width *= $scene->titleScale;
        $height *= $scene->titleScale;
        $shift = $height + $topMargin + 1;
        $graph = $scene->graph;
        $canvas = \max($width + 4, $graph->width);
        $horizontal = ($canvas - $graph->width) / 2;
        $translation = MermaidTranslation::graph($graph, $horizontal, $shift);
        yield from $translation;
        $translated = $translation->getReturn();
        $nodes = $translated->nodes;
        $id = [] === $nodes ? 0 : \max(\array_keys($nodes)) + 1;
        $nodes[$id] = new GraphNode(
            $id,
            'frontmatter-title',
            $label,
            $canvas / 2,
            $topMargin + ($height / 2),
            $width,
            $height,
            color: null,
            fillColor: null,
            dashed: false,
            textColor: $scene->titleColor,
            fontScale: $scene->titleScale,
        );

        $origins = MermaidOrigins::rows((2 * \count($scene->origins)) + $shift, $title->line);
        foreach ($origins as $row => $_origin) {
            $previous = (int) \floor($row - ($shift / 2));
            if ($previous >= 0) {
                $origins[$row] = $scene->origins[$previous] ?? $title->line;
            }
            yield null;
        }

        return new MermaidScene(
            new GraphLayout($canvas, $graph->height + $shift, $nodes, $translated->edges, $translated->clusters),
            $origins,
            [['text' => $title->text, 'origin' => $title->line], ...$scene->legend],
            $scene->elements,
            $scene->shapes + [$id => 'text'],
            $scene->background,
            $scene->titleColor,
            $scene->titleScale,
            $scene->palette,
            $scene->runs,
        );
    }
}
