<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Graph;

/**
 * A complete laid-out graph in screen-down point coordinates — everything the
 * renderer needs, nothing of the wire format it came from.
 */
final readonly class GraphLayout
{
    /** @var list<GraphEdge> semantic connections, excluding marker and grid decorations */
    public array $relationships;

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
    ) {
        $this->relationships = \array_values(\array_filter($edges, static fn (GraphEdge $edge): bool => !$edge->decoration));
    }

    public function nodeCount(): int
    {
        return \count($this->nodes);
    }

    public function edgeCount(): int
    {
        return \count($this->relationships);
    }
}
