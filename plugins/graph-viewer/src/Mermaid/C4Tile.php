<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphNode;

/** An intrinsic grid cell; nested cells are translated without changing their contents. */
final readonly class C4Tile
{
    /** @param array<int, GraphNode> $nodes
     * @param list<GraphCluster> $clusters
     */
    public function __construct(
        public float $width,
        public float $height,
        public array $nodes = [],
        public array $clusters = [],
    ) {}
}
