<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Graph;

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
        public ?string $fillColor = null,
        public ?string $textColor = null,
        public float $strokeWidth = 0.12,
        public bool $dashed = false,
        public GraphFont $font = GraphFont::Regular,
        public float $fontScale = 1.0,
        /** @var ?list<float> authored dash and gap lengths in units; null draws the default dash */
        public ?array $dashes = null,
    ) {}

    public function painted(?string $color, ?string $fillColor, ?string $textColor): self
    {
        return clone($this, ['color' => $color, 'fillColor' => $fillColor, 'textColor' => $textColor]);
    }

    /** The frame with its bottom edge moved to `$y1`. */
    public function reaching(float $y1): self
    {
        return clone($this, ['y1' => $y1]);
    }
}
