<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** One exclusive region shares a center and a text stack. */
final readonly class VennCaptionGroup
{
    /** @param array{x: float, y: float, margin: float} $point
     * @param list<VennCaption> $items
     */
    public function __construct(
        public array $point,
        public array $items,
        public float $width,
        public float $height,
    ) {}
}
