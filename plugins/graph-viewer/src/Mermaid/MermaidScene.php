<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphLayout;

/** Shared native scene: existing box/line/text graph primitives plus source origins. */
final readonly class MermaidScene
{
    public GraphLayout $selectionGraph;

    /**
     * @param list<int> $origins source line for each full-sized terminal row
     * @param list<array{text: string, origin: int}> $legend labels and relationships readable even in a narrow pane
     * @param array<int, int> $elements selectable node id => source line; decorations are absent
     * @param array<int, string> $shapes graphical node outlines keyed by layout id
     * @param array<string, list<list<array{string, GraphFont}>>> $runs styled label lines by `node:ID`, `edge:INDEX` or `cluster:INDEX`
     */
    public function __construct(
        public GraphLayout $graph,
        public array $origins,
        public array $legend,
        public array $elements = [],
        public array $shapes = [],
        public ?string $background = null,
        public ?string $titleColor = null,
        public float $titleScale = 1.0,
        public ?MermaidPalette $palette = null,
        public array $runs = [],
    ) {
        $this->selectionGraph = new GraphLayout($graph->width, $graph->height, \array_intersect_key($graph->nodes, $elements), edges: [], clusters: []);
    }

    /** The picture's theme: color tokens resolve, and graphics paint the theme background. */
    public function themed(MermaidPalette $palette): self
    {
        return new self(
            $palette->resolveGraph($this->graph),
            $this->origins,
            $this->legend,
            $this->elements,
            $this->shapes,
            $palette->resolve($this->background) ?? $palette->color('background'),
            $palette->resolve($this->titleColor),
            $this->titleScale,
            $palette,
            $this->runs,
        );
    }

    /** @param array<string, list<list<array{string, GraphFont}>>> $runs */
    public function styled(array $runs): self
    {
        return new self($this->graph, $this->origins, $this->legend, $this->elements, $this->shapes, $this->background, $this->titleColor, $this->titleScale, $this->palette, $runs);
    }

    public function selectable(?int $id): ?int
    {
        return null !== $id && \array_key_exists($id, $this->elements) ? $id : null;
    }

    public function onLine(int $line): ?int
    {
        return \array_find_key($this->elements, static fn (int $origin): bool => $line === $origin);
    }

    public function origin(?int $id): ?int
    {
        return null === $id ? null : $this->elements[$id] ?? null;
    }
}
