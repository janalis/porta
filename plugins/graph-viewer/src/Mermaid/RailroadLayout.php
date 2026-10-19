<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Each rule owns a separate left-to-right syntax track with baseline-aligned branches. */
final readonly class RailroadLayout
{
    public function __construct(
        private ?MermaidOptions $options = null,
    ) {}

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(RailroadDiagram $source): \Generator
    {
        $canvas = new RailroadCanvas($this->options ?? MermaidOptions::defaults());
        $top = 4.0;
        foreach ($source->rules as $rule) {
            $work = RailroadMeasure::expression($rule['definition']);
            yield from $work;
            yield from $canvas->rule($rule['name'], $rule['statement'], $work->getReturn(), $top);
            $top = $canvas->nextTop();
        }
        $scene = $canvas->scene($source->origin);
        yield from $scene;
        if (null !== $source->title) {
            $title = MermaidTitle::apply($scene->getReturn(), $source->title);
            yield from $title;

            return $title->getReturn();
        }

        return $scene->getReturn();
    }
}
