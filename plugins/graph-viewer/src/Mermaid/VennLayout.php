<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphNode;

/** Circle geometry and text preparation remain independent from the parsed source model. */
final readonly class VennLayout
{
    public function __construct(
        private ?MermaidPalette $palette = null,
    ) {}

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(VennDiagram $source): \Generator
    {
        $theme = new VennTheme($this->palette ?? MermaidPalette::defaults('venn-beta'));
        $geometry = VennGeometry::layout($source);
        yield from $geometry;
        $circles = $geometry->getReturn();
        $styles = new VennStyles($source);
        $prepare = VennCaptions::prepare($source, $circles, $styles, $theme);
        yield from $prepare;
        $captions = $prepare->getReturn();
        foreach ($circles as $id => $circle) {
            $circles[$id] = new GraphNode(
                $circle->id,
                $circle->name,
                '',
                $circle->x * $captions->scale,
                $circle->y * $captions->scale,
                $circle->w * $captions->scale,
                $circle->h * $captions->scale,
                null,
                null,
                dashed: false,
            );
            yield null;
        }
        $canvas = new VennCanvas();
        yield from $canvas->circles($circles, $styles, $theme);
        yield from $canvas->regions($source, $circles, $styles);
        yield from $canvas->captions($captions->groups, $captions->scale);
        $scene = $canvas->scene($source);
        yield from $scene;
        if (null !== $source->title) {
            $title = MermaidTitle::apply($scene->getReturn(), $source->title);
            yield from $title;

            return $title->getReturn();
        }

        return $scene->getReturn();
    }
}
