<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Unlabeled overlaps retain a compact source/search target without inventing graphic text. */
final readonly class VennCaption
{
    public function __construct(
        public string $name,
        public string $label,
        public float $width,
        public float $height,
        public VennPaint $paint,
        public MermaidStatement $statement,
        public bool $visible,
    ) {}
}
