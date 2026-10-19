<?php

declare(strict_types=1);

namespace Porta\GraphViewer;

use Porta\GraphViewer\Graph\DiagramEngine;
use Porta\GraphViewer\Graph\DiagramKind;
use Porta\GraphViewer\Mermaid\MermaidCliPicture;
use Porta\GraphViewer\Mermaid\MermaidCliRenderer;

/** Normalize ordinary tool pictures and Mermaid's optional same-snapshot geometry. */
final readonly class DiagramBitmapRenderer
{
    public function __construct(
        private ?MermaidCliRenderer $mermaid = null,
    ) {}

    /** @param \Closure(MermaidCliPicture): void $success
     * @param \Closure(string): void $error
     */
    public function render(DiagramKind $kind, DiagramEngine $engine, string $source, int $dpi, \Closure $success, \Closure $error): void
    {
        if (DiagramKind::Mermaid === $kind && null !== $this->mermaid) {
            $this->mermaid->render($source, $dpi, $success, $error);

            return;
        }
        $engine->render($source, $dpi, static fn (string $png) => $success(new MermaidCliPicture($png, scene: null)), $error);
    }

    public function cancel(?DiagramEngine $engine): void
    {
        $engine?->cancel();
        $this->mermaid?->cancel();
    }
}
