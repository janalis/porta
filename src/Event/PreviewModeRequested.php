<?php

declare(strict_types=1);

namespace Porta\Event;

use Porta\Editor\PreviewMode;

/**
 * A command: show the active file in this arrangement — the text, the text
 * beside its preview, or the preview alone. Dispatched by the breadcrumbs
 * bar's toggles; performed by {@see \Porta\Editor\BufferService}, which does
 * nothing for a file that has no preview.
 */
final readonly class PreviewModeRequested
{
    public function __construct(
        public PreviewMode $mode,
    ) {}
}
