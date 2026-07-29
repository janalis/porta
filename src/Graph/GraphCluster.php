<?php

declare(strict_types=1);

namespace Porta\Graph;

/** One drawable cluster frame: its bounding box (y-down, y0 < y1), label and color. */
final readonly class GraphCluster
{
    /** @param ?array{float, float} $labelPos */
    public function __construct(
        public string $name,
        public string $label,
        public float $x0,
        public float $y0,
        public float $x1,
        public float $y1,
        public ?string $color,
        public ?array $labelPos,
    ) {}
}
