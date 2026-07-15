<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

/**
 * Whether the editor pane currently owns focus, as threaded into the static
 * render helpers ({@see EditorRenderer::identifierOccurrences()},
 * {@see EditorRenderer::searchOverlay()}). Replaces the former `bool $focused`
 * flag.
 */
enum PaneFocus
{
    case Focused;
    case Blurred;
}
