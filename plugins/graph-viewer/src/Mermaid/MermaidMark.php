<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphFont;

/** One backend-independent drawing operation in the scene's logical coordinates. */
final readonly class MermaidMark
{
    /** @param list<array{float, float}> $points */
    public function __construct(
        public string $kind,
        public array $points,
        public string $text = '',
        public string $stroke = '#333333',
        public string $fill = '#ffffff',
        public bool $dashed = false,
        public float $strokeWidth = 0.12,
        public GraphFont $font = GraphFont::Regular,
        public float $fontScale = 1.0,
        public float $fillOpacity = 1.0,
        public float $opacity = 1.0,
        /** A text mark's rotation about its anchor, in degrees clockwise. */
        public float $angle = 0.0,
        /** @var ?array{string, string} a polygon's fill from its left to its right edge */
        public ?array $gradient = null,
        /** @var ?list<float> authored dash and gap lengths in units; null draws the default dash */
        public ?array $dashes = null,
    ) {}
}
