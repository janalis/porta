<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Graph;

/**
 * One laid-out edge: the B-spline pre-flattened to a polyline (points, y-down)
 * plus the precomputed bounding box the renderer culls against without walking
 * the points.
 */
final readonly class GraphEdge
{
    /**
     * @param list<array{float, float}>  $points
     * @param ?array{float, float}       $arrowTip
     * @param ?array{float, float}       $labelPos
     */
    public function __construct(
        public int $tail,
        public int $head,
        public array $points,
        public ?array $arrowTip,
        public float $minX,
        public float $minY,
        public float $maxX,
        public float $maxY,
        public ?string $color,
        public ?string $label,
        public ?array $labelPos,
        public bool $dashed,
    ) {}
}
