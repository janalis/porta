<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\Help\Fence\FenceElement;

/** The pixels and their optional geometry come from a single CLI SVG snapshot. */
final readonly class MermaidCliPicture
{
    /** @param list<FenceElement> $elements */
    public function __construct(
        public string $png,
        public ?MermaidScene $scene,
        public array $elements = [],
    ) {}
}
