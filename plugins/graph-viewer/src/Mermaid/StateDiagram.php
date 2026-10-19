<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Explicit state identities and authored descriptions survive layout projection. */
final class StateDiagram
{
    public readonly MermaidGraph $graph;
    /** @var array<array-key, list<array{text: string, origin: int}>> */
    public array $descriptions = [];
    /** @var array<array-key, string> */
    public array $kinds = [];
    /** @var list<array{text: string, origin: int}> */
    public array $metadata = [];
    /** @var array<string, array{target: string, side: string}> */
    public array $notes = [];

    public function __construct()
    {
        $this->graph = new MermaidGraph('state');
        $this->graph->colorSlots = MermaidColorSlots::FrameStrokes;
        $this->graph->inheritDirectionsAcrossLinks = false;
    }
}
