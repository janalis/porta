<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Drawable nodes plus non-drawable group rectangles used as link endpoints. */
final readonly class MermaidPlacement
{
    /**
     * @param array<array-key, GraphNode> $endpoints
     * @param array<array-key, string> $directions
     * @param array<int, list<array{float, float}>> $lanes Reserved route points by source edge index.
     */
    public function __construct(
        public GraphLayout $graph,
        public array $endpoints,
        public array $directions,
        public array $lanes = [],
    ) {}
}
