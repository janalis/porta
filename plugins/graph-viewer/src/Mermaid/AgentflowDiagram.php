<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Agent contracts remain separate from the graph's visual attributes. */
final class AgentflowDiagram
{
    public readonly MermaidGraph $graph;
    /** @var array<array-key, array<array-key, mixed>> */
    public array $metadata = [];
    /** @var array<int, array<array-key, mixed>> */
    public array $edgeMetadata = [];
    /** @var array<array-key, true> */
    public array $connectors = [];

    public function __construct()
    {
        $this->graph = new MermaidGraph();
    }
}
