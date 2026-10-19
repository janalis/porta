<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Entity identity is independent of its first alias and ordered, repeated attribute blocks. */
final class ErDiagram
{
    public readonly MermaidGraph $graph;
    public readonly MermaidNodeStyles $styles;
    /** @var list<array{left: string, right: string}> */
    public array $cardinalities = [];
    /** @var array<array-key, string> */
    public array $aliases = [];
    /** @var array<array-key, list<array{text: string, origin: int, cells: list<string>}>> */
    public array $attributes = [];
    /** @var list<array{text: string, origin: int}> */
    public array $metadata = [];

    public function __construct()
    {
        $this->styles = new MermaidNodeStyles('ER');
        $this->graph = new MermaidGraph(styles: $this->styles);
        $this->graph->colorSlots = MermaidColorSlots::Nodes;
        $this->graph->rankGap = 44.0;
    }
}
