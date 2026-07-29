<?php

declare(strict_types=1);

namespace Porta\Graph;

/**
 * A complete laid-out graph in screen-down point coordinates — everything the
 * renderer needs, nothing of the wire format it came from.
 */
final readonly class GraphLayout
{
    /**
     * @param array<int, GraphNode> $nodes keyed by Graphviz _gvid
     * @param list<GraphEdge>       $edges
     * @param list<GraphCluster>    $clusters
     */
    public function __construct(
        public float $width,
        public float $height,
        public array $nodes,
        public array $edges,
        public array $clusters,
    ) {}

    public function nodeCount(): int
    {
        return \count($this->nodes);
    }

    public function edgeCount(): int
    {
        return \count($this->edges);
    }
}
