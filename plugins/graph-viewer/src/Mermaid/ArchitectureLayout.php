<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** The source model remains unchanged while glyphs, frames, named-port routes and hit targets are assembled. */
final readonly class ArchitectureLayout
{
    public function __construct(
        private ArchitectureConfiguration $configuration = new ArchitectureConfiguration(),
    ) {}

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(ArchitectureDiagram $source): \Generator
    {
        $placement = new ArchitecturePlacement($this->configuration)->place($source);
        yield from $placement;
        $routes = ArchitectureRoutes::layout($source, $placement->getReturn());
        yield from $routes;
        $bounds = MermaidCaptionBounds::layout($routes->getReturn());
        yield from $bounds;
        $graph = $bounds->getReturn();
        $origins = MermaidOrigins::rows($graph->height, $source->origin);
        $elements = [];
        $shapes = [];
        $legend = $source->legend;
        foreach ($graph->nodes as $node) {
            $item = $source->items[$node->name];
            $origin = $item['statement']->line;
            $elements[$node->id] = $origin;
            $shapes[$node->id] = match ($item['kind']) {
                'group' => null === $item['icon'] ? 'text' : 'arch-group:'.\base64_encode($item['icon']).':',
                'junction' => 'arch-junction',
                default => 'arch-service:'.\base64_encode($item['icon'] ?? '').':'.\base64_encode($item['iconText'] ?? '').':'.(null === $item['in'] ? 'root' : 'frame'),
            };
            for ($row = (int) \floor(($node->y - ($node->h / 2)) / 2); $row <= (int) \ceil(($node->y + ($node->h / 2)) / 2); ++$row) {
                $origins[$row] = $origin;
                yield null;
            }
            if (null !== $item['icon']) {
                $legend[] = ['text' => $node->label.' icon: '.$item['icon'], 'origin' => $origin];
            }
            yield null;
        }
        foreach ($source->edges as $edge) {
            $legend[] = [
                'text' => $edge['lhsId'].':'.$edge['lhsDir'].' -- '.$edge['rhsDir'].':'.$edge['rhsId'].(null === $edge['title'] ? '' : ': '.$edge['title']),
                'origin' => $edge['statement']->line,
            ];
            yield null;
        }

        return new MermaidScene($graph, $origins, $legend, $elements, $shapes);
    }
}
