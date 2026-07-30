<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Graph;

/**
 * One laid-out node. Coordinates are points, y-down (the parser flips
 * Graphviz's y-up origin once); (x, y) is the CENTER, (w, h) the extent.
 */
final readonly class GraphNode
{
    public function __construct(
        public int $id,
        public string $name,
        public string $label,
        public float $x,
        public float $y,
        public float $w,
        public float $h,
        public ?string $color,
        public ?string $fillColor,
        public bool $dashed,
    ) {}
}
