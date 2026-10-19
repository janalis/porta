<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphNode;

/** Measured cards and source identities are independent of recursive grid placement. */
final readonly class C4Elements
{
    /** @param array<int, GraphNode> $nodes
     * @param array<array-key, int> $ids
     * @param array<array-key, int> $groups
     * @param array<int, string> $shapes
     * @param array<int, int> $origins
     * @param list<array{text: string, origin: int}> $legend
     * @param array<string, string> $edgeLabels
     * @param array<string, list<list<array{string, GraphFont}>>> $runs the cards' styled lines
     */
    public function __construct(
        public array $nodes,
        public array $ids,
        public array $groups,
        public array $shapes,
        public array $origins,
        public array $legend,
        public array $edgeLabels,
        public array $runs = [],
    ) {}
}
