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
     * @param ?list<array{float, float}> $ribbon filled boundary for native ribbons and marker glyphs
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
        public ?array $ribbon = null,
        public float $strokeWidth = 0.12,
        public ?string $textColor = null,
        public bool $decoration = false,
        public GraphFont $font = GraphFont::Regular,
        public float $fontScale = 1.0,
        /** @var ?array{string, string} a ribbon's left-to-right gradient, drawn instead of its color */
        public ?array $gradient = null,
        /** @var ?list<float> authored dash and gap lengths in units; null draws the default dash */
        public ?array $dashes = null,
    ) {}

    public function painted(?string $color, ?string $textColor): self
    {
        return clone($this, ['color' => $color, 'textColor' => $textColor]);
    }

    /** @param ?list<float> $dashes the authored lengths, or null for the default dash */
    public function dashed(bool $dashed, ?array $dashes = null): self
    {
        return clone($this, ['dashed' => $dashed, 'dashes' => $dashes]);
    }

    /** The edge without its filled tip, its line running on to where the tip was. */
    public function untipped(): self
    {
        $points = $this->points;
        if (null !== $this->arrowTip) {
            $points[\count($points) - 1] = $this->arrowTip;
        }

        return clone($this, ['arrowTip' => null, 'points' => $points]);
    }

    public function stroked(float $strokeWidth): self
    {
        return clone($this, ['strokeWidth' => $strokeWidth]);
    }
}
